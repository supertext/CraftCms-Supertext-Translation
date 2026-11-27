<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\helpers;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\data\LinkData;
use craft\fields\Table;

/**
 * Reads and builds the values of the field types whose texts sit inside a structure: Table cells,
 * Link texts, SEOmatic's texts and the chunks of a CKEditor field with nested entries.
 *
 * CKEditor and SEOmatic are other plugins, so their values are only touched through their
 * public methods and properties (the classes may not be installed).
 */
final class FieldValues
{
    public const CKEDITOR_FIELD = 'craft\\ckeditor\\Field';
    public const SEOMATIC_FIELD = 'nystudio107\\seomatic\\fields\\SeoSettings';

    /** Table column types whose cells are text (single line, multi-line, row heading). */
    public const TABLE_TEXT_COLUMNS = ['singleline', 'multiline', 'heading'];

    /** Link field texts: the label and, where the field shows them, the title text and the ARIA label. */
    public const LINK_TEXTS = ['label', 'title', 'ariaLabel'];

    /** SEOmatic texts (its `metaGlobalVars`); each has a `<name>Source` setting. */
    public const SEO_TEXTS = [
        'seoTitle', 'seoDescription', 'seoKeywords', 'seoImageDescription',
        'ogTitle', 'ogDescription', 'ogImageDescription',
        'twitterTitle', 'twitterDescription', 'twitterImageDescription',
    ];

    /**
     * The chunks of a CKEditor value in document order: HTML between nested entries, and the
     * nested entries (any status). Empty for any other value.
     *
     * @return list<array{html: string, entry: null}|array{html: null, entry: ?Entry}>
     */
    public static function chunks(mixed $value): array
    {
        if (!\is_object($value) || !method_exists($value, 'getChunks')) {
            return [];
        }
        $chunks = [];
        foreach ($value->getChunks(false) as $chunk) {
            // CKEditor's chunks: `craft\ckeditor\data\Entry` (type `entry`) and `…\Markup` (type `markup`).
            if ($chunk->getType() === 'entry') {
                $entry = $chunk->getEntry();
                $chunks[] = ['html' => null, 'entry' => $entry instanceof Entry ? $entry : null];
            } else {
                $chunks[] = ['html' => (string) ($chunk->rawHtml ?? ''), 'entry' => null];
            }
        }

        return $chunks;
    }

    /**
     * An HTML field's stored HTML: CKEditor's and Redactor's raw content, with reference tags such as
     * `{entry:12@1:url||…}` and `{asset:5:url||…}` as they are (casting the value to a string
     * would resolve them to addresses).
     */
    public static function html(mixed $value): string
    {
        if (\is_object($value) && method_exists($value, 'getRawContent')) {
            return (string) $value->getRawContent();
        }

        return \is_string($value) || $value instanceof \Stringable ? (string) $value : '';
    }

    /** @param list<array{html: ?string, entry: ?Entry}> $chunks */
    public static function hasEntries(array $chunks): bool
    {
        foreach ($chunks as $chunk) {
            if ($chunk['html'] === null) {
                return true;
            }
        }

        return false;
    }

    /** CKEditor's markup for a nested entry, as CKEditor writes it. */
    public static function entryTag(int $entryId): string
    {
        return \sprintf('<craft-entry data-entry-id="%d">&nbsp;</craft-entry>', $entryId);
    }

    /** @return array<int, array<string, mixed>> the table's rows keyed by column id (`col1`, …) */
    public static function tableRows(Table $field, ElementInterface $element): array
    {
        $rows = $field->serializeValue($element->getFieldValue($field->handle), $element);

        return \is_array($rows) ? array_values(array_filter($rows, '\is_array')) : [];
    }

