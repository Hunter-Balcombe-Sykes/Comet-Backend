<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\HtmlScrapeBase;
use App\Services\V5\Scraping\Contracts\FetchContract;
use Illuminate\Support\Facades\Log;

// V5 Fresha scraper — fetches salon location data (team, services) from a
// Fresha public page. Fresha uses HTML scraping of the Next.js page for the
// location data (__NEXT_DATA__ JSON-LD), with no API key needed.
//
// Falls back to empty items with a logged warning when the page is unavailable
// or the __NEXT_DATA__ structure changes.
class FreshaScraper extends HtmlScrapeBase implements FetchContract
{
    private const SCRAPE_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /**
     * Fetch services/team from a Fresha location URL.
     *
     * @param string $identifier Fresha location URL
     * @return array{items: list<array>, profile: array}
     */
    public function fetch(string $identifier): array
    {
        $url = $this->stripLocale($identifier);
        $html = $this->fetchHtml($url, ['User-Agent' => self::SCRAPE_USER_AGENT]);
        if ($html === null) {
            Log::warning('v5.fresha.page_unavailable', ['url' => $url]);
            return ['items' => [], 'profile' => []];
        }

        $location = $this->extractLocationData($html);
        if ($location === null) {
            Log::warning('v5.fresha.no_location_data', ['url' => $url]);
            return ['items' => [], 'profile' => []];
        }

        $storeName = $this->extractStoreName($location);
        $services = $this->extractServices($location);

        $items = [];
        foreach ($services as $service) {
            $values = [
                ['field_name' => 'name', 'value' => $service['name'] ?? '', 'format' => 'text'],
                ['field_name' => 'duration', 'value' => $service['duration'] ?? '', 'format' => 'text'],
            ];
            if (! empty($service['description'])) {
                $values[] = ['field_name' => 'description', 'value' => $service['description'], 'format' => 'text'];
            }
            if (! empty($service['price'])) {
                $values[] = ['field_name' => 'price', 'value' => $service['price'], 'format' => 'text'];
            }
            if (! empty($service['category'])) {
                $values[] = ['field_name' => 'category', 'value' => $service['category'], 'format' => 'text'];
            }

            $items[] = [
                'identifier' => $service['serviceId'],
                'name' => $service['name'] ?? 'Untitled Service',
                'item_type' => 'service',
                'values' => $values,
            ];
        }

        $profile = [];
        if ($storeName !== null) {
            $profile['display_name'] = $storeName;
        }

        return ['items' => $items, 'profile' => $profile];
    }

    /**
     * Required by HtmlScrapeBase — not used directly. fetch() handles the full flow.
     */
    protected function parseProfile(string $html): ?array
    {
        return null;
    }

    /**
     * Strip locale segment so we always use the canonical /a/<slug> form.
     */
    private function stripLocale(string $url): string
    {
        return preg_replace('#fresha\.com/[a-z]{2,3}(-[a-z]{2})?/a/#i', 'fresha.com/a/', $url) ?? $url;
    }

    /**
     * Extract the __NEXT_DATA__ location object from page HTML.
     *
     * @return array|null The location data, or null if not found
     */
    private function extractLocationData(string $html): ?array
    {
        if (! preg_match('#<script id="__NEXT_DATA__"[^>]*>(.+?)</script>#s', $html, $m)) {
            return null;
        }

        $data = json_decode($m[1], true);
        if (! is_array($data)) {
            return null;
        }

        $location = data_get($data, 'props.pageProps.data.location');

        return is_array($location) ? $location : null;
    }

    /**
     * Extract store display name from location data.
     */
    private function extractStoreName(array $location): ?string
    {
        $name = $location['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Extract services from location data.
     *
     * @return list<array{serviceId: string, name: string, duration: ?string, description: ?string, price: ?string, category: ?string}>
     */
    private function extractServices(array $location): array
    {
        $categories = data_get($location, 'services', []);
        if (! is_array($categories)) {
            return [];
        }

        $out = [];
        foreach ($categories as $category) {
            $categoryName = $category['name'] ?? null;
            foreach (($category['items'] ?? []) as $item) {
                $id = (string) ($item['id'] ?? '');
                if (! str_starts_with($id, 's:')) {
                    continue;
                }

                $out[] = [
                    'serviceId' => $id,
                    'name' => (string) ($item['name'] ?? ''),
                    'duration' => $item['caption'] ?? null,
                    'description' => $item['description'] ?? null,
                    'price' => $item['formattedRetailPrice'] ?? null,
                    'category' => $categoryName,
                ];
            }
        }

        return $out;
    }
}
