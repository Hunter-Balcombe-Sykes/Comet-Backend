<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Two data paths feed the Google Business card:
//
// 1. URL parse (legacy connect): a Maps share link carries the place name and
//    coordinates in the URL itself, and the classic keyless embed
//    (maps.google.com/maps?...&output=embed) renders a live map for them.
//    Short links are resolved first (maps.app.goo.gl / goo.gl/maps /
//    share.google), full /maps/place/ URLs parse with no network at all.
// 2. Place Details enrichment (picker connect + refresh cron): when a placeId
//    is known, fetchPlaceDetails() pulls the full Places API (New) snapshot —
//    rating, reviews, hours, phone, website, photos — with the server-side
//    key. Best-effort: enrichment failures never block the basic card.
class GoogleBusinessService extends PlatformScraper
{
    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /**
     * @return array{url:string, name:?string, lat:?float, lng:?float}|null
     */
    public function resolve(string $input): ?array
    {
        $input = trim($input);
        $host = strtolower(preg_replace('~^www\.~i', '', (string) parse_url($input, PHP_URL_HOST)));

        $url = $input;
        if (in_array($host, ['maps.app.goo.gl', 'goo.gl', 'share.google', 'g.co', 'maps.google.com'], true)) {
            $url = $this->followShortLink($input) ?? $input;
        }

        $parsed = $this->parsePlaceUrl($url);
        if ($parsed === null && $url !== $input) {
            $parsed = $this->parsePlaceUrl($input);
        }

        return $parsed;
    }

    /** Resolve a short link to the full Maps URL (redirects, then body scan). */
    private function followShortLink(string $shortUrl): ?string
    {
        $res = $this->fetcher->tryFetch($shortUrl, ['User-Agent' => self::USER_AGENT]);
        if (! is_string($res['finalUrl'] ?? null)) {
            return null;
        }

        // Normal case: the redirect chain landed on the real Maps URL.
        if (str_contains($res['finalUrl'], '/maps/')) {
            return $res['finalUrl'];
        }

        // Interstitial case: the canonical place URL is in the page body.
        if (is_string($res['body'] ?? null)
            && preg_match('~https://www\.google\.[a-z.]+/maps/place/[^"\'\\\\<>\s]+~i', $res['body'], $m)) {
            return html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5);
        }

