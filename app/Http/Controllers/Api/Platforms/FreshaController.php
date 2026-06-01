<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Services\SmartLinks\SafeUrlFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

// Test-mode endpoints for the Fresha integration. Saves a Fresha store URL
// globally (single-tenant test cache, no auth) and returns the staff list
// extracted from the page's __NEXT_DATA__ blob.
//
// Approach proven and documented in:
//   ~/Developer/platform link capabilites/fresha.md
//
// Promotion plan: when the test is done, extract scrape logic to
// App\Services\Platforms\FreshaScraper, persist via a platform_connections
// table per user, and wire to /account/platforms in Partna-Frontend.
class FreshaController extends ApiController
{
    private const CACHE_KEY = 'platforms.fresha.url';

    // The saved selection: which team member + their services. This is the blob
    // partna-pages will read back to render the booking section.
    private const SELECTION_CACHE_KEY = 'platforms.fresha.selection';

    private const CACHE_TTL_DAYS = 30;

    // Matches both locale-prefixed and bare slug URLs:
    //   https://www.fresha.com/a/<slug>
    //   https://www.fresha.com/en-GB/a/<slug>
    private const URL_PATTERN = '#^https?://(www\.)?fresha\.com/(?:[a-z]{2,3}(-[a-z]{2})?/)?a/[a-z0-9-]+/?$#i';

    private const SCRAPE_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    // Fresha's internal booking GraphQL — same call the booking page fires to
    // load a single employee's filtered service menu.
    private const GRAPHQL_URL = 'https://www.fresha.com/graphql';

    // Persisted-query hash + client version are pinned to a Fresha frontend
    // build and rotate when they redeploy. When they do, fetchEmployeeServices
    // returns null and callers fall back to the whole-location menu until these
    // are re-captured. (Test-mode tradeoff; the real version uses Fresha's
    // partner API.)
    private const BOOKING_INIT_HASH = '4ea9d1b31075d62f789fcec884c45d76aaeb42e56ffb1b78cc1b7f7c557ad7cb';

