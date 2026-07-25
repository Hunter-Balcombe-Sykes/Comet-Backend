<?php

/** @phpstan-ignore-all */

use App\Http\Controllers\Api\Staff\StaffSite\StaffNotificationController;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\Notifications\NotificationListingService;
use App\Services\Segments\SegmentResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Builds a Request carrying a partna_staff attribute — #SEC-5 gates
 * indexForProfessional() with staffView.
 */
function staffNotifOnBehalf_request(string $method): Request
{
    return staffNotifOnBehalf_requestAs($method, PartnaStaff::ROLE_ADMIN);
}

/**
 * Same as staffNotifOnBehalf_request() but with a caller-chosen role — used to
 * prove the #NOTIF-1 staffManage policy gate denies a non-admin on its own,
 * independent of the staff.admin route middleware (which this direct
 * controller call bypasses entirely).
 */
function staffNotifOnBehalf_requestAs(string $method, string $role): Request
{
    $request = Request::create('/', $method);
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = $role;
    $request->attributes->set('partna_staff', $staff);

    return $request;
}

beforeEach(function () {
    attachTestSchemas();
    setupUsersTable();
    setupPartnaStaffTable();

    $conn = DB::connection('pgsql');

    // staff.audit middleware writes here on every write request (see
    // StaffNotificationEmailPolicyControllerAuthTest.php for the same setup).
    $conn->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY,
        staff_id TEXT,
        staff_email_snapshot TEXT,
        impersonator_staff_id TEXT,
        impersonator_email_snapshot TEXT,
        user_id TEXT,
        professional_handle_snapshot TEXT,
        route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\',
        status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\',
        ip_hash TEXT,
        user_agent TEXT,
        created_at TEXT
    )');

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
        severity TEXT NOT NULL DEFAULT "info",        critical INTEGER NOT NULL DEFAULT 0,
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
    $handle = 'brand-'.substr($id, 0, 8);
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
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

    $controller = new StaffNotificationController(app(NotificationListingService::class), app(SegmentResolver::class));
    $response = $controller->indexForProfessional(staffNotifOnBehalf_request('GET'), $pro);

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
    $controller = new StaffNotificationController($listing, app(SegmentResolver::class));

    // before: 1 unread
    $before = json_decode($controller->indexForProfessional(staffNotifOnBehalf_request('GET'), $pro)->getContent(), true);
    expect($before['unread_count'])->toBe(1);

    // staff marks read on the pro's behalf (admin — the seam should allow it)
    $markResponse = $controller->markReadForProfessional(staffNotifOnBehalf_request('POST'), $pro, $notification);
    expect($markResponse->status())->toBe(200);

    // after: still in list, but no longer counted as unread (no cache assertion —
    // the service busts the same key the index path uses, so the next call sees
    // the fresh receipt row)
    $after = json_decode($controller->indexForProfessional(staffNotifOnBehalf_request('GET'), $pro)->getContent(), true);
    expect($after['unread_count'])->toBe(0);
});

it('dismissForProfessional hides the notification from the default index variant', function () {
    $pro = staffNotif_makeBrand();
    $notification = staffNotif_seedNotificationForPro($pro->id);

    $controller = new StaffNotificationController(app(NotificationListingService::class), app(SegmentResolver::class));

    $controller->dismissForProfessional(staffNotifOnBehalf_request('POST'), $pro, $notification);

    $after = json_decode(
        $controller->indexForProfessional(staffNotifOnBehalf_request('GET'), $pro)->getContent(),
        true
    );
    expect($after['unread_count'])->toBe(0);
    expect(count($after['notifications']))->toBe(0);
});

it('markReadForProfessional returns 404 when the notification belongs to a different pro', function () {
    $pro = staffNotif_makeBrand();
    $otherPro = staffNotif_makeBrand();
    $notification = staffNotif_seedNotificationForPro($otherPro->id);

    $controller = new StaffNotificationController(app(NotificationListingService::class), app(SegmentResolver::class));

    expect(fn () => $controller->markReadForProfessional(staffNotifOnBehalf_request('POST'), $pro, $notification))
        ->toThrow(NotFoundHttpException::class);
});

