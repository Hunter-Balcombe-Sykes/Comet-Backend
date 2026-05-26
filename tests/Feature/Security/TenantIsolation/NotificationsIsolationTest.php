<?php

use App\Http\Controllers\Api\User\Notifications\NotificationController;
use App\Models\Core\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

it('notification markRead returns 404 when the notification targets another professional', function () {
    [$a, $b] = createTwoTenants('brand');

    // Targeted notification owned by Brand A.
    $notifId = (string) Str::uuid();
    DB::table('notifications.notifications')->insert([
        'id' => $notifId,
        'user_id' => $a->id,
        'type' => 'Info',
        'title' => 'A private notice',
        'body' => 'Only Brand A should see this',
        'severity' => 'info',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $notification = Notification::query()->findOrFail($notifId);
    $req = tenantRequestAs($b, [], 'POST');

    // NotificationPolicy denies with 404 when user_id does not match the actor.
    expect(fn () => app(NotificationController::class)->markRead($req, $notification))
        ->toThrow(AuthorizationException::class);

    // No receipt row must be written for Brand B against Brand A's notification.
    $receiptCount = DB::table('notifications.notification_receipts')
        ->where('notification_id', $notifId)
        ->where('user_id', $b->id)
        ->count();
    expect($receiptCount)->toBe(0);
});

it('notification dismiss returns 404 when the notification targets another professional', function () {
    [$a, $b] = createTwoTenants('brand');

    $notifId = (string) Str::uuid();
    DB::table('notifications.notifications')->insert([
        'id' => $notifId,
        'user_id' => $a->id,
        'type' => 'Critical',
        'title' => 'A account alert',
        'body' => 'Only visible to A',
        'severity' => 'critical',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $notification = Notification::query()->findOrFail($notifId);
    $req = tenantRequestAs($b, [], 'POST');

    expect(fn () => app(NotificationController::class)->dismiss($req, $notification))
        ->toThrow(AuthorizationException::class);

    $receiptCount = DB::table('notifications.notification_receipts')
        ->where('notification_id', $notifId)
        ->where('user_id', $b->id)
        ->count();
    expect($receiptCount)->toBe(0);
});

it('broadcast notifications (user_id null) are receipted per-caller, not cross-tenant', function () {
    // Broadcast notifications — user_id is null so ALL pros may mark-read.
    // The isolation guarantee here is that each pro's read/dismiss state writes a
    // separate row keyed by user_id, so one pro's action cannot mutate another's state.
    [$a, $b] = createTwoTenants('brand');

    $notifId = (string) Str::uuid();
    DB::table('notifications.notifications')->insert([
        'id' => $notifId,
        'user_id' => null,
        'type' => 'Info',
        'title' => 'System-wide notice',
        'body' => 'Everyone sees this',
        'severity' => 'info',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $notification = Notification::query()->findOrFail($notifId);

    // Brand A marks read.
    $response = app(NotificationController::class)->markRead(tenantRequestAs($a, [], 'POST'), $notification);
    expect($response->getStatusCode())->toBe(200);

    // Exactly one receipt exists — keyed to Brand A only.
    $rows = DB::table('notifications.notification_receipts')
        ->where('notification_id', $notifId)
        ->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->user_id)->toBe($a->id);
    expect($rows[0]->read_at)->not->toBeNull();

    // Brand B's state is untouched — they haven't read it yet (no row).
    $bReceipt = DB::table('notifications.notification_receipts')
        ->where('notification_id', $notifId)
        ->where('user_id', $b->id)
        ->first();
    expect($bReceipt)->toBeNull();
});
