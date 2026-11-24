<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use supertext\crafttranslation\api\Reporting as ApiReporting;
use supertext\crafttranslation\Plugin;
use supertext\crafttranslation\pluginhealth\InstallationReporter;
use supertext\crafttranslation\pluginhealth\KeyCheck;
use supertext\crafttranslation\pluginhealth\ReporterResult;
use yii\web\Response as YiiResponse;

/**
 * Craft's side of the Commando Center (version reports, API key check, ping): the site URL, the Craft
 * and plugin versions, the setting, and the reporter's state in the table `supertext_state`.
 *
 * - afterSettingsSaved(): Plugin::afterSaveSettings() (every save of the plugin settings)
 * - onRequest() / authenticationFailed(): translate actions, the Supertext box, the settings page,
 *   queue jobs and console commands. On web requests they run after the response was sent.
 * - testConnection(): the settings page's *Test connection*; check(): `translate/check`.
 * - sendConfiguration(): the settings page's *Send configuration to Supertext* (installed plugins and language mapping).
 *
 * Nothing here throws into a request (api/Reporting catches everything).
 */
class Reporting extends Component
{
    public const STATE_TABLE = '{{%supertext_state}}';
    private const STATE_NAME = 'reporter';

    /** Path of the public ping action below the site URL. */
    public const PING_ACTION = 'supertext-translation/ping';

    /** @var (callable(string, string, array<string, string>, ?string, float): array{status: int, body: string, headers: array<string, string>})|null Tests only. */
    public $transport = null;

    /** Tests only: the center's URL (default SUPERTEXT_REPORT_URL, else Plugin Health's). */
    public ?string $reportUrl = null;

    /** @var (\Closure(): ?ReporterResult)|null the action that runs after this web request */
    private ?\Closure $deferred = null;

    private bool $deferredHasPriority = false;

    /** Whether version reports are on (the setting; SUPERTEXT_VERSION_REPORTS wins). */
    public function enabled(): bool
    {
        return ApiReporting::enabled(Plugin::getInstance()->getSettings()->versionReports);
    }

    public function afterSettingsSaved(): ?ReporterResult
    {
        return $this->reporting()->afterSettingsSaved($this->apiKey());
    }

    /** Back-end requests that run the plugin's code: after the response (web) or right away (console, queue). */
    public function onRequest(): void
    {
        $this->defer(fn () => $this->reporting()->onRequest($this->apiKey()), false);
    }

    /** A translation was rejected with 401/403. */
    public function authenticationFailed(int $httpStatus = 401): void
    {
        $status = \in_array($httpStatus, [401, 403], true) ? $httpStatus : 401;
        $this->defer(fn () => $this->reporting()->authenticationFailed($this->apiKey(), $status), true);
    }

    /** *Test connection*: the key check (and a report if its status changed). */
    public function testConnection(): KeyCheck
    {
        $result = $this->reporting()->testConnection($this->apiKey());

        return $result->check ?? self::failedCheck();
    }

    /**
     * `translate/check`: the key check, then a `check` report unless the key check already sent one.
     *
     * @return array{check: KeyCheck, event: ?string, sent: bool}
     */
    public function check(): array
    {
        $reporting = $this->reporting();
        $result = $reporting->testConnection($this->apiKey());
        if ($result !== null && $result->event === null && $this->enabled()) {
            $result = $reporting->reportCheck() ?? $result;
        }
        $check = $result->check ?? self::failedCheck();

        return ['check' => $check, 'event' => $result?->event, 'sent' => (bool) $result?->sent];
    }

    /**
     * *Send configuration to Supertext*: the installed plugins and every site's Supertext language, now.
     *
     * @return array{enabled: bool, sent: bool, plugins: int, languages: int}
     */
    public function sendConfiguration(): array
    {
        $plugins = InstallationReporter::normalizeExtensions(self::installedPlugins());
        $languages = InstallationReporter::normalizeLanguages(self::languageMapping());
        $enabled = $this->enabled();
        $result = $enabled ? $this->reporting()->sendConfiguration($plugins, $languages) : null;

        return ['enabled' => $enabled, 'sent' => (bool) $result?->sent, 'plugins' => \count($plugins), 'languages' => \count($languages)];
    }

    /**
     * Every installed plugin (enabled or not), this one included. Name and version only: no settings, no licence keys.
     *
     * @return list<array{name: string, version: string, active: bool, type: string}>
     */
    public static function installedPlugins(): array
    {
        $list = [];
        foreach (Craft::$app->getPlugins()->getAllPluginInfo() as $info) {
            if (empty($info['isInstalled'])) {
                continue;
            }
            $list[] = [
                'name' => (string) ($info['name'] ?? ''),
                'version' => (string) ($info['version'] ?? ''),
                'active' => (bool) ($info['isEnabled'] ?? false),
                'type' => 'plugin',
            ];
        }

        return $list;
    }

