<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\Http\SafeUrlFetcher;
use App\Services\V5\Scraping\BaseTemplates\ApiBase;
use App\Services\V5\Scraping\Contracts\FetchContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// V5 Google Business scraper — fetches business info from the Google Places
// API (New). Accepts a place ID (ChIJ...) or a Google Maps URL (including
// short links like maps.app.goo.gl).
//
// Returns:
//   items:
//     1 service item with business profile fields
//     N review items (up to 5)
//   profile:
//     display_name, profile_pic_url, bio, follower_count
//
// When the Places API key is not configured, returns a minimal manual item so
// the workplace page still shows connected data.
class GoogleBusinessScraper extends ApiBase implements FetchContract
{
    protected string $endpoint = 'https://places.googleapis.com/v1';
    protected string $authType = 'none'; // Places API uses X-Goog-Api-Key, not Authorization
    protected string $apiKey = '';

    // Fields requested from the Places API (New).
    private const FIELD_MASK = 'id,displayName,formattedAddress,location,businessStatus,primaryTypeDisplayName,googleMapsUri,rating,userRatingCount,nationalPhoneNumber,websiteUri,regularOpeningHours,editorialSummary,reviews(authorAttribution.displayName,authorAttribution.uri,authorAttribution.photoUri,rating,text.text,relativePublishTimeDescription,publishTime),photos(name,widthPx,heightPx,authorAttributions.displayName),reviewSummary';

    public function __construct(
        SafeUrlFetcher $fetcher,
    ) {
        parent::__construct($fetcher);
        $this->apiKey = config('services.google_maps.server_api_key', '');
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Fetch Google Business data from a Maps URL or place ID.
     *
     * @return array{items: list<array>, profile: array}
     */
    public function fetch(string $identifier): array
    {
        $input = trim($identifier);
        if ($input === '') {
            return ['items' => [], 'profile' => []];
        }

        $placeId = null;
        $placeName = null;
        $lat = null;
        $lng = null;

        // 1. Check if input is already a place ID (ChIJ...)
        if (preg_match('/^ChI[A-Za-z0-9_\-]+$/', $input)) {
            $placeId = $input;
        }

        // 2. Try to resolve URLs — short links -> full Maps URL -> parse
        if ($placeId === null) {
            $url = $this->resolveToMapsUrl($input);
            $parsed = $this->parseMapsUrl($url);
            if ($parsed !== null) {
                $placeId = $parsed['place_id'] ?? null;
                $placeName = $parsed['name'] ?? null;
                $lat = $parsed['lat'] ?? null;
                $lng = $parsed['lng'] ?? null;
            }
        }

        // 3. With a confirmed place ID (ChIJ prefix) — fetch details directly
        if ($placeId !== null && str_starts_with($placeId, 'ChIJ') && $this->hasApiKey()) {
            $details = $this->fetchPlaceDetails($placeId);
            if ($details !== null) {
                return $this->buildResult($details, $placeId);
            }
        }

        // 4. Have a place name from URL parsing — try Text Search (most reliable
        //    for URL-derived data where the extracted place ID may be a non-ChIJ
        //    data segment rather than a real place ID)
        if ($placeName !== null && $this->hasApiKey()) {
            $details = $this->searchPlace($placeName, $lat, $lng);
            if ($details !== null) {
                $fetchedPlaceId = data_get($details, 'id');
                return $this->buildResult($details, $fetchedPlaceId);
            }
        }

        // 5. Non-ChIJ place ID extracted from URL — try direct fetch anyway
        if ($placeId !== null && $this->hasApiKey()) {
            $details = $this->fetchPlaceDetails($placeId);
            if ($details !== null) {
                return $this->buildResult($details, $placeId);
            }
        }

        // 6. Fallback — no API key or fetch failed; return manual items
        $reason = $this->hasApiKey()
            ? 'Could not resolve or fetch place details for: '.$input
            : 'Google Places API key not configured (services.google_maps.server_api_key)';
        $this->logFailure('google_business', 'fetch', $reason);

        return $this->buildFallbackResult($placeName ?? $input);
    }

    // -----------------------------------------------------------------------
    // URL resolution & parsing
    // -----------------------------------------------------------------------

    /**
     * Follow short link redirects to a full Maps URL. Returns the original
     * input unchanged if it isn't a known short-link domain or resolution
     * fails.
     */
    private function resolveToMapsUrl(string $input): string
    {
        $host = strtolower(preg_replace('~^www\.~i', '', (string) parse_url($input, PHP_URL_HOST)));

        if (! in_array($host, ['maps.app.goo.gl', 'goo.gl', 'share.google', 'g.co', 'g.page', 'maps.google.com'], true)) {
            return $input;
        }

        $res = $this->fetcher->tryFetch($input, ['User-Agent' => 'Mozilla/5.0 (compatible; Partna/1.0)']);
        if ($res === null) {
            return $input;
        }

        $finalUrl = $res['finalUrl'] ?? null;
        if (is_string($finalUrl) && str_contains($finalUrl, '/maps/')) {
            return $finalUrl;
        }

        // Fallback: scan body for embedded Maps URL
        $body = $res['body'] ?? null;
        if (is_string($body) && preg_match('~https://www\.google\.[a-z.]+/maps/place/[^"\'\\\\<>\s]+~i', $body, $m)) {
            return html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5);
        }

        return $input;
    }

