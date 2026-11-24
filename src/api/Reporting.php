<?php

/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace supertext\crafttranslation\api;

use supertext\crafttranslation\pluginhealth\CallableKeyChecker;
use supertext\crafttranslation\pluginhealth\InstallationReporter;
use supertext\crafttranslation\pluginhealth\KeyCheck;
use supertext\crafttranslation\pluginhealth\PingResponse;
use supertext\crafttranslation\pluginhealth\ReporterResult;
use supertext\crafttranslation\pluginhealth\ReporterState;
use supertext\crafttranslation\sdk\Supertext;
use supertext\crafttranslation\sdk\V1\Client;
use supertext\crafttranslation\sdk\V1\Protocol;

/**
 * Version reports to the Supertext Commando Center, the automatic API key check and the ping
 * (Commando-Center-Supertext-Translation → docs/DEVELOPER.md → Report protocol). The protocol is
 * Supertext Plugin Health's InstallationReporter (lib/supertext-plugin-health) with the Supertext SDK's
 * checkApiKey() as key checker; this class only loads the reporter's state, runs one action, and stores
 * the new state. Nothing here ever throws: reporting must never break a save or a translation.
 *
 * Has no Craft dependencies (services/Reporting gives it Craft's values and storage).
 */
final class Reporting
{
    /** The plugin's GitHub repository name, as the center expects it. */
    public const PLUGIN_NAME = 'CraftCms-Supertext-Translation';

    public const SYSTEM_NAME = 'Craft CMS';

    /** @var callable(string, string, array<string, string>, ?string, float): array{status: int, body: string, headers?: array<string, string>} */
    private $transport;

    /** @var callable(): ?string */
    private $load;

    /** @var callable(string): void */
    private $save;

    /** @var (callable(): float)|null */
    private $now;

    /**
     * @param bool        $enabled       the setting combined with SUPERTEXT_VERSION_REPORTS: see enabled()
     * @param string|null $siteUrl       the site's base URL; null: nothing is reported (the key check still runs)
     * @param string|null $callbackUrl   the ping endpoint's absolute URL
     * @param callable(string, string, array<string, string>, ?string, float): array{status: int, body: string, headers?: array<string, string>} $transport
     * @param callable(): ?string    $load the stored state (JSON), null if none
     * @param callable(string): void $save stores the state (JSON)
     * @param string|null $reportUrl     tests: the center's URL (default: SUPERTEXT_REPORT_URL, else Plugin Health's)
     * @param (callable(): float)|null $now tests: wall-clock Unix seconds
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly ?string $siteUrl,
        private readonly string $pluginVersion,
        private readonly ?string $systemVersion,
        private readonly ?string $callbackUrl,
        private readonly string $apiBaseUrl,
        callable $transport,
        callable $load,
        callable $save,
        private readonly ?string $reportUrl = null,
        ?callable $now = null,
    ) {
        $this->transport = $transport;
        $this->load = $load;
        $this->save = $save;
        $this->now = $now;
    }

    /** Whether reports are on: SUPERTEXT_VERSION_REPORTS (0/false/off/no or 1/true/on/yes) wins over the setting. */
    public static function enabled(bool $setting): bool
    {
        return InstallationReporter::reportsEnabled($setting);
    }

    /** Whether SUPERTEXT_VERSION_REPORTS on this server overrides the setting. */
    public static function forcedByEnvironment(): bool
    {
        return !InstallationReporter::reportsEnabled(true) || InstallationReporter::reportsEnabled(false);
    }

    /** The answer of the public ping endpoint (status, headers and body as Plugin Health returns them). */
    public static function ping(?string $challenge, bool $enabled): PingResponse
    {
        return InstallationReporter::pingResponse($challenge, self::PLUGIN_NAME, $enabled);
    }

