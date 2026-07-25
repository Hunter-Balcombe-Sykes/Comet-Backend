<?php

/**
 * OV-A hardening — StaffAggregateAnalyticsController + AnalyticsQueryService
 * user-scope injection. Proves the two scoped-aggregate paths return correctly
 * scoped counts: segment → whereIn(member ids); no segment → null = all users.
 * Seeds 2 users in a segment + 1 outside and asserts the segment view counts
 * only the 2.
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

function ovaScopeStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;

    return $staff;
}

function ovaScopeUser(): string
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

// Seed $n page-view rows for a user, each a distinct visitor, all inside the
// default 30-day window (totals.views = COUNT(*)).
function ovaScopeSeedVisits(string $userId, int $n): void
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

it('scopes aggregates to segment members via whereIn (2 in, 1 out)', function () {
    $inA = ovaScopeUser();
    $inB = ovaScopeUser();
    $outsider = ovaScopeUser();

    ovaScopeSeedVisits($inA, 2);
    ovaScopeSeedVisits($inB, 3);
    ovaScopeSeedVisits($outsider, 4); // must NOT be counted under the segment scope

    $segment = UserSegment::query()->create(['name' => 'scope-seg', 'filters' => []]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $inA]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $inB]);

    actingAsStaff(ovaScopeStaff())
        ->getJson('/api/staff/analytics/summary?days=30&segment_id='.$segment->id)
        ->assertStatus(200)
        ->assertJsonPath('audience.scope', 'segment')
        ->assertJsonPath('audience.user_count', 2)
        ->assertJsonPath('totals.views', 5); // 2 + 3 only; the outsider's 4 is excluded
});

it('counts all users when no segment is given (null scope)', function () {
    ovaScopeSeedVisits(ovaScopeUser(), 2);
    ovaScopeSeedVisits(ovaScopeUser(), 3);
    ovaScopeSeedVisits(ovaScopeUser(), 4);

    actingAsStaff(ovaScopeStaff())
        ->getJson('/api/staff/analytics/summary?days=30')
        ->assertStatus(200)
        ->assertJsonPath('audience.scope', 'all')
        ->assertJsonPath('totals.views', 9); // 2 + 3 + 4 across all users
});
