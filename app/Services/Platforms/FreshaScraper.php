<?php

namespace App\Services\Platforms;

use App\Exceptions\Platforms\FreshaEmployeeMenuUnavailableException;
use App\Services\Http\FetchBudget;
use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

// Fresha page + booking-GraphQL scraping, extracted verbatim from
// FreshaController (its own "promotion plan" comment) so the scheduled
// refresh strategy (FreshaFetch) and the HTTP endpoints share one
// implementation. TEST-MODE integration — the real version uses Fresha's
// partner API. Spec: ~/Developer/platform link capabilites/fresha.md
class FreshaScraper
{
    use CasesScannedNames;

    private const SCRAPE_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    // Fresha's internal booking GraphQL — same call the booking page fires to
    // load a single employee's filtered service menu.
    private const GRAPHQL_URL = 'https://www.fresha.com/graphql';

    // Ceiling for the raw booking-GraphQL POST, clamped down by any open
    // FetchBudget (see fetchEmployeeServices()). Deliberately NOT
    // config('partna.http_fetch.timeout_seconds') (8): that key is
    // SafeUrlFetcher's per-HOP ceiling, and adopting it would turn a
    // slow-but-successful employee-menu fetch into a silent fallback to the
    // storewide menu. Named so the no-budget default and the clamp below
    // cannot drift — and because Laravel's own default is 30 s
    // (PendingRequest::$options), so the explicit value is load-bearing.
    private const GRAPHQL_TIMEOUT_SECONDS = 12;

