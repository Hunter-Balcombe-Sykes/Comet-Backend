<?php

namespace App\Console\Commands;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Site\Documents\BuildState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Slice 6 §2.3, part two. One-off purge of the two UNGOVERNED copies of a
 * reviewer's display name.
 *
 * GoogleBusinessReviewProjector used to set `headline` to the reviewer's
 * name. ProjectionWriter folds a non-empty headline into content.f_text and
 * resolves it into content.items.headline_cache, so the name existed in three
 * tables while only content.f_review was reached by Manifest::
 * $redactionScopes, content:prune-orphaned-review-pii and the DSAR omission.
 *
 * Task 2 stopped NEW copies. This removes the existing ones, and it is not
 * optional: the singleton-facet write path is upsert-only and never deletes, so the
 * f_text rows survive the projector change — and because headline_cache is
 * resolved FROM those rows, the name would keep being served forever.
 *
 * Deliberately does NOT touch content.f_review. That is the copy the platform
 * is entitled to hold for a claimed account and is what renders attribution;
 * deleting it is content:prune-orphaned-review-pii's job, on its own orphan
 * rule and 14-day grace window.
 *
 * One-off by intent, but idempotent and safe to re-run — a second run finds
 * nothing because Task 2 stopped the source.
 */
class PurgeReviewHeadlinePiiCommand extends Command
{
    protected $signature = 'content:purge-review-headline-pii
                            {--dry-run : Report affected rows without mutating anything}';

    protected $description = 'Delete reviewer display names from content.f_text and content.items.headline_cache '
        .'for review-kind items (slice 6 §2.3 — the copies outside redaction, pruning and DSAR).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $itemIds = DB::connection('pgsql')->table('content.items')
            ->where('kind', 'review')
            ->whereNotNull('headline_cache')
            ->pluck('id');

        $textIds = DB::connection('pgsql')->table('content.f_text')
            ->join('content.items', 'content.items.id', '=', 'content.f_text.item_id')
            ->where('content.items.kind', 'review')
            ->pluck('content.f_text.item_id');

        $affected = $itemIds->merge($textIds)->unique()->values();

        if ($affected->isEmpty()) {
            $this->info('No reviewer PII on review headlines — nothing to do.');
            Log::info('content.purge_review_headline_pii', ['affected' => 0, 'dry_run' => $dryRun]);

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("Would clear {$affected->count()} review item(s).");
            Log::info('content.purge_review_headline_pii_dry_run', ['affected' => $affected->count()]);

            return self::SUCCESS;
        }

        // Resolve sites BEFORE mutating — the join runs off content.items,
        // which survives, but doing it first keeps the ordering identical to
        // PruneOrphanedReviewPiiCommand's and avoids a second decision later.
        // Subdomain comes along because the edge purge is keyed by handle, not
        // by site id.
        $sites = DB::connection('pgsql')->table('content.items')
            ->join('site.sites', 'site.sites.user_id', '=', 'content.items.user_id')
            ->whereIn('content.items.id', $affected)
            ->distinct()
            ->get(['site.sites.id', 'site.sites.subdomain']);

        $textDeleted = DB::connection('pgsql')->table('content.f_text')
            ->whereIn('item_id', $affected)->delete();

        $headlinesCleared = DB::connection('pgsql')->table('content.items')
            ->whereIn('id', $affected)
            ->update(['headline_cache' => null]);

        // MANDATORY, all three lanes: this is a raw write that bypasses
        // Eloquent observers, so nothing else invalidates the published
        // document. Without it the page keeps rendering a headline whose row
        // was just deleted. PruneOrphanedReviewPiiCommand bumps BuildState
        // only — that is a gap in it, not a precedent to copy.
        foreach ($sites as $site) {
            BuildState::bump((string) $site->id);
        }

        DB::connection('pgsql')->table('site.sites')
            ->whereIn('id', $sites->pluck('id'))
            ->update(['updated_at' => now()]);

        foreach ($sites as $site) {
            if ($site->subdomain !== null && $site->subdomain !== '') {
                CloudflareCachePurgeJob::dispatch((string) $site->subdomain);
            }
        }

        $this->info("Cleared {$headlinesCleared} headline(s), deleted {$textDeleted} f_text row(s), bumped {$sites->count()} site(s).");

        Log::info('content.purge_review_headline_pii', [
            'headlines_cleared' => $headlinesCleared,
            'f_text_deleted' => $textDeleted,
            'sites_bumped' => $sites->count(),
            'dry_run' => false,
        ]);

        return self::SUCCESS;
    }
}