    /**
     * Parse a Google Maps URL for place ID, name, and coordinates.
     *
     * @return array{place_id: ?string, name: ?string, lat: ?float, lng: ?float}|null
     */
    private function parseMapsUrl(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! preg_match('~(^|\.)google\.[a-z.]+$~', $host)) {
            return null;
        }

        // Place name from /maps/place/NAME/
        $name = null;
        if (preg_match('~/maps/place/([^/@?]+)~i', $url, $m)) {
            $name = trim(rawurldecode(str_replace('+', ' ', $m[1])));
        }

        // Coordinates: prefer !3d...!4d... pin over @lat,lng viewport centre
        $lat = $lng = null;
        if (preg_match('~!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)~', $url, $m)) {
            [$lat, $lng] = [(float) $m[1], (float) $m[2]];
        } elseif (preg_match('~/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)~', $url, $m)) {
            [$lat, $lng] = [(float) $m[1], (float) $m[2]];
        }

        // Place ID from the !1s data segment (long base64-ish string)
        $placeId = null;
        if (preg_match('~!1s([a-zA-Z0-9_\-]{20,})~', $url, $m)) {
            $placeId = $m[1];
        }
        // Or from path: /maps/place/NAME/ChIJ...
        if ($placeId === null && preg_match('~/maps/place/[^/]+/(ChI[A-Za-z0-9_\-]+)~', $url, $m)) {
            $placeId = $m[1];
        }

        // q= param fallback for simple search URLs
        if ($name === null && preg_match('~[?&]q=([^&]+)~', $url, $m)) {
            $candidate = trim(rawurldecode(str_replace('+', ' ', $m[1])));
            if ($candidate !== '' && ! preg_match('~^-?\d+(\.\d+)?,\s*-?\d+(\.\d+)?$~', $candidate)) {
                $name = $candidate;
            }
        }

        if ($placeId === null && $name === null && $lat === null) {
            return null;
        }

