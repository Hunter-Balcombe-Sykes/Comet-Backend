<?php

namespace App\Console\Commands;

use App\Models\Core\Site\Block;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * One-shot data remediation: soft-delete excess platform link blocks for
 * professionals already over the platform-link cap (platform_links_max).
 *
 * The write-time cap in StoreLinkBlockRequest prevents new over-limit creates,
 * but data created before the cap was introduced (or via buggy code paths,
 * see #CR-001/#CR-011) may already exceed the limit. This command brings
 * existing data into compliance.
 *
 * Strategy: for each over-cap professional, keep the oldest `platform_links_max`
 * blocks (by created_at ASC) and soft-delete the rest. Oldest-first preserves
 * the links the professional set up first, which are most likely intentional.
 *
 * Idempotent — already-soft-deleted blocks are excluded from the scan, so
 * re-running is safe and won't touch previously remediated rows.
 *
 * Run order:
 *   1. Always run with --dry-run first to preview impact.
 *   2. Run for real once you've reviewed the output.
 */
class EnforcePlatformLinkCapCommand extends Command
{
    protected $signature = 'partna:enforce-platform-link-cap
        {--dry-run : Preview which blocks would be soft-deleted without writing}';

    protected $description = 'Soft-delete excess platform link blocks for professionals already over the cap. Idempotent.';

    public function handle(): int
    {
        $cappedCategories = (array) config('partna.platform_links_categories', []);
        $max = (int) config('partna.platform_links_max', 7);
        $dryRun = (bool) $this->option('dry-run');

        if (empty($cappedCategories) || $max <= 0) {
            $this->error('platform_links_categories or platform_links_max is not configured.');

            return self::FAILURE;
        }

        Log::info('enforce-platform-link-cap started', [
            'operator' => get_current_user() ?: 'unknown',
            'dry_run' => $dryRun,
            'max' => $max,
        ]);

        $stats = [
            'professionals_scanned' => 0,
            'professionals_over_cap' => 0,
            'blocks_soft_deleted' => 0,
        ];

        // Stream distinct professional IDs that have any non-deleted link blocks.
        // Categories are filtered in PHP rather than via JSON DB operators so this
        // command runs identically against SQLite (tests) and PostgreSQL (prod).
        // SCALE-1: cursor() over SELECT DISTINCT user_id yields ids lazily instead
        // of materialising every professional's id into memory at 10k+ scale.
        $userIdStream = Block::query()
            ->where('block_group', 'links')
            ->whereNull('deleted_at')
            ->select('user_id')
            ->distinct()
            ->cursor();

        $now = Carbon::now()->toDateTimeString();

        foreach ($userIdStream as $row) {
            $proId = $row->user_id;
            if (! $proId) {
                continue;
            }
            $stats['professionals_scanned']++;

            // Load all non-deleted link blocks in the capped scope for this
            // professional, oldest first. We keep the first $max and soft-delete
            // everything beyond that index.
            $cappedBlocks = Block::query()
                ->where('user_id', $proId)
                ->where('block_group', 'links')
                ->whereNull('deleted_at')
                ->orderBy('created_at')
                ->orderBy('id') // stable tie-break for same-second inserts
                ->get()
                ->filter(fn (Block $b) => in_array($b->settings['category'] ?? null, $cappedCategories, true))
                ->values();

            if ($cappedBlocks->count() <= $max) {
                continue;
            }

            $stats['professionals_over_cap']++;
            $excess = $cappedBlocks->slice($max);

            foreach ($excess as $block) {
                $stats['blocks_soft_deleted']++;
                $category = $block->settings['category'] ?? 'unknown';
                $this->line("  [soft-delete] block {$block->id} (pro {$proId}, category: {$category})");

                if (! $dryRun) {
                    Block::query()->where('id', $block->id)->update(['deleted_at' => $now]);
                }
            }
        }

        $this->newLine();
        $this->info($dryRun ? 'DRY RUN — no changes written.' : 'Cap enforcement complete.');
        $this->table(
            ['Metric', 'Count'],
            collect($stats)->map(fn ($v, $k) => [$k, $v])->all()
        );

        return self::SUCCESS;
    }
}
