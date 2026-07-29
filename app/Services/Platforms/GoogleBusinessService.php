<?php

namespace App\Services\Platforms;

use App\Exceptions\Platforms\PlaceDetailsUnavailableException;
use App\Exceptions\Platforms\PlacesBudgetExhaustedException;
use App\Services\Cache\PlacesBudget;
use App\Services\Cache\PlacesClaim;
use App\Services\Http\SafeUrlFetcher;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
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
    /**
     * Real Google ccTLD suffixes, enumerated — mirrors EventbriteScraper::TLDS /
     * OpenTableService::TLDS. The previous host gate accepted the OPEN family
     * `com(\.[a-z]{2})?|co\.[a-z]{2}|[a-z]{2}`, which admits ~2,029 registrable
     * suffixes (`google.<any 2-letter cc>`, `google.co.<any cc>`,
     * `google.com.<any cc>`) — `google.tk`, `google.cm`, `google.com.zz` all
     * passed. Deliberately conservative: an omitted real locale is a visible,
     * one-line-fix rejection; a wrongly-admitted one is the spoof hole this
     * closes. Extend with a real Google country domain if one is rejected.
     *
     * Entries must be VERIFIED, not guessed by pattern. Review caught a bare
     * `pe` here on the first pass: `google.pe` does not exist (Peru is
     * `google.com.pe`), so the gate simultaneously admitted a fabricated host
     * and rejected the real one. Google's ccTLDs do not follow a derivable
     * rule — `google.com.au` but `google.de`, `google.co.uk` but `google.fr` —
     * so each entry has to be checked individually.
     */
    private const TLDS = '(?:com|com\.au|com\.br|com\.mx|com\.pe|com\.ar|com\.co|com\.tr|com\.sg|com\.hk|com\.tw|com\.ph|com\.vn|com\.pk|com\.eg|com\.sa|com\.ua|com\.ng|co\.uk|co\.jp|co\.in|co\.nz|co\.za|co\.kr|co\.id|co\.il|de|fr|it|es|nl|ca|ie|pl|pt|se|no|dk|fi|ch|at|be|cz|gr|hu|ro|ru|cl)';

    public function __construct(private readonly SafeUrlFetcher $fetcher, private readonly PlacesBudget $budget) {}

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
            && preg_match('~https://www\.google\.(?:com(?:\.[a-z]{2})?|co\.[a-z]{2}|[a-z]{2})/maps/place/[^"\'\\\\<>\s]+~i', $res['body'], $m)) {
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
        // Closed Google ccTLD enumeration only (self::TLDS) — the prior open
        // `com(\.[a-z]{2})?|co\.[a-z]{2}|[a-z]{2}` family accepted
        // `google.<attacker-domain>` as a Maps host.
        if (! preg_match('~(^|\.)google\.'.self::TLDS.'$~', $host)) {
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
    private const DETAILS_FIELD_MASK = 'id,displayName,formattedAddress,location,businessStatus,primaryTypeDisplayName,googleMapsUri,googleMapsLinks,utcOffsetMinutes,rating,userRatingCount,nationalPhoneNumber,internationalPhoneNumber,websiteUri,regularOpeningHours,currentOpeningHours,postalAddress,priceLevel,priceRange,photos,reviews,reviewSummary,editorialSummary,accessibilityOptions,parkingOptions,paymentOptions,outdoorSeating,reservable,delivery,takeout,dineIn,curbsidePickup,goodForChildren,goodForGroups,allowsDogs,restroom,liveMusic,servesCoffee,servesBreakfast,servesBrunch,servesLunch,servesDinner,servesDessert,servesVegetarianFood,servesBeer,servesWine,servesCocktails';

    // Preserves the previous ->retry(2, 200) semantics: at most 2 attempts, the
    // second only after a transport-level failure on the first.
    private const DETAILS_MAX_ATTEMPTS = 2;

    /**
     * Fetch Place Details (New) for a place ID and map the response onto
     * payload keys (rating, reviewCount, reviews, hours, phone, website,
     * photos, amenities, …). Null when the server key is unset or the fetch
     * fails — callers keep their existing payload untouched.
     *
     * RV-6: every billed request (this call, and each photo media resolve
     * inside resolvePhotoUrls) claims a PlacesBudget slot immediately before
     * it fires — never once per call to this method, or the accounting would
     * undercount by up to 16× (1 details + up to 15 photos). A denied claim
     * throws PlacesBudgetExhaustedException so the caller can decide whether
     * that's a 429 (their own doing) or a quiet degrade (platform-wide).
     *
     * @return array<string,mixed>|null
     *
     * @throws PlacesBudgetExhaustedException
     */
    public function fetchPlaceDetails(string $placeId, string $userId, array $priorPhotos = []): ?array
    {
        $key = config('services.google_maps.server_api_key');
        if (! is_string($key) || $key === '') {
            return null;
        }

        $res = null;
        for ($attempt = 1; $attempt <= self::DETAILS_MAX_ATTEMPTS; $attempt++) {
            $claim = $this->budget->claim('details', $userId);
            if ($claim !== PlacesClaim::Granted) {
                throw new PlacesBudgetExhaustedException('details', $claim, $placeId);
            }

            try {
                $res = Http::timeout(5)
                    ->withHeaders([
                        'X-Goog-Api-Key' => $key,
                        'X-Goog-FieldMask' => self::DETAILS_FIELD_MASK,
                    ])
                    ->get('https://places.googleapis.com/v1/places/'.rawurlencode($placeId));
                break;
            } catch (\Throwable $e) {
                if ($attempt < self::DETAILS_MAX_ATTEMPTS) {
                    usleep(200_000);

                    continue;
                }

                report($e); // OBS-1: billed-call network failure must reach Nightwatch, not just the log.
                Log::warning('google_business.details_fetch_failed', [
                    'placeId' => $placeId,
                    'message' => $e->getMessage(),
                ]);

                return null;
            }
        }

        if (! $res->ok()) {
            report(new PlaceDetailsUnavailableException($placeId, $res->status())); // OBS-1: an outage (429/5xx) pages on-call.
            Log::warning('google_business.details_fetch_failed', [
                'placeId' => $placeId,
                'status' => $res->status(),
            ]);

            return null;
        }

        $mapped = $this->mapDetails((array) $res->json());

        // Photo refs → servable image URLs (one billed media call per photo,
        // pooled). Street View availability is a free metadata probe.
        if (isset($mapped['photos']) && is_array($mapped['photos'])) {
            // SCALE-3: pre-populate servable urls from the prior payload for unchanged
            // refs so resolvePhotoUrls skips them (no billed re-call). Best-effort —
            // a rotated ref just resolves fresh below. Connect callers pass no prior
            // photos, so this is a no-op there.
            $mapped['photos'] = $this->carryForwardPhotoUrls($mapped['photos'], $priorPhotos);
            $mapped['photos'] = $this->resolvePhotoUrls($key, $placeId, $mapped['photos'], $userId);
        }
        if (isset($mapped['lat'], $mapped['lng'])
            && ($pano = $this->streetViewPano($key, (float) $mapped['lat'], (float) $mapped['lng'])) !== null) {
            $mapped['streetView'] = $pano;
        }

        return $mapped;
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
            // 15 = the content-selection slot count (ContentSelection::MAX_POSITION)
            // so a GB connect can fill every gallery slot; each kept photo costs one
            // billed media-URL resolve on connect (carry-forward covers refreshes).
        ], array_slice(array_values(array_filter((array) data_get($place, 'photos', []), 'is_array')), 0, 15));

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
        // Holiday-aware hours for the next 7 days — public-holiday exceptions
        // are baked into the weekday descriptions.
        $currentHours = data_get($place, 'currentOpeningHours');
        $postal = data_get($place, 'postalAddress');
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
            'currentHours' => is_array($currentHours)
                ? (array_filter(['weekdays' => data_get($currentHours, 'weekdayDescriptions')], $notNull) ?: null)
                : null,
            'addressParts' => is_array($postal)
                ? (array_filter([
                    'lines' => data_get($postal, 'addressLines'),
                    'suburb' => data_get($postal, 'locality'),
                    'state' => data_get($postal, 'administrativeArea'),
                    'postcode' => data_get($postal, 'postalCode'),
                    'country' => data_get($postal, 'regionCode'),
                ], $notNull) ?: null)
                : null,
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

    /**
     * Copy a resolved servable url from the prior payload onto any fresh photo whose
     * ref is unchanged, so the billed media re-resolve is skipped (SCALE-3). Fail-safe:
     * an unmatched/rotated ref is left without a url and resolved fresh downstream.
     *
     * @param  array<int, array<string,mixed>>  $photos  fresh photos (ref only, no url)
     * @param  array<int, array<string,mixed>>  $priorPhotos  previously stored photos (ref + url)
     * @return array<int, array<string,mixed>>
     */
    private function carryForwardPhotoUrls(array $photos, array $priorPhotos): array
    {
        $priorByRef = [];
        foreach ($priorPhotos as $p) {
            if (! empty($p['ref']) && ! empty($p['url'])) {
                $priorByRef[$p['ref']] = $p['url'];
            }
        }
        if ($priorByRef === []) {
            return $photos;
        }

        foreach ($photos as $i => $photo) {
            $ref = $photo['ref'] ?? null;
            if ($ref !== null && empty($photo['url']) && isset($priorByRef[$ref])) {
                $photos[$i]['url'] = $priorByRef[$ref];
            }
        }

        return $photos;
    }

    /**
     * Resolve stored photo refs to servable image URLs via the Place Photos
     * media endpoint — one billed call per photo, pooled for latency. A
     * failed resolve keeps the ref without a url; the next weekly refresh
     * retries.
     *
     * Photos that already carry a non-empty url (e.g. carried over from the
     * prior payload by Task 6) are skipped — never re-billed (SCALE-3).
     *
     * RV-6: one PlacesBudget 'photos' claim per photo admitted into
     * $toResolve, BEFORE the pool fires — so claims and billed requests are
     * identical in count by construction. A denied claim stops admitting
     * further photos (partial result, never fatal — a photo without a
     * resolved url is already a supported state, identical in shape to a
     * failed resolve) rather than throwing; only the details call site (§ RV-6)
     * throws, since a caller's connect can still succeed on a degraded gallery.
     *
     * @param  array<int, array<string,mixed>>  $photos
     * @return array<int, array<string,mixed>>
     */
    private function resolvePhotoUrls(string $key, string $placeId, array $photos, string $userId): array
    {
        $photos = array_values($photos);

        // SCALE-3: only resolve photos MISSING a servable url. A photo whose url was
        // carried over from the prior payload (GoogleBusinessFetch) is not re-billed.
        $toResolve = [];
        foreach ($photos as $index => $photo) {
            if (! empty($photo['url']) || empty($photo['ref'])) {
                continue; // carry-forward (already has a url) or no ref to resolve — no request, no claim
            }

            $claim = $this->budget->claim('photos', $userId);
            if ($claim !== PlacesClaim::Granted) {
                // Only page Nightwatch for a platform-level condition — a routine
                // per-user cap hit is expected/self-inflicted, not an operator event.
                if ($claim !== PlacesClaim::UserCapReached) {
                    report(new PlacesBudgetExhaustedException('photos', $claim, $placeId));
                }
                break; // stop admitting — further claims would also be denied
            }

            $toResolve[$index] = $photo;
        }
        if ($toResolve === []) {
            return $photos;
        }

        // Cap the concurrent burst of BILLED media calls (SCALE-3): chunk the pool.
        $max = max(1, (int) config('partna.refresh.host_limits.google_places.pool_concurrency', 5));

        try {
            $responses = [];
            foreach (array_chunk($toResolve, $max, true) as $chunk) {
                $batch = Http::pool(fn (Pool $pool) => array_map(
                    fn (int $i, array $photo) => $pool->as((string) $i)
                        ->timeout(5)
                        ->withHeaders(['X-Goog-Api-Key' => $key])
                        ->get('https://places.googleapis.com/v1/'.($photo['ref'] ?? '').'/media', [
                            'maxWidthPx' => 1200,
                            'skipHttpRedirect' => 'true',
                        ]),
                    array_keys($chunk),
                    array_values($chunk),
                ));
                $responses += $batch;
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('google_business.photo_resolve_failed', [
                'place_id' => $placeId,
                'message' => $e->getMessage(),
            ]);

            return $photos;
        }

        foreach ($toResolve as $index => $photo) {
            $res = $responses[$index] ?? null;
            $uri = $res instanceof Response && $res->ok() ? $res->json('photoUri') : null;
            if (is_string($uri) && $uri !== '') {
                $photos[$index]['url'] = $uri;
            }
        }

        return $photos;
    }

    /**
     * Street View availability probe — the metadata endpoint is free (only
     * image renders are billed). Null when no outdoor pano covers the pin or
     * the Street View Static API isn't enabled on the key.
     *
     * RV-6: NOT gated by PlacesBudget — verified free, and must stay that way.
     * The URL below MUST end in `/streetview/metadata`, never `/streetview` —
     * dropping that suffix turns this into a billed image render with zero
     * spend accounting (guarded by an architecture test, § RV-6).
     *
     * @return array{panoId: string, lat: float, lng: float}|null
     */
    private function streetViewPano(string $key, float $lat, float $lng): ?array
    {
        try {
            $res = Http::timeout(5)->get('https://maps.googleapis.com/maps/api/streetview/metadata', [
                'location' => $lat.','.$lng,
                'radius' => 100,
                'source' => 'outdoor',
                'key' => $key,
            ]);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('google_business.streetview_probe_failed', [
                'lat' => $lat,
                'lng' => $lng,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $panoId = $res->ok() && $res->json('status') === 'OK' ? $res->json('pano_id') : null;
        if (! is_string($panoId) || $panoId === '') {
            return null;
        }

        return [
            'panoId' => $panoId,
            'lat' => (float) ($res->json('location.lat') ?? $lat),
            'lng' => (float) ($res->json('location.lng') ?? $lng),
        ];
    }
}
