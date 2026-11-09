<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\migrations;

use Craft;
use craft\db\Migration;
use supertext\crafttranslation\api\Languages;

/**
 * The *Supertext language* of each site is now a select of Supertext's target codes. Stored values from
 * older versions are converted to the list's spelling ("de_ch" → "de-CH", "de" → "de-DE", "es-ES" → "es");
 * a value that is no Supertext language is removed (logged as a warning) and the site shows its select again.
 */
class m261017_000000_language_codes extends Migration
{
    private const PATH = 'plugins.supertext-translation';

    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();
        // Only once: project config changes travel to other environments with the YAML files.
        $schemaVersion = (string) $projectConfig->get(self::PATH . '.schemaVersion', true);
        if ($schemaVersion !== '' && version_compare($schemaVersion, '1.2.0', '>=')) {
            return true;
        }
        $languages = $projectConfig->get(self::PATH . '.settings.languages');
        if (!\is_array($languages)) {
            return true;
        }
        $result = Languages::migrate($languages);
        foreach ($result['dropped'] as $handle => $code) {
            Craft::warning("Supertext: the Supertext language \"$code\" of site $handle is not a Supertext language and was removed; choose one in the plugin settings.", __METHOD__);
        }
        if ($result['changed']) {
            $projectConfig->set(self::PATH . '.settings.languages', $result['languages'], 'Supertext: language codes from the list');
        }

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
