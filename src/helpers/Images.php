<?php
/**
 * @package     Supertext Translation for Craft CMS
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

declare(strict_types=1);

namespace supertext\crafttranslation\helpers;

/**
 * Images in translated HTML (CKEditor, Redactor): keeps a site's own (localized) images when a
 * translation is overwritten. No Craft classes (unit-tested on its own).
 *
 * An image's *slot* is its place in the field: the n-th `<img>` of the field's HTML. When the
 * plugin writes a translation it records, per slot, the source's image (`originalSrc`) and the
 * target's (`src`). On the next overwrite, per slot:
 *
 * - source image unchanged → the target keeps the image it has now (its own, or the source's);
 * - source image replaced, the target still had the old one → the target gets the new one;
 * - source image replaced, the target had its own → it gets the new one, marked `check`;
 * - no record (translated before this existed) and the target's image differs → its own is kept,
 *   marked `check`;
 * - the target's images can't be lined up with the record (images added or removed by hand) →
 *   the source's images; those that change are marked `check`.
 *
 * Images are compared by their address without the fallback URL of a reference tag:
 * `{asset:12:url||https://…/a.png}` is `{asset:12:url}`.
 *
 * @phpstan-type Record array{slot: int, originalSrc: string, src: string, status: string, reason?: string}
 */
final class Images
{
    public const SAME = 'same';
    public const OWN = 'own';
    public const CHECK = 'check';

    public const ORIGINAL_CHANGED = 'originalChanged';
    public const NO_RECORD = 'noRecord';
    public const LAYOUT_CHANGED = 'layoutChanged';

    private const IMG = '/<img\b[^>]*>/i';

    /**
     * The `<img>` tags of the HTML, in order.
     *
     * @return list<array{tag: string, src: string}>
     */
    public static function tags(string $html): array
    {
        preg_match_all(self::IMG, $html, $matches);
        $tags = [];
        foreach ($matches[0] as $tag) {
            $tags[] = ['tag' => $tag, 'src' => self::attribute($tag, 'src') ?? ''];
        }

        return $tags;
    }

    /** An image address without a reference tag's fallback URL, for comparing. */
    public static function identity(string $src): string
    {
        return (string) preg_replace('/^\{([^}|]*)\|\|[^}]*\}$/', '{$1}', trim($src));
    }

    /**
     * The record of a field written with `$html` where every image is the source's.
     *
     * @return list<Record>
     */
    public static function record(string $html): array
    {
        $records = [];
        foreach (self::tags($html) as $n => $tag) {
            $records[] = ['slot' => $n, 'originalSrc' => $tag['src'], 'src' => $tag['src'], 'status' => self::SAME];
        }

        return $records;
    }

    /**
     * Merges the images of an overwrite: `$html` (the translation, with the source's images) gets
     * the target's own images where the record says so. `$current` is the target field's HTML now,
     * `$written` the record of the last translation (null: none).
     *
     * @param list<Record>|null $written
     * @return array{html: string, records: list<Record>}
     */
    public static function merge(string $html, string $current, ?array $written): array
    {
        $next = self::tags($html);
        $now = self::tags($current);
        $before = [];
        foreach ($written ?? [] as $record) {
            $before[(int) $record['slot']] = $record;
        }
        $sameLayout = $written === null ? \count($next) === \count($now) : \count($before) === \count($now);

        $replace = [];
        $records = [];
        foreach ($next as $n => $slot) {
            $original = $slot['src'];
            $own = $now[$n] ?? null;
            $record = ['slot' => $n, 'originalSrc' => $original, 'src' => $original, 'status' => self::SAME];
            $differs = $own !== null && self::identity($own['src']) !== self::identity($original);
            if (!$sameLayout) {
                if ($differs) {
                    $record['status'] = self::CHECK;
                    $record['reason'] = self::LAYOUT_CHANGED;
                }
            } elseif ($written === null) {
                if ($differs) {
                    $replace[$n] = self::keep((string) $own['tag'], $slot['tag']);
                    $record = ['slot' => $n, 'originalSrc' => $original, 'src' => $own['src'], 'status' => self::CHECK, 'reason' => self::NO_RECORD];
                }
            } else {
                $last = $before[$n] ?? null;
                if ($last === null) {
                    // An image added to the source: the target gets it.
                } elseif (self::identity($last['originalSrc']) === self::identity($original)) {
                    if ($last['status'] === self::CHECK && $own !== null && self::identity($own['src']) === self::identity($last['src'])) {
                        // Still to check and unchanged since: it stays marked.
                        if ($differs) {
                            $replace[$n] = self::keep($own['tag'], $slot['tag']);
                        }
                        $record = ['slot' => $n, 'originalSrc' => $original, 'src' => $own['src'], 'status' => self::CHECK] + (isset($last['reason']) ? ['reason' => $last['reason']] : []);
                    } elseif ($differs) {
                        $replace[$n] = self::keep((string) $own['tag'], $slot['tag']);
                        $record = ['slot' => $n, 'originalSrc' => $original, 'src' => $own['src'], 'status' => self::OWN];
                    }
                } elseif ($differs && self::identity($own['src']) !== self::identity($last['originalSrc'])) {
                    // The source's image was replaced and the target had its own: the new one, to check.
                    $record['status'] = self::CHECK;
                    $record['reason'] = self::ORIGINAL_CHANGED;
                }
            }
            $records[] = $record;
        }

        if ($replace !== []) {
            $i = 0;
            $html = (string) preg_replace_callback(self::IMG, static function (array $m) use (&$i, $replace): string {
                $n = $i++;

                return $replace[$n] ?? $m[0];
            }, $html);
        }

        return ['html' => $html, 'records' => $records];
    }

    /** The target's own tag (address, size, classes) with the alternative text just translated. */
    private static function keep(string $own, string $translated): string
    {
        $alt = self::attribute($translated, 'alt');

        return $alt === null ? $own : self::withAttribute($own, 'alt', $alt);
    }

    private static function attribute(string $tag, string $name): ?string
    {
        if (!preg_match('/\s' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $m)) {
            return null;
        }

        return ($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? '');
    }

    private static function withAttribute(string $tag, string $name, string $value): string
    {
        $pattern = '/\s' . preg_quote($name, '/') . '\s*=\s*("[^"]*"|\'[^\']*\')/i';
        $quoted = ' ' . $name . '="' . str_replace('"', '&quot;', $value) . '"';
        if (preg_match($pattern, $tag)) {
            return (string) preg_replace($pattern, $quoted, $tag, 1);
        }

        return (string) preg_replace('/^<img\b/i', '<img' . $quoted, $tag, 1);
    }

    /** The image at a slot of the HTML (for "Mark as checked"). */
    public static function at(string $html, int $slot): ?string
    {
        return self::tags($html)[$slot]['src'] ?? null;
    }
}
