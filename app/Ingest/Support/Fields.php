<?php

namespace App\Ingest\Support;

/**
 * Shared "first present value" lookup for connector payloads whose vendor
 * keys drift across actor versions and runs. Consolidates four near-duplicate
 * private `firstString` copies (Doordash/Square/UberEats menu connectors,
 * Instagram) that had quietly drifted apart — two never gained the numeric
 * fallback below.
 *
 * Numeric fallback stringifies int/float values a string-only check would
 * silently drop (e.g. a vendor item id that arrives as `4021` rather than
 * `"4021"`) — the string branch always wins for actual strings, so this only
 * fires for real ints/floats.
 */
final class Fields
{
    /**
     * First non-empty value across $paths, string-coerced.
     *
     * Uses data_get(), the Instagram copy's semantic — a strict superset of
     * flat `$item[$key] ?? null`, which is all the menu connectors ever
     * needed. NOTE: a key containing `.` or `*` is a data_get PATH
     * (traversal), not a literal key — none of today's call sites use such
     * a key, but a future one that does would silently start traversing.
     *
     * @param  array<string, mixed>  $item
     * @param  list<string>  $paths
     */
    public static function firstString(array $item, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($item, $path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * First numeric value across $paths, int-coerced. Moved from
     * InstagramConnector — its only prior caller — for cohesion with
     * firstString().
     *
     * @param  array<string, mixed>  $item
     * @param  list<string>  $paths
     */
    public static function firstInt(array $item, array $paths): ?int
    {
        foreach ($paths as $path) {
            $value = data_get($item, $path);
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }
}
