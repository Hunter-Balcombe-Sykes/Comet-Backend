<?php

namespace App\Services\Brand;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\StoreRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The ShopBrandProfiler successor (WAVE-2C item 2; plan §12).
 *
 * The legacy profiler FETCHED: every question about a store's identity was
 * answered by going back to the platform (wp-json root, page JSON, meta.json),
 * which is why it needed one bespoke branch per provider and a deferred-job
 * half for the fetches that could not happen at request time.
 *
 * This one READS. Everything it reports was already earned by the pipeline:
 * the probe's evidence landed on content.storefronts (re-home Task 10 — it was
 * the site.shop_brands row until that table was dropped), the brand-asset
 * pipeline turned the store logo into owned bytes (content.media_assets via
 * content.brand_asset_refs), and the product catalog's offers carry the
 * store's trading currency. No branch per provider, no deferral, no second
 * HTTP request — a store's profile is a pure function of what we hold.
 *
 * Where the outputs flow (§12): store cards, ordering action buttons, menu
 * headers. The OWNED logo always outranks the hotlinked one — a CDN URL rots
 * and is a live third-party channel onto a user's page; bytes we sanitised
 * and address by content are neither.
 */
class StoreBrandProfiler
{
    public function __construct(private readonly ShopConnections $shop) {}

    /**
     * The display profile for a store connection.
     *
     * @return array{name: ?string, currency: ?string, logoUrl: ?string, faviconUrl: ?string, ownedLogo: bool}
     */
    public function profile(string $connectionId): array
    {
        $store = $this->storeFor($connectionId);
        $owned = $this->ownedAssetUrls($connectionId);

        return [
            'name' => $store?->name ?: $this->attributionName($connectionId),
            'currency' => $store?->currency ?: $this->catalogCurrency($connectionId),
            'logoUrl' => $owned['logo'] ?? ($store?->logoUrl ?: null),
            'faviconUrl' => $owned['favicon'] ?? ($store?->faviconUrl ?: null),
            'ownedLogo' => isset($owned['logo']),
        ];
    }

    /**
     * The store behind a connection id, off content.*.
     *
     * This class is connection-keyed throughout — profile(), ownedAssetUrls(),
     * catalogCurrency() and attributionName() all take a connection id, because
     * content.brand_asset_refs and content.sources genuinely carry one.
     * content.storefronts does NOT, so there is no join path from a connection
     * to a store inside content.* at all. The bridge is
     * site.platform_connections itself: ShopConnections::anchor() and
     * SourceReconciler::applyIntent() both write resource_id = the store id, so
     * the connection row carries the owner and the external_ref this needs.
     *
     * The legacy read took the connection's LOWEST-position brand. With one
     * connection per store that set has exactly one member, so resolving by
     * resource_id is the same answer by a route that survives the DROP.
     */
    private function storeFor(string $connectionId): ?StoreRecord
    {
        // Eager-loaded, not read lazily off a fresh find(): nothing else loads
        // this relation here, and ShopConnections::anchorFor() goes out of its
        // way to setRelation('user', …) to avoid exactly this shape. A lazy
        // read works today only because preventLazyLoading is not armed on a
        // single-row hydrate.
        $connection = IntegrationConnection::with('user')->find($connectionId);
        $user = $connection?->user;
        if ($user === null) {
            return null;
        }

        return $this->shop->store($user, (string) $connection->resource_id);
    }

    /**
     * Owned, sanitised brand assets for the connection, by role. logo_full is
     * preferred over logo_square for the same reason SiteAccentResolver
     * prefers it: the fuller mark is the more representative one.
     *
     * @return array{logo?: string, favicon?: string}
     */
    private function ownedAssetUrls(string $connectionId): array
    {
        $refs = DB::table('content.brand_asset_refs')
            ->join('content.media_assets', 'content.media_assets.id', '=', 'content.brand_asset_refs.asset_id')
            ->where('content.brand_asset_refs.connection_id', $connectionId)
            ->whereNotNull('content.media_assets.storage_path')
            ->get(['content.brand_asset_refs.role', 'content.media_assets.storage_path']);

        $byRole = [];
        foreach ($refs as $ref) {
            $byRole[(string) $ref->role] = Storage::disk((string) config('partna.media_disk'))
                ->url((string) $ref->storage_path);
        }

        $out = [];
        $logo = $byRole['logo_full'] ?? $byRole['logo_square'] ?? null;
        if ($logo !== null) {
            $out['logo'] = $logo;
        }
        if (isset($byRole['favicon'])) {
            $out['favicon'] = $byRole['favicon'];
        }

        return $out;
    }

    /**
     * The store's trading currency as its landed offers tell it: the modal
     * currency across every offer contributed by this connection's source
     * channel. Ties break to the most recently updated, because a store that
     * changed currency did so once, forward.
     */
    private function catalogCurrency(string $connectionId): ?string
    {
        $currency = DB::table('content.offers')
            ->join('content.sources', 'content.sources.id', '=', 'content.offers.source_id')
            ->where('content.sources.connection_id', $connectionId)
            ->whereNotNull('content.offers.currency')
            ->groupBy('content.offers.currency')
            ->orderByRaw('count(*) DESC, max(content.offers.updated_at) DESC')
            ->limit(1)
            ->value('currency');

        return $currency === null ? null : (string) $currency;
    }

    /**
     * The ingest job records the store's display name as the ref's
     * attribution (owned bytes carry the obligation to say whose they are) —
     * which makes it an honest last-resort name for a row the probe could not
     * name. A URL-shaped attribution is not a name and is never promoted.
     */
    private function attributionName(string $connectionId): ?string
    {
        $attribution = DB::table('content.brand_asset_refs')
            ->where('connection_id', $connectionId)
            ->whereNotNull('attribution')
            ->orderBy('updated_at', 'desc')
            ->value('attribution');

        if (! is_string($attribution)) {
            return null;
        }

        $attribution = trim($attribution);
        if ($attribution === '' || preg_match('~^https?://~i', $attribution)) {
            return null;
        }

        return $attribution;
    }
}
