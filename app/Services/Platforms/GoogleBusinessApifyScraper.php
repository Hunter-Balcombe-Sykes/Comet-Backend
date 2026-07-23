<?php

namespace App\Services\Platforms;

use App\Services\Cache\ApifyBudget;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

// Google Business enrichment BEYOND Place Details: the action links Google's
// Places API (New) doesn't expose — menu, table reservation, order-online,
// appointment booking — plus the social profiles Google has on file. Sourced
// from Apify's compass/crawler-google-places actor via
// run-sync-get-dataset-items (201 on success). Pure fetch + map; the connect
// job (GoogleBusinessEnrichJob) persists the result.
//
// We deliberately request ZERO reviews and ZERO images (maxReviews/maxImages 0)
// — Place Details already gives us those, so paying Apify to re-fetch them would
// be waste. Only the net-new fields are asked for.
//
// Field-name caveat: a couple of the actor's input flags / output keys carry
// slight doc ambiguity (e.g. the order-online provider array). Mapping is
// therefore defensive (multiple candidate keys, scheme-checked) and the present
// keys are logged on each run so the shape can be tuned against real data.
class GoogleBusinessApifyScraper extends PlatformScraper
{
    /**
     * Run the actor for one place ID and map its first dataset item onto the
     * enrichment payload keys (menu, reservation, order, booking, socials).
     * Null on missing token / failure / empty result — the caller keeps the
     * existing payload untouched.
     *
     * @return array<string,mixed>|null
     */
    public function fetch(string $placeId, ?string $userId = null): ?array
    {
        $token = config('services.apify.token');
        if (! $token) {
            return null;
        }

        // SCALE-2: claim a slot from the shared Apify budget before spending. Null
        // here = same skip contract as a failed scrape (caller keeps prior payload).
        if (! app(ApifyBudget::class)->tryClaim('google-business')) {
            Log::warning('google_business.apify.budget_exhausted', ['place_id' => $placeId, 'user_id' => $userId]);

            return null;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(110)
                ->post(
                    'https://api.apify.com/v2/acts/'.config('services.apify.actors.google_places').'/run-sync-get-dataset-items',
                    $this->input($placeId),
                );
        } catch (Throwable $e) {
            report($e);
            Log::warning('google_business.apify.threw', ['place_id' => $placeId, 'user_id' => $userId, 'error' => $e->getMessage()]);

            return null;
        }

        // run-sync-get-dataset-items returns 201 on success — ->ok() only accepts 200.
        if (! $response->successful()) {
            // 5xx is genuine Apify infra worth alerting on; 4xx (e.g. unknown
            // place / actor not rented) is expected and log-only.
            if ($response->status() >= 500) {
                report(new \RuntimeException('Apify google-business scrape failed with status '.$response->status()));
            }
            Log::warning('google_business.apify.not_ok', ['place_id' => $placeId, 'user_id' => $userId, 'status' => $response->status()]);

            return null;
        }

        $items = $response->json();
        if (! is_array($items) || empty($items) || ! is_array($items[0])) {
            Log::warning('google_business.apify.bad_items', ['place_id' => $placeId, 'user_id' => $userId, 'type' => gettype($items)]);

            return null;
        }

        $place = $items[0];

        // First-run visibility: which of the fields we care about actually came
        // back, so the mapping can be tuned against real listings without
        // dumping the whole (large) item. Drop to debug once settled.
        Log::info('google_business.apify.keys', [
            'place_id' => $placeId,
            'user_id' => $userId,
            'present' => array_values(array_filter([
                isset($place['menu']) ? 'menu' : null,
                isset($place['reserveTableUrl']) ? 'reserveTableUrl' : null,
                isset($place['tableReservationLinks']) ? 'tableReservationLinks' : null,
                data_get($place, 'restaurantData.tableReservationProvider') ? 'tableReservationProvider' : null,
                isset($place['googleFoodUrl']) ? 'googleFoodUrl' : null,
                isset($place['orderBy']) ? 'orderBy' : null,
                isset($place['bookingLinks']) ? 'bookingLinks' : null,
                isset($place['instagrams']) ? 'instagrams' : null,
                isset($place['facebooks']) ? 'facebooks' : null,
            ])),
        ]);

        return $this->map($place);
    }

