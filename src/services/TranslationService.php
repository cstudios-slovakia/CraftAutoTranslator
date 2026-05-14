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
            $siteId = is_numeric($supportedSite) ? $supportedSite : (is_object($supportedSite) ? $supportedSite->siteId : $supportedSite['siteId']);
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

    public function translateElement(int $elementId, int $sourceSiteId, int $targetSiteId)
    {
        Craft::info("Starting translation for Element $elementId from Site $sourceSiteId to Site $targetSiteId", 'auto-translator');

        $sourceElement = Craft::$app->getElements()->getElementById($elementId, null, $sourceSiteId);
        if (!$sourceElement) {
            Craft::error("Source element $elementId not found for Site $sourceSiteId", 'auto-translator');
            return false;
        }

        $targetElement = Craft::$app->getElements()->getElementById($elementId, null, $targetSiteId);
        if (!$targetElement) {
            Craft::error("Target element $elementId not found for Site $targetSiteId", 'auto-translator');
            return false;
        }

        $sourceLanguage = Craft::$app->getSites()->getSiteById($sourceSiteId)->language;
        $targetLanguage = Craft::$app->getSites()->getSiteById($targetSiteId)->language;

        $fields = $this->_getTranslatableFields($sourceElement);
        Craft::info("Extracted translatable fields: " . json_encode($fields), 'auto-translator');
        
        if (empty($fields)) {
            Craft::info("No translatable fields found for element $elementId", 'auto-translator');
            return true;
        }

        $translatedFields = AutoTranslator::$plugin->openai->translate($fields, $sourceLanguage, $targetLanguage);
        Craft::info("Translated fields received: " . json_encode($translatedFields), 'auto-translator');

        if ($translatedFields) {
            $success = $this->_applyTranslatedFields($targetElement, $translatedFields, $targetSiteId);
            Craft::info("Apply translated fields success: " . ($success ? 'Yes' : 'No'), 'auto-translator');
            if (!$success && $targetElement->hasErrors()) {
                Craft::error("Validation errors on save: " . json_encode($targetElement->getErrors()), 'auto-translator');
            }
            return $success;
        }

        Craft::error("Translation returned empty/null for element $elementId", 'auto-translator');
        return false;
    }

    private function _applyTranslatedFields(ElementInterface $element, array $translatedFields, int $targetSiteId): bool
    {
        $regularFields = [];
        
        foreach ($translatedFields as $key => $value) {
            if ($key === 'title') {
                $element->title = $value;
                try {
                    $element->setFieldValue('title', $value);
                } catch (\Throwable $e) {
                    // Ignore if it's not a custom field
                }
                $element->slug = ''; // Force Craft to regenerate the slug based on the new title
                continue;
            }

            // If it's an array and keys are numeric (block IDs), it's a matrix/nested field
            if (is_array($value) && !empty($value) && is_numeric(array_key_first($value))) {
                foreach ($value as $blockId => $blockFields) {
                    $blockElement = Craft::$app->getElements()->getElementById((int)$blockId, null, $targetSiteId);
                    if ($blockElement) {
                        $this->_applyTranslatedFields($blockElement, $blockFields, $targetSiteId);
                    }
                }
            } else {
                $regularFields[$key] = $value;
            }
        }

        if (!empty($regularFields)) {
            $element->setFieldValues($regularFields);
        }

        $element->setScenario(\craft\base\Element::SCENARIO_LIVE);
        return Craft::$app->getElements()->saveElement($element);
    }

    private function _getTranslatableFields(ElementInterface $element): array
    {
        $fieldsToTranslate = [];
        $fieldLayout = $element->getFieldLayout();
        
        if (!$fieldLayout) {
            return [];
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            $value = $element->getFieldValue($field->handle);
            $isTranslatable = $field->translationMethod !== 'none';
            
            // Log field information for debugging
            Craft::info("Processing field: {$field->handle}, type: " . get_class($field) . ", translatable: " . ($isTranslatable ? 'yes' : 'no'), 'auto-translator');

            // If it's a relation/matrix field, we MUST traverse it even if the relation itself is not translatable,
            // because the blocks themselves might have translatable fields in different sites!
            if ($value instanceof \craft\elements\db\ElementQueryInterface) {
                $blocks = $value->all();
            } elseif (is_iterable($value)) {
                $blocks = $value;
            } else {
                $blocks = null;
            }

            if ($blocks !== null && (is_array($blocks) || $blocks instanceof \Traversable) && (empty($blocks) || current((array)$blocks) instanceof \craft\base\ElementInterface)) {
                $blockData = [];
                foreach ($blocks as $block) {
                    if ($block instanceof \craft\base\ElementInterface) {
                        $blockFields = $this->_getTranslatableFields($block);
                        if (!empty($blockFields)) {
                            $blockData[$block->id] = $blockFields;
                        }
                    }
                }
                if (!empty($blockData)) {
                    $fieldsToTranslate[$field->handle] = $blockData;
                }
            } elseif ($isTranslatable) {
                // For non-element fields, only translate if they are set to be translatable
                if (is_string($value) && !empty($value)) {
                    $fieldsToTranslate[$field->handle] = $value;
                } elseif (is_object($value) && method_exists($value, '__toString')) {
                    $strValue = (string)$value;
                    if (!empty($strValue)) {
                        $fieldsToTranslate[$field->handle] = $strValue;
                    }
                } elseif (is_array($value)) {
                    $fieldsToTranslate[$field->handle] = $value;
                }
            }
        }

        // Craft 5 handles titles dynamically, sometimes as a custom field, sometimes as a native property
        try {
            $titleValue = $element->title ?? null;
            if ($titleValue && !isset($fieldsToTranslate['title'])) {
                $strTitle = is_object($titleValue) && method_exists($titleValue, '__toString') ? (string)$titleValue : (is_string($titleValue) ? $titleValue : null);
                if (!empty($strTitle)) {
                    $fieldsToTranslate['title'] = $strTitle;
                    Craft::info("Extracted title: " . $strTitle, 'auto-translator');
                }
            }
        } catch (\Throwable $e) {
            // Ignore if title doesn't exist on this element type
        }

        return $fieldsToTranslate;
    }
}
