<?php

namespace App\Services\V5\Scraping\BaseTemplates;

use App\Services\V5\Scraping\BaseScraper;
use App\Services\V5\Scraping\Budget\ApifyBudget;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// V5 ApifyBase — for platforms that use Apify actors.
// Handles: actor dispatch, run-sync-get-dataset-items polling, budget management,
// per-item error checks, field-name drift (5+ variants per field).
//
// Usage: extend this, set $actorName + $actorInput, add field mappings.
abstract class ApifyBase extends BaseScraper
{
    protected string $actorName = '';
    protected array $actorInput = [];
    protected string $apifyToken = '';
    protected int $timeout = 120;
    protected int $pollIntervalMs = 2000;
    protected int $maxPollAttempts = 60;

    public function __construct(
        \App\Services\Http\SafeUrlFetcher $fetcher,
        protected readonly ApifyBudget $apifyBudget,
    ) {
        parent::__construct($fetcher);
        $this->apifyToken = config('services.apify.token', '');
    }

    /** Dispatch actor and return dataset items. */
    protected function runActor(array $inputOverrides = []): ?array
    {
        if (empty($this->apifyToken)) {
            $this->logFailure($this->actorName, 'dispatch', 'APIFY_TOKEN not configured');
            return null;
        }

        if ($this->apifyBudget->isExhausted($this->actorName)) {
            $this->logFailure($this->actorName, 'budget', 'Daily cap exhausted');
            return null;
        }

        $input = array_merge($this->actorInput, $inputOverrides);

        $result = $this->dispatch($input);
        if ($result === null) return null;

        // Sync path: dispatch returned items directly from a synchronous actor run
        if (is_array($result)) {
            $items = $result;
        } else {
            // Async path: poll for results
            $items = $this->pollForResults($result);
            if ($items === null) return null;
        }

        $this->apifyBudget->record($this->actorName, count($items));

        return $items;
    }

    private function dispatch(array $input): string|array|null
    {
        $url = "https://api.apify.com/v2/acts/{$this->actorName}/run-sync-get-dataset-items";

        try {
            $response = Http::withToken($this->apifyToken)
                ->timeout($this->timeout)
                ->post($url, $input);

            // 201 = synchronous completion with dataset items in the response body
            if ($response->status() === 201 && is_array($response->json())) {
                return $response->json();
            }

            if ($response->successful()) {
                $data = $response->json();
                return $data['data']['id'] ?? null;
            }

            // Rate limited — wait and retry once
            if ($response->status() === 429) {
                sleep(10);
                return $this->dispatch($input);
            }

            $this->logFailure($this->actorName, 'dispatch', "HTTP {$response->status()}");
            return null;
        } catch (\Throwable $e) {
            $this->logFailure($this->actorName, 'dispatch', $e->getMessage());
            return null;
        }
    }

    private function pollForResults(string $runId): ?array
    {
        $url = "https://api.apify.com/v2/actor-runs/{$runId}/dataset/items";

        for ($i = 0; $i < $this->maxPollAttempts; $i++) {
            usleep($this->pollIntervalMs * 1000);

            try {
                $response = Http::withToken($this->apifyToken)
                    ->timeout(30)
                    ->get($url);

                if ($response->successful()) {
                    $items = $response->json();
                    if (is_array($items) && ! empty($items)) {
                        return $items;
                    }
                }

                if ($response->status() === 404) {
                    // Dataset not ready yet, keep polling
                    continue;
                }
            } catch (\Throwable $e) {
                if ($i === $this->maxPollAttempts - 1) {
                    $this->logFailure($this->actorName, 'poll', $e->getMessage());
                    return null;
                }
            }
        }

        $this->logFailure($this->actorName, 'poll', 'Timeout after '.$this->maxPollAttempts.' attempts');
        return null;
    }

