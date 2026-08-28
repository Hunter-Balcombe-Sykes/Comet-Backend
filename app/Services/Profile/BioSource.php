<?php

namespace App\Services\Profile;

/**
 * The source-agnostic input to one BioIntelligence pass. Instagram fills it from
 * the scraped profile node; Google Business from the Places listing. Deliberately
 * the SAME four fields BioIntelligence::analyse() already takes — a new source
 * maps onto this shape rather than widening the model's signature.
 */
final readonly class BioSource
{
    public function __construct(
        public string $handle,
        public ?string $fullName = null,
        public ?string $biography = null,
        public ?string $businessCategory = null,
    ) {}

    /** No biography means nothing to analyse — the caller must not spend a call. */
    public function hasBiography(): bool
    {
        return $this->biography !== null && trim($this->biography) !== '';
    }
}
