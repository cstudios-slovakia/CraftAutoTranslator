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
     * Per-field maximum length constraints collected during field extraction,
     * keyed by field handle: ['handle' => ['unit' => 'characters'|'words', 'limit' => int]].
     * Passed to OpenAI so the translation is asked to stay within the limit a
     * PlainText (charLimit) or CKEditor (characterLimit/wordLimit) field enforces.
     */
    private array $fieldLimits = [];

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

    public function translateElement(int $elementId, ?int $sourceSiteId, int $targetSiteId)
    {
        // When sourceSiteId is null we translate the target site's own content in-place
        // (source language auto-detected by OpenAI).
        $effectiveSourceSiteId = $sourceSiteId ?? $targetSiteId;

        Craft::info("Starting translation for Element $elementId from Site $effectiveSourceSiteId to Site $targetSiteId" . ($sourceSiteId === null ? ' (auto-detect source language)' : ''), 'auto-translator');

        $sourceElement = Craft::$app->getElements()->getElementById($elementId, null, $effectiveSourceSiteId);
        if (!$sourceElement) {
            Craft::error("Source element $elementId not found for Site $effectiveSourceSiteId", 'auto-translator');
            return false;
        }

        $targetElement = Craft::$app->getElements()->getElementById($elementId, null, $targetSiteId);
        if (!$targetElement) {
            Craft::error("Target element $elementId not found for Site $targetSiteId", 'auto-translator');
            return false;
        }

        // Guard against elements that don't actually support the requested target site
        // (e.g. Solspace Calendar events that aren't enabled for that site). Craft can
        // silently fall back to a different site's row, which would cause us to overwrite
        // the wrong site's content. Refuse to proceed in that case.
        if ((int)$targetElement->siteId !== (int)$targetSiteId) {
            Craft::error(
                "Element $elementId does not support Site $targetSiteId (loaded as Site {$targetElement->siteId}). Skipping translation.",
                'auto-translator'
            );
            return false;
        }

        // null source language = let OpenAI auto-detect (used for in-place translation)
        $sourceLanguage = $sourceSiteId !== null
            ? Craft::$app->getSites()->getSiteById($sourceSiteId)->language
            : null;
        $targetLanguage = Craft::$app->getSites()->getSiteById($targetSiteId)->language;

        $this->fieldLimits = [];
        $fields = $this->_getTranslatableFields($sourceElement);
        Craft::info("Extracted translatable fields: " . json_encode($fields), 'auto-translator');
        if (!empty($this->fieldLimits)) {
            Craft::info("Field length limits: " . json_encode($this->fieldLimits), 'auto-translator');
        }

        if (empty($fields)) {
            Craft::info("No translatable fields found for element $elementId", 'auto-translator');
            return true;
        }

        $translatedFields = AutoTranslator::$plugin->openai->translate($fields, $sourceLanguage, $targetLanguage, $this->fieldLimits);
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
                $element->slug = '';
                continue;
            }

            // Element-keyed arrays (matrix blocks, relation fields like Categories/Entries/Assets)
            // must be traversed and saved on the sub-elements, never passed to setFieldValues()
            // on the parent — relation fields expect ID arrays, not nested data structures.
            // Table fields (row-indexed arrays) are NOT element fields and fall through to regularFields.
            if (is_array($value) && !empty($value)) {
                $field = $this->_getFieldFromLayout($element, $key);
                if ($field && $this->_isElementArrayField($field)) {
                    foreach ($value as $blockId => $blockFields) {
                        if (!is_numeric($blockId) || !is_array($blockFields)) {
                            continue;
                        }
                        $blockElement = Craft::$app->getElements()->getElementById((int)$blockId, null, $targetSiteId);
                        if ($blockElement) {
                            $this->_applyTranslatedFields($blockElement, $blockFields, $targetSiteId);
                        }
                    }
                    continue;
                }
            }

            $regularFields[$key] = $value;
        }

        if (!empty($regularFields)) {
            $element->setFieldValues($regularFields);
        }

        $element->setScenario(\craft\base\Element::SCENARIO_LIVE);
        try {
            $saved = Craft::$app->getElements()->saveElement($element);
            if (!$saved && $element->hasErrors()) {
                // A validation error (e.g. a translated value exceeding a field's
                // character limit) is the single most common save failure. Surface
                // it as an exception with a human-readable message so the queue
                // records it and the sidebar shows the real reason in the toast,
                // instead of a generic "Translation failed".
                Craft::error(
                    "Validation errors on save for element {$element->id} (site {$element->siteId}): "
                        . json_encode($element->getErrors(), JSON_UNESCAPED_UNICODE),
                    'auto-translator'
                );
                throw new \RuntimeException($this->_formatValidationErrors($element));
            }
            return $saved;
        } catch (\Throwable $e) {
            // Solspace Calendar events (and some other element types) can throw
            // "Attempting to save an element in an unsupported site" at save time
            // even when getElementById happily loaded the element for that site.
            // Treat that as a soft skip rather than a hard queue failure.
            $msg = $e->getMessage();
            if (stripos($msg, 'unsupported site') !== false) {
                Craft::warning(
                    "Skipping save of element {$element->id} for site {$element->siteId}: {$msg}",
                    'auto-translator'
                );
                return false;
            }
            throw $e;
        }
    }

    /**
     * Flatten an element's validation errors into a single human-readable string.
     * Craft's messages already name the field (e.g. "*Short intro* should contain
     * at most 135 characters."), which is exactly what we want in the toast.
     */
    private function _formatValidationErrors(ElementInterface $element): string
    {
        $messages = [];
        foreach ($element->getErrors() as $attr => $attrMessages) {
            foreach ((array)$attrMessages as $m) {
                $m = trim((string)$m);
                if ($m !== '' && !in_array($m, $messages, true)) {
                    $messages[] = $m;
                }
            }
        }

        if (empty($messages)) {
            return 'Element validation failed on save.';
        }

        return implode(' ', $messages);
    }

    /**
     * Return the maximum-length constraint a field enforces, if any, as
     * ['unit' => 'characters'|'words', 'limit' => int]. Used to tell OpenAI to
     * keep the translation within the limit so the save doesn't fail validation.
     */
    /**
     * If the field enforces a max length, remember it (keyed by handle) so it can
     * be sent to OpenAI. Records nested fields too — handles are unique enough that
     * a flat map lines up with the keys the model sees in the JSON.
     */
    private function _recordFieldLimit(\craft\base\FieldInterface $field): void
    {
        $limit = $this->_getFieldLimit($field);
        if ($limit !== null) {
            $this->fieldLimits[$field->handle] = $limit;
        }
    }

    private function _getFieldLimit(\craft\base\FieldInterface $field): ?array
    {
        if ($field instanceof \craft\fields\PlainText && !empty($field->charLimit)) {
            return ['unit' => 'characters', 'limit' => (int)$field->charLimit];
        }

        if (class_exists('\craft\ckeditor\Field') && $field instanceof \craft\ckeditor\Field) {
            if (!empty($field->characterLimit)) {
                return ['unit' => 'characters', 'limit' => (int)$field->characterLimit];
            }
            if (!empty($field->wordLimit)) {
                return ['unit' => 'words', 'limit' => (int)$field->wordLimit];
            }
        }

        return null;
    }

    private function _getFieldFromLayout(ElementInterface $element, string $handle): ?\craft\base\FieldInterface
    {
        $fieldLayout = $element->getFieldLayout();
        if (!$fieldLayout) {
            return null;
        }
        foreach ($fieldLayout->getCustomFields() as $field) {
            if ($field->handle === $handle) {
                return $field;
            }
        }
        return null;
    }

    private function _isElementArrayField(\craft\base\FieldInterface $field): bool
    {
        // Matrix and Neo store blocks as element-keyed arrays
        if ($field instanceof \craft\fields\Matrix) {
            return true;
        }
        if (class_exists('\benf\neo\Field') && $field instanceof \benf\neo\Field) {
            return true;
        }
        // Relation fields (Categories, Entries, Assets, Tags, Users) also produce
        // element-keyed arrays during extraction — they must never be written back
        // to the parent via setFieldValues() as Craft expects plain ID arrays there.
        if ($field instanceof \craft\fields\BaseRelationField) {
            return true;
        }
        return false;
    }

    /**
     * Determine whether this element's native `title` is stored per-site.
     * If it isn't (translationMethod = 'none'), writing the title on one site
     * will overwrite all other sites — so we must not translate it.
     */
    private function _isTitleSiteTranslatable(ElementInterface $element): bool
    {
        // Craft 5 ElementInterface exposes getIsTitleTranslatable() — Solspace
        // Calendar implements it (checks $calendar->titleTranslationMethod),
        // and core element types delegate to their section/group/entry-type
        // settings. Use it whenever it's available.
        if (method_exists($element, 'getIsTitleTranslatable')) {
            try {
                return (bool)$element->getIsTitleTranslatable();
            } catch (\Throwable $e) {
                // Fall through to the property probe below.
            }
        }

        $methodNone = \craft\base\Field::TRANSLATION_METHOD_NONE;
        $containers = [];
        foreach (['getType', 'getCalendar', 'getGroup', 'getSection'] as $getter) {
            if (method_exists($element, $getter)) {
                try {
                    $obj = $element->$getter();
                    if ($obj) {
                        $containers[] = $obj;
                    }
                } catch (\Throwable $e) {
                    // skip
                }
            }
        }

        foreach ($containers as $container) {
            if (property_exists($container, 'titleTranslationMethod')) {
                $method = $container->titleTranslationMethod ?? null;
                if ($method === $methodNone) {
                    return false;
                }
                if ($method !== null) {
                    return true;
                }
            }
        }

        return true;
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

            // Handle ether/seo SeoField — SeoData is an object that doesn't stringify,
            // so we extract the user-editable parts (titleRaw, descriptionRaw) explicitly.
            if (class_exists('\ether\seo\fields\SeoField')
                && $field instanceof \ether\seo\fields\SeoField
                && $value instanceof \ether\seo\models\data\SeoData
            ) {
                $seoData = [];

                $titleRaw = $value->titleRaw;
                if (is_array($titleRaw) && !empty($titleRaw)) {
                    $editableParts = array_filter($titleRaw, fn($v) => is_string($v) && $v !== '');
                    if (!empty($editableParts)) {
                        $seoData['titleRaw'] = $editableParts;
                    }
                } elseif (is_string($titleRaw) && $titleRaw !== '') {
                    $seoData['titleRaw'] = $titleRaw;
                }

                if (!empty($value->descriptionRaw) && is_string($value->descriptionRaw)) {
                    $seoData['descriptionRaw'] = $value->descriptionRaw;
                }

                if (!empty($seoData)) {
                    $fieldsToTranslate[$field->handle] = $seoData;
                    Craft::info("Extracted SEO fields for {$field->handle}: " . json_encode($seoData), 'auto-translator');
                }
                continue;
            }

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
            } elseif ($isTranslatable || $field instanceof \craft\fields\Table) {
                // Always include Table fields even when translationMethod = 'none',
                // because their rows contain text content that needs translation.
                if (is_string($value) && !empty($value)) {
                    $fieldsToTranslate[$field->handle] = $value;
                    $this->_recordFieldLimit($field);
                } elseif (is_object($value) && method_exists($value, '__toString')) {
                    $strValue = (string)$value;
                    if (!empty($strValue)) {
                        $fieldsToTranslate[$field->handle] = $strValue;
                        $this->_recordFieldLimit($field);
                    }
                } elseif (is_array($value) && !empty($value)) {
                    $fieldsToTranslate[$field->handle] = $value;
                }
            }
        }

        // Craft 5 handles titles dynamically, sometimes as a custom field, sometimes as a native property.
        // Only extract the title if it's actually site-translatable — otherwise saving it on one site
        // will propagate the translated value to all other sites (Solspace Calendar defaults to
        // a non-translatable title, so a SK translation would overwrite the HU title and vice versa).
        try {
            $titleValue = $element->title ?? null;
            if ($titleValue && !isset($fieldsToTranslate['title']) && $this->_isTitleSiteTranslatable($element)) {
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
