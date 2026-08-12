<?php

namespace App\Console\Commands;

use App\Services\Migration\ShopBackfiller;
use Illuminate\Console\Command;

/** Artisan wrapper for the slice-5a shop backfill (spec §3.4). Idempotent. */
class BackfillShopContentCommand extends Command
{
    protected $signature = 'content:backfill-shop
        {--dry-run : Report counts without writing}
        {--user= : Only this user id}';

    protected $description = 'Backfill site.shop_brands / shop_products into content.*';

    public function handle(ShopBackfiller $backfiller): int
    {
        $dry = (bool) $this->option('dry-run');
        $r = $backfiller->run($dry, $this->option('user') ?: null);

        $verb = $dry ? 'would backfill' : 'backfilled';
        $this->info("Shop: {$verb} {$r['stores']} stores, {$r['products']} products"
            .($r['skipped_no_url'] > 0 ? ", skipped {$r['skipped_no_url']} without a url" : '')
            .($r['failed'] > 0 ? ", {$r['failed']} FAILED" : '.'));

        if ($r['failed'] > 0) {
            $this->warn('Failures are reported to Nightwatch and logged with brand ids. Fix and re-run — the lane is idempotent.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
