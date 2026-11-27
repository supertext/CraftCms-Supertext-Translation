<?php

declare(strict_types=1);

namespace supertext\crafttranslation\tests\unit;

use PHPUnit\Framework\TestCase;
use supertext\crafttranslation\helpers\Slugs;

final class SlugsTest extends TestCase
{
    public function testNewOnlyWhileTheTargetHasTheSourcesSlug(): void
    {
        self::assertTrue(Slugs::translate(Slugs::NEW, 'about-us', 'about-us'));
        self::assertTrue(Slugs::translate(Slugs::NEW, 'about-us', ''));
        self::assertFalse(Slugs::translate(Slugs::NEW, 'about-us', 'ueber-uns'));
        self::assertTrue(Slugs::translate('', 'about-us', 'about-us'), 'unknown values behave like the default');
    }

    public function testAlwaysAndNever(): void
    {
        self::assertTrue(Slugs::translate(Slugs::ALWAYS, 'about-us', 'ueber-uns'));
        self::assertFalse(Slugs::translate(Slugs::NEVER, 'about-us', 'about-us'));
    }
}
