<?php

namespace App\Services\Platforms\Actors;

use App\Services\Platforms\ProfileFetchFailure;

// apify~instagram-profile-scraper. Input key is `usernames`; up to 12 latest
// posts come back automatically — the actor has no flag to request them, so
// there is no includeRecentPosts equivalent to set here.
//
// Unlike figue it reports a machine-readable error code, so a genuinely missing
// handle IS distinguishable from an upstream break (verified live 2026-08-10:
// a nonexistent handle returns error="not_found").
class ApifyProfileScraperAdapter implements InstagramActorAdapter
{
    public function input(string $username): array
    {
        return ['usernames' => [$username]];
    }

    public function classify(array $item): ?ProfileFetchFailure
    {
        $error = $item['error'] ?? null;
        if (! is_string($error)) {
            return null;
        }

        return $error === 'not_found'
            ? ProfileFetchFailure::ProfileNotFound
            : ProfileFetchFailure::ProfileUnavailable;
    }
}
