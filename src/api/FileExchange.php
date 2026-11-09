<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\api;

use supertext\crafttranslation\sdk\Files\FileJob;
use supertext\crafttranslation\sdk\Files\FileSegment;
use supertext\crafttranslation\sdk\Files\ImportedSegment;
use supertext\crafttranslation\sdk\Files\TranslationFile;
use supertext\crafttranslation\sdk\Files\TranslationFileError;
use supertext\crafttranslation\sdk\Files\TranslationFiles;

/**
 * "Export for translation" and "Import translation": an entry's texts as an XLIFF 1.2 or HTML
 * file (the SDK's `Files\TranslationFiles`), translated outside the API, and the translated file
 * written back like an API translation.
 *
 * One job = one entry from its source site into one target site. The segments are the units the
 * API translation collects (`Translator::fileSegments()`), keyed `<element id>.<title|slug|field handle>`.
 * Reference: `entry:<entry id>:site-<source site id>:site-<target site id>`, e.g. `entry:5:site-1:site-3`.
 *
 * No Craft dependencies (tests/unit/FileExchangeTest.php); Translator loads and writes the entries.
 */
final class FileExchange
{
    /** The `plugin` of every job: this repository's name. */
    public const PLUGIN = 'CraftCms-Supertext-Translation';

    /** Uploads larger than this are refused before they are read. */
    public const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    public const FORMAT_XLIFF = 'xliff';
    public const FORMAT_HTML = 'html';

    /** Import errors (Messages::fileError() shows them); the TranslationFileError codes come from read(). */
    public const OTHER_SYSTEM = 'other_system';
    public const NOT_FOUND = 'not_found';
    public const NO_LANGUAGE = 'no_language';
    public const FORBIDDEN = 'forbidden';
    public const ALREADY_TRANSLATED = 'already_translated';

    private const REFERENCE = '/^entry:([1-9]\d*):site-([1-9]\d*):site-([1-9]\d*)$/';

    public static function reference(int $entryId, int $sourceSiteId, int $targetSiteId): string
    {
        return "entry:$entryId:site-$sourceSiteId:site-$targetSiteId";
    }

    /** @return array{entryId: int, sourceSiteId: int, targetSiteId: int}|null */
    public static function parseReference(string $reference): ?array
    {
        if (!preg_match(self::REFERENCE, $reference, $m)) {
            return null;
        }

        return ['entryId' => (int) $m[1], 'sourceSiteId' => (int) $m[2], 'targetSiteId' => (int) $m[3]];
    }

    /** A site language as a language tag (`de_CH` → `de-CH`), for sites without a Supertext code. */
    public static function fallbackCode(string $language): string
    {
        return str_replace('_', '-', $language);
    }

    /**
     * The file for one entry and the given target sites (XLIFF: one or more, HTML: exactly one), or
     * null when the entry has nothing to translate.
     *
     * @param list<array{key: string, text: string, html: bool}> $segments
     * @param list<array{siteId: int, handle: string, language: string}> $targets `language`: the Supertext code (or the fallback code)
     * @return array{content: string, filename: string, contentType: string}|null
     */
    public static function export(int $entryId, int $sourceSiteId, string $sourceLanguage, array $targets, array $segments, string $format): ?array
    {
        if ($targets === []) {
            throw new \InvalidArgumentException('Choose at least one target site.');
        }
        if ($format === self::FORMAT_HTML && \count($targets) !== 1) {
            throw new \InvalidArgumentException('An HTML file holds exactly one target language.');
        }

        $fileSegments = [];
        foreach ($segments as $segment) {
            if (trim($segment['text']) !== '') {
                $fileSegments[] = new FileSegment($segment['key'], $segment['text'], $segment['html']);
            }
        }
        if ($fileSegments === []) {
            return null;
        }

        $jobs = [];
        foreach ($targets as $target) {
            $jobs[] = new FileJob(self::PLUGIN, self::reference($entryId, $sourceSiteId, $target['siteId']), $sourceLanguage, $target['language'], $fileSegments);
        }

        $name = 'craft-' . $entryId . (\count($targets) === 1 ? '-' . preg_replace('/[^A-Za-z0-9_-]+/', '', $targets[0]['handle']) : '');
        if ($format === self::FORMAT_HTML) {
            return ['content' => TranslationFiles::exportHtml($jobs[0]), 'filename' => $name . '.html', 'contentType' => 'text/html; charset=utf-8'];
        }

        return ['content' => TranslationFiles::exportXliff($jobs), 'filename' => $name . '.xlf', 'contentType' => 'application/x-xliff+xml'];
    }

    /**
     * @return list<TranslationFile>
     * @throws TranslationFileError invalid_file, missing_metadata, too_large
     */
    public static function read(string $content): array
    {
        return TranslationFiles::read($content);
    }

    /**
     * Imports one job of a file.
     *
     * `$open(entryId, sourceSiteId, targetSiteId)` loads the entry and the target site with the user's
     * permissions and returns either `['error' => <code>, 'params' => [...]]` or
     * `['title' => …, 'site' => …, 'segments' => <current segments, as for the export>, 'write' => callable(array<string, string> key => text)]`.
     *
     * @param callable(int, int, int): array<string, mixed> $open
     * @return array{entryId: ?int, siteId: ?int, title: string, site: string, language: string, error: ?string, params: array<string, string>, counts: array<string, int>, imported: int}
     */
    public static function import(TranslationFile $file, bool $includeChanged, callable $open): array
    {
        $result = [
            'entryId' => null,
            'siteId' => null,
            'title' => $file->reference,
            'site' => $file->targetLanguage,
            'language' => $file->targetLanguage,
            'error' => null,
            'params' => [],
            'counts' => array_fill_keys([ImportedSegment::OK, ImportedSegment::SOURCE_CHANGED, ImportedSegment::UNTRANSLATED, ImportedSegment::INVALID_MARKUP, ImportedSegment::MISSING], 0),
            'imported' => 0,
        ];

        if ($file->plugin !== self::PLUGIN) {
            return ['error' => self::OTHER_SYSTEM, 'params' => ['plugin' => $file->plugin]] + $result;
        }
        $reference = self::parseReference($file->reference);
        if ($reference === null) {
            return ['error' => self::NOT_FOUND] + $result;
        }

        $result['entryId'] = $reference['entryId'];
        $result['siteId'] = $reference['targetSiteId'];
        $opened = $open($reference['entryId'], $reference['sourceSiteId'], $reference['targetSiteId']);
        $result['title'] = (string) ($opened['title'] ?? $result['title']);
        $result['site'] = (string) ($opened['site'] ?? $result['site']);
        if (isset($opened['error'])) {
            return ['error' => (string) $opened['error'], 'params' => array_map('strval', (array) ($opened['params'] ?? []))] + $result;
        }

        $current = [];
        foreach ($opened['segments'] ?? [] as $segment) {
            if (trim($segment['text']) !== '') {
                $current[] = new FileSegment($segment['key'], $segment['text'], $segment['html']);
            }
        }

        $translations = [];
        foreach (TranslationFiles::importTranslations($file, $current) as $segment) {
            $result['counts'][$segment->status]++;
            if ($segment->text !== null && ($segment->status === ImportedSegment::OK || ($includeChanged && $segment->status === ImportedSegment::SOURCE_CHANGED))) {
                $translations[$segment->key] = $segment->text;
            }
        }

        if ($translations !== []) {
            ($opened['write'])($translations);
        }
        $result['imported'] = \count($translations);

        return $result;
    }
}
