<?php

use App\Jobs\Platforms\LinkInBioScanJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Routing\Importers\LinkInBioImporter;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\RouteContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// F8: the event branch wrote a real eventbrite/humanitix row but returned
// RouteResult::seeded() with NO findings, so the "we found and connected this"
// modal (GET /platforms/instagram/synced) never mentioned it. Emitting a
// finding is only half the fix — InstagramController::shapeFinding() resolves a
// seeded finding back to a live row by "platform|resourceId" and DROPS it when
// there is no match, and events are the one platform whose resource_id is not
// the platform name (they are 'event-<id>' / 'acct-<hash>', many rows per
// platform). So these pin all three: the finding exists, it carries the id of
// the row that was ACTUALLY written, and its remove path targets that one
// event/account rather than the platform's forget-everything route.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupNotificationsTable();
    // /synced folds new-pipeline Hold intents (SyncFindingsBridge).
    setupRoutingTables();
});

const EB_EVENT_URL = 'https://www.eventbrite.com.au/e/winter-tasting-tickets-99887';
const EB_ORG_URL = 'https://www.eventbrite.com.au/o/melbourne-food-collective-1234';

/** Minimal event-detail page — parseEvent() takes the first JSON-LD node carrying a startDate. */
function ebEventPage(string $url): string
{
    return '<html><body><script type="application/ld+json">'.json_encode([
        '@type' => 'Event',
        'name' => 'Winter Tasting',
        'startDate' => '2027-03-01T19:00:00+11:00',
        'url' => $url,
        'location' => ['name' => 'The Depot', 'address' => ['addressLocality' => 'Brunswick']],
    ]).'</script></body></html>';
}

/** Organiser page — fetchEvents() harvests www.eventbrite.<tld>/e/… links out of the body. */
function ebOrgPage(): string
{
    return '<html><head><meta property="og:title" content="Melbourne Food Collective Events | Eventbrite"></head>'
        .'<body><a href="'.EB_EVENT_URL.'">Winter Tasting</a></body></html>';
}

function fakeEventbrite(): void
{
    Http::fake([
        'www.eventbrite.com.au/o/*' => Http::response(ebOrgPage(), 200),
        'www.eventbrite.com.au/e/*' => Http::response(ebEventPage(EB_EVENT_URL), 200),
    ]);
}

// ── The finding must resolve to the row that was written ─────────────────────

it('stamps a standalone event finding with the resource id of the row it actually wrote', function () {
    $user = User::factory()->create(['account_type' => 'partna']);
    fakeEventbrite();

    $result = app(LinkRouter::class)->route($user, EB_EVENT_URL, new RouteContext);

    $row = IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'eventbrite'])->firstOrFail();
    expect($result->findings)->toHaveCount(1);
    expect($result->findings[0]['outcome'])->toBe('seeded');
    expect($result->findings[0]['platform'])->toBe('eventbrite');
    // The lookup key shapeFinding() uses. 'eventbrite' as the resourceId — the
    // shape every other platform has — would silently drop off the modal.
    expect($result->findings[0]['resourceId'])->toBe($row->resource_id);
    expect($row->resource_id)->toStartWith('event-');
});

it('stamps an organiser finding with the resource id of the account row it actually wrote', function () {
    $user = User::factory()->create(['account_type' => 'partna']);
    fakeEventbrite();

    $result = app(LinkRouter::class)->route($user, EB_ORG_URL, new RouteContext);

    $row = IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'eventbrite'])->firstOrFail();
    expect($result->findings)->toHaveCount(1);
    expect($result->findings[0]['resourceId'])->toBe($row->resource_id);
    expect($row->resource_id)->toStartWith('acct-');
});

it('reuses the resource id of an existing organiser row rather than the id it would have minted', function () {
    // seedAccount() keys updateOrCreate on `$existing?->resource_id ?? $rid` —
    // a row matched by canonical_key keeps ITS id. A finding built from the
    // freshly-derived $rid would point at a row that does not exist.
    $user = User::factory()->create(['account_type' => 'partna']);
    $existing = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'eventbrite', 'resource_id' => 'acct-legacy-id',
        'canonical_key' => strtolower(EB_ORG_URL),
        'payload' => ['url' => EB_ORG_URL, 'upcoming' => []], 'is_active' => true,
    ]);
    fakeEventbrite();

    $result = app(LinkRouter::class)->route($user, EB_ORG_URL, new RouteContext);

    expect($result->findings[0]['resourceId'])->toBe($existing->resource_id);
});

// ── Remove path must target the one event/account ────────────────────────────

it('points a standalone event finding at the single-event remove route', function () {
    // '/platforms/eventbrite' is DELETE forget() — it would drop every event
    // the user has. removeEvent() takes the BARE id ('event-' is re-added).
    $user = User::factory()->create(['account_type' => 'partna']);
    fakeEventbrite();

    $result = app(LinkRouter::class)->route($user, EB_EVENT_URL, new RouteContext);

    $row = IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'eventbrite'])->firstOrFail();
    $bareId = substr($row->resource_id, strlen('event-'));
    expect($result->findings[0]['removePath'])->toBe('/platforms/eventbrite/events/'.$bareId);
});

it('points an organiser finding at the single-account remove route', function () {
    // removeAccount() matches on the FULL resource_id, unlike removeEvent().
    $user = User::factory()->create(['account_type' => 'partna']);
    fakeEventbrite();

    $result = app(LinkRouter::class)->route($user, EB_ORG_URL, new RouteContext);

    $row = IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'eventbrite'])->firstOrFail();
    expect($result->findings[0]['removePath'])->toBe('/platforms/eventbrite/accounts/'.$row->resource_id);
});

// ── End to end: it reaches the modal ─────────────────────────────────────────

it('shows an event found inside a link-in-bio page as synced in the modal', function () {
    Queue::fake();
    $user = User::factory()->create(['account_type' => 'partna']);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['syncFindings' => [], 'unmatched' => []], 'is_active' => true,
    ]);
    Http::fake([
        'linktr.ee/*' => Http::response('<a href="'.EB_EVENT_URL.'">Tickets</a>', 200),
        'www.eventbrite.com.au/e/*' => Http::response(ebEventPage(EB_EVENT_URL), 200),
    ]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(app(LinkInBioImporter::class));

    // NEW CONTRACT (LinkInBioImporter migration, owner ruling 2026-08-18):
    // the event still seeds — a real eventbrite connection and its event item
    // exist the moment the scan lands — but the synced MODAL no longer lists
    // items found one hop inside a bio page: the modal is payload findings
    // (written by the still-legacy direct bio scan) plus the B4 conflict
    // fold, and successful placements on the new path write neither. Modal
    // completeness for unroll-found items returns when InstagramAutoSync
    // (P8 consumer 2) migrates and the fold is widened with it.
    $conn = IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'eventbrite'])->first();
    expect($conn)->not->toBeNull();
    expect($conn->resource_id)->toStartWith('event-');

    $synced = actingAsUser($user)->getJson('/api/platforms/instagram/synced')->assertOk()->json('synced');
    expect(collect($synced)->firstWhere('platform', 'eventbrite'))->toBeNull();
});
