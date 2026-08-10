<?php

namespace App\Services\Platforms\Actors;

use App\Services\Platforms\ProfileFetchFailure;

// One Apify actor's contract. Every actor defines its OWN run input schema and
// its own way of signalling a per-item scrape failure, so the actor id and these
// two methods must move together — that pairing is what
// config('partna.instagram.actor_adapters') encodes.
//
// This is also the seam a non-Apify source (e.g. Meta's official Graph API,
// post-pilot) would implement instead of a scraper.
interface InstagramActorAdapter
{
    /** Run input for a single username, in this actor's own schema. */
    public function input(string $username): array;

    /** Null when $item is a usable profile. */
    public function classify(array $item): ?ProfileFetchFailure;
}
