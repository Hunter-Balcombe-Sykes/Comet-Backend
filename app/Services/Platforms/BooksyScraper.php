<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;

// Booksy business listings (booksy.com/{locale}/{id}_{slug}…) server-render
// schema.org JSON-LD for the business: name, image, aggregate rating, review
// count, and postal address — everything the sitepage booking card needs,
// keyless. The book action deep-links back to the listing.
class BooksyScraper extends PlatformScraper
{
    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /** Canonical listing URL (host + path only), or null for non-listing pages. */
    public function normalizeUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));
        $host = strtolower(preg_replace('~^www\.~i', '', $parts['host'] ?? ''));
        if ($host !== 'booksy.com') {
            return null;
        }

        // Listing paths start with a numeric business id: /en-au/12345_slug…
        if (! preg_match('~^/([a-z]{2}-[a-z]{2}/\d+_[^/]+)~i', $parts['path'] ?? '', $m)) {
            return null;
        }

        return 'https://booksy.com/'.$m[1];
    }

    /**
     * @return array{name:?string, image:?string, rating:?float, reviewCount:?int, address:?string}|null
     */
    public function fetchBusiness(string $url): ?array
    {
        $res = $this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }

        $node = $this->businessNode($this->jsonLdNodes($res['body']));
        if ($node === null) {
            return null;
        }

        $rating = $node['aggregateRating'] ?? null;

        return [
            'name' => is_string($node['name'] ?? null) ? trim($node['name']) : null,
            'image' => $this->firstImage($node['image'] ?? null),
            'rating' => isset($rating['ratingValue']) && is_numeric($rating['ratingValue'])
                ? round((float) $rating['ratingValue'], 2)
                : null,
            'reviewCount' => isset($rating['reviewCount']) && is_numeric($rating['reviewCount'])
                ? (int) $rating['reviewCount']
                : null,
            'address' => $this->composeAddress($node['address'] ?? null),
        ];
    }

    /** The first JSON-LD node that looks like the listed business. */
    private function businessNode(array $nodes): ?array
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $type = $node['@type'] ?? null;
            $types = is_array($type) ? $type : [$type];

            // Booksy categorises per vertical (HairSalon, BarberShop, NailSalon,
            // DaySpa…) — any typed node with a name and rating/address is the one.
            $isBusiness = (bool) array_filter($types, fn ($t) => is_string($t) && $t !== 'BreadcrumbList' && $t !== 'WebPage');
            if ($isBusiness && isset($node['name']) && (isset($node['aggregateRating']) || isset($node['address']))) {
                return $node;
            }
        }

        return null;
    }

    private function firstImage(mixed $image): ?string
    {
        if (is_string($image)) {
            return $image;
        }
        if (is_array($image)) {
            $first = $image['url'] ?? ($image[0] ?? null);

            return is_string($first) ? $first : (is_array($first) && is_string($first['url'] ?? null) ? $first['url'] : null);
        }

        return null;
    }

    /** "12 Side St, Suburb" from a PostalAddress node. */
    private function composeAddress(mixed $address): ?string
    {
        if (! is_array($address)) {
            return null;
        }
        $bits = array_filter([
            is_string($address['streetAddress'] ?? null) ? trim($address['streetAddress']) : null,
            is_string($address['addressLocality'] ?? null) ? trim($address['addressLocality']) : null,
        ]);

        return $bits === [] ? null : implode(', ', $bits);
    }
}
