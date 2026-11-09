<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\models;

use craft\base\Model;
use craft\helpers\App;
use supertext\crafttranslation\api\Languages;
use supertext\crafttranslation\api\SupertextClient;

/**
 * Plugin settings (stored in the project config). The API key is normally an
 * environment variable reference such as `$SUPERTEXT_API_KEY`, so the key itself
 * never ends up in the project config files.
 */
class Settings extends Model
{
    public const SAVE_DRAFT = 'draft';
    public const SAVE_DIRECT = 'direct';

    /** API key or an environment variable reference (`$SUPERTEXT_API_KEY`). */
    public string $apiKey = '$SUPERTEXT_API_KEY';

    /** live, staging or testing. */
    public string $environment = 'live';

    /** Custom API base URL (overrides the environment), may be an env var reference. */
    public string $apiUrl = '';

    /**
     * Review service for sites with *Translation review* on (default: Supertext's review service), may be an
     * env var reference. '' turns review off for every site. `SUPERTEXT_REVIEW_URL` on the server wins.
     */
    public string $reviewUrl = SupertextClient::REVIEW_URL;

    /** How translations are saved: as a draft for review (default) or directly. */
    public string $saveMode = self::SAVE_DRAFT;

    /**
     * Per site handle: Supertext language code (chosen in a select of Supertext's targets) and tone. Without a
     * code, the site's language is used if it is a Supertext code itself (apart from case and "_"/"-"), else
     * the SDK's default ("de" → "de-DE"); a site language with neither isn't translated (see api/Languages).
     * `config/supertext-translation.php` can set them too and then wins (see configuredLanguageCode()).
     *
     * `review` (*Translation review*, off by default): translations into the site go through the review service.
     *
     * @var array<array-key, mixed> site handle → ['code' => string, 'politeness' => 'default'|'more'|'less', 'review' => bool|'1'|''], as posted
     *      (validateLanguages() checks it)
     */
    public array $languages = [];

    /** Seconds to wait for one translation. */
    public int $timeout = 180;

    /** "Send version information to Supertext" (SUPERTEXT_VERSION_REPORTS on the server wins). */
    public bool $versionReports = true;

    public function getApiKey(): string
    {
        $value = App::parseEnv($this->apiKey);

        return SupertextClient::normalizeKey(\is_string($value) ? $value : '');
    }

    public function getBaseUrl(): string
    {
        $custom = App::parseEnv($this->apiUrl);

        return SupertextClient::baseUrlFor($this->environment, \is_string($custom) ? $custom : '');
    }

    /** The review service address ('' = review off everywhere), see SupertextClient::reviewUrlFor(). */
    public function getReviewUrl(): string
    {
        $setting = App::parseEnv($this->reviewUrl);

        return SupertextClient::reviewUrlFor(self::reviewUrlFromEnvironment(), \is_string($setting) ? $setting : '');
    }

    /** `SUPERTEXT_REVIEW_URL` on the server (null if not set; `false`/`0` count as empty, i.e. review off). */
    public static function reviewUrlFromEnvironment(): ?string
    {
        $value = App::env('SUPERTEXT_REVIEW_URL');
        if ($value === null) {
            return null;
        }

        return \is_string($value) ? $value : '';
    }

    /** *Translation review* of a site: translations into it go through the review service (if there is one). */
    public function review(string $siteHandle): bool
    {
        $value = $this->languages[$siteHandle]['review'] ?? false;

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * The site's Supertext language from `config/supertext-translation.php` ('' if the file doesn't set one).
     * Craft merges that file over the stored settings, so this value is the one that is used.
     */
    public static function configuredLanguageCode(string $siteHandle): string
    {
        try {
            $config = \Craft::$app->getConfig()->getConfigFromFile('supertext-translation');
        } catch (\Throwable) {
            return '';
        }
        $code = $config['languages'][$siteHandle]['code'] ?? '';

        return \is_string($code) ? trim($code) : '';
    }

    /** The site's *Translation review* from `config/supertext-translation.php` (null if the file doesn't set it). */
    public static function configuredReview(string $siteHandle): ?bool
    {
        try {
            $config = \Craft::$app->getConfig()->getConfigFromFile('supertext-translation');
        } catch (\Throwable) {
            return null;
        }
        $value = $config['languages'][$siteHandle]['review'] ?? null;

        return $value === null ? null : (bool) $value;
    }

    /**
     * The site's Supertext language as set in the settings ('' if none), in the list's spelling ("de_ch" from a
     * config file → "de-CH"); a code the list doesn't know is returned as it is (and sent as it is).
     */
    public function languageCode(string $siteHandle): string
    {
        $code = trim((string) ($this->languages[$siteHandle]['code'] ?? ''));

        return Languages::canonical($code) ?? $code;
    }

    /**
     * Supertext target code for a site: its Supertext language, else the site's language if it is a Supertext code.
     *
     * @throws \supertext\crafttranslation\api\SupertextException reason unsupported_language
     */
    public function targetCode(string $siteHandle, string $siteLanguage): string
    {
        return Languages::target($siteLanguage, $this->languageCode($siteHandle));
    }

    /** Like targetCode(), but null for a site without a Supertext language (settings page). */
    public function targetCodeOrNull(string $siteHandle, string $siteLanguage): ?string
    {
        return Languages::targetOrNull($siteLanguage, $this->languageCode($siteHandle));
    }

    /**
     * Supertext source code for a site: the source form of its Supertext language or of the site's language ("en-US" → "en").
     *
     * @throws \supertext\crafttranslation\api\SupertextException reason unsupported_language
     */
    public function sourceCode(string $siteHandle, string $siteLanguage): string
    {
        return Languages::source($siteLanguage, $this->languageCode($siteHandle));
    }

    public function politeness(string $siteHandle): string
    {
        $value = (string) ($this->languages[$siteHandle]['politeness'] ?? 'default');

        return \in_array($value, ['more', 'less'], true) ? $value : 'default';
    }

    protected function defineRules(): array
    {
        return [
            [['environment'], 'in', 'range' => ['live', 'staging', 'testing']],
            [['saveMode'], 'in', 'range' => [self::SAVE_DRAFT, self::SAVE_DIRECT]],
            [['timeout'], 'integer', 'min' => 10, 'max' => 1800],
            [['apiKey', 'apiUrl', 'reviewUrl'], 'string'],
            [['versionReports'], 'boolean'],
            [['languages'], 'validateLanguages'],
        ];
    }

    /** Every Supertext language must be one of Supertext's target codes (the select's options), the tone default/more/less. */
    public function validateLanguages(string $attribute): void
    {
        foreach ($this->languages as $handle => $config) {
            if (!\is_array($config)) {
                $this->addError($attribute, \Craft::t('supertext-translation', 'Choose the Supertext language of site {site} from the list.', ['site' => (string) $handle]));
                continue;
            }
            $code = trim((string) ($config['code'] ?? ''));
            if ($code !== '' && !Languages::isTarget($code) && $code !== self::configuredLanguageCode((string) $handle)) {
                $this->addError($attribute, \Craft::t('supertext-translation', 'Choose the Supertext language of site {site} from the list.', ['site' => (string) $handle]));
            }
            if (!\in_array((string) ($config['politeness'] ?? 'default'), ['default', 'more', 'less'], true)) {
                $this->addError($attribute, \Craft::t('supertext-translation', 'Choose the form of address of site {site} from the list.', ['site' => (string) $handle]));
            }
        }
    }
}
