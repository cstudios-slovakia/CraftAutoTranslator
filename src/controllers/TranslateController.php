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
                    $targetSiteIds[] = $siteId;
                }
            }
        } else {
            $targetSiteIds[] = $targetSiteId;
        }

        foreach ($targetSiteIds as $siteId) {
            Craft::$app->getQueue()->push(new TranslateElementJob([
                'elementId' => $elementId,
                'sourceSiteId' => $sourceSiteId,
                'targetSiteId' => $siteId,
            ]));
        }

        return $this->asSuccess('Translation job(s) added to queue.');
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