    /**
     * Actor input — flags confirmed against the actor's input schema (verified
     * 201 against a live place). maxReviews/maxImages 0 keeps the run to the
     * net-new fields (Place Details already covers reviews + photos);
     * maximumLeadsEnrichmentRecords 0 skips the B2B-leads add-on.
     *
     * @return array<string,mixed>
     */
    private function input(string $placeId): array
    {
        return [
            'placeIds' => [$placeId],
            'language' => 'en',
            'maxCrawledPlacesPerSearch' => 1,
            'maxReviews' => 0,
            'maxImages' => 0,
            'scrapeReviewsPersonalData' => false,
            'maximumLeadsEnrichmentRecords' => 0,
            // The place detail page carries the menu / reservation / order links.
            'scrapePlaceDetailPage' => true,
            'scrapeTableReservationProvider' => true,
            'scrapeOrderOnline' => true,
            // Company-contacts add-on crawls the business website for the social
            // profile URLs (instagrams / facebooks / linkedIns / …). NOT
            // scrapeSocialMediaProfiles — that's an OBJECT-shaped per-profile
            // follower-count enrichment we don't need (and it 400s as a bool).
            'scrapeContacts' => true,
        ];
    }

    /**
     * Map a dataset item to the stored enrichment keys. Absent groups are
     * DROPPED (array_filter) so merging over an existing payload never clobbers
     * a stored value with null — same contract as Place Details mapDetails().
     * Every URL is scheme-checked (http/https only) before it is stored, since
     * these all become outbound links the dashboard opens.
     *
     * @param  array<string,mixed>  $place
     * @return array<string,mixed>
     */
    private function map(array $place): array
    {
        $notNull = fn ($v) => $v !== null;

        // Reservation: prefer the DIRECT provider link (OpenTable / Resy / …)
        // over the long google.com/maps/reserve URL. Keep the Google one as
        // googleUrl so a place with only that still books.
        $resLinks = $this->namedLinks(data_get($place, 'tableReservationLinks'));
        $googleReserve = $this->safeUrl(data_get($place, 'reserveTableUrl'))
            ?? $this->safeUrl(data_get($place, 'restaurantData.tableReservationProvider.reserveTableUrl'));
        $reservation = array_filter([
            'url' => ($resLinks[0]['url'] ?? null) ?? $googleReserve,
            'provider' => $this->cleanString(data_get($place, 'restaurantData.tableReservationProvider.name'))
                ?? ($resLinks[0]['name'] ?? null),
            'googleUrl' => $googleReserve,
            'links' => $resLinks !== [] ? $resLinks : null,
        ], $notNull);

        $order = array_filter([
            'googleFood' => $this->safeUrl(data_get($place, 'googleFoodUrl')),
            'providers' => $this->orderProviders(data_get($place, 'orderOnline')),
        ], $notNull);

        $socials = array_filter([
            'instagram' => $this->firstUrl(data_get($place, 'instagrams')),
            'facebook' => $this->firstUrl(data_get($place, 'facebooks')),
            'linkedin' => $this->firstUrl(data_get($place, 'linkedIns')),
            'youtube' => $this->firstUrl(data_get($place, 'youtubes')),
            'tiktok' => $this->firstUrl(data_get($place, 'tiktoks')),
            'twitter' => $this->firstUrl(data_get($place, 'twitters')),
            'pinterest' => $this->firstUrl(data_get($place, 'pinterests')),
        ], $notNull);

        return array_filter([
            'menu' => $this->safeUrl(data_get($place, 'menu')),
            'reservation' => $reservation !== [] ? $reservation : null,
            'order' => $order !== [] ? $order : null,
            'booking' => $this->bookingLinks(data_get($place, 'bookingLinks'), $this->safeUrl(data_get($place, 'website'))),
            'socials' => $socials !== [] ? $socials : null,
        ], $notNull);
    }

    /** A trimmed http/https URL, or null — drops javascript:/data:/mailto: etc. */
    private function safeUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $url = trim($value);

