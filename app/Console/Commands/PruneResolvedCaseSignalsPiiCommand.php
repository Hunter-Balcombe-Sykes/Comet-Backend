<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DINT-6: Erase reporter PII on case_signals whose parent case has been resolved
 * for longer than the retention window — covering NON-account reporters.
 *
 * Background: AccountDeletionService::purgeCaseSignalPii() handles the deletion-time path.
 * This docblock previously claimed that path was "silent for anonymous reporters
 * (reporter_user_id IS NULL)" — the opposite of reality on both halves. Since
 * ContentReportService::submit() never writes reporter_user_id (the /v1/public/report
 * route is unauthenticated), EVERY signal is anonymous by that definition; and since
 * PRIV-3 that purge also matches on lower(trim(reporter_email)) against the deletion
 * audit's email snapshot, so it reaches an anonymous reporter whenever that reporter
 * happens to hold a Partna account being deleted.
 *
 * What that purge genuinely cannot reach is a reporter who has NO account to delete —
 * there is no deletion event to hang the erasure off, so their reporter_email /
 * reason_details / signal_data would accumulate indefinitely after a case closes. That
 * is this command's job: time-based retention, keyed off the case, not off a person.
 *
 * This command erases the same PII column set as purgeCaseSignalPii(), keyed off the
 * PARENT case's resolved_at rather than a user deletion event:
 *   Nulled  : reporter_email, reason_details, signal_data (reset to {})
 *   Kept    : reporter_ip_hash (one-way hash — not personally identifiable; retained for
 *             T&S dedup analytics), reason_code, signal_source, dedup_hash, case_id
 *
 * The signal row itself is preserved — it is moderation evidence.
 *
 * Prune criteria: parent case status IN ('resolved','auto_actioned') AND
 * cases.resolved_at < cutoff (default 90 days). Only signals with a non-null
 * reporter_email are targeted so re-runs are cheap no-ops.
 *
 * Note on whereIn scale: for a P3 weekly job on a T&S table expected to stay small,
 * a single whereIn is acceptable. If the resolved-case set grows large, chunk the
 * pluck + whereIn loop in future.
 */
class PruneResolvedCaseSignalsPiiCommand extends Command
{
    protected $signature = 'moderation:prune-resolved-signal-pii
                            {--days= : Override retention window (default from config partna.moderation.signal_pii_retention_days)}
                            {--dry-run : Report eligible signals without mutating anything}';

    protected $description = 'Null reporter PII (email, details, verbatim data) on case_signals whose parent '
        .'case resolved more than the retention window ago. Covers anonymous reporters — account reporters '
        .'are handled at deletion by AccountDeletionService::purgeCaseSignalPii().';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('partna.moderation.signal_pii_retention_days', 90));
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $this->line(sprintf(
            '%s reporter PII on signals from cases resolved more than %d days ago (cutoff: %s).',
            $dryRun ? '[DRY RUN] Would null' : 'Nulling',
            $days,
            $cutoff->toDateTimeString()
        ));

        // Resolve the set of closed cases outside the retention window first,
        // then operate on their signals. Two-step avoids UPDATE…FROM (not portable
        // to the SQLite test driver) and is safe for the expected table sizes.
        $caseIds = DB::connection('pgsql')
            ->table('moderation.cases')
            ->whereIn('status', ['resolved', 'auto_actioned'])
            ->where('resolved_at', '<', $cutoff->toDateTimeString())
            ->pluck('id');

        if ($caseIds->isEmpty()) {
            $this->info('No resolved cases outside the retention window — nothing to do.');

            Log::info('moderation.prune_resolved_signal_pii', [
                'updated' => 0,
                'cutoff' => $cutoff->toIso8601String(),
                'dry_run' => $dryRun,
            ]);

            return self::SUCCESS;
        }

        // Filter to signals that still carry PII (makes re-runs idempotent / cheap).
        $query = DB::connection('pgsql')
            ->table('moderation.case_signals')
            ->whereIn('case_id', $caseIds)
            ->whereNotNull('reporter_email');

        if ($dryRun) {
            $count = (clone $query)->count();
            $this->info("Would null reporter PII on {$count} signal(s).");

            Log::info('moderation.prune_resolved_signal_pii_dry_run', [
                'eligible' => $count,
                'cutoff' => $cutoff->toIso8601String(),
            ]);

            return self::SUCCESS;
        }

        // Mirror the column set from purgeCaseSignalPii(): reporter_email,
        // reason_details (freetext — may identify the reporter), signal_data
        // (verbatim report payload). reporter_ip_hash is a one-way hash and
        // is deliberately kept for T&S dedup analytics.
        $updated = $query->update([
            'reporter_email' => null,
            'reason_details' => null,
            'signal_data' => '{}',
        ]);

        $this->info("Nulled reporter PII on {$updated} signal(s).");

        Log::info('moderation.prune_resolved_signal_pii', [
            'updated' => $updated,
            'cutoff' => $cutoff->toIso8601String(),
            'dry_run' => false,
        ]);

        return self::SUCCESS;
    }
}
