<?php

namespace App\Console\Commands;

use App\Jobs\Platforms\ScanPreviousWebsiteContentJob;
use App\Models\Core\Site\Workplace;
use Illuminate\Console\Command;

/**
 * One-shot: dispatch ScanPreviousWebsiteContentJob for every EXISTING
 * Workplace with a non-blank previous_website. WorkplaceObserver's new
 * previous_website-changed trigger (Task A4.11) only fires for a change
 * going forward — it does not retroactively scan the existing install base.
 *
 * Idempotent in the sense that every write ScanPreviousWebsiteContentJob makes
 * is fill-if-empty — a repeat scan for an already-scanned site never produces
 * duplicate rows. It is NOT a harmless no-op, though: a re-run re-dispatches
 * WebsiteMenuPdfScanJob for every site with a menu-relevant PDF and re-bills
 * Mistral OCR (and MenuAiExtractor for any HTML-menu fallback), same as a
 * fresh scan would. This is the operator's fleet-wide re-scan escape hatch —
 * budget accordingly. ScanPreviousWebsiteContentJob is also ShouldBeUnique
 * per user (300s), so a duplicate dispatch within that window is blocked.
 *
 * Rate-limited via a delay spread across the affected population so this
 * doesn't spike the 'scraping' queue on a large install base.
 *
 * Run once after this deploys. See Task A4.11 in
 * PARTNA-SCRAPER-AND-DKIT-REWORKING-PLAN.md.
 */
class BackfillPreviousWebsiteContentScanCommand extends Command
{
    /** Spread dispatches across this many seconds per batch. */
    private const DISPATCH_SPREAD_SECONDS = 300;

    private const BATCH_SIZE = 200;

    protected $signature = 'partna:backfill-previous-website-content-scan
        {--dry-run : Show what would be dispatched without dispatching}';

    protected $description = 'Dispatch ScanPreviousWebsiteContentJob for every existing Workplace with a previous_website already set. Idempotent — safe to re-run.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $rows = Workplace::query()
            ->whereNotNull('previous_website')
            ->where('previous_website', '!=', '')
            ->get(['site_id', 'previous_website']);

        if ($rows->isEmpty()) {
            $this->info('No workplaces with a previous_website found — nothing to backfill.');

            return self::SUCCESS;
        }

        $this->info("{$rows->count()} workplace(s) with a previous_website to scan.");

        if ($dryRun) {
            $this->info('DRY RUN — no jobs dispatched. Note: a real run re-bills Mistral OCR / MenuAiExtractor for every site with a menu-relevant PDF or HTML menu, even ones already scanned.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($rows->chunk(self::BATCH_SIZE) as $batch) {
            foreach ($batch->values() as $i => $workplace) {
                $site = $workplace->site;
                if ($site === null) {
                    continue;
                }
                $delaySeconds = (int) floor($i / self::BATCH_SIZE * self::DISPATCH_SPREAD_SECONDS);
                ScanPreviousWebsiteContentJob::dispatch(
                    (string) $site->user_id,
                    (string) $workplace->site_id,
                    trim((string) $workplace->previous_website),
                )->delay(now()->addSeconds($delaySeconds));
                $dispatched++;
            }
        }

        $this->info("Dispatched {$dispatched} ScanPreviousWebsiteContentJob(s), spread over ".self::DISPATCH_SPREAD_SECONDS.'s per batch of '.self::BATCH_SIZE.'.');

        return self::SUCCESS;
    }
}