    /**
     * Every site's language with the Supertext code the plugin translates into (its Supertext language, else
     * the SDK's default for the site's language; null when neither applies).
     *
     * @return list<array{code: string, supertext: ?string}>
     */
    public static function languageMapping(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $list = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $list[] = ['code' => (string) $site->language, 'supertext' => $settings->targetCodeOrNull($site->handle, (string) $site->language)];
        }

        return $list;
    }

    public function lastCheck(): ?KeyCheck
    {
        return $this->reporting()->lastCheck();
    }

    /** The site's base URL: the primary site's, else the current request's host. Null if neither is an absolute http(s) URL. */
    public function siteUrl(): ?string
    {
        try {
            $url = (string) Craft::$app->getSites()->getPrimarySite()->getBaseUrl();
        } catch (\Throwable) {
            $url = '';
        }
        if (!preg_match('#^https?://[^/]#i', $url)) {
            $request = Craft::$app->getRequest();
            $url = $request->getIsConsoleRequest() ? '' : (string) $request->getHostInfo();
        }

        return preg_match('#^https?://[^/]#i', $url) ? rtrim($url, '/') : null;
    }

    /** The ping endpoint's absolute URL (Craft's action URL on the primary site). */
    public function callbackUrl(?string $siteUrl): ?string
    {
        if ($siteUrl === null) {
            return null;
        }
        $config = Craft::$app->getConfig()->getGeneral();
        $path = $config->actionTrigger . '/' . self::PING_ACTION;

        return $config->omitScriptNameInUrls
            ? $siteUrl . '/' . $path
            : $siteUrl . '/index.php?' . ($config->pathParam ?: 'p') . '=' . $path;
    }

    public function reporting(): ApiReporting
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $siteUrl = $this->siteUrl();

        return new ApiReporting(
            enabled: $this->enabled(),
            siteUrl: $siteUrl,
            pluginVersion: $plugin->getVersion(),
            systemVersion: Craft::$app->getVersion(),
            callbackUrl: $this->callbackUrl($siteUrl),
            apiBaseUrl: $settings->getBaseUrl(),
            transport: $this->transport ?? $plugin->getTranslator()->transport ?? Translator::guzzleTransport(),
            load: static fn (): ?string => self::loadState(),
            save: static fn (string $json) => self::saveState($json),
            reportUrl: $this->reportUrl,
        );
    }

    private static function loadState(): ?string
    {
        $value = (new Query())->select(['value'])->from(self::STATE_TABLE)->where(['name' => self::STATE_NAME])->scalar();

        return \is_string($value) ? $value : null;
    }

    private static function saveState(string $json): void
    {
        Db::upsert(self::STATE_TABLE, ['name' => self::STATE_NAME, 'value' => $json]);
    }

    /** Only if Plugin Health failed unexpectedly (it never throws). */
    private static function failedCheck(): KeyCheck
    {
        return new KeyCheck(KeyCheck::ERROR, KeyCheck::timestamp(microtime(true)));
    }

    private function apiKey(): string
    {
        return Plugin::getInstance()->getSettings()->getApiKey();
    }

    /**
     * Web requests: once, after the response was sent (authenticationFailed wins over onRequest).
     * Console and queue: right away.
     *
     * @param \Closure(): ?ReporterResult $action
     */
    private function defer(\Closure $action, bool $priority): void
    {
        try {
            $request = Craft::$app->getRequest();
            if ($request->getIsConsoleRequest()) {
                $action();

                return;
            }
            if ($this->deferred !== null && $this->deferredHasPriority && !$priority) {
                return;
            }
            $first = $this->deferred === null;
            $this->deferred = $action;
            $this->deferredHasPriority = $priority;
            if ($first) {
                Craft::$app->getResponse()->on(YiiResponse::EVENT_AFTER_SEND, function () {
                    $action = $this->deferred;
                    $this->deferred = null;
                    if ($action === null) {
                        return;
                    }
                    if (\function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    try {
                        $action();
                    } catch (\Throwable $e) {
                        Craft::debug('Supertext version report skipped: ' . $e->getMessage(), __METHOD__);
                    }
                });
            }
        } catch (\Throwable $e) {
            Craft::debug('Supertext version report skipped: ' . $e->getMessage(), __METHOD__);
        }
    }
}
