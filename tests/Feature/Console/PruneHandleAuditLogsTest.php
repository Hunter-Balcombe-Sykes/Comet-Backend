<?php

// PRIV-2: retention prune for the append-only audit.handle_change_log table.
// The real deletion runs through the SECURITY DEFINER audit.prune_handle_change_log()
// RPC (20260718010000_handle_change_log_retention_prune.sql) because app_backend
// cannot DELETE from the audit schema directly. That RPC doesn't exist on the
// SQLite test connection, so only the dry-run + retention-floor guard are
// exercised here; the real-delete path is gated behind a Postgres-only test.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupHandleChangeLogTable();
});

function seedHandleChangeLogRow(string $handle, \Carbon\Carbon $changedAt): void
{
    DB::connection('pgsql')->table('audit.handle_change_log')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => (string) Str::uuid(),
        'old_handle' => $handle.'-old',
        'new_handle' => $handle,
        'reason' => 'rename',
        'changed_at' => $changedAt->toDateTimeString(),
    ]);
}

it('dry-run reports the eligible count and deletes nothing', function () {
    seedHandleChangeLogRow('stale', now()->subYears(8));
    seedHandleChangeLogRow('recent', now()->subYears(6));

    $this->artisan('handles:prune-audit-logs', ['--dry-run' => true])
        ->expectsOutputToContain('Would delete 1 handle_change_log rows')
        ->assertExitCode(0);

    expect(DB::connection('pgsql')->table('audit.handle_change_log')->count())->toBe(2);
});

it('rejects a retention config below the 1-year floor and touches nothing', function () {
    config(['partna.handle.audit_retention_years' => 0]);

    seedHandleChangeLogRow('stale', now()->subYears(8));

    $this->artisan('handles:prune-audit-logs')
        ->expectsOutputToContain('Retention window must be at least 1 year')
        ->assertExitCode(1);

    expect(DB::connection('pgsql')->table('audit.handle_change_log')->count())->toBe(1);
});

// The RPC (audit.prune_handle_change_log) only exists on real Postgres — SQLite
// has no equivalent function, no append-only trigger, and no SECURITY DEFINER
// concept. Skips on CI's SQLite driver; run against a Supabase dev DB to verify
// the real delete path — see docs/migration-guidelines.md for how to point
// DB_CONNECTION at Postgres locally.
it('deletes only rows past the retention cutoff via the SECURITY DEFINER RPC', function () {
    if (DB::connection('pgsql')->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('audit.prune_handle_change_log() RPC requires a real PostgreSQL connection.');
    }

    seedHandleChangeLogRow('stale', now()->subYears(8));
    seedHandleChangeLogRow('recent', now()->subYears(6));

    $this->artisan('handles:prune-audit-logs')->assertExitCode(0);

    expect(DB::connection('pgsql')->table('audit.handle_change_log')->where('new_handle', 'stale')->exists())->toBeFalse();
    expect(DB::connection('pgsql')->table('audit.handle_change_log')->where('new_handle', 'recent')->exists())->toBeTrue();
});
