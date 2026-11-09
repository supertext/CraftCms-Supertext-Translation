<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\api;

use supertext\crafttranslation\sdk\V1\Languages as SdkLanguages;
use supertext\crafttranslation\sdk\V1\SupertextException as SdkException;

/**
 * Site languages → Supertext language codes (the Supertext SDK's rules, the same in every
 * Supertext plugin).
 *
 * Supertext accepts exactly the codes of its `GET features` list: bare sources ("en"); targets
 * regional only for German, English, French, Italian and Portuguese ("de-CH", "en-US", "pt-BR"),
 * with a script for Chinese and Serbian ("zh-Hans", "sr-Latn"), bare for the others ("es", "nl").
 *
 * A site's *Supertext language* (the select in the plugin settings) wins; otherwise a site language
 * that equals a Supertext code apart from case and "_"/"-" ("de-CH", "fr_ch" → "fr-CH", "es");
 * otherwise the SDK's default where one is obvious ("de" → "de-DE", "en" → "en-US", "zh-TW" →
 * "zh-Hant", "es-ES" → "es"); anything else ("en-IE", "de-LI", "gsw") is the error
 * `unsupported_language` and nothing is sent. The same setting serves the source: a mapped
 * "de-CH" is sent as the source "de".
 *
 * Has no Craft dependencies.
 */
final class Languages
{
    /**
     * Every target Supertext offers: code, English name, whether it has formal/informal.
     *
     * @return list<array{code: string, name: string, politeness: bool}>
     */
    public static function targets(): array
    {
        return SdkLanguages::TARGETS;
    }

    /** The Supertext target that equals the site language apart from case and "_"/"-", else null ("de" → null). */
    public static function match(string $siteLanguage): ?string
    {
        return SdkLanguages::matchTarget($siteLanguage);
    }

    /** What the settings page preselects for a site language: the exact match, else the default ("de" → "de-DE"), else null. */
    public static function suggest(string $siteLanguage): ?string
    {
        return SdkLanguages::suggestTarget($siteLanguage);
    }

    /** Whether $code is one of Supertext's target codes, exactly as the list writes it. */
    public static function isTarget(string $code): bool
    {
        return \in_array($code, array_column(SdkLanguages::TARGETS, 'code'), true);
    }

    /**
     * A stored Supertext language in the list's spelling, for settings from older versions that may hold a
     * typed code: '' stays '', an exact match is spelled as the list does ("de_ch" → "de-CH"), a code with a
     * default gets it ("de" → "de-DE", "es-ES" → "es"), anything else is null (not a Supertext language).
     */
    public static function canonical(string $code): ?string
    {
        $code = trim($code);
        if ($code === '') {
            return '';
        }

        return SdkLanguages::suggestTarget($code);
    }

    /**
     * Stored per-site settings from an older version (typed codes) in the select's spelling: each code through
     * canonical(); a code that isn't a Supertext language is removed and its site handle returned in `dropped`,
     * so the settings page shows that site's select ("— choose —" or the suggestion) instead of a dead value.
     *
     * @param array<array-key, mixed> $languages site handle → ['code' => …, 'politeness' => …]
     *
     * @return array{languages: array<array-key, mixed>, dropped: array<string, string>, changed: bool}
     */
    public static function migrate(array $languages): array
    {
        $dropped = [];
        $changed = false;
        foreach ($languages as $handle => $config) {
            if (!\is_array($config) || !isset($config['code']) || !\is_string($config['code'])) {
                continue;
            }
            $canonical = self::canonical($config['code']);
            if ($canonical === $config['code']) {
                continue;
            }
            $changed = true;
            if ($canonical === null) {
                $dropped[(string) $handle] = trim($config['code']);
                $canonical = '';
            }
            $languages[$handle]['code'] = $canonical;
        }

        return ['languages' => $languages, 'dropped' => $dropped, 'changed' => $changed];
    }

    /**
     * The options of the *Supertext language* select: every target with its name in $displayLocale where PHP's
     * intl knows it (English name from the SDK otherwise) and the code, sorted by that name.
     *
     * @return list<array{code: string, name: string, politeness: bool}>
     */
    public static function options(string $displayLocale = 'en'): array
    {
        $options = [];
        foreach (SdkLanguages::targetOptions() as $target) {
            $options[] = ['code' => $target['code'], 'name' => self::displayName($target, $displayLocale), 'politeness' => $target['politeness']];
        }
        $collator = \extension_loaded('intl') ? \Collator::create($displayLocale) : null;
        usort($options, static fn (array $a, array $b): int => $collator instanceof \Collator
            ? (int) $collator->compare($a['name'], $b['name'])
            : strcmp($a['name'], $b['name']));

        return $options;
    }

    /** The target code to send, or null when the site language has no Supertext language (for the settings page). */
    public static function targetOrNull(string $siteLanguage, string $code = ''): ?string
    {
        try {
            return self::target($siteLanguage, $code);
        } catch (SupertextException) {
            return null;
        }
    }

    /**
     * The target code to send: the site's Supertext language, else an exact match, else the SDK's default.
     *
     * @throws SupertextException reason unsupported_language
     */
    public static function target(string $siteLanguage, string $code = ''): string
    {
        try {
            return SdkLanguages::target($siteLanguage, self::mapping($siteLanguage, $code));
        } catch (SdkException $e) {
            throw SupertextClient::fromSdk($e);
        }
    }

    /**
     * The source code to send: the source form of the site's Supertext language, else of the site language
     * ("en-US" → "en"). Empty stays empty (auto-detect).
     *
     * @throws SupertextException reason unsupported_language
     */
    public static function source(string $siteLanguage, string $code = ''): string
    {
        try {
            return SdkLanguages::source($siteLanguage, self::mapping($siteLanguage, $code));
        } catch (SdkException $e) {
            throw SupertextClient::fromSdk($e);
        }
    }

    /** Whether Supertext offers formal/informal for a target code (null: a code the SDK's list doesn't know). */
    public static function supportsPoliteness(string $target): ?bool
    {
        return SdkLanguages::supportsPoliteness($target);
    }

    /**
     * A target's name in $displayLocale ("Deutsch (Schweiz)"), or the SDK's English name where intl isn't
     * loaded, the locale is English or the code has extensions intl doesn't name (gsw-u-sd-chzh).
     *
     * @param array{code: string, name: string, politeness: bool} $target
     */
    private static function displayName(array $target, string $displayLocale): string
    {
        $locale = str_replace('_', '-', trim($displayLocale));
        if (!\extension_loaded('intl') || $locale === '' || str_starts_with(strtolower($locale), 'en') || str_contains($target['code'], '-u-')) {
            return $target['name'];
        }
        $name = \Locale::getDisplayName(str_replace('-', '_', $target['code']), str_replace('-', '_', $locale));
        if (!\is_string($name) || strcasecmp($name, $target['code']) === 0 || strcasecmp($name, str_replace('-', '_', $target['code'])) === 0) {
            return $target['name'];
        }

        return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }

    /**
     * One site's setting as the SDK's mapping (site language → Supertext code).
     *
     * @return array<string, string>
     */
    private static function mapping(string $siteLanguage, string $code): array
    {
        return trim($code) !== '' && trim($siteLanguage) !== '' ? [$siteLanguage => trim($code)] : [];
    }
}
