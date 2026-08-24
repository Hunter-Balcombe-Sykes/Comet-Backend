<?php

namespace App\Ingest\Connectors;

use App\Ingest\Landing\Coverage;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Bookmark;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Message;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

/**
 * Fresha via its pinned internal booking-flow GraphQL — a THIN wrapper around
 * the exact persisted-query shape `FreshaScraper::fetchEmployeeServices()`
 * already fires against the real vendor (same URL, same operation name, same
 * `extensions.persistedQuery{version,sha256Hash}` envelope), generalised from
 * one employee's filtered menu to whichever menu the owner
 * chose -- `Pull.config['selection_ref']` carries an employee id or the
 * literal 'storewide'. `shouldShowAllEmployees: true` returns the employee
 * PICKER screen with an empty `screenServices` and must never be sent here.
 *
 * `services` parses `data.bookingFlowInitialize.screenServices.categories`
 * exactly like the scraper does — including its `primaryAction`/
 * `secondaryAction` id's embedded `{"catalogId":"s:123"}` string, verified
 * against a real captured response (tests/Feature/Platforms/
 * RefreshFetchBudgetTest.php).
 *
 * There was a second stream, `profile`, reading a `location` object off the
 * SAME response for display_name/address/phone. Phase 1 deleted it: it
 * targeted `profile_fields`, whose consumer was dropped by
 * 20260805110000_drop_field_bindings.sql, so its output went nowhere. The
 * identity work it looked like it was doing is done by
 * App\Services\Platforms\IdentitySync, which reads the CONNECTION payload,
 * not this stream.
 *
 * The persisted-query hash is pinned to a specific Fresha frontend build and
 * WILL rotate; `FRESHA_BOOKING_INIT_HASH` / `FRESHA_CLIENT_VERSION`
 * (config/services.php) exist precisely so re-pinning is a one-line config
 * change, never a code deploy — see the re-pin runbook this connector's
 * Unavailable message names.
 */
class FreshaConnector implements Connector
{
    private const GRAPHQL_URL = 'https://www.fresha.com/graphql';

    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('fresha'),
            identifierKind: 'slug',
            hosts: ['*.fresha.com', 'fresha.com'],
            streams: [
                'services' => new StreamSpec(
                    name: 'services',
                    target: 'service',
                    profile: SourceProfile::Catalogue,
                    requires: ['serviceId', 'name'],
                    volatile: [],
                    // Fresha returns the full menu grouped by category, not
                    // time — there is no reverse-chron prefix to claim, so
                    // this stream is exhaustive-or-nothing, never partial.
                    orderField: null,
                    // ...but the one call IS the whole menu, so a service the
                    // menu no longer lists is gone (see StreamSpec::mayDelete).
                    deletesOnExhaustive: true,
                ),
            ],
            cost: CostClass::Free,
            defaultIntervalSeconds: 172800,
            // One free GraphQL call: run as soon as the connection (or its
            // team-member selection) lands so the services pool is not empty
            // until the scheduler's next tick.
            eagerOnConnect: true,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $slug = trim($pull->identifier, '/');
        // Slug rotation (2026-08-18): Fresha retires a venue slug when its
        // address lands; the identifier we provisioned then answers 410 / a
        // rejected booking flow while the venue is alive one redirect away.
        // Resolved once via the share alias and cached in the stream cursor —
        // the same shape as YoutubeRssConnector's handle→channel id — so a
        // later run costs nothing extra. FreshaController::team() persists
        // the rotation onto the connection when the OWNER trips it; this is
        // the lane's own healing when the scheduler trips it first.
        $cachedSlug = $pull->cursor['slug'] ?? null;
        if (is_string($cachedSlug) && preg_match('/^[a-z0-9-]+$/i', $cachedSlug)) {
            $slug = $cachedSlug;
        }
        $rotatedTo = null;
        $selectionRef = $pull->config['selection_ref'] ?? null;
        $selectionRef = is_string($selectionRef) && trim($selectionRef) !== '' ? trim($selectionRef) : null;

        if ($pull->stream->name === 'services' && $selectionRef === null) {
            // Nobody has chosen whose menu this is. Fetching the STORE menu
            // here would publish a whole salon's catalogue, at "from
            // <cheapest staff member>" prices, on one individual's page --
            // measured on dev, 22 of 23 prices understated. Landing nothing
            // is what happens today; the Note is so that "nobody chose yet"
            // stops looking identical to "the connector is broken".
            yield new Note('no_selection', 'No Fresha team member or storewide menu has been chosen for this connection');

            return;
        }

        $decoded = $this->fetchBookingFlow($slug, $io, $selectionRef);

        // A rejected flow OR a flow with no menu on it is also what a retired
        // slug produces. Before blaming the persisted-query hash, ask Fresha
        // what it calls this venue today and retry once on that.
        if ($decoded === null || ! is_array(data_get($decoded, 'data.bookingFlowInitialize.screenServices.categories'))) {
            $current = $this->resolveCurrentSlug($slug, $io);
            if ($current !== null && $current !== $slug) {
                $rotatedTo = $current;
                $slug = $current;
                $decoded = $this->fetchBookingFlow($slug, $io, $selectionRef);
            }
        }

