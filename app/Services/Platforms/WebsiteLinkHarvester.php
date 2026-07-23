<?php

namespace App\Services\Platforms;

use App\Services\Http\SafeUrlFetcher;

// In-house replacement for the slice of the Google Business Apify enrichment
// that never needed Google at all: businesses put their own social /
// reservation / ordering / booking links on their own website, and Place
// Details already hands us that website URL. One SSRF-guarded fetch of the
// homepage + anchor classification recovers those links instantly and free —
// the paid Apify run stays only for what genuinely lives on the Maps listing
// (menu action link, google reserve/food URLs), see GoogleBusinessEnrichJob's
// needsApify rule.
//
// Output is shaped EXACTLY like GoogleBusinessApifyScraper::map()'s subset so
// GoogleBusinessAutoSync::seed() consumes either source unchanged:
//   { reservation: {url, links:[{url}]}, order: {providers:[{name,url}]},
//     booking: [url,…], socials: {instagram,facebook,…} }
// Keys are present only when something was found.
class WebsiteLinkHarvester
{
    /** Host-pattern → socials key. First match per key wins (homepage order). */
    private const SOCIAL_HOSTS = [
        'instagram' => '~(^|\.)instagram\.com$~',
        'facebook' => '~(^|\.)facebook\.com$~',
        'tiktok' => '~(^|\.)tiktok\.com$~',
        'twitter' => '~(^|\.)(twitter\.com|x\.com)$~',
        'linkedin' => '~(^|\.)linkedin\.com$~',
        'youtube' => '~(^|\.)(youtube\.com|youtu\.be)$~',
        'pinterest' => '~(^|\.)pinterest\.[a-z.]+$~',
    ];

    /**
     * Reservation provider hosts, keyed by label — the seeder's own provider
     * services re-validate. Kept per-provider (not one joined regex) so
     * classify() can report WHICH provider matched, not just "reservation-y".
     */
    private const RESERVATION_HOSTS = [
        'OpenTable' => '~(^|\.)opentable\.[a-z.]+$~',
        'ResDiary' => '~(^|\.)resdiary\.com$~',
        'NowBookit' => '~(^|\.)nowbookit\.com$~',
    ];

    /** Label => platform slug for RESERVATION_HOSTS, used only by classify(). */
    private const RESERVATION_PLATFORM = ['OpenTable' => 'opentable', 'ResDiary' => 'resdiary', 'NowBookit' => 'nowbookit'];

    /** Online-ordering provider hosts (AU market set). */
    private const ORDERING_HOSTS = [
        'Uber Eats' => '~(^|\.)ubereats\.com$~',
        'DoorDash' => '~(^|\.)doordash\.com$~',
        'Menulog' => '~(^|\.)menulog\.com\.au$~',
        'Deliveroo' => '~(^|\.)deliveroo\.[a-z.]+$~',
        'Order Online' => '~(^|\.)order\.online$~',
        'OrderMate' => '~(^|\.)ordermate\.online$~',
    ];

    /** Booking provider hosts (Fresha / Square), keyed by label — see RESERVATION_HOSTS note. */
    private const BOOKING_HOSTS = [
        'Fresha' => '~(^|\.)fresha\.com$~',
        'Square' => '~(^|\.)(squareup\.com|square\.site)$~',
    ];

    /** Label => platform slug for BOOKING_HOSTS, used only by classify(). */
    private const BOOKING_PLATFORM = ['Fresha' => 'fresha', 'Square' => 'square'];

    /**
     * Decisive store hosts (signup-v2 C1) — a URL on these IS a storefront, no
     * probe needed. Generic store detection (a business's own domain) is the
     * probe job's business, never classify()'s. squareup.com/square.site stays
     * classified 'booking' above — Square Online stores share those hosts and
     * a host pattern can't disambiguate; flipping it would regress booking.
     */
    private const SHOP_HOSTS = [
        'Shopify' => '~(^|\.)myshopify\.com$~',
        'Big Cartel' => '~(^|\.)bigcartel\.com$~',
    ];

