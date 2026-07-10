<?php

/** @phpstan-ignore-all */

use App\Http\Controllers\Api\Staff\StaffSite\StaffNotificationController;
use App\Http\Requests\Api\Staff\Notifications\StaffStoreNotificationRequest;
use App\Models\Core\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;

/**
 * Locks in the retention-days behaviour for staff broadcasts.
 *
 * Before this fix the controller keyed the retention map by the normalized
 * frontend type ('Info' / 'Critical' / ...) which never matched the semantic
 * keys in config('partna.notification_retention_days') — so the lookup silently
 * fell through to the 'default' value every single time. The fix removes the
 * dead per-key branch and uses the 'default' value directly; this test asserts
 * that changing the config default actually propagates to the notification row.
 */
beforeEach(function () {
    // Notification extends BaseModel which hardcodes the pgsql connection name,
    // so we have to point the pgsql connection at sqlite in-memory for this
    // test. This mirrors what phpunit.xml does for DB_CONNECTION globally.
    Config::set('database.connections.pgsql', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    DB::purge('pgsql');
    DB::reconnect('pgsql');

    $conn = DB::connection('pgsql');

    // The Notification model uses $table = 'notifications.notifications' (schema-qualified).
    // SQLite has no schemas, so we ATTACH a second in-memory database under the alias
    // `notifications` to make `notifications.notifications` resolve.
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS notifications");
    } catch (Throwable $e) {
        // Ignore if already attached from a previous test in this process.
    }

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

    Carbon::setTestNow('2026-04-14 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Build a mocked StaffStoreNotificationRequest whose ->validated() returns $data.
 * Mirrors the mockFormRequest() helper in StaffFeatureFlagsControllerTest.
 *
 * @param  array<string,mixed>  $data
 */
function mockStaffStoreNotificationRequest(array $data): StaffStoreNotificationRequest
{
    $mock = Mockery::mock(StaffStoreNotificationRequest::class)->makePartial();
    $mock->shouldReceive('validated')->andReturn($data);

    // Symfony's $attributes property must be initialised before ->set() is usable.
    $ref = new ReflectionClass(Request::class);
    $prop = $ref->getProperty('attributes');
    $prop->setValue($mock, new ParameterBag);

    return $mock;
}

it('uses the default retention window when ends_at is not provided', function () {
    // Non-default semantic keys (invite, brand_status, ...) must NOT influence
    // staff broadcast retention: the controller has no category to key by, so
    // only 'default' is consulted. The large 'invite' value here is the
    // canary — if the map lookup ever comes back, this test will catch it.
    Config::set('partna.notification_retention_days', [
        'default' => 45,
        'invite' => 999,
    ]);

    $controller = app(StaffNotificationController::class);

    $request = mockStaffStoreNotificationRequest([
        'type' => 'info',
        'title' => 'System maintenance',
        'body' => 'Planned maintenance tonight.',
    ]);

    $response = $controller->store($request);

    expect($response->getStatusCode())->toBe(201);

    $payload = json_decode($response->getContent(), true);
    expect($payload)->toHaveKey('notification');

    $notification = Notification::query()->findOrFail($payload['notification']['id']);
    expect($notification->ends_at->toDateTimeString())
        ->toBe(Carbon::parse('2026-04-14 12:00:00')->addDays(45)->toDateTimeString());
});

it('honours an explicit ends_at on the request', function () {
    Config::set('partna.notification_retention_days.default', 30);

    $controller = app(StaffNotificationController::class);

    $explicit = '2026-05-01 09:30:00';

    $request = mockStaffStoreNotificationRequest([
        'type' => 'warning',
        'title' => 'Scheduled outage',
        'body' => 'We will be down briefly.',
        'ends_at' => $explicit,
    ]);

    $response = $controller->store($request);

    expect($response->getStatusCode())->toBe(201);

    $payload = json_decode($response->getContent(), true);

    $notification = Notification::query()->findOrFail($payload['notification']['id']);
    expect($notification->ends_at->toDateTimeString())->toBe($explicit);
});
