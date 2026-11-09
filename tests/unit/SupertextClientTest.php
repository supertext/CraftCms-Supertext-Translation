<?php

namespace supertext\crafttranslation\tests\unit;

use PHPUnit\Framework\TestCase;
use supertext\crafttranslation\api\SupertextClient;
use supertext\crafttranslation\api\SupertextException;

final class SupertextClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    private array $calls = [];

    /** @param callable(string, string, ?string): array{status: int, body: string, headers?: array<string, string>} $handler */
    private function client(callable $handler, string $key = 'test-key'): SupertextClient
    {
        $transport = function (string $method, string $url, array $headers, ?string $body) use ($handler): array {
            $this->calls[] = compact('method', 'url', 'headers', 'body');

            return $handler($method, $url, $body) + ['headers' => []];
        };

        return new SupertextClient($key, 'https://api.test/v1', $transport, 10, 0.01, static function (): void {
        });
    }

    public function testRunsTheFullProtocol(): void
    {
        $polls  = 0;
        $client = $this->client(function (string $method, string $url) use (&$polls): array {
            return match (true) {
                $method === 'POST'                    => ['status' => 200, 'body' => '{"file_id":"f1"}'],
                str_ends_with($url, '/status')        => ['status' => 200, 'body' => json_encode(['status' => ++$polls < 2 ? 'running' : 'done'])],
                str_ends_with($url, '/translation')   => ['status' => 200, 'body' => '<div data-st-id="0">Hallo</div>'],
                default                               => ['status' => 200, 'body' => '{}'],
            };
        });

        $html = $client->translateDocument('<div data-st-id="0">Hello</div>', 'de-CH', 'en', 'more');

        self::assertStringContainsString('Hallo', $html);
        self::assertSame(
            ['POST translate/ai/file', 'GET translate/ai/file/f1/status', 'GET translate/ai/file/f1/status', 'GET translate/ai/file/f1/translation', 'DELETE translate/ai/file/f1'],
            array_map(static fn (array $c): string => $c['method'] . ' ' . str_replace('https://api.test/v1/', '', $c['url']), $this->calls)
        );

        $post = $this->calls[0];
        self::assertSame('Supertext-Auth-Key test-key', $post['headers']['Authorization']);
        self::assertStringStartsWith('multipart/form-data; boundary=', $post['headers']['Content-Type']);
        self::assertStringContainsString("name=\"target_lang\"\r\n\r\nde-CH\r\n", $post['body']);
        self::assertStringContainsString("name=\"source_lang\"\r\n\r\nen\r\n", $post['body']);
        self::assertStringContainsString("name=\"politeness\"\r\n\r\nmore\r\n", $post['body']);
        self::assertStringContainsString("filename=\"content.html\"\r\nContent-Type: text/html\r\n\r\n<div", $post['body']);
    }

    public function testSendsLanguageCodesAsGiven(): void
    {
        $client = $this->client(fn (string $method, string $url) => match (true) {
            $method === 'POST'                  => ['status' => 200, 'body' => '{"file_id":"f1"}'],
            str_ends_with($url, '/status')      => ['status' => 200, 'body' => '{"status":"done"}'],
            str_ends_with($url, '/translation') => ['status' => 200, 'body' => '<div data-st-id="0">Hola</div>'],
            default                             => ['status' => 200, 'body' => '{}'],
        });

        $client->translateDocument('<div data-st-id="0">Hello</div>', 'es', 'en');

        self::assertStringContainsString("name=\"target_lang\"\r\n\r\nes\r\n", $this->calls[0]['body']);
        self::assertStringContainsString("name=\"source_lang\"\r\n\r\nen\r\n", $this->calls[0]['body']);
    }

    public function testExplainsAToneTheLanguageLacks(): void
    {
        $client = $this->client(fn () => ['status' => 400, 'body' => '{"error_code":"FEATURE_ERROR","detail":"politeness not supported"}']);

        try {
            $client->translateDocument('<p>x</p>', 'en-US', 'de', 'more');
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertSame('unsupported_feature', $e->reason);
        }
    }

    public function testChecksTheKeyWithoutThrowing(): void
    {
        $check = $this->client(fn () => ['status' => 401, 'body' => '{"error_code":"UNAUTHORIZED"}'])->checkApiKey();
        self::assertSame('invalid', $check->status);
        self::assertSame(401, $check->httpStatus);

        self::assertSame('missing', $this->client(fn () => ['status' => 200, 'body' => '{}'], '')->checkApiKey()->status);
        self::assertSame('ok', $this->client(fn () => ['status' => 200, 'body' => '{}'])->checkApiKey()->status);
    }

    public function testAcceptsTheKeyWithItsPrefix(): void
    {
        $client = $this->client(fn () => ['status' => 200, 'body' => '{}'], '  Supertext-Auth-Key abc+/= ');
        $client->validateApiKey();

        self::assertSame('Supertext-Auth-Key abc+/=', $this->calls[0]['headers']['Authorization']);
    }

    public function testRetriesWhenRateLimited(): void
    {
        $limited = 2;
        $client  = $this->client(function () use (&$limited): array {
            return $limited-- > 0 ? ['status' => 429, 'body' => 'slow down'] : ['status' => 200, 'body' => '{}'];
        });
        $client->validateApiKey();

        self::assertCount(3, $this->calls);
    }

    public function testGivesUpAfterFourRetries(): void
    {
        $client = $this->client(fn () => ['status' => 429, 'body' => 'slow down']);

        try {
            $client->validateApiKey();
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertStringContainsString('Too many requests', $e->getMessage());
        }

        self::assertCount(5, $this->calls);
    }

    public function testHonoursRetryAfter(): void
    {
        self::assertSame(3.0, SupertextClient::retryDelay(0, '3'));
        self::assertGreaterThanOrEqual(2.0, SupertextClient::retryDelay(1));
    }

    public function testGivesAReasonTheCmsCanTranslate(): void
    {
        $client = $this->client(fn () => ['status' => 418, 'body' => 'teapot']);

        try {
            $client->validateApiKey();
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertSame('http_error', $e->reason);
            self::assertSame([418], $e->args);
            self::assertSame('teapot', $e->detail);
            self::assertSame('Supertext answered with HTTP 418. (teapot)', $e->getMessage());
        }
    }

    public function testExplainsAuthenticationErrors(): void
    {
        $client = $this->client(fn () => ['status' => 401, 'body' => '{"detail":"bad key"}']);

        $this->expectException(SupertextException::class);
        $this->expectExceptionMessageMatches('/Authentication failed.*bad key/');
        $client->translateDocument('<p>x</p>', 'fr-FR');
    }

    public function testStopsOnLimitExceededAndCleansUp(): void
    {
        $client = $this->client(function (string $method, string $url): array {
            return match (true) {
                $method === 'POST'             => ['status' => 200, 'body' => '{"file_id":"f2"}'],
                str_ends_with($url, '/status') => ['status' => 200, 'body' => '{"status":"limit_exceeded"}'],
                default                        => ['status' => 200, 'body' => '{}'],
            };
        });

        try {
            $client->translateDocument('<p>x</p>', 'fr-FR');
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertStringContainsString('limit is exceeded', $e->getMessage());
            self::assertSame('limit_exceeded', $e->reason);
        }

        self::assertSame('DELETE', end($this->calls)['method']);
    }

    public function testRefusesToRunWithoutKey(): void
    {
        $client = $this->client(fn () => ['status' => 200, 'body' => '{}'], '');

        self::assertFalse($client->hasApiKey());
        $this->expectException(SupertextException::class);
        $client->validateApiKey();
    }

    public function testTranslatesSegmentsAndToleratesMissingOnes(): void
    {
        $client = $this->client(fn (string $method, string $url) => match (true) {
            $method === 'POST'                  => ['status' => 200, 'body' => '{"file_id":"f1"}'],
            str_ends_with($url, '/status')      => ['status' => 200, 'body' => '{"status":"done"}'],
            str_ends_with($url, '/translation') => ['status' => 200, 'body' => '<div data-st-id="0">Hallo<br>Welt</div><div data-st-id="1"><p>Ein <b>Text</b></p></div>'],
            default                             => ['status' => 200, 'body' => '{}'],
        });

        $result = $client->translateSegments([
            3 => ['text' => "Hello\nworld", 'html' => false],
            5 => ['text' => '<p>A <b>text</b></p>', 'html' => true],
            7 => ['text' => '   ', 'html' => false],
            9 => ['text' => 'Lost', 'html' => false],
        ], 'de-CH', 'en');

        self::assertSame([3 => "Hallo\nWelt", 5 => '<p>Ein <b>Text</b></p>'], $result);
        self::assertStringContainsString('<div data-st-id="0">Hello<br>world</div>', (string) $this->calls[0]['body']);
        self::assertStringContainsString('<div data-st-id="2">Lost</div>', (string) $this->calls[0]['body']);
    }

    public function testSplitsLargeContentIntoSeveralDocuments(): void
    {
        $posts = 0;
        $client = $this->client(function (string $method, string $url) use (&$posts) {
            return match (true) {
                $method === 'POST'                  => ['status' => 200, 'body' => '{"file_id":"f' . ++$posts . '"}'],
                str_ends_with($url, '/status')      => ['status' => 200, 'body' => '{"status":"done"}'],
                str_ends_with($url, '/translation') => ['status' => 200, 'body' => '<div data-st-id="0">x</div>'],
                default                             => ['status' => 200, 'body' => '{}'],
            };
        });

        $client->translateSegments([['text' => str_repeat('a', 8), 'html' => false], ['text' => str_repeat('b', 8), 'html' => false]], 'de-DE', '', 'default', 10);

        self::assertSame(2, $posts);
    }

    public function testReportsAnUnsupportedLanguagePair(): void
    {
        $client = $this->client(fn () => ['status' => 400, 'body' => '{"error_code":"INVALID_LANGUAGE_PAIR","source_lang":"en","target_lang":"xx-YY"}']);

        try {
            $client->translateDocument('<p>x</p>', 'xx-YY', 'en');
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertSame('invalid_language_pair', $e->reason);
            self::assertSame(['en', 'xx-YY'], $e->args);
        }
    }

    public function testEnvironments(): void
    {
        self::assertSame(SupertextClient::LIVE, SupertextClient::baseUrlFor('live'));
        self::assertSame(SupertextClient::STAGING, SupertextClient::baseUrlFor('staging'));
        self::assertSame('http://127.0.0.1:8765/v1/', SupertextClient::baseUrlFor('live', 'http://127.0.0.1:8765/v1/'));
    }

    /** @return callable(string, string, array<string, string>, ?string): array{status: int, body: string, headers: array<string, string>} */
    private function recordingTransport(): callable
    {
        return function (string $method, string $url, array $headers, ?string $body): array {
            $this->calls[] = compact('method', 'url', 'headers', 'body');

            return match (true) {
                $method === 'POST'                  => ['status' => 200, 'body' => '{"file_id":"f1"}', 'headers' => []],
                str_ends_with($url, '/status')      => ['status' => 200, 'body' => '{"status":"done"}', 'headers' => []],
                str_ends_with($url, '/translation') => ['status' => 200, 'body' => '<div data-st-id="0">Hallo</div>', 'headers' => []],
                default                             => ['status' => 200, 'body' => '{}', 'headers' => []],
            };
        };
    }

    public function testTranslationReviewGoesThroughTheReviewService(): void
    {
        $client = new SupertextClient('test-key', 'https://api.test/v1/', $this->recordingTransport(), 10, 0.01, static function (): void {
        }, 'https://review.test/v1/', '1.2.3');

        self::assertSame($client, $client->forReview(false), 'review off: the direct client');
        $review = $client->forReview(true);
        self::assertNotSame($client, $review);
        self::assertSame($review, $client->forReview(true), 'one review client');

        // A site with review on: upload, status, download and delete all through the review service.
        self::assertStringContainsString('Hallo', $review->translateDocument('<div data-st-id="0">Hello</div>', 'de-CH', 'en'));
        self::assertSame(
            ['POST review.test', 'GET review.test', 'GET review.test', 'DELETE review.test'],
            array_map(static fn (array $c): string => $c['method'] . ' ' . parse_url($c['url'], \PHP_URL_HOST), $this->calls)
        );
        self::assertSame('Supertext-Auth-Key test-key', $this->calls[0]['headers']['Authorization'], 'same key');
        self::assertStringContainsString('CraftCms-Supertext-Translation/1.2.3', $this->calls[0]['headers']['User-Agent'], 'User-Agent with the version');

        // Another site: directly to Supertext.
        $this->calls = [];
        $client->forReview(false)->translateDocument('<div data-st-id="0">Hello</div>', 'fr-CH', 'en');
        self::assertSame(
            ['POST api.test', 'GET api.test', 'GET api.test', 'DELETE api.test'],
            array_map(static fn (array $c): string => $c['method'] . ' ' . parse_url($c['url'], \PHP_URL_HOST), $this->calls)
        );
        self::assertStringContainsString('CraftCms-Supertext-Translation/1.2.3', $this->calls[0]['headers']['User-Agent']);

        // The key check never uses the review service.
        $this->calls = [];
        $client->validateApiKey();
        self::assertSame('https://api.test/v1/features', $this->calls[0]['url']);
    }

    public function testTranslationReviewOffWithoutReviewUrl(): void
    {
        $client = new SupertextClient('test-key', 'https://api.test/v1/', $this->recordingTransport());
        self::assertSame($client, $client->forReview(true), 'empty review URL: review off');

        $same = new SupertextClient('test-key', 'https://api.test/v1', $this->recordingTransport(), reviewUrl: 'https://api.test/v1/');
        self::assertSame($same, $same->forReview(true), 'review URL = API URL: no second client');
    }

    public function testReviewUrlDefaultsToSupertextsReviewService(): void
    {
        self::assertSame('https://localizationreview-production.up.railway.app/v1/', SupertextClient::REVIEW_URL);
        $settings = new \ReflectionClass(\supertext\crafttranslation\models\Settings::class);
        self::assertSame(SupertextClient::REVIEW_URL, $settings->getProperty('reviewUrl')->getDefaultValue(), 'the setting\'s default');

        self::assertSame(SupertextClient::REVIEW_URL, SupertextClient::reviewUrlFor(null, SupertextClient::REVIEW_URL), 'no SUPERTEXT_REVIEW_URL: the setting');
        self::assertSame('', SupertextClient::reviewUrlFor(null, ' '), 'setting empty: off');
        self::assertSame('http://localhost:3000/v1/', SupertextClient::reviewUrlFor(' http://localhost:3000/v1/ ', SupertextClient::REVIEW_URL), 'SUPERTEXT_REVIEW_URL wins');
        self::assertSame('', SupertextClient::reviewUrlFor('', SupertextClient::REVIEW_URL), 'SUPERTEXT_REVIEW_URL empty: off');
    }
}
