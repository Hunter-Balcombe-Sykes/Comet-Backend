<?php

use App\Http\Controllers\Api\User\Notifications\NotificationController;
use App\Models\Core\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupNotificationsTable();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Insert a notification row and return the Eloquent model.
 *
 * @param  array<string, mixed>  $overrides
 */
function createNotification(array $overrides = []): Notification
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('notifications.notifications')->insert(array_merge([
        'id' => $id,
        'user_id' => null,
        'type' => 'info',
        'title' => 'Test Notification',
        'body' => 'Test body',
        'severity' => 'info',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return Notification::query()->findOrFail($id);
}

// ---------------------------------------------------------------------------
// markRead
// ---------------------------------------------------------------------------

it('allows the owner to call markRead on their targeted notification', function () {
    $owner = createTenant('notif-mark-owner');
    $notification = createNotification(['user_id' => $owner->id]);
    $req = tenantRequestAs($owner);

    $response = app(NotificationController::class)->markRead($req, $notification);

    expect($response->getStatusCode())->toBe(200);
});

it('allows any professional to call markRead on a global notification', function () {
    $pro = createTenant('notif-mark-global');
    // Global: user_id is null
    $notification = createNotification(['user_id' => null]);
    $req = tenantRequestAs($pro);

    $response = app(NotificationController::class)->markRead($req, $notification);

    expect($response->getStatusCode())->toBe(200);
});

it('blocks a non-owner from calling markRead on a targeted notification with 404', function () {
    $owner = createTenant('notif-mark-own');
    $intruder = createTenant('notif-mark-intruder');
    $notification = createNotification(['user_id' => $owner->id]);
    $req = tenantRequestAs($intruder);

    try {
        app(NotificationController::class)->markRead($req, $notification);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});

// ---------------------------------------------------------------------------
// dismiss
// ---------------------------------------------------------------------------

it('allows the owner to call dismiss on their targeted notification', function () {
    $owner = createTenant('notif-dismiss-owner');
    $notification = createNotification(['user_id' => $owner->id]);
    $req = tenantRequestAs($owner);

    $response = app(NotificationController::class)->dismiss($req, $notification);

    expect($response->getStatusCode())->toBe(200);
});

it('allows any professional to call dismiss on a global notification', function () {
    $pro = createTenant('notif-dismiss-global');
    $notification = createNotification(['user_id' => null]);
    $req = tenantRequestAs($pro);

    $response = app(NotificationController::class)->dismiss($req, $notification);

    expect($response->getStatusCode())->toBe(200);
});

it('blocks a non-owner from calling dismiss on a targeted notification with 404', function () {
    $owner = createTenant('notif-dismiss-own');
    $intruder = createTenant('notif-dismiss-intruder');
    $notification = createNotification(['user_id' => $owner->id]);
    $req = tenantRequestAs($intruder);

    try {
        app(NotificationController::class)->dismiss($req, $notification);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    }
});
