<?php

namespace App\Services\WebsiteScan;

use App\Services\Http\MetadataParser;

/**
 * Extracts a business's own "about" description from an already-fetched
 * homepage — JSON-LD (LocalBusiness/Organization) description first, then
 * <meta name="description"> — reusing MetadataParser rather than a new
 * hand-rolled parser (it already does the DOMXPath JSON-LD/meta-tag walk
 * this pipeline needs).
 */
class AboutTextExtractor
{
    /** Checked in order — the first JSON-LD block of any of these types that carries a description wins. */
    private const JSON_LD_TYPES = ['LocalBusiness', 'Organization', 'WebSite'];

    public function __construct(private MetadataParser $parser) {}

    public function extract(string $html, string $baseUrl): ?string
    {
        $parsed = $this->parser->parse($html, $baseUrl);

        foreach (self::JSON_LD_TYPES as $type) {
            $node = $parsed->jsonLdOfType($type);
            $description = is_string($node['description'] ?? null) ? trim($node['description']) : '';
            if ($description !== '') {
                return $this->decode($description);
            }
        }

        $meta = trim((string) ($parsed->meta['description'] ?? ''));

        return $meta !== '' ? $this->decode($meta) : null;
    }

    // <script> content isn't HTML-entity-decoded by the DOM parser, so a
    // JSON-LD block that (incorrectly) HTML-escaped its own text — real sites
    // do this — comes through json_decode() with literal "&amp;" etc. still in
    // it. Decode defensively; a site with no such bug round-trips unchanged.
    //
    // Issue 16 (Parker, T26 2026-08-28): that same decode RESURRECTS markup a
    // site escaped INTO its description — "&lt;p class=…&gt;" became a live
    // <p> tag served verbatim on the info panel, with an unclosed <strong>
    // where the site had truncated its own string mid-tag. Strip tags after
    // decoding (order matters: decode reveals the tags, strip removes them),
    // decode once more for double-escaped entities, and squish whitespace —
    // a plain-prose description round-trips through all of it unchanged.
    private function decode(string $text): string
    {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $stripped = html_entity_decode(strip_tags($decoded), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\s+/', ' ', $stripped) ?? $stripped);
    }
}
