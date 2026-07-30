<?php

/**
 * SCALE-13 — StaffAggregateAnalyticsController rejects an oversized resolved
 * segment before it becomes an unbounded whereIn in AnalyticsQueryService.
 * Cap is config('partna.analytics.staff_segment_max_users'); this proves the
 * boundary is `>` (not `>=`), that the 422 body names the limit, and that the
 * no-segment (null scope) path is unaffected by the config value.
 */

use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Segments\UserSegmentMember;
use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
    setupUsersTable();
    setupSegmentsTables();
    setupSiteVisitsTable();
    setupSiteSessionsTable();
    setupLinkClicksTable();
    setupSectionViewsTable();

    // staff.audit middleware writes here after each staff response.
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

    DB::connection('pgsql')->statement('DELETE FROM analytics.site_visits');
    DB::connection('pgsql')->statement('DELETE FROM core.users');
    DB::connection('pgsql')->statement('DELETE FROM core.user_segments');
    DB::connection('pgsql')->statement('DELETE FROM core.user_segment_members');
});

function segCapStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;

    return $staff;
}

function segCapUser(): string
{
    $id = (string) Str::uuid();
    $handle = 'sc-'.Str::random(8);
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $id;
}

function segCapSeedVisits(string $userId, int $n): void
{
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'visitor_id' => (string) Str::uuid(),
            'occurred_at' => now()->subDay()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
        ];
    }
    DB::connection('pgsql')->table('analytics.site_visits')->insert($rows);
}

it('rejects a segment that resolves above the configured cap with a 422 naming the limit', function () {
    config(['partna.analytics.staff_segment_max_users' => 2]);

    $userA = segCapUser();
    $userB = segCapUser();
    $userC = segCapUser();

    $segment = UserSegment::query()->create(['name' => 'over-cap-seg', 'filters' => []]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $userA]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $userB]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $userC]);

    $response = actingAsStaff(segCapStaff())
        ->getJson('/api/staff/analytics/summary?days=30&segment_id='.$segment->id)
        ->assertStatus(422);

    $message = $response->json('message');
    expect($message)->toContain('3')->toContain('2');
});

it('still returns 200 with correct counts at exactly the cap (boundary is >, not >=)', function () {
    config(['partna.analytics.staff_segment_max_users' => 2]);

    $userA = segCapUser();
    $userB = segCapUser();
    $outsider = segCapUser();

    segCapSeedVisits($userA, 2);
    segCapSeedVisits($userB, 3);
    segCapSeedVisits($outsider, 4); // must NOT be counted under the segment scope

    $segment = UserSegment::query()->create(['name' => 'at-cap-seg', 'filters' => []]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $userA]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $userB]);

    actingAsStaff(segCapStaff())
        ->getJson('/api/staff/analytics/summary?days=30&segment_id='.$segment->id)
        ->assertStatus(200)
        ->assertJsonPath('audience.scope', 'segment')
        ->assertJsonPath('audience.user_count', 2)
        ->assertJsonPath('totals.views', 5); // 2 + 3 only; the outsider's 4 is excluded
});

it('leaves the no-segment (null scope) path unaffected by a low cap', function () {
    config(['partna.analytics.staff_segment_max_users' => 1]);

    segCapSeedVisits(segCapUser(), 2);
    segCapSeedVisits(segCapUser(), 3);
    segCapSeedVisits(segCapUser(), 4);

    actingAsStaff(segCapStaff())
        ->getJson('/api/staff/analytics/summary?days=30')
        ->assertStatus(200)
        ->assertJsonPath('audience.scope', 'all')
        ->assertJsonPath('totals.views', 9); // 2 + 3 + 4 across all users, cap never applies to null scope
});
