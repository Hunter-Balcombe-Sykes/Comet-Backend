<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\Staff\StaffAuditEntry;
use App\Models\Core\User\User;
use App\Services\Audit\StaffAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $conn = DB::connection('pgsql');
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS core");
    } catch (Throwable) {
    }
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS audit");
    } catch (Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY,
        staff_id TEXT,
        staff_email_snapshot TEXT,
        impersonator_staff_id TEXT,
        impersonator_email_snapshot TEXT,
        user_id TEXT,
        professional_handle_snapshot TEXT,
        route TEXT NOT NULL,
        http_method TEXT NOT NULL,
        status_code INTEGER NOT NULL,
        payload_summary TEXT NOT NULL DEFAULT "{}",
        ip_hash TEXT,
        user_agent TEXT,
        created_at TEXT
    )');
});

it('inserts a row capturing the staff, target, route, and method', function () {
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->primary_email = 'support@partna.au';
    $staff->role = PartnaStaff::ROLE_SUPPORT;

    $professional = new User;
    $professional->id = (string) Str::uuid();
    $professional->handle = 'acme-brand';

    $entry = (new StaffAuditService)->record(
        staff: $staff,
        impersonator: null,
        professional: $professional,
        route: 'staff.professionals.update',
        httpMethod: 'PATCH',
        statusCode: 200,
        payloadSummary: ['professional' => $professional->id],
        ip: '203.0.113.42',
        userAgent: 'PestTest',
    );

    expect($entry)->toBeInstanceOf(StaffAuditEntry::class)
        ->and($entry->staff_id)->toBe($staff->id)
        ->and($entry->staff_email_snapshot)->toBe('support@partna.au')
        ->and($entry->user_id)->toBe($professional->id)
        ->and($entry->professional_handle_snapshot)->toBe('acme-brand')
        ->and($entry->route)->toBe('staff.professionals.update')
        ->and($entry->http_method)->toBe('PATCH')
        ->and($entry->status_code)->toBe(200)
        ->and($entry->payload_summary)->toBe(['professional' => $professional->id])
        ->and($entry->ip_hash)->toBe(hash_hmac('sha256', '203.0.113.42', config('app.key')))
        ->and($entry->user_agent)->toBe('PestTest');
});

// DINT-1: the raw IP must never reach the append-only table — only a one-way
// HMAC-SHA256 digest (same scheme as site.enquiries.ip_hash / HashesClientData).
it('DINT-1: hashes the IP with HMAC-SHA256 instead of storing it raw', function () {
    $entry = (new StaffAuditService)->record(
        staff: null,
        impersonator: null,
        professional: null,
        route: 'staff.professionals.update',
        httpMethod: 'PATCH',
        statusCode: 200,
        ip: '198.51.100.7',
    );

    expect($entry->ip_hash)
        ->not->toBe('198.51.100.7')
        ->toBe(hash_hmac('sha256', '198.51.100.7', config('app.key')));
});

it('DINT-1: stores a null ip_hash when no IP is given', function () {
    $entry = (new StaffAuditService)->record(
        staff: null,
        impersonator: null,
        professional: null,
        route: 'staff.professionals.update',
        httpMethod: 'PATCH',
        statusCode: 200,
    );

    expect($entry->ip_hash)->toBeNull();
});

it('accepts a null professional and null staff', function () {
    $entry = (new StaffAuditService)->record(
        staff: null,
        impersonator: null,
        professional: null,
        route: 'staff.commission-payouts.retry',
        httpMethod: 'POST',
        statusCode: 202,
    );

    expect($entry)->toBeInstanceOf(StaffAuditEntry::class)
        ->and($entry->staff_id)->toBeNull()
        ->and($entry->user_id)->toBeNull()
        ->and($entry->payload_summary)->toBe([]);
});

it('swallows insert failures and returns null while logging a warning', function () {
    Log::spy();

    // Drop the table to force the insert to throw.
    DB::connection('pgsql')->statement('DROP TABLE audit.staff_audit_log');

    $entry = (new StaffAuditService)->record(
        staff: null,
        impersonator: null,
        professional: null,
        route: 'staff.professionals.update',
        httpMethod: 'PATCH',
        statusCode: 200,
    );

    expect($entry)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) => $message === 'staff.audit.write_failed'
            && isset($context['exception'])
        );
});

