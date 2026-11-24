<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use supertext\crafttranslation\helpers\Messages;
use supertext\crafttranslation\Plugin;
use supertext\crafttranslation\pluginhealth\KeyCheck;
use supertext\crafttranslation\services\Translator;
use yii\console\ExitCode;

/**
 * php craft supertext-translation/translate <elementId> --from=en --to=de,fr [--overwrite]
 *   (the id of an entry, category, global set or asset)
 * php craft supertext-translation/translate/check   API key check and a `check` version report
 */
class TranslateController extends Controller
{
    public $defaultAction = 'index';

    /** Handle of the source site (default: the primary site). */
    public ?string $from = null;

    /** Comma-separated target site handles (default: every other site the element exists in). */
    public ?string $to = null;

    /** Replace translations that already exist. */
    public bool $overwrite = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'index' ? ['from', 'to', 'overwrite'] : []);
    }

    public function actionIndex(int $elementId): int
    {
        $sites = Craft::$app->getSites();
        $source = $this->from ? $sites->getSiteByHandle($this->from) : $sites->getPrimarySite();
        if (!$source) {
            $this->stderr("Unknown site: {$this->from}\n", Console::FG_RED);

            return ExitCode::USAGE;
        }
        $translator = Plugin::getInstance()->getTranslator();
        $element = $translator->elementInSite($elementId, $source->id);
        if (!$element) {
            $this->stderr("No entry, category, global set or asset {$elementId} in site {$source->handle}.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $targets = [];
        if ($this->to) {
            foreach (array_filter(array_map('trim', explode(',', $this->to))) as $handle) {
                $site = $sites->getSiteByHandle($handle);
                if (!$site) {
                    $this->stderr("Unknown site: {$handle}\n", Console::FG_RED);

                    return ExitCode::USAGE;
                }
                $targets[] = $site->id;
            }
        } else {
            foreach ($translator->describe($element)['sites'] as $site) {
                if (!$site['isSource'] && $site['available']) {
                    $targets[] = $site['id'];
                }
            }
        }

        $failed = false;
        foreach ($translator->translate($elementId, $source->id, $targets, $this->overwrite) as $result) {
            $line = "{$result['site']}: {$result['status']}" . (isset($result['message']) ? " ({$result['message']})" : '');
            $failed = $failed || $result['status'] === Translator::STATUS_ERROR;
            $this->stdout($line . "\n", $result['status'] === Translator::STATUS_ERROR ? Console::FG_RED : Console::FG_GREEN);
        }

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Checks the API key against the Supertext API (cost-free) and sends a `check` version report to
     * Supertext (unless version reports are off). Fails unless the key check is ok.
     */
    public function actionCheck(): int
    {
        $plugin = Plugin::getInstance();
        $reporting = $plugin->getReporting();
        $result = $reporting->check();
        $check = $result['check'];
        $text = strip_tags(Messages::keyCheck($check));
        if (\in_array($check->status, [KeyCheck::INVALID, KeyCheck::MISSING], true)) {
            $text .= ' (' . Messages::SIGNUP_URL . ', ' . Messages::API_KEY_URL . ')';
        }
        $ok = $check->status === KeyCheck::OK;
        $this->stdout('Supertext Translation ' . $plugin->getVersion() . ', API ' . $plugin->getSettings()->getBaseUrl() . "\n");
        $this->stdout($text . "\n", $ok ? Console::FG_GREEN : Console::FG_RED);
        $this->stdout('Version report to Supertext: ' . match (true) {
            !$reporting->enabled() => 'off',
            $result['sent'] => 'sent (' . $result['event'] . ')',
            default => 'not sent',
        } . "\n");

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }
}
