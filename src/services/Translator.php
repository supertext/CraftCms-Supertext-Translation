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
use craft\base\Field;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\User;
use craft\fields\data\LinkData;
use craft\fields\Link;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\models\Site;
use supertext\crafttranslation\api\FileExchange;
use supertext\crafttranslation\api\SupertextClient;
use supertext\crafttranslation\api\SupertextException;
use supertext\crafttranslation\helpers\FieldValues;
use supertext\crafttranslation\helpers\Images;
use supertext\crafttranslation\helpers\Links;
use supertext\crafttranslation\helpers\Slugs;
use supertext\crafttranslation\helpers\Messages;
use supertext\crafttranslation\Plugin;
use supertext\crafttranslation\records\ImageRecord;
use supertext\crafttranslation\records\TranslationRecord;

/**
 * Translates an entry, category, global set or asset from one site into others.
 *
 * All translatable texts of the element go to Supertext as one HTML document per target site, one
 * `data-st-id` element per text: title, slug (entries and categories) and alternative text
 * (assets), plain text and rich text fields, Table text cells, Link texts, SEOmatic texts, and the
 * same texts of nested entries (Matrix fields and entries inside CKEditor fields). The translations
 * are written to the element in the target site and saved (for entries this creates a revision, so
 * the previous version can be restored).
 *
 * Entries inside a translatable CKEditor field belong to one site each: the target site's own
 * nested entries are reused in the same order (same entry type), the others are copied from the
 * source like Craft's "copy from site" does, and the field's HTML is rebuilt around them.
 *
 * @phpstan-type Via array{owner: ElementInterface, field: string, ordinal: int}
 * @phpstan-type Unit array{element: ElementInterface, attribute: ?string, field: ?string, path: ?string, kind: string, value: string, depth: int, via: ?Via}
 */
class Translator extends Component
{
    public const STATUS_TRANSLATED = 'translated';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';

    /** Element types the plugin translates. */
    public const ELEMENT_TYPES = [Entry::class, Category::class, GlobalSet::class, Asset::class];

    /** Rich text field classes whose value is HTML. */
    public const HTML_FIELDS = [FieldValues::CKEDITOR_FIELD, 'craft\\redactor\\Field'];

    /** Slugs made of words ("swiss-chocolate-shipped-worldwide") are translated as words. */
    private const SLUG = '/^[\p{Ll}\p{N}]+(?:-[\p{Ll}\p{N}]+)+$/u';

    /** Nested entries are followed this deep. */
    private const MAX_DEPTH = 5;

    /** @var (callable(string, string, array<string, string>, ?string, float): array{status: int, body: string, headers: array<string, string>})|null Tests only. */
    public $transport = null;

    /** @var array<int, Via|null> how each collected element is reached: inside a translatable CKEditor field, or by its id */
    private array $vias = [];

    /** @var array<string, ElementInterface|null> source element id:site id => the element in that site */
    private array $resolved = [];

    /** @var array<string, array{entries: array<int, ?Entry>, displaced: list<Entry>}> owner id:field:site id => its nested entries in that site */
    private array $nestedMaps = [];

    /** *Save translations as drafts*: the draft being written (of the entry in the target site), else null. */
    private ?Entry $draft = null;

    /** @var array<int, Entry> in the draft: canonical nested (Matrix) entry id => the draft's own copy */
    private array $draftNested = [];

    /**
     * The element's sites, for the Supertext box.
     *
     * `available`: the element exists in that site (assets and entries can be saved to some sites only).
     *
     * @return array{sourceSiteId: int, sites: list<array{id: int, handle: string, name: string, language: string, isSource: bool, available: bool, translated: bool, lastTranslation: ?string, canSave: bool}>}
     */
    public function describe(ElementInterface $element, ?User $user = null): array
    {
        $source = $this->elementInSite((int) $element->id, (int) $element->siteId);
        if (!$source || !$this->mayView($source, $user)) {
            throw new SupertextException(Craft::t('supertext-translation', 'This content was not found.'));
        }
        $sourceUnits = $this->collectUnits($source);
        $last = $this->lastTranslations((int) $source->id);
        $sites = [];

        foreach ($this->supportedSites($source) as $site) {
            $isSource = $site->id === $source->siteId;
            $target = $isSource ? $source : $this->elementInSite((int) $source->id, $site->id);
            $sites[] = [
                'id' => $site->id,
                'handle' => $site->handle,
                'name' => $site->getName(),
                'language' => $site->language,
                'isSource' => $isSource,
                'available' => $target !== null,
                'translated' => !$isSource && $target && $this->differs($sourceUnits, $target),
                'lastTranslation' => $last[$site->id] ?? null,
                'canSave' => $target !== null && Craft::$app->getElements()->canSave($target, $user),
            ];
        }

        return ['sourceSiteId' => (int) $source->siteId, 'sites' => $sites];
    }

