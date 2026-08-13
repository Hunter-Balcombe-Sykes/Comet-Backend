<?php

namespace App\Ingest\Connectors;

use App\Ingest\Landing\Coverage;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
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
 * RefreshFetchBudgetTest.php). `profile` reads a `location` object off the
 * SAME response for display_name/address/phone — **this path is NOT verified
 * against a live capture** (only `screenServices` is proven in this
 * codebase); it is this connector's one deliberate best-effort guess, and a
 * missing `location` degrades to a quiet Note rather than Unavailable, since
 * the call itself still succeeded.
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
                ),
                'profile' => new StreamSpec(
                    name: 'profile',
                    target: 'profile_fields',
                    profile: SourceProfile::Identity,
                    requires: [],
                    volatile: [],
                    orderField: null,
                    // EMPTY until a real capture confirms the location path
                    // this connector currently guesses at. Declaring a field
                    // authoritative is a claim that this source is CAPABLE of
                    // reporting it — which authorises clearing the user's value
                    // when it comes back absent. Making that claim on an
                    // unverified path risks wiping a real business name,
                    // address or phone number the first time the guess is
                    // wrong. Populate this in the same change that verifies
                    // the path, never before.
                    authoritativeFields: [],
                ),
            ],
            cost: CostClass::Free,
            defaultIntervalSeconds: 172800,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $slug = trim($pull->identifier, '/');
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
            yield from $this->servicesMessages($decoded);

            return;
        }

        if ($pull->stream->name === 'profile') {
            yield from $this->profileMessages($decoded, $slug);
        }
    }

    /** @return iterable<Message> */
    private function servicesMessages(array $decoded): iterable
    {
        $categories = data_get($decoded, 'data.bookingFlowInitialize.screenServices.categories');
        if (! is_array($categories)) {
            // Missing categories on an otherwise-successful call is the
            // documented rotation symptom (FreshaScraper's own comment) —
            // the same failure mode as a rejected hash, just discovered one
            // layer deeper.
            yield new Unavailable(
                'Fresha booking-flow response had no screenServices.categories — '.
                'the classic pinned-hash rotation symptom on an otherwise-200 '.
                'response; re-pin FRESHA_BOOKING_INIT_HASH / FRESHA_CLIENT_VERSION.',
            );

            return;
        }

        $items = [];
        foreach ($categories as $category) {
            $categoryName = is_string($category['name'] ?? null) ? $category['name'] : null;
            foreach ((array) ($category['items'] ?? []) as $item) {
                $mapped = $this->mapServiceItem($item, $categoryName);
                if ($mapped !== null) {
                    $items[] = $mapped;
                }
            }
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
    }

    /** @return iterable<Message> */
    private function profileMessages(array $decoded, string $slug): iterable
    {
        $location = data_get($decoded, 'data.bookingFlowInitialize.location');
        if (! is_array($location)) {
            // The connectivity/auth call succeeded — this is "the response
            // didn't carry profile fields this run", not a fetch failure.
            yield new Note('no_profile_fields', 'Fresha booking-flow response carried no location profile data');

            return;
        }

        $doc = array_filter([
            'display_name' => is_string($location['name'] ?? null) ? $location['name'] : null,
            'address' => is_string($location['formattedAddress'] ?? null) ? $location['formattedAddress'] : null,
            'phone' => is_string($location['phoneNumber'] ?? null) ? $location['phoneNumber'] : null,
        ], static fn ($v) => $v !== null);

        if ($doc === []) {
            yield new Note('no_profile_fields', 'Fresha location object carried no usable profile fields');

            return;
        }

        yield new Record('profile', $slug, $doc);
        yield new Covered('profile', Coverage::exhaustive());
    }

    /** @return array<string, mixed>|null */
    private function mapServiceItem(mixed $item, ?string $categoryName): ?array
    {
        if (! is_array($item)) {
            return null;
        }
        $name = is_string($item['name'] ?? null) ? trim($item['name']) : '';
        if ($name === '') {
            return null;
        }

        // Mirrors FreshaScraper::fetchEmployeeServices exactly: the real
        // serviceId only ever surfaces embedded as a JSON string inside the
        // action id, e.g. primaryAction.id === '{"catalogId":"s:123"}'.
        $actionId = (string) (data_get($item, 'primaryAction.id') ?? data_get($item, 'secondaryAction.id') ?? '');
        if (! preg_match('/"catalogId":"(s:\d+)"/', $actionId, $m)) {
            return null;
        }

        return array_filter([
            'serviceId' => $m[1],
            'name' => $name,
            'duration' => is_string($item['caption'] ?? null) ? $item['caption'] : null,
            'description' => is_string($item['description'] ?? null) ? $item['description'] : null,
            'price' => is_string(data_get($item, 'price.formatted')) ? data_get($item, 'price.formatted') : null,
            'category' => $categoryName,
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
