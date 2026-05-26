<?php

use App\Http\Controllers\Api\User\Notifications\NotificationController;
use App\Services\Cache\CacheLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Verifies that the /me/notifications index response includes a `type` field on
// every notification item — the frontend renderer switches on this value.

beforeEach(function () {
    tenantHelpersEnsureTables();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        type TEXT NULL,
        category TEXT NULL,
        title TEXT NULL,
        body TEXT NULL,
        cta_url TEXT NULL,
        primary_action_label TEXT NULL,
        secondary_action_label TEXT NULL,
        secondary_action_url TEXT NULL,
        severity TEXT NULL,
        starts_at TEXT NULL,
        ends_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.notification_receipts (
        id TEXT PRIMARY KEY,
        notification_id TEXT NULL,
        user_id TEXT NULL,
        read_at TEXT NULL,
        dismissed_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        UNIQUE (notification_id, user_id)
    )');
});

it('GET /me/notifications exposes the type field on each notification item', function () {
    $pro = createBrandTenant('brand-type-test');

    DB::table('notifications.notifications')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'type' => 'Warning',
        'title' => 'Payout action required',
        'body' => 'Your payout needs attention.',
        'severity' => 'warning',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $controller = app(NotificationController::class);
    $request = tenantRequestAs($pro);

    $response = $controller->index($request);
    $data = json_decode($response->getContent(), true);

    // success() returns the payload directly — {unread_count, has_more, notifications}
    expect($data['notifications'])->toHaveCount(1);

    $item = $data['notifications'][0];
    expect($item)->toHaveKey('type');
    // normalizeFrontendType maps 'Warning' → 'Warning'
    expect($item['type'])->toBe('Warning');
});

it('GET /me/notifications normalizes missing type to Info', function () {
    $pro = createBrandTenant('brand-type-null');

    DB::table('notifications.notifications')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'type' => null,
        'title' => 'General notice',
        'body' => 'No explicit type set.',
        'severity' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $controller = app(NotificationController::class);
    $response = $controller->index(tenantRequestAs($pro));
    $data = json_decode($response->getContent(), true);

    $item = $data['notifications'][0];
    expect($item)->toHaveKey('type');
    // null type with no severity → 'Info' default
    expect($item['type'])->toBe('Info');
});
