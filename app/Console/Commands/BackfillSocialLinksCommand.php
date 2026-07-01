<?php

namespace App\Console\Commands;

use App\Models\Core\Site\Block;
use App\Services\Site\SocialLinkNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * One-shot backfill: tag ALL link blocks with the `platform` column,
 * `settings.handle` (when extractable), and the `category` column.
 *
 * Phase 2: writes to the promoted columns (platform, category) instead of
 * settings JSONB. handle stays in settings as it has no dedicated column.
 *
 * Idempotent — safe to re-run. Skips rows that already have both platform
 * and category columns set. Rows with a known social icon_key get full platform
 * resolution; custom/unknown rows get category='other' only.
 *
 * Why we have this:
 *   When the social_platforms registry was introduced, existing link blocks
 *   used icon_key='instagram' etc. but had no platform tag. The category field
 *   was added later, requiring another pass over all link blocks.
 *
 * Run order:
 *   1. Always run with --dry-run first to preview the stats.
 *   2. Run for real once you've reviewed.
 *   3. Optional: --limit=N for a cautious first batch on large datasets.
 *
 * Security:
 *   - Per-chunk DB transaction so a partial chunk failure rolls back cleanly.
 *   - chunkById(200) keeps memory bounded.
 *   - Logging hygiene: warnings reference block ID + platform key only,
 *     never the full URL or handle.
 *   - Audit log line at start records the operator and run mode.
 *
 * See docs/social-links.md.
 */
class BackfillSocialLinksCommand extends Command
{
    protected $signature = 'partna:backfill-social-links
        {--dry-run : Show what would change without writing}
        {--limit=0 : Process at most N rows (0 = unlimited)}';

    protected $description = 'Backfill link blocks with settings.platform, settings.handle (when derivable), and settings.category. Idempotent — safe to re-run.';

    public function handle(SocialLinkNormalizer $normalizer): int
    {
        $registry = config('partna.social_platforms', []);
        $iconToPlatform = $this->buildIconToPlatformMap($registry);

        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        Log::info('Backfill link blocks started', [
            'operator' => get_current_user() ?: 'unknown',
            'dry_run' => $dryRun,
            'limit' => $limit,
        ]);

        $stats = [
            'total' => 0,
            'already_tagged' => 0,
            'tagged_with_handle' => 0,
            'tagged_url_only' => 0,
            'category_only' => 0,
            'url_normalized' => 0,
            'unmatched_host' => 0,
            'errors' => 0,
        ];

        // Process ALL link blocks. Social-icon rows get platform + category
        // resolution; other rows get category='other'. See the plan §Task 16
        // and docs/social-links.md for the rationale.
        $query = Block::query()
            ->where('block_group', 'links')
            ->where('block_type', 'link')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $query->chunkById(200, function ($blocks) use (&$stats, $iconToPlatform, $registry, $normalizer, $dryRun) {
            DB::transaction(function () use ($blocks, &$stats, $iconToPlatform, $registry, $normalizer, $dryRun) {
                foreach ($blocks as $block) {
                    $stats['total']++;

                    // Phase 2: idempotency reads the promoted columns, not settings.
                    $hasCategory = $block->category !== null;
                    $hasPlatform = $block->platform !== null;

                    // Fully-tagged rows: skip (idempotent).
                    if ($hasCategory && $hasPlatform) {
                        $stats['already_tagged']++;

                        continue;
                    }

                    $settings = is_array($block->settings) ? $block->settings : [];

                    // Identify the platform to use for category lookup + (if the
                    // row isn't platform-tagged yet) URL normalization.
                    $platformKey = $hasPlatform
                        ? $block->platform
                        : ($iconToPlatform[$block->icon_key] ?? null);

                    // Legacy social-icon path: row has a social icon_key but no
                    // platform column yet. Normalize URL, tag platform/handle.
                    if (! $hasPlatform && $platformKey !== null && $block->url !== null) {
                        try {
                            $normalized = $normalizer->normalize($platformKey, null, $block->url);
                        } catch (InvalidArgumentException $e) {
                            // URL doesn't match the platform's host_allowlist
                            // (e.g. a Linktree URL behind an Instagram icon).
                            // Leave platform/handle alone but still backfill
                            // category so the row isn't stuck.
                            $stats['unmatched_host']++;
                            $this->warn(sprintf('  Host mismatch for block %s (%s) — category-only backfill', $block->id, $platformKey));
                            Log::warning('Backfill: host mismatch', [
                                'block_id' => (string) $block->id,
                                'platform' => $platformKey,
                            ]);
                            $platformKey = null; // fall through to category=other
                        }

                        if ($platformKey !== null && isset($normalized)) {
                            // Phase 2: write platform to the column; keep handle in settings.
                            $block->platform = $platformKey;
                            if ($normalized['handle'] !== null) {
                                $settings['handle'] = $normalized['handle'];
                                $stats['tagged_with_handle']++;
                            } else {
                                $stats['tagged_url_only']++;
                            }

                            if ($normalized['url'] !== $block->url) {
                                $stats['url_normalized']++;
                                if (! $dryRun) {
                                    $block->url = $normalized['url'];
                                }
                            }
                        }
                    }

                    // Resolve category: platform default, or 'other' for
                    // custom/unknown rows and host-mismatch fallbacks.
                    if (! $hasCategory) {
                        // Phase 2: write category to the column.
                        $block->category = $platformKey !== null
                            ? ($registry[$platformKey]['default_category'] ?? 'other')
                            : 'other';
                        $stats['category_only']++;
                    }

                    if (! $dryRun) {
                        $block->settings = $settings;
                        $block->save();
                    }

                    unset($normalized);
                }
            });
        });

        $this->newLine();
        $this->info($dryRun ? 'DRY RUN — no changes written.' : 'Backfill complete.');
        $this->table(
            ['Metric', 'Count'],
            collect($stats)->map(fn ($v, $k) => [$k, $v])->all()
        );

        return self::SUCCESS;
    }

    /**
     * Build a reverse map of icon_key → platform_key from the registry, so we
     * can look up "which platform does this existing block's icon belong to?"
     * in O(1) per row.
     *
     * @param  array<string, array<string, mixed>>  $registry
     * @return array<string, string>
     */
    private function buildIconToPlatformMap(array $registry): array
    {
        $map = [];
        foreach ($registry as $platformKey => $config) {
            $map[$config['icon_key']] = $platformKey;
        }

        return $map;
    }
}
