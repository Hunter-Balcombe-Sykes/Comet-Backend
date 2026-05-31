<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;

// Scrapes an Eventbrite organiser's upcoming events with no auth. The organiser
// page (/o/<slug>-<id>) lists its event links; each event page carries a JSON-LD
// graph with name, venue + location, startDate, AggregateOffer (price range +
// availability), and a cover image. Returns the events soonest-first.
// Spec: ~/Developer/platform link capabilites/eventbrite.md
class EventbriteScraper extends PlatformScraper
{
    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    // Accept a full Eventbrite organiser URL → canonical /o/<slug-id> form, else null.
    public function normalizeOrgUrl(string $input): ?string
    {
        if (preg_match('~https?://(?:www\.)?eventbrite\.[a-z.]+/o/[a-z0-9-]+~i', trim($input), $m)) {
            return $m[0];
        }

        return null;
    }

    /**
     * The organiser's upcoming events, soonest first, up to $limit.
     *
     * @return array{organiser:?string, events:list<array<string,mixed>>}|null
     */
    public function fetchEvents(string $orgUrl, int $limit = 5): ?array
    {
        $headers = ['User-Agent' => self::USER_AGENT];

        $page = $this->fetcher->fetch($orgUrl, $headers);
        if ($page['status'] !== 200) {
            return null;
        }
        $organiser = $this->orgName($page['body']);

        // Unique event-detail links the org page lists.
        preg_match_all('~https://www\.eventbrite\.[a-z.]+/e/[a-z0-9-]+~i', $page['body'], $m);
        $eventUrls = array_values(array_unique($m[0]));

        // Fetch a few extra (some may be past / unparseable), parse each event's JSON-LD.
        $events = [];
        foreach (array_slice($eventUrls, 0, $limit + 3) as $url) {
            $event = $this->fetchEvent($url, $headers);
            if ($event) {
                $events[] = $event;
            }
        }

        // Soonest first; prefer upcoming (>= now), fall back to whatever we have.
        usort($events, fn ($a, $b) => strcmp((string) ($a['startDate'] ?? ''), (string) ($b['startDate'] ?? '')));
        $now = now()->toIso8601String();
        $upcoming = array_values(array_filter(
            $events,
            fn ($e) => empty($e['startDate']) || $e['startDate'] >= $now,
        ));

        return [
            'organiser' => $organiser,
            'events' => array_slice($upcoming ?: $events, 0, $limit),
        ];
    }

    /**
     * @return array{name:?string, venue:?string, location:?string, startDate:?string, price:?string, availability:?string, image:?string, link:string}|null
     */
    private function fetchEvent(string $url, array $headers): ?array
    {
        $res = $this->fetcher->fetch($url, $headers);
        if ($res['status'] !== 200) {
            return null;
        }

        // The event node is the JSON-LD object that has a startDate (Eventbrite
        // types events as Festival / MusicEvent / Event subtypes).
        $event = null;
        foreach ($this->jsonLdNodes($res['body']) as $node) {
            if (isset($node['startDate'])) {
                $event = $node;
                break;
            }
        }
        if (! $event) {
            return null;
        }

        $loc = $event['location'] ?? [];
        $offers = $event['offers'] ?? [];
        if (isset($offers[0])) {
            $offers = $offers[0];
        }
        $image = $event['image'] ?? null;
        if (is_array($image)) {
            $image = $image[0] ?? null;
        }

        return [
            'name' => $event['name'] ?? null,
            'venue' => data_get($loc, 'name'),
            'location' => data_get($loc, 'address.addressLocality') ?? data_get($loc, 'address.addressRegion'),
            'startDate' => $event['startDate'] ?? null,
            'price' => $this->formatPrice(is_array($offers) ? $offers : []),
            'availability' => $this->normalizeAvailability(data_get($offers, 'availability')),
            'image' => is_string($image) ? $image : null,
            'link' => $event['url'] ?? $url,
        ];
    }

    // Flatten every <script type="application/ld+json"> block (expanding @graph)
    // into one list of nodes.
    private function jsonLdNodes(string $html): array
    {
        $nodes = [];
        if (preg_match_all('~<script type="application/ld\+json">(.+?)</script>~s', $html, $m)) {
            foreach ($m[1] as $block) {
                $data = json_decode(trim($block), true);
                if (! is_array($data)) {
                    continue;
                }
                if (isset($data['@graph']) && is_array($data['@graph'])) {
                    $nodes = array_merge($nodes, $data['@graph']);
                } elseif (array_is_list($data)) {
                    $nodes = array_merge($nodes, $data);
                } else {
                    $nodes[] = $data;
                }
            }
        }

        return $nodes;
    }

    // AggregateOffer → a display string. "Free", "AUD 20.00", or "AUD 20.00 – 50.00".
    private function formatPrice(array $offers): ?string
    {
        $low = data_get($offers, 'lowPrice') ?? data_get($offers, 'price');
        if ($low === null) {
            return null;
        }
        $high = data_get($offers, 'highPrice');
        $cur = data_get($offers, 'priceCurrency');
        $prefix = $cur ? $cur.' ' : '';

        if ((float) $low === 0.0 && ($high === null || (float) $high === 0.0)) {
            return 'Free';
        }
        if ($high !== null && (float) $high !== (float) $low) {
            return "{$prefix}{$low} – {$high}";
        }

        return "{$prefix}{$low}";
    }

    // schema.org availability URL → "available" | "sold_out" | null.
    private function normalizeAvailability(?string $availability): ?string
    {
        if (! $availability) {
            return null;
        }
        $a = strtolower($availability);
        if (str_contains($a, 'soldout')) {
            return 'sold_out';
        }
        if (str_contains($a, 'instock') || str_contains($a, 'limited') || str_contains($a, 'presale') || str_contains($a, 'preorder')) {
            return 'available';
        }

        return null;
    }

    // og:title on the org page reads "<Organiser> Events | Eventbrite".
    private function orgName(string $html): ?string
    {
        if (preg_match('~<meta[^>]+property="og:title"[^>]+content="([^"]+)"~i', $html, $m)) {
            $title = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);

            return trim(preg_replace('~\s*(Events)?\s*\|\s*Eventbrite\s*$~i', '', $title)) ?: null;
        }

        return null;
    }
}
