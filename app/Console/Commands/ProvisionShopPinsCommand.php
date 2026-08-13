<?php

namespace App\Console\Commands;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\Site;
use App\Site\Documents\BuildState;
use App\Site\Pools\PoolRegistry;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seed the owner's product ordering as pins on each site's pool:shop section
 * (spec §3.2). PoolSectionProvisioner::ensure() creates the page and section on
 * demand at first read, but nothing creates pins — and without them the Shop
 * page renders in last_seen_at order instead of the order the owner chose.
 *
 * The pool's auto half offers only alphabetical / occurrence / recency
 * (SectionCandidates:105-116) and none of them is "the order the owner picked",
 * which is why the ordering is carried by pins rather than by order_by.
 *
 * Idempotent: an existing pin is left alone, never rewritten, so a drag on the
 * pool page survives a re-run. Read-only under --dry-run.
 */
class ProvisionShopPinsCommand extends Command
{
    protected $signature = 'content:provision-shop-pins
        {--dry-run : Report what would be pinned without writing}';

    protected $description = 'Pin every shop product at its catalogue position on the pool:shop section';

    public function __construct(private readonly PoolSectionProvisioner $provisioner)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $pinned = 0;
        $left = 0;
        $sites = 0;

        $userIds = DB::connection('pgsql')->table('content.collections')
            ->where('kind', 'storefront')
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            $site = Site::query()->where('user_id', (string) $userId)->first();
            if ($site === null) {
                // Parent §8.2: derive user_id through the site explicitly and
                // fail loudly rather than skipping in silence.
                $this->warn("  ! user {$userId} holds storefronts but has no site — skipped");

                continue;
            }

            // Store position first, then catalogue position within the store.
            // items.id breaks ties deterministically: two collections may share
            // a position, and without a total order the pin sequence would be
            // heap-order dependent. removed_at is filtered because the pool read
            // filters it — pinning a retired item would put a row in
            // section_items that resolves to nothing.
            $itemIds = DB::connection('pgsql')->table('content.collection_items as ci')
                ->join('content.collections as c', 'c.id', '=', 'ci.collection_id')
                ->join('content.items as i', 'i.id', '=', 'ci.item_id')
                ->where('c.user_id', (string) $userId)
                ->where('c.kind', 'storefront')
                ->whereNull('i.removed_at')
                ->orderBy('c.position')->orderBy('ci.position')->orderBy('i.id')
                ->pluck('ci.item_id')
                // An item listed by two of this user's stores is ONE item
                // (5a §3.3 — the coord is URL-derived, not store-scoped), so it
                // pins once, at its earliest position.
                ->unique()
                ->values();

            if ($itemIds->isEmpty()) {
                continue;
            }

            // ensure() INSERTs site.pages/site.sections rows — never call it
            // under --dry-run. Look up the existing pool:shop section instead;
            // a site that has never opened its Shop page has none yet, which
            // is the common case a dry run is run against, so treat "no
            // section" as "no existing pins" rather than skipping the site.
            if ($dry) {
                $section = DB::connection('pgsql')->table('site.sections')
                    ->where('site_id', $site->id)
                    ->where('key', PoolRegistry::sectionKey('shop'))
                    ->first();
            } else {
                $section = $this->provisioner->ensure($site, 'shop');
            }

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
                    // a drag (:173), so a seeded pin and a dragged pin are
                    // indistinguishable.
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
        $this->info("pool:shop pins: {$verb} {$pinned}, left alone {$left}, across {$sites} site(s).");

        return self::SUCCESS;
    }

    /**
     * Raw-write seam: all three lanes by hand (spec §4). bump() alone is not
     * enough — the payload cache key composes from sites.updated_at, and the
     * CDN outlives the origin write. No CI check enforces this.
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
