<?php

use App\Site\Pools\PoolRegistry;

// The registry is a closed map reached from a URL segment. These pin the
// three properties the rest of the pool lane assumes and never re-checks.

it('assigns every kind to at most one pool', function () {
    $seen = [];
    foreach (PoolRegistry::POOLS as $pool => $kinds) {
        foreach ($kinds as $kind) {
            // Message built lazily — an eager "{$seen[$kind]}" would read an
            // unset key on every passing iteration.
            if (isset($seen[$kind])) {
                throw new RuntimeException(
                    "kind '{$kind}' is claimed by both '{$seen[$kind]}' and '{$pool}' — "
                    .'an item in two pools is curated twice and excluded once',
                );
            }
            $seen[$kind] = $pool;
        }
    }
    expect($seen)->not->toBeEmpty();
});

it('gives every pool a page key and a label', function () {
    foreach (array_keys(PoolRegistry::POOLS) as $pool) {
        expect(PoolRegistry::PAGE_KEYS)->toHaveKey($pool);
        expect(PoolRegistry::PAGE_LABELS)->toHaveKey($pool);
    }
});

it('names only real pools in the latest-tag list', function () {
    foreach (PoolRegistry::LATEST_TAG_POOLS as $pool) {
        expect(PoolRegistry::isPool($pool))->toBeTrue();
    }
});

// Slice 2 deliberately leaves `channel` and `article` poolless. Both are
// live kinds with rows in content.items, so "no pool" must be a decision on
// the record rather than an oversight the next reader silently corrects.
//
//  channel — 7 rows: Twitch 3, Spotify 3, SoundCloud 1. NOT architecturally
//    blocked: two pools may share a page key, so a 'channels' pool pinned to
//    an existing page would provision fine. The objection is product — those
//    platforms own TWO different pages (Watch and Listen), so one pool mixes
//    them; and a channel card is a profile, not content. Owner's call, unmade.
//  article — 1 row (Substack). Unblocked technically; needs a Writing page,
//    which is a new SitepageId case in LOCKSTEP with the frontend taxonomy.
//    Owner declined 2026-08-11.
//
// Delete the relevant line here when you build the pool — the failure is the
// reminder to also update the deferral note in the slice-2 plan.
it('keeps the deferred kinds out of every pool', function (string $kind) {
    expect(PoolRegistry::poolForKind($kind))->toBeNull();
})->with(['channel', 'article']);