        return ['place_id' => $placeId, 'name' => $name, 'lat' => $lat, 'lng' => $lng];
    }

    // -----------------------------------------------------------------------
    // Google Places API calls
    // -----------------------------------------------------------------------

    /**
     * Fetch Place Details from the Places API (New).
     */
    private function fetchPlaceDetails(string $placeId): ?array
    {
        $url = $this->endpoint.'/places/'.rawurlencode($placeId);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-Goog-Api-Key' => $this->apiKey,
                    'X-Goog-FieldMask' => self::FIELD_MASK,
                ])
                ->get($url);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('v5.scrape.google_business.details_fetch_failed', [
                'placeId' => $placeId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('v5.scrape.google_business.details_fetch_error', [
                'placeId' => $placeId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Search for a place by name with optional location bias.
     * Returns the first matching place, or null.
     */
    private function searchPlace(string $name, ?float $lat, ?float $lng): ?array
    {
        $body = [
            'textQuery' => $name,
            'maxResultCount' => 1,
        ];

        if ($lat !== null && $lng !== null) {
            $body['locationBias'] = [
                'circle' => [
                    'center' => ['latitude' => $lat, 'longitude' => $lng],
                    'radius' => 500.0,
                ],
            ];
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-Goog-Api-Key' => $this->apiKey,
                    'X-Goog-FieldMask' => self::FIELD_MASK,
                ])
                ->post($this->endpoint.'/places:searchText', $body);

            if ($response->successful()) {
                $places = $response->json('places');
                if (is_array($places) && count($places) > 0) {
                    return $places[0];
                }
            }

            Log::warning('v5.scrape.google_business.search_failed', [
                'query' => $name,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('v5.scrape.google_business.search_error', [
                'query' => $name,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // -----------------------------------------------------------------------
    // Response mapping
    // -----------------------------------------------------------------------

    /**
     * Build V5 items and profile from a Places API response.
     *
     * Produces:
     *   1 service item with business profile fields
     *   N review items (up to 5)
     */
    private function buildResult(array $place, ?string $placeId): array
    {
        $businessName = data_get($place, 'displayName.text', 'Unknown Business');
        $rating = data_get($place, 'rating');
        $reviewCount = data_get($place, 'userRatingCount');
        $address = data_get($place, 'formattedAddress');
        $phone = data_get($place, 'nationalPhoneNumber');
        $website = data_get($place, 'websiteUri');
        $mapsUri = data_get($place, 'googleMapsUri');
        $summary = data_get($place, 'editorialSummary.text') ?? data_get($place, 'reviewSummary.text');
        $hours = data_get($place, 'regularOpeningHours.weekdayDescriptions');
        $category = data_get($place, 'primaryTypeDisplayName.text');
        $businessStatus = data_get($place, 'businessStatus');
        $photos = (array) data_get($place, 'photos', []);
        $rawReviews = (array) data_get($place, 'reviews', []);

        $id = $placeId ?? data_get($place, 'id', 'unknown');

        // Resolve profile photo from first photo ref
        $profilePicUrl = null;
        if (! empty($photos)) {
            $photoRef = data_get($photos[0], 'name');
            if ($photoRef) {
                $profilePicUrl = $this->resolvePhotoUrl($photoRef);
            }
        }

        $items = [];

        // 1. Main service item with business profile fields
        $values = [
            ['field_name' => 'name', 'value' => $businessName, 'format' => 'text'],
        ];
        if ($address !== null) {
            $values[] = ['field_name' => 'address', 'value' => $address, 'format' => 'text'];
        }
        if ($rating !== null) {
            $values[] = ['field_name' => 'rating', 'value' => (string) $rating, 'format' => 'text'];
        }
        if ($reviewCount !== null) {
            $values[] = ['field_name' => 'follower_count', 'value' => (string) $reviewCount, 'format' => 'text'];
        }
        if ($phone !== null) {
            $values[] = ['field_name' => 'phone', 'value' => $phone, 'format' => 'text'];
        }
        if ($website !== null) {
            $values[] = ['field_name' => 'website', 'value' => $website, 'format' => 'url'];
        }
        if ($mapsUri !== null) {
            $values[] = ['field_name' => 'maps_url', 'value' => $mapsUri, 'format' => 'url'];
        }
        if ($category !== null) {
            $values[] = ['field_name' => 'category', 'value' => $category, 'format' => 'text'];
        }
        if ($summary !== null) {
            $values[] = ['field_name' => 'bio', 'value' => $summary, 'format' => 'text'];
        }
        if ($hours !== null) {
            $values[] = ['field_name' => 'hours', 'value' => implode("\n", $hours), 'format' => 'text'];
        }
        if ($profilePicUrl !== null) {
            $values[] = ['field_name' => 'profile_pic_url', 'value' => $profilePicUrl, 'format' => 'image'];
        }

        $items[] = [
            'identifier' => 'google_business_'.$id,
            'name' => $businessName,
            'item_type' => 'service',
            'values' => $values,
        ];

        // 2. Review items (up to 5)
        foreach (array_slice($rawReviews, 0, 5) as $i => $review) {
            $authorName = data_get($review, 'authorAttribution.displayName', 'Anonymous');
            $reviewText = data_get($review, 'text.text', '');
            $reviewRating = data_get($review, 'rating');
            $reviewTime = data_get($review, 'relativePublishTimeDescription');
            $authorPhoto = data_get($review, 'authorAttribution.photoUri');
            $authorUri = data_get($review, 'authorAttribution.uri');

            $rv = [
                ['field_name' => 'author_name', 'value' => $authorName, 'format' => 'text'],
                ['field_name' => 'review_text', 'value' => $reviewText, 'format' => 'text'],
            ];
            if ($reviewRating !== null) {
                $rv[] = ['field_name' => 'rating', 'value' => (string) $reviewRating, 'format' => 'text'];
            }
            if ($reviewTime !== null) {
                $rv[] = ['field_name' => 'published_ago', 'value' => $reviewTime, 'format' => 'text'];
            }
            if ($authorPhoto !== null) {
                $rv[] = ['field_name' => 'author_photo', 'value' => $authorPhoto, 'format' => 'image'];
            }
            if ($authorUri !== null) {
                $rv[] = ['field_name' => 'author_url', 'value' => $authorUri, 'format' => 'url'];
            }

            $items[] = [
                'identifier' => 'google_business_review_'.$id.'_'.$i,
                'name' => 'Review by '.$authorName,
                'item_type' => 'review',
                'values' => $rv,
            ];
        }

        $this->logSuccess('google_business', 'fetch', count($items));

        return [
            'items' => $items,
            'profile' => [
                'display_name' => $businessName,
                'profile_pic_url' => $profilePicUrl,
                'bio' => $summary,
                'follower_count' => (int) ($reviewCount ?? 0),
            ],
        ];
    }

    /**
     * Resolve a photo reference to a servable image URL via the Places
     * Photos media endpoint. Returns null on failure.
     */
    private function resolvePhotoUrl(string $photoRef): ?string
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['X-Goog-Api-Key' => $this->apiKey])
                ->get('https://places.googleapis.com/v1/'.$photoRef.'/media', [
                    'maxWidthPx' => 800,
                    'skipHttpRedirect' => 'true',
                ]);

            if ($response->successful()) {
                $uri = $response->json('photoUri');

                return is_string($uri) && $uri !== '' ? $uri : null;
            }
        } catch (\Throwable $e) {
            Log::warning('v5.scrape.google_business.photo_resolve_failed', [
                'photoRef' => $photoRef,
                'message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Fallback / no-API-key result
    // -----------------------------------------------------------------------

    /**
     * Build a fallback result when the API key is missing or the fetch fails.
     * Produces a minimal item so the workplace page shows the connection.
     */
    private function buildFallbackResult(string $input): array
    {
        // Extract a readable name from URL path if possible
        $displayName = $input;
        if (filter_var($input, FILTER_VALIDATE_URL)) {
            $path = parse_url($input, PHP_URL_PATH);
            if ($path && preg_match('~/place/([^/@?]+)~i', $path, $m)) {
                $displayName = trim(rawurldecode(str_replace('+', ' ', $m[1])));
            }
        }

        $placeId = 'manual_'.md5($input);

        $items = [
            [
                'identifier' => 'google_business_'.$placeId,
                'name' => $displayName,
                'item_type' => 'service',
                'values' => [
                    ['field_name' => 'name', 'value' => $displayName, 'format' => 'text'],
                    ['field_name' => 'note', 'value' => 'Google Business profile connected. Profile data will appear once the Google Places API key is configured.', 'format' => 'text'],
                ],
            ],
        ];

        return [
            'items' => $items,
            'profile' => [
                'display_name' => $displayName,
                'profile_pic_url' => null,
                'bio' => null,
                'follower_count' => 0,
            ],
        ];
    }
}
