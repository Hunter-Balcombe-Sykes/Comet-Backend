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
 * fresh scan would — UNLESS #LIFE-9's claim guard has already seen that exact
 * (user, kind, content) pair. ScanPreviousWebsiteContentJob::claimSubJobDispatch()
 * refuses (and now logs, `website_scan.subjob_dispatch_already_claimed`) any
 * sub-job dispatch identical to one already claimed within `horizon.trim.failed`
 * minutes (default 7 days) of the earlier run. So: a re-run OUTSIDE that window,
 * or one that finds genuinely new content (a changed page, a new PDF), still
 * re-bills as described above and remains the operator's fleet-wide re-scan
 * escape hatch — budget accordingly. A re-run INSIDE the window against
 * unchanged content instead SKIPS those already-claimed sub-job dispatches
 * silently as far as this command's own "Dispatched N …" count is concerned
 * (that count reflects ScanPreviousWebsiteContentJob dispatches, not sub-job
 * dispatches) — check the log line above to see what was actually refused.
 * Re-billing a fleet-wide re-scan of unchanged content INSIDE that window is
 * therefore an owner decision with no mechanism today: there is deliberately
 * no bypass flag here, because the claim guard exists to only ever REFUSE
 * spend, never to be re-enabled on demand. ScanPreviousWebsiteContentJob is
 * also ShouldBeUnique per user (300s), so a duplicate dispatch of the PARENT
 * job within that window is blocked independently of the above.
 *
 * Rate-limited via a delay spread across the affected population so this
 * doesn't spike the 'scraping' queue on a large install base. The stagger is
 * a WHOLE-RUN rate (seconds between consecutive dispatches), not a per-batch
 * window — it does not reset partway through, so run length grows linearly
 * with the population instead of collapsing spacing as N grows.
 *
 * --limit caps rows processed THIS RUN — it is a cap, not a cursor. There is
 * no "already scanned" filter, so a second capped run re-picks the same
 * site_id-ordered leading rows and re-bills them, same as any other re-run.
 *
 * ⚠️ uniqueFor ceiling: ScanPreviousWebsiteContentJob's own ShouldBeUnique
 * lock (uniqueId "{userId}:website-content-scan") lasts 300s. Past roughly
 * N * stagger >= 300s, a job's delay outlives its own unique lock, which
 * expires before the job ever runs — so a user editing their site mid-run
 * is no longer blocked by that lock and can trigger a second, duplicate-
 * billing scan via WorkplaceObserver's own trigger. Accepted by the owner;
 * size --limit so a large first run keeps N * stagger under 300s.
 *
 * Run once after this deploys. See Task A4.11 in
 * PARTNA-SCRAPER-AND-DKIT-REWORKING-PLAN.md — that document is not present
 * in this repo; kept for provenance, not as a live reference.
 */
class BackfillPreviousWebsiteContentScanCommand extends Command
{
    /** Seconds between consecutive dispatches. A whole-RUN rate, not a per-batch window — it paces arrivals at the billed scraping queue, so it must not reset. */
    private const DISPATCH_STAGGER_SECONDS = 2;

    protected $signature = 'partna:backfill-previous-website-content-scan
        {--dry-run : Show what would be dispatched without dispatching}
        {--limit=0 : Cap rows processed this run (0 = no cap)}
        {--stagger-seconds= : Seconds between consecutive dispatches (default: 2)}';

    protected $description = 'Dispatch ScanPreviousWebsiteContentJob for every existing Workplace with a previous_website already set. Idempotent — safe to re-run.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $stagger = $this->option('stagger-seconds') !== null
            ? (int) $this->option('stagger-seconds')
            : self::DISPATCH_STAGGER_SECONDS;

        // Reject rather than clamp: a negative would otherwise flow through the
        // `$delaySeconds > 0` guard below and silently become 0 for every job —
        // the entire matched population dispatching at once onto the billed
        // scraping queue. 0 itself stays legitimate (an operator's deliberate
        // "no stagger" choice, same as RefreshIntegrationConnectionsCommand's
        // explicit <= 0 branch); only a negative is unambiguously a typo.
        if ($stagger < 0) {
            $this->error('--stagger-seconds cannot be negative. Use 0 for no stagger — but note that dispatches the entire matched population at once onto the billed scraping queue.');

            return self::FAILURE;
        }

        $query = Workplace::query()
            ->whereNotNull('previous_website')
            ->where('previous_website', '!=', '')
            ->with('site')
            ->orderBy('site_id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $rows = $query->get(['site_id', 'previous_website']);

        if ($rows->isEmpty()) {
            $this->info('No workplaces with a previous_website found — nothing to backfill.');

            return self::SUCCESS;
        }

        $this->info("{$rows->count()} workplace(s) with a previous_website to scan.");

        if ($dryRun) {
            // "Up to" is load-bearing: dry-run can't know in advance how many
            // rows will be skipped for a null site, so it can only project an
            // upper bound on the dispatched count and the resulting spread.
            $lastDelay = max(0, ($rows->count() - 1) * $stagger);
            $this->info("Up to {$rows->count()} job(s) would be staggered {$stagger}s apart, the last landing {$lastDelay}s from now.");
            $this->info('DRY RUN — no jobs dispatched. Note: a real run re-bills Mistral OCR / MenuAiExtractor for every site with a menu-relevant PDF or HTML menu, even ones already scanned.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        foreach ($rows as $workplace) {
            $site = $workplace->site;
            if ($site === null) {
                continue;
            }

            // Read before increment so job #1 (dispatched === 0) gets delay 0.
            $delaySeconds = $dispatched * $stagger;
            $pending = ScanPreviousWebsiteContentJob::dispatch(
                (string) $site->user_id,
                (string) $workplace->site_id,
                trim((string) $workplace->previous_website),
            );
            if ($delaySeconds > 0) {
                $pending->delay(now()->addSeconds($delaySeconds));
            }
            $dispatched++;
        }

        $lastDelay = max(0, ($dispatched - 1) * $stagger);
        $this->info("Dispatched {$dispatched} ScanPreviousWebsiteContentJob(s), staggered {$stagger}s apart across the whole run — the last lands {$lastDelay}s from now.");

        return self::SUCCESS;
    }
}
