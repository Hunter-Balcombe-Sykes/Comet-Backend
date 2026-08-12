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
     * Idempotent: keyed by (user_id, kind='storefront', label) so a re-run
     * updates the existing row rather than minting a duplicate collection.
     */
    public function upsertStore(ShopBrand $brand, string $ownerId): string
    {
        $existing = DB::table('content.collections')
            ->where('user_id', $ownerId)
            ->where('kind', 'storefront')
            ->where('label', (string) ($brand->name ?? $brand->brand_id))
            ->value('id');

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
            'provider', 'url', 'source_url', 'currency', 'discount_code',
            'referral_query', 'is_individual', 'fetch_mode', 'connect_status',
            'connect_error', 'products_curated_at', 'logo_url', 'favicon_url',
            'logo_mark_url', 'logo_mark_svg_url', 'updated_at',
        ]);

        return $collectionId;
    }
}
