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
            try {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                @set_time_limit(300);
                Craft::$app->getQueue()->run();
            } catch (\Throwable $e) {
                Craft::error('Inline queue run failed: ' . $e->getMessage(), 'auto-translator');
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
