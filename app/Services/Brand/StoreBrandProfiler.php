<?php

namespace App\Services\Brand;

use App\Models\Core\Site\ShopBrand;
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
 * the probe's evidence landed on the site.shop_brands row, the brand-asset
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
    /**
     * The display profile for a store connection.
     *
     * @return array{name: ?string, currency: ?string, logoUrl: ?string, faviconUrl: ?string, ownedLogo: bool}
     */
    public function profile(string $connectionId): array
    {
        $brand = ShopBrand::query()
            ->where('connection_id', $connectionId)
            ->orderBy('position')
            ->first();

        $owned = $this->ownedAssetUrls($connectionId);

        return [
            'name' => $brand?->name ?: $this->attributionName($connectionId),
            'currency' => $brand?->currency ?: $this->catalogCurrency($connectionId),
            'logoUrl' => $owned['logo'] ?? ($brand?->logo ?: null),
            'faviconUrl' => $owned['favicon'] ?? ($brand?->favicon ?: null),
            'ownedLogo' => isset($owned['logo']),
        ];
    }

    /**
     * Fill what a pending brand row is missing from the catalog — the
     * successor to the legacy forRow() deferral, without its re-fetch: by the
     * time anyone asks, the products have landed and carry the answer.
     *
     * Fill-if-empty only. A value the probe (or the user) already put on the
     * row is someone else's truth; this method never competes with it.
     */
    public function settle(ShopBrand $brand): bool
    {
        $changed = false;

        if (($brand->currency ?? '') === '') {
            $currency = $this->catalogCurrency((string) $brand->connection_id);
            if ($currency !== null) {
                $brand->currency = $currency;
                $changed = true;
            }
        }

        if (($brand->name ?? '') === '') {
            $name = $this->attributionName((string) $brand->connection_id);
            if ($name !== null) {
                $brand->name = $name;
                $changed = true;
            }
        }

        if ($changed) {
            $brand->save();
        }

        return $changed;
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
