<?php

namespace App\Services\SmartLinks;

/**
 * A normalized URL split into the pieces the resolver needs (spec §4).
 *
 *   canonical      — scheme://host/path + essential query (e.g. Apple's `?i=`),
 *                    with `variant` + known tracking params removed. Used for
 *                    scraping + dedup.
 *   trackingQuery  — the extracted affiliate/referral params (verbatim), or
 *                    null. Re-attached to the visitor URL so attribution
 *                    survives the click.
 */
final readonly class ParsedUrl
{
    /**
     * @param  array<string,string>  $essentialQuery  query params kept on canonical
     */
    public function __construct(
        public string $original,
        public string $canonical,
        public ?string $trackingQuery,
        public string $scheme,
        public string $host,
        public array $pathSegments,
        public array $essentialQuery,
    ) {}

    /** First path segment, or '' (e.g. 'products' in /products/handle). */
    public function segment(int $index): string
    {
        return $this->pathSegments[$index] ?? '';
    }

    public function lastSegment(): string
    {
        return $this->pathSegments === [] ? '' : (string) end($this->pathSegments);
    }
}
