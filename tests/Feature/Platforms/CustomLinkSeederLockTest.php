<?php

// PWL-10: CustomLinkSeeder::seed() once wrote its row with no lock, racing
// CustomLinksController::addLink(). This proves the seeder takes the SAME lock
// key across its authoritative read + write and backs off (returns null, logs a
// timeout warning) rather than double-writing when a concurrent holder has the
// key — mirroring BookingXorLockTest's contention-probe pattern.
//
// Convergence Phase 6 moved the write onto the custom_links POOL. The lock did
// NOT go with it: the pool write is an idempotent upsert on a deterministic
// coord and needs no serialising, but the 20-link CAP is still a read-then-write
// and two concurrent seeds could both observe 19. Same key, same contract.

use App\Jobs\Content\EnrichPoolLinkJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\LinkPoolReader;
use App\Services\Platforms\CustomLinkSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

// A pool item needs a section, which hangs off the site — so unlike the
// connection lane, a siteless user has nowhere to put a link and short-circuits
// before the lock is ever taken. Every case here therefore needs a real site.
function seederLockUser(): User
{
    $user = User::factory()->create(['account_type' => 'business']);
    $site = new Site(['subdomain' => 'lock'.substr((string) $user->id, 0, 8), 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
}

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    // Without this, FeatureAvailability::allows() queries a nonexistent table,
    // fails open, and logs its own unrelated
    // 'feature_availability.resolve_overrides_failed' warning — which pollutes
    // the Log::spy() assertions below (an empty table = "no overrides", same
    // effective allow-by-default behaviour, just without the warning).
    setupFeatureAvailabilityTable();
});

it('a concurrent holder of the custom-link lock makes seed() skip (not double-write), return null, and log a timeout warning', function () {
    Log::spy();
    // Queue::fake() so, if the write DOES go through (pre-fix), the
    // dispatched EnrichLinkCardJob::dispatch(...)->afterCommit() does not
    // actually run inline (QUEUE_CONNECTION=sync + no open transaction means
    // afterCommit fires synchronously in this suite) and independently block
    // on the SAME lock key for its own re-read+write — which would produce a
    // coincidental ~5s delay and a DIFFERENT log message
    // ('platforms.enrich_link_card.lock_timeout'), masking whether seed()
    // itself is the thing taking the lock.
    Queue::fake();
    $user = seederLockUser();
    // Hard-coded string (not CacheKeyGenerator) — matches
    // BookingXorLockTest's precedent of proving the exact key independently
    // of the production code that derives it.
    $key = "platforms:custom:lock:{$user->id}";

    // Pre-acquire and DELIBERATELY hold — simulates CustomLinksController::addLink()
    // already mid check-then-write for this user.
    $held = Cache::lock($key, 10);
    expect($held->get())->toBeTrue();

    $start = microtime(true);
    try {
        // Blocks up to 5s (Cache::lock(...)->block(5, ...)) before giving up —
        // this test is slow by design, not flaky; do not shorten the wait.
        $result = app(CustomLinkSeeder::class)->seedCustom($user, 'https://example.com/thing');
    } finally {
        $held->release();
    }
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeGreaterThanOrEqual(4.5);
    expect($result)->toBeNull();

    // Nothing written — the whole point of backing off rather than double-writing.
    expect(app(LinkPoolReader::class)->cards($user->refresh()))->toBe([]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'platforms.custom_link_seeder.lock_timeout'
            && ($context['user_id'] ?? null) === (string) $user->id);
});

it('an uncontended call creates the pool item and dispatches the enrichment', function () {
    Queue::fake();
    $user = seederLockUser();

    // seedCustom() returns null on every path now: there is no connection row to
    // hand back, and every caller already discarded the return value (Issue F).
    app(CustomLinkSeeder::class)->seedCustom($user, 'https://example.com/thing');

    $cards = app(LinkPoolReader::class)->cards($user->refresh());
    expect($cards)->toHaveCount(1)
        ->and($cards[0]['url'])->toBe('https://example.com/thing');
    Queue::assertPushed(EnrichPoolLinkJob::class, 1);
});