// B3/P2-12: write-failures must correlate to the originating HTTP request so
// SRE can join the warning to NGINX/Cloudflare access logs. Matches the
// pattern in FeatureFlagService.
it('B3/P2-12: write-failure warning includes the X-Request-Id header for correlation', function () {
    Log::spy();

    DB::connection('pgsql')->statement('DROP TABLE audit.staff_audit_log');

    // The X-Request-Id header is set by Cloudflare/NGINX on inbound requests.
    request()->headers->set('X-Request-Id', 'req-abc-123');

    (new StaffAuditService)->record(
        staff: null,
        impersonator: null,
        professional: null,
        route: 'staff.professionals.update',
        httpMethod: 'PATCH',
        statusCode: 200,
    );

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'staff.audit.write_failed'
            && ($context['request_id'] ?? null) === 'req-abc-123'
        );
});

// The force-delete path hard-deletes core.users inside the request, but
// RecordStaffAuditEntry writes from terminate() — after the response. The FK
// then has no parent and the whole row used to be discarded, leaving the most
// destructive staff endpoint with no audit trail at all.
it('keeps the audit row, unlinked, when the target user was hard-deleted before the write', function () {
    $conn = DB::connection('pgsql');
    $ghostId = (string) Str::uuid();

    // Stage the driver's REAL failure shape. SQLite says "FOREIGN KEY constraint
    // failed"; Postgres raises 23503 with the same wording in production. A
    // trigger is the only way to stage it here — SQLite does not support foreign
    // keys across ATTACHed databases, which is how the audit/core split is faked.
    $conn->statement('DROP TRIGGER IF EXISTS audit.staff_audit_log_fk_sim');
    $conn->statement("CREATE TRIGGER audit.staff_audit_log_fk_sim
        BEFORE INSERT ON staff_audit_log
        FOR EACH ROW WHEN NEW.user_id = '{$ghostId}'
        BEGIN SELECT RAISE(ABORT, 'FOREIGN KEY constraint failed'); END");

    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->primary_email = 'admin@partna.au';
    $staff->role = PartnaStaff::ROLE_ADMIN;

    $professional = new User;
    $professional->id = $ghostId;
    $professional->handle = 'deleted-pro';

    $entry = (new StaffAuditService)->record(
        staff: $staff,
        impersonator: null,
        professional: $professional,
        route: 'staff.professionals.force-destroy',
        httpMethod: 'DELETE',
        statusCode: 200,
        payloadSummary: ['professional' => $ghostId],
    );

    // The row survives; only the FK link is dropped. Identity is still
    // recoverable from the handle snapshot and payload_summary.
    expect($entry)->not->toBeNull();
    expect($entry->user_id)->toBeNull();
    expect($entry->professional_handle_snapshot)->toBe('deleted-pro');
    expect($entry->payload_summary['professional'])->toBe($ghostId);
    expect($conn->table('audit.staff_audit_log')->count())->toBe(1);

    $conn->statement('DROP TRIGGER IF EXISTS audit.staff_audit_log_fk_sim');
});

// A failure that is NOT a foreign-key violation must still fail closed — the
// retry is narrow, not a blanket "insert anyway".
it('does not retry unlinked when the insert fails for a non-FK reason', function () {
    Log::spy();

    $conn = DB::connection('pgsql');
    $conn->statement('DROP TRIGGER IF EXISTS audit.staff_audit_log_other_sim');
    $conn->statement("CREATE TRIGGER audit.staff_audit_log_other_sim
        BEFORE INSERT ON staff_audit_log
        FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'disk I/O error'); END");

    $professional = new User;
    $professional->id = (string) Str::uuid();
    $professional->handle = 'still-here';

    $entry = (new StaffAuditService)->record(
        staff: null,
        impersonator: null,
        professional: $professional,
        route: 'staff.professionals.update',
        httpMethod: 'PATCH',
        statusCode: 200,
    );

    expect($entry)->toBeNull();
    expect($conn->table('audit.staff_audit_log')->count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => $message === 'staff.audit.write_failed');

    $conn->statement('DROP TRIGGER IF EXISTS audit.staff_audit_log_other_sim');
});
