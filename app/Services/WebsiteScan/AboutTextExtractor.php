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
                return $description;
            }
        }

        $meta = trim((string) ($parsed->meta['description'] ?? ''));

        return $meta !== '' ? $meta : null;
    }
}
