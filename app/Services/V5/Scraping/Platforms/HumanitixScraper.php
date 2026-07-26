<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\HtmlScrapeBase;
use Carbon\Carbon;

// V5 Humanitix scraper — scrapes a host's upcoming events with no auth. Event
// pages carry a clean schema.org Event JSON-LD (name, dates with local offset,
// Place + address, AggregateOffer, image). The host page
// (events.humanitix.com/host/…) links its event pages. When the host page
// itself embeds Event JSON-LD, that's used directly — no per-event fetches.
// Replaces the old HumanitixScraper.
class HumanitixScraper extends HtmlScrapeBase
{
    // Host-page path segments that are product chrome, not event slugs.
    private const NON_EVENT_SLUGS = [
        'host', 'search', 'tours', 'sell', 'about', 'signin', 'login', 'signup',
        'contact', 'contact-us', 'help', 'privacy', 'terms', 'blog', 'faqs',
        'careers', 'pricing', 'features', 'refunds', 'cookies', 'us', 'au', 'nz', 'uk',
    ];

    /**
     * Main entry: fetch host info + upcoming events as V5 items.
     *
     * @return array{display_name:?string, organiser:?string, items:list<array>}|null
     */
    public function fetch(string $input, int $limit = 5): ?array
    {
        $hostUrl = $this->resolveHostUrl($input);
        if ($hostUrl === null) {
            return null;
        }

        $html = $this->fetchHtml($hostUrl);
        if ($html === null) {
            return null;
        }

        $organiser = $this->hostName($html);

        // Fast path: host page may embed events' JSON-LD directly.
        $events = $this->parseEventsFromHostPage($html, $hostUrl);

        // Fallback: harvest candidate event links and fetch each page.
        if (empty($events)) {
            $events = $this->fetchEventsFromLinks($html, $limit);
        }

        // Sort soonest-first, filter to upcoming
        $events = $this->sortEventsByDate($events);
        $upcoming = $this->filterUpcoming($events);

        $items = $this->mapEventsToItems(array_slice($upcoming ?: $events, 0, $limit));

        return [
            'items' => $items,
            'profile' => [
                'display_name' => $organiser,
                'profile_pic_url' => null,
            ],
        ];
    }

    /**
     * Build a price display string from a JSON-LD offers value.
     * Replicates the old PlatformScraper::formatPrice logic (accepts array,
     * not a float) since V5 BaseScraper::formatPrice expects a float.
     */
    private function formatPriceFromOffers(array $offers): ?string
    {
        $low = $offers['lowPrice'] ?? $offers['price'] ?? null;
        if ($low === null) {
            return null;
        }
        $high = $offers['highPrice'] ?? null;
        $cur = $offers['priceCurrency'] ?? null;
        $prefix = $cur ? $cur.' ' : '';

        if ((float) $low === 0.0 && ($high === null || (float) $high === 0.0)) {
            return 'Free';
        }
        if ($high !== null && (float) $high !== (float) $low) {
            return "{$prefix}{$low} – {$high}";
        }

        return "{$prefix}{$low}";
    }

