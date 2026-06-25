<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PRIV-8: Enforce the waitlist applicant PII retention window for NON-converting applicants.
 *
 * Hard-deletes core.waitlist_signups rows older than the retention window. The CONVERTING
 * applicant case (user signs up, then deletes their account) is handled separately by
 * AccountDeletionService::purgeWaitlistSignup() — this command covers the orthogonal case
 * of applicants who never converted and whose rows accumulate indefinitely.
 *
 * The whole row is deleted (not nulled) because every column is applicant PII (name, email,
 * phone, industry, consent fingerprints). There is no skeleton worth keeping once the
 * retention window has elapsed.
 */
class PruneWaitlistSignupsCommand extends Command
{
    protected $signature = 'waitlist:prune-old-signups
                            {--days= : Override retention window (default from config partna.waitlist.retention_days)}
                            {--dry-run : Report eligible rows without deleting anything}';

    protected $description = 'Hard-delete core.waitlist_signups rows older than the retention window. '
        .'Removes stale non-converting applicant PII (name, email, phone, consent fields) the platform no longer has a basis to keep.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('partna.waitlist.retention_days', 730));
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $this->line(sprintf(
            '%s waitlist signups older than %d days (cutoff: %s).',
            $dryRun ? '[DRY RUN] Would delete' : 'Deleting',
            $days,
            $cutoff->toDateTimeString()
        ));

        $query = DB::connection('pgsql')
            ->table('core.waitlist_signups')
            ->where('last_submitted_at', '<', $cutoff->toDateTimeString());

        if ($dryRun) {
            $count = (clone $query)->count();
            $this->info("Would delete {$count} waitlist signup(s).");

            Log::info('waitlist.prune_old_signups_dry_run', [
                'eligible' => $count,
                'cutoff' => $cutoff->toIso8601String(),
            ]);

            return self::SUCCESS;
        }

        // Bulk delete — no per-row side-effects; the converting-applicant path
        // (AccountDeletionService::purgeWaitlistSignup) uses email_lc lookup and is
        // independent. No model observer on this table, so bulk delete fires no events.
        $deleted = $query->delete();

        $this->info("Deleted {$deleted} waitlist signup(s).");

        Log::info('waitlist.prune_old_signups', [
            'deleted' => $deleted,
            'cutoff' => $cutoff->toIso8601String(),
        ]);

        return self::SUCCESS;
    }
}
