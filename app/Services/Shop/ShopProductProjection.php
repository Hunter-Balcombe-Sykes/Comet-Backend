<?php

namespace App\Services\Shop;

/**
 * Slice 5a §3.2: one site.shop_products.data blob → one projection for
 * ProjectionWriter::writeManualItem(). Pure — no I/O, no models — so the 51
 * real dev blobs can be fixtures.
 */
final class ShopProductProjection
{
    /** Shopify's placeholder for a product with no options. Names no choice. */
    private const PLACEHOLDER_VARIANT = 'Default Title';

    /** @return array<string, mixed> */
    public static function fromBlob(array $data, ?string $storeCurrency): array
    {
        $currency = self::str($data['currency']) ?? $storeCurrency;
        $url = (string) ($data['url'] ?? '');

        $variants = self::variants($data['variants'] ?? []);

        return array_filter([
            'kind' => 'product',
            'headline' => self::str($data['title']),
            'facets' => array_filter([
                'f_link' => $url === '' ? null : ['url' => $url],
                'f_catalog' => ($sku = self::str($data['productId'])) === null ? null : ['sku' => $sku],
            ]),
            'offers' => self::offers($data, $variants, $currency, $url),
            'variants' => array_map(
                fn (array $v) => ['label' => $v['label'], 'sku' => $v['sku']],
                $variants,
            ),
            'media' => self::media($data),
        ], static fn ($v, $k) => $v !== null && ($k === 'facets' ? $v !== [] : true), ARRAY_FILTER_USE_BOTH);
    }

    public static function coordFor(string $url): string
    {
        return 'manual:'.sha1($url);
    }

    /**
     * "200.00" → 20000. String arithmetic, never a float: every one of the 51
     * dev rows matches ^[0-9]+(\.[0-9]{1,2})?$ and a float round-trip is how a
     * cent goes missing.
     */
    public static function minorUnits(?string $price): ?int
    {
        if ($price === null || ! preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/', trim($price), $m)) {
            return null;
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /** @return list<array{label: string, sku: ?string, price: ?string, available: bool}> */
    private static function variants(mixed $raw): array
    {
        $out = [];
        foreach ((array) $raw as $variant) {
            $variant = (array) $variant;
            $label = trim((string) ($variant['title'] ?? ''));
            if ($label === '' || $label === self::PLACEHOLDER_VARIANT) {
                continue;
            }
            $out[] = [
                'label' => $label,
                'sku' => self::str($variant['id'] ?? null),
                'price' => self::str($variant['price'] ?? null),
                'available' => ($variant['available'] ?? true) !== false,
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function offers(array $data, array $variants, ?string $currency, string $url): array
    {
        $offers = [];
        $productAmount = self::minorUnits(self::str($data['price']));
        if ($productAmount !== null) {
            $offers[] = [
                'variant_label' => null,
                'amount_minor' => $productAmount,
                'currency' => $currency,
                'qualifier' => $productAmount === 0 ? 'free' : 'exact',
                'availability' => ($data['available'] ?? true) !== false ? 'in_stock' : 'out_of_stock',
                'url' => $url === '' ? null : $url,
            ];
        }

        foreach ($variants as $variant) {
            $amount = self::minorUnits($variant['price']);
            if ($amount === null) {
                continue;
            }
            $offers[] = [
                'variant_label' => $variant['label'],
                'amount_minor' => $amount,
                'currency' => $currency,
                'qualifier' => $amount === 0 ? 'free' : 'exact',
                'availability' => $variant['available'] ? 'in_stock' : 'out_of_stock',
                'url' => $url === '' ? null : $url,
            ];
        }

        return $offers;
    }

    /** @return list<array{role: string, url: string}> */
    private static function media(array $data): array
    {
        $cover = self::str($data['image']);
        $out = $cover === null ? [] : [['role' => 'cover', 'url' => $cover]];

        foreach ((array) ($data['images'] ?? []) as $image) {
            $image = self::str($image);
            // images[] repeats the cover on every dev row; one asset, one row.
            if ($image !== null && $image !== $cover) {
                $out[] = ['role' => 'gallery', 'url' => $image];
            }
        }

        return $out;
    }

    private static function str(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return ($value === null || $value === '') ? null : $value;
    }
}
