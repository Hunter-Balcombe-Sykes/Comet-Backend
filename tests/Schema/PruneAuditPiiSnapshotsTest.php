<?php

// B8 / models-data PRIV-2 + PRIV-3: retention prune for the append-only audit tables
// audit.user_deletion_audit + audit.data_export_audit. The real redaction runs through
// the SECURITY DEFINER audit.prune_user_deletion_audit() / audit.prune_data_export_audit()
// RPCs (20260722010000_audit_pii_snapshot_retention_prune.sql) because app_backend cannot
// UPDATE the audit schema directly. Those RPCs only exist on real Postgres, so this file
// lives in the applied-schema lane rather than behind a driver-gated skip.
//
// #COV-LANE-4: moved here (not a drift bug) — both audit tables' user_id FKs onto
// core.users (user_deletion_audit_user_fk / data_export_audit's equivalent, both ON
// DELETE SET NULL). The old SQLite fixture seeded every row with a fresh, unrelated
// Str::uuid() — SQLite's stand-in has no such FK, so every test failed against real
// Postgres, not only the one gated behind a pgsql check. Fixed with the standard
// SeedsAuthUsers seed-the-parent-row-first pattern.

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Schema\Concerns\SeedsAuthUsers;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class, SeedsAuthUsers::class)->in(__FILE__);

function seedUserDeletionAuditRow(string $userId, string $email, Carbon $createdAt): void
{
    DB::connection('pgsql')->table('audit.user_deletion_audit')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'professional_handle_snapshot' => 'somehandle',
        'professional_email_snapshot' => $email,
        'event' => 'purged',
        'actor_type' => 'professional',
        'created_at' => $createdAt->toDateTimeString(),
    ]);
}

function seedDataExportAuditRow(string $userId, string $recipient, Carbon $createdAt): void
{
    DB::connection('pgsql')->table('audit.data_export_audit')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'professional_handle_snapshot' => 'somehandle',
        'professional_email_snapshot' => 'pro@example.com',
        'triggered_by' => 'self',
        'recipient_email' => $recipient,
        'status' => 'completed',
        'created_at' => $createdAt->toDateTimeString(),
    ]);
}

/**
 * No RefreshDatabase in this lane — the DB is persistent and shared across
 * the whole run. Both FKs are ON DELETE SET NULL (not CASCADE), so the audit
 * rows survive the seeded user's forceDelete and must be cleaned up first.
 */
function cleanupPiiSnapshotRows(string $userId): void
{
    DB::connection('pgsql')->table('audit.user_deletion_audit')->where('user_id', $userId)->delete();
    DB::connection('pgsql')->table('audit.data_export_audit')->where('user_id', $userId)->delete();
}

it('dry-run reports the eligible counts and redacts nothing', function () {
    $user = $this->seedAuthUser();

    try {
        // Default retention is 7y — subYears(8) is past the cutoff, subYears(6) is not.
        seedUserDeletionAuditRow($user->id, 'stale@example.com', now()->subYears(8));
        seedUserDeletionAuditRow($user->id, 'recent@example.com', now()->subYears(6));
        seedDataExportAuditRow($user->id, 'stale-recipient@example.com', now()->subYears(8));
        seedDataExportAuditRow($user->id, 'recent-recipient@example.com', now()->subYears(6));

        $this->artisan('audit:prune-pii-snapshots', ['--dry-run' => true])
            ->expectsOutputToContain('Would redact 1 user_deletion_audit + 1 data_export_audit rows')
            ->assertExitCode(0);

        // Nothing mutated by a dry run.
        expect(DB::connection('pgsql')->table('audit.user_deletion_audit')
            ->where('professional_email_snapshot', 'stale@example.com')->exists())->toBeTrue();
        expect(DB::connection('pgsql')->table('audit.data_export_audit')
            ->where('recipient_email', 'stale-recipient@example.com')->exists())->toBeTrue();
    } finally {
        cleanupPiiSnapshotRows($user->id);
        $this->cleanupSeededUser($user);
    }
});

it('dry-run skips rows already redacted (sentinel guard keeps re-runs cheap)', function () {
    $user = $this->seedAuthUser();

    try {
        seedUserDeletionAuditRow($user->id, '[redacted]', now()->subYears(8));
        seedDataExportAuditRow($user->id, '[redacted]', now()->subYears(8));

        $this->artisan('audit:prune-pii-snapshots', ['--dry-run' => true])
            ->expectsOutputToContain('Would redact 0 user_deletion_audit + 0 data_export_audit rows')
            ->assertExitCode(0);
    } finally {
        cleanupPiiSnapshotRows($user->id);
        $this->cleanupSeededUser($user);
    }
});

it('rejects a retention config below the 1-year floor and touches nothing', function () {
    config(['partna.audit.pii_retention_years' => 0]);

    $user = $this->seedAuthUser();

    try {
        seedUserDeletionAuditRow($user->id, 'stale@example.com', now()->subYears(8));

        $this->artisan('audit:prune-pii-snapshots')
            ->expectsOutputToContain('Retention window must be at least 1 year')
            ->assertExitCode(1);

        expect(DB::connection('pgsql')->table('audit.user_deletion_audit')
            ->where('professional_email_snapshot', 'stale@example.com')->exists())->toBeTrue();
    } finally {
        cleanupPiiSnapshotRows($user->id);
        $this->cleanupSeededUser($user);
    }
});

// The RPCs (audit.prune_user_deletion_audit / audit.prune_data_export_audit) only exist
// on real Postgres — SQLite has no equivalent function, no schema-wide REVOKE, and no
// SECURITY DEFINER concept. This lane's SchemaTestCase already guarantees a real pgsql
// connection with the migrations applied.
it('redacts only rows past the retention cutoff via the SECURITY DEFINER RPCs, keeping the event rows', function () {
    $user = $this->seedAuthUser();

    try {
        seedUserDeletionAuditRow($user->id, 'stale@example.com', now()->subYears(8));
        seedUserDeletionAuditRow($user->id, 'recent@example.com', now()->subYears(6));
        seedDataExportAuditRow($user->id, 'stale-recipient@example.com', now()->subYears(8));
        seedDataExportAuditRow($user->id, 'recent-recipient@example.com', now()->subYears(6));

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

        // Anonymise-in-place, not delete — every event row survives, scoped to this
        // test's own user (this lane is a shared, persistent database).
        expect(DB::connection('pgsql')->table('audit.user_deletion_audit')->where('user_id', $user->id)->count())->toBe(2);
        expect(DB::connection('pgsql')->table('audit.data_export_audit')->where('user_id', $user->id)->count())->toBe(2);
    } finally {
        cleanupPiiSnapshotRows($user->id);
        $this->cleanupSeededUser($user);
    }
});
