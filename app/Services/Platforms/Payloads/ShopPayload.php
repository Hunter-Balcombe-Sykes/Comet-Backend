<?php

namespace App\Services\Platforms\Payloads;

// Typed boundary for the multi-brand `shop` archetype's in-memory shape: a MAP
// keyed by brand id ({ "<brandId>": {brand}, "individual": {brand}, … }).
//
// FOUND-25: brands are no longer STORED this way — they live in the relational
// site.shop_brands/site.shop_products child tables, hydrated into this same map
// shape by ShopController::brandMap() via ShopBrand::toBrandArray(). This DTO
// now only serves the in-memory helpers built around that shape: the is-array
// guard on a possibly-garbage input, and primaryWithProducts() for the COMPAT
// /selection endpoint. `provider ??= 'shopify'` stays as a defensive default —
// harmless now that every relational row always has a provider, but keeps this
// tolerant of any caller passing an incomplete array.
//
// It PRESERVES every brand array VERBATIM apart from that provider default —
// products pass through untouched (each is an upstream-shaped object carrying an
// absolute url), and internal keys (`sourceUrl`, `fetchMode`) MUST survive because
// ShopController::providerProducts() dispatches on them. That is why this
// normalizes only `provider` and never imposes a fixed brand key set.
final readonly class ShopPayload
{
    /** @param array<string,mixed> $brands brand-keyed map; provider defaulted, all else verbatim */
    public function __construct(public array $brands) {}

    /** Hydrate the stored connection payload (a brand-keyed map, or null/garbage). */
    public static function fromArray(mixed $payload): self
    {
        if (! is_array($payload)) {
            return new self([]);
        }

        $brands = [];
        foreach ($payload as $id => $brand) {
            if (is_array($brand)) {
                // Brands stored before the provider field existed are Shopify
                // (the only provider back then).
                $brand['provider'] ??= 'shopify';
            }
            $brands[$id] = $brand;
        }

        return new self($brands);
    }

    /** Back to the stored brand-keyed map (provider defaulted, else verbatim). */
    public function toArray(): array
    {
        return $this->brands;
    }

    /** Brands as a plain ordered list (drops the id keys). */
    public function all(): array
    {
        return array_values($this->brands);
    }

    /**
     * The COMPAT "primary" brand for the legacy single-brand /selection view: the
     * first brand that has at least one chosen product, or null. Mirrors
     * ShopController::selection()'s `first(fn ($b) => ! empty($b['products']))`.
     *
     * @return array<string,mixed>|null
     */
    public function primaryWithProducts(): ?array
    {
        foreach ($this->brands as $brand) {
            if (is_array($brand) && ! empty($brand['products'])) {
                return $brand;
            }
        }

        return null;
    }
}
