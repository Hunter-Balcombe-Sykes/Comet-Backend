<?php

namespace App\Services\Shop;

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Platforms\NormalizesMenuItemNames;
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
 *
 * Item 12 (2026-09-01) adds two publish-side gates — neither touches what is
 * INGESTED, only what auto-pins: a food account's store on their own website
 * domain seeds nothing (their ordering system is not a merch shop), and a
 * product whose normalized name matches a live menu_item is never a
 * candidate in any sector (the dish already renders on the Menu page). Both
 * leave the stamp unset, same reasoning as the empty catalogue.
 */
class ShopAutoSelector
{
    use NormalizesMenuItemNames;

    public const MAX_PINS = 5;

    public function __construct(private readonly PoolSectionProvisioner $provisioner) {}

    /** @return int Number of products pinned (0 on any gate). */
    public function selectInitial(string $collectionId): int
    {
        $storefront = DB::connection('pgsql')->table('content.storefronts')
            ->where('collection_id', $collectionId)
            ->first(['user_id', 'products_curated_at', 'products_autoselected_at', 'url', 'source_url']);

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

        // Item 12 food-sector guard (2026-09-01): a food account whose
        // connected store sits on their OWN website domain is a restaurant's
        // ordering system, not a merch shop — auto-selecting its catalogue put
        // the menu on the Sell page twice (the-famished-wolf WooCommerce
        // case). The catalogue stays library-only; the connection and its
        // ordering links are untouched, and the owner can still pin products
        // by hand (shop is opt-in pins by design). Deliberately NO stamp: like
        // the empty-catalogue gate, this is a policy answer, not the owner's —
        // as if selectInitial() was never called for this store.
        if ($this->ownDomainFoodStore($storefront, $site)) {
            return 0;
        }

        // No LIMIT here: the menu-name backstop below filters AFTER the fetch,
        // and a limit-then-filter would under-pin a store whose newest products
        // happen to collide. Catalogues are small (ShopContentWriter::syncStore
        // caps at the 250-product request bound; observed stores hold ≤20).
        $candidates = DB::connection('pgsql')->table('content.collection_items as ci')
            ->join('content.items as i', 'i.id', '=', 'ci.item_id')
            ->where('ci.collection_id', $collectionId)
            ->whereNull('i.removed_at')
            ->orderBy('ci.position')->orderBy('i.id')
            ->get(['ci.item_id', 'i.headline_cache'])
            ->unique('item_id');

        // No stamp on an empty catalogue — see the class docblock. Checked
        // before the menu read so the common empty case costs no extra query.
        if ($candidates->isEmpty()) {
            return 0;
        }

        // Item 12 menu-name backstop, ALL sectors: a product named like an
        // existing dish IS the dish ("Classic Mac" as both menu_item and
        // product) — never auto-pin it. Library membership is untouched; a
        // fully-colliding catalogue behaves exactly like an empty one (no
        // stamp), so a later genuine merch product still gets its chance.
        $menuNames = $this->menuNameKeys((string) $storefront->user_id);
        $candidates = $candidates
            ->reject(fn (object $row): bool => isset($menuNames[$this->normalizeName((string) ($row->headline_cache ?? ''))]))
            ->pluck('item_id')
            ->take(self::MAX_PINS)
            ->values();

        // Every candidate collided with the menu — same no-stamp rule as the
        // empty catalogue above.
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

    /**
     * Item 12: is this store the user's own website wearing a shop hat, on a
     * food account? "Own website" = the store's recorded url/source_url host
     * equals the previous-website host (site.workplaces.previous_website —
     * the same record the website scan that discovered the store was keyed
     * on). Host equality lowercased with a leading www. stripped, never
     * substring containment — CustomLinkSeeder's own comparison shape.
     *
     * Food-ness reads AccountCapabilities::can_use_menu, the ONE sector-food
     * capability (never a branch on account_type): a store on the domain of a
     * user who has a Menu is their ordering system, and its dishes belong on
     * the Menu page, not the Sell page.
     */
    private function ownDomainFoodStore(object $storefront, Site $site): bool
    {
        $previous = DB::connection('pgsql')->table('site.workplaces')
            ->where('site_id', (string) $site->id)
            ->value('previous_website');
        $ownHost = $this->hostOf((string) ($previous ?? ''));
        if ($ownHost === null) {
            return false;
        }

        $storeHosts = array_filter([
            $this->hostOf((string) ($storefront->url ?? '')),
            $this->hostOf((string) ($storefront->source_url ?? '')),
        ]);
        if (! in_array($ownHost, $storeHosts, true)) {
            return false;
        }

        $user = User::query()->find((string) $storefront->user_id);

        return $user !== null && AccountCapabilities::for($user)->can_use_menu;
    }

    /**
     * The user's live dish names as normalized lookup keys. Empty-string keys
     * are dropped so a nameless dish can never veto a nameless product.
     *
     * @return array<string, true>
     */
    private function menuNameKeys(string $userId): array
    {
        $keys = [];
        $names = DB::connection('pgsql')->table('content.items')
            ->where('user_id', $userId)
            ->where('kind', 'menu_item')
            ->whereNull('removed_at')
            ->pluck('headline_cache');
        foreach ($names as $name) {
            $key = $this->normalizeName((string) $name);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /** Lowercased host, leading www. stripped — the shared comparison shape (CustomLinkSeeder, SweepPreviousWebsiteCardsJob). */
    private function hostOf(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\./', '', strtolower($host));
    }
}
