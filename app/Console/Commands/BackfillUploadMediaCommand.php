<?php

namespace App\Console\Commands;

use App\Services\Migration\MediaUploadBackfiller;
use Illuminate\Console\Command;

/**
 * Artisan wrapper for the slice-1a upload backfill (spec §3.7). Idempotent,
 * re-runnable; the coord is the site_media uuid, so a second run updates the
 * item — the asset row is insert-once (a reprocessed variant's new dims
 * never reach content.media_assets; the resolver serves the variant row's
 * dims live, so no wire impact).
 */
class BackfillUploadMediaCommand extends Command
{
    protected $signature = 'content:backfill-upload-media
        {--dry-run : Report counts without writing}
        {--site= : Only this site id}';

    protected $description = 'Backfill live gallery/content uploads as media-kind content items';

    public function handle(MediaUploadBackfiller $backfiller): int
    {
        $dry = (bool) $this->option('dry-run');
        $result = $backfiller->run($dry, $this->option('site') ?: null);

        $verb = $dry ? 'would backfill' : 'backfilled';
        $this->info("Uploads: {$verb} {$result['backfilled']}, "
            ."skipped {$result['skipped_not_ready']} not-ready, "
            ."{$result['skipped_no_variant']} without a webp variant"
            .($result['failed'] > 0 ? ", {$result['failed']} FAILED" : '.'));

        if ($result['failed'] > 0) {
            $this->warn('Failures are reported to Nightwatch and logged with site_media ids. Fix and re-run — the lane is idempotent.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
