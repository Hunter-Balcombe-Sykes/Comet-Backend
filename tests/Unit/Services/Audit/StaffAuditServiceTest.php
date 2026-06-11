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
        ip TEXT,
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
        ->and($entry->ip)->toBe('203.0.113.42')
        ->and($entry->user_agent)->toBe('PestTest');
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
