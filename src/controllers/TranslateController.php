<?php

namespace cstudios\autotranslator\controllers;

use Craft;
use craft\web\Controller;
use cstudios\autotranslator\AutoTranslator;
use cstudios\autotranslator\jobs\TranslateElementJob;
use yii\web\Response;

class TranslateController extends Controller
{
    /**
     * @var string|bool|array Allows anonymous access to this controller's actions.
     */
    protected array|int|bool $allowAnonymous = false;

    /**
     * Run the queue after the current response has been sent, so the client gets a
     * fast acknowledgement and queue jobs actually execute. Craft's built-in
     * runQueueAutomatically dispatches a separate HTTP request to /actions/queue/run
     * which often fails on shared hosting, leaving jobs sitting in the queue.
     */
    private function _runQueueAfterResponse(): void
    {
        Craft::$app->getResponse()->on(Response::EVENT_AFTER_SEND, function() {
            // Detach from the client BEFORE doing the slow queue work, so the
            // browser sees our JSON response instantly. Without a working
            // detach (FastCGI/LiteSpeed), the OpenAI call blocks the response
            // and the UI sits silent for ~7s after a click.
            $detached = false;
            try {
                if (function_exists('litespeed_finish_request')) {
                    @litespeed_finish_request();
                    $detached = true;
                } elseif (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                    $detached = true;
                } else {
                    // mod_php fallback: flush output buffers and close the
                    // connection by sending Content-Length + Connection: close.
                    @ignore_user_abort(true);
                    while (ob_get_level() > 0) {
                        @ob_end_flush();
                    }
                    @flush();
                }
            } catch (\Throwable $e) {
                Craft::warning('Connection detach failed: ' . $e->getMessage(), 'auto-translator');
            }

            // If we couldn't detach, don't run the queue inline — it would
            // block the client. Polling on queue-status will drive execution.
            if (!$detached) {
                return;
            }

            try {
                @set_time_limit(300);
                Craft::$app->getQueue()->run();
            } catch (\Throwable $e) {
                Craft::error('Inline queue run failed: ' . $e->getMessage(), 'auto-translator');
            }
        });
    }

    /**
     * Best-effort: if jobs are sitting in the queue and we can detach from
     * the client, run them. Called from the polling endpoint so a stuck
     * queue gets nudged even on hosts where the POST handler couldn't
     * detach (e.g. mod_php with output buffering).
     */
    private function _nudgeQueueAfterResponse(): void
    {
        Craft::$app->getResponse()->on(Response::EVENT_AFTER_SEND, function() {
            try {
                if (function_exists('litespeed_finish_request')) {
                    @litespeed_finish_request();
                } elseif (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                } else {
                    return;
                }
                @set_time_limit(120);
                Craft::$app->getQueue()->run();
            } catch (\Throwable $e) {
                Craft::warning('Queue nudge failed: ' . $e->getMessage(), 'auto-translator');
            }
        });
    }

    /**
     * Wipe any stale failed TranslateElementJob rows from the queue table so
     * the next polling cycle only sees the result of the job we're about to
     * push. Without this, an old "OpenAI quota exceeded" error from a previous
     * session would be reported as the result of a brand new translation.
     */
    private function _clearStaleFailedJobs(): void
    {
        try {
            $tableName = Craft::$app->getQueue()->tableName ?? '{{%queue}}';
            Craft::$app->getDb()->createCommand()
                ->delete($tableName, ['and',
                    ['like', 'job', 'TranslateElementJob'],
                    ['fail' => true],
                ])
                ->execute();
        } catch (\Throwable $e) {
            Craft::warning('Failed to clear stale translate jobs: ' . $e->getMessage(), 'auto-translator');
        }
    }

    /**
     * Translate the current site's content into its own language (auto-detect source).
     * Used by the "Translate" in-place button in the sidebar.
     */
    public function actionTranslateInPlace(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $siteId = (int)$request->getRequiredBodyParam('siteId');

        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);
        if (!$element) {
            return $this->asFailure('Element not found.');
        }

        // Guard against Craft's cross-site fallback: if the element isn't enabled
        // for the requested site, getElementById() silently returns another site's
        // row. Queuing a job in that state causes a silent no-op (site-mismatch
        // check in translateElement() returns false without marking the job failed).
        if ((int)$element->siteId !== $siteId) {
            return $this->asFailure("Element {$elementId} is not enabled for site {$siteId}. Enable the element for this site in its settings first.");
        }

        $this->_clearStaleFailedJobs();

        Craft::$app->getQueue()->push(new TranslateElementJob([
            'elementId' => $elementId,
            'sourceSiteId' => null, // auto-detect source language
            'targetSiteId' => $siteId,
        ]));

        $this->_runQueueAfterResponse();

