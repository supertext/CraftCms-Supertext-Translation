<?php

namespace supertext\crafttranslation\tests\unit;

use PHPUnit\Framework\TestCase;
use supertext\crafttranslation\api\FileExchange;
use supertext\crafttranslation\sdk\Files\FileJob;
use supertext\crafttranslation\sdk\Files\FileSegment;
use supertext\crafttranslation\sdk\Files\TranslationFileError;
use supertext\crafttranslation\sdk\Files\TranslationFiles;

/** Export for translation / Import translation glue (the Craft side is in Translator::exportFile()/importFile()). */
final class FileExchangeTest extends TestCase
{
    private const SEGMENTS = [
        ['key' => '5.title', 'text' => 'Swiss chocolate', 'html' => false],
        ['key' => '5.slug', 'text' => 'swiss chocolate', 'html' => false],
        ['key' => '5.body', 'text' => '<p>Made in <b>Zurich</b>.</p>', 'html' => true],
        ['key' => '9.text', 'text' => 'Nested', 'html' => false],
    ];

    private const FR = ['siteId' => 3, 'handle' => 'fr', 'language' => 'fr-CH'];
    private const DE = ['siteId' => 4, 'handle' => 'deLi', 'language' => 'de-LI'];

    public function testReferenceRoundTrip(): void
    {
        self::assertSame('entry:5:site-1:site-3', FileExchange::reference(5, 1, 3));
        self::assertSame(['entryId' => 5, 'sourceSiteId' => 1, 'targetSiteId' => 3], FileExchange::parseReference('entry:5:site-1:site-3'));
        foreach (['', 'entry:5', 'entry:5:site-1', 'entry:0:site-1:site-3', 'page:5:site-1:site-3', 'entry:5:1:3', 'entry:5:site-1:site-3:x'] as $invalid) {
            self::assertNull(FileExchange::parseReference($invalid), $invalid);
        }
        self::assertSame('de-CH', FileExchange::fallbackCode('de_CH'));
    }

    public function testXliffExportHasTheCollectorsKeysAndOneJobPerSite(): void
    {
        $file = FileExchange::export(5, 1, 'en', [self::FR, self::DE], self::SEGMENTS, FileExchange::FORMAT_XLIFF);

        self::assertSame('craft-5.xlf', $file['filename']);
        self::assertSame('application/x-xliff+xml', $file['contentType']);
        $jobs = TranslationFiles::read($file['content']);
        self::assertCount(2, $jobs);
        self::assertSame([FileExchange::PLUGIN, 'entry:5:site-1:site-3', 'en', 'fr-CH'], [$jobs[0]->plugin, $jobs[0]->reference, $jobs[0]->sourceLanguage, $jobs[0]->targetLanguage]);
        self::assertSame(['entry:5:site-1:site-4', 'de-LI'], [$jobs[1]->reference, $jobs[1]->targetLanguage]);
        self::assertSame(['5.title', '5.slug', '5.body', '9.text'], array_map(static fn ($u) => $u->key, $jobs[0]->units));
    }

    public function testHtmlExportHasExactlyOneSite(): void
    {
        $file = FileExchange::export(5, 1, 'en', [self::FR], self::SEGMENTS, FileExchange::FORMAT_HTML);
        self::assertSame('craft-5-fr.html', $file['filename']);
        self::assertSame('text/html; charset=utf-8', $file['contentType']);

        $this->expectException(\InvalidArgumentException::class);
        FileExchange::export(5, 1, 'en', [self::FR, self::DE], self::SEGMENTS, FileExchange::FORMAT_HTML);
    }

    public function testNothingToExport(): void
    {
        self::assertNull(FileExchange::export(5, 1, 'en', [self::FR], [['key' => '5.title', 'text' => '  ', 'html' => false]], FileExchange::FORMAT_XLIFF));
    }

    public function testImportWritesOkTexts(): void
    {
        $html = FileExchange::export(5, 1, 'en', [self::FR], self::SEGMENTS, FileExchange::FORMAT_HTML)['content'];
        $translated = str_replace(['Swiss chocolate', 'Made in', 'Nested'], ['Chocolat suisse', 'Fabriqué à', 'Imbriqué'], $html);
        $opened = new Opened(self::SEGMENTS);

        $result = FileExchange::import(FileExchange::read($translated)[0], false, $opened->open(...));

        self::assertNull($result['error']);
        self::assertSame([5, 1, 3], $opened->args);
        self::assertSame(4, $result['imported']);
        self::assertSame('Chocolat suisse', $opened->written['5.title']);
        self::assertSame('<p>Fabriqué à <b>Zurich</b>.</p>', $opened->written['5.body']);
        self::assertSame(['Entry', 'Français'], [$result['title'], $result['site']]);
    }