    /**
     * Handle field-name drift across actor versions.
     * Override to map raw actor output → canonical field names.
     */
    protected function mapItem(array $raw): array
    {
        return $raw;
    }

    /** Validate that a single item from the actor is usable. */
    protected function isValidItem(array $item): bool
    {
        return ! isset($item['error']) && ! isset($item['statusCode']);
    }

    /** Filter, validate, and map raw actor output. */
    protected function processItems(array $raw): array
    {
        $items = [];
        foreach ($raw as $item) {
            if (! $this->isValidItem($item)) continue;
            $mapped = $this->mapItem($item);
            if (! empty($mapped)) {
                $items[] = $mapped;
            }
        }
        return $items;
    }

    // Instagram-specific field drift helpers (reused by any Apify scraper)

    /**
     * Handle the 5+ ways an Instagram actor returns profile picture URLs.
     * Override in InstagramScraper or reuse directly.
     */
    protected function extractProfilePicUrl(array $item): ?string
    {
        return $this->fieldDrift($item, [
            'profilePicUrlHD',
            'profile_pic_url_hd',
            'profilePicUrl',
            'profile_pic_url',
        ]);
    }

    /**
     * Handle the 5+ ways Instagram returns bio links.
     * Returns up to $limit cleaned, deduped URLs.
     */
    protected function extractBioLinks(array $item, int $limit = 10): array
    {
        $links = [];

        // externalUrl (single string)
        $extUrl = $item['externalUrl'] ?? null;
        if (is_string($extUrl) && $extUrl !== '') {
            $links[] = $extUrl;
        }

        // externalUrls array
        $extUrls = $item['externalUrls'] ?? [];
        if (is_array($extUrls)) {
            foreach ($extUrls as $url) {
                if (is_array($url) && isset($url['url'])) {
                    $links[] = $url['url'];
                } elseif (is_string($url)) {
                    $links[] = $url;
                }
            }
        }

        // bio_links array
        $bioLinks = $item['bio_links'] ?? $item['bioLinks'] ?? [];
        if (is_array($bioLinks)) {
            foreach ($bioLinks as $bl) {
                $link = is_array($bl) ? ($bl['url'] ?? $bl['link'] ?? '') : (string) $bl;
                if ($link !== '') $links[] = $link;
            }
        }

        // biography regex fallback
        $biography = $item['biography'] ?? $item['bio'] ?? '';
        if (is_string($biography) && $biography !== '') {
            if (preg_match_all('#https?://[^\s]+#', $biography, $m)) {
                $links = array_merge($links, $m[0]);
            }
        }

        // Dedupe, clean, limit
        $links = array_unique(array_map('trim', $links));
        $links = array_values(array_filter($links, fn ($l) => $l !== ''));

        return array_slice($links, 0, $limit);
    }

    /**
     * Extract URLs from post captions for routing through the V5 link router.
     */
    protected function extractCaptionUrls(array $item): array
    {
        $caption = $item['caption'] ?? $item['description'] ?? $item['text'] ?? '';
        if (! is_string($caption) || $caption === '') {
            return [];
        }

        if (preg_match_all('#https?://[^\s]+#', $caption, $m)) {
            return array_unique(array_map('trim', $m[0]));
        }

        return [];
    }

    /**
     * Determine if an Instagram post is a video.
     * Handles 5+ representations across actor versions.
     */
    protected function isVideoPost(array $item): bool
    {
        $type = $item['type'] ?? $item['media_type'] ?? $item['__typename'] ?? '';
        if (in_array($type, ['Video', 'GraphVideo', 'XDTGraphVideo', 'video'], true)) {
            return true;
        }

        if (! empty($item['is_video']) || ! empty($item['isVideo'])) {
            return true;
        }

        $productType = $item['product_type'] ?? $item['productType'] ?? '';
        if (in_array($productType, ['clips', 'igtv', 'feed_video', 'reel'], true)) {
            return true;
        }

        return false;
    }
}
