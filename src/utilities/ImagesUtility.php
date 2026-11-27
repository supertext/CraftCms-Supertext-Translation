<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\utilities;

use Craft;
use craft\base\Utility;
use craft\elements\Asset;
use supertext\crafttranslation\helpers\Images;
use supertext\crafttranslation\Plugin;
use supertext\crafttranslation\records\ImageRecord;

/**
 * Utilities → Supertext images: every image in the HTML fields the plugin translated, the source's
 * beside the site's, with its status (*To check* first). See helpers\Images.
 */
class ImagesUtility extends Utility
{
    public const PER_PAGE = 50;

    public static function displayName(): string
    {
        return Craft::t('supertext-translation', 'Supertext images');
    }

    public static function id(): string
    {
        return 'supertext-images';
    }

    public static function icon(): ?string
    {
        return 'image';
    }

    public static function contentHtml(): string
    {
        $request = Craft::$app->getRequest();
        $status = (string) $request->getQueryParam('status', 'all');
        if (!\in_array($status, ['all', Images::CHECK, Images::OWN, Images::SAME], true)) {
            $status = 'all';
        }
        $rows = [];
        $counts = ['all' => 0, Images::CHECK => 0, Images::OWN => 0, Images::SAME => 0];
        $elements = Craft::$app->getElements();
        /** @var ImageRecord $record */
        foreach (ImageRecord::find()->orderBy(['siteId' => SORT_ASC, 'elementId' => SORT_ASC])->all() as $record) {
            $images = json_decode($record->images, true);
            foreach (\is_array($images) ? $images : [] as $image) {
                if (!\is_array($image) || !isset($image['status'], $image['slot'])) {
                    continue;
                }
                $counts['all']++;
                $counts[$image['status']] = ($counts[$image['status']] ?? 0) + 1;
                if ($status !== 'all' && $image['status'] !== $status) {
                    continue;
                }
                $rows[] = [
                    'elementId' => (int) $record->elementId,
                    'siteId' => (int) $record->siteId,
                    'field' => $record->field,
                    'slot' => (int) $image['slot'],
                    'status' => (string) $image['status'],
                    'reason' => isset($image['reason']) ? (string) $image['reason'] : null,
                    'original' => (string) $image['originalSrc'],
                    'src' => (string) $image['src'],
                ];
            }
        }
        $order = [Images::CHECK => 0, Images::OWN => 1, Images::SAME => 2];
        usort($rows, static fn(array $a, array $b) => ($order[$a['status']] ?? 3) <=> ($order[$b['status']] ?? 3));
        $pages = max(1, (int) ceil(\count($rows) / self::PER_PAGE));
        $page = min($pages, max(1, (int) $request->getQueryParam('page', 1)));
        $rows = \array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        foreach ($rows as &$row) {
            $element = Plugin::getInstance()->getTranslator()->elementInSite($row['elementId'], $row['siteId']);
            $row['title'] = $element ? (string) $element : null;
            $row['editUrl'] = $element?->getCpEditUrl();
            $row['site'] = Craft::$app->getSites()->getSiteById($row['siteId'])?->getName() ?? '';
            $field = $element?->getFieldLayout()?->getFieldByHandle($row['field']);
            $row['fieldName'] = $field !== null ? (string) $field->name : $row['field'];
            $row['originalUrl'] = self::url($row['original']);
            $row['url'] = self::url($row['src']);
        }
        unset($row);

        return Craft::$app->getView()->renderTemplate('supertext-translation/_images', [
            'rows' => $rows,
            'status' => $status,
            'counts' => $counts,
            'page' => $page,
            'pages' => $pages,
            'checked' => (bool) $request->getQueryParam('checked'),
        ]);
    }

    /** An image's address for a thumbnail: an asset's URL for `{asset:…}`, else the address itself. */
    private static function url(string $src): ?string
    {
        if (preg_match('/^\{asset:(\d+)[^}]*\}$/', $src, $m)) {
            $asset = Asset::find()->id((int) $m[1])->one();

            return $asset?->getUrl() ?? (preg_match('/\|\|([^}]+)\}$/', $src, $f) ? $f[1] : null);
        }

        return preg_match('#^(https?:)?//|^/#', $src) ? $src : null;
    }
}
