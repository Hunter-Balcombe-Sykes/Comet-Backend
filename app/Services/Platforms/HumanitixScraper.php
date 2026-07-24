<?php

namespace App\Services\Platforms;

use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Carbon;

// Scrapes a Humanitix host's upcoming events with no auth. Event pages carry a
// clean schema.org Event JSON-LD (name, dates with local offset, Place +
// address, AggregateOffer, image). The host page (events.humanitix.com/host/…)
// links its event pages; when the host page itself embeds Event JSON-LD we use
// that directly and skip the per-event fetches.
class HumanitixScraper extends PlatformScraper
{
    // Host-page path segments that are product chrome, not event slugs.
    private const NON_EVENT_SLUGS = [
        'host', 'search', 'tours', 'sell', 'about', 'signin', 'login', 'signup',
        'contact', 'contact-us', 'help', 'privacy', 'terms', 'blog', 'faqs',
        'careers', 'pricing', 'features', 'refunds', 'cookies', 'us', 'au', 'nz', 'uk',
    ];

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /**
     * Resolve any pasted Humanitix URL to the canonical host page. A host URL
     * normalises directly; an event URL is fetched once to find its host link.
     */
    public function resolveHostUrl(string $input): ?string
    {
        $input = PlatformInput::urlish($input);

        // A bare host slug maps straight onto the canonical host page.
        if (PlatformInput::isBareToken($input, '~^[a-z0-9-]{2,80}$~i')) {
            return 'https://events.humanitix.com/host/'.strtolower(PlatformInput::token($input));
        }

        if (preg_match('~^https?://(?:events\.)?humanitix\.com/host/([a-z0-9-]+)~i', $input, $m)) {
            return 'https://events.humanitix.com/host/'.strtolower($m[1]);
        }

        // An event page → find the host link on it.
        if (preg_match('~^https?://events\.humanitix\.com/([a-z0-9-]+)~i', $input, $m)
            && ! in_array(strtolower($m[1]), self::NON_EVENT_SLUGS, true)) {
            $res = $this->fetcher->tryFetch('https://events.humanitix.com/'.strtolower($m[1]), ['User-Agent' => self::USER_AGENT]);
            // $res is null on transport failure (tryFetch swallows it) — reading
            // $res['status'] unguarded turns that into an ErrorException, i.e. a
            // 500 instead of the caller's intended 422. Same WS-B1 lesson as
            // ShopifyScraper::fetchProducts(); newly routine now that this
            // resolver runs inside a FetchBudget, whose exhaustion yields null.
            if ($res !== null
                && $res['status'] === 200
                && preg_match('~href="(?:https://events\.humanitix\.com)?/host/([a-z0-9-]+)~i', $res['body'], $h)) {
                return 'https://events.humanitix.com/host/'.strtolower($h[1]);
            }
        }

        return null;
    }

    // Accept a single event-page URL (events.humanitix.com/<slug>) → canonical
    // form, else null. Host pages and product chrome are rejected.
    public function normalizeEventUrl(string $input): ?string
    {
        $input = PlatformInput::urlish($input);
        if (preg_match('~^https?://events\.humanitix\.com/([a-z0-9][a-z0-9-]{2,})~i', $input, $m)
            && ! in_array(strtolower($m[1]), self::NON_EVENT_SLUGS, true)) {
            return 'https://events.humanitix.com/'.strtolower($m[1]);
        }

        return null;
    }

    /**
     * One event page → the stored event shape (same fields as fetchEvents
     * items). Used for user-added standalone events — the host need not be
     * connected. Null when the page can't be fetched or carries no event.
     *
     * @return array{name:?string, venue:?string, location:?string, startDate:?string, endDate:?string, price:?string, availability:?string, image:?string, link:string}|null
     */
    public function fetchSingleEvent(string $eventUrl): ?array
    {
        $res = $this->fetcher->tryFetch($eventUrl, ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }
        foreach ($this->jsonLdNodes($res['body']) as $node) {
            if (is_array($node) && isset($node['startDate'])) {
                return $this->parseEventNode($node, $eventUrl);
            }
        }

        return null;
    }

