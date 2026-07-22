<?php

// B8 / models-data PRIV-2 + PRIV-3: retention prune for the append-only audit tables
// audit.user_deletion_audit + audit.data_export_audit. The real redaction runs through
// the SECURITY DEFINER audit.prune_user_deletion_audit() / audit.prune_data_export_audit()
// RPCs (20260722010000_audit_pii_snapshot_retention_prune.sql) because app_backend cannot
// UPDATE the audit schema directly. Those RPCs don't exist on the SQLite test connection,
// so only the dry-run + retention-floor guard are exercised there; the real anonymise
// path is gated behind a Postgres-only test.

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUserDeletionAuditTable();
    setupDataExportAuditTable();
});

function seedUserDeletionAuditRow(string $email, Carbon $createdAt): void
{
    DB::connection('pgsql')->table('audit.user_deletion_audit')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => (string) Str::uuid(),
        'professional_handle_snapshot' => 'somehandle',
        'professional_email_snapshot' => $email,
        'event' => 'purged',
        'actor_type' => 'professional',
        'created_at' => $createdAt->toDateTimeString(),
    ]);
}

function seedDataExportAuditRow(string $recipient, Carbon $createdAt): void
{
    DB::connection('pgsql')->table('audit.data_export_audit')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => (string) Str::uuid(),
        'professional_handle_snapshot' => 'somehandle',
        'professional_email_snapshot' => 'pro@example.com',
        'triggered_by' => 'self',
        'recipient_email' => $recipient,
        'status' => 'completed',
        'created_at' => $createdAt->toDateTimeString(),
    ]);
}

it('dry-run reports the eligible counts and redacts nothing', function () {
    // Default retention is 7y — subYears(8) is past the cutoff, subYears(6) is not.
    seedUserDeletionAuditRow('stale@example.com', now()->subYears(8));
    seedUserDeletionAuditRow('recent@example.com', now()->subYears(6));
    seedDataExportAuditRow('stale-recipient@example.com', now()->subYears(8));
    seedDataExportAuditRow('recent-recipient@example.com', now()->subYears(6));

    $this->artisan('audit:prune-pii-snapshots', ['--dry-run' => true])
        ->expectsOutputToContain('Would redact 1 user_deletion_audit + 1 data_export_audit rows')
        ->assertExitCode(0);

    // Nothing mutated by a dry run.
    expect(DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('professional_email_snapshot', 'stale@example.com')->exists())->toBeTrue();
    expect(DB::connection('pgsql')->table('audit.data_export_audit')
        ->where('recipient_email', 'stale-recipient@example.com')->exists())->toBeTrue();
});

it('dry-run skips rows already redacted (sentinel guard keeps re-runs cheap)', function () {
    seedUserDeletionAuditRow('[redacted]', now()->subYears(8));
    seedDataExportAuditRow('[redacted]', now()->subYears(8));

    $this->artisan('audit:prune-pii-snapshots', ['--dry-run' => true])
        ->expectsOutputToContain('Would redact 0 user_deletion_audit + 0 data_export_audit rows')
        ->assertExitCode(0);
});

it('rejects a retention config below the 1-year floor and touches nothing', function () {
    config(['partna.audit.pii_retention_years' => 0]);

    seedUserDeletionAuditRow('stale@example.com', now()->subYears(8));

    $this->artisan('audit:prune-pii-snapshots')
        ->expectsOutputToContain('Retention window must be at least 1 year')
        ->assertExitCode(1);

    expect(DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('professional_email_snapshot', 'stale@example.com')->exists())->toBeTrue();
});

// The RPCs (audit.prune_user_deletion_audit / audit.prune_data_export_audit) only exist
// on real Postgres — SQLite has no equivalent function, no schema-wide REVOKE, and no
// SECURITY DEFINER concept. Skips on CI's SQLite driver; run against a Supabase dev DB
// to verify the real anonymise path.
it('redacts only rows past the retention cutoff via the SECURITY DEFINER RPCs, keeping the event rows', function () {
    if (DB::connection('pgsql')->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('audit.prune_* RPCs require a real PostgreSQL connection.');
    }

    seedUserDeletionAuditRow('stale@example.com', now()->subYears(8));
    seedUserDeletionAuditRow('recent@example.com', now()->subYears(6));
    seedDataExportAuditRow('stale-recipient@example.com', now()->subYears(8));
    seedDataExportAuditRow('recent-recipient@example.com', now()->subYears(6));

    $this->artisan('audit:prune-pii-snapshots')->assertExitCode(0);

    // Stale PII is gone (redacted to the sentinel), recent PII is untouched.
    expect(DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('professional_email_snapshot', 'stale@example.com')->exists())->toBeFalse();
    expect(DB::connection('pgsql')->table('audit.data_export_audit')
        ->where('recipient_email', 'stale-recipient@example.com')->exists())->toBeFalse();
    expect(DB::connection('pgsql')->table('audit.user_deletion_audit')
        ->where('professional_email_snapshot', 'recent@example.com')->exists())->toBeTrue();
    expect(DB::connection('pgsql')->table('audit.data_export_audit')
        ->where('recipient_email', 'recent-recipient@example.com')->exists())->toBeTrue();

    // Anonymise-in-place, not delete — every event row survives.
    expect(DB::connection('pgsql')->table('audit.user_deletion_audit')->count())->toBe(2);
    expect(DB::connection('pgsql')->table('audit.data_export_audit')->count())->toBe(2);
});
