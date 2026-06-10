<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// V2: Deletes raw analytics events older than retention window (min 30 days). Aggregate data preserved in hourly/daily tables.
class PurgeRawAnalyticsEvents extends Command
{
    protected $signature = 'partna:analytics:purge-raw-events
                            {--days= : Retain events newer than N days (default from config, minimum 30)}
                            {--dry-run : Report row counts without deleting}';

    protected $description = 'Delete raw analytics event rows older than the retention window. '
        .'Aggregate data is preserved in the hourly/daily tables. '
        .'Runs in batches to avoid long-running transactions.';

    private const BATCH_SIZE = 10_000;

    private const TABLES = [
        'analytics.link_clicks' => 'occurred_at',
        'analytics.site_visits' => 'occurred_at',
        'analytics.lead_submissions' => 'occurred_at',
        'analytics.section_views' => 'occurred_at',
        // Sessions age by last activity so a long-lived session isn't purged mid-flight.
        'analytics.site_sessions' => 'last_seen_at',
    ];

    public function handle(): int
    {
        $days = $this->retentionDays();

        if ($days === null) {
            return self::FAILURE;
        }

        $cutoff = now()->subDays($days)->toImmutable();
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

        $this->info(sprintf(
            '%s %d total rows.',
            $dryRun ? 'Would delete' : 'Deleted',
            $totalDeleted
        ));

        Log::info('partna:analytics:purge-raw-events completed', [
            'dry_run' => $dryRun,
            'days' => $days,
            'cutoff' => $cutoff->toIso8601String(),
            'total_rows' => $totalDeleted,
        ]);

        return self::SUCCESS;
    }

    private function retentionDays(): ?int
    {
        $raw = $this->option('days') ?? config('partna.analytics_raw_event_retention_days', 90);
        $days = (int) $raw;

        if ($days < 30) {
            $this->error(sprintf(
                'Retention window must be at least 30 days (got %d). '
                .'Set ANALYTICS_RAW_EVENT_RETENTION_DAYS or pass --days=N.',
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
        $deleted = 0;

        do {
            $count = DB::table($table)
                ->where($tsColumn, '<', $cutoff)
                ->limit(self::BATCH_SIZE)
                ->delete();

            $deleted += $count;
        } while ($count === self::BATCH_SIZE);

        return $deleted;
    }
}
