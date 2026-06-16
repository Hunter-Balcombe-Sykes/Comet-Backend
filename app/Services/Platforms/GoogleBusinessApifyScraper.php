<?php

namespace App\Services\Platforms;

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
    // owner~name form for the Apify API path.
    private const ACTOR = 'compass~crawler-google-places';

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

        try {
            $response = Http::withToken($token)
                ->timeout(110)
                ->post(
                    'https://api.apify.com/v2/acts/'.self::ACTOR.'/run-sync-get-dataset-items',
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
     * Actor input — only the flags confirmed against the actor's input schema.
     * maxReviews/maxImages 0 keeps the run to the net-new fields (Place Details
     * already covers reviews + photos). Social + order + reservation enrichment
     * is opt-in.
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
            'scrapeTableReservationProvider' => true,
            'scrapeOrderOnline' => true,
            'scrapeSocialMediaProfiles' => true,
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

        $reservation = array_filter([
            'url' => $this->safeUrl(data_get($place, 'reserveTableUrl'))
                ?? $this->safeUrl(data_get($place, 'restaurantData.tableReservationProvider.reserveTableUrl')),
            'provider' => $this->cleanString(data_get($place, 'restaurantData.tableReservationProvider.name')),
            'links' => $this->urlList(data_get($place, 'tableReservationLinks')),
        ], $notNull);

        $order = array_filter([
            'googleFood' => $this->safeUrl(data_get($place, 'googleFoodUrl')),
            'providers' => $this->providerList(data_get($place, 'orderBy')),
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
            'booking' => $this->urlList(data_get($place, 'bookingLinks')),
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
     * Order-online providers → list of { name, url } with safe URLs only. Null
     * when none survive.
     *
     * @return list<array{name?:string,url:string}>|null
     */
    private function providerList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $providers = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }
            $url = $this->safeUrl(data_get($item, 'url')) ?? $this->safeUrl(data_get($item, 'orderUrl'));
            if ($url === null) {
                continue;
            }
            $providers[] = array_filter([
                'name' => $this->cleanString(data_get($item, 'name')) ?? $this->cleanString(data_get($item, 'orderType')),
                'url' => $url,
            ], fn ($v) => $v !== null);
        }

        return $providers !== [] ? $providers : null;
    }
}
