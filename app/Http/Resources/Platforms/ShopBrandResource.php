<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * One shop brand object inside the multi-brand map (formerly
 * ShopifyBrandResource — the shop integration is provider-agnostic now). Used
 * both singly (addBrand/updateBrand/setProducts) and as a collection
 * (brands/removeBrand). Shop is the only multi-resource platform, so the brand
 * is the sub-resource (mirrors PublicIntegrationConnectionResource's
 * SHOP_BRAND_ALLOWLIST).
 *
 * `$this->resource` is one brand ARRAY. `products[]` are scraped product
 * objects passed through verbatim (each carries an absolute `url`) — this is the
 * OWNER's dashboard, so verbatim is correct here. Do not assume parity with the
 * public sibling: since #API-1, PublicIntegrationConnectionResource additionally
 * filters every product through SHOP_PRODUCT_ALLOWLIST.
 * `provider` defaults to shopify for brands stored before the field existed.
 */
class ShopBrandResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => (string) ($this->resource['id'] ?? ''),
            'provider' => $this->resource['provider'] ?? 'shopify',
            'url' => $this->resource['url'] ?? null,
            'name' => $this->resource['name'] ?? null,
            'currency' => $this->resource['currency'] ?? null,
            'favicon' => $this->resource['favicon'] ?? null,
            'logo' => $this->resource['logo'] ?? null,
            'discountCode' => $this->resource['discountCode'] ?? '',
            'selectionMode' => $this->resource['selectionMode'] ?? 'manual',
            // Per-store auto-latest (2026-08-17, Sell opt-in): whether this
            // store's newest product publishes automatically.
            'autoLatest' => (bool) ($this->resource['autoLatest'] ?? true),
            'linkMode' => $this->resource['linkMode'] ?? 'product',
            'referralQuery' => $this->resource['referralQuery'] ?? '',
            // Reserved bucket of individually-added products (no parent store);
            // the dashboard renders these without a store header.
            'individual' => (bool) ($this->resource['individual'] ?? false),
            'products' => $this->resource['products'] ?? [],
        ];

        // W9: conditional pass-through, mirroring ShopContentReader::brandMap()'s
        // optional-key emission — present only while a deferred connect is
        // pending/failed, so a settled brand's body stays byte-identical
        // (dark-merge / IntegrationContractGoldenMasterTest).
        // Processed logo marks — conditional pass-through, same byte-identity
        // contract as the W9 keys below.
        if (array_key_exists('logoMark', $this->resource)) {
            $data['logoMark'] = $this->resource['logoMark'];
        }
        if (array_key_exists('logoMarkSvg', $this->resource)) {
            $data['logoMarkSvg'] = $this->resource['logoMarkSvg'];
        }
        if (array_key_exists('connectStatus', $this->resource)) {
            $data['connectStatus'] = $this->resource['connectStatus'];
        }
        if (array_key_exists('connectError', $this->resource)) {
            $data['connectError'] = $this->resource['connectError'];
        }
        // #SEM-1: same conditional pass-through — present only for a brand the
        // user hand-curated (ShopContentReader::brandMap() only sets the key
        // when non-null), keeping a non-curated brand's body byte-identical.
        if (array_key_exists('productsCuratedAt', $this->resource)) {
            $data['productsCuratedAt'] = $this->resource['productsCuratedAt'];
        }

        return $data;
    }
}
