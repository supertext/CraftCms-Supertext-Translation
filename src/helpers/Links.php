<?php
/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace supertext\crafttranslation\helpers;

/**
 * Links in a translation that point to the source site's own pages, pointed to the same page in
 * the target site. No Craft classes (unit-tested on its own; the lookups are passed in).
 *
 * - Reference tags for the source site, as CKEditor's and Link fields' entry links store them:
 *   `{entry:12@1:url||https://example.com/en/about}` → `{entry:12@2:url||https://example.com/de/ueber-uns}`
 *   (also `{category:…}`; the fallback URL, if any, becomes the target's).
 * - Absolute and root-relative addresses of the source site's pages (`https://example.com/en/about`,
 *   `/en/about#team`) → a reference tag for the same page in the target site, with its current
 *   address as fallback (`{entry:12@2:url||https://example.com/de/ueber-uns}#team`), so the link
 *   still works when that page's address is translated later. Query and anchor are kept.
 *
 * Links to other sites, to other websites, and to pages that don't exist in the target site stay
 * as they are. Tags without a site (`{entry:12:url}`) already resolve in the site being shown.
 */
final class Links
{
    private const REF = '/\{(entry|category):(\d+)@(\d+)(:[^}|]*)?(?:\|\|([^}]*))?\}/';
    private const HREF = '/(\shref\s*=\s*)(["\'])([^"\']*)\2/i';

    /**
     * @param callable(string, int, int): ?string $urlOf The URL of an element (type `entry`|`category`, id) in a site ('' if it has none); null if it isn't in that site.
     * @param callable(string): ?array{0: string, 1: int} $elementAt The source site's element at an address (type, id), or null.
     */
    public static function localize(string $text, int $sourceSiteId, int $targetSiteId, callable $urlOf, callable $elementAt): string
    {
        if ($sourceSiteId === $targetSiteId) {
            return $text;
        }
        // Reference tags (anywhere: href attributes, a Link field's value).
        $text = (string) preg_replace_callback(self::REF, static function (array $m) use ($sourceSiteId, $targetSiteId, $urlOf): string {
            if ((int) $m[3] !== $sourceSiteId) {
                return $m[0];
            }
            $url = $urlOf($m[1], (int) $m[2], $targetSiteId);
            if ($url === null) {
                return $m[0];
            }
            $fallback = isset($m[5]) && $m[5] !== '' ? '||' . ($url !== '' ? $url : $m[5]) : '';

            return '{' . $m[1] . ':' . $m[2] . '@' . $targetSiteId . ($m[4] ?? '') . $fallback . '}';
        }, $text);

        // Plain addresses of the source site's pages.
        return (string) preg_replace_callback(self::HREF, static function (array $m) use ($targetSiteId, $urlOf, $elementAt): string {
            $href = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5);
            if ($href === '' || str_starts_with($href, '{') || str_starts_with($href, '#') || preg_match('/^(mailto|tel|javascript):/i', $href)) {
                return $m[0];
            }
            $suffix = '';
            if (preg_match('/^([^?#]*)([?#].*)$/', $href, $parts)) {
                [$href, $suffix] = [$parts[1], $parts[2]];
            }
            $element = $elementAt($href);
            if ($element === null) {
                return $m[0];
            }
            $url = $urlOf($element[0], $element[1], $targetSiteId);
            if ($url === null || $url === '') {
                return $m[0];
            }

            $tag = '{' . $element[0] . ':' . $element[1] . '@' . $targetSiteId . ':url||' . $url . '}';

            return $m[1] . $m[2] . htmlspecialchars($tag . $suffix, ENT_QUOTES | ENT_HTML5, 'UTF-8', false) . $m[2];
        }, $text);
    }
}
