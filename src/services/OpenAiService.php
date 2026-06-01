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
     * @param array $fieldLimits Per-field max-length constraints keyed by handle:
     *                           ['handle' => ['unit' => 'characters'|'words', 'limit' => int]].
     * @return array|null Returns the translated array, or null on failure.
     */
    public function translate(array $content, ?string $sourceLanguage, string $targetLanguage, array $fieldLimits = []): ?array
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

            if ($sourceLanguage !== null) {
                $langInstruction = "translate all text values from the source language '$sourceLanguage' to the target language '$targetLanguage'";
            } else {
                $langInstruction = "auto-detect the source language of each text value and translate it to the target language '$targetLanguage'";
            }

            $systemPrompt = "You are a professional translator. You will receive a JSON object representing fields of a CMS entry. Your task is to $langInstruction. Maintain the exact same JSON structure, keys, and any HTML formatting or tags. Only translate the textual content.\n\n"
                . "ALWAYS attempt the translation. Translate every descriptive word, even when the text contains proper nouns, brand names, event names, dates, numbers, or place names — translate the descriptive/common-noun parts around them and keep the proper nouns, brand names, person names, and place names in their original form. For example 'Letný hudobný festival Pohoda 2025' → Hungarian: 'Pohoda 2025 nyári zenei fesztivál' (the brand 'Pohoda' and year stay, the surrounding words are translated).\n\n"
                . "Only keep a value unchanged if it is literally a code snippet, a URL, a single number, an email address, or a slug-like token with no natural-language words. Titles, headings, names of events, and any phrase containing at least one common-language word MUST be translated. Never return the source text unchanged just because it contains a name.\n\n"
                . "Never return explanations, apologies, refusals, or error messages — always return a valid translated JSON value for every key.";

            $systemPrompt .= $this->_buildLimitInstruction($fieldLimits);

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
            throw new \RuntimeException('OpenAI returned malformed JSON.');
        } catch (\OpenAI\Exceptions\ErrorException $e) {
            // OpenAI API responded with an error (rate limit, quota, invalid key, etc).
            // Re-throw with a user-readable message so the queue records it and the
            // sidebar JS can surface it in a toast.
            $code = $e->getErrorCode();
            $type = $e->getErrorType();
            $raw = $e->getMessage();

            Craft::error("OpenAI API error [code={$code} type={$type}]: {$raw}", __METHOD__);

            $userMsg = match (true) {
                $code === 'insufficient_quota' || $type === 'insufficient_quota' =>
                    'OpenAI quota exceeded. Check your OpenAI account billing/usage.',
                $code === 'rate_limit_exceeded' || str_contains(strtolower($raw), 'rate limit') =>
                    'OpenAI rate limit hit. Retry in a moment.',
                $code === 'invalid_api_key' || $type === 'invalid_request_error' && str_contains(strtolower($raw), 'api key') =>
                    'OpenAI API key is invalid. Update it in plugin settings.',
                $code === 'model_not_found' =>
                    'OpenAI model not available for this API key.',
                default => 'OpenAI error: ' . $raw,
            };

            throw new \RuntimeException($userMsg, 0, $e);
        } catch (\OpenAI\Exceptions\TransporterException $e) {
            Craft::error('OpenAI network error: ' . $e->getMessage(), __METHOD__);
            throw new \RuntimeException('Could not reach OpenAI (network error): ' . $e->getMessage(), 0, $e);
        } catch (\RuntimeException $e) {
            // Already-formatted user-facing message — bubble up.
            throw $e;
        } catch (\Throwable $e) {
            Craft::error('OpenAI Translation failed: ' . $e->getMessage(), __METHOD__);
            throw new \RuntimeException('Translation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Build an instruction block listing the fields that have a hard max length,
     * so the model produces translations that fit and the CMS save doesn't fail
     * validation. Returns an empty string when there are no constrained fields.
     */
    private function _buildLimitInstruction(array $fieldLimits): string
    {
        if (empty($fieldLimits)) {
            return '';
        }

        $lines = [];
        foreach ($fieldLimits as $handle => $info) {
            $limit = (int)($info['limit'] ?? 0);
            $unit = $info['unit'] ?? 'characters';
            if ($limit > 0) {
                $lines[] = "- \"$handle\": at most $limit $unit";
            }
        }

        if (empty($lines)) {
            return '';
        }

        return "\n\nLENGTH LIMITS — STRICT. The CMS rejects values that exceed these maximums, so the translated value for each listed field MUST stay within its limit. If a faithful translation would be too long, rephrase it more concisely (shorten wording, remove redundancy, use shorter synonyms) while preserving the core meaning and any HTML tags. Count includes all characters of the value. The constrained fields are:\n"
            . implode("\n", $lines);
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
