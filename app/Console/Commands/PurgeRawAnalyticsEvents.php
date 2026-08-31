<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// V2: Deletes raw analytics events older than retention window (min 30 days).
// PRIV-4: also purges stale analytics.content_popularity_scores rows on their OWN
// retention window — see DERIVED_TABLES below.
class PurgeRawAnalyticsEvents extends Command
{
    protected $signature = 'partna:analytics:purge-raw-events
                            {--days= : Retain raw events newer than N days (default from config, minimum 30)}
                            {--dry-run : Report row counts without deleting}';

    protected $description = 'Delete raw analytics event rows and stale derived content_popularity_scores '
        .'rows older than their retention windows. Runs in batches to avoid long-running transactions.';

    /**
     * Floor on BOTH retention windows — raw events and the derived scores
     * table. Below it on either one the run aborts and deletes NOTHING (not
     * just the offending table), so this is not a tunable: it is the line
     * between "rows are purged on a schedule" and "rows live forever".
     */
    public const MINIMUM_RETENTION_DAYS = 30;

    /**
     * Floor on the batch size. Zero is the value that made this a THIRD way to
     * switch the purge off with one env var, and it is reachable by accident:
     * config casts with (int), so `PARTNA_ANALYTICS_PURGE_BATCH_SIZE=` (blank,
     * or any non-numeric string) is 0, and LIMIT 0 deletes nothing forever
     * while the command still exits 0 and the policy still promised deletion.
     * A negative value is the mirror-image failure — Laravel's limit() drops
     * values below zero, so the DELETE loses its bound entirely and takes the
     * whole table in one transaction, which is the exact thing batching exists
     * to prevent.
     */
    public const MINIMUM_PURGE_BATCH_SIZE = 1;

    private const TABLES = [
        'analytics.link_clicks' => 'occurred_at',
        'analytics.site_visits' => 'occurred_at',
        'analytics.lead_submissions' => 'occurred_at',
        'analytics.section_views' => 'occurred_at',
        'analytics.item_views' => 'occurred_at',
        'analytics.action_events' => 'occurred_at',
        // Sessions age by last activity so a long-lived session isn't purged mid-flight.
        'analytics.site_sessions' => 'last_seen_at',
    ];

    // PRIV-4: content_popularity_scores is a DERIVED scores table (ComputeContentPopularityScores
    // upserts a row per site/content-key every run), not a raw event log — it ages on
    // computed_at (there is no occurred_at column) and uses its OWN retention config/floor
    // (configuredScoresRetentionDays()), independent of --days and analytics_raw_event_retention_days.
    // A dormant site (no raw events in the last hour) is never rescanned by that command's
    // own recency window, so without this its rows would sit forever at their last
    // computed_at with no other purge path.
    private const DERIVED_TABLES = [
        'analytics.content_popularity_scores' => 'computed_at',
    ];

    public function handle(): int
    {
        // ONE evaluation of the guard set, shared with the policy generator —
        // see blockingMisconfigurations().
        $blockers = self::blockingMisconfigurations($this->daysOverride());

        if ($blockers !== []) {
            foreach ($blockers as $blocker) {
                $this->error($blocker);
            }

            return self::FAILURE;
        }

        $days = $this->daysOverride() ?? self::configuredRawRetentionDays();
        $scoresDays = self::configuredScoresRetentionDays();
        $batchSize = self::configuredPurgeBatchSize();

        $cutoff = now()->subDays($days)->toImmutable();
        $scoresCutoff = now()->subDays($scoresDays)->toImmutable();
        $dryRun = (bool) $this->option('dry-run');

        $this->line(sprintf(
            '%s raw analytics events older than %d days (cutoff: %s).',
            $dryRun ? '[DRY RUN] Would delete' : 'Purging',
            $days,
            $cutoff->toDateTimeString()
        ));

        $totalDeleted = 0;

        foreach (self::TABLES as $table => $tsColumn) {
            $deleted = $dryRun
                ? $this->countEligible($table, $tsColumn, $cutoff)
                : $this->purgeBatched($table, $tsColumn, $cutoff, $batchSize);

            $this->line(sprintf('  %-45s %s %d rows', $table, $dryRun ? 'eligible:' : 'deleted:', $deleted));
            $totalDeleted += $deleted;
        }

        $this->line(sprintf(
            '%s stale content_popularity_scores rows older than %d days (cutoff: %s).',
            $dryRun ? '[DRY RUN] Would delete' : 'Purging',
            $scoresDays,
            $scoresCutoff->toDateTimeString()
        ));

        foreach (self::DERIVED_TABLES as $table => $tsColumn) {
            $deleted = $dryRun
                ? $this->countEligible($table, $tsColumn, $scoresCutoff)
                : $this->purgeBatched($table, $tsColumn, $scoresCutoff, $batchSize);

            $this->line(sprintf('  %-45s %s %d rows', $table, $dryRun ? 'eligible:' : 'deleted:', $deleted));
            $totalDeleted += $deleted;
        }

        $this->info(sprintf(
            '%s %d total rows.',
            $dryRun ? 'Would delete' : 'Deleted',
            $totalDeleted
        ));

        Log::info('partna:analytics:purge-raw-events completed', [
            'dry_run' => $dryRun,
            'days' => $days,
            'cutoff' => $cutoff->toIso8601String(),
            'content_popularity_scores_days' => $scoresDays,
            'content_popularity_scores_cutoff' => $scoresCutoff->toIso8601String(),
            'batch_size' => $batchSize,
            'total_rows' => $totalDeleted,
        ]);

        return self::SUCCESS;
    }

