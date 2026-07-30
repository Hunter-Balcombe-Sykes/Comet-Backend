<?php

/** @phpstan-ignore-all */

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Notifications\Dispatchers\PlatformHealthNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupContactInboxSchema();
    // The partial unique index is what makes insertOrIgnore dedupe (prod has it via migration).
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq
         ON notifications (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL'
    );
    Config::set('partna.refresh.max_consecutive_failures', 3);
});

function unsavedConnection(int $failures, ?Carbon $lastRefreshedAt = null): IntegrationConnection
{
    // Unsaved model — the notifier only reads attributes, so we skip persistence
    // (and its platform-registry saving guard) entirely.
    $conn = new IntegrationConnection([
        'user_id' => 'pro-1',
        'platform' => 'instagram',
        'consecutive_failures' => $failures,
        'last_refreshed_at' => $lastRefreshedAt,
    ]);
    $conn->id = 'conn-1';

    return $conn;
}

it('fires a CRITICAL platform_connection warning when the circuit breaker trips', function () {
    app(PlatformHealthNotifier::class)->connectionRefreshFailing(unsavedConnection(3));

    $row = DB::table('notifications.notifications')->where('user_id', 'pro-1')->first();

    expect($row)->not->toBeNull();
    expect($row->category)->toBe('platform_connection');
    expect((int) $row->critical)->toBe(1);         // critical → in-app + email
    expect($row->ends_at)->toBeNull();             // persists until reconnected
    expect($row->title)->toContain('Instagram');   // humanised platform label
});

it('does NOT fire while the connection is still below the failure threshold', function () {
    app(PlatformHealthNotifier::class)->connectionRefreshFailing(unsavedConnection(2));

    expect(DB::table('notifications.notifications')->count())->toBe(0);
});

it('fires the circuit-breaker warning only once (dedupe per connection)', function () {
    $notifier = app(PlatformHealthNotifier::class);
    $notifier->connectionRefreshFailing(unsavedConnection(3));
    $notifier->connectionRefreshFailing(unsavedConnection(4));

    expect(DB::table('notifications.notifications')->where('category', 'platform_connection')->count())->toBe(1);
});

it('still dedupes repeated failures WITHIN a single episode (no notification storm)', function () {
    // Same last_refreshed_at on both calls simulates two failures inside one
    // ongoing episode — recordFailure() never touches last_refreshed_at, so
    // this is exactly what happens on real repeated failures.
    $episodeStart = now()->subDay();
    $notifier = app(PlatformHealthNotifier::class);

    $notifier->connectionRefreshFailing(unsavedConnection(3, $episodeStart));
    $notifier->connectionRefreshFailing(unsavedConnection(5, $episodeStart));

    expect(DB::table('notifications.notifications')->where('category', 'platform_connection')->count())->toBe(1);
});

it('LIFE-9: fires a SECOND critical notification for a genuine new failure episode after recovery', function () {
    $notifier = app(PlatformHealthNotifier::class);

    // Episode 1: breaker trips on a connection that has never successfully
    // refreshed (last_refreshed_at null).
    $notifier->connectionRefreshFailing(unsavedConnection(3));

    $episode1 = DB::table('notifications.notifications')->where('category', 'platform_connection')->get();
    expect($episode1)->toHaveCount(1);

    // Recovery: a successful refresh resets consecutive_failures to 0 and
    // bumps last_refreshed_at (PlatformRefresher::recordNotModified / the
    // refresh strategies' success paths). Recovery itself never calls the
    // notifier, so nothing should be inserted by this step alone.
    $recoveredAt = now();
    expect(DB::table('notifications.notifications')->where('category', 'platform_connection')->count())->toBe(1);

    // Episode 2: the SAME connection fails again after recovering. Against
    // the pre-fix connection-lifetime dedupe key this collapses into the
    // episode-1 row (still count 1) and the user is never told about the new
    // failure — that is the bug this test proves fixed.
    $notifier->connectionRefreshFailing(unsavedConnection(3, $recoveredAt));

    $rows = DB::table('notifications.notifications')->where('category', 'platform_connection')->get();
    expect($rows)->toHaveCount(2);

    // The episode-1 row is left exactly as it was — this fix does not attempt
    // to auto-resolve it (that would require touching the reset call sites in
    // PlatformRefresher / the auto-sync services, out of scope here). It stays
    // critical/permanent until the user dismisses it via the receipts table,
    // which is the existing, intentional design for critical rows.
    expect((int) $episode1->first()->critical)->toBe(1);
    expect($episode1->first()->ends_at)->toBeNull();

    // The new episode-2 row is its own independent critical notification.
    $episode2 = $rows->firstWhere('dedupe_key', '!=', $episode1->first()->dedupe_key);
    expect($episode2)->not->toBeNull();
    expect((int) $episode2->critical)->toBe(1);
    expect($episode2->ends_at)->toBeNull();
});

it('publishes a non-critical content_scrape warning on menu scrape failure', function () {
    app(PlatformHealthNotifier::class)->menuScrapeFailed('pro-1', null);

    $row = DB::table('notifications.notifications')->where('category', 'content_scrape')->first();

    expect($row)->not->toBeNull();
    expect((int) $row->critical)->toBe(0);         // transient → in-app only
    expect($row->ends_at)->not->toBeNull();        // auto-expires
});

it('still dedupes repeated menu-scrape failures WITHIN a single episode', function () {
    // Same last_successful_fetch_at on both calls simulates two terminal failures
    // inside one ongoing episode — only MenuFetchJob's fetch_status='ok' branch
    // advances that column, so this is exactly what real repeated failures look
    // like (failed() only flips fetch_status).
    $episodeStart = now()->subDay();
    $notifier = app(PlatformHealthNotifier::class);

    $notifier->menuScrapeFailed('pro-1', $episodeStart);
    $notifier->menuScrapeFailed('pro-1', $episodeStart);

    expect(DB::table('notifications.notifications')->where('category', 'content_scrape')->count())->toBe(1);
});

it('LIFE-12: fires a SECOND content_scrape notification for a genuine new failure episode after recovery', function () {
    $notifier = app(PlatformHealthNotifier::class);

    // Episode 1: the menu has never successfully fetched (last_successful_fetch_at null).
    $notifier->menuScrapeFailed('pro-1', null);

    $episode1 = DB::table('notifications.notifications')->where('category', 'content_scrape')->get();
    expect($episode1)->toHaveCount(1);

    // Recovery: a successful scrape bumps last_successful_fetch_at
    // (MenuFetchJob::handle, the 'ok' branch — its only writer). Recovery itself
    // never calls the notifier, so nothing should be inserted by this step alone.
    $recoveredAt = now();
    expect(DB::table('notifications.notifications')->where('category', 'content_scrape')->count())->toBe(1);

    // Episode 2: the SAME user's menu fails again after recovering. Against the
    // pre-fix user-lifetime dedupe key this would collapse into the episode-1
    // row (still count 1) and the user is never told about the new failure —
    // that is the bug this test proves fixed.
    $notifier->menuScrapeFailed('pro-1', $recoveredAt);

    $rows = DB::table('notifications.notifications')->where('category', 'content_scrape')->get();
    expect($rows)->toHaveCount(2);

    $episode2 = $rows->firstWhere('dedupe_key', '!=', $episode1->first()->dedupe_key);
    expect($episode2)->not->toBeNull();
    expect((int) $episode2->critical)->toBe(0);
});