        if ($decoded === null) {
            yield new Unavailable(
                'Fresha booking-flow GraphQL rejected the pinned persisted query — '.
                'the hash/client version has likely rotated on a Fresha frontend '.
                'deploy; re-pin by capturing a fresh persisted-query hash from the '.
                'current Fresha build and updating FRESHA_BOOKING_INIT_HASH / '.
                'FRESHA_CLIENT_VERSION (config/services.php "fresha") — see the '.
                'Fresha re-pin runbook.',
            );

            return;
        }

        if ($pull->stream->name === 'services') {
            // The booking-flow response prices everything as a bare formatted
            // string ("from $360") with NO currency anywhere in it, so every
            // service landed currency-less (overnight 2026-08-18 W6). The
            // location page's __NEXT_DATA__ carries the venue's ISO currency;
            // resolve it once and keep it in the stream cursor, like the
            // YouTube handle→channel-id resolution.
            $currency = $pull->cursor['currency'] ?? null;
            $currency = is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) ? $currency : null;
            $resolvedFresh = false;
            if ($currency === null) {
                $currency = $this->resolveCurrency($slug, $io);
                $resolvedFresh = $currency !== null;
            }

            // Non-null here: the guard above returns early when this stream is
            // 'services' and nothing has been chosen. (The dropped null arm was
            // redundant regardless — a null $selectionRef falls through to null.)
            $employeeId = $selectionRef === 'storewide' ? null : $selectionRef;
            yield from $this->servicesMessages($decoded, $slug, $employeeId, $currency);

            if ($resolvedFresh || $rotatedTo !== null) {
                // One Bookmark carrying everything the cursor already knew:
                // a Bookmark REPLACES the stream cursor, so a currency-only
                // write would drop a cached slug and vice versa.
                $cursor = array_filter([
                    ...$pull->cursor,
                    'currency' => $currency,
                    'slug' => $rotatedTo ?? ($pull->cursor['slug'] ?? null),
                ], static fn ($v) => $v !== null);
                yield new Bookmark('services', $cursor);
            }

