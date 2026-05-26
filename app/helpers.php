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
