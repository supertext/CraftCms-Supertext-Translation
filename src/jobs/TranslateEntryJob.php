<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\jobs;

use Craft;
use craft\elements\User;
use craft\queue\BaseJob;
use supertext\crafttranslation\api\SupertextException;
use supertext\crafttranslation\Plugin;
use supertext\crafttranslation\services\Translator;

/**
 * Translates one entry, category or asset into several sites (from the index action).
 * `entryId` is the element's id; the class and property keep their names for jobs already queued.
 */
class TranslateEntryJob extends BaseJob
{
    public int $entryId;
    public int $sourceSiteId;
    /** @var list<int> */
    public array $targetSiteIds = [];
    public bool $overwrite = false;
    public ?int $userId = null;

    public function execute($queue): void
    {
        $user = $this->userId ? User::find()->id($this->userId)->status(null)->one() : null;
        $results = Plugin::getInstance()->getTranslator()->translate($this->entryId, $this->sourceSiteId, $this->targetSiteIds, $this->overwrite, $user);
        $errors = array_filter($results, static fn(array $r) => $r['status'] === Translator::STATUS_ERROR);
        if ($errors !== []) {
            throw new SupertextException(implode('; ', array_map(static fn(array $r) => $r['site'] . ': ' . ($r['message'] ?? ''), $errors)));
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('supertext-translation', 'Translating item {id} with Supertext', ['id' => $this->entryId]);
    }
}
