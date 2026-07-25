<?php

namespace App\Services\FeatureFlags;

use App\Models\Core\FeatureFlagOverride;

/**
 * Return value of FeatureFlagService::setOverride() — carries the upserted
 * row itself (see the docblock on setOverride() for why) plus whether the
 * follow-up cache invalidation succeeded.
 */
final class OverrideResult
{
    public function __construct(
        public readonly FeatureFlagOverride $override,
        public readonly bool $cacheInvalidated,
    ) {}
}
