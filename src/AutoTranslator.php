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
    public static ?AutoTranslator $plugin = null;

    private bool $sidebarRendered = false;
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
        
        // Hook into Edit templates to add sidebar button (Craft 3/4)
        Craft::$app->getView()->hook('cp.entries.edit.details', function(array &$context) {
            return $this->_renderSidebarButton($context['entry'] ?? $context['element'] ?? null);
        });

        Craft::$app->getView()->hook('cp.commerce.product.edit.details', function(array &$context) {
            return $this->_renderSidebarButton($context['product'] ?? $context['element'] ?? null);
        });

        // Craft 5 generic element edit hook
        Craft::$app->getView()->hook('cp.elements.edit.details', function(array &$context) {
            return $this->_renderSidebarButton($context['element'] ?? $context['entry'] ?? null);
        });

        // Fallback JS injection for any element edit page (Craft 5 URL structures & Vue apps like Calendar)
        Event::on(View::class, View::EVENT_END_BODY, function(\yii\base\Event $event) {
            if ($this->sidebarRendered) {
                return;
            }
            $request = Craft::$app->getRequest();
            if ($request->getIsCpRequest() && !$request->getIsConsoleRequest() && !$request->getIsAjax()) {
                $path = $request->getPathInfo();

                // Broadly match any CP path that ends with an ID (e.g., calendar/events/1670)
                if (preg_match('/\/(\d+)(?:-[^\/]+)?$/', $path, $matches)) {
                    $eventId = $matches[1];
                    if (is_numeric($eventId)) {
                        $siteId = $this->_resolveCurrentSiteId();
                        $eventElement = Craft::$app->getElements()->getElementById((int)$eventId, null, $siteId);

                        // Ensure it's a translatable element with supported sites
                        if ($eventElement && method_exists($eventElement, 'getSupportedSites')) {
                            $this->sidebarRendered = true;
                            $buttonHtml = $this->_renderSidebarButton($eventElement);

                            echo "<div id='auto-translator-injected' style='display:none;'>{$buttonHtml}</div>";
                            echo "<script>
                                (function() {
                                    var maxTries = 20;
                                    var tries = 0;
                                    var injectInterval = setInterval(function() {
                                        var \$sidebar = $('#details, #settings, .meta, #sidebar, .sidebar, .layout-sidebar');
                                        if (\$sidebar.length > 0 && $('#auto-translator-sidebar').length === 0) {
                                            var html = $('#auto-translator-injected').html();
                                            \$sidebar.first().append(html);
                                            clearInterval(injectInterval);
                                        }
                                        tries++;
                                        if (tries >= maxTries) clearInterval(injectInterval);
                                    }, 500);
                                })();
                            </script>";
                        }
                    }
                }
            }
        });
    }

    /**
     * Resolve which site the user is currently editing.
     *
     * Order of preference:
     *  1. `site` query parameter (Craft 5 element editor uses ?site=handle)
     *  2. `siteId` query parameter
     *  3. The user's currently-selected CP site
     */
    private function _resolveCurrentSiteId(): ?int
    {
        $request = Craft::$app->getRequest();
        $sitesService = Craft::$app->getSites();

        $siteHandle = $request->getQueryParam('site');
        if ($siteHandle) {
            $site = $sitesService->getSiteByHandle($siteHandle);
            if ($site) {
                return $site->id;
            }
        }

        $siteIdParam = $request->getQueryParam('siteId');
        if ($siteIdParam && is_numeric($siteIdParam)) {
            $site = $sitesService->getSiteById((int)$siteIdParam);
            if ($site) {
                return $site->id;
            }
        }

        try {
            return $sitesService->getCurrentSite()->id;
        } catch (\Throwable $e) {
            return $sitesService->getPrimarySite()->id;
        }
    }

    private function _renderSidebarButton($element)
    {
        if (!$element || !$element->id) return '';
        if ($this->sidebarRendered) return '';
        try {
            $html = Craft::$app->getView()->renderTemplate('auto-translator/_components/sidebar-button', [
                'element' => $element,
                'currentSiteId' => $element->siteId ?? $this->_resolveCurrentSiteId(),
            ]);
            $this->sidebarRendered = true;
            return $html;
        } catch (\Throwable $e) {
            Craft::error('Failed to render sidebar button: ' . $e->getMessage(), __METHOD__);
            return '';
        }
    }
}
