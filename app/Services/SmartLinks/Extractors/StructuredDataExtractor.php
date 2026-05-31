<?php

namespace App\Services\SmartLinks\Extractors;

use App\Services\SmartLinks\MetadataParser;
use App\Services\SmartLinks\ParsedMetadata;
use App\Services\SmartLinks\ParsedUrl;
use App\Services\SmartLinks\ResolvedSmartLinkData;
use App\Services\SmartLinks\SafeUrlException;
use App\Services\SmartLinks\SafeUrlFetcher;

/**
 * JSON-LD + OpenGraph + favicon extractor. Serves:
 *   - commerce.event   (Eventbrite — JSON-LD Event)
 *   - commerce.product (generic, non-Shopify fallback — JSON-LD Product → OG)
 *   - commerce.brand   (any store homepage — OG/Organization + favicon)
 *   - custom-link enrichment (favicon + page name)
 */
class StructuredDataExtractor implements SmartLinkExtractor
{
    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly MetadataParser $parser,
    ) {}

    public function matches(ParsedUrl $url, string $type): bool
    {
        // The generic fallback — handles any commerce type or custom enrichment.
        return str_starts_with($type, 'commerce.') || $type === 'custom';
    }

    public function resolve(ParsedUrl $url, string $type): ?ResolvedSmartLinkData
    {
        $meta = $this->fetchMeta($url->canonical);
        if ($meta === null) {
            return null;
        }

        return match ($type) {
            'commerce.event' => $this->resolveEvent($url, $meta),
            'commerce.product' => $this->resolveProduct($url, $meta),
            'commerce.brand' => $this->resolveBrand($url, $meta),
            'custom' => $this->resolveCustom($url, $meta),
            default => null,
        };
    }

    private function fetchMeta(string $url): ?ParsedMetadata
    {
        try {
            $res = $this->fetcher->fetch($url);
        } catch (SafeUrlException) {
            return null;
        }
        if ($res['status'] >= 400 || ! str_contains(strtolower($res['contentType']), 'html')) {
            return null;
        }

        return $this->parser->parse($res['body'], $url);
    }

    private function resolveEvent(ParsedUrl $url, ParsedMetadata $meta): ResolvedSmartLinkData
    {
        $event = $meta->jsonLdOfType('Event') ?? [];
        $name = $this->str($event['name'] ?? null) ?? $meta->og('og:title') ?? $meta->title;
        $image = $this->str($this->firstImage($event['image'] ?? null)) ?? $meta->og('og:image');
        $startsAt = $this->str($event['startDate'] ?? null);
        $endsAt = $this->str($event['endDate'] ?? null);
        $location = $this->eventLocation($event['location'] ?? null);

        return new ResolvedSmartLinkData(
            title: $name,
            imageUrl: $image,
            faviconUrl: $meta->faviconUrl,
            brandName: $meta->og('og:site_name') ?? 'Eventbrite',
            platform: str_contains($url->host, 'eventbrite') ? 'eventbrite' : 'generic',
            metadata: array_filter([
                'startsAt' => $startsAt,
                'endsAt' => $endsAt,
                'location' => $location,
            ], fn ($v) => $v !== null),
            imageSources: $image ? ['image_url' => $image] : [],
        );
    }

    private function resolveProduct(ParsedUrl $url, ParsedMetadata $meta): ResolvedSmartLinkData
    {
        $product = $meta->jsonLdOfType('Product');
        $name = null;
        $image = null;
        $price = null;
        $currency = null;
        $stock = 'unknown';

        if ($product !== null) {
            $name = $this->str($product['name'] ?? null);
            $image = $this->str($this->firstImage($product['image'] ?? null));
            [$price, $currency, $stock] = $this->offerFields($product['offers'] ?? null);
        }

        // OG fallback.
        $name ??= $meta->og('og:title') ?? $meta->title;
        $image ??= $meta->og('og:image');
        $price ??= $this->numeric($meta->og('product:price:amount'));
        $currency ??= $meta->og('product:price:currency');

        return new ResolvedSmartLinkData(
            title: $name,
            imageUrl: $image,
            faviconUrl: $meta->faviconUrl,
            brandName: $meta->og('og:site_name') ?? $this->hostBrand($url->host),
            platform: 'generic',
            metadata: array_filter([
                'price' => $price,
                'currency' => $currency,
                'stockStatus' => $stock,
            ], fn ($v) => $v !== null),
            imageSources: $image ? ['image_url' => $image] : [],
        );
    }

    private function resolveBrand(ParsedUrl $url, ParsedMetadata $meta): ResolvedSmartLinkData
    {
        $org = $meta->jsonLdOfType('Organization');
        $name = $this->str($org['name'] ?? null) ?? $meta->og('og:site_name') ?? $meta->title ?? $this->hostBrand($url->host);
        $logo = $this->str($this->firstImage($org['logo'] ?? null));
        $hero = $meta->og('og:image');

        return new ResolvedSmartLinkData(
            title: $name,
            imageUrl: $hero,
            faviconUrl: $meta->faviconUrl,
            brandName: $name,
            brandLogoUrl: $logo,
            platform: 'generic',
            metadata: array_filter(['heroImageUrl' => $hero], fn ($v) => $v !== null),
            imageSources: array_filter([
                'image_url' => $hero,
                'brand_logo_url' => $logo,
                'favicon_url' => $meta->faviconUrl,
            ], fn ($v) => $v !== null),
        );
    }

    private function resolveCustom(ParsedUrl $url, ParsedMetadata $meta): ResolvedSmartLinkData
    {
        $name = $meta->og('og:site_name') ?? $meta->og('og:title') ?? $meta->title ?? $this->hostBrand($url->host);

        return new ResolvedSmartLinkData(
            title: $name,
            faviconUrl: $meta->faviconUrl,
            brandName: $name,
            platform: 'generic',
        );
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** @return array{0:?float,1:?string,2:string} [price, currency, stockStatus] */
    private function offerFields(mixed $offers): array
    {
        if (! is_array($offers)) {
            return [null, null, 'unknown'];
        }
        // AggregateOffer or a list → take the first concrete offer.
        $offer = $offers;
        if (array_is_list($offers)) {
            $offer = $offers[0] ?? [];
        }
        $price = $this->numeric($offer['price'] ?? $offer['lowPrice'] ?? null);
        $currency = $this->str($offer['priceCurrency'] ?? null);
        $availability = strtolower($this->str($offer['availability'] ?? '') ?? '');
        $stock = 'unknown';
        if ($availability !== '') {
            $stock = str_contains($availability, 'instock') || str_contains($availability, 'in_stock')
                ? 'in_stock'
                : (str_contains($availability, 'outofstock') || str_contains($availability, 'soldout') ? 'out_of_stock' : 'unknown');
        }

        return [$price, $currency, $stock];
    }

    private function eventLocation(mixed $location): ?string
    {
        if (is_string($location)) {
            return trim($location) ?: null;
        }
        if (! is_array($location)) {
            return null;
        }
        if (array_is_list($location)) {
            $location = $location[0] ?? [];
        }
        $type = strtolower($this->str($location['@type'] ?? '') ?? '');
        if (str_contains($type, 'virtual')) {
            return 'Online';
        }
        $name = $this->str($location['name'] ?? null);
        $address = $location['address'] ?? null;
        if (is_array($address)) {
            $parts = array_filter([
                $this->str($address['streetAddress'] ?? null),
                $this->str($address['addressLocality'] ?? null),
                $this->str($address['addressRegion'] ?? null),
            ]);
            $addressStr = implode(', ', $parts);
        } else {
            $addressStr = $this->str($address);
        }

        return trim(implode(' · ', array_filter([$name, $addressStr]))) ?: null;
    }

    private function firstImage(mixed $image): ?string
    {
        if (is_string($image)) {
            return $image;
        }
        if (is_array($image)) {
            if (array_is_list($image)) {
                $first = $image[0] ?? null;

                return is_string($first) ? $first : ($this->str($first['url'] ?? null));
            }

            return $this->str($image['url'] ?? null);
        }

        return null;
    }

    private function str(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $t = trim($v);

        return $t === '' ? null : $t;
    }

    private function numeric(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v) && is_numeric(trim($v))) {
            return (float) trim($v);
        }

        return null;
    }

    /** "gymshark.com" → "Gymshark" (last-resort brand name). */
    private function hostBrand(string $host): string
    {
        $host = preg_replace('/^www\./', '', $host);
        $label = explode('.', $host)[0] ?? $host;

        return ucfirst($label);
    }
}
