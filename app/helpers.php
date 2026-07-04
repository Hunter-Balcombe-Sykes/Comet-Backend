<?php

use App\Models\Core\User\User;
use App\Services\FeatureFlags\FeatureFlagService;

if (! function_exists('feature')) {
    /**
     * Check whether a feature flag is enabled for an optional user context.
     * Null context falls back to the flag's default_enabled + rollout_percent.
     */
    function feature(string $key, ?User $user = null): bool
    {
        return app(FeatureFlagService::class)->enabled($key, $user);
    }
}

if (! function_exists('trim_or_null')) {
    /**
     * Trim a value and collapse it to null unless it's a non-empty string.
     * SLOP-1: dedupes the byte-identical trimToNull()/trimOrNull() private
     * methods previously duplicated in SitepageDataResolverService and
     * UserWorkplaceController.
     */
    function trim_or_null(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