    /** After the settings were saved: checks the key, reports `installed` or `settings_saved`. */
    public function afterSettingsSaved(#[\SensitiveParameter] string $apiKey): ?ReporterResult
    {
        return $this->run(static fn (InstallationReporter $r, ReporterState $s) => $r->afterSettingsSaved($s, $apiKey));
    }

    /** Back-end requests that run the plugin's code: checks a changed key, reports what changed. */
    public function onRequest(#[\SensitiveParameter] string $apiKey): ?ReporterResult
    {
        return $this->run(static fn (InstallationReporter $r, ReporterState $s) => $r->onRequest($s, $apiKey));
    }

    /** A translation got 401/403: the key is invalid (reported once, when the status changes). */
    public function authenticationFailed(#[\SensitiveParameter] string $apiKey, int $httpStatus = 401): ?ReporterResult
    {
        return $this->run(static fn (InstallationReporter $r, ReporterState $s) => $r->authenticationFailed($s, $apiKey, $httpStatus));
    }

    /** The settings page's *Test connection*: checks the key, reports a change. */
    public function testConnection(#[\SensitiveParameter] string $apiKey): ?ReporterResult
    {
        return $this->run(static fn (InstallationReporter $r, ReporterState $s) => $r->testConnection($s, $apiKey));
    }

    /** The `check` console command: sends a `check` report now. */
    public function reportCheck(): ?ReporterResult
    {
        return $this->run(static fn (InstallationReporter $r, ReporterState $s) => $r->reportCheck($s));
    }

    /**
     * The settings page's *Send configuration to Supertext*: the installed plugins and the language mapping
     * in one `extensions` report, now (also after a recent failure), but only while reports are on.
     *
     * @param list<array{name: string, version?: string, active?: bool, type?: string}> $extensions
     * @param list<array{code: string, supertext: ?string}>                            $languages
     */
    public function sendConfiguration(array $extensions, array $languages): ?ReporterResult
    {
        return $this->run(fn (InstallationReporter $r, ReporterState $s) => $this->reporter(static fn (): array => $languages)->sendExtensions($s, $extensions));
    }

    /** The stored state (empty if none or unreadable). */
    public function state(): ReporterState
    {
        try {
            return ReporterState::from(($this->load)());
        } catch (\Throwable) {
            return ReporterState::empty();
        }
    }

    /** The latest stored key check, for the settings page. */
    public function lastCheck(): ?KeyCheck
    {
        return $this->state()->keyCheck;
    }

    /** @param (callable(): iterable<mixed>)|null $languages the language mapping every report of this reporter carries */
    public function reporter(?callable $languages = null): InstallationReporter
    {
        return new InstallationReporter(
            enabled: $this->enabled && $this->siteUrl !== null,
            siteUrl: $this->siteUrl ?? '',
            pluginName: self::PLUGIN_NAME,
            pluginVersion: $this->pluginVersion,
            systemName: self::SYSTEM_NAME,
            keyChecker: $this->keyChecker(),
            systemVersion: $this->systemVersion,
            sdkVersion: Supertext::SDK_VERSION,
            callbackUrl: $this->callbackUrl,
            reportUrl: $this->reportUrl,
            transport: new ReportTransport($this->transport),
            now: $this->now,
            languages: $languages,
        );
    }

    /** The SDK's key check (GET features) against the configured API, through the plugin's transport. */
    private function keyChecker(): CallableKeyChecker
    {
        $baseUrl = $this->apiBaseUrl;
        $userAgent = self::PLUGIN_NAME . '/' . $this->pluginVersion;
        $transport = new CallableTransport($this->transport);
        $now = $this->now;

        return new CallableKeyChecker(
            static fn (string $key) => (new Client($key, $baseUrl, userAgent: $userAgent, transport: $transport, now: $now))->checkApiKey(),
            [Protocol::class, 'apiKeyFingerprint'],
        );
    }

    /** @param \Closure(InstallationReporter, ReporterState): ReporterResult $action */
    private function run(\Closure $action): ?ReporterResult
    {
        try {
            $result = $action($this->reporter(), $this->state());
        } catch (\Throwable) {
            return null;
        }
        try {
            ($this->save)((string) json_encode($result->state, JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            // The state couldn't be stored (e.g. the table is missing before `craft up`): next time again.
        }

        return $result;
    }
}
