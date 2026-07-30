<?php

namespace App\Services\Platforms;

use App\Services\Cache\ApifyBudget;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

// Deeper sweep of a Google listing's photo stream for the automatic menu
// scan — the same compass/crawler-google-places plumbing as
// GoogleBusinessApifyScraper, but an IMAGES run: no detail page, no reviews,
// just maxImages of the place's photo stream. The actor exposes NO
// category-filtered image input (imageCategories is an OUTPUT field naming
// the tabs Google shows), so menu-photo detection stays downstream:
// GoogleMenuPhotoScanJob OCRs each URL and keeps the text-dense ones — a
// menu board OCRs to hundreds of characters, a latte photo to ~nothing.
//
// This run only fires when the FREE tier (the Place-Details photos already
// stored on the connection payload) yielded no menu-dense text — it's the
// paid second dig, budget-gated like every other Apify spend.
class GoogleMenuImagesScraper
{
    private const MAX_IMAGES = 14;

    /** Fallback default for config('partna.limits.apify.run_sync_timeout_seconds') (CFG-9). */
    private const RUN_SYNC_TIMEOUT_SECONDS = 110;

    /**
     * The listing's photo-stream image URLs (bounded), or null on missing
     * token / budget exhaustion / failure — the caller treats null and []
     * the same way (nothing further to scan).
     *
     * @return list<string>|null
     */
    public function fetch(string $placeId, ?string $userId = null): ?array
    {
        $token = config('services.apify.token');
        if (! $token) {
            return null;
        }

        // SCALE-2: same shared-budget contract as the enrichment scraper.
        if (! app(ApifyBudget::class)->tryClaim('google-business')) {
            Log::warning('google_menu_images.budget_exhausted', ['place_id' => $placeId, 'user_id' => $userId]);

            return null;
        }

        try {
            $response = Http::withToken($token)
                ->timeout((int) config('partna.limits.apify.run_sync_timeout_seconds', self::RUN_SYNC_TIMEOUT_SECONDS))
                ->post(
                    'https://api.apify.com/v2/acts/'.config('services.apify.actors.google_places').'/run-sync-get-dataset-items',
                    [
                        'placeIds' => [$placeId],
                        'language' => 'en',
                        'maxCrawledPlacesPerSearch' => 1,
                        'maxImages' => self::MAX_IMAGES,
                        'maxReviews' => 0,
                        'scrapeReviewsPersonalData' => false,
                        'maximumLeadsEnrichmentRecords' => 0,
                    ],
                );
        } catch (Throwable $e) {
            report($e);
            Log::warning('google_menu_images.threw', ['place_id' => $placeId, 'user_id' => $userId, 'error' => $e->getMessage()]);

            return null;
        }

        // run-sync-get-dataset-items returns 201 on success.
        if (! $response->successful()) {
            if ($response->status() >= 500) {
                report(new \RuntimeException('Apify google-menu-images scrape failed with status '.$response->status()));
            }
            Log::warning('google_menu_images.not_ok', ['place_id' => $placeId, 'user_id' => $userId, 'status' => $response->status()]);

            return null;
        }

        $items = $response->json();
        if (! is_array($items) || empty($items) || ! is_array($items[0])) {
            return null;
        }

        // imageUrls is the actor's documented list-of-strings output; tolerate
        // an images[] object list too (same defensive-mapping stance as the
        // enrichment scraper).
        $place = $items[0];
        $urls = [];
        foreach ((array) ($place['imageUrls'] ?? []) as $url) {
            if (is_string($url) && preg_match('~^https?://~i', $url) === 1) {
                $urls[] = $url;
            }
        }
        if ($urls === []) {
            foreach ((array) ($place['images'] ?? []) as $image) {
                $url = is_array($image) ? ($image['imageUrl'] ?? $image['url'] ?? null) : null;
                if (is_string($url) && preg_match('~^https?://~i', $url) === 1) {
                    $urls[] = $url;
                }
            }
        }

        return array_slice(array_values(array_unique($urls)), 0, self::MAX_IMAGES);
    }
}
