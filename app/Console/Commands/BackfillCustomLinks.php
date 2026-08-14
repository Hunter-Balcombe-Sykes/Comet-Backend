<?php

namespace App\Console\Commands;

use App\Services\Migration\CustomLinkBackfiller;
use Illuminate\Console\Command;

/**
 * Convergence Phase 3 / W5: carry the live `partna.custom_link` connections
 * onto content.* as `link` items. Idempotent on the URL-derived coord, so
 * re-running is safe — and is how links added after the first run land, until
 * Phase 6 moves the live write path itself. Read-only under --dry-run.
 */
class BackfillCustomLinks extends Command
{
    protected $signature = 'content:backfill-custom-links {--dry-run} {--user= : limit to one user id}';

    protected $description = 'Backfill custom-link connections into content.* through the manual lane';

    public function handle(CustomLinkBackfiller $backfiller): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $backfiller->run($dryRun, $this->option('user'));

        $this->line(($dryRun ? '[dry-run] would backfill ' : 'backfilled ').$result['backfilled']
            .', duplicate url '.$result['duplicate_url']
            .', skipped (no url) '.$result['skipped_no_url']
            .', skipped (no site) '.$result['skipped_no_site']
            .', failed '.$result['failed']);

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
