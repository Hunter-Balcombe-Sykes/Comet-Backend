<?php

namespace App\Services\Http;

/** Structured metadata extracted from an HTML page by {@see MetadataParser}. */
final class ParsedMetadata
{
    /**
     * @param  array<string,string>  $og  property => content (og:* / product:* / music:*)
     * @param  array<string,string>  $meta  name => content
     * @param  array<int,mixed>  $jsonLd  decoded JSON-LD blocks
     */
    public function __construct(
        public array $og = [],
        public array $meta = [],
        public ?string $title = null,
        public array $jsonLd = [],
        public ?string $faviconUrl = null,
        public ?string $appleTouchIconUrl = null,
        public ?string $headerLogoUrl = null,
        public bool $isShopify = false,
    ) {}

    public function og(string $key): ?string
    {
        $v = $this->og[$key] ?? null;

        return ($v !== null && trim($v) !== '') ? trim($v) : null;
    }

    /**
     * First JSON-LD object whose @type matches (case-insensitive), walking
     *
     * @graph arrays and top-level arrays. Returns the matched node or null.
     *
     * @return array<string,mixed>|null
     */
    public function jsonLdOfType(string $type): ?array
    {
        $type = strtolower($type);
        foreach ($this->jsonLd as $block) {
            $found = $this->searchType($block, $type);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function searchType(mixed $node, string $type): ?array
    {
        if (! is_array($node)) {
            return null;
        }
        // @graph or plain list of nodes
        if (isset($node['@graph']) && is_array($node['@graph'])) {
            foreach ($node['@graph'] as $child) {
                $found = $this->searchType($child, $type);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        if (array_is_list($node)) {
            foreach ($node as $child) {
                $found = $this->searchType($child, $type);
                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        }
        $nodeType = $node['@type'] ?? null;
        $types = is_array($nodeType) ? $nodeType : [$nodeType];
        foreach ($types as $t) {
            if (is_string($t) && strtolower($t) === $type) {
                return $node;
            }
        }

        return null;
    }
}
