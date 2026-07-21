<?php

// PWL-10: CustomLinkSeeder::seed() writes an IntegrationConnection('custom')
// row with no lock, racing CustomLinksController::addLink() (which locks via
// withConnectionLock -> CacheKeyGenerator::platformConnectionLock('custom',
// $userId)). This test proves the seeder now takes the SAME lock key across
// its authoritative read (existing-row check + MAX_LINKS count) + write, and
// backs off (returns null, logs a timeout warning) rather than double-writing
// when a concurrent holder already has the key — mirroring BookingXorLockTest's
// contention-probe pattern.

use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\CustomLinkSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
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
    $user = User::factory()->create(['account_type' => 'business']);
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
        $result = app(CustomLinkSeeder::class)->seed($user, 'https://example.com/thing');
    } finally {
        $held->release();
    }
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeGreaterThanOrEqual(4.5);
    expect($result)->toBeNull();

    $rid = 'link-'.substr(sha1(strtolower('https://example.com/thing')), 0, 16);
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'custom')->where('resource_id', $rid)->exists())->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'platforms.custom_link_seeder.lock_timeout'
            && ($context['user_id'] ?? null) === (string) $user->id);
});

it('an uncontended call creates the row and dispatches EnrichLinkCardJob', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'business']);

    $row = app(CustomLinkSeeder::class)->seed($user, 'https://example.com/thing');

    expect($row)->not->toBeNull();
    expect($row->platform)->toBe('custom');
    Queue::assertPushed(EnrichLinkCardJob::class, 1);
});
