<?php

namespace App\Services\PreAccount;

// Item 1a: everything phase one of a build learns before any identity exists.
// The bundle a generator's prefetch() hands back is the ONLY thing generate()
// may consume in place of re-fetching — payload is the source's own scraped
// truth (IG profile array / GB Place Details), the names are what the handle
// allocation and the provisional user row are seeded from, and extra carries
// source-specific precomputations (Instagram: the gated name trio + the
// serialized BioIntel, so the paid AI pass is never made twice).
final readonly class SourcePrefetch
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public array $payload,
        public ?string $displayName = null,
        public ?string $untrimmedName = null,
        public array $extra = [],
    ) {}
}
