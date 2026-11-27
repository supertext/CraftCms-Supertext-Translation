<?php
/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace supertext\crafttranslation\helpers;

/**
 * Whether a translation changes the target site's slug (the page's address), by the setting
 * *Translate page addresses (slugs)*. No Craft classes (unit-tested on its own).
 */
final class Slugs
{
    /** Only while the target site still has the source's slug (its first translation). */
    public const NEW = 'new';
    /** On every translation, also when it overwrites one (the page's address changes). */
    public const ALWAYS = 'always';
    /** Never: every site keeps the slug it has. */
    public const NEVER = 'never';

    public const MODES = [self::NEW, self::ALWAYS, self::NEVER];

    public static function translate(string $mode, string $sourceSlug, string $targetSlug): bool
    {
        return match ($mode) {
            self::ALWAYS => true,
            self::NEVER => false,
            default => $targetSlug === '' || $targetSlug === $sourceSlug || str_starts_with($targetSlug, '__temp_'),
        };
    }
}
