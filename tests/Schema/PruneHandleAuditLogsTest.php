<?php

// PRIV-2: retention prune for the append-only audit.handle_change_log table.
// The real deletion runs through the SECURITY DEFINER audit.prune_handle_change_log()
// RPC (20260718010000_handle_change_log_retention_prune.sql) because app_backend
// cannot DELETE from the audit schema directly. That RPC only exists on real
// Postgres, so this file lives in the applied-schema lane rather than behind a
// driver-gated skip.
//
// #COV-LANE-4: moved here (not a drift bug) — audit.handle_change_log.user_id
// FKs onto core.users (handle_change_log_user_id_fkey, ON DELETE SET NULL). The
// old SQLite fixture seeded every row with a fresh, unrelated Str::uuid() —
// SQLite's stand-in has no such FK, so it "just worked" there and every test
// failed against real Postgres, not only the one gated behind a pgsql check.
// Fixed with the standard SeedsAuthUsers seed-the-parent-row-first pattern.

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Schema\Concerns\SeedsAuthUsers;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class, SeedsAuthUsers::class)->in(__FILE__);

function seedHandleChangeLogRow(string $userId, string $handle, Carbon $changedAt): string
{
    $id = (string) Str::uuid();

    DB::connection('pgsql')->table('audit.handle_change_log')->insert([
        'id' => $id,
        'user_id' => $userId,
        'old_handle' => $handle.'-old',
        'new_handle' => $handle,
        'reason' => 'rename',
        'changed_at' => $changedAt->toDateTimeString(),
    ]);

    return $id;
}

/**
 * The table is append-only (handle_change_log_no_update BEFORE DELETE/UPDATE
 * trigger raises 42501 on any mutation) — the same reason the real prune path
 * runs through a SECURITY DEFINER RPC that disables the trigger for the
 * duration of its own DELETE. Test cleanup needs the identical bracket: this
 * lane has no RefreshDatabase, so leftover rows would otherwise accumulate in
 * the shared database across every run.
 */
function cleanupHandleChangeLogRows(string $userId): void
{
    DB::connection('pgsql')->statement('ALTER TABLE audit.handle_change_log DISABLE TRIGGER handle_change_log_no_update');
    DB::connection('pgsql')->table('audit.handle_change_log')->where('user_id', $userId)->delete();
    DB::connection('pgsql')->statement('ALTER TABLE audit.handle_change_log ENABLE TRIGGER handle_change_log_no_update');
}

it('dry-run reports the eligible count and deletes nothing', function () {
    $user = $this->seedAuthUser();

    try {
        seedHandleChangeLogRow($user->id, 'stale', now()->subYears(8));
        seedHandleChangeLogRow($user->id, 'recent', now()->subYears(6));

        $this->artisan('handles:prune-audit-logs', ['--dry-run' => true])
            ->expectsOutputToContain('Would delete 1 handle_change_log rows')
            ->assertExitCode(0);

        expect(DB::connection('pgsql')->table('audit.handle_change_log')->where('user_id', $user->id)->count())->toBe(2);
    } finally {
        // handle_change_log_user_id_fkey is ON DELETE SET NULL (not CASCADE),
        // so the log rows survive the user's forceDelete and are cleaned up
        // explicitly first.
        cleanupHandleChangeLogRows($user->id);
        $this->cleanupSeededUser($user);
    }
});

it('rejects a retention config below the 1-year floor and touches nothing', function () {
    config(['partna.handle.audit_retention_years' => 0]);

    $user = $this->seedAuthUser();

    try {
        seedHandleChangeLogRow($user->id, 'stale', now()->subYears(8));

        $this->artisan('handles:prune-audit-logs')
            ->expectsOutputToContain('Retention window must be at least 1 year')
            ->assertExitCode(1);

        expect(DB::connection('pgsql')->table('audit.handle_change_log')->where('user_id', $user->id)->count())->toBe(1);
    } finally {
        cleanupHandleChangeLogRows($user->id);
        $this->cleanupSeededUser($user);
    }
});

// The RPC (audit.prune_handle_change_log) only exists on real Postgres — SQLite
// has no equivalent function, no append-only trigger, and no SECURITY DEFINER
// concept. This lane's SchemaTestCase already guarantees a real pgsql
// connection with the migrations applied.
it('deletes only rows past the retention cutoff via the SECURITY DEFINER RPC', function () {
    $user = $this->seedAuthUser();

    try {
        seedHandleChangeLogRow($user->id, 'stale', now()->subYears(8));
        seedHandleChangeLogRow($user->id, 'recent', now()->subYears(6));

        $this->artisan('handles:prune-audit-logs')->assertExitCode(0);

        expect(DB::connection('pgsql')->table('audit.handle_change_log')->where('new_handle', 'stale')->where('user_id', $user->id)->exists())->toBeFalse();
        expect(DB::connection('pgsql')->table('audit.handle_change_log')->where('new_handle', 'recent')->where('user_id', $user->id)->exists())->toBeTrue();
    } finally {
        cleanupHandleChangeLogRows($user->id);
        $this->cleanupSeededUser($user);
    }
});
