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
     * Floor on the raw-event retention window. Below it handle() aborts and
     * deletes NOTHING, so this is not a tunable — it is the line between "rows
     * are purged on a schedule" and "rows live forever". SitePolicyResolver
     * reads it for exactly that reason: the generated privacy policy may only
     * name a deletion window when the purge would actually run.
     */
    public const MINIMUM_RETENTION_DAYS = 30;

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
    // (scoresRetentionDays()), independent of --days and analytics_raw_event_retention_days.
    // A dormant site (no raw events in the last hour) is never rescanned by that command's
    // own recency window, so without this its rows would sit forever at their last
    // computed_at with no other purge path.
    private const DERIVED_TABLES = [
        'analytics.content_popularity_scores' => 'computed_at',
    ];

    public function handle(): int
    {
        $days = $this->retentionDays();

        if ($days === null) {
            return self::FAILURE;
        }

        $scoresDays = $this->scoresRetentionDays();

        if ($scoresDays === null) {
            return self::FAILURE;
        }

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
                : $this->purgeBatched($table, $tsColumn, $cutoff);

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
                : $this->purgeBatched($table, $tsColumn, $scoresCutoff);

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
            'total_rows' => $totalDeleted,
        ]);

        return self::SUCCESS;
    }

    private function retentionDays(): ?int
    {
        $raw = $this->option('days') ?? config('partna.analytics_raw_event_retention_days', 90);
        $days = (int) $raw;

        if ($days < self::MINIMUM_RETENTION_DAYS) {
            $this->error(sprintf(
                'Retention window must be at least %d days (got %d). '
                .'Set ANALYTICS_RAW_EVENT_RETENTION_DAYS or pass --days=N.',
                self::MINIMUM_RETENTION_DAYS,
                $days
            ));

            return null;
        }

        return $days;
    }

    // PRIV-4: content_popularity_scores' own retention window — independent of --days,
    // which only overrides the raw-event tables above.
    private function scoresRetentionDays(): ?int
    {
        $days = (int) config('partna.analytics.content_popularity_scores_retention_days', 180);

        if ($days < self::MINIMUM_RETENTION_DAYS) {
            $this->error(sprintf(
                'content_popularity_scores retention window must be at least %d days (got %d). '
                .'Set PARTNA_ANALYTICS_CONTENT_POPULARITY_SCORES_RETENTION_DAYS.',
                self::MINIMUM_RETENTION_DAYS,
                $days
            ));

            return null;
        }

        return $days;
    }

    private function countEligible(string $table, string $tsColumn, \DateTimeImmutable $cutoff): int
    {
        return (int) DB::table($table)
            ->where($tsColumn, '<', $cutoff)
            ->count();
    }

    private function purgeBatched(string $table, string $tsColumn, \DateTimeImmutable $cutoff): int
    {
        // CFG-1: batch size bounds each DELETE's row count so the purge never holds
        // one long-running transaction.
        $batchSize = (int) config('partna.analytics.purge_batch_size', 10_000);
        $deleted = 0;

        do {
            $count = DB::table($table)
                ->where($tsColumn, '<', $cutoff)
                ->limit($batchSize)
                ->delete();

            $deleted += $count;
        } while ($count === $batchSize);

        return $deleted;
    }
}
