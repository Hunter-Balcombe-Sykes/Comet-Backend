<?php

namespace App\Console\Commands;

use App\Services\Migration\MenuBackfiller;
use Illuminate\Console\Command;

class BackfillMenus extends Command
{
    protected $signature = 'content:backfill-menus
        {--dry-run : Report what would be written without writing}
        {--user= : Only this user id}';

    protected $description = 'Backfill site.menu_items into content.* as menu_item items';

    public function handle(MenuBackfiller $backfiller): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $backfiller->run($dryRun, $this->option('user'));

        // Every counter prints, including the zero ones. The coord is stable,
        // so a healthy re-run writes every dish again and mints nothing — and
        // the only way to see that from the output is for the numbers to be
        // identical across two runs. A counter omitted because it is usually
        // zero is the one that hides a regression (Phase 3 shipped that bug
        // and had to fix it after the fact).
        $this->line(($dryRun ? '[dry-run] would backfill ' : 'backfilled ').$result['backfilled']
            .', duplicate name '.$result['duplicate_name']
            .', skipped (no site) '.$result['skipped_no_site']
            .', skipped (no name) '.$result['skipped_no_name']
            .', failed '.$result['failed']);

        $this->line('  slugs: migrated '.$result['slugs_migrated']
            .', collided '.$result['slugs_collided']
            .', unmapped '.$result['slugs_unmapped']
            .' | storefronts '.$result['storefronts']);

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
