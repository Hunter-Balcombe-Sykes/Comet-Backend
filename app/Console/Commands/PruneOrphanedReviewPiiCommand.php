<?php

namespace App\Console\Commands;

use App\Site\Documents\BuildState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * #PRIV-3: hard-delete content.f_review rows (author_name, author_photo_url,
 * verbatim text — third-party reviewer PII) once the review is no longer
 * carried by any LIVE content.source_items row for its (item_id, source_id)
 * pair, i.e. the reviewer deleted it upstream or the platform stopped
 * surfacing it, on a connection that is STILL connected.
 *
 * Deliberately NOT a TTL over live data. ingest:dispatch re-projects every
 * 15 minutes from ingest.record_versions and would simply re-insert an
 * unchanged live review on its next run — an age-based rule over live rows
 * never terminates. The only terminating condition is orphan status: no
 * live source_items row still points at this (item_id, source_id) pair.
 *
 * Grace window (default 14d, partna.ingest.review_pii_orphan_grace_days)
 * absorbs a transient projection failure: a run that fetched zero records
 * retires (source_items.removed_at) every source_item for the stream, and
 * without the grace window a real review that reappears on the very next
 * successful run would already have been erased.
 *
 * Account-close erasure of the same PII is already handled by the
 * item_id/source_id -> content.items/content.sources -> core.users CASCADE
 * chain (see AccountDeletionService::PURGED_PII_TABLES docblock) inside the
 * 30-day soft-delete retention window. This command is the separate
 * live-account path that cascade never reaches — the same pairing as
 * moderation:prune-resolved-signal-pii alongside
 * AccountDeletionService::purgeCaseSignalPii().
 *
 * Deliberately scoped to f_review ONLY, not all 14 singleton facets. Every
 * other facet leaks orphaned rows the same way once its source_item is
 * retired — that is a correctness defect (stale content), not a PII one,
 * and generalising this command would change ValueResolver inputs and
 * has_facet resolution fleet-wide in one step. File a follow-up; do not
 * bundle into this command.
 */
class PruneOrphanedReviewPiiCommand extends Command
{
    protected $signature = 'content:prune-orphaned-review-pii
                            {--days= : Override grace window (default from config partna.ingest.review_pii_orphan_grace_days)}
                            {--dry-run : Report eligible rows without mutating anything}';

    protected $description = 'Hard-delete content.f_review rows (third-party reviewer PII) whose review is '
        .'no longer carried by any live content.source_items row, past a grace window.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('partna.ingest.review_pii_orphan_grace_days', 14));
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $this->line(sprintf(
            '%s orphaned reviewer PII on content.f_review rows untouched since before %s.',
            $dryRun ? '[DRY RUN] Would delete' : 'Deleting',
            $cutoff->toDateTimeString()
        ));

        $eligible = fn () => DB::connection('pgsql')
            ->table('content.f_review')
            ->where('content.f_review.updated_at', '<', $cutoff->toDateTimeString())
            ->whereNotExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('content.source_items')
                ->whereColumn('content.source_items.item_id', 'content.f_review.item_id')
                ->whereColumn('content.source_items.source_id', 'content.f_review.source_id')
                ->whereNull('content.source_items.removed_at'));

        $count = $eligible()->count();

        if ($count === 0) {
            $this->info('No orphaned reviewer PII past the grace window — nothing to do.');

            Log::info('content.prune_orphaned_review_pii', [
                'deleted' => 0,
                'cutoff' => $cutoff->toIso8601String(),
                'dry_run' => $dryRun,
            ]);

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("Would delete {$count} orphaned review row(s).");

            Log::info('content.prune_orphaned_review_pii_dry_run', [
                'eligible' => $count,
                'cutoff' => $cutoff->toIso8601String(),
            ]);

            return self::SUCCESS;
        }

        // Resolve affected sites BEFORE deleting — once the f_review rows are
        // gone there is no path from (item_id, source_id) back to a site_id.
        $siteIds = $eligible()
            ->join('content.items', 'content.items.id', '=', 'content.f_review.item_id')
            ->join('site.sites', 'site.sites.user_id', '=', 'content.items.user_id')
            ->distinct()
            ->pluck('site.sites.id');

        $deleted = $eligible()->delete();

        // MANDATORY: without this, the published document keeps rendering a
        // review whose PII was just deleted from the database — the worst of
        // both a leak and a broken page. DocumentBuilder's has_facet
        // predicate is a bare EXISTS against content.f_review with no
        // removed_at check, so the row's disappearance must be signalled
        // explicitly via BuildState, exactly as ProjectionWriter::bumpSite()
        // does for every other raw-write seam.
        foreach ($siteIds as $siteId) {
            BuildState::bump((string) $siteId);
        }

        $this->info("Deleted {$deleted} orphaned review row(s), bumped {$siteIds->count()} site(s).");

        Log::info('content.prune_orphaned_review_pii', [
            'deleted' => $deleted,
            'sites_bumped' => $siteIds->count(),
            'cutoff' => $cutoff->toIso8601String(),
            'dry_run' => false,
        ]);

        return self::SUCCESS;
    }
}
