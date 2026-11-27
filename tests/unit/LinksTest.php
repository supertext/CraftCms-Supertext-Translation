<?php

namespace supertext\crafttranslation\tests\unit;

use PHPUnit\Framework\TestCase;
use supertext\crafttranslation\helpers\Links;

/** Links to the source site's pages, pointed to the same page in the target site. */
final class LinksTest extends TestCase
{
    private static function localize(string $text): string
    {
        $urls = [
            'entry:12:2' => 'https://example.com/de/ueber-uns',
            'category:30:2' => 'https://example.com/de/themen/pralinen',
        ];
        $pages = ['https://example.com/about' => ['entry', 12], '/about' => ['entry', 12]];

        return Links::localize(
            $text,
            1,
            2,
            fn(string $type, int $id, int $site) => $urls["$type:$id:$site"] ?? null,
            fn(string $url) => $pages[$url] ?? null,
        );
    }

    public function testReferenceTagsForTheSourceSitePointToTheTargetSite(): void
    {
        self::assertSame(
            '<a href="{entry:12@2:url||https://example.com/de/ueber-uns}">Über uns</a>',
            self::localize('<a href="{entry:12@1:url||https://example.com/about}">Über uns</a>'),
        );
        self::assertSame('{entry:12@2:url}', self::localize('{entry:12@1:url}'));
        self::assertSame('{category:30@2:url}', self::localize('{category:30@1:url}'));
    }

    public function testLeavesOtherSitesMissingPagesAndTagsWithoutASite(): void
    {
        foreach (['{entry:12@3:url}', '{entry:99@1:url}', '{entry:12:url}', '<img src="{asset:5:url}">'] as $text) {
            self::assertSame($text, self::localize($text));
        }
    }

    public function testAddressesOfTheSourceSitesPagesKeepQueryAndAnchor(): void
    {
        // As a reference tag, so the link follows when the page's address is translated later.
        self::assertSame('<a href="{entry:12@2:url||https://example.com/de/ueber-uns}#team">x</a>', self::localize('<a href="https://example.com/about#team">x</a>'));
        self::assertSame('<a href="{entry:12@2:url||https://example.com/de/ueber-uns}?a=1&amp;b=2">x</a>', self::localize('<a href="/about?a=1&amp;b=2">x</a>'));
        foreach (['<a href="https://www.supertext.com">x</a>', '<a href="#top">x</a>', '<a href="mailto:a@b.ch">x</a>'] as $text) {
            self::assertSame($text, self::localize($text));
        }
    }
}
