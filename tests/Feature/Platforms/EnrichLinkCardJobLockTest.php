<?php

// tests/Feature/Platforms/EnrichLinkCardJobLockTest.php
//
// PWL-8: EnrichLinkCardJob's re-read+write half now takes the same per-user/
// platform lock (CacheKeyGenerator::platformConnectionLock) the connect-time
// controller writes hold (CustomLinksController::addLink,
// OnlineOrderingController::addEntry via ManagesIntegrationConnection::
// withConnectionLock) — closing a race where the job's slow-HTTP-then-
// unlocked-update() could clobber a concurrent connect/forget on the same
// row. The slow scraper->snapshot() HTTP call itself stays OUTSIDE the lock
// (the #1 rule: never hold a lock across vendor I/O) — only the re-read +
// write span is covered.
//
// Lock-timeout policy for THIS job (Q5) is log-and-skip, NOT terminal-write:
// the minimal card written synchronously at connect is already an
// acceptable final state, so on contention the job leaves the row's
// pending/last-good state untouched, logs a warning, and does not retry or
// fail the job (mirrors BookingXorLockTest's contention-skip shape, not
// GoogleBusinessEnrichJob::persist()'s release-and-retry shape).

use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\LinkCardScraper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function elcUser(string $h = 'elc'): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('skips the write and logs a lock-timeout warning when the platform-connection lock is held — log-and-skip, not terminal-write', function () {
    Log::spy();
    $user = elcUser('elclock');
    $key = "platforms:custom:lock:{$user->id}"; // hard-coded, matching BookingXorLockTest precedent

    $row = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'custom', 'resource_id' => 'link-abc',
        'payload' => ['kind' => 'link', 'url' => 'https://x.com', 'name' => 'x.com', 'favicon' => 'g', 'logo' => null, 'description' => null],
        'last_refresh_status' => 'pending',
    ]);

    // Bind BEFORE the job runs (IntegrationConnection guard test timing gotcha).
    $this->mock(LinkCardScraper::class, function ($m) {
        $m->shouldReceive('snapshot')->andReturn([
            'url' => 'https://x.com/final', 'name' => 'UPGRADED', 'description' => 'desc',
            'favicon' => 'https://x.com/fav.ico', 'logo' => 'https://x.com/og.png',
        ]);
    });

    // Pre-acquire and DELIBERATELY hold across the whole job run — simulates a
    // concurrent connect/forget mid write. Released only in finally, after the
    // job returns, so the job's own lock attempt must contend the full block(5).
    $held = Cache::lock($key, 10);
    expect($held->get())->toBeTrue();

    $start = microtime(true);
    try {
        // Blocks up to 5s before giving up — this test is slow by design, not
        // flaky; do not "fix" it by shortening the wait.
        (new EnrichLinkCardJob((string) $user->id, 'custom', 'link-abc', 'https://x.com'))
            ->handle(app(LinkCardScraper::class));
    } finally {
        $held->release();
    }
    $elapsed = microtime(true) - $start;

    // Proves the job actually contended for the lock (block(5)) rather than
    // sailing past it unlocked.
    expect($elapsed)->toBeGreaterThanOrEqual(4.5);

    $row->refresh();
    expect($row->payload['name'])->toBe('x.com')           // NOT 'UPGRADED' — write was blocked
        ->and($row->last_refresh_status)->toBe('pending'); // NOT 'ok' — row left untouched

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'platforms.enrich_link_card.lock_timeout'
            && ($context['user_id'] ?? null) === (string) $user->id
            && ($context['platform'] ?? null) === 'custom'
            && ($context['resource_id'] ?? null) === 'link-abc');
});

it('writes the upgraded card normally when the lock is free', function () {
    $user = elcUser('elcfree');
    $row = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'custom', 'resource_id' => 'link-abc',
        'payload' => ['kind' => 'link', 'url' => 'https://x.com', 'name' => 'x.com', 'favicon' => 'g', 'logo' => null, 'description' => null],
        'last_refresh_status' => 'pending',
    ]);

    $this->mock(LinkCardScraper::class, function ($m) {
        $m->shouldReceive('snapshot')->andReturn([
            'url' => 'https://x.com/final', 'name' => 'UPGRADED', 'description' => 'desc',
            'favicon' => 'https://x.com/fav.ico', 'logo' => 'https://x.com/og.png',
        ]);
    });

    (new EnrichLinkCardJob((string) $user->id, 'custom', 'link-abc', 'https://x.com'))
        ->handle(app(LinkCardScraper::class));

    $row->refresh();
    expect($row->last_refresh_status)->toBe('ok')
        ->and($row->payload['name'])->toBe('UPGRADED');
});
