<?php

namespace App\Services\Platforms;

/**
 * Pure reader of Fresha's public venue page and booking-flow GraphQL shape —
 * URL grammar, the `__NEXT_DATA__` blob, and the persisted-query request body.
 *
 * Shared by FreshaScraper (dashboard, via SafeUrlFetcher) and FreshaConnector
 * (ingest, via Io) so the two cannot drift on a regex or a request shape
 * again — the connector's docblock used to carry a hand-sync instruction
 * ("the exact persisted-query shape FreshaScraper::fetchEmployeeServices()"),
 * which is a comment where a function belongs. Same split as
 * SquareBookingPage/SquareBookingClient.
 *
 * ZERO I/O, and that is load-bearing: no Http, no SafeUrlFetcher, no Io, no
 * config(), no abort(). Transport and error POLICY stay in the lanes — the
 * scraper aborts 502, the connector yields Unavailable, and each reads its
 * own response shape. Only grammar lives here.
 */
final class FreshaPage
{
    /** Fresha's internal booking GraphQL — the call the booking page fires. */
    public const GRAPHQL_URL = 'https://www.fresha.com/graphql';

    /** Drop the locale segment so we always cache the canonical /a/<slug> form. */
    public static function stripLocale(string $url): string
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
    public static function canonicalUrl(string $url): string
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
     * Host-anchored (an unanchored `/a/…` would match inside a foreign query
     * string), optional locale segment (Fresha's own redirects land on
     * `/en-GB/a/<slug>/booking?…`). The three write paths that pre-date link
     * routing all canonicalise before they store, so this only ever saw `/a/`.
     * The routing lane does not: SourceReconciler and SuggestionApplier write
     * `intent.canonical_url` verbatim, which for a Fresha link-in-bio is the
     * share URL.
     *
     * Two further copies of this grammar still live at SourceProvisioner.php
     * (`[a-z0-9][a-z0-9-]*`, rejects a leading hyphen) and FreshaBinding.php
     * (`/a/` only) — three grammars, four sites. Converging them is separate
     * work; this is where they should land.
     */
    public static function slugFromUrl(string $url): ?string
    {
        return preg_match('#^https?://(?:www\.)?fresha\.com/(?:[a-z]{2,3}(?:-[a-z]{2})?/)?(?:a|book-now)/([a-z0-9-]+)#i', $url, $m) ? $m[1] : null;
    }

    /**
     * The share-alias URL to probe when asking Fresha what it calls a venue
     * today: the canonical `/a/<slug>` page 410s once a slug rotates, while
     * the `book-now` alias keeps redirecting.
     */
    public static function shareProbeUrl(string $slug): string
    {
        return 'https://www.fresha.com/book-now/'.rawurlencode($slug).'/all-offer';
    }

    /**
     * The raw `__NEXT_DATA__` JSON string, or null when the page has no such
     * script tag.
     *
     * Separate from parseNextData() so a caller can tell "structure moved"
     * apart from "JSON is broken" — FreshaScraper raises a different 502 for
     * each, and one null cannot carry both.
     */
    public static function nextDataJson(string $body): ?string
    {
        return preg_match('#<script id="__NEXT_DATA__"[^>]*>(.+?)</script>#s', $body, $m) ? $m[1] : null;
    }

    /** The decoded `__NEXT_DATA__` document, or null if absent or undecodable. */
    public static function parseNextData(string $body): ?array
    {
        $json = self::nextDataJson($body);
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /**
     * The `props.pageProps.data.location` blob, or null.
     *
     * Null is the NORMAL answer for a `/book-now/<slug>/all-offer` page: that
     * is a different Next.js route and carries no location. Pinned by
     * FreshaPageTest against both recorded fixtures — it is the whole reason
     * canonicalUrl() exists.
     *
     * @param  array<string, mixed>  $nextData
     * @return array<string, mixed>|null
     */
    public static function locationFrom(array $nextData): ?array
    {
        $location = data_get($nextData, 'props.pageProps.data.location');

        return is_array($location) ? $location : null;
    }

    /** The salon's display name from the Fresha location blob. */
    public static function extractStoreName(array $location): ?string
    {
        $name = $location['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The venue's identity beyond its name (owner, 2026-08-19): what a
     * partna account's Fresha connect hands FreshaWorkplaceLinker so it can
     * find the same place on Google. Every key optional; null when Fresha's
     * blob has no address.
     *
     * @return array{name:?string, street:?string, city:?string, postcode:?string, region:?string, country:?string, lat:?float, lng:?float, phone:?string, mapsUrl:?string}|null
     */
    public static function extractVenue(array $location): ?array
    {
        $address = data_get($location, 'address');
        if (! is_array($address)) {
            return null;
        }
        $str = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $num = static fn (mixed $v): ?float => is_numeric($v) ? (float) $v : null;

        return [
            'name' => self::extractStoreName($location),
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

    /**
     * @return list<array{employeeId:string, displayName:string, jobTitle:?string, avatarUrl:?string, rating:?float}>
     */
    public static function extractTeam(array $location): array
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
     * The booking-flow persisted-query request body, identical for both lanes.
     *
     * `$employeeId` null means the location's own (storewide) menu.
     * `shouldShowAllEmployees: true` returns the employee PICKER screen, whose
     * screenServices is `{}` — which is why the ingest stream landed zero
     * records from 2026-07-28. It is hardcoded false and must stay so.
     *
     * The hash and client version are PASSED IN, not read from config(), so
     * this class stays I/O-free; each lane resolves
     * config('services.fresha.*') itself.
     *
     * @return array<string, mixed>
     */
    public static function bookingFlowPayload(string $slug, ?string $employeeId, string $hash, string $clientVersion): array
    {
        return [
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
                'persistedQuery' => ['version' => 1, 'sha256Hash' => $hash],
                'platform' => 'web',
                'version' => $clientVersion,
            ],
        ];
    }
}
