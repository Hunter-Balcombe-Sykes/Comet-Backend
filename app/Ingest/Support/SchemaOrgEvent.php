<?php

namespace App\Ingest\Support;

/**
 * schema.org Event JSON-LD node → the one landed event doc shape Eventbrite
 * and Humanitix share (their sitepage cards have always rendered identically,
 * so their landed records should too — one projector serves both).
 *
 * Offer semantics ported from the legacy scrapers verbatim: the historical
 * first-entry collapse feeds availability, while the LOWEST price is taken
 * across the RAW offers list because Humanitix's leading AggregateOffer often
 * carries only priceCurrency with per-tier prices on later Offer entries.
 */
final class SchemaOrgEvent
{
    /** @return array<string, mixed>|null null when the node has no usable name */
    public static function map(array $node, string $fallbackUrl): ?array
    {
        $name = is_string($node['name'] ?? null) ? trim($node['name']) : '';
        if ($name === '') {
            return null;
        }

        $location = is_array($node['location'] ?? null) ? $node['location'] : [];
        $address = is_array($location['address'] ?? null) ? $location['address'] : [];

        $offersRaw = $node['offers'] ?? [];
        $first = $offersRaw;
        if (isset($first[0])) {
            $first = $first[0];
        }
        if (! is_array($first)) {
            $first = [];
        }

        $image = $node['image'] ?? null;
        if (is_array($image)) {
            $image = $image[0] ?? null;
        }

        $lowest = self::lowestOffer($offersRaw);
        $url = is_string($node['url'] ?? null) && $node['url'] !== '' ? $node['url'] : $fallbackUrl;

        return array_filter([
            'name' => $name,
            'url' => $url,
            'start_date' => is_string($node['startDate'] ?? null) ? $node['startDate'] : null,
            'end_date' => is_string($node['endDate'] ?? null) ? $node['endDate'] : null,
            'venue' => is_string($location['name'] ?? null) ? (trim($location['name']) ?: null) : null,
            'locality' => is_string($address['addressLocality'] ?? null)
                ? $address['addressLocality']
                : (is_string($address['addressRegion'] ?? null) ? $address['addressRegion'] : null),
            'description' => Html::plainText($node['description'] ?? null),
            'price_min' => $lowest['priceMin'],
            'currency' => $lowest['currency'],
            'availability' => self::availability($first['availability'] ?? null),
            'image' => is_string($image) ? $image : null,
        ], static fn ($v) => $v !== null);
    }

    /**
     * The LOWEST ticket price + its currency across any offers shape the
     * events platforms emit. Per entry `lowPrice` wins over `price` (an
     * AggregateOffer's own low); min across entries; currency = first declared.
     *
     * @return array{priceMin: ?float, currency: ?string}
     */
    private static function lowestOffer(mixed $offers): array
    {
        $list = is_array($offers) ? (array_is_list($offers) ? $offers : [$offers]) : [];

        $min = null;
        $currency = null;
        foreach ($list as $offer) {
            if (! is_array($offer)) {
                continue;
            }
            $cur = $offer['priceCurrency'] ?? null;
            if ($currency === null && is_string($cur) && $cur !== '') {
                $currency = strtoupper($cur);
            }
            foreach ([$offer['lowPrice'] ?? null, $offer['price'] ?? null] as $candidate) {
                if (is_numeric($candidate)) {
                    $price = (float) $candidate;
                    if ($min === null || $price < $min) {
                        $min = $price;
                    }
                    break; // lowPrice already IS this entry's low.
                }
            }
        }

        return ['priceMin' => $min, 'currency' => $currency];
    }

    /** schema.org availability URL → "available" | "sold_out" | null. */
    private static function availability(mixed $availability): ?string
    {
        if (! is_string($availability) || $availability === '') {
            return null;
        }
        $a = strtolower($availability);
        // OutOfStock is distinct from SoldOut but means the same to a buyer.
        if (str_contains($a, 'soldout') || str_contains($a, 'outofstock')) {
            return 'sold_out';
        }
        if (str_contains($a, 'instock') || str_contains($a, 'limited') || str_contains($a, 'presale') || str_contains($a, 'preorder')) {
            return 'available';
        }

        return null;
    }
}
