<?php

namespace App\Services\Platforms;

use App\Services\Http\SafeUrlFetcher;

// Generic event-page reader (corrected 2026-09-04 overnight run, §1F — the
// text below used to claim a standalone "any venue's own site" capability
// that T3 removed). read() itself is still host-agnostic — it JSON-LD-
// parses whatever page it's given and does not branch on the host — but
// every call site now reaches it only for a URL a KNOWN events platform
// already claims: PoolItemCreateController's events-pool item-creation path
// calls it only after PastedLinkClassifier::claims() recognises the URL for
// 'events' (T3, owner, 2026-08-20), and EventsSeeder calls it only for its
// GENERIC_EVENT_PLATFORMS roster (Luma, Partiful, Ticketmaster, Ticketek,
// Oztix, TryBooking, Resident Advisor, Meetup). An arbitrary unrecognised
// host — a venue's own site with no platform behind it — now falls to the
// plain card path upstream and never reaches this class at all. Eventbrite/
// Humanitix URLs still delegate to their own scrapers so their
// canonicalisation quirks (regional-host rewrite, slug chrome filters,
// event-node selection) stay authoritative in exactly one place each.
class EventPageReader extends PlatformScraper
{
    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly EventbriteScraper $eventbrite,
        private readonly HumanitixScraper $humanitix,
    ) {}

    /**
     * Read one event page. `canonical` is the URL the item should be keyed on
     * (bespoke normalizers for the two connect platforms; otherwise the pasted
     * URL minus fragment — ManualEventWriter::coordFor dedupes on the string).
     *
     * @return array{canonical: string, event: array<string,mixed>}|null
     */
    public function read(string $url): ?array
    {
        if (($eb = $this->eventbrite->normalizeEventUrl($url)) !== null) {
            $event = $this->eventbrite->fetchSingleEvent($eb);

            return $event === null ? null : ['canonical' => $eb, 'event' => $event];
        }

        if (($hx = $this->humanitix->normalizeEventUrl($url)) !== null) {
            $event = $this->humanitix->fetchSingleEvent($hx);

            return $event === null ? null : ['canonical' => $hx, 'event' => $event];
        }

        $canonical = trim((string) preg_replace('~#.*$~', '', trim($url)));
        if ($canonical === '') {
            return null;
        }

        $res = $this->fetcher->tryFetch($canonical, ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }

        foreach ($this->jsonLdNodes($res['body']) as $node) {
            if (is_array($node) && isset($node['startDate']) && $this->isEventNode($node)) {
                return ['canonical' => $canonical, 'event' => $this->schemaOrgEventNode($node, $canonical)];
            }
        }

        return null;
    }

    /**
     * The platform label when the URL is an ORGANISER page of a connectable
     * events platform — the caller should send the owner to the connect flow
     * rather than storing an "event" that is really an account. Pure regex,
     * no fetch (HumanitixScraper::resolveHostUrl's event arm fetches, so it
     * is deliberately NOT used here — same reasoning as WLH::classify).
     */
    public function organiserPlatformLabel(string $url): ?string
    {
        if ($this->eventbrite->normalizeOrgUrl($url) !== null) {
            return 'Eventbrite';
        }
        if (preg_match('~^https?://(?:events\.)?humanitix\.com/host/[a-z0-9-]+~i', PlatformInput::urlish($url))) {
            return 'Humanitix';
        }

        return null;
    }

    /**
     * Eventbrite's own rule is "the node with a startDate" because Eventbrite
     * only emits Event subtypes. A GENERIC page needs the extra guard: when
     * the node declares a @type, it must name an Event type (SocialEvent,
     * MusicEvent, Festival…) — a stray startDate on non-event JSON-LD must
     * not become a ticket card. A node with no @type keeps the lenient rule.
     */
    private function isEventNode(array $node): bool
    {
        $type = $node['@type'] ?? null;
        if ($type === null) {
            return true;
        }
        foreach (is_array($type) ? $type : [$type] as $t) {
            if (is_string($t) && stripos($t, 'event') !== false) {
                return true;
            }
        }

        return false;
    }
}
