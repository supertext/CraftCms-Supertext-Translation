<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\models\Site;
use supertext\crafttranslation\api\FileExchange;
use supertext\crafttranslation\api\SupertextClient;
use supertext\crafttranslation\api\SupertextException;
use supertext\crafttranslation\helpers\Messages;
use supertext\crafttranslation\Plugin;
use supertext\crafttranslation\records\TranslationRecord;

/**
 * Translates an entry from one site into others.
 *
 * All translatable texts of the entry (title, slug, plain text and rich text fields, and
 * the same fields of its nested Matrix entries) go to Supertext as one HTML document per
 * target site, one `data-st-id` element per field value. The translations are written to
 * the entry in the target site and saved, which creates a revision (the previous version
 * can be restored from the entry's revisions).
 */
class Translator extends Component
{
    public const STATUS_TRANSLATED = 'translated';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';

    /** Rich text field classes whose value is HTML. */
    public const HTML_FIELDS = ['craft\\ckeditor\\Field', 'craft\\redactor\\Field'];

    /** Slugs made of words ("swiss-chocolate-shipped-worldwide") are translated as words. */
    private const SLUG = '/^[\p{Ll}\p{N}]+(?:-[\p{Ll}\p{N}]+)+$/u';

    /** @var (callable(string, string, array<string, string>, ?string, float): array{status: int, body: string, headers: array<string, string>})|null Tests only. */
    public $transport = null;

    /**
     * The entry's sites, for the Supertext box.
     *
     * @return array{sourceSiteId: int, sites: list<array{id: int, handle: string, name: string, language: string, isSource: bool, translated: bool, lastTranslation: ?string, canSave: bool}>}
     */
    public function describe(Entry $entry, ?User $user = null): array
    {
        $source = $this->entryInSite($entry->id, $entry->siteId);
        if (!$source) {
            throw new SupertextException(Craft::t('supertext-translation', 'The entry was not found.'));
        }
        $sourceUnits = $this->collectUnits($source);
        $last = $this->lastTranslations($entry->id);
        $sites = [];

        foreach ($this->supportedSites($entry) as $site) {
            $isSource = $site->id === $source->siteId;
            $target = $isSource ? $source : $this->entryInSite($entry->id, $site->id);
            $sites[] = [
                'id' => $site->id,
                'handle' => $site->handle,
                'name' => $site->getName(),
                'language' => $site->language,
                'isSource' => $isSource,
                'translated' => !$isSource && $target && $this->differs($sourceUnits, $target),
                'lastTranslation' => $last[$site->id] ?? null,
                'canSave' => $target !== null && Craft::$app->getElements()->canSave($target, $user),
            ];
        }

        return ['sourceSiteId' => $source->siteId, 'sites' => $sites];
    }

