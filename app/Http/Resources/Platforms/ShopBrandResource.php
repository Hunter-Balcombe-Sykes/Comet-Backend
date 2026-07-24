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
 * objects passed through verbatim (each carries an absolute `url`).
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
            'linkMode' => $this->resource['linkMode'] ?? 'product',
            'referralQuery' => $this->resource['referralQuery'] ?? '',
            // Reserved bucket of individually-added products (no parent store);
            // the dashboard renders these without a store header.
            'individual' => (bool) ($this->resource['individual'] ?? false),
            'products' => $this->resource['products'] ?? [],
        ];

        // W9: conditional pass-through, mirroring ShopBrand::toBrandArray()'s
        // own optional-key emission — present only while a deferred connect is
        // pending/failed, so a settled brand's body stays byte-identical
        // (dark-merge / IntegrationContractGoldenMasterTest).
        if (array_key_exists('connectStatus', $this->resource)) {
            $data['connectStatus'] = $this->resource['connectStatus'];
        }
        if (array_key_exists('connectError', $this->resource)) {
            $data['connectError'] = $this->resource['connectError'];
        }

        return $data;
    }
}
