<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\HtmlScrapeBase;
use Carbon\Carbon;

// V5 Eventbrite scraper — scrapes an organiser's upcoming events with no auth.
// The organiser page (/o/<slug>-<id>) lists event links; each event page carries
// a JSON-LD graph with name, venue, dates, pricing, and a cover image. Returns
// events soonest-first. Multi-page event detail fetches are done concurrently.
// Replaces the old EventbriteScraper.
class EventbriteScraper extends HtmlScrapeBase
{
    /**
     * Main entry: fetch organiser info + upcoming events as V5 items.
     *
     * @return array{display_name:?string, organiser:?string, items:list<array>}|null
     */
    public function fetch(string $input, int $limit = 5): ?array
    {
        $orgUrl = $this->normalizeOrgUrl($input);
        if ($orgUrl === null) {
            return null;
        }

        $html = $this->fetchHtml($orgUrl);
        if ($html === null) {
            return null;
        }

        $organiser = $this->orgName($html);

        // Collect unique event-detail URLs from the org page
        preg_match_all('~https://www\.eventbrite\.[a-z.]+/e/[a-z0-9-]+~i', $html, $m);
        $eventUrls = array_values(array_unique($m[0]));

        if (empty($eventUrls)) {
            return [
                'display_name' => $organiser,
                'organiser' => $organiser,
                'items' => [],
            ];
        }

        // Fetch event detail pages concurrently (same batch pattern as old scraper)
        $batch = array_slice($eventUrls, 0, $limit + 3);
        $events = $this->fetchEventsConcurrent($batch);

        // Sort soonest-first, filter to upcoming
        $events = $this->sortEventsByDate($events);
        $upcoming = $this->filterUpcoming($events);

        $items = $this->mapEventsToItems(array_slice($upcoming ?: $events, 0, $limit));

        return [
            'display_name' => $organiser,
            'organiser' => $organiser,
            'items' => $items,
        ];
    }

    /**
     * Parse profile from an organiser page (not used directly — fetch()
     * orchestrates the full flow).
     */
    protected function parseProfile(string $html): ?array
    {
        $name = $this->orgName($html);

        if ($name === null) {
            return null;
        }

        return [
            'display_name' => $name,
        ];
    }

    // -----------------------------------------------------------------------
    // URL normalization
    // -----------------------------------------------------------------------

    /** Normalize an Eventbrite organiser URL to canonical /o/<slug-id> form. */
    private function normalizeOrgUrl(string $input): ?string
    {
        $input = $this->normalizeToUrl($input);

        if (preg_match('~https?://(?:www\.)?eventbrite\.[a-z.]+/o/[a-z0-9-]+~i', $input, $m)) {
            return $m[0];
        }

        return null;
    }

    private function normalizeToUrl(string $input): string
    {
        if (! str_starts_with($input, 'http')) {
            return 'https://'.$input;
        }

        return preg_replace('/^http:/', 'https:', $input);
    }

    // -----------------------------------------------------------------------
    // Concurrent event fetching
    // -----------------------------------------------------------------------

