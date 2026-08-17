<?php

namespace App\Services\Shop;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\User\User;
use App\Services\Platforms\ShopProviderDetector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Convergence Phase 6, owner ruling 4: ONE CONNECTION PER STORE.
 *
 * Before this, a user's whole shop was a single `partna.storefront` row whose
 * payload was a static marker, with every connected store hanging off it as a
 * `site.shop_brands` child. That is the exact shape scope §1.6 objects to: five
 * Shopify stores and a WooCommerce one behind one pseudo-platform row, so
 * ingest could not tell them apart, identity resolution never saw them, and no
 * per-brand feature could reach them.
 *
 * Now each store carries its own connection on its real brand surface —
 * `shopify.store`, `woocommerce.store`, `squarespace.store`, `bigcartel.store`,
 * or `generic.store` for a storefront whose platform has no name. The
 * `site.shop_brands` row points at ITS OWN connection, which the existing
 * UNIQUE (connection_id, brand_id) still allows.
 *
 * This class is the single place that knows the mapping, so no caller has to
 * re-derive it — and, more importantly, so `where('connection_id', $one->id)`
 * cannot survive anywhere as a silently-narrowing read. Every brand lookup goes
 * through brands()/brandsFor(), which span the user's whole shop family.
 *
 * The RESOURCE ID is the brand id (`75102060779`, `fearnoevil-com-au`), which
 * is already unique per store and already what every read keys on. That keeps
 * `updateOrCreate` idempotent per store without inventing a second identifier.
 */
class ShopConnections
{
    /**
     * The surface this controller's family used to occupy, kept as a READ key
     * only. Rows written before the split are live until the retirement command
     * runs, and a read that missed them would blank an owner's shop between
     * deploy and migration.
     */
    public const LEGACY_SURFACE = 'partna.storefront';

    /**
     * The reserved bucket holding individually-added products (not tied to any
     * connected store). Its home is `partna.manual_product` — hidden, dormant
     * and explicitly NOT retired (§16 names it "the manual product add-path").
     * It is the one shop connection that is not a store, which is exactly what
     * that surface was reserved for.
     */
    public const INDIVIDUAL_SURFACE = 'partna.manual_product';

    /** Detector provider → its catalog surface. */
    private const PROVIDER_SURFACE = [
        ShopProviderDetector::PROVIDER_SHOPIFY => 'shopify.store',
        ShopProviderDetector::PROVIDER_WOOCOMMERCE => 'woocommerce.store',
        ShopProviderDetector::PROVIDER_SQUARESPACE => 'squarespace.store',
        ShopProviderDetector::PROVIDER_BIGCARTEL => 'bigcartel.store',
        ShopProviderDetector::PROVIDER_GENERIC => 'generic.store',
    ];

    /**
     * The surface a store of this provider lives on. An unknown provider falls
     * to `generic.store` rather than throwing: the detector is the authority on
     * what providers exist, and a store we can read but cannot name is precisely
     * what that surface is for. Failing the connect instead would turn a new
     * detector arm into a 500 for the owner.
     */
    public static function surfaceFor(?string $provider): string
    {
        return self::PROVIDER_SURFACE[$provider] ?? 'generic.store';
    }

    /** Every shop-family surface, including the individual-products bucket. */
    public static function surfaces(): array
    {
        return [...array_values(self::PROVIDER_SURFACE), self::INDIVIDUAL_SURFACE];
    }

    /**
     * Find-or-create the connection anchoring one store.
     *
     * Deliberately NOT policy-gated here — this is a storage helper, and every
     * caller is a controller that has already run its own create/update ability
     * (ShopController) or a server-side seeder acting on a server-derived user
     * id (StoreBrandSeeder, the commerce probe). Putting an authorize() call
     * here would either duplicate that or hide it from the call sites that owe it.
     */
    public function anchor(User $user, ?string $provider, string $brandId): IntegrationConnection
    {
        return IntegrationConnection::updateOrCreate(
            [
                'user_id' => $user->id,
                'surface_key' => self::surfaceFor($provider),
                'resource_id' => $brandId,
            ],
            [
                'payload' => ['storage' => 'relational'],
                'is_active' => true,
                'last_refresh_status' => 'ok',
                'last_refresh_error' => null,
                'consecutive_failures' => 0,
            ],
        );
    }

    /** The anchor for the individual-products bucket. */
    public function individualAnchor(User $user): IntegrationConnection
    {
        return IntegrationConnection::updateOrCreate(
            [
                'user_id' => $user->id,
                'surface_key' => self::INDIVIDUAL_SURFACE,
                'resource_id' => ShopBrand::INDIVIDUAL_BRAND_ID,
            ],
            [
                'payload' => ['storage' => 'relational'],
                'is_active' => true,
                'last_refresh_status' => 'ok',
                'last_refresh_error' => null,
                'consecutive_failures' => 0,
            ],
        );
    }

