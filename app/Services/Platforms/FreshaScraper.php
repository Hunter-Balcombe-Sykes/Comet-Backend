<?php

namespace App\Services\Platforms;

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
    private const SCRAPE_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    // Fresha's internal booking GraphQL — same call the booking page fires to
    // load a single employee's filtered service menu.
    private const GRAPHQL_URL = 'https://www.fresha.com/graphql';

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /** Drop the locale segment so we always cache the canonical /a/<slug> form. */
    public function stripLocale(string $url): string
    {
        return preg_replace('#fresha\.com/[a-z]{2,3}(-[a-z]{2})?/a/#i', 'fresha.com/a/', $url) ?? $url;
    }

    /** Extract the `<slug>` from a Fresha `.../a/<slug>` URL. */
    public function slugFromUrl(string $url): ?string
    {
        return preg_match('#/a/([a-z0-9-]+)#i', $url, $m) ? $m[1] : null;
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
    public function fetchEmployeeServices(string $slug, string $employeeId): ?array
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
}