            return;
        }
    }

    /**
     * The slug Fresha currently uses for $slug's venue, via the `book-now`
     * share alias (kept redirecting after the canonical `/a/<slug>` goes 410).
     * Null unless the probe lands 200 on a Fresha URL carrying a slug — a
     * failure must never read as a rotation.
     */
    private function resolveCurrentSlug(string $slug, Io $io): ?string
    {
        $response = $io->get('https://www.fresha.com/book-now/'.rawurlencode($slug).'/all-offer');
        // No `?? 0`: Io::get() returns array{status: int, body: string, headers: array}.
        if ($response['status'] !== 200) {
            return null;
        }
        $final = (string) ($response['headers']['final-url'] ?? '');

        return preg_match('#^https?://(?:www\.)?fresha\.com/(?:[a-z]{2,3}(?:-[a-z]{2})?/)?(?:a|book-now)/([a-z0-9-]+)#i', $final, $m) ? $m[1] : null;
    }

    /** The venue's ISO-4217 currency from its public location page, or null. */
    private function resolveCurrency(string $slug, Io $io): ?string
    {
        $response = $io->get('https://www.fresha.com/a/'.rawurlencode($slug));
        if ($response['status'] !== 200 || $response['body'] === '') {
            return null;
        }
        if (preg_match('/"currency":"([A-Z]{3})"/', $response['body'], $m)) {
            return $m[1];
        }

        return null;
    }

    /** @return iterable<Message> */
    private function servicesMessages(array $decoded, string $slug, ?string $employeeId, ?string $currency = null): iterable
    {
        $categories = data_get($decoded, 'data.bookingFlowInitialize.screenServices.categories');
        if (! is_array($categories)) {
            // Missing categories on an otherwise-successful call is the
            // documented rotation symptom (FreshaScraper's own comment) —
            // the same failure mode as a rejected hash, just discovered one
            // layer deeper.
            yield new Unavailable(
                'Fresha booking-flow response carried no screenServices.categories. '.
                'Most likely the request asked for a screen that has no menu on it — '.
                'check that shouldShowAllEmployees is false and that selection_ref '.
                'names a real employee (verified live 2026-08-13: allEmployees=true '.
                'returns the picker screen with screenServices {}). A rotated '.
                'persisted-query hash produces the same symptom and is the second '.
                'thing to check — re-pin FRESHA_BOOKING_INIT_HASH / '.
                'FRESHA_CLIENT_VERSION only after ruling the first out.',
            );

            return;
        }

        $items = [];
        $unmapped = 0;
        $categoryPosition = 0;
        foreach ($categories as $category) {
            $categoryName = is_string($category['name'] ?? null) ? $category['name'] : null;
            $categoryId = isset($category['id']) && is_scalar($category['id'])
                ? (string) $category['id']
                : null;
            // The venue's own ordering — categories, and services inside each
            // — rides on the record as seeds (F30, 2026-08-18); an owner's
            // reorder in the Categories sheet wins on the next run.
            $position = 0;
            foreach ((array) ($category['items'] ?? []) as $item) {
                $mapped = $this->mapServiceItem($item, $categoryName, $categoryId);
                if ($mapped !== null) {
                    $mapped['url'] = $this->bookingDeepLink($slug, $employeeId, $mapped['serviceId']);
                }
                if ($mapped !== null && $currency !== null) {
                    $mapped['currency'] = $currency;
                }
                if ($mapped !== null) {
                    $mapped['category_position'] = $categoryPosition;
                    $mapped['position'] = $position++;
                }
                $mapped === null ? $unmapped++ : $items[] = $mapped;
            }
            $categoryPosition++;
        }

        if ($items === []) {
            yield new Note('empty_menu', 'No services parsed from the Fresha booking-flow response');

            return;
        }

        foreach ($items as $item) {
            yield new Record('services', $item['serviceId'], $item);
        }

        // A parsed menu is the whole menu — Fresha does not paginate this
        // call, so what came back is everything there is.
        yield new Covered('services', Coverage::exhaustive());

        if ($unmapped > 0) {
            // A mapper gap used to be invisible: three of one salon's rows --
            // 12% of its menu -- vanished with no record and no signal.
            yield new Note('unmapped_rows', $unmapped.' Fresha row(s) carried no recognisable catalog id and were not landed');
        }
    }

    /**
     * The per-service booking deep link — the shape the design system shipped
     * (platform-sections.ts buildBookingProviders, 2026-08) and the frozen
     * dashboard before it: the venue's /booking flow opened on this service
     * and, when the owner chose a team member, on that person. Fresha mints
     * the cartId. Lost in the services-to-pools cutover (slice 7 → W6,
     * 2026-08-16..19) when this connector landed services with no URL and
     * the resolver lent the venue lander instead; restored 2026-08-24 at the
     * source so it rides as the item's own f_link.
     */
    private function bookingDeepLink(string $slug, ?string $employeeId, string $serviceId): string
    {
        $query = array_filter([
            'employeeId' => $employeeId,
            'offerItemId' => $serviceId,
        ], static fn ($v) => $v !== null && $v !== '');

        return 'https://www.fresha.com/a/'.rawurlencode($slug).'/booking?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<string, mixed>|null */
    private function mapServiceItem(mixed $item, ?string $categoryName, ?string $categoryId): ?array
    {
        if (! is_array($item)) {
            return null;
        }
        $name = is_string($item['name'] ?? null) ? trim($item['name']) : '';
        if ($name === '') {
            return null;
        }

        // The real id only ever surfaces embedded as a JSON string inside an
        // action id. A single service is `s:123`; a multi-service PACKAGE is
        // `p:360081` and carries it on secondaryAction only -- primaryAction
        // has bookableId instead. This must be a MATCH-based fallback, not
        // `?? `: primaryAction.id is a non-null string on a package, so a
        // null-coalesce never reaches the id that would have matched.
        $serviceId = null;
        foreach ([data_get($item, 'primaryAction.id'), data_get($item, 'secondaryAction.id')] as $actionId) {
            if (is_string($actionId) && preg_match('/"catalogId":"((?:s|p):\d+)"/', $actionId, $m)) {
                $serviceId = $m[1];
                break;
            }
        }
        if ($serviceId === null) {
            return null;
        }

        return array_filter([
            'serviceId' => $serviceId,
            'name' => $name,
            'duration' => is_string($item['caption'] ?? null) ? $item['caption'] : null,
            'description' => is_string($item['description'] ?? null) ? $item['description'] : null,
            'price' => is_string(data_get($item, 'price.formatted')) ? data_get($item, 'price.formatted') : null,
            'category' => $categoryName,
            'categoryId' => $categoryId,
        ], static fn ($v) => $v !== null);
    }

    /** @return array<string, mixed>|null null when the pinned query is rejected */
    private function fetchBookingFlow(string $slug, Io $io, ?string $selectionRef): ?array
    {
        $clientVersion = (string) config('services.fresha.client_version');
        $hash = (string) config('services.fresha.booking_init_hash');

        // 'storewide' is the reserved token for "the location's own menu";
        // anything else is an employee id. shouldShowAllEmployees:true returns
        // the employee-PICKER screen, whose screenServices is {} -- which is
        // why this stream landed zero records from 2026-07-28 (spec §1.3).
        $employeeId = ($selectionRef === null || $selectionRef === 'storewide') ? null : $selectionRef;

        $body = [
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

        $response = $io->post(self::GRAPHQL_URL, $body, [
            'content-type' => 'application/json',
            'x-client-platform' => 'web',
            'x-client-version' => $clientVersion,
            'x-graphql-operation-name' => 'mutation BookingFlow_Initialize_Mutation',
            'origin' => 'https://www.fresha.com',
        ]);

        if ($response['status'] !== 200) {
            return null;
        }

        $decoded = json_decode($response['body'], true);
        if (! is_array($decoded) || isset($decoded['errors'])) {
            // A rejected persisted-query hash surfaces as a GraphQL `errors`
            // key on a 200, not as a non-200 status.
            return null;
        }

        return $decoded;
    }
}
