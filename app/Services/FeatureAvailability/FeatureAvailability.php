<?php

namespace App\Services\FeatureAvailability;

use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\User\User;
use App\Services\Segments\SegmentResolver;
use Illuminate\Support\Facades\Cache;

/**
 * Read side for core.feature_availability — answers "is feature X available to
 * this user right now?".
 *
 * THE PATTERN (for any feature-gated surface):
 *
 *     if (FeatureAvailability::for($user)->allows('integration.instagram')) { ... }
 *
 * Resolution per feature key:
 *   1. no rule rows at all               → AVAILABLE (absence = enabled)
 *   2. segment-scoped rules the user belongs to BEAT the global rule
 *   3. several matching segment rules    → 'disabled' wins (deny wins)
 *   4. otherwise the global rule's mode.
 *
 * Key convention: 'integration.<platform>' (consulted by the /platforms/meta
 * integrations-config endpoint), 'feature.<name>' for other surfaces.
 *
 * The whole rule table is small and staff-curated, so the per-user snapshot is
 * computed in one pass and cached for 60s; staff CRUD calls flush().
 */
final class FeatureAvailability
{
    private const CACHE_TTL_SECONDS = 60;

    private const CACHE_VERSION_KEY = 'feature-availability:version';

    public static function for(User $user): UserFeatureAvailability
    {
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 0);

        $overrides = Cache::remember(
            "feature-availability:user:{$user->id}:v{$version}",
            self::CACHE_TTL_SECONDS,
            fn () => self::resolveOverrides($user),
        );

        return new UserFeatureAvailability($overrides);
    }

    /** Staff CRUD calls this after every write so reads reflect changes immediately. */
    public static function flush(): void
    {
        Cache::increment(self::CACHE_VERSION_KEY);
    }

    /**
     * Compute the user's non-default availability map. Only keys with an
     * applicable rule appear; everything else defaults to available.
     *
     * @return array<string, bool> feature_key => allowed
     */
    private static function resolveOverrides(User $user): array
    {
        try {
            $rules = FeatureAvailabilityRule::query()->get(['feature_key', 'mode', 'segment_id']);
        } catch (\Throwable) {
            // Fail-open to "everything available" — matches the absence-=-enabled
            // contract. Also covers SQLite test mirrors without the table.
            return [];
        }

        if ($rules->isEmpty()) {
            return [];
        }

        // Resolve segment membership only for segments that actually carry rules.
        $segmentIds = $rules->pluck('segment_id')->filter()->unique()->values();
        $memberOf = [];
        if ($segmentIds->isNotEmpty()) {
            $resolver = app(SegmentResolver::class);
            foreach (UserSegment::query()->whereIn('id', $segmentIds)->get() as $segment) {
                if (in_array((string) $user->id, $resolver->userIds($segment), true)) {
                    $memberOf[(string) $segment->id] = true;
                }
            }
        }

        $map = [];
        foreach ($rules->groupBy('feature_key') as $key => $keyRules) {
            $segmentModes = [];
            $globalMode = null;

            foreach ($keyRules as $rule) {
                if ($rule->segment_id === null) {
                    $globalMode = $rule->mode;
                } elseif (isset($memberOf[(string) $rule->segment_id])) {
                    $segmentModes[] = $rule->mode;
                }
            }

            if ($segmentModes !== []) {
                // Segment rules beat global; deny wins across segments.
                $map[(string) $key] = ! in_array(FeatureAvailabilityRule::MODE_DISABLED, $segmentModes, true);
            } elseif ($globalMode !== null) {
                $map[(string) $key] = $globalMode === FeatureAvailabilityRule::MODE_ENABLED;
            }
        }

        return $map;
    }
}
