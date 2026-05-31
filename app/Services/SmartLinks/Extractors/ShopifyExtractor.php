<?php

namespace App\Services\SmartLinks\Extractors;

use App\Services\SmartLinks\MetadataParser;
use App\Services\SmartLinks\ParsedUrl;
use App\Services\SmartLinks\ResolvedSmartLinkData;
use App\Services\SmartLinks\SafeUrlException;
use App\Services\SmartLinks\SafeUrlFetcher;

/**
 * Shopify commerce extractor using the public, unauthenticated `.json`
 * endpoints. Serves commerce.product (`/products/{handle}.json`) and
 * commerce.collection (`/collections/{handle}.json`). Returns null when the
 * probe fails (store disabled the endpoint, WAF, not actually Shopify) so the
 * resolver falls through to the generic StructuredDataExtractor for products.
 */
class ShopifyExtractor implements SmartLinkExtractor
{
    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly MetadataParser $parser,
    ) {}

    public function matches(ParsedUrl $url, string $type): bool
    {
        return match ($type) {
            'commerce.product' => in_array('products', $url->pathSegments, true),
            'commerce.collection' => in_array('collections', $url->pathSegments, true),
            default => false,
        };
    }

    public function resolve(ParsedUrl $url, string $type): ?ResolvedSmartLinkData
    {
        return match ($type) {
            'commerce.product' => $this->resolveProduct($url),
            'commerce.collection' => $this->resolveCollection($url),
            default => null,
        };
    }

    private function resolveProduct(ParsedUrl $url): ?ResolvedSmartLinkData
    {
        $handle = $this->segmentAfter($url, 'products');
        if ($handle === null) {
            return null;
        }
        $json = $this->getJson("{$url->scheme}://{$url->host}/products/{$handle}.json");
        $product = $json['product'] ?? null;
        if (! is_array($product)) {
            return null;
        }

        $image = $this->str($product['images'][0]['src'] ?? $product['image']['src'] ?? null);
        $variants = is_array($product['variants'] ?? null) ? $product['variants'] : [];
        $variant = $this->pickVariant($variants);
        $price = isset($variant['price']) && is_numeric($variant['price']) ? (float) $variant['price'] : null;
        $anyAvailable = false;
        foreach ($variants as $v) {
            if (($v['available'] ?? false) === true) {
                $anyAvailable = true;
                break;
            }
        }

        return new ResolvedSmartLinkData(
            title: $this->str($product['title'] ?? null),
            imageUrl: $image,
            faviconUrl: "{$url->scheme}://{$url->host}/favicon.ico",
            brandName: $this->str($product['vendor'] ?? null) ?? $this->hostBrand($url->host),
            platform: 'shopify',
            metadata: array_filter([
                'price' => $price,
                'currency' => $this->shopCurrency($url),
                'stockStatus' => $variants === [] ? 'unknown' : ($anyAvailable ? 'in_stock' : 'out_of_stock'),
            ], fn ($v) => $v !== null),
            imageSources: array_filter([
                'image_url' => $image,
                'favicon_url' => "{$url->scheme}://{$url->host}/favicon.ico",
            ]),
        );
    }

    private function resolveCollection(ParsedUrl $url): ?ResolvedSmartLinkData
    {
        $handle = $this->segmentAfter($url, 'collections');
        if ($handle === null) {
            return null;
        }
        $json = $this->getJson("{$url->scheme}://{$url->host}/collections/{$handle}.json");
        $collection = $json['collection'] ?? null;
        if (! is_array($collection)) {
            return null;
        }

        $image = $this->str($collection['image']['src'] ?? null);
        [$brandName, $favicon, $logo] = $this->brandFieldsFromRoot($url);

        return new ResolvedSmartLinkData(
            title: $this->str($collection['title'] ?? null),
            imageUrl: $image,
            faviconUrl: $favicon,
            brandName: $brandName,
            brandLogoUrl: $logo,
            platform: 'shopify',
            metadata: array_filter([
                'collectionName' => $this->str($collection['title'] ?? null),
            ], fn ($v) => $v !== null),
            imageSources: array_filter([
                'image_url' => $image,
                'favicon_url' => $favicon,
                'brand_logo_url' => $logo,
            ]),
        );
    }

    /** Prefer the first available variant, else the first variant. */
    private function pickVariant(array $variants): array
    {
        foreach ($variants as $v) {
            if (($v['available'] ?? false) === true) {
                return $v;
            }
        }

        return $variants[0] ?? [];
    }

    /** products.json carries no currency — read the shop's /meta.json. */
    private function shopCurrency(ParsedUrl $url): ?string
    {
        $meta = $this->getJson("{$url->scheme}://{$url->host}/meta.json");

        return $this->str($meta['currency'] ?? null);
    }

    /** @return array{0:string,1:?string,2:?string} [brandName, faviconUrl, logoUrl] */
    private function brandFieldsFromRoot(ParsedUrl $url): array
    {
        $favicon = "{$url->scheme}://{$url->host}/favicon.ico";
        $name = $this->hostBrand($url->host);
        $logo = null;
        try {
            $res = $this->fetcher->fetch("{$url->scheme}://{$url->host}/");
            if ($res['status'] < 400 && str_contains(strtolower($res['contentType']), 'html')) {
                $meta = $this->parser->parse($res['body'], "{$url->scheme}://{$url->host}/");
                $name = $meta->og('og:site_name') ?? $meta->title ?? $name;
                $favicon = $meta->faviconUrl ?? $favicon;
                $org = $meta->jsonLdOfType('Organization');
                $logo = $this->str($org['logo']['url'] ?? (is_string($org['logo'] ?? null) ? $org['logo'] : null));
            }
        } catch (SafeUrlException) {
            // keep fallbacks
        }

        return [$name, $favicon, $logo];
    }

    private function getJson(string $url): array
    {
        try {
            $res = $this->fetcher->fetch($url, ['Accept' => 'application/json']);
        } catch (SafeUrlException) {
            return [];
        }
        if ($res['status'] >= 400) {
            return [];
        }
        $decoded = json_decode($res['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    private function segmentAfter(ParsedUrl $url, string $marker): ?string
    {
        $idx = array_search($marker, $url->pathSegments, true);
        if ($idx === false) {
            return null;
        }

        return $url->pathSegments[$idx + 1] ?? null;
    }

    private function str(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $t = trim($v);

        return $t === '' ? null : $t;
    }

    private function hostBrand(string $host): string
    {
        $host = preg_replace('/^www\./', '', $host);

        return ucfirst(explode('.', $host)[0] ?? $host);
    }
}