    /**
     * @param list<int> $targetSiteIds
     * @return list<array{siteId: int, site: string, status: string, message?: string}>
     */
    public function translate(int $entryId, int $sourceSiteId, array $targetSiteIds, bool $overwrite, ?User $user = null): array
    {
        $source = $this->entryInSite($entryId, $sourceSiteId);
        if (!$source) {
            throw new SupertextException(Craft::t('supertext-translation', 'The entry was not found.'));
        }
        if ($user && !$user->can(Plugin::PERMISSION)) {
            throw new SupertextException(Craft::t('supertext-translation', 'You are not allowed to translate with Supertext.'));
        }

        $settings = Plugin::getInstance()->getSettings();
        $reporting = Plugin::getInstance()->getReporting();
        try {
            $client = $this->client();
        } catch (SupertextException $e) {
            $reporting->onRequest();
            throw $e;
        }
        $sourceSite = Craft::$app->getSites()->getSiteById($sourceSiteId);
        $units = $this->collectUnits($source);
        $results = [];
        $rejected = null;

        foreach (array_unique(array_map('intval', $targetSiteIds)) as $siteId) {
            if ($siteId === $sourceSiteId) {
                continue;
            }
            $site = Craft::$app->getSites()->getSiteById($siteId);
            $result = ['siteId' => $siteId, 'site' => $site?->getName() ?? (string) $siteId];

            try {
                $target = $site ? $this->entryInSite($entryId, $siteId) : null;
                if (!$site || !$target) {
                    throw new SupertextException(Craft::t('supertext-translation', 'The entry is not available in this site.'));
                }
                if ($user && !Craft::$app->getElements()->canSave($target, $user)) {
                    throw new SupertextException(Craft::t('supertext-translation', 'You are not allowed to edit the entry in this site.'));
                }

                if (!$overwrite && $this->differs($units, $target)) {
                    $results[] = $result + ['status' => self::STATUS_SKIPPED, 'message' => Craft::t('supertext-translation', 'Already translated.')];
                    continue;
                }

                // Sites with *Translation review* on go through the review service (same key and protocol).
                $translated = $this->translateUnits(
                    $client->forReview($settings->review($site->handle)),
                    $units,
                    $sourceSite ? $settings->sourceCode($sourceSite->handle, $sourceSite->language) : '',
                    $settings->targetCode($site->handle, $site->language),
                    $settings->politeness($site->handle),
                );
                $this->apply($units, $translated, $siteId, $sourceSite?->getName() ?? '', $user);
                $this->remember($entryId, $sourceSiteId, $siteId, $user);
                $results[] = $result + ['status' => self::STATUS_TRANSLATED];
            } catch (\Throwable $e) {
                if (!$e instanceof SupertextException) {
                    Craft::error($e, __METHOD__);
                } elseif ($e->reason === 'auth_failed') {
                    $rejected = $e->getCode();
                }
                $results[] = $result + ['status' => self::STATUS_ERROR, 'message' => Messages::of($e)];
            }
        }

        // Commando Center: a rejected key marks the key check invalid; otherwise version changes are reported.
        if ($rejected !== null) {
            $reporting->authenticationFailed($rejected);
        } else {
            $reporting->onRequest();
        }

        return $results;
    }

    /**
     * Export for translation: the entry's texts in its current site as an XLIFF or HTML file for
     * the given target sites (see FileExchange), or null when there is nothing to translate.
     *
     * @param list<int> $targetSiteIds
     * @return array{content: string, filename: string, contentType: string}|null
     */
    public function exportFile(int $entryId, int $sourceSiteId, array $targetSiteIds, string $format): ?array
    {
        $source = $this->entryInSite($entryId, $sourceSiteId);
        $sourceSite = Craft::$app->getSites()->getSiteById($sourceSiteId);
        if (!$source || !$sourceSite) {
            throw new SupertextException(Craft::t('supertext-translation', 'The entry was not found.'));
        }

        $settings = Plugin::getInstance()->getSettings();
        $supported = array_map(static fn(Site $site) => $site->id, $this->supportedSites($source));
        $targets = [];
        foreach (array_unique(array_map('intval', $targetSiteIds)) as $siteId) {
            $site = Craft::$app->getSites()->getSiteById($siteId);
            if ($site && $siteId !== $sourceSiteId && \in_array($siteId, $supported, true)) {
                $targets[] = [
                    'siteId' => $site->id,
                    'handle' => $site->handle,
                    'language' => $settings->targetCodeOrNull($site->handle, $site->language) ?? FileExchange::fallbackCode($site->language),
                ];
            }
        }
        if ($targets === []) {
            throw new SupertextException(Craft::t('supertext-translation', 'Choose at least one site.'));
        }

        // A language without a Supertext code doesn't block an export: the file may go to any translator.
        try {
            $sourceLanguage = $settings->sourceCode($sourceSite->handle, $sourceSite->language);
        } catch (SupertextException) {
            $sourceLanguage = FileExchange::fallbackCode($sourceSite->language);
        }

        return FileExchange::export($entryId, $sourceSiteId, $sourceLanguage, $targets, $this->fileSegments($this->collectUnits($source)), $format);
    }

