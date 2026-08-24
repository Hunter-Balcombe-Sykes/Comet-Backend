<?php

namespace App\Services\Shop;

use App\Models\Core\Site\Site;
use App\Site\Documents\SiteCacheLanes;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * First-connect Sell-page seeding (owner spec, 2026-08-20): when a store
 * connects and the owner has never chosen products for it, pin up to
 * MAX_PINS of the store's most recent products — once, ever.
 *
 * "Most recent" is content.collection_items.position order: ShopCatalog
 * sorts the fetched catalogue by the provider's own createdAt before
 * syncStore() writes positions, so position IS the store's own date order
 * (SectionCandidates' storefrontLatestArm relies on the same fact). The
 * f_published recency expression would agree today; position is cheaper and
 * is what ProvisionShopPinsCommand and the grandfather migration used.
 *
 * Once-only is carried by content.storefronts.products_autoselected_at —
 * deliberately NOT products_curated_at, which means "the user hand-picked"
 * and suppresses scheduled ShopFetch syncs (#SEM-1). The stamp is a
 * compare-and-set (WHERE ... IS NULL), so two racing connect jobs cannot
 * double-seed. The column lives outside StoreRecord/upsertStore() on
 * purpose: upsertStore() writes every listed column unconditionally in its
 * ON CONFLICT arm, and this marker must never ride along with routine syncs.
 *
 * An empty catalogue does NOT stamp: the fill may simply have failed, and a
 * reconnect should get another chance. Any existing engagement with this
 * store's items on pool:shop (a pin OR an exclude) stamps without seeding —
 * the owner has already answered the question this feature asks.
 */
class ShopAutoSelector
{
    public const MAX_PINS = 5;

    public function __construct(private readonly PoolSectionProvisioner $provisioner) {}

    /** @return int Number of products pinned (0 on any gate). */
    public function selectInitial(string $collectionId): int
    {
        $storefront = DB::connection('pgsql')->table('content.storefronts')
            ->where('collection_id', $collectionId)
            ->first(['user_id', 'products_curated_at', 'products_autoselected_at']);

        if ($storefront === null || $storefront->user_id === null) {
            return 0;
        }
        if ($storefront->products_curated_at !== null || $storefront->products_autoselected_at !== null) {
            return 0;
        }

        // Mirrors ShopBrandConnectJob::bumpSiteCache(): a fixture or a user
        // mid-signup may have no site row — skip rather than guess.
        $site = Site::query()->where('user_id', (string) $storefront->user_id)->first();
        if ($site === null) {
            return 0;
        }

        $candidates = DB::connection('pgsql')->table('content.collection_items as ci')
            ->join('content.items as i', 'i.id', '=', 'ci.item_id')
            ->where('ci.collection_id', $collectionId)
            ->whereNull('i.removed_at')
            ->orderBy('ci.position')->orderBy('i.id')
            ->limit(self::MAX_PINS)
            ->pluck('ci.item_id')
            ->unique()->values();

        // No stamp on an empty catalogue — see the class docblock.
        if ($candidates->isEmpty()) {
            return 0;
        }

        $section = $this->provisioner->ensure($site, 'shop');

        // ONE transaction for the claim, the engagement re-check, and the pin
        // writes (critic pass, 2026-08-20): the claim used to commit on its
        // own, so any failure in the pin loop stranded the store stamped with
        // a partial (or zero) seed and no retry path — the stamp is the sole
        // retry gate. A rollback now takes the stamp with it. The engagement
        // check also moved INSIDE the claim: read before it, a manual
        // pin/exclude landing in the gap would have been seeded over.
        $pinned = (int) DB::connection('pgsql')->transaction(function () use ($collectionId, $section, $candidates) {
            // Compare-and-set claim: whichever concurrent caller moves this
            // row owns the seeding; everyone else no-ops.
            $claimed = DB::connection('pgsql')->table('content.storefronts')
                ->where('collection_id', $collectionId)
                ->whereNull('products_autoselected_at')
                ->whereNull('products_curated_at')
                ->update(['products_autoselected_at' => now(), 'updated_at' => now()]) > 0;

            if (! $claimed) {
                return 0;
            }

            // ANY curation row touching this store's items — pinned or
            // excluded — means the owner already engaged with this store's
            // Sell selection: keep the stamp, seed nothing.
            $storeItemIds = DB::connection('pgsql')->table('content.collection_items')
                ->where('collection_id', $collectionId)
                ->pluck('item_id');
            $engaged = DB::connection('pgsql')->table('site.section_items')
                ->where('section_id', $section->id)
                ->whereIn('item_id', $storeItemIds)
                ->exists();

            if ($engaged) {
                return 0;
            }

            // Items already on the section (an overlap-listed item pinned via
            // another store) are left alone — same rule as
            // ProvisionShopPinsCommand.
            $existing = DB::connection('pgsql')->table('site.section_items')
                ->where('section_id', $section->id)
                ->pluck('item_id')->flip();

            // Append after whatever is already there so another store's
            // earlier pins (or the owner's own ordering) are never
            // interleaved.
            $maxSortKey = (float) (DB::connection('pgsql')->table('site.section_items')
                ->where('section_id', $section->id)
                ->max('sort_key') ?? 0.0);

            $pinned = 0;
            foreach ($candidates as $itemId) {
                if ($existing->has($itemId)) {
                    continue;
                }
                // insertOrIgnore: a concurrent PoolController::select() racing
                // us onto the same (section_id, item_id) unique key must not
                // abort the whole seed — their row wins, ours skips.
                $pinned += DB::connection('pgsql')->table('site.section_items')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'section_id' => $section->id,
                    'item_id' => $itemId,
                    'state' => 'pinned',
                    'sort_key' => $maxSortKey + $pinned + 1.0,
                    'created_at' => now(),
                ]);
            }

            return $pinned;
        });

        // Outside the transaction — SiteCacheLanes dispatches a CDN purge job.
        if ($pinned > 0) {
            SiteCacheLanes::bust([(string) $site->id]);
        }

        return $pinned;
    }
}
