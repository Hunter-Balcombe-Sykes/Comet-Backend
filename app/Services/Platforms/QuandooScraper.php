<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;

// Quandoo restaurant pages (quandoo.com.au/place/{slug}-{id}) server-render
// a schema.org Restaurant JSON-LD node: name, image, aggregate rating (out
// of 6), review count, cuisines, and postal address. Keyless; the reserve
// action deep-links back to the listing.
class QuandooScraper extends PlatformScraper
{
    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /** Canonical /place/ URL, or null when the link isn't a restaurant page. */
    public function normalizeUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));
        $host = strtolower(preg_replace('~^www\.~i', '', $parts['host'] ?? ''));
        if (! preg_match('~^quandoo\.(?:com\.au|com|co\.uk|de|at|ch|it|nl|fi|sg|hk)$~', $host)) {
            return null;
        }

        if (! preg_match('~^/(place/[a-z0-9-]+)~i', $parts['path'] ?? '', $m)) {
            return null;
        }

        return "https://www.{$host}/".strtolower($m[1]);
    }

    /**
     * @return array{name:?string, image:?string, rating:?float, bestRating:?int, reviewCount:?int, cuisines:list<string>, address:?string}|null
     */
    public function fetchRestaurant(string $url): ?array
    {
        $res = $this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }

        $node = null;
        foreach ($this->jsonLdNodes($res['body']) as $candidate) {
            $types = is_array($candidate['@type'] ?? null) ? $candidate['@type'] : [$candidate['@type'] ?? null];
            if (in_array('Restaurant', $types, true) || in_array('FoodEstablishment', $types, true)) {
                $node = $candidate;
                break;
            }
        }
        if ($node === null) {
            return null;
        }

        $rating = $node['aggregateRating'] ?? null;
        $cuisines = $node['servesCuisine'] ?? [];
        $address = $node['address'] ?? null;

        return [
            'name' => is_string($node['name'] ?? null) ? trim($node['name']) : null,
            'image' => is_string($node['image'] ?? null) ? $node['image'] : (is_string($node['image'][0] ?? null) ? $node['image'][0] : null),
            'rating' => isset($rating['ratingValue']) && is_numeric($rating['ratingValue'])
                ? round((float) $rating['ratingValue'], 1)
                : null,
            // Quandoo scores out of 6, not 5 — the UI needs the scale.
            'bestRating' => isset($rating['bestRating']) && is_numeric($rating['bestRating'])
                ? (int) $rating['bestRating']
                : null,
            'reviewCount' => isset($rating['reviewCount']) && is_numeric($rating['reviewCount'])
                ? (int) $rating['reviewCount']
                : null,
            'cuisines' => array_values(array_filter(
                array_slice(is_array($cuisines) ? $cuisines : [$cuisines], 0, 3),
                'is_string',
            )),
            'address' => is_array($address)
                ? (implode(', ', array_filter([
                    is_string($address['streetAddress'] ?? null) ? trim($address['streetAddress']) : null,
                    is_string($address['addressLocality'] ?? null) ? trim($address['addressLocality']) : null,
                ])) ?: null)
                : null,
        ];
    }
}
