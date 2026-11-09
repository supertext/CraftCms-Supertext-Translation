<?php

namespace supertext\crafttranslation\tests\unit;

use PHPUnit\Framework\TestCase;
use supertext\crafttranslation\api\Languages;
use supertext\crafttranslation\api\SupertextException;

/** Site languages → Supertext codes: the site's Supertext language, else an exact match, else the SDK's default, else unsupported_language. */
final class LanguagesTest extends TestCase
{
    public function testASiteLanguageThatIsASupertextCodeIsUsed(): void
    {
        self::assertSame('de-CH', Languages::target('de-CH'));
        self::assertSame('de-CH', Languages::target('de_ch'));
        self::assertSame('fr-CH', Languages::target('FR-ch'));
        self::assertSame('es', Languages::target('es'));
        self::assertSame('zh-Hant', Languages::target('zh_hant'));
    }

    public function testObviousDefaults(): void
    {
        self::assertSame('de-DE', Languages::target('de'));
        self::assertSame('en-US', Languages::target('en'));
        self::assertSame('fr-FR', Languages::target('fr'));
        self::assertSame('es', Languages::target('es-ES'));
        self::assertSame('zh-Hans', Languages::target('zh-CN'));
        self::assertSame('zh-Hant', Languages::target('zh_TW'));
        self::assertSame('sr-Latn', Languages::target('sr'));
    }

    public function testNoGuessedRegions(): void
    {
        foreach (['en-IE', 'de-LI', 'fr-BE', 'gsw', 'sr-RS', 'xx'] as $code) {
            try {
                Languages::target($code);
                self::fail("$code should need a Supertext language");
            } catch (SupertextException $e) {
                self::assertSame('unsupported_language', $e->reason);
                self::assertSame([$code], $e->args);
            }
            self::assertNull(Languages::targetOrNull($code));
            self::assertNull(Languages::suggest($code));
        }
    }

    public function testTheSitesSupertextLanguageWins(): void
    {
        self::assertSame('de-AT', Languages::target('de', 'de-AT'));
        self::assertSame('fr-FR', Languages::target('fr-CH', ' fr-FR '));
        self::assertSame('de-CH', Languages::target('de-CH', '  '), 'a blank setting means the site language');
        self::assertSame('xx-NEW', Languages::target('xx', 'xx-NEW'), 'a code the list does not know yet is sent as it is');
    }

    public function testSourcesAreBareAndUseTheSameSetting(): void
    {
        self::assertSame('en', Languages::source('en-US'));
        self::assertSame('en', Languages::source('en'));
        self::assertSame('de', Languages::source('de-CH'));
        self::assertSame('en', Languages::source('en', 'en-US'), 'a mapped target is sent in its source form');
        self::assertSame('de', Languages::source('xx', 'de-CH'));
        self::assertSame('', Languages::source(''));
    }

    public function testAnUnknownSourceIsRefused(): void
    {
        $this->expectException(SupertextException::class);
        Languages::source('xx');
    }

    public function testMatchesAndSuggestionsForTheSettingsPage(): void
    {
        self::assertSame('it-CH', Languages::match('it_CH'));
        self::assertNull(Languages::match('de'));
        self::assertSame('it-CH', Languages::suggest('it_CH'));
        self::assertSame('de-DE', Languages::suggest('de'));
        self::assertSame('en-US', Languages::suggest('en'));
        self::assertTrue(Languages::isTarget('de-CH'));
        self::assertFalse(Languages::isTarget('de_ch'), 'the select posts the list spelling');
        self::assertFalse(Languages::isTarget('de'));
    }

    public function testOptionsAreEveryTargetWithItsCode(): void
    {
        $english = Languages::options('en');
        self::assertCount(\count(Languages::targets()), $english);
        $byCode = array_column($english, 'name', 'code');
        self::assertSame('German (Switzerland)', $byCode['de-CH']);
        $names = array_column($english, 'name');
        $sorted = $names;
        sort($sorted);
        self::assertSame($sorted, $names, 'sorted by name');

        if (\extension_loaded('intl')) {
            $german = array_column(Languages::options('de-CH'), 'name', 'code');
            self::assertSame('Deutsch (Schweiz)', $german['de-CH']);
            self::assertSame('Französisch (Frankreich)', $german['fr-FR']);
            self::assertSame($byCode['gsw-u-sd-chzh'], $german['gsw-u-sd-chzh'], 'codes intl does not name keep the English name');
        }
    }

    public function testStoredCodesAreMigratedToTheList(): void
    {
        self::assertSame('', Languages::canonical(' '));
        self::assertSame('de-CH', Languages::canonical('de_ch'));
        self::assertSame('de-DE', Languages::canonical('de'));
        self::assertSame('es', Languages::canonical('es-ES'));
        self::assertNull(Languages::canonical('xx-YY'));

        $result = Languages::migrate([
            'default' => ['code' => 'en-US', 'politeness' => 'default'],
            'de' => ['code' => 'de_ch', 'politeness' => 'more'],
            'es' => ['code' => 'es-ES'],
            'old' => ['code' => 'xx-YY', 'politeness' => 'less'],
            'none' => ['politeness' => 'default'],
        ]);
        self::assertTrue($result['changed']);
        self::assertSame(['old' => 'xx-YY'], $result['dropped']);
        self::assertSame([
            'default' => ['code' => 'en-US', 'politeness' => 'default'],
            'de' => ['code' => 'de-CH', 'politeness' => 'more'],
            'es' => ['code' => 'es'],
            'old' => ['code' => '', 'politeness' => 'less'],
            'none' => ['politeness' => 'default'],
        ], $result['languages']);

        self::assertFalse(Languages::migrate(['a' => ['code' => 'fr-CH']])['changed']);
    }

    public function testKnowsWhichTargetsHaveAFormOfAddress(): void
    {
        self::assertTrue(Languages::supportsPoliteness('de-CH'));
        self::assertFalse(Languages::supportsPoliteness('en-US'));
        self::assertNull(Languages::supportsPoliteness('xx'));
    }
}