    public function testSourceChangedOnlyWithTheOption(): void
    {
        $xliff = self::translateXliff(FileExchange::export(5, 1, 'en', [self::FR], self::SEGMENTS, FileExchange::FORMAT_XLIFF)['content']);
        $changed = self::SEGMENTS;
        $changed[0]['text'] = 'Swiss chocolate, shipped worldwide';

        $skip = new Opened($changed);
        $result = FileExchange::import(FileExchange::read($xliff)[0], false, $skip->open(...));
        self::assertSame(1, $result['counts']['source_changed']);
        self::assertSame(3, $result['imported']);
        self::assertArrayNotHasKey('5.title', $skip->written);

        $include = new Opened($changed);
        $result = FileExchange::import(FileExchange::read($xliff)[0], true, $include->open(...));
        self::assertSame(4, $result['imported']);
        self::assertSame('FR Swiss chocolate', $include->written['5.title']);
    }

    public function testUntranslatedInvalidAndMissingAreNotWritten(): void
    {
        $html = FileExchange::export(5, 1, 'en', [self::FR], self::SEGMENTS, FileExchange::FORMAT_HTML)['content'];
        $html = str_replace('<b>Zurich</b>', '<script>x</script>Zurich', $html);
        $current = [...self::SEGMENTS, ['key' => '9.caption', 'text' => 'Added later', 'html' => false]];
        $opened = new Opened($current);

        $result = FileExchange::import(FileExchange::read($html)[0], true, $opened->open(...));
        self::assertSame(1, $result['counts']['invalid_markup']);
        self::assertSame(1, $result['counts']['missing']);
        self::assertArrayNotHasKey('5.body', $opened->written);
        self::assertArrayNotHasKey('9.caption', $opened->written);

        // XLIFF without targets: all untranslated, nothing written.
        $untranslated = new Opened(self::SEGMENTS);
        $xliff = FileExchange::export(5, 1, 'en', [self::FR], self::SEGMENTS, FileExchange::FORMAT_XLIFF)['content'];
        $result = FileExchange::import(FileExchange::read($xliff)[0], true, $untranslated->open(...));
        self::assertSame(4, $result['counts']['untranslated']);
        self::assertSame(0, $result['imported']);
        self::assertNull($untranslated->written);
    }

    public function testRefusesOtherSystemsUnknownReferencesAndErrorsFromLoading(): void
    {
        $file = static fn (string $plugin, string $reference) => FileExchange::read(TranslationFiles::exportHtml(
            new FileJob($plugin, $reference, 'en', 'fr-CH', [new FileSegment('5.title', 'Swiss chocolate')]),
        ))[0];
        $never = static fn () => throw new \LogicException('not loaded');

        $other = FileExchange::import($file('Typo3-Supertext-Translation', 'entry:5:site-1:site-3'), false, $never);
        self::assertSame([FileExchange::OTHER_SYSTEM, ['plugin' => 'Typo3-Supertext-Translation']], [$other['error'], $other['params']]);
        self::assertSame(FileExchange::NOT_FOUND, FileExchange::import($file(FileExchange::PLUGIN, 'page:12'), false, $never)['error']);

        foreach ([FileExchange::NOT_FOUND, FileExchange::NO_LANGUAGE, FileExchange::FORBIDDEN, FileExchange::ALREADY_TRANSLATED] as $error) {
            $result = FileExchange::import($file(FileExchange::PLUGIN, 'entry:5:site-1:site-3'), false, static fn () => ['error' => $error]);
            self::assertSame($error, $result['error']);
            self::assertSame(0, $result['imported']);
        }
    }

    public function testTooLargeFilesAreRefused(): void
    {
        self::assertSame(10 * 1024 * 1024, FileExchange::MAX_UPLOAD_BYTES);
        try {
            FileExchange::read(str_repeat('x', TranslationFiles::MAX_FILE_CHARACTERS + 1));
            self::fail('expected too_large');
        } catch (TranslationFileError $e) {
            self::assertSame(TranslationFileError::TOO_LARGE, $e->errorCode);
        }
    }

    /** Fills every XLIFF target with "FR " + source (inline codes kept). */
    private static function translateXliff(string $xliff): string
    {
        return (string) preg_replace_callback('~<source>(.*?)</source>~s', static fn (array $m) => '<source>' . $m[1] . '</source><target>FR ' . $m[1] . '</target>', $xliff);
    }
}

/** What Translator::importFile() passes as `$open`, for one entry in its target site. */
final class Opened
{
    /** @var list<int> */
    public array $args = [];
    /** @var array<string, string>|null */
    public ?array $written = null;

    /** @param list<array{key: string, text: string, html: bool}> $segments */
    public function __construct(private readonly array $segments)
    {
    }

    /** @return array<string, mixed> */
    public function open(int $entryId, int $sourceSiteId, int $targetSiteId): array
    {
        $this->args = [$entryId, $sourceSiteId, $targetSiteId];

        return ['title' => 'Entry', 'site' => 'Français', 'segments' => $this->segments, 'write' => function (array $texts): void {
            $this->written = $texts;
        }];
    }
}
