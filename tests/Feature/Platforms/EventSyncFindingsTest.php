<?php

use App\Jobs\Platforms\LinkInBioScanJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Routing\Importers\LinkInBioImporter;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\RouteContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// F8: the event branch wrote a real eventbrite/humanitix row but returned
// RouteResult::seeded() with NO findings, so the "we found and connected this"
// modal (the retired GET /platforms/instagram/synced) never mentioned it. Emitting a
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
    // The inbox reads intents and folds the legacy payload ledger.
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

it('routes a standalone event to the POOL — no connection row, no finding (R7, 2026-08-19)', function () {
    // The synced modal that consumed the finding is retired, and the dual
    // write went with it: the events pool item is the whole outcome — which
    // needs a site to pin it to.
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    $user = User::factory()->create(['account_type' => 'partna']);
    $site = new \App\Models\Core\Site\Site(['subdomain' => 'esf-standalone', 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();
    fakeEventbrite();

    $result = app(LinkRouter::class)->route($user, EB_EVENT_URL, new RouteContext);

    expect($result->outcome)->toBe('custom');
    expect($result->handled)->toBeTrue();
    expect($result->findings)->toBeEmpty();
    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'eventbrite'])->exists())->toBeFalse();
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

// (The standalone-event remove-path pin left with the finding itself — R7:
// a pasted event is a pool item; the pool row carries its own remove.)

it('points an organiser finding at the single-account remove route', function () {
    // removeAccount() matches on the FULL resource_id, unlike removeEvent().
    $user = User::factory()->create(['account_type' => 'partna']);
    fakeEventbrite();

    $result = app(LinkRouter::class)->route($user, EB_ORG_URL, new RouteContext);

    $row = IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'eventbrite'])->firstOrFail();
    expect($result->findings[0]['removePath'])->toBe('/platforms/eventbrite/accounts/'.$row->resource_id);
});

// ── End to end: it reaches the modal ─────────────────────────────────────────

it('seeds an event found inside a link-in-bio page as a pool ITEM only — no eventbrite platform row', function () {
    Queue::fake();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    $user = User::factory()->create(['account_type' => 'partna']);
    $site = new Site(['subdomain' => 'ebscan', 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['syncFindings' => [], 'unmatched' => []], 'is_active' => true,
    ]);
    Http::fake([
        'linktr.ee/*' => Http::response('<a href="'.EB_EVENT_URL.'">Tickets</a>', 200),
        'www.eventbrite.com.au/e/*' => Http::response(ebEventPage(EB_EVENT_URL), 200),
    ]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(app(LinkInBioImporter::class));

    // Owner ruling 2026-08-18 (gsnwilliams): a single event is an ITEM, not a
    // platform. The new lane records no payload finding, so a
    // resource_kind='event' connection row was read by nobody and only
    // surfaced as a bogus "Eventbrite" card beside the real event. This path
    // now writes exactly what the interactive addEvent verb writes — the pool
    // item — and nothing under site.platform_connections.
    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'eventbrite'])->exists())->toBeFalse();

    $items = DB::table('content.items')->where('user_id', $user->id)->where('kind', 'event')->whereNull('removed_at')->get();
    expect($items)->toHaveCount(1);

    // Nor does it ask about it: a successful placement writes neither a
    // payload finding nor a blocked intent, so the suggestions inbox has
    // nothing to say. (Until 2026-08-19 this read the retired synced modal.)
    $suggestions = actingAsUser($user)->getJson('/api/routing/suggestions')->assertOk()->json('suggestions');
    expect(collect($suggestions)->firstWhere('surfaceKey', 'eventbrite.organiser'))->toBeNull()
        ->and(collect($suggestions)->firstWhere('id', 'sync:instagram:eventbrite'))->toBeNull();
});

it('tags a bio-found event with its origin so the sheet can say where it came from', function () {
    Queue::fake();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    $user = User::factory()->create(['account_type' => 'partna']);
    $site = new Site(['subdomain' => 'eborig', 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();
    Http::fake([
        'linktr.ee/*' => Http::response('<a href="'.EB_EVENT_URL.'">Tickets</a>', 200),
        'www.eventbrite.com.au/e/*' => Http::response(ebEventPage(EB_EVENT_URL), 200),
    ]);

    (new LinkInBioScanJob((string) $user->id, 'https://linktr.ee/venue'))->handle(app(LinkInBioImporter::class));

    $itemId = DB::table('content.items')->where('user_id', $user->id)->where('kind', 'event')->value('id');
    expect(DB::table('content.item_tags')->where('item_id', $itemId)->where('tag_type', 'origin')->value('tag'))->toBe('link_in_bio');
});
