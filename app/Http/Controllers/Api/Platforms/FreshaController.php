<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\FreshaEmployeeServicesRequest;
use App\Http\Requests\Platforms\PlatformConnectRequest;
use App\Http\Requests\Platforms\SaveFreshaSelectionRequest;
use App\Http\Requests\Platforms\SetFreshaServiceVisibilityRequest;
use App\Http\Resources\Platforms\FreshaSelectionResource;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\Payloads\SelectionPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

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
    // are re-captured. Override FRESHA_BOOKING_INIT_HASH / FRESHA_CLIENT_VERSION
    // in env to update without a code deploy. (Test-mode tradeoff; the real
    // version uses Fresha's partner API.)

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    protected function platform(): string
    {
        return 'fresha';
    }

    // The per-user Fresha connection payload is { url, selection } — the connected
    // store URL plus the saved { storeName, employee, services } blob (or null).
    private function freshaUrl(User $user): ?string
    {
        return SelectionPayload::fromArray($this->readConnection($user) ?? [])->url;
    }

    // POST /api/platforms/fresha/connect
    public function connect(PlatformConnectRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // Fresha + Square are mutually exclusive booking providers (XOR).
        if ($this->hasConflictingConnection($user, 'square')) {
            return $this->error('Disconnect Square before connecting Fresha — only one booking provider can be active at a time.', 409);
        }

        $validated = $request->validated();

        $url = $this->stripLocale($validated['url']);
        $menu = $this->fetchMenu($url);

        // Business Partna accounts book storewide — no team-member picker. Finalise
        // the selection here so connect() completes setup in one step; the dashboard
        // sees mode='storewide' and skips the picker. Capability-gated so the
        // account_type read stays inside AccountCapabilities.
        if (AccountCapabilities::for($user)->can_book_storewide) {
            $selection = [
                'url' => $url,
                'storeName' => $menu['storeName'],
                'mode' => 'storewide',
                'employee' => null,
                'services' => $menu['services'],
                'hiddenServiceIds' => [],
            ];
            $this->writeConnection($user, ['url' => $url, 'selection' => $selection]);

            return $this->success([
                'url' => $url,
                'mode' => 'storewide',
                'selection' => (new FreshaSelectionResource($selection))->resolve(),
            ]);
        }

        // Individual: preserve any existing selection (re-connecting the same store
        // keeps the saved team member); the dashboard re-picks via saveSelection.
        // FreshaSelection::toArray() returns the stored inner blob verbatim, so a
        // canonical stored selection round-trips byte-identically; a pending row
        // (selection null) carries forward as null, exactly as before.
        $existing = SelectionPayload::fromArray($this->readConnection($user) ?? []);
        $this->writeConnection($user, [
            'url' => $url,
            'selection' => $existing->selection?->toArray(),
        ]);

        return $this->success(['url' => $url, 'mode' => 'team', ...$menu]);
    }

    // GET /api/platforms/fresha/team — team + services for the saved URL.
    public function team(Request $request): JsonResponse
    {
        $url = $this->freshaUrl($this->currentUser($request));
        if (! $url) {
            return $this->error('No Fresha URL connected yet. POST one to /connect first.', 404);
        }

        return $this->success(['url' => $url, ...$this->fetchMenu($url)]);
    }

    // GET /api/platforms/fresha/url — peek at what's saved without re-scraping.
    public function show(Request $request): JsonResponse
    {
        return $this->success(['url' => $this->freshaUrl($this->currentUser($request))]);
    }

    // POST /api/platforms/fresha/selection — save which team member is "you"
    // plus the current service menu. Re-scrapes the saved URL so the stored
    // blob is server-authoritative (not whatever the client happened to hold).
    public function saveSelection(SaveFreshaSelectionRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $validated = $request->validated();

        $url = $this->freshaUrl($user);
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

        // Preserve previously hidden services, dropping ids that no longer exist
        // in the refreshed menu so the hidden list never drifts stale.
        $serviceIds = array_map(static fn (array $s): string => (string) $s['serviceId'], $services);
        $existing = SelectionPayload::fromArray($this->readConnection($user) ?? []);
        $hidden = array_values(array_filter(
            $existing->selection?->hiddenServiceIds() ?? [],
            static fn ($id): bool => in_array($id, $serviceIds, true),
        ));

        $selection = [
            'url' => $url,
            'storeName' => $this->extractStoreName($location),
            'mode' => 'employee',
            'employee' => $employee,
            'services' => $services,
            'hiddenServiceIds' => $hidden,
        ];
        $this->writeConnection($user, ['url' => $url, 'selection' => $selection]);

        return $this->success((new FreshaSelectionResource($selection))->resolve());
    }

    // GET /api/platforms/fresha/employee-services?employeeId=X — the per-employee
    // menu for the dashboard preview (before saving). Same fallback as above.
    public function employeeServices(FreshaEmployeeServicesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $url = $this->freshaUrl($this->currentUser($request));
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
    public function selection(Request $request): JsonResponse
    {
        $payload = SelectionPayload::fromArray($this->readConnection($this->currentUser($request)) ?? []);

        return $this->success([
            'selection' => $payload->selection !== null
                ? (new FreshaSelectionResource($payload->selection->toArray()))->resolve()
                : null,
            // Pending (Google-seeded) connections have a url but no selection — the
            // dashboard uses it to show "Finish setup" and open the picker.
            'url' => $payload->url,
        ]);
    }

    // POST /api/platforms/fresha/service-visibility — show/hide one service on the
    // public page. Toggles the service id in the saved selection's hiddenServiceIds
    // list; only ids present in the saved menu are accepted. Returns the updated
    // selection so the dashboard swaps state in place. (partna-pages filters the
    // services list by hiddenServiceIds at render time — the public payload is
    // shipped verbatim, so the hidden list is curation, not a privacy boundary.)
    public function setServiceVisibility(SetFreshaServiceVisibilityRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $validated = $request->validated();

        return $this->withConnectionLock($user, function () use ($user, $validated): JsonResponse {
            $payload = SelectionPayload::fromArray($this->readConnection($user) ?? []);
            $selection = $payload->selection;
            if ($selection === null) {
                return $this->error('No Fresha selection saved yet.', 404);
            }

            // Only toggle services that exist in the saved menu.
            $serviceIds = array_map(
                static fn ($s) => is_array($s) ? ($s['serviceId'] ?? null) : null,
                $selection->services(),
            );
            if (! in_array($validated['serviceId'], $serviceIds, true)) {
                return $this->error('That service is not part of the saved Fresha menu.', 404);
            }

            $hidden = array_values(array_filter(
                $selection->hiddenServiceIds(),
                static fn ($id): bool => is_string($id),
            ));

            if ($validated['hidden']) {
                $hidden = array_values(array_unique([...$hidden, $validated['serviceId']]));
            } else {
                $hidden = array_values(array_filter($hidden, static fn ($id): bool => $id !== $validated['serviceId']));
            }

            // Write back the inner blob VERBATIM with only hiddenServiceIds replaced —
            // FreshaSelection::toArray() returns the stored blob unchanged, so the
            // public (verbatim) selection payload never gains a canonical-null key.
            $inner = [...$selection->toArray(), 'hiddenServiceIds' => $hidden];
            $this->writeConnection($user, ['url' => $payload->url, 'selection' => $inner]);

            return $this->success((new FreshaSelectionResource($inner))->resolve());
        });
    }

    // DELETE /api/platforms/fresha — clear the saved URL and selection.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

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
            ])->timeout(12)->post(self::GRAPHQL_URL, $payload);
        } catch (Throwable $e) {
            // Surface silent failures so a rotated BOOKING_INIT_HASH/client version
            // is visible in Nightwatch instead of silently degrading to the
            // whole-location menu (the documented rotation inevitability).
            Log::warning('fresha.employee_services.failed', [
                'reason' => 'exception',
                'slug' => $slug,
                'employee_id' => $employeeId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->ok()) {
            Log::warning('fresha.employee_services.failed', [
                'reason' => 'http_error',
                'slug' => $slug,
                'employee_id' => $employeeId,
                'status' => $response->status(),
            ]);

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