    /**
     * SOCIAL_HOSTS key => [platform slug, display label], used only by classify().
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SOCIAL_PLATFORM = [
        'instagram' => ['instagram', 'Instagram'],
        'facebook' => ['facebook', 'Facebook'],
        'tiktok' => ['tiktok', 'TikTok'],
        'twitter' => ['x', 'X'],
        'linkedin' => ['linkedin', 'LinkedIn'],
        'youtube' => ['youtube', 'YouTube'],
        'pinterest' => ['pinterest', 'Pinterest'],
    ];

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    // Lazily built from our own fetcher (both scrapers take exactly a
    // SafeUrlFetcher) so classify() gains event patterns without changing this
    // class's construction contract — the normalize methods used here are pure
    // regex and never actually fetch.
    private ?EventbriteScraper $eventbriteScraper = null;

    private ?HumanitixScraper $humanitixScraper = null;

    private function eventbrite(): EventbriteScraper
    {
        return $this->eventbriteScraper ??= new EventbriteScraper($this->fetcher);
    }

    private function humanitix(): HumanitixScraper
    {
        return $this->humanitixScraper ??= new HumanitixScraper($this->fetcher);
    }

    /**
     * @return array<string, mixed> enrichment-shaped subset; [] when the site
     *                              is missing, unreachable, or linkless.
     */
    public function harvest(?string $websiteUrl): array
    {
        if (! is_string($websiteUrl) || ! preg_match('~^https?://~i', trim($websiteUrl))) {
            return [];
        }
        $websiteUrl = trim($websiteUrl);

        $response = $this->fetcher->tryFetch($websiteUrl);
        $html = is_array($response) && ($response['status'] ?? 0) === 200
            ? (string) ($response['body'] ?? '')
            : '';
        if ($html === '' || strlen($html) > 3_000_000) {
            return [];
        }

        return $this->harvestHtml($html, $response['finalUrl'] ?? $websiteUrl);
    }

    /**
     * Same classification harvest() does, off an already-fetched HTML string —
     * lets a caller that already has the page in hand (previous-website scan,
     * link-in-bio scan) reuse this class's classification without a second fetch.
     *
     * @return array<string, mixed> enrichment-shaped subset; [] when linkless.
     */
    public function harvestHtml(string $html, string $baseUrl): array
    {
        $links = $this->extractLinks($html, $baseUrl);
        if ($links === []) {
            return [];
        }

        $socials = [];
        $reservationLinks = [];
        $orderProviders = [];
        $booking = [];

        foreach ($links as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host === '') {
                continue;
            }

            foreach (self::SOCIAL_HOSTS as $key => $pattern) {
                if (! isset($socials[$key]) && preg_match($pattern, $host) && $this->looksLikeProfile($key, $url)) {
                    $socials[$key] = $url;

                    continue 2;
                }
            }

            if ($this->matchesAnyHost(self::RESERVATION_HOSTS, $host)) {
                $reservationLinks[] = ['url' => $url];

                continue;
            }

            foreach (self::ORDERING_HOSTS as $name => $pattern) {
                if (preg_match($pattern, $host)) {
                    $orderProviders[] = ['name' => $name, 'url' => $url];

                    continue 2;
                }
            }

            if ($this->matchesAnyHost(self::BOOKING_HOSTS, $host)) {
                $booking[] = $url;
            }
        }

