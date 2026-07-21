<?php

namespace App\Console\Commands;

use App\Jobs\Design\ResolveDesignPresetsJob;
use App\Models\Core\Site\DesignKitContribution;
use App\Models\Core\Site\Site;
use Illuminate\Console\Command;

/**
 * One-shot: dispatch a preset resolve for every user with an orphaned
 * `previous-website:styles` / `outside-websites:styles` contribution row —
 * the two sources PreviousWebsiteFactor/OutsideWebsitesFactor used to write,
 * now deregistered from DesignFactorRegistry.
 *
 * Doesn't delete anything itself: DesignPresetResolver::resolveForUser()
 * already deletes any contribution row whose `source` no longer matches a
 * registered factor, every time it runs for a user (D5,
 * PARTNA-SCRAPER-AND-DKIT-REWORKING-PLAN.md). This command just makes sure
 * every currently-affected user's resolve gets triggered promptly instead of
 * waiting on an unrelated future connection/sector-change/etc. to do it.
 *
 * Idempotent — safe to re-run. Once a user's stale rows are swept, they no
 * longer match the query and are skipped on the next run.
 *
 * Run once after this deploy ships. See D5 and Task B0.7 in
 * PARTNA-SCRAPER-AND-DKIT-REWORKING-PLAN.md.
 */
class SweepStaleDesignKitContributionsCommand extends Command
{
    private const STALE_SOURCES = ['previous-website:styles', 'outside-websites:styles'];

    /** Spread dispatches across this many seconds per batch so a large affected
     *  population doesn't spike the 'default' queue all at once. */
    private const DISPATCH_SPREAD_SECONDS = 60;

    private const BATCH_SIZE = 200;

    protected $signature = 'partna:sweep-stale-design-kit-contributions
        {--dry-run : Show what would be dispatched without dispatching}';

    protected $description = 'Dispatch a preset resolve for every user with an orphaned previous-website/outside-websites design-kit contribution row. Idempotent — safe to re-run.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $siteIds = DesignKitContribution::query()
            ->whereIn('source', self::STALE_SOURCES)
            ->distinct()
            ->pluck('site_id');

        if ($siteIds->isEmpty()) {
            $this->info('No stale contribution rows found — nothing to sweep.');

            return self::SUCCESS;
        }

        $userIds = Site::query()
            ->whereIn('id', $siteIds)
            ->pluck('user_id', 'id')
            ->unique()
            ->values();

        $this->info(sprintf(
            '%d stale contribution row(s) across %d site(s) → %d distinct user(s) to resolve.',
            DesignKitContribution::query()->whereIn('source', self::STALE_SOURCES)->count(),
            $siteIds->count(),
            $userIds->count(),
        ));

        if ($dryRun) {
            $this->info('DRY RUN — no jobs dispatched.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($userIds->chunk(self::BATCH_SIZE) as $batch) {
            foreach ($batch->values() as $i => $userId) {
                $delaySeconds = (int) floor($i / self::BATCH_SIZE * self::DISPATCH_SPREAD_SECONDS);
                ResolveDesignPresetsJob::dispatch((string) $userId)->delay(now()->addSeconds($delaySeconds));
                $dispatched++;
            }
        }

        $this->info("Dispatched {$dispatched} ResolveDesignPresetsJob(s), spread over ".self::DISPATCH_SPREAD_SECONDS.'s per batch of '.self::BATCH_SIZE.'.');

        return self::SUCCESS;
    }
}