    /**
     * FetchBudget is the SCOPED container binding (AppServiceProvider::register())
     * — resolved, never hand-constructed, so this scraper observes the SAME
     * deadline FreshaController opened. A fresh instance would report null
     * (no budget) and silently defeat the clamp below.
     */
    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly FetchBudget $budget,
    ) {}

    /** Set by fetchLocation() when it had to follow a slug rotation; null otherwise. */
    private ?string $lastResolvedSlug = null;

    /** The slug fetchLocation() ended up on if it differed from the one asked for. */
    public function lastResolvedSlug(): ?string
    {
        return $this->lastResolvedSlug;
    }

    /** Drop the locale segment so we always cache the canonical /a/<slug> form. */
    public function stripLocale(string $url): string
    {
        return preg_replace('#fresha\.com/[a-z]{2,3}(-[a-z]{2})?/a/#i', 'fresha.com/a/', $url) ?? $url;
    }

    /**
     * Rewrite a booking-page URL to the canonical `/a/<slug>` form.
     *
     * Bio links are almost always the share URL Fresha's own app hands out
     * (`/book-now/<slug>/all-offer?share=true&pId=…`), but slugFromUrl() and the
     * connect-input validator both only understand `/a/<slug>`. Canonicalising at
     * WRITE time (resolveWrite) rather than read time is deliberate: GET
     * /platforms/fresha/team re-scrapes from payload.url, so the user's own
     * recovery path needs a usable URL just as much as our auto-fetch does.
     */
    public function canonicalUrl(string $url): string
    {
        return preg_replace(
            '#^(https?://)(?:www\.)?fresha\.com/book-now/([a-z0-9-]+)(?:/[^?\#]*)?.*$#i',
            'https://www.fresha.com/a/$2',
            $url
        ) ?? $url;
    }

    /**
     * Extract the `<slug>` from a Fresha `/a/<slug>` or `/book-now/<slug>/…` URL.
     *
     * The three write paths that pre-date link routing all canonicalise before
     * they store, so this only ever saw `/a/`. The routing lane does not:
     * SourceReconciler and SuggestionApplier write `intent.canonical_url`
     * verbatim, which for a Fresha link-in-bio is the share URL. Reading both
     * shapes here — rather than canonicalising inside the brand-agnostic
     * reconciler — keeps the knowledge of Fresha's URL grammar in the one class
     * that already owns it.
     */
    public function slugFromUrl(string $url): ?string
    {
        // Host-anchored (an unanchored `/a/…` would match inside a foreign
        // query string), optional locale segment (Fresha's own redirects land
        // on `/en-GB/a/<slug>/booking?…`), same alternative as the catalog
        // detector and SourceProvisioner::freshaSlug.
        return preg_match('#^https?://(?:www\.)?fresha\.com/(?:[a-z]{2,3}(?:-[a-z]{2})?/)?(?:a|book-now)/([a-z0-9-]+)#i', $url, $m) ? $m[1] : null;
    }

    /**
     * The slug Fresha CURRENTLY uses for the venue behind $url, or null when
     * that cannot be established without guessing.
     *
     * Fresha rotates a venue's slug (adding the suburb + street when an
     * address lands: `anseo-studio-v0v92jna` → `anseo-studio-melbourne-140a-
     * chapel-street-w8ajp04r`, live 2026-08-18). The old `/a/<slug>` page
     * answers 410 Gone; the old share URL (`/book-now/<slug>/all-offer?…`)
     * still 307s to `/<locale>/a/<new-slug>/booking?…`. So the slug in a link
     * is a claim about the past; the redirect target is the present. One
     * fetch, redirects followed by SafeUrlFetcher, slug read off finalUrl.
     *
     * Only ever a POSITIVE answer: a fetch failure, a non-Fresha landing, or a
     * body-less 4xx/5xx returns null and the caller keeps what it had. This
     * must never turn a transient network blip into a slug change.
     */
    public function resolveCurrentSlug(string $url): ?string
    {
        $given = $this->slugFromUrl($url);
        if ($given === null) {
            return null;
        }

        // Prefer the share form for the probe when we only hold a canonical
        // one: `/a/<old>` is exactly the page that goes 410, whereas Fresha
        // keeps the `book-now` alias redirecting.
        $probe = preg_match('#/book-now/#i', $url) === 1
            ? $url
            : 'https://www.fresha.com/book-now/'.rawurlencode($given).'/all-offer';

        try {
            $response = $this->fetcher->fetch($probe, ['User-Agent' => self::SCRAPE_USER_AGENT]);
        } catch (Throwable) {
            return null;
        }

        if ($response['status'] !== 200) {
            return null;
        }

        return $this->slugFromUrl((string) $response['finalUrl']);
    }

    /**
     * Fetch + parse the Fresha page once, returning both team and services.
     *
     * Services are location-wide (the full menu). For BY-TOPIC salons that
     * matches Fresha's own per-employee booking page closely enough; for
     * BY-EMPLOYEE salons every employee sees the full list (acceptable for
     * test mode — see ~/Developer/platform link capabilites/fresha.md).
     *
     * @return array{storeName:?string, team:list<array<string,mixed>>, services:list<array<string,mixed>>}
     */
    public function fetchMenu(string $url): array
    {
        $location = $this->fetchLocation($url);

        return [
            'storeName' => $this->extractStoreName($location),
            'team' => $this->extractTeam($location),
            'services' => $this->extractServices($location),
            'venue' => $this->extractVenue($location),
        ];
    }

    /**
     * The venue's identity beyond its name (owner, 2026-08-19): what a
     * partna account's Fresha connect hands FreshaWorkplaceLinker so it can
     * find the same place on Google — street, suburb, postcode, coordinates
     * and phone are the corroborating details a name alone can't give.
     * Every key optional; null when Fresha's blob has no address.
     *
     * @return array{name:?string, street:?string, city:?string, postcode:?string, region:?string, country:?string, lat:?float, lng:?float, phone:?string, mapsUrl:?string}|null
     */
    public function extractVenue(array $location): ?array
    {
        $address = data_get($location, 'address');
        if (! is_array($address)) {
            return null;
        }
        $str = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $num = static fn (mixed $v): ?float => is_numeric($v) ? (float) $v : null;

        return [
            'name' => $this->extractStoreName($location),
            'street' => $str($address['streetAddress'] ?? null),
            'city' => $str($address['cityName'] ?? null),
            'postcode' => $str($address['postalCode'] ?? null),
            'region' => $str($address['region1'] ?? null),
            'country' => $str($address['countryCode'] ?? ($location['countryCode'] ?? null)),
            'lat' => $num($address['latitude'] ?? null),
            'lng' => $num($address['longitude'] ?? null),
            'phone' => $str($location['contactNumber'] ?? null),
            'mapsUrl' => $str($address['mapsUrl'] ?? null),
        ];
    }

    /** The salon's display name from the Fresha location blob. */
    public function extractStoreName(array $location): ?string
    {
        $name = $location['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** Fetch the page and return the decoded `location` object from __NEXT_DATA__. */
    public function fetchLocation(string $url): array
    {
        // `/book-now/<slug>/all-offer` is a DIFFERENT Next.js route: its
        // __NEXT_DATA__ carries no `props.pageProps.data.location`, so scraping a
        // stored share URL verbatim aborts 502 or yields an empty menu
        // (→ fresha_no_services). No-op on an already-canonical URL.
        $response = $this->fetcher->fetch($this->canonicalUrl($url), ['User-Agent' => self::SCRAPE_USER_AGENT]);

        // A retired slug (Fresha rotates them — see resolveCurrentSlug) makes
        // the canonical page 404/410 while the venue is perfectly alive one
        // redirect away. Resolve once and retry with what Fresha uses now;
        // the caller learns the new slug via lastResolvedSlug() so it can be
        // persisted rather than re-discovered on every read.
        if (in_array($response['status'], [404, 410], true)) {
            $current = $this->resolveCurrentSlug($url);
            $given = $this->slugFromUrl($url);
            if ($current !== null && $current !== $given) {
                $this->lastResolvedSlug = $current;
                Log::info('fresha.slug_rotated', ['from' => $given, 'to' => $current]);
                $response = $this->fetcher->fetch('https://www.fresha.com/a/'.rawurlencode($current), ['User-Agent' => self::SCRAPE_USER_AGENT]);
            }
        }

        if ($response['status'] !== 200) {
            abort(502, "Fresha returned HTTP {$response['status']}");
        }

        if (! preg_match('#<script id="__NEXT_DATA__"[^>]*>(.+?)</script>#s', $response['body'], $m)) {
            abort(502, 'Fresha page did not contain __NEXT_DATA__ — structure may have changed.');
        }

        $data = json_decode($m[1], true);
        if (! is_array($data)) {
            abort(502, 'Failed to decode __NEXT_DATA__ JSON.');
        }

        $location = data_get($data, 'props.pageProps.data.location', []);

        return is_array($location) ? $location : [];
    }

    /**
     * @return list<array{employeeId:string, displayName:string, jobTitle:?string, avatarUrl:?string, rating:?float}>
     */
    public function extractTeam(array $location): array
    {
        $edges = data_get($location, 'employeeProfiles.edges', []);
        if (! is_array($edges)) {
            return [];
        }

        return array_values(array_map(static function (array $edge): array {
            $node = $edge['node'] ?? [];

            return [
                'employeeId' => (string) ($node['employeeId'] ?? ''),
                'displayName' => (string) ($node['displayName'] ?? ''),
                'jobTitle' => $node['jobTitle'] ?? null,
                'avatarUrl' => data_get($node, 'avatar.url'),
                'rating' => isset($node['rating']) ? (float) $node['rating'] : null,
            ];
        }, $edges));
    }

    /**
     * Flatten Fresha's category → items tree into one service list. Only real
     * services (id prefixed `s:`) are kept; the `s:` id is what the booking
     * deep link's offerItemId needs.
     *
     * @return list<array{serviceId:string, name:string, duration:?string, description:?string, price:?string, priceValue:mixed, currency:mixed, category:?string, hasVariants:bool}>
     */
    public function extractServices(array $location): array
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

                $variants = $item['variants'] ?? null;

                $out[] = [
                    'serviceId' => $id,
                    // B5/3a: vendors type service names ALL-CAPS; re-case at
                    // scrape time (uniform-case guard — mixed case untouched).
                    'name' => (string) ($this->scanTitleCase((string) ($item['name'] ?? '')) ?? ''),
                    'duration' => $item['caption'] ?? null,
                    'description' => $item['description'] ?? null,
                    'price' => $item['formattedRetailPrice'] ?? null,
                    'priceValue' => data_get($item, 'retailPrice.value'),
                    'currency' => data_get($item, 'retailPrice.currency'),
                    'category' => $categoryName,
                    'hasVariants' => is_array($variants) && count($variants) > 1,
                ];
            }
        }

        return $out;
    }

    /**
     * Fetch one employee's bookable services via Fresha's booking-flow GraphQL
     * (the same call the booking page fires). Returns null if the call fails so
     * callers fall back to the whole-location menu.
     *
     * The service id we extract is the `catalogId` embedded in each item's
     * action — verified equal to the location-page service id, i.e. the
     * `offerItemId` the booking deep link needs.
     *
     * Persisted-query hash + client version are pinned to a Fresha frontend
     * build and rotate when they redeploy; override FRESHA_BOOKING_INIT_HASH /
     * FRESHA_CLIENT_VERSION in env to update without a code deploy.
     *
     * @return list<array<string,mixed>>|null
     */
    public function fetchEmployeeServices(string $slug, string $employeeId, bool $reportFailure = true): ?array
    {
        // R8: this POST deliberately bypasses SafeUrlFetcher — GRAPHQL_URL is a
        // hardcoded constant, never user input, so there is no SSRF surface to
        // guard (same reasoning as YoutubeThumbnailResolver's raw pool; see
        // FetchBudget's docblock). The cost was invisibility: saveSelection()'s
        // worst case was the 20 s budget PLUS this leg's flat 12 s. Consult the
        // shared budget directly instead of wrapping this in a second open() —
        // nesting fails OPEN, clearing the OUTER deadline in its finally.
        //
        // remaining() === null means NO budget is open — the scheduled/on-demand
        // refresh path (RefreshConnectionJob -> PlatformRefresher ->
        // ScheduledRefresh -> FreshaFetch) never opens one. That is "unbounded",
        // NOT "out of time": it must keep the flat ceiling, or every BY-EMPLOYEE
        // salon degrades to the storewide menu on every refresh, forever, and
        // silently — the fallback is by design invisible.
        $remaining = $this->budget->remaining();
        if ($remaining !== null && $remaining <= 0) {
            // Our own deadline, not a vendor miss: logged like
            // SafeUrlFetcher::pooledGet()'s budget_exhausted line, deliberately
            // NOT report()ed, so an over-budget connect cannot page Nightwatch
            // as a rotated BOOKING_INIT_HASH. Callers already read null as
            // "fall back to the whole-location menu".
            Log::warning('fresha.employee_services.failed', [
                'reason' => 'budget_exhausted',
                'slug' => $slug,
                'employee_id' => $employeeId,
                'remaining_seconds' => $remaining,
            ]);

            return null;
        }

        $timeout = $remaining === null
            ? self::GRAPHQL_TIMEOUT_SECONDS
            : (int) max(1, ceil(min(self::GRAPHQL_TIMEOUT_SECONDS, $remaining)));

        $clientVersion = config('services.fresha.client_version');

        $payload = [
            'operationName' => 'BookingFlow_Initialize_Mutation',
            'variables' => [
                'fullUpfrontPaymentEnabled' => true,
                'discountsAndBenefitsEnabled' => false,
                'input' => [
                    'locationSlug' => $slug,
                    'referer' => '',
                    'options' => [
                        'employeeId' => $employeeId,
                        'shouldShowAllEmployees' => false,
                        'isGroupBooking' => false,
                        'isRebook' => false,
                        'isFromLinkBuilder' => false,
                        'clientChannelType' => 'MARKETPLACE',
                        'cartId' => null,
                        'offerItemId' => null,
                        'offerItems' => null,
                    ],
                    'shouldAutoContinue' => true,
                    'capabilities' => ['SERVICE_ADDONS', 'CONFIRMATION', 'FULL_UPFRONT_PAYMENT', 'MARKETPLACE_REFRESH'],
                ],
            ],
            'extensions' => [
                'persistedQuery' => ['version' => 1, 'sha256Hash' => config('services.fresha.booking_init_hash')],
                'platform' => 'web',
                'version' => $clientVersion,
            ],
        ];

        try {
            $response = Http::withHeaders([
                'content-type' => 'application/json',
                'x-client-platform' => 'web',
                'x-client-version' => $clientVersion,
                'x-graphql-operation-name' => 'mutation BookingFlow_Initialize_Mutation',
                'origin' => 'https://www.fresha.com',
                'User-Agent' => self::SCRAPE_USER_AGENT,
            ])->timeout($timeout)->post(self::GRAPHQL_URL, $payload);
        } catch (Throwable $e) {
            // Surface silent failures so a rotated BOOKING_INIT_HASH/client version
            // is visible in Nightwatch instead of silently degrading to the
            // whole-location menu (the documented rotation inevitability).
            Log::warning('fresha.employee_services.failed', [
                'reason' => 'exception',
                'slug' => $slug,
                'employee_id' => $employeeId,
                'error' => $e->getMessage(),
                'timeout_seconds' => $timeout,
            ]);
            report(new FreshaEmployeeMenuUnavailableException($slug, $employeeId, 'exception', previous: $e));

            return null;
        }

        if (! $response->ok()) {
            Log::warning('fresha.employee_services.failed', [
                'reason' => 'http_error',
                'slug' => $slug,
                'employee_id' => $employeeId,
                'status' => $response->status(),
            ]);
            // T3 (2026-08-27): the auto-selector's FIRST attempt runs at the
            // stored slug, which Fresha rotates — it suppresses this report
            // and retries at the re-resolved slug, reporting only a failure
            // of THAT attempt. The dashboard picker path keeps the default.
            if ($reportFailure) {
                report(new FreshaEmployeeMenuUnavailableException($slug, $employeeId, 'http_error', status: $response->status()));
            }

            return null;
        }

        $categories = data_get($response->json(), 'data.bookingFlowInitialize.screenServices.categories');
        if (! is_array($categories)) {
            // Missing categories on a 2xx is the classic hash/version-rotation symptom.
            Log::warning('fresha.employee_services.failed', [
                'reason' => 'no_categories',
                'slug' => $slug,
                'employee_id' => $employeeId,
            ]);
            if ($reportFailure) {
                report(new FreshaEmployeeMenuUnavailableException($slug, $employeeId, 'no_categories'));
            }

            return null;
        }

        $out = [];
        foreach ($categories as $category) {
            $categoryName = $category['name'] ?? null;
            foreach (($category['items'] ?? []) as $item) {
                $actionId = (string) (data_get($item, 'primaryAction.id') ?? data_get($item, 'secondaryAction.id') ?? '');
                if (! preg_match('/"catalogId":"(s:\d+)"/', $actionId, $m)) {
                    continue;
                }

                $out[] = [
                    'serviceId' => $m[1],
                    'name' => (string) ($this->scanTitleCase(trim((string) ($item['name'] ?? ''))) ?? ''),
                    'duration' => $item['caption'] ?? null,
                    'description' => $item['description'] ?? null,
                    'price' => data_get($item, 'price.formatted'),
                    'priceValue' => null,
                    'currency' => null,
                    'category' => $categoryName,
                    'hasVariants' => preg_match_all('/"id":"sv:\d+"/', $actionId) > 1,
                ];
            }
        }

        return $out;
    }
}
