<?php

namespace App\Services\Shop;

use App\Models\Core\Site\ShopBrand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Slice 5a §3.1: the one place that upserts a site.shop_brands row into its
 * content.collections + content.storefronts shape. Lives here (not on
 * ShopBackfiller, Services/Migration/) because Task 6's scheduled syncStore()
 * needs the identical upsert and a sync path must not depend on a
 * Services/Migration/ class — ShopBackfiller injects this and calls the same
 * method.
 */
class ShopContentWriter
{
    /**
     * Idempotent: keyed by (user_id, provider, external_ref) — external_ref
     * is site.shop_brands.brand_id, the PROVIDER's own store id (half of
     * shop_brands_connection_id_brand_id_key), stable across a rename.
     *
     * The label (the brand's display name) was the ORIGINAL key and is a
     * bug: it's a mutable, user-editable field (ShopController::updateBrand
     * writes site.shop_brands.name freely). A rename between two upsertStore()
     * calls — which Task 6's syncStore() makes on every scheduled cycle —
     * missed the old lookup, minting a second content.collections +
     * content.storefronts pair and orphaning the first, taking its
     * referral_query/discount_code (affiliate revenue) with it while both
     * rows stayed linked to the same product items.
     */
    public function upsertStore(ShopBrand $brand, string $ownerId): string
    {
        $externalRef = (string) $brand->brand_id;

        $existing = DB::table('content.collections as c')
            ->join('content.storefronts as s', 's.collection_id', '=', 'c.id')
            ->where('c.user_id', $ownerId)
            ->where('c.kind', 'storefront')
            ->where('s.provider', (string) $brand->provider)
            ->where('s.external_ref', $externalRef)
            ->value('c.id');

        $collectionId = (string) ($existing ?? Str::uuid());

        DB::table('content.collections')->upsert([[
            'id' => $collectionId,
            'user_id' => $ownerId,
            'parent_id' => null,
            'label' => (string) ($brand->name ?? $brand->brand_id),
            'kind' => 'storefront',
            'position' => (int) $brand->position,
            'is_user_created' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['id'], ['label', 'position', 'updated_at']);

        DB::table('content.storefronts')->upsert([[
            'collection_id' => $collectionId,
            'provider' => (string) $brand->provider,
            'external_ref' => $externalRef,
            'url' => $brand->url,
            'source_url' => $brand->source_url,
            'currency' => $brand->currency,
            'discount_code' => $brand->discount_code,
            'referral_query' => (string) ($brand->referral_query ?? ''),
            'is_individual' => (bool) $brand->is_individual,
            'fetch_mode' => $brand->fetch_mode,
            'connect_status' => $brand->connect_status,
            'connect_error' => $brand->connect_error,
            'products_curated_at' => $brand->products_curated_at,
            'logo_url' => $brand->logo,
            'favicon_url' => $brand->favicon,
            'logo_mark_url' => $brand->logo_mark_url,
            'logo_mark_svg_url' => $brand->logo_mark_svg_url,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['collection_id'], [
            'provider', 'external_ref', 'url', 'source_url', 'currency', 'discount_code',
            'referral_query', 'is_individual', 'fetch_mode', 'connect_status',
            'connect_error', 'products_curated_at', 'logo_url', 'favicon_url',
            'logo_mark_url', 'logo_mark_svg_url', 'updated_at',
        ]);

        return $collectionId;
    }
}
