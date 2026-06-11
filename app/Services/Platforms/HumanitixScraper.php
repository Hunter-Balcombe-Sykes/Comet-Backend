<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;
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
            if ($res['status'] === 200
                && preg_match('~href="(?:https://events\.humanitix\.com)?/host/([a-z0-9-]+)~i', $res['body'], $h)) {
                return 'https://events.humanitix.com/host/'.strtolower($h[1]);
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
        usort($events, function ($a, $b) {
            $aDate = $a['startDate'] ?? '';
            $bDate = $b['startDate'] ?? '';
            if ($aDate === '' && $bDate === '') {
                return 0;
            }
            if ($aDate === '') {
                return -1;
            }
            if ($bDate === '') {
                return 1;
            }

            return Carbon::parse($aDate)->getTimestamp() <=> Carbon::parse($bDate)->getTimestamp();
        });
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

    // ── internals ────────────────────────────────────────────────

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
        $offers = $event['offers'] ?? [];
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

        return [
            'name' => $event['name'] ?? null,
            'venue' => data_get($loc, 'name') !== null ? trim((string) data_get($loc, 'name')) : null,
            'location' => data_get($loc, 'address.addressLocality') ?? data_get($loc, 'address.addressRegion'),
            'startDate' => $event['startDate'] ?? null,
            'endDate' => $event['endDate'] ?? null,
            'price' => $this->formatPrice($offers),
            'availability' => $this->normalizeAvailability(data_get($offers, 'availability')),
            'image' => is_string($image) ? $image : null,
            'link' => is_string($event['url'] ?? null) ? $event['url'] : $fallbackUrl,
        ];
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
