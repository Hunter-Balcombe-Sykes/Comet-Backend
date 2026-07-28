<?php

namespace App\Ingest\Support;

/**
 * Pure HTML-extraction helpers shared by the scraping connectors (plan §4
 * sanctions shared static parsers — the one thing connectors may share).
 * Ported from the legacy PlatformScraper base so its production-proven
 * patterns survive the P8 deletion of app/Services/Platforms.
 */
final class Html
{
    /** <meta property="og:X" content="..."> — property/content in either order. */
    public static function metaContent(string $html, string $property): ?string
    {
        $p = preg_quote($property, '~');
        if (preg_match('~<meta[^>]+property=["\']'.$p.'["\'][^>]+content=["\']([^"\']+)["\']~i', $html, $m)
            || preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']'.$p.'["\']~i', $html, $m)) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
        }

        return null;
    }

    /**
     * Flatten every <script type="application/ld+json"> block (expanding
     *
     * @graph and top-level arrays) into one list of nodes.
     *
     * @return list<array<string, mixed>>
     */
    public static function jsonLdNodes(string $html): array
    {
        $nodes = [];
        if (preg_match_all('~<script type="application/ld\+json"[^>]*>(.+?)</script>~s', $html, $m)) {
            foreach ($m[1] as $block) {
                $data = json_decode(trim($block), true);
                if (! is_array($data)) {
                    continue;
                }
                if (isset($data['@graph']) && is_array($data['@graph'])) {
                    $nodes = array_merge($nodes, array_values(array_filter($data['@graph'], 'is_array')));
                } elseif (array_is_list($data)) {
                    $nodes = array_merge($nodes, array_values(array_filter($data, 'is_array')));
                } else {
                    $nodes[] = $data;
                }
            }
        }

        return $nodes;
    }

    /**
     * Description HTML → bounded plain text: whitespace at block boundaries
     * (strip_tags alone glues "<p>a</p><p>b</p>" into "ab"), tags stripped,
     * entities decoded, whitespace collapsed, capped. Null for blank input.
     */
    public static function plainText(mixed $html, int $limit = 2000): ?string
    {
        if (! is_string($html) || trim($html) === '') {
            return null;
        }

        // preg_replace returns null on a PCRE engine error — degrade to "no
        // boundary spaces" rather than a silently-null description.
        $spaced = preg_replace(
            '~</?(?:p|div|li|ul|ol|h[1-6]|br|hr|tr|td|th|table|thead|tbody|blockquote)(?:\s[^>]*)?/?>~i',
            ' ',
            $html
        ) ?? $html;

        $text = trim((string) preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5)));
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) : $text;
    }
}
