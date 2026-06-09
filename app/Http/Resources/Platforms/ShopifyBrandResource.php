<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * One Shopify brand object inside the multi-brand map. Used both singly
 * (addBrand/updateBrand/setProducts) and as a collection (brands/removeBrand).
 * Shopify is the only multi-resource platform, so the brand is the sub-resource
 * (mirrors PublicIntegrationConnectionResource::SHOPIFY_BRAND_ALLOWLIST).
 *
 * `$this->resource` is one brand ARRAY. `products[]` are scraped product
 * objects passed through verbatim. `id` is string-cast per the ApiResource
 * house contract (the brand id is already a string, so this is a no-op that
 * keeps the type stable).
 */
class ShopifyBrandResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) ($this->resource['id'] ?? ''),
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
