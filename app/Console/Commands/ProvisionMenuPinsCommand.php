<?php

namespace App\Console\Commands;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\Site;
use App\Services\Migration\MenuBackfiller;
use App\Site\Documents\BuildState;
use App\Site\Pools\PoolRegistry;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seed the owner's dish ordering as pins on each site's pool:menus section
 * (slice 4 Unit 7). The pool's auto half offers only alphabetical / occurrence
 * / recency (SectionCandidates:105-116) and none of them is "the order the
 * owner chose", so the arrangement is carried by pins — the same answer slices
 * 3a and 5a reached independently, and the reason no `position` operator
 * exists in the rule DSL.
 *
 * The order is read from the LEGACY tables — site.menu_categories.position,
 * then site.menu_item_categories.position — because that is literally the menu
 * the owner is looking at today. content.collection_items.position cannot
 * answer it: ProjectionWriter writes it from the projection's array order,
 * which is a dish's index in its OWN category list, not its rank within a
 * category. Reading legacy here is safe precisely because pins are seeded
 * ONCE; after this run the order lives in site.section_items.sort_key and
 * slice 7 may drop the menu tables without touching it.
 *
 * Idempotent: an existing pin is left alone, never rewritten, so a drag on the
 * pool page survives a re-run. Read-only under --dry-run.
 *
 * NO per-site try/catch, copied deliberately from content:provision-shop-pins:
 * one failing site aborts the run for every site after it. A partial pin seed
 * is worse than none — the owner sees a half-ordered menu and cannot tell
 * which half is theirs.
 */
class ProvisionMenuPinsCommand extends Command
{
    protected $signature = 'content:provision-menu-pins
        {--dry-run : Report what would be pinned without writing}';

    protected $description = 'Pin every dish at its legacy menu position on the pool:menus section';

    public function __construct(
        private readonly PoolSectionProvisioner $provisioner,
        private readonly MenuBackfiller $backfiller,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $pinned = 0;
        $left = 0;
        $unmapped = 0;
        $sites = 0;

        $menus = DB::connection('pgsql')->table('site.menus')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'user_id']);

        foreach ($menus as $menu) {
            $userId = (string) $menu->user_id;

            $site = Site::query()->where('user_id', $userId)->first();
            if ($site === null) {
                // Parent §8.2: derive through the site explicitly and say so
                // rather than skipping in silence.
                $this->warn("  ! user {$userId} holds a menu but has no site — skipped");

                continue;
            }

            // Category order, then the dish's position within the category.
            // menu_items.id breaks ties deterministically: two rows may share a
            // pivot position, and without a total order the pin sequence would
            // be heap-order dependent.
            $legacyIds = DB::connection('pgsql')->table('site.menu_item_categories as mic')
                ->join('site.menu_categories as mc', 'mc.id', '=', 'mic.menu_category_id')
                ->join('site.menu_items as mi', 'mi.id', '=', 'mic.menu_item_id')
                ->where('mc.menu_id', $menu->id)
                ->orderBy('mc.position')->orderBy('mic.position')->orderBy('mi.id')
                ->pluck('mic.menu_item_id');

            // A dish in several categories pins ONCE, at its earliest position
            // — the pool is a flat list of dishes and collections group them
            // for display, not the other way round.
            $itemIds = [];
            foreach ($legacyIds as $legacyId) {
                $itemId = $this->backfiller->contentItemForLegacyDish($userId, (string) $legacyId);
                if ($itemId === null) {
                    $unmapped++;

                    continue;
                }
                $itemIds[$itemId] = true;
            }
            $itemIds = array_keys($itemIds);

            if ($itemIds === []) {
                continue;
            }

            // ensure() INSERTs site.pages/site.sections rows — never call it
            // under --dry-run. A site that has never opened its Menu page has
            // no section yet, which is the common case a dry run is aimed at,
            // so treat "no section" as "no existing pins" rather than skipping.
            $section = $dry
                ? DB::connection('pgsql')->table('site.sections')
                    ->where('site_id', $site->id)
                    ->where('key', PoolRegistry::sectionKey('menus'))
                    ->first()
                : $this->provisioner->ensure($site, 'menus');

            $existing = $section === null
                ? collect()
                : DB::connection('pgsql')->table('site.section_items')
                    ->where('section_id', $section->id)
                    ->pluck('item_id')->flip();

            $wrote = 0;
            foreach ($itemIds as $index => $itemId) {
                if ($existing->has($itemId)) {
                    $left++;

                    continue;
                }
                if ($dry) {
                    $pinned++;

                    continue;
                }
                DB::connection('pgsql')->table('site.section_items')->insert([
                    'id' => (string) Str::uuid(),
                    'section_id' => $section->id,
                    'item_id' => $itemId,
                    'state' => 'pinned',
                    // Dense 1-based, exactly as PoolController::reorder() writes
                    // a drag, so a seeded pin and a dragged pin are
                    // indistinguishable afterwards.
                    'sort_key' => (float) ($index + 1),
                    'created_at' => now(),
                ]);
                $pinned++;
                $wrote++;
            }

            if (! $dry && $wrote > 0) {
                $this->invalidate($site);
                $sites++;
            }
        }

        $verb = $dry ? 'would pin' : 'pinned';
        $this->info("pool:menus pins: {$verb} {$pinned}, left alone {$left}, unmapped {$unmapped}, across {$sites} site(s).");

        return self::SUCCESS;
    }

    /**
     * Raw-write seam: all three lanes by hand (parent §9.2). bump() alone is
     * not enough — the payload cache key composes from sites.updated_at, and
     * the CDN outlives the origin write. No CI check enforces this.
     */
    private function invalidate(Site $site): void
    {
        BuildState::bump((string) $site->id);
        DB::connection('pgsql')->table('site.sites')
            ->where('id', $site->id)->update(['updated_at' => now()]);
        if ((string) ($site->subdomain ?? '') !== '') {
            CloudflareCachePurgeJob::dispatch($site->subdomain);
        }
    }
}
