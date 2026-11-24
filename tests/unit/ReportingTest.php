<?php

namespace supertext\crafttranslation\tests\unit;

use PHPUnit\Framework\TestCase;
use supertext\crafttranslation\api\Reporting;
use supertext\crafttranslation\sdk\Supertext;

/** The plugin's Commando Center glue: state kept between calls, which action reports what, ping answers, never throwing. */
final class ReportingTest extends TestCase
{
    private const CENTER = 'http://center.test/api/v1/report';
    private const KEY = 'secret-test-key';

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: ?float}> */
    private array $calls = [];

    private ?string $stored = null;

    private int $features = 200;

    private int $center = 202;

    private bool $storageFails = false;

    private function reporting(bool $enabled = true, ?string $siteUrl = 'https://craft.example.ch', string $version = '1.2.0'): Reporting
    {
        $transport = function (string $method, string $url, array $headers, ?string $body, ?float $timeout = null): array {
            $this->calls[] = compact('method', 'url', 'headers', 'body', 'timeout');
            if ($url === self::CENTER) {
                return ['status' => $this->center, 'body' => '{"ok":true}'];
            }

            return ['status' => $this->features, 'body' => $this->features === 200 ? '[]' : '{"error_code":"UNAUTHORIZED"}'];
        };

        return new Reporting(
            enabled: $enabled,
            siteUrl: $siteUrl,
            pluginVersion: $version,
            systemVersion: '5.11.5',
            callbackUrl: $siteUrl !== null ? $siteUrl . '/actions/supertext-translation/ping' : null,
            apiBaseUrl: 'https://api.test/v1/',
            transport: $transport,
            load: fn (): ?string => $this->storageFails ? throw new \RuntimeException('no table') : $this->stored,
            save: function (string $json): void {
                if ($this->storageFails) {
                    throw new \RuntimeException('no table');
                }
                $this->stored = $json;
            },
            reportUrl: self::CENTER,
        );
    }

    /** @return list<array<string, mixed>> */
    private function reports(): array
    {
        $reports = [];
        foreach ($this->calls as $call) {
            if ($call['url'] === self::CENTER) {
                $reports[] = json_decode((string) $call['body'], true);
            }
        }

        return $reports;
    }

    public function testSavingTheSettingsChecksTheKeyAndReportsTheInstallation(): void
    {
        $result = $this->reporting()->afterSettingsSaved(self::KEY);

        self::assertNotNull($result);
        self::assertSame('ok', $result->check?->status);
        self::assertSame('installed', $result->event);
        self::assertTrue($result->sent);
        [$report] = $this->reports();
        self::assertSame('https://craft.example.ch', $report['site_url']);
        self::assertSame(['name' => 'CraftCms-Supertext-Translation', 'version' => '1.2.0'], $report['plugin']);
        self::assertSame(['name' => 'Craft CMS', 'version' => '5.11.5'], $report['system']);
        self::assertSame('PHP', $report['runtime']['name']);
        self::assertSame(Supertext::SDK_VERSION, $report['sdk_version'], 'the bundled SDK');
        self::assertSame('https://api.test/v1/features', $this->calls[0]['url'], 'the key check uses the configured API');
        self::assertTrue($report['api_key_configured']);
        self::assertSame('ok', $report['api_key_check']['status']);
        self::assertSame('https://craft.example.ch/actions/supertext-translation/ping', $report['callback']['url']);
        self::assertSame(3.0, $this->calls[1]['timeout'], 'reports wait at most 3 seconds');

        // The state is stored as Plugin Health's JSON (the same the SDK 0.2 reporter stored); the key itself never is, nor is it sent to the center.
        self::assertStringNotContainsString(self::KEY, (string) $this->stored);
        foreach ($this->calls as $call) {
            if ($call['url'] === self::CENTER) {
                self::assertStringNotContainsString(self::KEY, (string) $call['body']);
            }
        }
        self::assertSame('ok', $this->reporting()->lastCheck()?->status);

        // The next save reports settings_saved.
        self::assertSame('settings_saved', $this->reporting()->afterSettingsSaved(self::KEY)?->event);
    }

    public function testRequestsReportOnlyWhatChanged(): void
    {
        $this->reporting()->afterSettingsSaved(self::KEY);
        $this->calls = [];

        $result = $this->reporting()->onRequest(self::KEY);
        self::assertNull($result?->event);
        self::assertSame([], $this->calls, 'same key, same versions: no request at all');

        self::assertSame('upgraded', $this->reporting(version: '1.3.0')->onRequest(self::KEY)?->event);
    }

    public function testARejectedTranslationMarksTheKeyInvalidOnce(): void
    {
        $this->reporting()->afterSettingsSaved(self::KEY);
        $this->calls = [];

        $result = $this->reporting()->authenticationFailed(self::KEY, 403);
        self::assertSame('invalid', $result?->check?->status);
        self::assertSame(403, $result?->check?->httpStatus);
        self::assertSame('api_key_check', $result?->event);
        self::assertCount(1, $this->calls, 'no request to Supertext, one report');

        self::assertNull($this->reporting()->authenticationFailed(self::KEY, 401)?->event, 'reported once, not per failed translation');
    }

    public function testTestConnectionShowsTheCheck(): void
    {
        $this->features = 401;
        $result = $this->reporting()->testConnection(self::KEY);

        self::assertSame('invalid', $result?->check?->status);
        self::assertSame('installed', $result?->event);
        self::assertSame('missing', $this->reporting()->testConnection('')->check?->status);
    }

    public function testCheckCommandSendsACheckReport(): void
    {
        $this->reporting()->afterSettingsSaved(self::KEY);

        self::assertSame('check', $this->reporting()->reportCheck()?->event);
    }

    public function testSendConfigurationSendsThePluginsAndTheLanguageMapping(): void
    {
        $this->reporting()->afterSettingsSaved(self::KEY);
        $this->center = 503;
        $this->reporting()->onRequest(self::KEY);
        $this->center = 202;
        $this->calls = [];

        $result = $this->reporting()->sendConfiguration(
            [['name' => 'Supertext Translation', 'version' => '1.0.0', 'active' => true, 'type' => 'plugin'], ['name' => 'SEOmatic', 'version' => '5.1.0', 'active' => false, 'type' => 'plugin']],
            [['code' => 'en-US', 'supertext' => 'en-US'], ['code' => 'de-CH', 'supertext' => 'de-CH'], ['code' => 'en-IE', 'supertext' => null]],
        );

        self::assertSame('extensions', $result?->event);
        self::assertTrue($result?->sent, 'sent right away, even within the hour after a failed report');
        [$report] = $this->reports();
        self::assertSame('extensions', $report['event']);
        self::assertSame(['name' => 'SEOmatic', 'version' => '5.1.0', 'active' => false, 'type' => 'plugin'], $report['extensions'][1]);
        self::assertSame([['code' => 'en-US', 'supertext' => 'en-US'], ['code' => 'de-CH', 'supertext' => 'de-CH'], ['code' => 'en-IE', 'supertext' => null]], $report['languages']);
        self::assertArrayNotHasKey('languages', $this->reporting()->reporter()->buildReport('check', $this->reporting()->state()), 'other reports carry no mapping');
    }

    public function testSendConfigurationSendsNothingWithReportsOff(): void
    {
        $result = $this->reporting(enabled: false)->sendConfiguration([['name' => 'SEOmatic']], [['code' => 'de-CH', 'supertext' => 'de-CH']]);

        self::assertFalse((bool) $result?->sent);
        self::assertSame([], $this->reports());
    }

    public function testNothingIsSentWithReportsOff(): void
    {
        $result = $this->reporting(enabled: false)->afterSettingsSaved(self::KEY);

        self::assertSame('ok', $result?->check?->status, 'the key check runs anyway');
        self::assertFalse($result?->sent);
        self::assertSame([], $this->reports());
        self::assertNull($this->reporting(enabled: false)->reportCheck()?->event);
    }

    public function testNothingIsSentWithoutASiteUrl(): void
    {
        $result = $this->reporting(siteUrl: null)->afterSettingsSaved(self::KEY);

        self::assertSame('ok', $result?->check?->status);
        self::assertSame([], $this->reports());
    }

    public function testAFailedReportIsNotRetriedWithinTheHour(): void
    {
        $this->center = 503;
        self::assertFalse($this->reporting()->afterSettingsSaved(self::KEY)?->sent);
        $this->center = 202;
        $this->calls = [];

        self::assertNull($this->reporting()->onRequest(self::KEY)?->event);
        self::assertSame([], $this->reports());
    }

    public function testBrokenStorageNeverThrows(): void
    {
        $this->storageFails = true;

        self::assertSame('ok', $this->reporting()->afterSettingsSaved(self::KEY)?->check?->status);
        self::assertNull($this->reporting()->lastCheck());
    }

    public function testPingAnswers(): void
    {
        $ok = Reporting::ping('abcdefghijklmnop1234', true);
        self::assertSame(200, $ok->status);
        self::assertSame(['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'], $ok->headers);
        self::assertSame('{"ok":true,"plugin":"CraftCms-Supertext-Translation","challenge":"abcdefghijklmnop1234"}', $ok->body);

        self::assertSame(400, Reporting::ping('<script>', true)->status);
        self::assertSame(400, Reporting::ping(null, true)->status);
        self::assertSame(404, Reporting::ping('abcdefghijklmnop1234', false)->status);
    }

    public function testTheEnvironmentWinsOverTheSetting(): void
    {
        $old = getenv('SUPERTEXT_VERSION_REPORTS');
        try {
            putenv('SUPERTEXT_VERSION_REPORTS');
            self::assertTrue(Reporting::enabled(true));
            self::assertFalse(Reporting::enabled(false));
            self::assertFalse(Reporting::forcedByEnvironment());

            putenv('SUPERTEXT_VERSION_REPORTS=0');
            self::assertFalse(Reporting::enabled(true));
            self::assertTrue(Reporting::forcedByEnvironment());
        } finally {
            putenv($old === false ? 'SUPERTEXT_VERSION_REPORTS' : 'SUPERTEXT_VERSION_REPORTS=' . $old);
        }
    }

    public function testAStateStoredByTheSdk02ReporterIsKept(): void
    {
        $reporting = $this->reporting();
        $versions = $reporting->reporter()->versions();
        $this->stored = '{"reportedVersions":"' . $versions . '","reportedKeyStatus":"ok","lastFailureAt":null,"keyCheck":{"status":"ok","checkedAt":"2026-10-01T08:00:00Z","httpStatus":200,"errorCode":null,"fingerprint":"' . hash('sha256', self::KEY) . '"}}';
        $before = $this->stored;

        self::assertNull($reporting->onRequest(self::KEY)?->event, 'no new installed report after the update');
        self::assertSame([], $this->calls);
        // Plugin Health 0.2+ adds keyChecks and reportedKeyStatuses (null with one key); everything else is unchanged.
        self::assertSame(
            json_decode($before, true) + ['keyChecks' => null, 'reportedKeyStatuses' => null],
            json_decode((string) $this->stored, true),
            'stored in the same format',
        );
    }
}
