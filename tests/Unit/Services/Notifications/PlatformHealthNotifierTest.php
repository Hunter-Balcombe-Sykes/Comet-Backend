<?php

/** @phpstan-ignore-all */

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Notifications\Dispatchers\PlatformHealthNotifier;
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

function unsavedConnection(int $failures): IntegrationConnection
{
    // Unsaved model — the notifier only reads attributes, so we skip persistence
    // (and its platform-registry saving guard) entirely.
    $conn = new IntegrationConnection([
        'user_id' => 'pro-1',
        'platform' => 'instagram',
        'consecutive_failures' => $failures,
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

it('publishes a non-critical content_scrape warning on menu scrape failure', function () {
    app(PlatformHealthNotifier::class)->menuScrapeFailed('pro-1');

    $row = DB::table('notifications.notifications')->where('category', 'content_scrape')->first();

    expect($row)->not->toBeNull();
    expect((int) $row->critical)->toBe(0);         // transient → in-app only
    expect($row->ends_at)->not->toBeNull();        // auto-expires
});
