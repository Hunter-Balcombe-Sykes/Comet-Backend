<?php

namespace App\Console\Commands;

use App\Services\Migration\ServiceBackfiller;
use Illuminate\Console\Command;

/**
 * Slice 3a §3.1: carry the owner-authored site.services rows onto content.*.
 * Idempotent on the coord, so re-running is safe. Read-only under --dry-run.
 */
class BackfillOwnerServices extends Command
{
    protected $signature = 'content:backfill-owner-services {--dry-run} {--user= : limit to one user id}';

    protected $description = 'Backfill owner-authored services into content.* through the manual lane';

    public function handle(ServiceBackfiller $backfiller): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $backfiller->run($dryRun, $this->option('user'));

        $this->line(($dryRun ? '[dry-run] would backfill ' : 'backfilled ').$result['backfilled']
            .', retired '.$result['retired']
            .', skipped (no user) '.$result['skipped_no_user']
            .', failed '.$result['failed']);

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