    /**
     * EVERY configuration in which this command deletes nothing, as operator-
     * facing messages. Empty list = a run would actually delete.
     *
     * This exists because it has exactly two readers and they must never
     * disagree: handle() aborts on a non-empty list, and SitePolicyResolver
     * asks the same question through scheduledPurgeWouldDelete() before it
     * publishes a deletion window under a real business's name. The bug this
     * shape replaces was the second reader keeping its own copy of the rules
     * and falling behind them — first the scores floor, then the batch size,
     * each one an env var that silently turned the purge off while the
     * published policy went on promising automatic deletion. A guard added
     * here is a guard the policy already knows about; a guard added anywhere
     * else is the same bug a third time.
     *
     * @param  int|null  $daysOverride  the --days value for an operator run; null asks
     *                                  about the SCHEDULED run, which is what the policy cares about.
     * @return list<string>
     */
    public static function blockingMisconfigurations(?int $daysOverride = null): array
    {
        $blockers = [];

        $rawDays = $daysOverride ?? self::configuredRawRetentionDays();

        if ($rawDays < self::MINIMUM_RETENTION_DAYS) {
            $blockers[] = sprintf(
                'Retention window must be at least %d days (got %d). '
                .'Set ANALYTICS_RAW_EVENT_RETENTION_DAYS or pass --days=N.',
                self::MINIMUM_RETENTION_DAYS,
                $rawDays
            );
        }

        if (self::configuredScoresRetentionDays() < self::MINIMUM_RETENTION_DAYS) {
            $blockers[] = sprintf(
                'content_popularity_scores retention window must be at least %d days (got %d). '
                .'Set PARTNA_ANALYTICS_CONTENT_POPULARITY_SCORES_RETENTION_DAYS.',
                self::MINIMUM_RETENTION_DAYS,
                self::configuredScoresRetentionDays()
            );
        }

        if (self::configuredPurgeBatchSize() < self::MINIMUM_PURGE_BATCH_SIZE) {
            $blockers[] = sprintf(
                'Purge batch size must be at least %d (got %d). '
                .'Set PARTNA_ANALYTICS_PURGE_BATCH_SIZE.',
                self::MINIMUM_PURGE_BATCH_SIZE,
                self::configuredPurgeBatchSize()
            );
        }

        return $blockers;
    }

    /**
     * The one question anybody outside this command actually needs answered:
     * would the scheduled run delete anything? Derived from the guard set, not
     * from a mirrored copy of it.
     */
    public static function scheduledPurgeWouldDelete(): bool
    {
        return self::blockingMisconfigurations() === [];
    }

    public static function configuredRawRetentionDays(): int
    {
        return (int) config('partna.analytics_raw_event_retention_days', 90);
    }

    public static function configuredScoresRetentionDays(): int
    {
        return (int) config('partna.analytics.content_popularity_scores_retention_days', 180);
    }

    // CFG-1: batch size bounds each DELETE's row count so the purge never holds
    // one long-running transaction.
    public static function configuredPurgeBatchSize(): int
    {
        return (int) config('partna.analytics.purge_batch_size', 10_000);
    }

    private function daysOverride(): ?int
    {
        $raw = $this->option('days');

        return $raw !== null ? (int) $raw : null;
    }

    private function countEligible(string $table, string $tsColumn, \DateTimeImmutable $cutoff): int
    {
        return (int) DB::table($table)
            ->where($tsColumn, '<', $cutoff)
            ->count();
    }

    private function purgeBatched(string $table, string $tsColumn, \DateTimeImmutable $cutoff, int $batchSize): int
    {
        $deleted = 0;

        do {
            $count = DB::table($table)
                ->where($tsColumn, '<', $cutoff)
                ->limit($batchSize)
                ->delete();

            $deleted += $count;
            // Loop on PROGRESS, not on "the batch came back full". The old
            // condition ($count === $batchSize) spun forever the moment
            // $batchSize was 0, because a LIMIT 0 delete returns 0 and 0 === 0
            // is true — a hang, not a crash, so nothing ever surfaced it.
            // blockingMisconfigurations() now rejects that config before we get
            // here; this stays as the structural reason it can never hang again.
        } while ($count > 0);

        return $deleted;
    }
}