    /**
     * Import translation: writes each job of an uploaded file into its entry and target site, through
     * the same write-back as API translations (revision, nested entries, overwrite rule).
     *
     * @return list<array{entryId: ?int, siteId: ?int, title: string, site: string, language: string, error: ?string, params: array<string, string>, counts: array<string, int>, imported: int, message?: string}>
     * @throws \supertext\crafttranslation\sdk\Files\TranslationFileError invalid_file, missing_metadata, too_large
     */
    public function importFile(string $content, bool $includeChanged, bool $overwrite, ?User $user = null): array
    {
        $results = [];
        foreach (FileExchange::read($content) as $file) {
            $open = function (int $entryId, int $sourceSiteId, int $targetSiteId) use ($overwrite, $user): array {
                $source = $this->entryInSite($entryId, $sourceSiteId);
                if (!$source) {
                    return ['error' => FileExchange::NOT_FOUND];
                }
                $site = Craft::$app->getSites()->getSiteById($targetSiteId);
                $target = $site ? $this->entryInSite($entryId, $targetSiteId) : null;
                $info = ['title' => (string) $source->title, 'site' => $site?->getName() ?? ''];
                if (!$site || !$target) {
                    return $info + ['error' => FileExchange::NO_LANGUAGE];
                }
                if ($user && (!$user->can(Plugin::PERMISSION) || !Craft::$app->getElements()->canSave($target, $user))) {
                    return $info + ['error' => FileExchange::FORBIDDEN];
                }
                $units = $this->collectUnits($source);
                if (!$overwrite && $this->differs($units, $target)) {
                    return $info + ['error' => FileExchange::ALREADY_TRANSLATED];
                }

                return $info + [
                    'segments' => $this->fileSegments($units),
                    'write' => function (array $texts) use ($units, $entryId, $sourceSiteId, $targetSiteId, $user): void {
                        $translations = [];
                        foreach ($units as $i => $unit) {
                            if (isset($texts[self::unitKey($unit)])) {
                                $translations[$i] = $texts[self::unitKey($unit)];
                            }
                        }
                        $sourceSite = Craft::$app->getSites()->getSiteById($sourceSiteId);
                        $this->apply($units, $translations, $targetSiteId, $sourceSite?->getName() ?? '', $user);
                        $this->remember($entryId, $sourceSiteId, $targetSiteId, $user);
                    },
                ];
            };

            try {
                $result = FileExchange::import($file, $includeChanged, $open);
            } catch (\Throwable $e) {
                if (!$e instanceof SupertextException) {
                    Craft::error($e, __METHOD__);
                }
                $result = ['entryId' => null, 'siteId' => null, 'title' => $file->reference, 'site' => $file->targetLanguage, 'language' => $file->targetLanguage, 'error' => 'save_failed', 'params' => [], 'counts' => [], 'imported' => 0];
                $result['message'] = Messages::of($e);
            }
            if ($result['error'] !== null) {
                $result['message'] ??= Messages::fileError($result['error'], $result['params'] + ['language' => $result['language']]);
            }
            $results[] = $result;
        }

        return $results;
    }

    /**
     * The units as segments of a translation file, keyed `<element id>.<title|slug|field handle>`
     * (slugs as words, like the API translation sends them).
     *
     * @param list<array{element: ElementInterface, attribute: ?string, field: ?string, kind: string, value: string}> $units
     * @return list<array{key: string, text: string, html: bool}>
     */
    public function fileSegments(array $units): array
    {
        $segments = [];
        foreach ($units as $unit) {
            $segments[] = [
                'key' => self::unitKey($unit),
                'text' => $unit['kind'] === 'slug' ? str_replace('-', ' ', $unit['value']) : $unit['value'],
                'html' => $unit['kind'] === 'html',
            ];
        }

        return $segments;
    }

    /** @param array{element: ElementInterface, attribute: ?string, field: ?string, kind: string, value: string} $unit */
    private static function unitKey(array $unit): string
    {
        return $unit['element']->id . '.' . ($unit['attribute'] ?? $unit['field']);
    }

    public function client(): SupertextClient
    {
        $settings = Plugin::getInstance()->getSettings();
        if ($settings->getApiKey() === '') {
            throw new SupertextException(Messages::noApiKey());
        }

        return new SupertextClient(
            $settings->getApiKey(),
            $settings->getBaseUrl(),
            $this->transport ?? self::guzzleTransport(),
            $settings->timeout,
            reviewUrl: $settings->getReviewUrl(),
            pluginVersion: Plugin::getInstance()->getVersion(),
        );
    }

