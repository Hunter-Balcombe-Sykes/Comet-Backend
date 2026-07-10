<?php

namespace App\Services\FeatureAvailability;

/**
 * Per-user availability snapshot built by FeatureAvailability::for(). Holds only
 * the keys with an applicable rule; any other key defaults to available.
 */
final readonly class UserFeatureAvailability
{
    /** @param  array<string, bool>  $overrides  feature_key => allowed */
    public function __construct(private array $overrides) {}

    public function allows(string $featureKey): bool
    {
        return $this->overrides[$featureKey] ?? true;
    }

    /** Non-default entries only — feature_key => allowed. */
    public function overrides(): array
    {
        return $this->overrides;
    }
}
