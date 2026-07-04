<?php

namespace App\Services\Platforms;

use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Carbon;

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
        if (preg_match('~https?://(?:www\.)?eventbrite\.[a-z.]+/o/[a-z0-9-]+~i', PlatformInput::urlish($input), $m)) {
            return $m[0];
        }

        return null;
    }

    // Accept a single event-page URL (/e/<slug>) → canonical form, else null.
    public function normalizeEventUrl(string $input): ?string
    {
        if (preg_match('~https?://(?:www\.)?eventbrite\.[a-z.]+/e/[a-z0-9-]+~i', PlatformInput::urlish($input), $m)) {
            return $m[0];
        }

        return null;
    }

    /**
     * One event page → the stored event shape (same fields as fetchEvents
     * items). Used for user-added standalone events — the organiser need not
     * be connected. Null when the page can't be fetched or carries no event.
     *
     * @return array{name:?string, venue:?string, location:?string, startDate:?string, endDate:?string, price:?string, availability:?string, image:?string, link:string}|null
     */
    public function fetchSingleEvent(string $eventUrl): ?array
    {
        $res = $this->fetcher->tryFetch($eventUrl, ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }

        return $this->parseEvent($res, $eventUrl);
    }

    /**
     * The organiser's upcoming events, soonest first, up to $limit.
     *
     * @return array{organiser:?string, events:list<array<string,mixed>>}|null
     */
    public function fetchEvents(string $orgUrl, int $limit = 5): ?array
    {
        $headers = ['User-Agent' => self::USER_AGENT];

        $page = $this->fetcher->tryFetch($orgUrl, $headers);
        if ($page === null || $page['status'] !== 200) {
            return null;
        }
        $organiser = $this->orgName($page['body']);

        // Unique event-detail links the org page lists.
        preg_match_all('~https://www\.eventbrite\.[a-z.]+/e/[a-z0-9-]+~i', $page['body'], $m);
        $eventUrls = array_values(array_unique($m[0]));

        // Fetch a few extra (some may be past / unparseable). All detail pages are
        // independent of each other, so we fetch them concurrently — one pool
        // round-trip instead of up to (limit+3) serial DNS+TLS+HTTP round-trips.
        // fetchMany() preserves the same SSRF guarantees as the serial fetch() path:
        // every URL is pre-validated (scheme + public-IP check) and any 3xx redirect
        // target is re-validated before following, so a compromised/spoofed Eventbrite
        // URL cannot redirect us to an internal address.
        $batch = array_slice($eventUrls, 0, $limit + 3);
        $responses = $this->fetcher->fetchMany($batch, $headers);

        // Parse each returned body's JSON-LD (CPU-bound; done sequentially).
        $events = [];
        foreach ($batch as $url) {
            $res = $responses[$url] ?? null;
            if ($res === null) {
                continue;
            }
            $event = $this->parseEvent($res, $url);
            if ($event) {
                $events[] = $event;
            }
        }

        // Soonest first; prefer still-upcoming (end — or start when no end — >=
        // now), fall back to whatever we have. Using endDate keeps an in-progress
        // event (started, not yet ended) in the list.
        //
        // Carbon::parse is required because startDate/endDate carry the event's
        // LOCAL timezone offset (e.g. "2026-06-20T09:00:00-07:00"). Plain string
        // comparison against a UTC "now" string compares textual hour digits and
        // wrongly treats a 9am LA event as past versus a 16:xx UTC timestamp.
        // Events with a missing/empty startDate sort first (matching old behaviour)
        // and are never passed to Carbon::parse.
        $now = now();
        $this->sortByStartDate($events);
        $upcoming = array_values(array_filter(
            $events,
            function ($e) use ($now) {
                $compareDate = $e['endDate'] ?? $e['startDate'] ?? null;
                if (empty($compareDate)) {
                    return true;
                }

                return Carbon::parse($compareDate)->gte($now);
            },
        ));

        return [
            'organiser' => $organiser,
            'events' => array_slice($upcoming ?: $events, 0, $limit),
        ];
    }

    /**
     * Parse a single event-detail page response into a structured event array.
     * Called after the concurrent fetch; $res is the array returned by fetchMany.
     *
     * @param  array{status:int, body:string, finalUrl:string, contentType:string}  $res
     * @return array{name:?string, venue:?string, location:?string, startDate:?string, endDate:?string, price:?string, availability:?string, image:?string, link:string}|null
     */
    private function parseEvent(array $res, string $url): ?array
    {
        if ($res === null || $res['status'] !== 200) {
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
            'endDate' => $event['endDate'] ?? null,
            'price' => $this->formatPrice(is_array($offers) ? $offers : []),
            'availability' => $this->normalizeAvailability(data_get($offers, 'availability')),
            'image' => is_string($image) ? $image : null,
            'link' => $this->normalizeLink($event['url'] ?? $url),
        ];
    }

    /**
     * Rewrite any regional Eventbrite host (eventbrite.com.au, .co.uk, etc.)
     * to www.eventbrite.com so iOS Universal Link interception — which
     * matches per-domain — doesn't always route shared links into the
     * Eventbrite app. Eventbrite 301s .com to the regional URL anyway, so
     * the public event still resolves; the difference is the redirect runs
     * server-side instead of through the app.
     */
    private function normalizeLink(string $url): string
    {
        return preg_replace('~^(https?://)www\.eventbrite\.[a-z.]+(/.*)$~i', '$1www.eventbrite.com$2', $url) ?? $url;
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