    /**
     * Every translatable value of the entry (and its nested entries), in document order.
     *
     * @return list<array{element: ElementInterface, attribute: ?string, field: ?string, kind: string, value: string}>
     */
    public function collectUnits(Entry $entry, int $depth = 0): array
    {
        $units = [];
        $type = $entry->getType();

        if ($type->hasTitleField && $type->titleTranslationMethod !== \craft\base\Field::TRANSLATION_METHOD_NONE && (string) $entry->title !== '') {
            $units[] = ['element' => $entry, 'attribute' => 'title', 'field' => null, 'kind' => 'text', 'value' => (string) $entry->title];
        }
        if ($depth === 0 && \is_string($entry->slug) && preg_match(self::SLUG, $entry->slug)) {
            $units[] = ['element' => $entry, 'attribute' => 'slug', 'field' => null, 'kind' => 'slug', 'value' => $entry->slug];
        }

        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $kind = self::kindOf($field);
            if ($kind === null) {
                continue;
            }
            if ($kind === 'nested') {
                if ($depth < 5) {
                    /** @var \craft\elements\db\EntryQuery $query */
                    $query = $entry->getFieldValue($field->handle);
                    foreach ((clone $query)->status(null)->all() as $nested) {
                        array_push($units, ...$this->collectUnits($nested, $depth + 1));
                    }
                }
                continue;
            }
            if (!$field->getIsTranslatable($entry)) {
                continue;
            }
            $value = trim((string) $entry->getFieldValue($field->handle));
            if ($value !== '') {
                $units[] = ['element' => $entry, 'attribute' => null, 'field' => $field->handle, 'kind' => $kind, 'value' => $value];
            }
        }