        return $this->asSuccess('Translation job added to queue.', ['jobCount' => 1]);
    }

    /**
     * Manually translate a specific element.
     */
    public function actionElement(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $targetSiteId = $request->getBodyParam('targetSiteId'); // can be 'all'
        $sourceSiteIdParam = $request->getBodyParam('sourceSiteId');

        $sourceSiteId = null;
        if ($sourceSiteIdParam !== null && $sourceSiteIdParam !== '') {
            $sourceSiteId = (int)$sourceSiteIdParam;
        }

        $element = Craft::$app->getElements()->getElementById($elementId, null, $sourceSiteId);
        if (!$element) {
            return $this->asFailure('Element not found.');
        }

        // If the caller didn't tell us the source site, fall back to whatever site
        // the loaded element belongs to (Craft will have used the primary site).
        if (!$sourceSiteId) {
            $sourceSiteId = $element->siteId;
        }

        $targetSiteIds = [];
        if ($targetSiteId === 'all' || !$targetSiteId) {
            foreach ($element->getSupportedSites() as $supportedSite) {
                $siteId = is_numeric($supportedSite) ? $supportedSite : (is_object($supportedSite) ? $supportedSite->siteId : $supportedSite['siteId']);
                if ($siteId != $sourceSiteId) {
                    // Verify the element can actually be loaded for that site —
                    // some element types (e.g. Solspace Calendar events) report
                    // sites as supported but don't have a per-site row, which
                    // would cause the job to overwrite the wrong content or fail.
                    $check = Craft::$app->getElements()->getElementById($elementId, null, (int)$siteId);
                    if ($check && (int)$check->siteId === (int)$siteId) {
                        $targetSiteIds[] = $siteId;
                    }
                }
            }
        } else {
            $targetSiteIds[] = $targetSiteId;
        }

        $this->_clearStaleFailedJobs();

        foreach ($targetSiteIds as $siteId) {
            Craft::$app->getQueue()->push(new TranslateElementJob([
                'elementId' => $elementId,
                'sourceSiteId' => $sourceSiteId,
                'targetSiteId' => $siteId,
            ]));
        }

        $this->_runQueueAfterResponse();

        return $this->asSuccess('Translation job(s) added to queue.', [
            'jobCount' => count($targetSiteIds),
        ]);
    }

    /**
     * Returns whether any TranslateElementJobs are still pending in the queue.
     * Used by the sidebar JS to poll for completion.
     *
     * Craft 5 queue schema: completed jobs are DELETED (no done_at column).
     * Active jobs: fail=false (waiting: timeUpdated=null, running: timeUpdated=timestamp).
     * Failed jobs: fail=true (kept in table).
     */
    public function actionQueueStatus(): Response
    {
        $this->requireAcceptsJson();

        try {
            $queue = Craft::$app->getQueue();
            $tableName = $queue->tableName ?? '{{%queue}}';

            $active = (int)(new \yii\db\Query())
                ->from($tableName)
                ->where(['like', 'job', 'TranslateElementJob'])
                ->andWhere(['fail' => false])
                ->count();

            // If there are pending jobs but nothing's been picked up yet
            // (timeUpdated IS NULL on all rows), nudge the queue from this
            // request so mod_php-style hosts where the POST couldn't detach
            // still get the work executed.
            if ($active > 0) {
                $waiting = (int)(new \yii\db\Query())
                    ->from($tableName)
                    ->where(['like', 'job', 'TranslateElementJob'])
                    ->andWhere(['fail' => false])
                    ->andWhere(['timeUpdated' => null])
                    ->count();
                if ($waiting > 0) {
                    $this->_nudgeQueueAfterResponse();
                }
            }

            $failed = (int)(new \yii\db\Query())
                ->from($tableName)
                ->where(['like', 'job', 'TranslateElementJob'])
                ->andWhere(['fail' => true])
                ->count();

            $lastError = null;
            if ($failed > 0) {
                // Surface the most recent failed job's error message so the
                // sidebar JS can show a meaningful toast (e.g. "OpenAI quota
                // exceeded" instead of a generic "Translation failed").
                $row = (new \yii\db\Query())
                    ->select(['error', 'dateFailed', 'timeUpdated'])
                    ->from($tableName)
                    ->where(['like', 'job', 'TranslateElementJob'])
                    ->andWhere(['fail' => true])
                    ->orderBy(['dateFailed' => SORT_DESC, 'timeUpdated' => SORT_DESC])
                    ->limit(1)
                    ->one();
                if ($row && !empty($row['error'])) {
                    // Yii records the full exception trace in `error`; the
                    // first line is the message we want.
                    $firstLine = strtok((string)$row['error'], "\n");
                    $lastError = trim($firstLine);
                }
            }

            return $this->asJson([
                'running' => $active > 0,
                'pending' => $active,
                'failed' => $failed,
                'lastError' => $lastError,
            ]);
        } catch (\Throwable $e) {
            Craft::warning('Queue status check failed: ' . $e->getMessage(), 'auto-translator');
            return $this->asJson(['running' => true, 'pending' => -1]);
        }
    }

    /**
     * Utility action: translate the whole site.
     */
    public function actionSite(): Response
    {
        $this->requirePostRequest();

        $sourceSiteId = Craft::$app->getRequest()->getRequiredBodyParam('sourceSiteId');
        $targetSiteId = Craft::$app->getRequest()->getRequiredBodyParam('targetSiteId');
        $elementType = Craft::$app->getRequest()->getRequiredBodyParam('elementType'); // e.g. craft\elements\Entry

        $query = $elementType::find()->siteId($sourceSiteId);
        $elements = $query->all();

        $count = 0;
        foreach ($elements as $element) {
            Craft::$app->getQueue()->push(new TranslateElementJob([
                'elementId' => $element->id,
                'sourceSiteId' => $sourceSiteId,
                'targetSiteId' => $targetSiteId,
            ]));
            $count++;
        }

        Craft::$app->getSession()->setNotice("Queued $count elements for translation.");

        return $this->redirectToPostedUrl();
    }
}
