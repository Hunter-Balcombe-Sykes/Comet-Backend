<?php

namespace App\Console\Commands;

use App\Services\Migration\ContentSelectionMigrator;
use Illuminate\Console\Command;

/**
 * Artisan wrapper for the slice-1b selection migration (spec D10). Idempotent,
 * re-runnable, additive — nothing is deleted from `site.content_selection`,
 * which slice 7 drops.
 *
 * The dropped counts and their site ids are printed rather than merely
 * returned: D10 requires the decision not to carry those rows to be ON THE
 * RECORD with whose rows on whose sites, and this output is that record.
 */
class MigrateContentSelectionCommand extends Command
{
    protected $signature = 'content:migrate-selection
        {--dry-run : Report what would change without writing}
        {--site= : Limit to one site id}';

    protected $description = 'Carry legacy content_selection upload picks into the media pool; record the rest as dropped';

    public function handle(ContentSelectionMigrator $migrator): int
    {
        $dry = (bool) $this->option('dry-run');
        $result = $migrator->run($dry, $this->option('site') ?: null);

        $verb = $dry ? 'would migrate' : 'migrated';
        $this->info("Uploads: {$verb} {$result['migrated']}");
        $this->line("Dropped (google-photo):   {$result['dropped_google']}");
        $this->line("Dropped (ig-post/ig-reel): {$result['dropped_ig']}");
        $this->line("Skipped (no backfilled item): {$result['skipped_no_item']}");

        if ($result['dropped_site_ids'] !== []) {
            $this->newLine();
            $this->warn('Dropped rows belonged to '.count($result['dropped_site_ids']).' site(s). '
                .'These are NOT deleted — site.content_selection is dropped in slice 7. Site ids:');
            foreach ($result['dropped_site_ids'] as $siteId) {
                $this->line("  {$siteId}");
            }
        }

        if ($result['skipped_no_item'] > 0) {
            $this->newLine();
            $this->warn('An upload selection with no backfilled item usually means the upload is not in a '
                ."'ready' processing_state — content:backfill-upload-media counts those as skipped. "
                .'Check its output before assuming the pick is lost.');
        }

        if ($result['failed'] > 0) {
            $this->newLine();
            $this->error("{$result['failed']} row(s) FAILED — reported to Nightwatch and logged with their ids. "
                .'Fix and re-run; the lane is idempotent.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
