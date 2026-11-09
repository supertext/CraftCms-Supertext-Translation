<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\web\assets\translate;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/** The Supertext box on the entry edit page. */
class TranslateAsset extends AssetBundle
{
    /** Strings the JavaScript translates with Craft.t('supertext-translation', …). */
    public const MESSAGES = [
        'From',
        'Into',
        'Already translated',
        'Translated with Supertext on {date}',
        'Overwrite existing translations',
        'Changes made to those translations are replaced by a new translation. Leave this off to translate only the sites without their own text.',
        'Supertext translates the saved version of this site. Save your changes first.',
        'Translate',
        'Translating…',
        'translated',
        'already translated, skipped',
        'Open',
        'Supertext is not set up yet: an administrator needs to add the API key in the plugin settings.',
        'This entry exists in no other site you can edit.',
        'Choose at least one site.',
        'Export for translation',
        'Import translation',
        'File format',
        'XLIFF 1.2 (for translation tools)',
        'HTML (one language per file)',
        'Download file',
        'Translated file (XLIFF or HTML)',
        'Also import texts whose source changed since the export',
        'Imported: {count}',
        'Skipped, source changed since the export: {count}',
        'Not translated in the file: {count}',
        'Formatting changed, not imported: {count}',
        'Not in the file: {count}',
        'Please choose a file.',
    ];

    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['translate.js'];
        $this->css = ['translate.css'];
        parent::init();
    }
}
