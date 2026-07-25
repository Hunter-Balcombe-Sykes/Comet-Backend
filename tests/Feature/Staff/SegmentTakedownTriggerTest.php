<?php

use App\Jobs\Platforms\ReconcilePlatformTakedownJob;
use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSegmentsTables();
    setupFeatureAvailabilityTable();
    setupPartnaStaffTable();
    // staff.audit middleware writes on terminate() — same audit.staff_audit_log
    // CREATE TABLE as Task 4 / tests/Feature/Staff/StaffBulkStatusTest.php.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY, staff_id TEXT, staff_email_snapshot TEXT,
        impersonator_staff_id TEXT, impersonator_email_snapshot TEXT, user_id TEXT,
        professional_handle_snapshot TEXT, route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\', status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\', ip_hash TEXT, user_agent TEXT, created_at TEXT
    )');
    DB::connection('pgsql')->statement('DELETE FROM core.feature_availability');
    DB::connection('pgsql')->statement('DELETE FROM core.user_segments');
    FeatureAvailability::flush();
});

function segmentTriggerStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin-seg@partna.au';

    return $staff;
}

it('dispatches a segment takedown when adding members to a segment with a disabled integration rule', function () {
    Bus::fake();
    $staff = segmentTriggerStaff();

    $newbie = User::create([
        'handle' => 'segnew', 'handle_lc' => 'segnew', 'display_name' => 'Seg', 'first_name' => 'Seg',
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(), 'primary_email' => 'segnew@example.com',
    ]);

    $segment = UserSegment::query()->create(['name' => 'seg-'.Str::random(4), 'filters' => []]);
    FeatureAvailabilityRule::query()->create([
        'feature_key' => 'integration.skool', 'mode' => 'disabled', 'segment_id' => $segment->id,
    ]);
    FeatureAvailability::flush();

    actingAsStaff($staff)->postJson("/api/staff/segments/{$segment->id}/members", [
        'user_ids' => [$newbie->id],
    ])->assertSuccessful();

    Bus::assertDispatched(ReconcilePlatformTakedownJob::class, function ($job) use ($segment) {
        return $job->platform === 'skool' && $job->segmentId === $segment->id;
    });
});

it('does not dispatch when the segment has no disabled integration rule', function () {
    Bus::fake();
    $staff = segmentTriggerStaff();

    $newbie = User::create([
        'handle' => 'segnone', 'handle_lc' => 'segnone', 'display_name' => 'None', 'first_name' => 'None',
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(), 'primary_email' => 'segnone@example.com',
    ]);
    $segment = UserSegment::query()->create(['name' => 'seg-'.Str::random(4), 'filters' => []]);

    actingAsStaff($staff)->postJson("/api/staff/segments/{$segment->id}/members", [
        'user_ids' => [$newbie->id],
    ])->assertSuccessful();

    Bus::assertNotDispatched(ReconcilePlatformTakedownJob::class);
});
