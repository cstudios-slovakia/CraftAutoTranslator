<?php

namespace cstudios\autotranslator\models;

use craft\base\Model;

class Settings extends Model
{
    public string $openaiApiKey = '';
    public bool $enableAutoTranslation = true;

    protected function defineRules(): array
    {
        return [
            [['openaiApiKey'], 'string'],
            [['enableAutoTranslation'], 'boolean'],
            [['openaiApiKey'], 'required', 'when' => function($model) {
                return $model->enableAutoTranslation;
            }],
        ];
    }
}