it('global broadcasts are visible to staff acting on behalf of any pro', function () {
    $pro = staffNotif_makeBrand();
    $broadcast = staffNotif_seedGlobalNotification('Maintenance window');

    $controller = new StaffNotificationController(app(NotificationListingService::class), app(SegmentResolver::class));

    // mark-read on a global broadcast should succeed (a global is "visible to" anyone)
    $response = $controller->markReadForProfessional(staffNotifOnBehalf_request('POST'), $pro, $broadcast);
    expect($response->status())->toBe(200);
});

// #NOTIF-1: the seam denies a non-admin at the policy layer, standing on its
// own regardless of the staff.admin middleware (which a direct controller
// call like this bypasses entirely — see StaffNotificationAudienceTest.php).
it('markReadForProfessional denies a non-admin staff member at the policy layer', function () {
    $pro = staffNotif_makeBrand();
    $notification = staffNotif_seedNotificationForPro($pro->id);

    $controller = new StaffNotificationController(app(NotificationListingService::class), app(SegmentResolver::class));

    expect(fn () => $controller->markReadForProfessional(
        staffNotifOnBehalf_requestAs('POST', PartnaStaff::ROLE_SUPPORT), $pro, $notification
    ))->toThrow(AuthorizationException::class);

    // Denied before the write — no receipt row was created.
    expect(DB::connection('pgsql')->table('notifications.notification_receipts')->count())->toBe(0);
});

// Route-level coverage. The tests above call the controller directly, so they
// never exercise route-model binding — which is exactly how these two endpoints
// 500'd in production unnoticed: scoped binding resolved {notification} through
// $professional->notifications(), Laravel's Notifiable trait relation to
// DatabaseNotification, instead of the app's Notification model. These two tests
// go through the router and would have caught it.

it('POST .../notifications/{notification}/read binds the app Notification model and returns 200', function () {
    $pro = staffNotif_makeBrand();
    $notification = staffNotif_seedNotificationForPro($pro->id);

    $admin = new PartnaStaff;
    $admin->id = (string) Str::uuid();
    $admin->role = PartnaStaff::ROLE_ADMIN;

    actingAsStaff($admin)
        ->postJson("/api/staff/professionals/{$pro->id}/notifications/{$notification->id}/read")
        ->assertStatus(200);

    expect(DB::connection('pgsql')->table('notifications.notification_receipts')
        ->where('notification_id', $notification->id)->whereNotNull('read_at')->count())->toBe(1);
});

it('the same route still denies a support-role staff member with 403', function () {
    $pro = staffNotif_makeBrand();
    $notification = staffNotif_seedNotificationForPro($pro->id);

    $support = new PartnaStaff;
    $support->id = (string) Str::uuid();
    $support->role = PartnaStaff::ROLE_SUPPORT;

    actingAsStaff($support)
        ->postJson("/api/staff/professionals/{$pro->id}/notifications/{$notification->id}/read")
        ->assertStatus(403);

    expect(DB::connection('pgsql')->table('notifications.notification_receipts')->count())->toBe(0);
});

it('dismissForProfessional denies a non-admin staff member at the policy layer', function () {
    $pro = staffNotif_makeBrand();
    $notification = staffNotif_seedNotificationForPro($pro->id);

    $controller = new StaffNotificationController(app(NotificationListingService::class), app(SegmentResolver::class));

    expect(fn () => $controller->dismissForProfessional(
        staffNotifOnBehalf_requestAs('POST', PartnaStaff::ROLE_SUPPORT), $pro, $notification
    ))->toThrow(AuthorizationException::class);

    // Denied before the write — no receipt row was created.
    expect(DB::connection('pgsql')->table('notifications.notification_receipts')->count())->toBe(0);
});