    /**
     * The host's upcoming events, soonest first, up to $limit.
     *
     * @return array{organiser:?string, events:list<array<string,mixed>>}|null
     */
    public function fetchEvents(string $hostUrl, int $limit = 5): ?array
    {
        $headers = ['User-Agent' => self::USER_AGENT];

        $page = $this->fetcher->tryFetch($hostUrl, $headers);
        if ($page === null || $page['status'] !== 200) {
            return null;
        }
        $organiser = $this->hostName($page['body']);

        // Fast path: the host page sometimes embeds the events' JSON-LD itself.
        $events = [];
        foreach ($this->jsonLdNodes($page['body']) as $node) {
            if (is_array($node) && isset($node['startDate'])) {
                $events[] = $this->parseEventNode($node, $hostUrl);
            }
        }

        // Fallback: harvest candidate event links and read each page's JSON-LD.
        // A non-event link self-filters (no startDate node on its page).
        if ($events === []) {
            $batch = array_slice($this->candidateEventUrls($page['body']), 0, $limit + 5);
            $responses = $this->fetcher->fetchMany($batch, $headers);
            foreach ($batch as $url) {
                $res = $responses[$url] ?? null;
                if ($res === null || $res['status'] !== 200) {
                    continue;
                }
                foreach ($this->jsonLdNodes($res['body']) as $node) {
                    if (is_array($node) && isset($node['startDate'])) {
                        $events[] = $this->parseEventNode($node, $url);
                        break;
                    }
                }
            }
        }

        // Soonest first; keep still-upcoming (end — or start when no end — >=
        // now). Carbon::parse because the dates carry the event's LOCAL offset
        // (e.g. "2026-05-15T18:00:00+1000") — string compare vs UTC is wrong.
        $now = now();
        $this->sortByStartDate($events);
        $upcoming = array_values(array_filter($events, function ($e) use ($now) {
            $compareDate = $e['endDate'] ?? $e['startDate'] ?? null;
            if (empty($compareDate)) {
                return true;
            }

            return Carbon::parse($compareDate)->gte($now);
        }));

        return [
            'organiser' => $organiser,
            'events' => array_slice($upcoming ?: $events, 0, $limit),
        ];
    }

    /** Candidate event-page URLs linked from the host page, deduped. */
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

    /**
     * One schema.org Event node → the stored event shape (same fields as the
     * Eventbrite scraper so the dashboard + sitepage render both identically).
     *
     * @return array{name:?string, venue:?string, location:?string, startDate:?string, endDate:?string, price:?string, availability:?string, image:?string, link:string}
     */
    private function parseEventNode(array $event, string $fallbackUrl): array
    {
        $loc = $event['location'] ?? [];
        // First-entry collapse kept for price string + availability; lowestOffer()
        // scans the RAW list — Humanitix's leading AggregateOffer often carries
        // only priceCurrency, with each ticket tier's `price` on later Offer
        // entries, so the minimum must be taken across ALL of them.
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
        $lowest = $this->lowestOffer($offersRaw);

        return [
            'name' => $event['name'] ?? null,
            'venue' => data_get($loc, 'name') !== null ? trim((string) data_get($loc, 'name')) : null,
            'location' => data_get($loc, 'address.addressLocality') ?? data_get($loc, 'address.addressRegion'),
            'startDate' => $event['startDate'] ?? null,
            'endDate' => $event['endDate'] ?? null,
            // Enrichment (2026-07-17) — mirrors EventbriteScraper::parseEvent field
            // for field (same wire contract, same rationale comments there).
            'description' => $this->sanitizeDescription($event['description'] ?? null),
            'startsAt' => $event['startDate'] ?? null,
            'endsAt' => $event['endDate'] ?? null,
            'price' => $this->formatPrice($offers),
            'priceMin' => $lowest['priceMin'],
            'currency' => $lowest['currency'],
            'availability' => $availability,
            'soldOut' => $availability === 'sold_out',
            'image' => is_string($image) ? $image : null,
            'link' => is_string($event['url'] ?? null) ? $event['url'] : $fallbackUrl,
        ];
    }

    // og:title on the host page reads "<Host> | Humanitix" (or similar suffix).
    private function hostName(string $html): ?string
    {
        $title = $this->metaContent($html, 'og:title');
        if (! is_string($title)) {
            return null;
        }

        return trim(preg_replace('~\s*[|·-]\s*Humanitix\s*$~i', '', $title)) ?: null;
    }
}
