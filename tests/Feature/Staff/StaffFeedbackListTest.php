<?php

/**
 * OV-D — staff feedback triage list (GET /staff/feedback). Any staff role
 * (support or admin) can list; filters by type/area/date; non-staff get the
 * real EnsurePartnaStaff 403 (not a stub) so this also pins the "staff-only
 * → 403" convention used by every other /staff/* endpoint in this codebase.
 */

use App\Models\Core\Feedback;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['partna.throttle.enabled' => false]);

    setupUsersTable();
    setupFeedbackTable();
    setupPartnaStaffTable();

    // staff.audit middleware writes here — this route is PII-allowlisted
    // (RecordStaffAuditEntry::PII_READ_ROUTE_NAMES 'staff.feedback.index').
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
});

function ovdSupportStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_SUPPORT;
    $staff->primary_email = 'support-ovd@partna.au';

    return $staff;
}

function ovdSeedUser(string $handle): User
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => $handle,
        'handle_lc' => mb_strtolower($handle),
        'display_name' => ucfirst($handle),
        'primary_email' => "{$handle}@example.test",
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return User::findOrFail($id);
}

function ovdSeedFeedback(string $userId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.feedback')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'kind' => 'idea',
        'type' => 'idea',
        'area' => 'analytics',
        'message' => 'seed feedback',
        'status' => 'new',
        'internal_notes' => '[]',
        'tags' => '[]',
        'source' => 'dashboard',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides));

    return $id;
}

it('lists feedback across ALL users, not scoped to one submitter', function () {
    $alice = ovdSeedUser('alice');
    $bob = ovdSeedUser('bob');
    ovdSeedFeedback($alice->id, ['message' => 'alice says hi']);
    ovdSeedFeedback($bob->id, ['message' => 'bob says hi']);

    $response = actingAsStaff(ovdSupportStaff())->getJson('/api/staff/feedback');

    $response->assertStatus(200);
    $items = $response->json('feedback');
    expect($items)->toHaveCount(2);
    expect($response->json('meta.total'))->toBe(2);

    $messages = array_column($items, 'message');
    expect($messages)->toContain('alice says hi', 'bob says hi');
});

it('includes a nested user summary and full triage fields per row', function () {
    $alice = ovdSeedUser('alice');
    ovdSeedFeedback($alice->id, [
        'message' => 'needs triage',
        'reply_email' => 'alice-reply@example.test',
        'ip_hash' => str_repeat('a', 64),
    ]);

    $response = actingAsStaff(ovdSupportStaff())->getJson('/api/staff/feedback');

    $response->assertStatus(200);
    $row = $response->json('feedback.0');
    expect($row['user'])->toBe([
        'id' => $alice->id,
        'handle' => 'alice',
        'display_name' => 'Alice',
        'email' => 'alice@example.test',
    ]);
    expect($row['reply_email'])->toBe('alice-reply@example.test');
    expect($row['ip_hash'])->toBe(str_repeat('a', 64));
});

it('filters by type', function () {
    $alice = ovdSeedUser('alice');
    ovdSeedFeedback($alice->id, ['type' => 'error', 'message' => 'an error']);
    ovdSeedFeedback($alice->id, ['type' => 'idea', 'message' => 'an idea']);

    $response = actingAsStaff(ovdSupportStaff())->getJson('/api/staff/feedback?type=error');

    $response->assertStatus(200);
    $items = $response->json('feedback');
    expect($items)->toHaveCount(1);
    expect($items[0]['message'])->toBe('an error');
});

it('ignores an unrecognised type filter rather than erroring', function () {
    $alice = ovdSeedUser('alice');
    ovdSeedFeedback($alice->id, ['type' => 'error']);
    ovdSeedFeedback($alice->id, ['type' => 'idea']);

    $response = actingAsStaff(ovdSupportStaff())->getJson('/api/staff/feedback?type=not-a-real-type');

    $response->assertStatus(200);
    expect($response->json('feedback'))->toHaveCount(2);
});

it('filters by area', function () {
    $alice = ovdSeedUser('alice');
    ovdSeedFeedback($alice->id, ['area' => 'analytics', 'message' => 'about analytics']);
    ovdSeedFeedback($alice->id, ['area' => 'booking', 'message' => 'about booking']);

    $response = actingAsStaff(ovdSupportStaff())->getJson('/api/staff/feedback?area=booking');

    $response->assertStatus(200);
    $items = $response->json('feedback');
    expect($items)->toHaveCount(1);
    expect($items[0]['message'])->toBe('about booking');
});

it('filters by created_at date range', function () {
    $alice = ovdSeedUser('alice');
    ovdSeedFeedback($alice->id, ['message' => 'old one', 'created_at' => '2026-01-01 00:00:00']);
    ovdSeedFeedback($alice->id, ['message' => 'recent one', 'created_at' => now()->toDateTimeString()]);

    $response = actingAsStaff(ovdSupportStaff())
        ->getJson('/api/staff/feedback?from='.now()->subDay()->toDateString());

    $response->assertStatus(200);
    $items = $response->json('feedback');
    expect($items)->toHaveCount(1);
    expect($items[0]['message'])->toBe('recent one');
});

it('returns 422 for an invalid date filter', function () {
    $response = actingAsStaff(ovdSupportStaff())->getJson('/api/staff/feedback?from=not-a-date');

    $response->assertStatus(422);
});

it('paginates with the house envelope and respects per_page', function () {
    $alice = ovdSeedUser('alice');
    for ($i = 0; $i < 3; $i++) {
        ovdSeedFeedback($alice->id, ['message' => "row-{$i}"]);
    }

    $response = actingAsStaff(ovdSupportStaff())->getJson('/api/staff/feedback?per_page=2');

    $response->assertStatus(200);
    expect($response->json('feedback'))->toHaveCount(2);
    expect($response->json('meta'))->toMatchArray([
        'current_page' => 1,
        'per_page' => 2,
        'total' => 3,
        'last_page' => 2,
    ]);
});

it('rejects a non-staff authenticated user with 403 (real EnsurePartnaStaff, not a stub)', function () {
    // actingAsUser() only stubs VerifySupabaseJwt (+ an unused LoadCurrentUser
    // stub — /staff/* doesn't run current.pro). EnsurePartnaStaff runs for
    // real, looks up core.partna_staff by auth_user_id, finds nothing, 403s.
    $intruder = ovdSeedUser('intruder');

    $response = actingAsUser($intruder)->getJson('/api/staff/feedback');

    $response->assertStatus(403);
});

it('rejects an unauthenticated request with 401', function () {
    $response = $this->getJson('/api/staff/feedback');

    $response->assertStatus(401);
});
