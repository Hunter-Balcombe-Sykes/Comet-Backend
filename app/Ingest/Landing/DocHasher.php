<?php

namespace App\Ingest\Landing;

/**
 * Content addressing for landed docs. The hash decides whether anything
 * actually changed, so volatile paths (rotating CDN signatures, nonces) MUST
 * be excluded or every run looks like a change and the whole "unchanged
 * content writes nothing" property collapses.
 *
 * The stored doc always keeps the full value — volatility affects the hash
 * INPUT only, never what we retain.
 */
class DocHasher
{
    /**
     * @param  array<string, mixed>  $doc
     * @param  list<string>  $volatile  dot paths, optionally suffixed `?query`
     *                                  to strip only a URL's query string
     */
    public static function hash(array $doc, array $volatile = []): string
    {
        $canonical = self::canonicalise($doc, $volatile);

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<string>  $volatile
     * @return array<string, mixed>
     */
    public static function canonicalise(array $doc, array $volatile): array
    {
        foreach ($volatile as $path) {
            $queryOnly = str_ends_with($path, '?query');
            $target = $queryOnly ? substr($path, 0, -6) : $path;
            $doc = self::applyVolatility($doc, explode('.', $target), $queryOnly);
        }

        return self::sortRecursive($doc);
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  list<string>  $segments
     * @return array<string, mixed>
     */
    private static function applyVolatility(array $doc, array $segments, bool $queryOnly): array
    {
        $head = array_shift($segments);
        if ($head === null || ! array_key_exists($head, $doc)) {
            return $doc;
        }

        // A wildcard segment applies the rule to every element of a list.
        if ($head === '*') {
            return $doc;
        }

        if ($segments === []) {
            if ($queryOnly && is_string($doc[$head])) {
                $doc[$head] = explode('?', $doc[$head])[0];
            } else {
                unset($doc[$head]);
            }

            return $doc;
        }

        if (is_array($doc[$head])) {
            // Descend into a list of objects as well as a single object, so a
            // path like `items.image_url` covers every item.
            if (array_is_list($doc[$head])) {
                foreach ($doc[$head] as $i => $element) {
                    if (is_array($element)) {
                        $doc[$head][$i] = self::applyVolatility($element, $segments, $queryOnly);
                    }
                }
            } else {
                $doc[$head] = self::applyVolatility($doc[$head], $segments, $queryOnly);
            }
        }

        return $doc;
    }

    /**
     * Key order must not affect the hash — vendors reorder JSON freely, and a
     * reordered doc is not a changed doc. List order IS preserved: for a feed,
     * order is content.
     *
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private static function sortRecursive(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::sortRecursive($v);
            }
        }

        return $value;
    }
}
