<?php

/** @phpstan-ignore-all */

// OV-H wiring: a real platform refresh that fails and trips the circuit breaker must
// publish the critical `platform_connection` notification via PlatformRefresher::recordFailure().

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\PlatformRefresher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();          // site.platform_connections
    setupContactInboxSchema();  // notifications.notifications (with critical + dedupe_key)
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq
         ON notifications (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL'
    );
});

function cbUser(): User
{
    return User::create([
        'handle' => 'cb', 'handle_lc' => 'cb', 'display_name' => 'CB',
        'account_type' => 'individual', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'cb@example.com',
    ]);
}

it('publishes a critical platform_connection notification when a refresh trips the breaker', function () {
    Exceptions::fake();
    Config::set('partna.refresh.max_consecutive_failures', 1); // one failure trips it

    $user = cbUser();
    // YouTube payload missing the required `handle` → YoutubeFetch throws FetchShapeException
    // → recordFailure() increments to 1 (== max) → the notifier fires.
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'youtube',
        'payload' => ['name' => 'no handle here'],
    ]);

    app(PlatformRefresher::class)->refresh($conn->refresh());

    $row = DB::table('notifications.notifications')
        ->where('user_id', $user->id)
        ->where('category', 'platform_connection')
        ->first();

    expect($row)->not->toBeNull();
    expect((int) $row->critical)->toBe(1);
    expect($row->title)->toContain('Youtube');
});

it('does NOT notify while the connection is still below the failure threshold', function () {
    Exceptions::fake();
    Config::set('partna.refresh.max_consecutive_failures', 5); // one failure stays well under

    $user = cbUser();
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'youtube',
        'payload' => ['name' => 'no handle here'],
    ]);

    app(PlatformRefresher::class)->refresh($conn->refresh());

    expect(
        DB::table('notifications.notifications')->where('category', 'platform_connection')->count()
    )->toBe(0);
});