    /**
     * @param list<int> $targetSiteIds
     * @return list<array{siteId: int, site: string, status: string, message?: string, imagesToCheck?: int, draftUrl?: string}>
     */
    public function translate(int $elementId, int $sourceSiteId, array $targetSiteIds, bool $overwrite, ?User $user = null): array
    {
        $source = $this->elementInSite($elementId, $sourceSiteId);
        if (!$source || !$this->mayView($source, $user)) {
            throw new SupertextException(Craft::t('supertext-translation', 'This content was not found.'));
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
                $target = $site ? $this->elementInSite($elementId, $siteId) : null;
                if (!$site || !$target) {
                    throw new SupertextException(Craft::t('supertext-translation', 'This content is not available in this site.'));
                }
                if ($user && !Craft::$app->getElements()->canSave($target, $user)) {
                    throw new SupertextException(Craft::t('supertext-translation', 'You are not allowed to edit this content in this site.'));
                }

                if (!$overwrite && $this->differs($units, $target)) {
                    $results[] = $result + ['status' => self::STATUS_SKIPPED, 'message' => Craft::t('supertext-translation', 'Already translated.')];
                    continue;
                }

                $siteUnits = $this->unitsFor($units, $target);
                // Sites with *Translation review* on go through the review service (same key and protocol).
                $translated = $this->translateUnits(
                    $client->forReview($settings->review($site->handle)),
                    $siteUnits,
                    $sourceSite ? $settings->sourceCode($sourceSite->handle, $sourceSite->language) : '',
                    $settings->targetCode($site->handle, $site->language),
                    $settings->politeness($site->handle),
                );
                $written = $this->apply($siteUnits, $translated, $siteId, $sourceSite?->getName() ?? '', $user);
                $this->remember($elementId, $sourceSiteId, $siteId, $user);
                $results[] = $result + ['status' => self::STATUS_TRANSLATED] + array_filter($written);
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
     * Export for translation: the element's texts in its current site as an XLIFF or HTML file for
     * the given target sites (see FileExchange), or null when there is nothing to translate.
     *
     * @param list<int> $targetSiteIds
     * @return array{content: string, filename: string, contentType: string}|null
     */
    public function exportFile(int $elementId, int $sourceSiteId, array $targetSiteIds, string $format, ?User $user = null): ?array
    {
        $source = $this->elementInSite($elementId, $sourceSiteId);
        $sourceSite = Craft::$app->getSites()->getSiteById($sourceSiteId);
        if (!$source || !$sourceSite || !$this->mayView($source, $user)) {
            throw new SupertextException(Craft::t('supertext-translation', 'This content was not found.'));
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

        return FileExchange::export($elementId, $sourceSiteId, $sourceLanguage, $targets, $this->fileSegments($this->collectUnits($source)), $format, self::referenceType($source));
    }

    /**
     * Import translation: writes each job of an uploaded file into its element and target site,
     * through the same write-back as API translations (revision, nested entries, overwrite rule).
     *
     * @return list<array{entryId: ?int, siteId: ?int, title: string, site: string, language: string, error: ?string, params: array<string, string>, counts: array<string, int>, imported: int, message?: string}>
     * @throws \supertext\crafttranslation\sdk\Files\TranslationFileError invalid_file, missing_metadata, too_large
     */
    public function importFile(string $content, bool $includeChanged, bool $overwrite, ?User $user = null): array
    {
        $results = [];
        foreach (FileExchange::read($content) as $file) {
            $open = function (int $elementId, int $sourceSiteId, int $targetSiteId) use ($overwrite, $user): array {
                $source = $this->elementInSite($elementId, $sourceSiteId);
                if (!$source) {
                    return ['error' => FileExchange::NOT_FOUND];
                }
                $site = Craft::$app->getSites()->getSiteById($targetSiteId);
                $target = $site ? $this->elementInSite($elementId, $targetSiteId) : null;
                $info = ['title' => self::label($source), 'site' => $site?->getName() ?? ''];
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
                    'write' => function (array $texts) use ($units, $target, $elementId, $sourceSiteId, $targetSiteId, $user): void {
                        $units = $this->unitsFor($units, $target);
                        $translations = [];
                        foreach ($units as $i => $unit) {
                            if (isset($texts[self::unitKey($unit)])) {
                                $translations[$i] = $texts[self::unitKey($unit)];
                            }
                        }
                        $sourceSite = Craft::$app->getSites()->getSiteById($sourceSiteId);
                        $this->apply($units, $translations, $targetSiteId, $sourceSite?->getName() ?? '', $user);
                        $this->remember($elementId, $sourceSiteId, $targetSiteId, $user);
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
     * The units as segments of a translation file, keyed `<element id>.<title|slug|alt|field handle>`
     * plus `.<path>` for texts inside a field (Table cells, Link texts, SEOmatic texts, CKEditor
     * chunks); slugs as words, like the API translation sends them.
     *
     * @param list<Unit> $units
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

    /** @param Unit $unit */
    private static function unitKey(array $unit): string
    {
        return $unit['element']->id . '.' . ($unit['attribute'] ?? $unit['field']) . ($unit['path'] !== null ? '.' . $unit['path'] : '');
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
     * Every translatable text of the element (and its nested entries), in document order.
     *
     * @param Via|null $via
     * @return list<Unit>
     */
    public function collectUnits(ElementInterface $element, int $depth = 0, ?array $via = null): array
    {
        $units = [];
        $this->vias[(int) $element->id] = $via;
        $add = static function (array $unit) use (&$units, $element, $depth, $via): void {
            $units[] = $unit + ['element' => $element, 'attribute' => null, 'field' => null, 'path' => null, 'depth' => $depth, 'via' => $via];
        };

        $title = trim((string) $element->title);
        if ($element::hasTitles() && $title !== '' && self::titleTranslatable($element)) {
            $add(['attribute' => 'title', 'kind' => 'text', 'value' => $title]);
        }
        if ($depth === 0 && $element::hasUris() && \is_string($element->slug) && preg_match(self::SLUG, $element->slug) && $element->getIsSlugTranslatable()) {
            $add(['attribute' => 'slug', 'kind' => 'slug', 'value' => $element->slug]);
        }
        if ($element instanceof Asset && trim((string) $element->alt) !== '' && $element->getVolume()->altTranslationMethod !== Field::TRANSLATION_METHOD_NONE) {
            $add(['attribute' => 'alt', 'kind' => 'text', 'value' => trim((string) $element->alt)]);
        }

        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $kind = self::kindOf($field);
            if ($kind === null) {
                continue;
            }
            $handle = $field->handle;
            if ($kind === 'nested') {
                if ($depth < self::MAX_DEPTH) {
                    /** @var \craft\elements\db\EntryQuery $query */
                    $query = $element->getFieldValue($handle);
                    foreach ((clone $query)->status(null)->all() as $nested) {
                        array_push($units, ...$this->collectUnits($nested, $depth + 1));
                    }
                }
                continue;
            }
            $translatable = $field->getIsTranslatable($element);

            // CKEditor with nested entries: the HTML between the entries, and the entries' own texts.
            if ($kind === 'html' && is_a($field, FieldValues::CKEDITOR_FIELD)) {
                $chunks = FieldValues::chunks($element->getFieldValue($handle));
                if (FieldValues::hasEntries($chunks)) {
                    $ordinal = 0;
                    foreach ($chunks as $i => $chunk) {
                        if ($chunk['html'] === null) {
                            if ($chunk['entry'] && $depth < self::MAX_DEPTH) {
                                $nestedVia = $translatable ? ['owner' => $element, 'field' => $handle, 'ordinal' => $ordinal] : null;
                                array_push($units, ...$this->collectUnits($chunk['entry'], $depth + 1, $nestedVia));
                            }
                            $ordinal++;
                        } elseif ($translatable && trim($chunk['html']) !== '') {
                            $add(['field' => $handle, 'path' => "chunk:$i", 'kind' => 'html', 'value' => trim($chunk['html'])]);
                        }
                    }
                    continue;
                }
            }
            if (!$translatable) {
                continue;
            }

            switch ($kind) {
                case 'table':
                    /** @var Table $field */
                    $columns = FieldValues::tableTextColumns($field);
                    foreach (FieldValues::tableRows($field, $element) as $r => $row) {
                        foreach ($columns as $column) {
                            $text = \is_string($row[$column] ?? null) ? trim($row[$column]) : '';
                            if ($text !== '') {
                                $add(['field' => $handle, 'path' => "table:$r:$column", 'kind' => 'text', 'value' => $text]);
                            }
                        }
                    }
                    break;
                case 'link':
                    $link = $element->getFieldValue($handle);
                    if ($link instanceof LinkData) {
                        foreach (FieldValues::LINK_TEXTS as $name) {
                            $text = trim(FieldValues::linkText($link, $name));
                            if ($text !== '') {
                                $add(['field' => $handle, 'path' => "link:$name", 'kind' => 'text', 'value' => $text]);
                            }
                        }
                    }
                    break;
                case 'seo':
                    $seo = FieldValues::seo($field, $element);
                    foreach (FieldValues::SEO_TEXTS as $name) {
                        $text = FieldValues::seoText($seo, $name);
                        if ($text !== '') {
                            $add(['field' => $handle, 'path' => "seo:$name", 'kind' => 'text', 'value' => $text]);
                        }
                    }
                    break;
                default:
                    $value = trim($kind === 'html' ? FieldValues::html($element->getFieldValue($handle)) : (string) $element->getFieldValue($handle));
                    if ($value !== '') {
                        $add(['field' => $handle, 'kind' => $kind, 'value' => $value]);
                    }
            }
        }

        return $units;
    }

    /** text | html | table | link | seo | nested | null (not translated) */
    public static function kindOf(FieldInterface $field): ?string
    {
        return match (true) {
            $field instanceof Matrix => 'nested',
            $field instanceof PlainText => 'text',
            $field instanceof Table => 'table',
            $field instanceof Link => 'link',
            is_a($field, FieldValues::SEOMATIC_FIELD) => 'seo',
            self::isHtmlField($field) => 'html',
            default => null,
        };
    }

    private static function isHtmlField(FieldInterface $field): bool
    {
        foreach (self::HTML_FIELDS as $class) {
            if (is_a($field, $class)) {
                return true;
            }
        }

        return false;
    }

    private static function titleTranslatable(ElementInterface $element): bool
    {
        if ($element instanceof Entry && !$element->getType()->hasTitleField) {
            return false;
        }
        // Asset titles that are file names ("IMG_1234") aren't worth translating: only titles made of words.
        if ($element instanceof Asset && !preg_match('/\s/u', trim((string) $element->title))) {
            return false;
        }

        return $element->getIsTitleTranslatable();
    }

    /**
     * The units to write into a target site: without the slug when *Translate page addresses* says
     * the site keeps its own (helpers/Slugs).
     *
     * @param list<Unit> $units
     * @return list<Unit>
     */
    private function unitsFor(array $units, ElementInterface $target): array
    {
        $mode = Plugin::getInstance()->getSettings()->translateSlugs;

        return array_values(array_filter($units, static fn(array $unit): bool => $unit['kind'] !== 'slug'
            || Slugs::translate($mode, (string) $unit['value'], (string) $target->slug)));
    }

    /**
     * Whether the target already has its own text: any translatable value differs from the source.
     *
     * @param list<Unit> $units
     */
    private function differs(array $units, ElementInterface $target): bool
    {
        $this->resolved = [];
        $this->nestedMaps = [];
        foreach ($units as $unit) {
            if ($unit['kind'] === 'slug') {
                continue;
            }
            $element = $this->resolve($unit['element'], (int) $target->siteId, false);
            if (!$element) {
                continue;
            }
            $current = $this->currentText($element, $unit);
            if ($current !== '' && $current !== $unit['value']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The unit's text as it is now in the given element (the source's counterpart in another site).
     *
     * @param Unit $unit
     */
    private function currentText(ElementInterface $element, array $unit): string
    {
        if ($unit['attribute'] !== null) {
            return trim((string) $element->{$unit['attribute']});
        }
        $handle = (string) $unit['field'];
        if ($unit['path'] === null) {
            return trim($unit['kind'] === 'html' ? FieldValues::html($element->getFieldValue($handle)) : (string) $element->getFieldValue($handle));
        }
        $field = $element->getFieldLayout()?->getFieldByHandle($handle);
        [$type, $a, $b] = explode(':', $unit['path'], 3) + [null, null, null];
        $value = match (true) {
            $type === 'table' && $field instanceof Table => FieldValues::tableRows($field, $element)[(int) $a][(string) $b] ?? '',
            $type === 'link' => ($link = $element->getFieldValue($handle)) instanceof LinkData ? FieldValues::linkText($link, (string) $a) : '',
            $type === 'seo' && $field !== null => FieldValues::seoText(FieldValues::seo($field, $element), (string) $a),
            $type === 'chunk' => FieldValues::chunks($element->getFieldValue($handle))[(int) $a]['html'] ?? '',
            default => '',
        };

        return \is_string($value) ? trim($value) : '';
    }

    /**
     * @param list<Unit> $units
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
     * Writes the translations to the elements in the target site and saves them (nested entries
     * first, then their owners; for entries this creates a revision). With *Save translations as
     * drafts*, an entry's translation goes into a new draft of the target site instead.
     *
     * Links to the source site's pages are pointed to the target site's (*Point links to the
     * translated pages*), and in HTML fields the target's own images are kept (helpers\Images).
     *
     * @param list<Unit> $units
     * @param array<int, string> $translations
     * @return array{imagesToCheck: int, draftUrl: ?string}
     */
    private function apply(array $units, array $translations, int $siteId, string $sourceName, ?User $user): array
    {
        $this->resolved = [];
        $this->nestedMaps = [];
        $this->draft = null;
        $this->draftNested = [];
        $settings = Plugin::getInstance()->getSettings();
        $root = null;
        foreach ($units as $unit) {
            if ($unit['depth'] === 0 && $unit['via'] === null) {
                $root = $unit['element'];
                break;
            }
        }
        $sourceSiteId = (int) ($root ?? ($units[0]['element'] ?? null))?->siteId;
        if ($settings->saveAsDrafts && $root instanceof Entry) {
            $this->startDraft($root, $siteId, $sourceName, $user);
        }
        /** @var list<array{0: ElementInterface, 1: string, 2: list<array{slot: int, originalSrc: string, src: string, status: string, reason?: string}>}> $imageRecords */
        $imageRecords = [];
        $localize = fn(string $text): string => $settings->translateLinks ? $this->localizeLinks($text, $sourceSiteId, $siteId) : $text;
        // An HTML field's new value: links localized, the target's own images kept.
        $finishHtml = function (ElementInterface $element, string $handle, string $html) use ($localize, &$imageRecords): string {
            $html = $localize($html);
            $merged = Images::merge($html, FieldValues::html($element->getFieldValue($handle)), $this->imagesOf($element, $handle));
            if ($merged['records'] !== [] || $this->imagesOf($element, $handle) !== null) {
                $imageRecords[] = [$element, $handle, $merged['records']];
            }

            return $merged['html'];
        };
        /** @var array<int, ElementInterface> $sources source element id => source element */
        $sources = [];
        /** @var array<int, ElementInterface|null> $targets source element id => target element */
        $targets = [];
        /** @var array<int, int> $depths */
        $depths = [];
        /** @var array<int, array<string, array<string, string>>> $parts source element id => field => path => text */
        $parts = [];
        /** @var array<string, array{0: ElementInterface, 1: string}> $containers CKEditor fields with nested entries to rebuild */
        $containers = [];

        foreach ($units as $i => $unit) {
            $source = $unit['element'];
            $id = (int) $source->id;
            if ($unit['via'] !== null) {
                $owner = $unit['via']['owner'];
                $containers[$owner->id . ':' . $unit['via']['field']] = [$owner, $unit['via']['field']];
            }
            if ($unit['path'] !== null && str_starts_with($unit['path'], 'chunk:')) {
                $containers[$id . ':' . $unit['field']] = [$source, (string) $unit['field']];
            }
            $text = trim($translations[$i] ?? '');
            if ($text === '') {
                continue;
            }
            $sources[$id] = $source;
            $depths[$id] = $unit['depth'];
            if (!\array_key_exists($id, $targets)) {
                $targets[$id] = $this->resolve($source, $siteId, true);
            }
            $element = $targets[$id];
            if (!$element) {
                continue;
            }

            if ($unit['path'] !== null) {
                $parts[$id][(string) $unit['field']][$unit['path']] = $text;
            } elseif ($unit['kind'] === 'slug') {
                $element->slug = ElementHelper::generateSlug($text, null, Craft::$app->getSites()->getSiteById($siteId)?->language);
            } elseif ($unit['attribute'] !== null) {
                $element->{$unit['attribute']} = $text;
            } elseif ($unit['kind'] === 'html') {
                $element->setFieldValue((string) $unit['field'], $finishHtml($element, (string) $unit['field'], $text));
            } else {
                $element->setFieldValue((string) $unit['field'], $text);
            }
        }

        // Texts inside a field: Table cells, Link texts, SEOmatic texts.
        foreach ($parts as $id => $fields) {
            $element = $targets[$id] ?? null;
            if (!$element) {
                continue;
            }
            foreach ($fields as $handle => $texts) {
                $field = $sources[$id]->getFieldLayout()?->getFieldByHandle($handle);
                $value = match (true) {
                    $field instanceof Table => FieldValues::composeTable($field, $sources[$id], $element, $texts),
                    $field instanceof Link => FieldValues::composeLink($field, $sources[$id], $element, $texts),
                    $field !== null && is_a($field, FieldValues::SEOMATIC_FIELD) => FieldValues::composeSeo($field, $sources[$id], $element, $texts),
                    default => false,
                };
                if ($field instanceof Link && \is_array($value) && \is_string($value['value'] ?? null)) {
                    $value['value'] = $localize($value['value']);
                }
                if ($value !== false && $value !== null) {
                    $element->setFieldValue($handle, $value);
                }
            }
        }

        // CKEditor fields with nested entries: the translated HTML around the target site's entries.
        /** @var list<Entry> $displaced */
        $displaced = [];
        foreach ($containers as [$owner, $handle]) {
            $id = (int) $owner->id;
            if (!\array_key_exists($id, $targets)) {
                $targets[$id] = $this->resolve($owner, $siteId, true);
                $sources[$id] = $owner;
            }
            $element = $targets[$id];
            if (!$element) {
                continue;
            }
            $depths[$id] ??= $this->depthOf($owner, $units);
            $map = $this->nestedMap($owner, $handle, $siteId, true);
            $html = '';
            $ordinal = 0;
            foreach (FieldValues::chunks($owner->getFieldValue($handle)) as $i => $chunk) {
                if ($chunk['html'] === null) {
                    $entry = $map['entries'][$ordinal++] ?? null;
                    $html .= $entry ? FieldValues::entryTag((int) $entry->id) : '';
                } else {
                    $html .= $parts[$id][$handle]["chunk:$i"] ?? $chunk['html'];
                }
            }
            $element->setFieldValue($handle, $finishHtml($element, $handle, $html));
            array_push($displaced, ...$map['displaced']);
        }

        // Nested entries before their owners, so an owner's revision includes them.
        $ids = array_keys(array_filter($targets));
        usort($ids, static fn(int $a, int $b) => ($depths[$b] ?? 0) <=> ($depths[$a] ?? 0));

        foreach ($ids as $id) {
            $element = $targets[$id];
            if (!$element) {
                continue;
            }
            if ($element instanceof Entry && ($depths[$id] ?? 0) === 0 && !$element->getIsDraft()) {
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

        // The target site's nested entries that the new HTML no longer contains (to the trash, restorable).
        // In a draft they still belong to the live entry: applying the draft takes care of them.
        if ($this->draft === null) {
            foreach ($displaced as $entry) {
                Craft::$app->getElements()->deleteElement($entry);
            }
        }

        $toCheck = 0;
        foreach ($imageRecords as [$element, $handle, $records]) {
            $this->saveImages($element, $handle, $sourceSiteId, $records);
            $toCheck += \count(array_filter($records, static fn(array $r) => $r['status'] === Images::CHECK));
        }
        $draftUrl = $this->draft?->getCpEditUrl();
        $this->draft = null;
        $this->draftNested = [];

        return ['imagesToCheck' => $toCheck, 'draftUrl' => $draftUrl];
    }

    /**
     * *Save translations as drafts*: a new draft of the entry in the target site, named after the
     * site, with its own copies of the Matrix fields' nested entries (as the control panel makes
     * them when an editor changes one in a draft), so nothing live is changed.
     */
    private function startDraft(Entry $source, int $siteId, string $sourceName, ?User $user): void
    {
        $canonical = $this->elementInSite((int) $source->id, $siteId);
        if (!$canonical instanceof Entry) {
            return;
        }
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $drafts = Craft::$app->getDrafts();
        $draft = $drafts->createDraft(
            $canonical,
            $user !== null ? (int) $user->id : $canonical->getAuthorId(),
            Craft::t('supertext-translation', 'Supertext: {site}', ['site' => $site?->getName() ?? (string) $siteId]),
            Craft::t('supertext-translation', 'Translated with Supertext from {site}', ['site' => $sourceName]),
        );
        $copies = [];
        foreach ($draft->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if (!$field instanceof Matrix) {
                continue;
            }
            /** @var \craft\elements\db\EntryQuery $query */
            $query = $draft->getFieldValue($field->handle);
            $nested = (clone $query)->status(null)->all();
            if ($nested === []) {
                continue;
            }
            $data = ['entries' => [], 'sortOrder' => []];
            foreach ($nested as $entry) {
                $data['entries'][(string) $entry->id] = ['type' => $entry->getType()->handle, 'enabled' => $entry->enabled, 'title' => $entry->title, 'fields' => []];
                $data['sortOrder'][] = (string) $entry->id;
            }
            $draft->setFieldValue($field->handle, $data);
            $copies[$field->id] = array_map(static fn(Entry $e) => (int) $e->id, $nested);
        }
        if ($copies !== []) {
            if (!Craft::$app->getElements()->saveElement($draft)) {
                throw new SupertextException(Craft::t('supertext-translation', 'The translation could not be saved: {errors}', ['errors' => implode(' ', $draft->getFirstErrors())]));
            }
            foreach ($copies as $fieldId => $ids) {
                $own = Entry::find()->ownerId($draft->id)->fieldId($fieldId)->siteId($siteId)->status(null)->all();
                foreach ($ids as $k => $id) {
                    if (isset($own[$k])) {
                        $this->draftNested[$id] = $own[$k];
                    }
                }
            }
        }
        $this->draft = Entry::find()->id($draft->id)->siteId($siteId)->drafts()->status(null)->one() ?? $draft;
    }

    /** Links to the source site's entries and categories, pointed to the target site's (helpers\Links). */
    private function localizeLinks(string $text, int $sourceSiteId, int $targetSiteId): string
    {
        $base = Craft::$app->getSites()->getSiteById($sourceSiteId)?->getBaseUrl() ?? '';
        $basePath = '/' . ltrim((string) parse_url($base, PHP_URL_PATH), '/');

        return Links::localize(
            $text,
            $sourceSiteId,
            $targetSiteId,
            static function (string $type, int $id, int $siteId): ?string {
                $element = ($type === 'category' ? Category::find() : Entry::find())->id($id)->siteId($siteId)->status(null)->one();

                return $element ? (string) ($element->getUrl() ?? '') : null;
            },
            static function (string $href) use ($base, $basePath, $sourceSiteId): ?array {
                if ($base !== '' && str_starts_with($href, $base)) {
                    $uri = substr($href, \strlen($base));
                } elseif (str_starts_with($href, '/') && !str_starts_with($href, '//') && str_starts_with($href, $basePath)) {
                    $uri = substr($href, \strlen($basePath));
                } else {
                    return null;
                }
                $uri = trim($uri, '/');
                $element = Craft::$app->getElements()->getElementByUri($uri === '' ? '__home__' : $uri, $sourceSiteId, true);

                return match (true) {
                    $element instanceof Entry => ['entry', (int) $element->id],
                    $element instanceof Category => ['category', (int) $element->id],
                    default => null,
                };
            },
        );
    }

    /** The key of an element's image record: its canonical id (a draft's images belong to the entry). */
    private static function imageKey(ElementInterface $element): int
    {
        return (int) ($element->getIsDerivative() ? $element->getCanonicalId() : $element->id);
    }

    /**
     * The images the plugin last wrote into an HTML field of the element (its site), null if none.
     *
     * @return list<array{slot: int, originalSrc: string, src: string, status: string, reason?: string}>|null
     */
    public function imagesOf(ElementInterface $element, string $field): ?array
    {
        $record = ImageRecord::findOne(['elementId' => self::imageKey($element), 'siteId' => (int) $element->siteId, 'field' => $field]);
        if (!$record) {
            return null;
        }
        $images = json_decode($record->images, true);

        return \is_array($images) ? array_values($images) : [];
    }

    /** @param list<array{slot: int, originalSrc: string, src: string, status: string, reason?: string}> $images */
    private function saveImages(ElementInterface $element, string $field, int $sourceSiteId, array $images): void
    {
        $where = ['elementId' => self::imageKey($element), 'siteId' => (int) $element->siteId, 'field' => $field];
        $record = ImageRecord::findOne($where) ?? new ImageRecord($where);
        $record->sourceSiteId = $sourceSiteId;
        $record->images = (string) json_encode($images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $record->save(false);
    }

    /** @param list<Unit> $units */
    private function depthOf(ElementInterface $element, array $units): int
    {
        foreach ($units as $unit) {
            if ($unit['element']->id === $element->id) {
                return $unit['depth'];
            }
            if ($unit['via'] !== null && $unit['via']['owner']->id === $element->id) {
                return max(0, $unit['depth'] - 1);
            }
        }

        return 0;
    }

    /**
     * The source element in the target site: the same element (its id) in that site, or, for an
     * entry inside a translatable CKEditor field, the target site's own entry at the same place
     * (copied from the source when `$create` and there is none of the same type).
     */
    private function resolve(ElementInterface $source, int $siteId, bool $create): ?ElementInterface
    {
        if ((int) $source->siteId === $siteId) {
            return $source;
        }
        // Writing a draft: the draft instead of the live entry, and its own copies of nested entries.
        if ($this->draft !== null && (int) $this->draft->siteId === $siteId) {
            if ((int) $source->id === (int) $this->draft->getCanonicalId()) {
                return $this->draft;
            }
            if (isset($this->draftNested[(int) $source->id])) {
                return $this->draftNested[(int) $source->id];
            }
        }
        $key = $source->id . ':' . $siteId;
        if (\array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }
        $via = $this->vias[(int) $source->id] ?? null;
        $this->resolved[$key] = $via === null
            ? $this->elementInSite((int) $source->id, $siteId)
            : ($this->nestedMap($via['owner'], $via['field'], $siteId, $create)['entries'][$via['ordinal']] ?? null);

        return $this->resolved[$key];
    }

    /**
     * The target site's entries for the nested entries of a translatable CKEditor field, by position:
     * the target's own entry when it has the same entry type, else (`$create`) a copy of the source's.
     * `displaced`: the target's entries that are no longer used.
     *
     * @return array{entries: array<int, ?Entry>, displaced: list<Entry>}
     */
    private function nestedMap(ElementInterface $owner, string $handle, int $siteId, bool $create): array
    {
        $key = $owner->id . ':' . $handle . ':' . $siteId;
        if (isset($this->nestedMaps[$key])) {
            return $this->nestedMaps[$key];
        }
        $targetOwner = $this->resolve($owner, $siteId, $create);
        $sourceEntries = array_values(array_map(static fn(array $c) => $c['entry'], array_filter(FieldValues::chunks($owner->getFieldValue($handle)), static fn(array $c) => $c['html'] === null)));
        $targetEntries = $targetOwner ? array_values(array_filter(array_map(static fn(array $c) => $c['entry'], FieldValues::chunks($targetOwner->getFieldValue($handle))))) : [];

        $entries = [];
        $used = [];
        foreach ($sourceEntries as $k => $sourceEntry) {
            $candidate = $targetEntries[$k] ?? null;
            if ($candidate && $sourceEntry && $candidate->typeId === $sourceEntry->typeId && $create && $this->draft !== null && $targetOwner === $this->draft) {
                // In a draft, the live entry's nested entry stays as it is: the draft gets its own copy.
                $entries[$k] = Craft::$app->getElements()->duplicateElement($candidate, [
                    'siteId' => $siteId,
                    'ownerId' => $targetOwner->id,
                    'primaryOwnerId' => $targetOwner->id,
                ]);
            } elseif ($candidate && $sourceEntry && $candidate->typeId === $sourceEntry->typeId) {
                $entries[$k] = $candidate;
                $used[$candidate->id] = true;
            } elseif ($create && $sourceEntry && $targetOwner) {
                // Like Craft's "copy from site": a copy of the source's entry that belongs to the target site.
                $copy = Craft::$app->getElements()->duplicateElement($sourceEntry, [
                    'siteId' => $siteId,
                    'ownerId' => $targetOwner->id,
                    'primaryOwnerId' => $targetOwner->id,
                ]);
                $entries[$k] = $copy;
            } else {
                $entries[$k] = null;
            }
        }
        $displaced = array_values(array_filter($targetEntries, static fn(Entry $e) => !isset($used[$e->id])));

        return $this->nestedMaps[$key] = ['entries' => $entries, 'displaced' => $create ? $displaced : []];
    }

    /** The element in the given site (any status; entries also as drafts), if the plugin translates its type. */
    /**
     * Whether $user may view the element (Craft's own rules: sections, sites, drafts). Without a
     * user (console commands) always. Elements the user can't view are reported as not found.
     */
    private function mayView(ElementInterface $element, ?User $user): bool
    {
        return $user === null || Craft::$app->getElements()->canView($element, $user);
    }

        public function elementInSite(int $id, int $siteId): ?ElementInterface
    {
        $type = Craft::$app->getElements()->getElementTypeById($id);
        if ($type === null || !\in_array($type, self::ELEMENT_TYPES, true)) {
            return null;
        }
        $query = $type::find()->id($id)->siteId($siteId)->status(null);
        if ($query instanceof \craft\elements\db\EntryQuery) {
            $query->drafts(null)->provisionalDrafts(null);
        }
        $element = $query->one();

        return $element instanceof ElementInterface ? $element : null;
    }

    /** `entry`, `category`, `global` or `asset`: the element type in translation file references. */
    public static function referenceType(ElementInterface $element): string
    {
        return match (true) {
            $element instanceof Category => 'category',
            $element instanceof GlobalSet => 'global',
            $element instanceof Asset => 'asset',
            default => 'entry',
        };
    }

    /** What editors see as the element's name: its title, or a global set's name. */
    public static function label(ElementInterface $element): string
    {
        if ($element instanceof GlobalSet) {
            return (string) $element->name;
        }

        return (string) $element->title;
    }

    /** @return list<Site> */
    private function supportedSites(ElementInterface $element): array
    {
        $ids = array_map(static fn($s) => \is_array($s) ? (int) $s['siteId'] : (int) $s, $element->getSupportedSites());
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
