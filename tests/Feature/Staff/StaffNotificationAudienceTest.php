<?php

/**
 * OV-A — staff notification audience targeting: all → one global row;
 * segment/selected → one in-app row per member; critical flag persists.
 */

use App\Http\Controllers\Api\Staff\StaffSite\StaffNotificationController;
use App\Http\Requests\Api\Staff\Notifications\StaffStoreNotificationRequest;
use App\Models\Core\Notifications\Notification;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Segments\UserSegmentMember;
use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request as SymfonyHttpRequest;

beforeEach(function () {
    setupUsersTable();
    setupNotificationsTable();
    setupSegmentsTables();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
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

    DB::connection('pgsql')->statement('DELETE FROM notifications.notifications');
    DB::connection('pgsql')->statement('DELETE FROM core.users');
});

function ovaNotifStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;

    return $staff;
}

function ovaNotifUser(): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => 'n-'.Str::random(8),
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}

function ovaNotifPayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'info',
        'title' => 'Audience test',
        'body' => 'Body',
    ], $overrides);
}

it('creates one global row for audience type all', function () {
    ovaNotifUser();

    actingAsStaff(ovaNotifStaff())
        ->postJson('/api/staff/notifications', ovaNotifPayload(['audience' => ['type' => 'all']]))
        ->assertStatus(201)
        ->assertJsonPath('created_count', 1)
        ->assertJsonPath('audience_size', null);

    expect(Notification::query()->count())->toBe(1)
        ->and(Notification::query()->first()->user_id)->toBeNull();
});

it('fans out one row per segment member', function () {
    $a = ovaNotifUser();
    $b = ovaNotifUser();
    ovaNotifUser(); // not in segment

    $segment = UserSegment::query()->create(['name' => 'seg', 'filters' => []]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $a]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $b]);

    actingAsStaff(ovaNotifStaff())
        ->postJson('/api/staff/notifications', ovaNotifPayload([
            'audience' => ['type' => 'segment', 'segment_id' => $segment->id],
            'critical' => true,
        ]))
        ->assertStatus(201)
        ->assertJsonPath('created_count', 2)
        ->assertJsonPath('audience_size', 2);

    $rows = Notification::query()->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('user_id')->sort()->values()->all())->toBe(collect([$a, $b])->sort()->values()->all())
        ->and($rows->every(fn (Notification $n) => $n->critical === true))->toBeTrue();
});

it('fans out to selected user ids and persists the critical flag default false', function () {
    $a = ovaNotifUser();
    $b = ovaNotifUser();

    actingAsStaff(ovaNotifStaff())
        ->postJson('/api/staff/notifications', ovaNotifPayload([
            'audience' => ['type' => 'selected', 'user_ids' => [$a, $b]],
        ]))
        ->assertStatus(201)
        ->assertJsonPath('created_count', 2);

    expect(Notification::query()->where('critical', true)->count())->toBe(0)
        ->and(Notification::query()->whereNotNull('user_id')->count())->toBe(2);
});

it('keeps the legacy single-user path working', function () {
    $a = ovaNotifUser();

    actingAsStaff(ovaNotifStaff())
        ->postJson('/api/staff/notifications', ovaNotifPayload(['user_id' => $a]))
        ->assertStatus(201)
        ->assertJsonPath('created_count', 1)
        ->assertJsonPath('notification.user_id', $a);
});

it('rejects a segment audience without segment_id', function () {
    actingAsStaff(ovaNotifStaff())
        ->postJson('/api/staff/notifications', ovaNotifPayload(['audience' => ['type' => 'segment']]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['audience.segment_id']);
});

it('denies store for a non-admin staff at the policy layer (defense-in-depth)', function () {
    // Direct controller call bypasses the staff.admin middleware so the
    // authorizeForUser(staffManage) policy call — not the middleware — is what
    // rejects the support-role actor. Proves the policy gate stands on its own.
    $support = new PartnaStaff;
    $support->id = (string) Str::uuid();
    $support->role = PartnaStaff::ROLE_SUPPORT;

    $request = Mockery::mock(StaffStoreNotificationRequest::class)->makePartial();
    $request->shouldReceive('validated')->andReturn(ovaNotifPayload(['audience' => ['type' => 'all']]));
    (new ReflectionProperty(SymfonyHttpRequest::class, 'attributes'))->setValue($request, new ParameterBag);
    $request->attributes->set('partna_staff', $support);

    expect(fn () => app(StaffNotificationController::class)->store($request))
        ->toThrow(AuthorizationException::class);

    // Admin passes the same gate and creates the row.
    $admin = ovaNotifStaff();
    $adminReq = Mockery::mock(StaffStoreNotificationRequest::class)->makePartial();
    $adminReq->shouldReceive('validated')->andReturn(ovaNotifPayload(['audience' => ['type' => 'all']]));
    (new ReflectionProperty(SymfonyHttpRequest::class, 'attributes'))->setValue($adminReq, new ParameterBag);
    $adminReq->attributes->set('partna_staff', $admin);

    app(StaffNotificationController::class)->store($adminReq);

    expect(Notification::query()->count())->toBe(1);
});
