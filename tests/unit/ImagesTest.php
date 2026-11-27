<?php

namespace supertext\crafttranslation\tests\unit;

use PHPUnit\Framework\TestCase;
use supertext\crafttranslation\helpers\Images;

/** Keeping a site's own (localized) images when a translation is overwritten. */
final class ImagesTest extends TestCase
{
    private static function html(string ...$srcs): string
    {
        $html = '';
        foreach ($srcs as $i => $src) {
            $html .= "<p>Text $i</p><figure class=\"image\"><img src=\"$src\" alt=\"Bild $i\" width=\"400\"></figure>";
        }

        return $html;
    }

    public function testFindsImagesAndComparesReferenceTagsWithoutFallback(): void
    {
        $tags = Images::tags(self::html('{asset:5:url||https://x/a.png}', 'https://cdn/b.png'));
        self::assertSame(['{asset:5:url||https://x/a.png}', 'https://cdn/b.png'], array_column($tags, 'src'));
        self::assertSame('{asset:5:url}', Images::identity('{asset:5:url||https://x/a.png}'));
        self::assertSame('https://cdn/b.png', Images::at(self::html('a', 'https://cdn/b.png'), 1));
    }

    public function testKeepsTheTargetsOwnImageWhileTheSourcesDoesNotChange(): void
    {
        $written = Images::record(self::html('{asset:5:url}', '{asset:6:url}'));
        $current = str_replace('alt="Bild 0"', 'alt="altes Bild"', self::html('{asset:15:url}', '{asset:6:url}'));
        $merged = Images::merge(self::html('{asset:5:url}', '{asset:6:url}'), $current, $written);
        self::assertSame(['own', 'same'], array_column($merged['records'], 'status'));
        self::assertStringContainsString('<img src="{asset:15:url}" alt="Bild 0" width="400">', $merged['html']);
        self::assertStringContainsString('{asset:6:url}', $merged['html']);
    }

    public function testTheNewSourceImageSilentlyOrMarkedToCheck(): void
    {
        $written = Images::record(self::html('{asset:5:url}', '{asset:6:url}'));
        $current = self::html('{asset:5:url}', '{asset:16:url}');
        $merged = Images::merge(self::html('{asset:7:url}', '{asset:8:url}'), $current, $written);
        self::assertSame([['same', null], ['check', 'originalChanged']], array_map(fn($r) => [$r['status'], $r['reason'] ?? null], $merged['records']));
        self::assertSame(['{asset:7:url}', '{asset:8:url}'], array_column(Images::tags($merged['html']), 'src'));
    }

    public function testStaysMarkedUntilChecked(): void
    {
        $written = [['slot' => 0, 'originalSrc' => '{asset:8:url}', 'src' => '{asset:8:url}', 'status' => 'check', 'reason' => 'originalChanged']];
        $merged = Images::merge(self::html('{asset:8:url}'), self::html('{asset:8:url}'), $written);
        self::assertSame('check', $merged['records'][0]['status']);
    }

    public function testWithoutARecordKeepsTheTargetsImageMarkedToCheck(): void
    {
        $merged = Images::merge(self::html('{asset:5:url}', '{asset:6:url}'), self::html('{asset:15:url}', '{asset:6:url}'), null);
        self::assertSame([['check', 'noRecord'], ['same', null]], array_map(fn($r) => [$r['status'], $r['reason'] ?? null], $merged['records']));
        self::assertStringContainsString('{asset:15:url}', $merged['html']);
    }

    public function testTakesTheSourcesImagesWhenTheTargetsNoLongerLineUp(): void
    {
        $written = Images::record(self::html('{asset:5:url}'));
        $merged = Images::merge(self::html('{asset:5:url}'), self::html('{asset:15:url}', '{asset:9:url}'), $written);
        self::assertSame([['check', 'layoutChanged']], array_map(fn($r) => [$r['status'], $r['reason'] ?? null], $merged['records']));
        self::assertSame(['{asset:5:url}'], array_column(Images::tags($merged['html']), 'src'));
    }

    public function testAnImageAddedToTheSourceAppears(): void
    {
        $written = Images::record(self::html('{asset:5:url}'));
        $merged = Images::merge(self::html('{asset:5:url}', '{asset:7:url}'), self::html('{asset:15:url}'), $written);
        self::assertSame(['own', 'same'], array_column($merged['records'], 'status'));
        self::assertSame(['{asset:15:url}', '{asset:7:url}'], array_column(Images::tags($merged['html']), 'src'));
    }
}
