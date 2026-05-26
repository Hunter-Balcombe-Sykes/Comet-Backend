<?php

/** @phpstan-ignore-all */

use App\Http\Controllers\Api\Staff\StaffSite\StaffNotificationController;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\User\User;
use App\Services\Notifications\NotificationListingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();

    $conn = DB::connection('pgsql');

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        type TEXT NOT NULL,
        category TEXT NULL,
        title TEXT NOT NULL,
        body TEXT NOT NULL,
        cta_url TEXT NULL,
        primary_action_label TEXT NULL,
        secondary_action_label TEXT NULL,
        secondary_action_url TEXT NULL,
        severity TEXT NOT NULL DEFAULT "info",
        starts_at TEXT NULL,
        ends_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notification_receipts (
        id TEXT PRIMARY KEY,
        notification_id TEXT NOT NULL,
        user_id TEXT NOT NULL,
        read_at TEXT NULL,
        dismissed_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        UNIQUE(notification_id, user_id)
    )');

    $conn->statement('DELETE FROM notifications.notifications');
    $conn->statement('DELETE FROM notifications.notification_receipts');
});

function staffNotif_makeBrand(): User
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'primary_email' => 'brand@example.test',
        'status' => 'active',
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    return User::query()->where('id', $id)->first();
}

function staffNotif_seedNotificationForPro(string $userId, string $title = 'Stuck banner'): Notification
{
    return Notification::query()->create([
        'user_id' => $userId,
        'type' => 'Info',
        'title' => $title,
        'body' => 'body',
        'severity' => 'info',
    ]);
}

function staffNotif_seedGlobalNotification(string $title = 'Global broadcast'): Notification
{
    return Notification::query()->create([
        'user_id' => null,
        'type' => 'Info',
        'title' => $title,
        'body' => 'body',
        'severity' => 'info',
    ]);
}

it('indexForProfessional returns the same payload shape as the self-service endpoint', function () {
    $pro = staffNotif_makeBrand();
    staffNotif_seedNotificationForPro($pro->id, 'Targeted A');
    staffNotif_seedNotificationForPro($pro->id, 'Targeted B');
    staffNotif_seedGlobalNotification('Global');

    $controller = new StaffNotificationController(app(NotificationListingService::class));
    $response = $controller->indexForProfessional(Request::create('/', 'GET'), $pro);

    expect($response->status())->toBe(200);

    $body = json_decode($response->getContent(), true);
    expect($body)->toHaveKey('unread_count');
    expect($body)->toHaveKey('has_more');
    expect($body)->toHaveKey('notifications');
    expect(count($body['notifications']))->toBe(3);
    expect($body['unread_count'])->toBe(3);
});

it('markReadForProfessional writes a receipt and the next index call no longer flags it unread', function () {
    $pro = staffNotif_makeBrand();
    $notification = staffNotif_seedNotificationForPro($pro->id);

    $listing = app(NotificationListingService::class);
    $controller = new StaffNotificationController($listing);

    // before: 1 unread
    $before = json_decode($controller->indexForProfessional(Request::create('/', 'GET'), $pro)->getContent(), true);
    expect($before['unread_count'])->toBe(1);

    // staff marks read on the pro's behalf
    $markResponse = $controller->markReadForProfessional(Request::create('/', 'POST'), $pro, $notification);
    expect($markResponse->status())->toBe(200);

    // after: still in list, but no longer counted as unread (no cache assertion —
    // the service busts the same key the index path uses, so the next call sees
    // the fresh receipt row)
    $after = json_decode($controller->indexForProfessional(Request::create('/', 'GET'), $pro)->getContent(), true);
    expect($after['unread_count'])->toBe(0);
});

it('dismissForProfessional hides the notification from the default index variant', function () {
    $pro = staffNotif_makeBrand();
    $notification = staffNotif_seedNotificationForPro($pro->id);

    $controller = new StaffNotificationController(app(NotificationListingService::class));

    $controller->dismissForProfessional(Request::create('/', 'POST'), $pro, $notification);

    $after = json_decode(
        $controller->indexForProfessional(Request::create('/', 'GET'), $pro)->getContent(),
        true
    );
    expect($after['unread_count'])->toBe(0);
    expect(count($after['notifications']))->toBe(0);
});

it('markReadForProfessional returns 404 when the notification belongs to a different pro', function () {
    $pro = staffNotif_makeBrand();
    $otherPro = staffNotif_makeBrand();
    $notification = staffNotif_seedNotificationForPro($otherPro->id);

    $controller = new StaffNotificationController(app(NotificationListingService::class));

    expect(fn () => $controller->markReadForProfessional(Request::create('/', 'POST'), $pro, $notification))
        ->toThrow(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});

it('global broadcasts are visible to staff acting on behalf of any pro', function () {
    $pro = staffNotif_makeBrand();
    $broadcast = staffNotif_seedGlobalNotification('Maintenance window');

    $controller = new StaffNotificationController(app(NotificationListingService::class));

    // mark-read on a global broadcast should succeed (a global is "visible to" anyone)
    $response = $controller->markReadForProfessional(Request::create('/', 'POST'), $pro, $broadcast);
    expect($response->status())->toBe(200);
});