        return null;
    }

    /**
     * @return array{url:string, name:?string, lat:?float, lng:?float}|null
     */
    private function parsePlaceUrl(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! preg_match('~(^|\.)google\.[a-z.]+$~', $host)) {
            return null;
        }

        $name = null;
        if (preg_match('~/maps/place/([^/@?]+)~i', $url, $m)) {
            $name = trim(rawurldecode(str_replace('+', ' ', $m[1])));
        }

        // The !3d…!4d… data segment is the exact place pin; the @lat,lng pair
        // is only the viewport centre — prefer the pin.
        $lat = $lng = null;
        if (preg_match('~!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)~', $url, $m)) {
            [$lat, $lng] = [(float) $m[1], (float) $m[2]];
        } elseif (preg_match('~/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)~', $url, $m)) {
            [$lat, $lng] = [(float) $m[1], (float) $m[2]];
        }

        // A q= search link (maps.google.com/?q=…) still names the place.
        if ($name === null && preg_match('~[?&]q=([^&]+)~', $url, $m)) {
            $candidate = trim(rawurldecode(str_replace('+', ' ', $m[1])));
            // Skip bare coordinate queries — they name nothing.
            if ($candidate !== '' && ! preg_match('~^-?\d+(\.\d+)?,\s*-?\d+(\.\d+)?$~', $candidate)) {
                $name = $candidate;
            }
        }

        if ($name === null && $lat === null) {
            return null;
        }

        return ['url' => $url, 'name' => $name, 'lat' => $lat, 'lng' => $lng];
    }

    // Everything the card stores — one Enterprise+Atmosphere-tier request per
    // place per refresh. Field additions here must also be mapped in
    // mapDetails() and allowlisted in PublicIntegrationConnectionResource if
    // they should reach the sitepage.
    private const DETAILS_FIELD_MASK = 'id,displayName,formattedAddress,location,businessStatus,primaryTypeDisplayName,googleMapsUri,googleMapsLinks,utcOffsetMinutes,rating,userRatingCount,nationalPhoneNumber,internationalPhoneNumber,websiteUri,regularOpeningHours,priceLevel,priceRange,photos,reviews,reviewSummary,editorialSummary,accessibilityOptions,parkingOptions,paymentOptions,outdoorSeating,reservable,delivery,takeout,dineIn,curbsidePickup,goodForChildren,goodForGroups,allowsDogs,restroom,liveMusic,servesCoffee,servesBreakfast,servesBrunch,servesLunch,servesDinner,servesDessert,servesVegetarianFood,servesBeer,servesWine,servesCocktails';

    /**
     * Fetch Place Details (New) for a place ID and map the response onto
     * payload keys (rating, reviewCount, reviews, hours, phone, website,
     * photos, amenities, …). Null when the server key is unset or the fetch
     * fails — callers keep their existing payload untouched.
     *
     * @return array<string,mixed>|null
     */
    public function fetchPlaceDetails(string $placeId): ?array
    {
        $key = config('services.google_maps.server_api_key');
        if (! is_string($key) || $key === '') {
            return null;
        }

        try {
            $res = Http::timeout(5)
                ->retry(2, 200, throw: false)
                ->withHeaders([
                    'X-Goog-Api-Key' => $key,
                    'X-Goog-FieldMask' => self::DETAILS_FIELD_MASK,
                ])
                ->get('https://places.googleapis.com/v1/places/'.rawurlencode($placeId));
        } catch (\Throwable $e) {
            Log::warning('google_business.details_fetch_failed', [
                'placeId' => $placeId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $res->ok()) {
            Log::warning('google_business.details_fetch_failed', [
                'placeId' => $placeId,
                'status' => $res->status(),
            ]);

            return null;
        }

        return $this->mapDetails((array) $res->json());
    }

    /**
     * Map a Place Details response to stored payload keys. Keys whose source
     * field is absent are DROPPED (not stored as null) so merging over an
     * existing payload never clobbers a stored value with an absent one.
     * `detailsFetchedAt` always survives — it drives the refresh cadence.
     *
     * @param  array<string,mixed>  $place
     * @return array<string,mixed>
     */
    private function mapDetails(array $place): array
    {
        $notNull = fn ($v) => $v !== null;

        $links = array_filter([
            'writeReview' => data_get($place, 'googleMapsLinks.writeAReviewUri'),
            'reviews' => data_get($place, 'googleMapsLinks.reviewsUri'),
            'photos' => data_get($place, 'googleMapsLinks.photosUri'),
            'directions' => data_get($place, 'googleMapsLinks.directionsUri'),
        ], $notNull);

        $reviews = array_map(fn (array $r) => [
            'author' => data_get($r, 'authorAttribution.displayName'),
            'authorUri' => data_get($r, 'authorAttribution.uri'),
            'authorPhoto' => data_get($r, 'authorAttribution.photoUri'),
            'rating' => data_get($r, 'rating'),
            'text' => data_get($r, 'text.text'),
            'publishedAgo' => data_get($r, 'relativePublishTimeDescription'),
            'publishTime' => data_get($r, 'publishTime'),
        ], array_slice(array_values(array_filter((array) data_get($place, 'reviews', []), 'is_array')), 0, 5));

        // Photo refs only (free in the details response). Resolving them to
        // image URLs is a separate billed media call — deferred until a
        // design pass actually renders photos.
        $photos = array_map(fn (array $p) => [
            'ref' => data_get($p, 'name'),
            'widthPx' => data_get($p, 'widthPx'),
            'heightPx' => data_get($p, 'heightPx'),
            'authors' => array_values(array_filter(array_map(
                fn ($a) => data_get($a, 'displayName'),
                (array) data_get($p, 'authorAttributions', []),
            ))),
        ], array_slice(array_values(array_filter((array) data_get($place, 'photos', []), 'is_array')), 0, 10));

        $serves = array_filter([
            'coffee' => data_get($place, 'servesCoffee'),
            'breakfast' => data_get($place, 'servesBreakfast'),
            'brunch' => data_get($place, 'servesBrunch'),
            'lunch' => data_get($place, 'servesLunch'),
            'dinner' => data_get($place, 'servesDinner'),
            'dessert' => data_get($place, 'servesDessert'),
            'vegetarian' => data_get($place, 'servesVegetarianFood'),
            'beer' => data_get($place, 'servesBeer'),
            'wine' => data_get($place, 'servesWine'),
            'cocktails' => data_get($place, 'servesCocktails'),
        ], $notNull);

        // `false` is informative for amenity booleans — only absent (null)
        // fields are dropped.
        $amenities = array_filter([
            'accessibility' => data_get($place, 'accessibilityOptions'),
            'parking' => data_get($place, 'parkingOptions'),
            'payments' => data_get($place, 'paymentOptions'),
            'outdoorSeating' => data_get($place, 'outdoorSeating'),
            'reservable' => data_get($place, 'reservable'),
            'delivery' => data_get($place, 'delivery'),
            'takeout' => data_get($place, 'takeout'),
            'dineIn' => data_get($place, 'dineIn'),
            'curbsidePickup' => data_get($place, 'curbsidePickup'),
            'goodForChildren' => data_get($place, 'goodForChildren'),
            'goodForGroups' => data_get($place, 'goodForGroups'),
            'allowsDogs' => data_get($place, 'allowsDogs'),
            'restroom' => data_get($place, 'restroom'),
            'liveMusic' => data_get($place, 'liveMusic'),
            'serves' => $serves !== [] ? $serves : null,
        ], $notNull);

        $hours = data_get($place, 'regularOpeningHours');
        $reviewSummary = data_get($place, 'reviewSummary.text.text') ?? data_get($place, 'reviewSummary.text');

        $mapped = array_filter([
            'name' => data_get($place, 'displayName.text'),
            'address' => data_get($place, 'formattedAddress'),
            'lat' => data_get($place, 'location.latitude'),
            'lng' => data_get($place, 'location.longitude'),
            // The canonical Maps URI beats the search deep link stored at connect.
            'url' => data_get($place, 'googleMapsUri'),
            'businessStatus' => data_get($place, 'businessStatus'),
            'category' => data_get($place, 'primaryTypeDisplayName.text'),
            'phone' => data_get($place, 'nationalPhoneNumber'),
            'phoneIntl' => data_get($place, 'internationalPhoneNumber'),
            'website' => data_get($place, 'websiteUri'),
            'rating' => data_get($place, 'rating'),
            'reviewCount' => data_get($place, 'userRatingCount'),
            'hours' => is_array($hours) ? [
                'weekdays' => data_get($hours, 'weekdayDescriptions'),
                'periods' => data_get($hours, 'periods'),
                'utcOffsetMinutes' => data_get($place, 'utcOffsetMinutes'),
            ] : null,
            'links' => $links !== [] ? $links : null,
            'priceLevel' => data_get($place, 'priceLevel'),
            'priceRange' => data_get($place, 'priceRange'),
            'editorialSummary' => data_get($place, 'editorialSummary.text'),
            'reviewSummary' => is_string($reviewSummary) ? $reviewSummary : null,
            'reviews' => $reviews !== [] ? $reviews : null,
            'photos' => $photos !== [] ? $photos : null,
            'amenities' => $amenities !== [] ? $amenities : null,
        ], $notNull);

        $mapped['detailsFetchedAt'] = now()->toIso8601String();

        return $mapped;
    }
}
