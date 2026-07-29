<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * #PRIV-4: thin site.site_documents to a bounded number of versions per
 * (site_id, channel). Every build (DocumentBuilder::build()) inserts a full
 * page snapshot as JSONB — display name, handle, bio, social links,
 * addresses — and site:build-documents --stale runs every five minutes. The
 * CAS hash gate suppresses byte-identical rebuilds but not distinct ones, so
 * an actively-edited site accumulates a full snapshot per edit, forever.
 *
 * The ONLY reader of this table is DocumentBuilder::build()'s
 * ->orderByDesc('version')->first() — every non-newest version has ZERO
 * consumers, not merely no cap. This command never touches that newest
 * version: it always keeps at least `keep` versions per pair, so
 * DocumentBuilder's next-version arithmetic (version = max + 1) and the
 * `UNIQUE (site_id, channel, version)` constraint are unaffected.
 *
 * Two conditions, both required, on the delete itself:
 *   - version < threshold   (older than the keep-N window)
 *   - built_at < ageCutoff  (older than min-age-days)
 * version < threshold alone would delete a version that is 3 builds old but
 * 2 minutes young. built_at < ageCutoff alone would delete the CURRENT live
 * document of a site nobody has edited in a month. Both together is the
 * correctness of this command.
 *
 * No BuildState interaction: this command never deletes the newest version
 * of any (site_id, channel) pair, so there is nothing to signal — the
 * published document is untouched.
 *
 * Implemented without window functions so the logic stays portable to the
 * SQLite test driver.
 */
class PruneSiteDocumentVersionsCommand extends Command
{
    protected $signature = 'site:prune-document-versions
                            {--keep= : Versions to keep per (site_id, channel) (default from config partna.documents.keep_versions)}
                            {--min-age-days= : Never delete anything built more recently than this (default from config partna.documents.min_age_days)}
                            {--dry-run : Report eligible rows without mutating anything}';

    protected $description = 'Delete old site.site_documents versions, keeping the newest N per (site_id, channel) '
        .'and never touching anything built within the minimum age window.';

    public function handle(): int
    {
        $keep = max(1, (int) ($this->option('keep') ?? config('partna.documents.keep_versions', 3)));
        $minAgeDays = (int) ($this->option('min-age-days') ?? config('partna.documents.min_age_days', 7));
        $batchSize = (int) config('partna.documents.prune_batch_size', 500);
        $dryRun = (bool) $this->option('dry-run');
        $ageCutoff = now()->subDays($minAgeDays);

        $this->line(sprintf(
            '%s site.site_documents versions older than %d build(s) and built before %s.',
            $dryRun ? '[DRY RUN] Would prune' : 'Pruning',
            $keep,
            $ageCutoff->toDateTimeString()
        ));

        $pairs = DB::connection('pgsql')
            ->table('site.site_documents')
            ->select(['site_id', 'channel'])
            ->where('built_at', '<', $ageCutoff->toDateTimeString())
            ->distinct()
            ->orderBy('site_id')
            ->orderBy('channel')
            ->get();

        $deleted = 0;
        $eligiblePairs = 0;

        foreach ($pairs as $pair) {
            // The keep-th newest version's number is the threshold: anything
            // strictly below it is old enough to prune. A pair with <= keep
            // versions total has no row at this offset — skip returns null.
            $threshold = DB::connection('pgsql')
                ->table('site.site_documents')
                ->where('site_id', $pair->site_id)
                ->where('channel', $pair->channel)
                ->orderByDesc('version')
                ->skip($keep - 1)
                ->take(1)
                ->value('version');

            if ($threshold === null) {
                continue;
            }

            $eligiblePairs++;

            $query = DB::connection('pgsql')
                ->table('site.site_documents')
                ->where('site_id', $pair->site_id)
                ->where('channel', $pair->channel)
                ->where('version', '<', $threshold)
                ->where('built_at', '<', $ageCutoff->toDateTimeString());

            if ($dryRun) {
                $deleted += $query->count();

                continue;
            }

            $deleted += $query->delete();

            if ($eligiblePairs % $batchSize === 0) {
                Log::info('site.prune_document_versions_progress', ['deleted_so_far' => $deleted]);
            }
        }

        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $this->info("{$verb} {$deleted} old document version(s) across {$eligiblePairs} pair(s).");

        Log::info($dryRun ? 'site.prune_document_versions_dry_run' : 'site.prune_document_versions', [
            'deleted' => $deleted,
            'eligible_pairs' => $eligiblePairs,
            'keep' => $keep,
            'min_age_days' => $minAgeDays,
            'age_cutoff' => $ageCutoff->toIso8601String(),
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}