        return preg_match('~^https?://~i', $url) === 1 ? $url : null;
    }

    /** A non-empty trimmed string, or null. */
    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $s = trim($value);

        return $s !== '' ? $s : null;
    }

    /**
     * First safe URL from a value that may be a bare string, a list of URL
     * strings, or a list of { url | link } objects (the social arrays are
     * lists of plain URLs; defensively handle object form too).
     */
    private function firstUrl(mixed $value): ?string
    {
        if (is_string($value)) {
            return $this->safeUrl($value);
        }
        if (! is_array($value)) {
            return null;
        }
        foreach ($value as $item) {
            $url = is_array($item)
                ? ($this->safeUrl(data_get($item, 'url')) ?? $this->safeUrl(data_get($item, 'link')))
                : $this->safeUrl($item);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Unique list of safe URLs from a list of strings or { url | link }
     * objects (tableReservationLinks / bookingLinks). Null when none survive.
     *
     * @return list<string>|null
     */
    private function urlList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $urls = [];
        foreach ($value as $item) {
            $url = is_array($item)
                ? ($this->safeUrl(data_get($item, 'url')) ?? $this->safeUrl(data_get($item, 'link')))
                : $this->safeUrl($item);
            if ($url !== null) {
                $urls[] = $url;
            }
        }
        $urls = array_values(array_unique($urls));

        return $urls !== [] ? $urls : null;
    }

    /**
     * Booking action links (Google's "Book online" / appointment action),
     * EXCLUDING only the literal "Website" button echo. The actor echoes the
     * "Website" button into bookingLinks when Google exposes no separate provider
     * link — seeding the bare site as a booking card is wrong. We drop ONLY the
     * exact website URL (scheme / www. / trailing-slash-insensitive), so a genuine
     * appointment link hosted on the business's OWN domain (e.g. example.com/book,
     * book.example.com) is KEPT and auto-syncs as the booking link. (This replaced
     * a whole-host filter that wrongly discarded same-domain appointment links.)
     *
     * @return list<string>|null
     */
    private function bookingLinks(mixed $value, ?string $websiteUrl): ?array
    {
        $links = $this->urlList($value);
        if ($links === null) {
            return null;
        }
        $site = $this->normalizeUrl($websiteUrl);
        if ($site !== null) {
            $links = array_values(array_filter($links, fn ($u) => $this->normalizeUrl($u) !== $site));
        }

        return $links !== [] ? $links : null;
    }

    /** Lower-cased host without a leading www., or null. */
    private function host(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return strtolower(preg_replace('/^www\./i', '', $host));
    }

    /**
     * host + path, lower-cased, www. + trailing slash stripped, scheme / query /
     * fragment ignored — the comparison key for "is this link the business's own
     * website button?". Root-path links collapse to just the host, so the bare
     * website echo matches the site while a deeper appointment path does not.
     */
    private function normalizeUrl(?string $url): ?string
    {
        $host = $this->host($url);
        if ($host === null) {
            return null;
        }
        $path = rtrim((string) parse_url((string) $url, PHP_URL_PATH), '/');

        return $host.$path;
    }

    /**
     * Named links (tableReservationLinks: [{ name, url }]) → list of { name,
     * url } with safe URLs only. Empty list when none survive.
     *
     * @return list<array{name?:string,url:string}>
     */
    private function namedLinks(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }
            $url = $this->safeUrl(data_get($item, 'url')) ?? $this->safeUrl(data_get($item, 'orderUrl'));
            if ($url === null) {
                continue;
            }
            $out[] = array_filter([
                'name' => $this->cleanString(data_get($item, 'name')),
                'url' => $url,
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    /**
     * Flatten orderOnline.{pickUps,deliveries} into one provider list — EVERY
     * platform (UberEats, DoorDash, Menulog, …) for both pickup and delivery,
     * each with name / url / type / time / fees. The link is in `orderUrl`
     * (`url` is usually null). Null when none survive.
     *
     * @return list<array{name?:string,url:string,type:string,time?:string,fees?:string}>|null
     */
    private function orderProviders(mixed $orderOnline): ?array
    {
        if (! is_array($orderOnline)) {
            return null;
        }
        $out = [];
        foreach (['pickUps' => 'pickup', 'deliveries' => 'delivery'] as $key => $type) {
            foreach ((array) data_get($orderOnline, $key, []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $url = $this->safeUrl(data_get($item, 'orderUrl')) ?? $this->safeUrl(data_get($item, 'url'));
                if ($url === null) {
                    continue;
                }
                $out[] = array_filter([
                    'name' => $this->cleanString(data_get($item, 'name')),
                    'url' => $url,
                    'type' => $type,
                    'time' => $this->cleanString(data_get($item, 'pickUpTime') ?? data_get($item, 'deliveryTime') ?? data_get($item, 'time')),
                    'fees' => $this->cleanString(data_get($item, 'pickUpFees') ?? data_get($item, 'deliveryFees') ?? data_get($item, 'fees')),
                ], fn ($v) => $v !== null);
            }
        }

        return $out !== [] ? $out : null;
    }
}