    /**
     * The ids of every connection in THIS controller's shop family.
     *
     * Scoped on an explicit surface list, NOT on routing_class — and that
     * distinction is load-bearing. `routing_class = 'shop'` also covers
     * `gumroad.store`, `stan.store` and `bandcamp`, which are separate
     * platforms with their own controllers and their own connect flows.
     * Scoping the class here would make DELETE /api/platforms/shop soft-delete
     * a user's Gumroad connection.
     *
     * (Ordering, booking and reservations legitimately DO scope on their class:
     * for those families the class genuinely is the whole family.)
     *
     * @return list<string>
     */
    public function connectionIds(User $user): array
    {
        return $user->integrationConnections()
            ->whereIn('surface_key', [...self::surfaces(), self::LEGACY_SURFACE])
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * Every connection in this controller's shop family, as models.
     *
     * The inverse relation is hydrated from the $user we were handed.
     * IntegrationConnectionObserver::deleted() reads `$connection->user?->site`
     * for any platform carrying a completeness predicate — shop does — and
     * lazy loading is disabled in this codebase, so a delete over these rows
     * would otherwise throw LazyLoadingViolationException. Setting it is
     * strictly better than making that query lazily: we already have the owner
     * in hand, and it is the same object.
     */
    public function connections(User $user)
    {
        return $user->integrationConnections()
            ->whereIn('surface_key', [...self::surfaces(), self::LEGACY_SURFACE])
            ->orderBy('created_at')
            ->get()
            ->each(fn (IntegrationConnection $row) => $row->setRelation('user', $user));
    }

    /**
     * Every connected store this user owns, keyed by its provider store id.
     *
     * Replaces brands(), which queried site.shop_brands. Ordering mirrors
     * ShopContentReader::brandMap() — c.position then s.external_ref — so the
     * two reads can never disagree about which store is position 0. Pinned by
     * ShopStoreReadLaneTest's "orders stores identically to
     * ShopContentReader::brandMap()".
     *
     * Returns a Collection rather than a query Builder deliberately: the
     * callers that chained ->where()/->max()/->count() all work unchanged on
     * one, and the whole family costs a single query instead of one per call.
     *
     * @return Collection<string, StoreRecord>
     */
    public function stores(User $user): Collection
    {
        return self::keyByExternalRef(
            $this->storeQuery()->where('c.user_id', (string) $user->id)->get()
        );
    }

    /** One store by its provider store id, across the user's whole shop family. */
    public function store(User $user, string $externalRef): ?StoreRecord
    {
        return $this->stores($user)->get($externalRef);
    }

    /**
     * The shared SELECT behind stores()/store(). Column list and ordering are
     * ShopContentReader::brandMap()'s, verbatim — one place to change if a
     * column moves, and no way for the two to drift apart silently.
     */
    private function storeQuery(): QueryBuilder
    {
        return DB::table('content.storefronts as s')
            ->join('content.collections as c', 'c.id', '=', 's.collection_id')
            ->where('c.kind', 'storefront')
            ->orderBy('c.position')
            ->orderBy('s.external_ref')
            ->select([
                's.collection_id', 's.external_ref', 's.provider', 's.url', 's.source_url',
                's.currency', 's.discount_code', 's.referral_query', 's.is_individual',
                's.fetch_mode', 's.connect_status', 's.connect_error', 's.products_curated_at',
                's.logo_url', 's.favicon_url', 's.logo_mark_url', 's.logo_mark_svg_url',
                // connectStatus()'s stale-pending clock — see StoreRecord::$updatedAt.
                's.updated_at',
                'c.label', 'c.position',
            ]);
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return Collection<string, StoreRecord>
     */
    private static function keyByExternalRef(Collection $rows): Collection
    {
        return $rows
            // A row with no external_ref has nothing to key on — skip rather
            // than collide every such row onto ''. The same guard brandMap()
            // applies, for the same reason.
            ->reject(fn (object $row): bool => (string) ($row->external_ref ?? '') === '')
            ->mapWithKeys(fn (object $row): array => [
                (string) $row->external_ref => StoreRecord::fromStorefrontRow($row),
            ]);
    }

    /**
     * Every ShopBrand row this user owns, across all their shop connections.
     *
     * The replacement for `ShopBrand::where('connection_id', $marker->id)`,
     * which now sees exactly one store instead of all of them.
     *
     * @deprecated Reads site.shop_brands. Use stores(). Deleted in Task 11.
     *
     * @return Builder<ShopBrand>
     */
    public function brands(User $user): Builder
    {
        $ids = $this->connectionIds($user);

        // whereIn on an empty list yields `in ()` — valid, matches nothing,
        // which is the correct answer for a user with no shop at all.
        return ShopBrand::query()->whereIn('connection_id', $ids);
    }

    /**
     * One brand by its brand id, across the user's whole shop family.
     *
     * @deprecated Reads site.shop_brands. Use store(). Deleted in Task 11.
     */
    public function brand(User $user, string $brandId): ?ShopBrand
    {
        return $this->brands($user)->where('brand_id', $brandId)->first();
    }
}
