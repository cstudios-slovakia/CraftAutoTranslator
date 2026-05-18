<?php

namespace cstudios\autotranslator\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use cstudios\autotranslator\AutoTranslator;
use OpenAI;

class OpenAiService extends Component
{
    /**
     * Translates an array of text fields or HTML content to the target language.
     *
     * @param array $content The content to translate, structured as fieldHandle => content.
     * @param string $sourceLanguage The source language (e.g., 'en', 'sk').
     * @param string $targetLanguage The target language (e.g., 'de', 'fr').
     * @return array|null Returns the translated array, or null on failure.
     */
    public function translate(array $content, string $sourceLanguage, string $targetLanguage): ?array
    {
        $settings = AutoTranslator::$plugin->getSettings();
        $apiKey = App::parseEnv($settings->openaiApiKey);

        if (empty($apiKey)) {
            Craft::error('OpenAI API key is missing.', __METHOD__);
            return null;
        }

        try {
            $client = OpenAI::client($apiKey);
            
            $jsonContent = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $systemPrompt = "You are a professional translator. You will receive a JSON object representing fields of a CMS entry. Your task is to translate all text values from the source language '$sourceLanguage' to the target language '$targetLanguage'. Maintain the exact same JSON structure, keys, and any HTML formatting or tags. Only translate the textual content. IMPORTANT: If a value is too short, unclear, gibberish, a code snippet, or otherwise untranslatable, return the original value UNCHANGED. Never return explanations, apologies, or error messages — always return a valid JSON value for every key.";

            $response = $client->chat()->create([
                'model' => 'gpt-4o',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $jsonContent],
                ],
                'response_format' => ['type' => 'json_object'],
            ]);

            $translatedJson = $response->choices[0]->message->content;
            
            $translatedContent = json_decode($translatedJson, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->_filterRefusals($content, $translatedContent);
            }
            
            Craft::error('Invalid JSON returned from OpenAI: ' . $translatedJson, __METHOD__);
            return null;

        } catch (\Exception $e) {
            Craft::error('OpenAI Translation failed: ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    private function _filterRefusals(array $original, array $translated): array
    {
        $refusalPatterns = [
            "/i'?m sorry/i",
            '/i need more context/i',
            '/i cannot translate/i',
            '/please provide/i',
            '/could you please/i',
            '/i don\'?t understand/i',
            '/unclear text/i',
            '/not enough context/i',
            '/unable to translate/i',
        ];

        foreach ($translated as $key => $value) {
            if (is_string($value)) {
                foreach ($refusalPatterns as $pattern) {
                    if (preg_match($pattern, $value)) {
                        Craft::warning("OpenAI returned a refusal for field '$key', keeping original value.", __METHOD__);
                        $translated[$key] = $original[$key] ?? $value;
                        break;
                    }
                }
            } elseif (is_array($value) && isset($original[$key]) && is_array($original[$key])) {
                $translated[$key] = $this->_filterRefusals($original[$key], $value);
            }
        }

        return $translated;
    }
}
