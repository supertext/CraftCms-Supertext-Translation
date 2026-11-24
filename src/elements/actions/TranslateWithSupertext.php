<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Queue;
use supertext\crafttranslation\jobs\TranslateEntryJob;

/**
 * Index action (entries, categories, assets): queues a translation of each selected item from
 * the site the index shows into all its other sites. Sites that already have their own text
 * are skipped (use the item's Supertext box to overwrite them).
 */
class TranslateWithSupertext extends ElementAction
{
    public static function displayName(): string
    {
        return Craft::t('supertext-translation', 'Translate with Supertext');
    }

    public function getConfirmationMessage(): ?string
    {
        return Craft::t('supertext-translation', 'Translate the selected items into all their other sites? Sites that already have their own text are skipped.');
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $user = Craft::$app->getUser()->getIdentity();
        $count = 0;
        foreach ($query->all() as $element) {
            $siteIds = [];
            foreach ($element->getSupportedSites() as $site) {
                // Sites the item isn't saved to (e.g. assets in a volume that lets each asset choose) are left out.
                if (\is_array($site) && ($site['propagate'] ?? true) === false) {
                    continue;
                }
                $siteId = \is_array($site) ? (int) $site['siteId'] : (int) $site;
                if ($siteId !== (int) $element->siteId) {
                    $siteIds[] = $siteId;
                }
            }
            if ($siteIds === []) {
                continue;
            }
            Queue::push(new TranslateEntryJob([
                'entryId' => $element->getCanonicalId(),
                'sourceSiteId' => (int) $element->siteId,
                'targetSiteIds' => $siteIds,
                'userId' => $user?->id,
            ]));
            $count++;
        }

        $this->setMessage(Craft::t('supertext-translation', '{count, plural, =1{One item is} other{# items are}} being translated in the background.', ['count' => $count]));

        return true;
    }
}