        return $units;
    }

    /** text | html | nested | null (not translated) */
    public static function kindOf(FieldInterface $field): ?string
    {
        if ($field instanceof Matrix) {
            return 'nested';
        }
        if ($field instanceof PlainText) {
            return 'text';
        }
        foreach (self::HTML_FIELDS as $class) {
            if (is_a($field, $class)) {
                return 'html';
            }
        }

        return null;
    }

    /**
     * Whether the target already has its own text: any translatable value differs from the source.
     *
     * @param list<array{element: ElementInterface, attribute: ?string, field: ?string, kind: string, value: string}> $units
     */
    private function differs(array $units, Entry $target): bool
    {
        foreach ($units as $unit) {
            if ($unit['kind'] === 'slug') {
                continue;
            }
            $element = $this->counterpart($unit['element'], $target->siteId);
            if (!$element) {
                continue;
            }
            $current = trim($unit['attribute'] !== null ? (string) $element->{$unit['attribute']} : (string) $element->getFieldValue($unit['field']));
            if ($current !== '' && $current !== $unit['value']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{element: ElementInterface, attribute: ?string, field: ?string, kind: string, value: string}> $units
     * @return array<int, string> unit index => translation
     */
    private function translateUnits(SupertextClient $client, array $units, string $sourceLanguage, string $targetLanguage, string $politeness): array
    {
        $segments = [];
        foreach ($units as $i => $unit) {
            $segments[$i] = [
                'text' => $unit['kind'] === 'slug' ? str_replace('-', ' ', $unit['value']) : $unit['value'],
                'html' => $unit['kind'] === 'html',
            ];
        }

        return $client->translateSegments($segments, $targetLanguage, $sourceLanguage, $politeness);
    }

    /**
     * Writes the translations to the elements in the target site and saves them
     * (nested entries first, then the entry itself, which creates a revision).
     *
     * @param list<array{element: ElementInterface, attribute: ?string, field: ?string, kind: string, value: string}> $units
     * @param array<int, string> $translations
     */
    private function apply(array $units, array $translations, int $siteId, string $sourceName, ?User $user): void
    {
        /** @var array<string, ElementInterface> $targets */
        $targets = [];
        $order = [];

        foreach ($units as $i => $unit) {
            $text = trim($translations[$i] ?? '');
            if ($text === '') {
                continue;
            }
            $key = $unit['element']->id . ':' . $siteId;
            $targets[$key] ??= $this->counterpart($unit['element'], $siteId);
            $element = $targets[$key];
            if (!$element) {
                continue;
            }
            $order[$key] = $unit['element'] instanceof Entry && $unit['element']->getPrimaryOwnerId() ? 0 : 1;

            if ($unit['kind'] === 'slug') {
                $element->slug = ElementHelper::generateSlug($text, null, Craft::$app->getSites()->getSiteById($siteId)?->language);
            } elseif ($unit['attribute'] !== null) {
                $element->{$unit['attribute']} = $text;
            } else {
                $element->setFieldValue($unit['field'], $text);
            }
        }

        // Nested entries before their owner, so the owner's revision includes them.
        uksort($targets, static fn($a, $b) => ($order[$a] ?? 1) <=> ($order[$b] ?? 1));

        foreach ($targets as $element) {
            if (!$element) {
                continue;
            }
            if ($element instanceof Entry && !$element->getPrimaryOwnerId()) {
                $element->setRevisionNotes(Craft::t('supertext-translation', 'Translated with Supertext from {site}', ['site' => $sourceName]));
                if ($user) {
                    $element->revisionCreatorId = $user->id;
                }
            }
            if (!Craft::$app->getElements()->saveElement($element)) {
                $errors = implode(' ', $element->getFirstErrors());
                throw new SupertextException(Craft::t('supertext-translation', 'The translation could not be saved: {errors}', ['errors' => $errors]));
            }
        }
    }

    private function counterpart(ElementInterface $element, int $siteId): ?ElementInterface
    {
        if ($element->siteId === $siteId) {
            return $element;
        }

        return $this->entryInSite($element->id, $siteId);
    }

    public function entryInSite(int $id, int $siteId): ?Entry
    {
        return Entry::find()->id($id)->siteId($siteId)->status(null)->drafts(null)->provisionalDrafts(null)->one();
    }

    /** @return list<Site> */
    private function supportedSites(Entry $entry): array
    {
        $ids = array_map(static fn($s) => \is_array($s) ? (int) $s['siteId'] : (int) $s, $entry->getSupportedSites());
        $sites = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if (\in_array($site->id, $ids, true)) {
                $sites[] = $site;
            }
        }

        return $sites;
    }

    private function remember(int $entryId, int $sourceSiteId, int $targetSiteId, ?User $user): void
    {
        $record = TranslationRecord::findOne(['elementId' => $entryId, 'targetSiteId' => $targetSiteId]) ?? new TranslationRecord();
        $record->elementId = $entryId;
        $record->targetSiteId = $targetSiteId;
        $record->sourceSiteId = $sourceSiteId;
        $record->userId = $user?->id;
        $record->dateTranslated = Db::prepareDateForDb(new \DateTime());
        $record->save(false);
    }

    /** @return array<int, string> site id => ISO date of the last Supertext translation */
    private function lastTranslations(int $entryId): array
    {
        $dates = [];
        foreach (TranslationRecord::find()->where(['elementId' => $entryId])->all() as $record) {
            $dates[(int) $record->targetSiteId] = (new \DateTime($record->dateTranslated, new \DateTimeZone('UTC')))->format(DATE_ATOM);
        }

        return $dates;
    }

    /** HTTP through Craft's Guzzle client (proxy settings etc. apply). */
    public static function guzzleTransport(): callable
    {
        return static function (string $method, string $url, array $headers, ?string $body, ?float $timeout = null): array {
            $seconds = $timeout !== null && $timeout > 0 ? $timeout : 60.0;
            $response = Craft::createGuzzleClient(['timeout' => $seconds, 'connect_timeout' => min($seconds, 10.0), 'http_errors' => false])->request($method, $url, [
                'headers' => $headers,
                'body' => $body,
            ]);
            $responseHeaders = [];
            foreach ($response->getHeaders() as $name => $values) {
                $responseHeaders[$name] = implode(', ', $values);
            }

            return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody(), 'headers' => $responseHeaders];
        };
    }
}
