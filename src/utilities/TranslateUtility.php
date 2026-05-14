<?php

namespace cstudios\autotranslator\utilities;

use Craft;
use craft\base\Utility;
use craft\elements\Entry;
use craft\commerce\elements\Product;

class TranslateUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('auto-translator', 'Auto Translator');
    }

    public static function id(): string
    {
        return 'auto-translator-utility';
    }

    public static function iconPath(): ?string
    {
        return Craft::getAlias('@appicons/translate.svg');
    }

    public static function contentHtml(): string
    {
        $sites = Craft::$app->getSites()->getAllSites();
        $siteOptions = [];
        foreach ($sites as $site) {
            $siteOptions[] = [
                'label' => $site->name . ' (' . $site->language . ')',
                'value' => $site->id,
            ];
        }

        $elementTypes = [
            ['label' => 'Entries', 'value' => Entry::class],
        ];
        
        if (class_exists(Product::class)) {
            $elementTypes[] = ['label' => 'Products', 'value' => Product::class];
        }
        if (class_exists('\Solspace\Calendar\Elements\Event')) {
            $elementTypes[] = ['label' => 'Calendar Events', 'value' => '\Solspace\Calendar\Elements\Event'];
        }

        return Craft::$app->getView()->renderTemplate('auto-translator/utility', [
            'siteOptions' => $siteOptions,
            'elementTypes' => $elementTypes,
        ]);
    }
}
