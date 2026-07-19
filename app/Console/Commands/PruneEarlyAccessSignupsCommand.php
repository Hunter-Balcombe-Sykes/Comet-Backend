<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PRIV-8: Enforce the early-access applicant PII retention window for
 * NON-converting applicants.
 *
 * Hard-deletes core.early_access_signups rows older than the retention window
 * whose status is not 'signed_up'. The CONVERTING case is handled on account
 * deletion by AccountDeletionService::purgeEarlyAccessSignup() — this command
 * covers the orthogonal case of applicants who never signed up.
 */
class PruneEarlyAccessSignupsCommand extends Command
{
    protected $signature = 'early-access:prune-old-signups
                            {--days= : Override retention window (default from config partna.early_access.retention_days)}
                            {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Hard-delete non-converting core.early_access_signups rows older than the retention window. '
        .'Removes stale applicant PII (email, consent fields) the platform no longer has a basis to keep.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('partna.early_access.retention_days', 730));
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $this->line(sprintf(
            '%s early access signups older than %d days (cutoff: %s).',
            $dryRun ? '[DRY RUN] Would delete' : 'Deleting',
            $days,
            $cutoff->toDateTimeString()
        ));

        // status != 'signed_up' — converted applicants are governed by account
        // deletion, not retention. created_at is the only age column on this table
        // (there is no last_submitted_at equivalent).
        $query = DB::connection('pgsql')
            ->table('core.early_access_signups')
            ->where('status', '!=', 'signed_up')
            ->where('created_at', '<', $cutoff->toDateTimeString());

        if ($dryRun) {
            $count = (clone $query)->count();
            $this->info("Would delete {$count} early access signup(s).");

            Log::info('early_access.prune_old_signups_dry_run', [
                'eligible' => $count,
                'cutoff' => $cutoff->toIso8601String(),
            ]);

            return self::SUCCESS;
        }

        // Bulk delete — no per-row side-effects; the converting-applicant path
        // (AccountDeletionService::purgeEarlyAccessSignup) uses email_lc lookup and
        // is independent. No model observer on this table, so no events fire.
        $deleted = $query->delete();

        $this->info("Deleted {$deleted} early access signup(s).");

        Log::info('early_access.prune_old_signups', [
            'deleted' => $deleted,
            'cutoff' => $cutoff->toIso8601String(),
        ]);

        return self::SUCCESS;
    }
}