    /**
     * Parse profile from a host page HTML.
     */
    protected function parseProfile(string $html): ?array
    {
        $name = $this->hostName($html);

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

    /**
     * Resolve any Humanitix URL to the canonical host page. A host URL
     * normalises directly; an event URL is fetched once to find its host link.
     * Preserved from the old HumanitixScraper::resolveHostUrl.
     */
    private function resolveHostUrl(string $input): ?string
    {
        $input = $this->normalizeToUrl($input);

        // Bare host slug
        if (preg_match('~^[a-z0-9-]{2,80}$~i', trim($input))) {
            return 'https://events.humanitix.com/host/'.strtolower(trim($input));
        }

        if (preg_match('~^https?://(?:events\.)?humanitix\.com/host/([a-z0-9-]+)~i', $input, $m)) {
            return 'https://events.humanitix.com/host/'.strtolower($m[1]);
        }

        // Event page → find the host link
        if (preg_match('~^https?://events\.humanitix\.com/([a-z0-9-]+)~i', $input, $m)
            && ! in_array(strtolower($m[1]), self::NON_EVENT_SLUGS, true)) {
            $eventHtml = $this->fetchHtml('https://events.humanitix.com/'.strtolower($m[1]));
            if ($eventHtml !== null
                && preg_match('~href="(?:https://events\.humanitix\.com)?/host/([a-z0-9-]+)~i', $eventHtml, $h)) {
                return 'https://events.humanitix.com/host/'.strtolower($h[1]);
            }
        }

        return null;
    }

    protected function normalizeToUrl(string $input): string
    {
        if (! str_starts_with($input, 'http')) {
            return 'https://'.$input;
        }

        return preg_replace('/^http:/', 'https:', $input);
    }

    // -----------------------------------------------------------------------
    // Event parsing
    // -----------------------------------------------------------------------

    /**
     * Parse events from JSON-LD embedded on the host page itself.
     * Preserved from the old HumanitixScraper's fast path.
     *
     * @return list<array<string,mixed>>
     */
    private function parseEventsFromHostPage(string $html, string $fallbackUrl): array
    {
        $events = [];
        foreach ($this->jsonLdNodes($html) as $node) {
            if (is_array($node) && isset($node['startDate'])) {
                $events[] = $this->parseEventNode($node, $fallbackUrl);
            }
        }

        return $events;
    }

    /**
     * Fetch events by following candidate links from the host page.
     * Preserved from the old HumanitixScraper's fallback path.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchEventsFromLinks(string $html, int $limit): array
    {
        $candidates = $this->candidateEventUrls($html);
        $batch = array_slice($candidates, 0, $limit + 5);
        $headers = ['User-Agent' => self::USER_AGENT];
        $responses = $this->fetcher->fetchMany($batch, $headers);

        $events = [];
        foreach ($batch as $url) {
            $res = $responses[$url] ?? null;
            if ($res === null || ($res['status'] ?? 0) !== 200) {
                continue;
            }

            foreach ($this->jsonLdNodes($res['body']) as $node) {
                if (is_array($node) && isset($node['startDate'])) {
                    $events[] = $this->parseEventNode($node, $url);
                    break;
                }
            }
        }

        return $events;
    }

    /**
     * Parse a single schema.org Event node into the event shape.
     * Preserved from the old HumanitixScraper::parseEventNode.
     *
     * @return array<string,mixed>
     */
    private function parseEventNode(array $event, string $fallbackUrl): array
    {
        $loc = $event['location'] ?? [];
        $offersRaw = $event['offers'] ?? [];
        $offers = $offersRaw;
        if (isset($offers[0])) {
            $offers = $offers[0];
        }
        if (! is_array($offers)) {
            $offers = [];
        }

        $image = $event['image'] ?? null;
        if (is_array($image)) {
            $image = $image[0] ?? null;
        }

        $availability = $this->normalizeAvailability(data_get($offers, 'availability'));

        return [
            'name' => $event['name'] ?? null,
            'venue' => data_get($loc, 'name') !== null ? trim((string) data_get($loc, 'name')) : null,
            'location' => data_get($loc, 'address.addressLocality') ?? data_get($loc, 'address.addressRegion'),
            'startDate' => $event['startDate'] ?? null,
            'endDate' => $event['endDate'] ?? null,
            'description' => $this->sanitizeDescription($event['description'] ?? null),
            'price' => $this->formatPriceFromOffers(is_array($offers) ? $offers : []),
            'priceMin' => $offers['lowPrice'] ?? $offers['price'] ?? null,
            'currency' => $offers['priceCurrency'] ?? null,
            'availability' => $availability,
            'soldOut' => $availability === 'sold_out',
            'image' => is_string($image) ? $image : null,
            'link' => is_string($event['url'] ?? null) ? $event['url'] : $fallbackUrl,
        ];
    }

    /**
     * Candidate event-page URLs linked from the host page, deduped.
     * Preserved from the old HumanitixScraper::candidateEventUrls.
     *
     * @return list<string>
     */
    private function candidateEventUrls(string $html): array
    {
        $urls = [];
        if (preg_match_all('~href="(?:https://events\.humanitix\.com)?/([a-z0-9][a-z0-9-]{2,})(?:\?[^"]*)?"~i', $html, $m)) {
            foreach ($m[1] as $slug) {
                $slug = strtolower($slug);
                if (in_array($slug, self::NON_EVENT_SLUGS, true)) {
                    continue;
                }
                $urls['https://events.humanitix.com/'.$slug] = true;
            }
        }

        return array_keys($urls);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** og:title on the host page reads "<Host> | Humanitix". */
    private function hostName(string $html): ?string
    {
        $title = $this->metaContent($html, 'title');
        if (! is_string($title)) {
            return null;
        }

        return trim(preg_replace('~\s*[|·-]\s*Humanitix\s*$~i', '', $title)) ?: null;
    }

    /** Sort events soonest-first by startDate. */
    private function sortEventsByDate(array &$events): array
    {
        return $this->sortByStartDate($events);
    }

    /** Filter to still-upcoming events (endDate or startDate >= now). */
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
}