    private const FRESHA_CLIENT_VERSION = 'd135e4b3a3be51f9dd24f5cc2af6dd6a647f85dd';

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    // POST /api/platforms/fresha/connect
    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:500', 'regex:'.self::URL_PATTERN],
        ]);

        $url = $this->stripLocale($validated['url']);
        Cache::put(self::CACHE_KEY, $url, now()->addDays(self::CACHE_TTL_DAYS));

        return $this->success(['url' => $url, ...$this->fetchMenu($url)]);
    }

    // GET /api/platforms/fresha/team — team + services for the saved URL.
    public function team(): JsonResponse
    {
        $url = Cache::get(self::CACHE_KEY);
        if (! $url) {
            return $this->error('No Fresha URL connected yet. POST one to /connect first.', 404);
        }

        return $this->success(['url' => $url, ...$this->fetchMenu($url)]);
    }

    // GET /api/platforms/fresha/url — peek at what's saved without re-scraping.
    public function show(): JsonResponse
    {
        return $this->success(['url' => Cache::get(self::CACHE_KEY)]);
    }

    // POST /api/platforms/fresha/selection — save which team member is "you"
    // plus the current service menu. Re-scrapes the saved URL so the stored
    // blob is server-authoritative (not whatever the client happened to hold).
    public function saveSelection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employeeId' => ['required', 'string', 'max:50'],
        ]);

        $url = Cache::get(self::CACHE_KEY);
        if (! $url) {
            return $this->error('No Fresha URL saved yet. Save one first.', 404);
        }

        $location = $this->fetchLocation($url);
        $employee = collect($this->extractTeam($location))->firstWhere('employeeId', $validated['employeeId']);
        if (! $employee) {
            return $this->error('That team member was not found on the saved Fresha page.', 404);
        }

        // Per-employee services via the booking GraphQL; fall back to the whole
        // location menu if that call fails (hash/version rotated).
        $slug = $this->slugFromUrl($url);
        $services = ($slug ? $this->fetchEmployeeServices($slug, $validated['employeeId']) : null)
            ?? $this->extractServices($location);

        $selection = [
            'url' => $url,
            'storeName' => $this->extractStoreName($location),
            'employee' => $employee,
            'services' => $services,
        ];
        Cache::put(self::SELECTION_CACHE_KEY, $selection, now()->addDays(self::CACHE_TTL_DAYS));

        return $this->success($selection);
    }

    // GET /api/platforms/fresha/employee-services?employeeId=X — the per-employee
    // menu for the dashboard preview (before saving). Same fallback as above.
    public function employeeServices(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employeeId' => ['required', 'string', 'max:50'],
        ]);

        $url = Cache::get(self::CACHE_KEY);
        if (! $url) {
            return $this->error('No Fresha URL saved yet. Save one first.', 404);
        }

        $slug = $this->slugFromUrl($url);
        $services = ($slug ? $this->fetchEmployeeServices($slug, $validated['employeeId']) : null)
            ?? $this->extractServices($this->fetchLocation($url));

        return $this->success(['services' => $services]);
    }

    // GET /api/platforms/fresha/selection — read the saved selection (partna-pages
    // reads this; the dashboard reads it to restore its "saved" state on load).
    public function selection(): JsonResponse
    {
        return $this->success(['selection' => Cache::get(self::SELECTION_CACHE_KEY)]);
    }

    // DELETE /api/platforms/fresha — clear the saved URL and selection.
    public function forget(): JsonResponse
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::SELECTION_CACHE_KEY);

        return $this->success(['url' => null, 'selection' => null]);
    }

    // ── internals ────────────────────────────────────────────────

    // Drop the locale segment so we always cache the canonical /a/<slug> form.
    private function stripLocale(string $url): string
    {
        return preg_replace('#fresha\.com/[a-z]{2,3}(-[a-z]{2})?/a/#i', 'fresha.com/a/', $url) ?? $url;
    }

    /** Extract the `<slug>` from a Fresha `.../a/<slug>` URL. */
    private function slugFromUrl(string $url): ?string
    {
        return preg_match('#/a/([a-z0-9-]+)#i', $url, $m) ? $m[1] : null;
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
     * @return list<array<string,mixed>>|null
     */
    private function fetchEmployeeServices(string $slug, string $employeeId): ?array
    {
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
                'persistedQuery' => ['version' => 1, 'sha256Hash' => self::BOOKING_INIT_HASH],
                'platform' => 'web',
                'version' => self::FRESHA_CLIENT_VERSION,
            ],
        ];

        try {
            $response = Http::withHeaders([
                'content-type' => 'application/json',
                'x-client-platform' => 'web',
                'x-client-version' => self::FRESHA_CLIENT_VERSION,
                'x-graphql-operation-name' => 'mutation BookingFlow_Initialize_Mutation',
                'origin' => 'https://www.fresha.com',
                'User-Agent' => self::SCRAPE_USER_AGENT,
            ])->timeout(12)->post(self::GRAPHQL_URL, $payload);
        } catch (Throwable) {
            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $categories = data_get($response->json(), 'data.bookingFlowInitialize.screenServices.categories');
        if (! is_array($categories)) {
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
                    'name' => trim((string) ($item['name'] ?? '')),
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
    private function fetchMenu(string $url): array
    {
        $location = $this->fetchLocation($url);

        return [
            'storeName' => $this->extractStoreName($location),
            'team' => $this->extractTeam($location),
            'services' => $this->extractServices($location),
        ];
    }

    /** The salon's display name from the Fresha location blob. */
    private function extractStoreName(array $location): ?string
    {
        $name = $location['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** Fetch the page and return the decoded `location` object from __NEXT_DATA__. */
    private function fetchLocation(string $url): array
    {
        $response = $this->fetcher->fetch($url, ['User-Agent' => self::SCRAPE_USER_AGENT]);

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
    private function extractTeam(array $location): array
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

                $variants = $item['variants'] ?? null;

                $out[] = [
                    'serviceId' => $id,
                    'name' => (string) ($item['name'] ?? ''),
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
}
