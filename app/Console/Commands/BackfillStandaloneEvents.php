<?php

namespace App\Console\Commands;

use App\Services\Migration\StandaloneEventBackfiller;
use Illuminate\Console\Command;

/**
 * Slice 7 Phase 4 / parent §7 step 1: carry the live standalone
 * (`resource_kind='event'`) connections onto content.* as `event` items.
 * Idempotent on the URL-derived coord, so re-running is safe — and is how an
 * event added through the per-platform `POST /platforms/{platform}/events`
 * verb lands, until Phase 6 retires that surface. Read-only under --dry-run.
 */
class BackfillStandaloneEvents extends Command
{
    protected $signature = 'content:backfill-standalone-events {--dry-run} {--user= : limit to one user id}';

    protected $description = 'Backfill standalone event connections into content.* through the manual lane';

    public function handle(StandaloneEventBackfiller $backfiller): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $backfiller->run($dryRun, $this->option('user'));

        // already_curated is the re-run signal: on a second pass every event is
        // written again (the coord is stable) but its curation is left alone,
        // so "backfilled 2, already curated 2" is what a healthy no-op looks
        // like. Without it the two runs would print identically.
        $this->line(($dryRun ? '[dry-run] would backfill ' : 'backfilled ').$result['backfilled']
            .', duplicate url '.$result['duplicate_url']
            .', skipped (no url) '.$result['skipped_no_url']
            .', skipped (no site) '.$result['skipped_no_site']
            .', already curated '.$result['already_curated']
            .', failed '.$result['failed']);

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