        // (Outcome logging lives in GoogleBusinessEnrichJob, which knows
        // whether the harvest replaced or merely complemented the Apify run.)
        return array_filter([
            'reservation' => $reservationLinks !== []
                ? ['url' => $reservationLinks[0]['url'], 'links' => $reservationLinks]
                : null,
            'order' => $orderProviders !== [] ? ['providers' => $orderProviders] : null,
            'booking' => $booking !== [] ? $booking : null,
            'socials' => $socials !== [] ? $socials : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Every absolute, deduped, http(s)-only outbound link on the page — not
     * just the ones classify()-able into a known platform. Used by the
     * link-in-bio scan and previous-website general link-harvest, which need
     * to hand EVERY link to classify()/CustomLinkSeeder themselves rather than
     * only the pre-bucketed subset harvestHtml() returns.
     *
     * @return list<string>
     */
    public function allOutboundLinks(string $html, string $baseUrl): array
    {
        return $this->extractLinks($html, $baseUrl);
    }

    /**
     * Classify a single URL by host into {platform, category, label}, or null
     * when it matches none of this class's known host patterns. Reuses the SAME
     * SOCIAL_HOSTS / RESERVATION_HOSTS / ORDERING_HOSTS / BOOKING_HOSTS constants
     * harvest() classifies a scraped homepage's anchors with — the one
     * host→platform mapping in the codebase (BE2: InstagramAutoSync classifies
     * Instagram bio links through this, instead of a second table). Category
     * values match GoogleBusinessAutoSync's finding categories
     * ('social'/'booking'/'reservations'/'online-ordering') so a consumer can
     * treat either source's findings identically.
     *
     * @return array{platform:string, category:string, label:string}|null
     */
    public function classify(string $url): ?array
    {
        $url = trim($url);
        if (! preg_match('~^https?://~i', $url)) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }

        foreach (self::SOCIAL_HOSTS as $key => $pattern) {
            // No isset() guard needed: SOCIAL_PLATFORM is hand-maintained with the
            // exact same 7 keys as SOCIAL_HOSTS, so the lookup below can never
            // miss for a $key drawn from this loop.
            if (preg_match($pattern, $host) && $this->looksLikeProfile($key, $url)) {
                [$platform, $label] = self::SOCIAL_PLATFORM[$key];

                return ['platform' => $platform, 'category' => 'social', 'label' => $label];
            }
        }

        foreach (self::BOOKING_HOSTS as $label => $pattern) {
            if (preg_match($pattern, $host)) {
                return ['platform' => self::BOOKING_PLATFORM[$label], 'category' => 'booking', 'label' => $label];
            }
        }

        foreach (self::RESERVATION_HOSTS as $label => $pattern) {
            if (preg_match($pattern, $host)) {
                return ['platform' => self::RESERVATION_PLATFORM[$label], 'category' => 'reservations', 'label' => $label];
            }
        }

        foreach (self::ORDERING_HOSTS as $label => $pattern) {
            if (preg_match($pattern, $host)) {
                return ['platform' => 'online-ordering', 'category' => 'online-ordering', 'label' => $label];
            }
        }

        // Events (signup-v2 C1): organiser pages vs single events. Pattern
        // authority stays with each scraper's own pure-regex normalizers so
        // classify() can never drift from what the connect flow accepts —
        // HumanitixScraper::resolveHostUrl() is deliberately NOT used (its
        // event-URL branch fetches). Humanitix org runs BEFORE event: the two
        // shapes share a host and only '/host/' discriminates.
        if (preg_match('~(^|\.)eventbrite\.[a-z.]+$~', $host)) {
            if ($this->eventbrite()->normalizeOrgUrl($url) !== null) {
                return ['platform' => 'eventbrite', 'category' => 'event-organiser', 'label' => 'Eventbrite'];
            }
            if ($this->eventbrite()->normalizeEventUrl($url) !== null) {
                return ['platform' => 'eventbrite', 'category' => 'event', 'label' => 'Eventbrite'];
            }
        }
        if (preg_match('~(^|\.)humanitix\.com$~', $host)) {
            if (preg_match('~^https?://(?:events\.)?humanitix\.com/host/[a-z0-9-]+~i', $url)) {
                return ['platform' => 'humanitix', 'category' => 'event-organiser', 'label' => 'Humanitix'];
            }
            if ($this->humanitix()->normalizeEventUrl($url) !== null) {
                return ['platform' => 'humanitix', 'category' => 'event', 'label' => 'Humanitix'];
            }
        }

        foreach (self::SHOP_HOSTS as $label => $pattern) {
            if (preg_match($pattern, $host)) {
                return ['platform' => 'shop', 'category' => 'shop', 'label' => $label];
            }
        }

        return null;
    }

    /** Whether $host matches ANY pattern in a label => regex map. */
    private function matchesAnyHost(array $hostMap, string $host): bool
    {
        foreach ($hostMap as $pattern) {
            if (preg_match($pattern, $host)) {
                return true;
            }
        }

        return false;
    }

    /** Absolute, deduped, http(s)-only hrefs from the page (≤500 to bound work). */
    private function extractLinks(string $html, string $baseUrl): array
    {
        $doc = new \DOMDocument;
        // Suppress libxml warnings for real-world HTML.
        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $loaded) {
            return [];
        }

        $seen = [];
        foreach ($doc->getElementsByTagName('a') as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#')) {
                continue;
            }
            $abs = $this->absolutize($href, $baseUrl);
            if ($abs !== null && ! isset($seen[$abs])) {
                $seen[$abs] = true;
                if (count($seen) >= 500) {
                    break;
                }
            }
        }

        return array_keys($seen);
    }

    /** Resolve relative hrefs against the page URL; null for non-http(s) schemes. */
    private function absolutize(string $href, string $base): ?string
    {
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $href)) {
            return null; // mailto:, tel:, javascript:, data:…
        }

        $parts = parse_url($base);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($href, '//')) {
            return $parts['scheme'].':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        $dir = isset($parts['path']) ? rtrim(dirname($parts['path']), '/') : '';

        return $origin.$dir.'/'.$href;
    }

    /**
     * A profile link, not a share/intent widget ("facebook.com/sharer",
     * "twitter.com/intent") — the classic false positives on business sites.
     */
    private function looksLikeProfile(string $key, string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        if (in_array($key, ['facebook', 'twitter'], true)
            && preg_match('~^/(sharer|share|intent|dialog)~', $path)) {
            return false;
        }

        // Bare-domain links (e.g. "https://instagram.com") carry no profile.
        return $path !== '' && $path !== '/';
    }
}
