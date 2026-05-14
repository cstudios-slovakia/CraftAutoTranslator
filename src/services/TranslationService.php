<?php

namespace cstudios\autotranslator\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\commerce\elements\Product;
use craft\models\Site;
use cstudios\autotranslator\AutoTranslator;

class TranslationService extends Component
{
    /**
     * Handle the saving of an element (Entry, Product, etc.)
     */
    public function handleElementSaved(ElementInterface $element, bool $isNew)
    {
        $settings = AutoTranslator::$plugin->getSettings();
        
        if (!$settings->enableAutoTranslation || empty($settings->openaiApiKey)) {
            return;
        }

        // We only want to trigger this when a new element is created.
        if (!$isNew) {
            return;
        }

        // Avoid infinite loops if we are saving other site versions
        if ($element->getIsDerivative()) {
            return;
        }

        $supportedClasses = [Entry::class];
        if (class_exists(Product::class)) {
            $supportedClasses[] = Product::class;
        }
        if (class_exists('\Solspace\Calendar\Elements\Event')) {
            $supportedClasses[] = '\Solspace\Calendar\Elements\Event';
        }

        $isSupported = false;
        foreach ($supportedClasses as $class) {
            if ($element instanceof $class) {
                $isSupported = true;
                break;
            }
        }

        if (!$isSupported) {
            return;
        }

        $sourceSite = $element->getSite();
        
        // Get all sites this element is enabled for
        $supportedSites = $element->getSupportedSites();
        
        foreach ($supportedSites as $supportedSite) {
            $siteId = $supportedSite['siteId'];
            if ($siteId == $sourceSite->id) {
                continue;
            }

            $targetSite = Craft::$app->getSites()->getSiteById($siteId);
            if ($targetSite) {
                // Queue the translation task
                Craft::$app->getQueue()->push(new \cstudios\autotranslator\jobs\TranslateElementJob([
                    'elementId' => $element->id,
                    'sourceSiteId' => $sourceSite->id,
                    'targetSiteId' => $targetSite->id,
                ]));
            }
        }
    }

    /**
     * Translates an element from source site to target site.
     */
    public function translateElement(int $elementId, int $sourceSiteId, int $targetSiteId)
    {
        $sourceElement = Craft::$app->getElements()->getElementById($elementId, null, $sourceSiteId);
        if (!$sourceElement) {
            return false;
        }

        $targetElement = Craft::$app->getElements()->getElementById($elementId, null, $targetSiteId);
        if (!$targetElement) {
            return false;
        }

        $sourceLanguage = Craft::$app->getSites()->getSiteById($sourceSiteId)->language;
        $targetLanguage = Craft::$app->getSites()->getSiteById($targetSiteId)->language;

        $fields = $this->_getTranslatableFields($sourceElement);
        
        if (empty($fields)) {
            return true;
        }

        $translatedFields = AutoTranslator::$plugin->openai->translate($fields, $sourceLanguage, $targetLanguage);

        if ($translatedFields) {
            $targetElement->setFieldValues($translatedFields);
            $targetElement->setScenario(ElementInterface::SCENARIO_LIVE);
            return Craft::$app->getElements()->saveElement($targetElement);
        }

        return false;
    }

    private function _getTranslatableFields(ElementInterface $element): array
    {
        $fieldsToTranslate = [];
        $fieldLayout = $element->getFieldLayout();
        
        if (!$fieldLayout) {
            return [];
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            // Check if field is translatable (different per site)
            if ($field->translationMethod !== 'none') {
                $value = $element->getFieldValue($field->handle);
                
                // For simplicity, handle basic string fields or arrays. 
                // More complex fields (Matrix) need specific normalization, 
                // but since we rely on JSON preservation with OpenAI, we can try to pass raw or serialized data.
                if (is_string($value) && !empty($value)) {
                    $fieldsToTranslate[$field->handle] = $value;
                } elseif (is_array($value)) {
                    $fieldsToTranslate[$field->handle] = $value;
                }
            }
        }

        // Also check native title
        if ($element instanceof Entry || $element instanceof Product || $element instanceof \Solspace\Calendar\Elements\Event) {
            if ($element->title) {
                $fieldsToTranslate['title'] = $element->title;
            }
        }

        return $fieldsToTranslate;
    }
}
