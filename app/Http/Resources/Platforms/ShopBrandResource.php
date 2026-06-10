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
        return [
            'id' => (string) ($this->resource['id'] ?? ''),
            'provider' => $this->resource['provider'] ?? 'shopify',
            'url' => $this->resource['url'] ?? null,
            'name' => $this->resource['name'] ?? null,
            'currency' => $this->resource['currency'] ?? null,
            'favicon' => $this->resource['favicon'] ?? null,
            'logo' => $this->resource['logo'] ?? null,
            'discountCode' => $this->resource['discountCode'] ?? '',
            'products' => $this->resource['products'] ?? [],
        ];
    }
}
