<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\helpers;

use Craft;
use supertext\crafttranslation\api\SupertextException;
use supertext\crafttranslation\pluginhealth\KeyCheck;

/** Shows errors from the API client (which only speaks English) in the user's control panel language. */
final class Messages
{
    public const SIGNUP_URL = 'https://www.supertext.com/person/en/account/signin';
    public const API_KEY_URL = 'https://www.supertext.com/en/integrations/api';

    public static function of(\Throwable $e): string
    {
        if (!$e instanceof SupertextException || $e->reason === '') {
            return $e->getMessage();
        }

        $params = $e->args + ['signupUrl' => self::SIGNUP_URL, 'url' => self::API_KEY_URL];
        $text = match ($e->reason) {
            'no_file_id' => Craft::t('supertext-translation', 'Supertext did not return a file id.'),
            'translation_failed' => Craft::t('supertext-translation', 'Supertext could not translate the document.'),
            'limit_exceeded' => Craft::t('supertext-translation', 'Your Supertext translation limit is exceeded. Please upgrade your subscription.'),
            'deleted' => Craft::t('supertext-translation', 'The document was deleted at Supertext before it could be downloaded.'),
            'timeout' => Craft::t('supertext-translation', 'Timed out waiting for the Supertext translation.'),
            'empty' => Craft::t('supertext-translation', 'The translated document was empty.'),
            'no_api_key' => self::noApiKey(),
            'unreachable' => Craft::t('supertext-translation', 'Could not reach Supertext: {0}', $params),
            'auth_failed' => Craft::t('supertext-translation', 'Authentication failed. Please check the Supertext API key. No Supertext account yet? Create one at {signupUrl}. Generate your API key at {url} (requires the Admin role).', $params),
            'invalid_language_pair' => Craft::t('supertext-translation', 'Supertext does not support this language pair ({0} → {1}). Check the language codes in the plugin settings.', $params),
            'not_found' => Craft::t('supertext-translation', 'The requested Supertext resource was not found.'),
            'too_large' => Craft::t('supertext-translation', 'The content is too large for Supertext to translate in one go.'),
            'rate_limited' => Craft::t('supertext-translation', 'Too many requests to Supertext. Please try again shortly.'),
            'unavailable' => Craft::t('supertext-translation', 'The Supertext service is currently unavailable.'),
            'http_error' => Craft::t('supertext-translation', 'Supertext answered with HTTP {0}.', $params),
            'unsupported_language' => Craft::t('supertext-translation', 'The language {0} has no Supertext language yet. Ask your administrator to choose it under Settings → Plugins → Supertext Translation → Languages (Supertext language).', $params),
            'unsupported_feature' => Craft::t('supertext-translation', 'Supertext offers no formal or informal tone for this language. Set its form of address to Default in the plugin settings (Languages).'),
            default => null,
        };

        if ($text === null) {
            return $e->getMessage();
        }

        return $e->detail !== '' ? $text . ' (' . $e->detail . ')' : $text;
    }

    /**
     * The automatic API key check's result (texts from the Commando Center's guide), as HTML: for
     * "invalid" and "missing" with the links to create an account and generate a key (new tab).
     */
    public static function keyCheck(KeyCheck $check): string
    {
        $links = [
            'signupUrl' => self::SIGNUP_URL,
            'apiKeyUrl' => self::API_KEY_URL,
            'status' => (string) ($check->httpStatus ?? ''),
        ];

        return match ($check->status) {
            KeyCheck::OK => Craft::t('supertext-translation', 'API key checked: the connection to Supertext works.'),
            KeyCheck::INVALID => Craft::t('supertext-translation', 'Supertext rejected the API key. Check that you copied the whole key, or generate a new one at <a href="{apiKeyUrl}" target="_blank" rel="noopener">supertext.com → Integrations → API</a> (requires the Admin role). No Supertext account yet? Create one at <a href="{signupUrl}" target="_blank" rel="noopener">supertext.com</a>.', $links),
            KeyCheck::MISSING => Craft::t('supertext-translation', 'No API key is set yet. No Supertext account yet? Create one at <a href="{signupUrl}" target="_blank" rel="noopener">supertext.com</a>. Generate your API key at <a href="{apiKeyUrl}" target="_blank" rel="noopener">supertext.com → Integrations → API</a> (requires the Admin role).', $links),
            KeyCheck::UNREACHABLE => Craft::t('supertext-translation', 'The API key couldn\'t be checked because Supertext can\'t be reached. The settings are saved; check again later.'),
            default => Craft::t('supertext-translation', 'The API key couldn\'t be checked: Supertext answered with an error ({status}). The settings are saved; check again later.', $links),
        };
    }

    /**
     * Errors of Export for translation / Import translation: the SDK's TranslationFileError codes
     * and FileExchange's import errors.
     *
     * @param array<string, string> $params `plugin`, `language`
     */
    public static function fileError(string $code, array $params = []): string
    {
        return match ($code) {
            'invalid_file' => Craft::t('supertext-translation', 'This file isn’t a valid XLIFF or HTML translation file.'),
            'missing_metadata' => Craft::t('supertext-translation', 'This file wasn’t exported by the Supertext plugin, or its header was removed.'),
            'too_large' => Craft::t('supertext-translation', 'The file is too large (at most 10 MB).'),
            'other_system' => Craft::t('supertext-translation', 'This file belongs to another system ({plugin}).', $params),
            'not_found' => Craft::t('supertext-translation', 'The entry this file was exported from no longer exists.'),
            'no_language' => Craft::t('supertext-translation', 'The site has no language {language}.', $params),
            'forbidden' => Craft::t('supertext-translation', 'You are not allowed to edit the entry in this site.'),
            'already_translated' => Craft::t('supertext-translation', 'This site already has its own text. Tick “Overwrite existing translations” to replace it.'),
            default => Craft::t('supertext-translation', 'Nothing to translate.'),
        };
    }

    public static function noApiKey(): string
    {
        return Craft::t('supertext-translation', 'No Supertext API key is configured. Set it in the plugin settings (usually as $SUPERTEXT_API_KEY). No Supertext account yet? Create one at {signupUrl}. Generate your API key at {url} (requires the Admin role).', [
            'signupUrl' => self::SIGNUP_URL,
            'url' => self::API_KEY_URL,
        ]);
    }
}