    /**
     * Fetch multiple event detail pages concurrently via fetchMany().
     * Preserved from the old EventbriteScraper: fetches limit+3 to account
     * for past/unparseable events, parses JSON-LD from each.
     *
     * @param  list<string>  $urls
     * @return list<array<string,mixed>>
     */
    private function fetchEventsConcurrent(array $urls): array
    {
        $headers = ['User-Agent' => self::USER_AGENT];
        $responses = $this->fetcher->fetchMany($urls, $headers);

        $events = [];
        foreach ($urls as $url) {
            $res = $responses[$url] ?? null;
            if ($res === null || ($res['status'] ?? 0) !== 200) {
                continue;
            }

            $event = $this->parseEventJsonLd($res['body'], $url);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * Parse a single event-detail page's JSON-LD for the event node (the
     * one with a startDate). Preserved from the old EventbriteScraper
     * parseEvent logic.
     *
     * @return array<string,mixed>|null
     */
    private function parseEventJsonLd(string $html, string $url): ?array
    {
        $event = null;
        foreach ($this->jsonLdNodes($html) as $node) {
            if (isset($node['startDate'])) {
                $event = $node;
                break;
            }
        }

        if ($event === null) {
            return null;
        }

        $loc = $event['location'] ?? [];
        $offersRaw = $event['offers'] ?? [];
        $offers = $offersRaw;
        if (isset($offers[0])) {
            $offers = $offers[0];
        }

        $image = $event['image'] ?? null;
        if (is_array($image)) {
            $image = $image[0] ?? null;
        }

        $availability = $this->normalizeAvailability(data_get($offers, 'availability'));
        $lowest = $this->lowestOffer($offersRaw);

        return [
            'name' => $event['name'] ?? null,
            'venue' => data_get($loc, 'name'),
            'location' => data_get($loc, 'address.addressLocality') ?? data_get($loc, 'address.addressRegion'),
            'startDate' => $event['startDate'] ?? null,
            'endDate' => $event['endDate'] ?? null,
            'description' => $this->sanitizeDescription($event['description'] ?? null),
            'price' => $this->formatPrice(is_array($offers) ? $offers : []),
            'priceMin' => $lowest['priceMin'] ?? null,
            'currency' => $lowest['currency'] ?? null,
            'availability' => $availability,
            'soldOut' => $availability === 'sold_out',
            'image' => is_string($image) ? $image : null,
            'link' => $this->normalizeLink($event['url'] ?? $url),
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** og:title on the org page reads "<Organiser> Events | Eventbrite". */
    private function orgName(string $html): ?string
    {
        if (preg_match('~<meta[^>]+property="og:title"[^>]+content="([^"]+)"~i', $html, $m)) {
            $title = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);

            return trim(preg_replace('~\s*(Events)?\s*\|\s*Eventbrite\s*$~i', '', $title)) ?: null;
        }

        return null;
    }

    /**
     * Rewrite regional Eventbrite hosts to www.eventbrite.com so iOS Universal
     * Links don't route into the Eventbrite app.
     */
    private function normalizeLink(string $url): string
    {
        return preg_replace('~^(https?://)www\.eventbrite\.[a-z.]+(/.*)$~i', '$1www.eventbrite.com$2', $url) ?? $url;
    }

    /** Sort events soonest-first by startDate. */
    private function sortEventsByDate(array &$events): array
    {
        return $this->sortByStartDate($events);
    }

    /**
     * Filter to still-upcoming events (endDate or startDate >= now).
     * Preserved from the old scraper's Carbon-based date comparison.
     */
    private function filterUpcoming(array $events): array
    {
        $now = now();

        return array_values(array_filter($events, function ($e) use ($now) {
            $compareDate = $e['endDate'] ?? $e['startDate'] ?? null;
            if (empty($compareDate)) {
                return true;
            }

            return Carbon::parse($compareDate)->gte($now);
        }));
    }

    /** schema.org availability URL → "available" | "sold_out" | null. */
    private function normalizeAvailability(?string $availability): ?string
    {
        if ($availability === null) {
            return null;
        }

        $a = strtolower($availability);
        if (str_contains($a, 'soldout') || str_contains($a, 'outofstock')) {
            return 'sold_out';
        }
        if (str_contains($a, 'instock') || str_contains($a, 'limited') || str_contains($a, 'presale') || str_contains($a, 'preorder')) {
            return 'available';
        }

        return null;
    }

    /**
     * Map event data to V5 item format.
     *
     * @param  list<array<string,mixed>>  $events
     * @return list<array{identifier:string, name:?string, item_type:string, values:list<array{field_name:string, value:string, format:string}>}>
     */
    private function mapEventsToItems(array $events): array
    {
        return array_map(function (array $event) {
            $link = $event['link'] ?? '';
            $values = [
                ['field_name' => 'title', 'value' => $event['name'] ?? '', 'format' => 'text'],
                ['field_name' => 'url', 'value' => $link, 'format' => 'url'],
                ['field_name' => 'start_date', 'value' => $event['startDate'] ?? '', 'format' => 'date'],
                ['field_name' => 'end_date', 'value' => $event['endDate'] ?? '', 'format' => 'date'],
            ];

            if (! empty($event['description'])) {
                $values[] = ['field_name' => 'description', 'value' => $event['description'], 'format' => 'text'];
            }
            if (! empty($event['image'])) {
                $values[] = ['field_name' => 'image', 'value' => $event['image'], 'format' => 'image'];
            }
            if (! empty($event['venue'])) {
                $values[] = ['field_name' => 'venue', 'value' => $event['venue'], 'format' => 'text'];
            }
            if (! empty($event['location'])) {
                $values[] = ['field_name' => 'location', 'value' => $event['location'], 'format' => 'text'];
            }
            if (! empty($event['price'])) {
                $values[] = ['field_name' => 'price', 'value' => $event['price'], 'format' => 'text'];
            }
            if (! empty($event['currency'])) {
                $values[] = ['field_name' => 'currency', 'value' => $event['currency'], 'format' => 'text'];
            }
            if (isset($event['soldOut'])) {
                $values[] = ['field_name' => 'sold_out', 'value' => $event['soldOut'] ? 'true' : 'false', 'format' => 'text'];
            }

            return [
                'identifier' => $link,
                'name' => $event['name'] ?? null,
                'item_type' => 'event',
                'values' => $values,
            ];
        }, $events);
    }

    /**
     * Override the abstract resolveHandle to extract from Eventbrite URLs.
     */
    protected function resolveHandle(string $url): ?string
    {
        // Extract the organiser slug from the /o/<slug> URL
        if (preg_match('~/o/([a-z0-9-]+)~i', $url, $m)) {
            return $m[1];
        }

        return parent::resolveHandle($url);
    }
}
