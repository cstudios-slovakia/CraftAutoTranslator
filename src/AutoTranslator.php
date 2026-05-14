<?php

namespace cstudios\autotranslator;

use Craft;
use craft\base\Plugin;
use craft\base\Model;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Elements;
use craft\services\Utilities;
use craft\web\View;
use yii\base\Event;

use cstudios\autotranslator\models\Settings;
use cstudios\autotranslator\services\TranslationService;
use cstudios\autotranslator\services\OpenAiService;
use cstudios\autotranslator\utilities\TranslateUtility;

class AutoTranslator extends Plugin
{
    public static ?AutoTranslator $plugin;
    public bool $hasCpSettings = true;
    public bool $hasCpSection = false;

    public function init()
    {
        parent::init();
        self::$plugin = $this;

        $this->setComponents([
            'translation' => TranslationService::class,
            'openai' => OpenAiService::class,
        ]);

        $fileTarget = new \yii\log\FileTarget([
            'logFile' => Craft::getAlias('@storage/logs/auto-translator.log'),
            'categories' => ['cstudios\autotranslator\*', 'auto-translator'],
            'logVars' => [],
        ]);
        Craft::getLogger()->dispatcher->targets[] = $fileTarget;

        $this->_registerEvents();

        Craft::info('Auto Translator plugin loaded', 'auto-translator');
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'auto-translator/settings',
            ['settings' => $this->getSettings()]
        );
    }

    private function _registerEvents()
    {
        // Register Utility
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = TranslateUtility::class;
            }
        );
        
        // Listen to Element Saved event
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_SAVE_ELEMENT,
            function (Event $event) {
                if ($event->isNew) {
                    $this->translation->handleElementSaved($event->element, $event->isNew);
                }
            }
        );
        
        // Hook into Edit templates to add sidebar button
        Craft::$app->getView()->hook('cp.entries.edit.details', function(array &$context) {
            return $this->_renderSidebarButton($context['entry'] ?? null);
        });

        Craft::$app->getView()->hook('cp.commerce.product.edit.details', function(array &$context) {
            return $this->_renderSidebarButton($context['product'] ?? null);
        });
        
        // For Calendar Events, inject manually if specific hook is missing
        Event::on(View::class, View::EVENT_END_BODY, function(\yii\base\Event $event) {
            $request = Craft::$app->getRequest();
            if ($request->getIsCpRequest() && !$request->getIsConsoleRequest() && !$request->getIsAjax()) {
                $path = $request->getPathInfo();
                if (str_contains($path, 'calendar/events/') && !str_contains($path, 'new')) {
                    // It's a calendar event edit page, we can try extracting element ID from URL
                    $parts = explode('/', $path);
                    $eventId = end($parts);
                    if (is_numeric($eventId)) {
                        $eventElement = Craft::$app->getElements()->getElementById($eventId);
                        if ($eventElement) {
                            $buttonHtml = $this->_renderSidebarButton($eventElement);
                            // We can render a JS snippet to inject it
                            echo "<div id='auto-translator-injected' style='display:none;'>{$buttonHtml}</div>";
                            echo "<script>
                                setTimeout(function() {
                                    var \$sidebar = $('#details, #settings');
                                    if (\$sidebar.length > 0) {
                                        var html = $('#auto-translator-injected').html();
                                        \$sidebar.append(html);
                                    }
                                }, 500);
                            </script>";
                        }
                    }
                }
            }
        });
    }

    private function _renderSidebarButton($element)
    {
        if (!$element || !$element->id) return '';
        try {
            return Craft::$app->getView()->renderTemplate('auto-translator/_components/sidebar-button', [
                'element' => $element
            ]);
        } catch (\Throwable $e) {
            Craft::error('Failed to render sidebar button: ' . $e->getMessage(), __METHOD__);
            return '';
        }
    }
}
