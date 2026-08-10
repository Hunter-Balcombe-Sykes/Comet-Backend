<?php

namespace App\Services\Platforms\Actors;

use App\Services\Platforms\ProfileFetchFailure;

// figue~instagram-profile-scraper — the previous default, kept so the actor can
// be rolled back via PARTNA_INSTAGRAM_ACTOR without a deploy.
//
// includeRecentPosts is OFF by the actor's own default ("faster, profile data
// only"); without it every scrape returns an empty latestPosts and no media ever
// mirrors (2026-07-23 live incident). Its input schema defines exactly these two
// params — the old resultsLimit key was never one of them.
//
// It reads Instagram's logged-out web endpoint, which Meta broke on 2026-08-10
// for profiles resolving a business-category subvertical, and its per-item
// failure is free prose that is byte-identical for a nonexistent handle and a
// blocked one (verified 2026-08-10). It therefore can never report
// ProfileNotFound — everything it fails on is treated as retryable.
class FigueProfileScraperAdapter implements InstagramActorAdapter
{
    public function input(string $username): array
    {
        return ['profiles' => [$username], 'includeRecentPosts' => true];
    }

    public function classify(array $item): ?ProfileFetchFailure
    {
        return is_string($item['error'] ?? null) ? ProfileFetchFailure::ProfileUnavailable : null;
    }
}