    /** @return list<string> column ids of the text columns */
    public static function tableTextColumns(Table $field): array
    {
        $ids = [];
        foreach ($field->columns as $id => $column) {
            if (\in_array($column['type'] ?? '', self::TABLE_TEXT_COLUMNS, true)) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * The translated table: the target's rows when it has as many as the source (so its other cells
     * stay), else the source's rows, with the translated cells.
     *
     * @param array<string, string> $texts `table:<row>:<column id>` => text
     * @return array<int, array<string, mixed>>
     */
    public static function composeTable(Table $field, ElementInterface $source, ElementInterface $target, array $texts): array
    {
        $sourceRows = self::tableRows($field, $source);
        $targetRows = self::tableRows($field, $target);
        $rows = \count($targetRows) === \count($sourceRows) ? $targetRows : $sourceRows;
        foreach ($texts as $path => $text) {
            [, $row, $column] = explode(':', $path, 3) + [null, null, null];
            if (isset($rows[(int) $row]) && $column !== null) {
                $rows[(int) $row][$column] = $text;
            }
        }

        return $rows;
    }

    public static function linkText(LinkData $link, string $name): string
    {
        return match ($name) {
            'label' => (string) $link->getLabel(true),
            'title' => (string) $link->title,
            'ariaLabel' => (string) $link->ariaLabel,
            default => '',
        };
    }

    /**
     * The translated link: the target's link (its URL or element may differ per site), else the
     * source's, with the translated texts.
     *
     * @param array<string, string> $texts `link:<label|title|ariaLabel>` => text
     * @return array<string, mixed>|null
     */
    public static function composeLink(FieldInterface $field, ElementInterface $source, ElementInterface $target, array $texts): ?array
    {
        $value = $target->getFieldValue($field->handle);
        $base = $value instanceof LinkData ? $value : $source->getFieldValue($field->handle);
        if (!$base instanceof LinkData) {
            return null;
        }
        $link = $field->serializeValue($base, $target);
        if (!\is_array($link)) {
            return null;
        }
        foreach ($texts as $path => $text) {
            $name = substr($path, \strlen('link:'));
            if (\in_array($name, self::LINK_TEXTS, true)) {
                $link[$name] = $text;
            }
        }

        return $link;
    }

    /** @return array<string, mixed> SEOmatic's value as an array (`metaGlobalVars`, `metaBundleSettings`, …) */
    public static function seo(FieldInterface $field, ElementInterface $element): array
    {
        $value = $field->serializeValue($element->getFieldValue($field->handle), $element);
        if (\is_string($value)) {
            $value = json_decode($value, true);
        }

        return \is_array($value) ? $value : [];
    }

    /** A SEOmatic text that is written by hand (not a template or taken from another field). */
    public static function seoText(array $seo, string $name): string
    {
        $text = $seo['metaGlobalVars'][$name] ?? '';
        if (!\is_string($text) || str_contains($text, '{')) {
            return '';
        }
        $source = $seo['metaBundleSettings'][$name . 'Source'] ?? null;
        if (\is_string($source) && $source !== '' && $source !== 'fromCustom') {
            return '';
        }

        return trim($text);
    }

    /**
     * The translated SEO settings: the target's, with the translated texts and the source's
     * "written by hand" setting for each of them.
     *
     * @param array<string, string> $texts `seo:<name>` => text
     * @return array<string, mixed>
     */
    public static function composeSeo(FieldInterface $field, ElementInterface $source, ElementInterface $target, array $texts): array
    {
        $seo = self::seo($field, $target);
        $sourceSettings = self::seo($field, $source)['metaBundleSettings'] ?? [];
        foreach ($texts as $path => $text) {
            $name = substr($path, \strlen('seo:'));
            if (!\in_array($name, self::SEO_TEXTS, true)) {
                continue;
            }
            $seo['metaGlobalVars'][$name] = $text;
            if (\is_array($sourceSettings) && isset($sourceSettings[$name . 'Source'])) {
                $seo['metaBundleSettings'][$name . 'Source'] = $sourceSettings[$name . 'Source'];
            }
        }

        return $seo;
    }
}
