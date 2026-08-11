<?php

namespace App\Services\Platforms;

use App\Services\Cache\PlacesClaim;

/**
 * Outcome of GoogleBusinessService::fetchPlaceDetailsRaw(): the RAW Places (New)
 * response, or the reason there isn't one. Exactly one of $place / $failure is
 * non-null. Mirrors ProfileFetchResult, which solved the same problem for
 * Instagram.
 *
 * $deniedBy is set only for BudgetDenied, and only so fetchPlaceDetails() can
 * rebuild the PlacesBudgetExhaustedException its callers still branch on —
 * GoogleBusinessController turns UserCapReached into a 429 and everything else
 * into a quiet degrade.
 */
final readonly class PlaceDetailsResult
{
    /** @param array<string, mixed>|null $place */
    private function __construct(
        public ?array $place,
        public ?PlaceDetailsFailure $failure,
        public ?PlacesClaim $deniedBy = null,
    ) {}

    /** @param array<string, mixed> $place */
    public static function ok(array $place): self
    {
        return new self($place, null);
    }

    public static function failed(PlaceDetailsFailure $failure): self
    {
        return new self(null, $failure);
    }

    public static function budgetDenied(PlacesClaim $reason): self
    {
        return new self(null, PlaceDetailsFailure::BudgetDenied, $reason);
    }
}
