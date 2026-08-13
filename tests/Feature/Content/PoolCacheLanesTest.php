<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// A pool mutation has to move THREE things or the owner's drag doesn't show:
// the document build state, site.sites.updated_at (the public payload cache
// key is derived from it), and the CDN in front of the rendered page.
//
// Lane 2 was missing until 2026-08-14 — a reorder bumped the build state and
// purged the edge while the ORIGIN kept serving the old order from its own
// cache until the TTL ran out, so the drag looked like it hadn't taken for up
// to a minute. Route-level on purpose: a bare Request skips the middleware and
// site resolution that poolChanged() depends on.

beforeEach(function () {
    setupContentCurationTables();
    Queue::fake();
});

it('a pool reorder fires all three cache lanes', function () {
    $user = createTenant('poollanes');
    $siteId = (string) $user->site->id;
    $a = seedContentItem($user->id);
    $b = seedContentItem($user->id);

    // Laravel binds timestamps at SECOND precision, so a same-second write
    // would let this pass or fail on wall-clock luck. Backdate first.
    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)
        ->update(['updated_at' => now()->subMinute()]);
    $before = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');
    $revisionBefore = (int) DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision');

    actingAsUser($user)
        ->putJson('/api/content/pools/watch/order', ['itemIds' => [$b, $a]])
        ->assertOk();

    // Lane 1 — the document build state.
    expect((int) DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision'))->toBeGreaterThan($revisionBefore);

    // Lane 2 — the public payload cache key. This is the one that regressed.
    expect(DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at'))
        ->not->toBe($before);

    // Lane 3 — the edge.
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});
