<?php

namespace cstudios\autotranslator\jobs;

use Craft;
use craft\queue\BaseJob;
use cstudios\autotranslator\AutoTranslator;

class TranslateElementJob extends BaseJob
{
    public int $elementId;
    public ?int $sourceSiteId = null;
    public int $targetSiteId;

    public function execute($queue): void
    {
        $this->setProgress($queue, 0, 'Translating element...');
        
        $success = AutoTranslator::$plugin->translation->translateElement($this->elementId, $this->sourceSiteId, $this->targetSiteId);

        if (!$success) {
            $msg = "Failed to translate element ID {$this->elementId} to site ID {$this->targetSiteId}. Check @storage/logs/auto-translator.log for details.";
            Craft::error($msg, __METHOD__);
            // Throw so the queue marks this job as failed and the sidebar UI
            // shows an error toast instead of a false "Translation complete".
            throw new \RuntimeException($msg);
        }

        $this->setProgress($queue, 1, 'Translation completed');
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('auto-translator', 'Translating Element ID {id}', ['id' => $this->elementId]);
    }
}
