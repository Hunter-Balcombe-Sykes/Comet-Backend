<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\PreAccountBuild;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Setup\SetupPassRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// A.9: GET /site/setup composes the pass list; PUT records/completes;
// POST /site/setup/accept applies one Continue; the observer settles a
// standing intent when its account arrives by another route.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    setupContentTables();
    setupIngestTables();
    setupPreAccountBuildsTable();
    setupPreAccountBuildEventsTable();
    AccountCapabilities::flushCache();
    Queue::fake();
});

function setupSeedIntent(string $userId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::table('routing.source_intents')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'surface_key' => 'instagram.profile',
        'routing_class' => 'social',
        'identifier' => 'someone',
        'canonical_url' => 'https://www.instagram.com/someone',
        'state' => 'proposed',
        'origin' => 'link_in_bio',
        'band' => 'auto',
        'first_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $id;
}

it('composes the partna pass list, listing first, done last', function () {
    $pro = createTenant('setup-partna');

    $response = actingAsUser($pro)->getJson('/api/site/setup');

    $response->assertOk();
    $keys = collect($response->json('passes'))->pluck('key');
    expect($keys->first())->toBe('listing')
        ->and($keys->last())->toBe('done')
        ->and($keys)->toContain('platforms.booking')
        ->and($keys)->toContain('platforms.social')
        ->and($keys)->not->toContain('platforms.ordering')
        ->and($keys)->not->toContain('logo');
});

it('composes the business pass list: ordering group, menu for food, logo present', function () {
    $pro = createTenant('setup-biz');
    $pro->forceFill(['account_type' => 'business', 'sector' => 'cafe'])->save();
    AccountCapabilities::flushCache();

    $keys = collect(actingAsUser($pro)->getJson('/api/site/setup')->json('passes'))->pluck('key');

    expect($keys)->toContain('platforms.ordering')
        ->and($keys)->toContain('logo')
        ->and($keys)->not->toContain('listing')
        ->and($keys)->not->toContain('platforms.booking')
        ->and($keys)->not->toContain('services');

    // Which of the two item-picking passes a food account gets is the
    // REGISTRY's answer, and it is asked here rather than of the payload: the
    // payload now omits an empty menu, so a composed pass list is evidence
    // about this account's data, not about the vocabulary.
    expect(SetupPassRegistry::keysFor($pro))->toContain('menu')
        ->and(SetupPassRegistry::keysFor($pro))->not->toContain('services');
});

it('renders a pre-scraped hidden row as a preselected syncing suggestion', function () {
    $pro = createTenant('setup-syncing');
    $connection = new IntegrationConnection([
        'user_id' => $pro->id, 'surface_key' => 'instagram.profile', 'routing_class' => 'social',
        'resource_id' => 'someone', 'payload' => [], 'is_active' => true,
        'visibility' => 'hidden', 'last_refresh_status' => 'pending',
    ]);
    $connection->save();
    setupSeedIntent($pro->id, ['state' => 'applied', 'connection_id' => $connection->id]);

    $passes = collect(actingAsUser($pro)->getJson('/api/site/setup')->json('passes'));
    $social = $passes->firstWhere('key', 'platforms.social');
    $row = collect($social['suggestions'])->firstWhere('surfaceKey', 'instagram.profile');

    expect($row)->not->toBeNull()
        ->and($row['syncing'])->toBeTrue()
        ->and($row['preselected'])->toBeTrue()
        ->and($row['connectionId'])->toBeNull();
});

it('PUT advances the step and completed stamps', function () {
    $pro = createTenant('setup-put');

    actingAsUser($pro)->putJson('/api/site/setup', ['step' => 'platforms.booking'])->assertOk();
    expect($pro->site->fresh()->setup_step)->toBe('platforms.booking');

    actingAsUser($pro)->putJson('/api/site/setup', ['step' => 'not-a-pass'])->assertStatus(422);

    actingAsUser($pro)->putJson('/api/site/setup', ['completed' => true])->assertOk();
    $site = $pro->site->fresh();
    expect($site->setup_completed_at)->not->toBeNull()
        ->and($site->setup_step)->toBe('done');
});

it('batch accept applies a proposed suggestion and reveals a hidden one', function () {
    $pro = createTenant('setup-batch');
    $proposedId = setupSeedIntent($pro->id);

    $hiddenConn = new IntegrationConnection([
        'user_id' => $pro->id, 'surface_key' => 'youtube.channel', 'routing_class' => 'content',
        'resource_id' => 'somechannel', 'payload' => [], 'is_active' => true, 'visibility' => 'hidden',
    ]);
    $hiddenConn->save();
    $appliedId = setupSeedIntent($pro->id, [
        'surface_key' => 'youtube.channel', 'routing_class' => 'content', 'identifier' => 'somechannel',
        'state' => 'applied', 'connection_id' => $hiddenConn->id, 'canonical_url' => 'https://youtube.com/@somechannel',
    ]);

    $response = actingAsUser($pro)->postJson('/api/site/setup/accept', [
        'pass' => 'platforms.social',
        'accept' => [$proposedId, $appliedId],
    ]);

    $response->assertOk();
    expect($response->json('errors'))->toBe([])
        ->and(DB::table('routing.source_intents')->where('id', $proposedId)->value('state'))->toBe('applied')
        ->and(IntegrationConnection::query()->where('user_id', $pro->id)->where('surface_key', 'instagram.profile')->value('visibility'))->toBe('visible')
        ->and($hiddenConn->fresh()->visibility)->toBe('visible');
});

it('ticking a store writes the shop STARTED note at accept, before the probe ever runs (2026-09-05)', function () {
    // Between Continue and the queue picking the probe up, items.shop used
    // to read ready-and-empty, so the dialog's "hold Continue until the
    // products are ready" returned at once and "Your products" was blank
    // until a refresh (squeakprobarber, st_ali).
    $pro = createTenant('setup-store-note');
    $build = PreAccountBuild::factory()->make(['source_type' => 'instagram']);
    $build->user()->associate($pro);
    $build->save();
    $intentId = setupSeedIntent($pro->id, [
        'surface_key' => 'shopify.store', 'routing_class' => 'shop',
        'identifier' => '64035750019', 'canonical_url' => 'https://64035750019.myshopify.com',
    ]);

    actingAsUser($pro)->postJson('/api/site/setup/accept', ['pass' => 'platforms.stores', 'accept' => [$intentId]])->assertOk();

    Queue::assertPushed(CommerceProbeJob::class);
    expect(DB::table('core.pre_account_build_events')
        ->where('build_id', $build->id)->where('stage', 'shop')->where('status', 'started')->exists())->toBeTrue();
    $shop = collect(actingAsUser($pro)->getJson('/api/site/setup')->json('passes'))->firstWhere('key', 'items.shop');
    if ($shop !== null) {
        expect($shop['ready'])->toBeFalse();
    }
});

it('a manual visible connect settles the matching standing intent (observer)', function () {
    $pro = createTenant('setup-settle');
    $intentId = setupSeedIntent($pro->id);

    $connection = new IntegrationConnection([
        'user_id' => $pro->id, 'surface_key' => 'instagram.profile', 'routing_class' => 'social',
        'resource_id' => 'someone', 'payload' => ['username' => 'someone'], 'is_active' => true,
    ]);
    $connection->save();

    $intent = DB::table('routing.source_intents')->where('id', $intentId)->first();
    expect($intent->state)->toBe('applied')
        ->and($intent->connection_id)->toBe($connection->id);
});

it('a hidden pre-scrape create does NOT settle the standing intent', function () {
    $pro = createTenant('setup-nosettle');
    $intentId = setupSeedIntent($pro->id);

    $connection = new IntegrationConnection([
        'user_id' => $pro->id, 'surface_key' => 'instagram.profile', 'routing_class' => 'social',
        'resource_id' => 'someone', 'payload' => ['username' => 'someone'], 'is_active' => true,
        'visibility' => 'hidden',
    ]);
    $connection->save();

    expect(DB::table('routing.source_intents')->where('id', $intentId)->value('state'))->toBe('proposed');
});

it('disconnect tears down the connection and reverts the intent to proposed (item 21)', function () {
    $pro = createTenant('setup-disc');
    $connection = new IntegrationConnection([
        'user_id' => $pro->id, 'surface_key' => 'instagram.profile', 'routing_class' => 'social',
        'resource_id' => 'someone', 'payload' => [], 'is_active' => true, 'visibility' => 'visible',
    ]);
    $connection->save();
    $intentId = setupSeedIntent($pro->id, ['state' => 'applied', 'connection_id' => $connection->id]);

    $response = actingAsUser($pro)->postJson('/api/site/setup/accept', [
        'pass' => 'platforms.social',
        'disconnect' => [$intentId],
    ]);

    $response->assertOk();
    expect($response->json('errors'))->toBe([])
        ->and(DB::table('routing.source_intents')->where('id', $intentId)->value('state'))->toBe('proposed')
        ->and(DB::table('routing.source_intents')->where('id', $intentId)->value('connection_id'))->toBeNull()
        ->and(IntegrationConnection::query()->whereKey($connection->id)->whereNull('deleted_at')->exists())->toBeFalse();
});

it('a visible connection WITHOUT an intent still renders in its pass, connected (item 23)', function () {
    $pro = createTenant('setup-manual');
    $connection = new IntegrationConnection([
        'user_id' => $pro->id, 'surface_key' => 'youtube.channel', 'routing_class' => 'content',
        'resource_id' => 'somechannel', 'payload' => ['url' => 'https://youtube.com/@somechannel'],
        'is_active' => true, 'visibility' => 'visible',
    ]);
    $connection->save();

    $passes = collect(actingAsUser($pro)->getJson('/api/site/setup')->json('passes'));
    $social = $passes->firstWhere('key', 'platforms.social');
    $row = collect($social['suggestions'])->firstWhere('surfaceKey', 'youtube.channel');

    expect($row)->not->toBeNull()
        ->and($row['id'])->toBe('connection:'.$connection->id)
        ->and($row['connectionId'])->toBe((string) $connection->id);
});

it('a legacy marker connection and the intent naming the same handle render as ONE card (2026-09-05, st_ali)', function () {
    // The Google listing seeds Instagram as resource_id='instagram' with the
    // handle only in its payload; the bio's @someone intent is filed as a
    // cap conflict against it. Two writers, one account — one card, named
    // from the scrape, never the bare "instagram" slug.
    $pro = createTenant('setup-marker');
    $marker = new IntegrationConnection([
        'user_id' => $pro->id, 'surface_key' => 'instagram.profile', 'routing_class' => 'social',
        'resource_id' => 'instagram',
        'payload' => ['username' => 'someone', 'url' => 'https://www.instagram.com/someone', 'source' => 'google-business'],
        'is_active' => true, 'visibility' => 'visible',
    ]);
    $marker->save();
    setupSeedIntent($pro->id, [
        'state' => 'blocked', 'block_reason' => 'cap_reached', 'conflicting_connection_id' => (string) $marker->id,
    ]);

    $passes = collect(actingAsUser($pro)->getJson('/api/site/setup')->json('passes'));
    $rows = collect($passes->firstWhere('key', 'platforms.social')['suggestions'])->where('surfaceKey', 'instagram.profile')->values();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['connectionId'])->toBe((string) $marker->id)
        ->and($rows[0]['accountName'])->not->toBeNull()
        ->and($rows[0]['accountName'])->not->toBe('instagram');
});

it('a hidden connection WITHOUT an intent renders as a preselected, syncing suggestion (setup-variant manual connect, 2026-09-04)', function () {
    $pro = createTenant('setup-manual-hidden');
    $connection = new IntegrationConnection([
        'user_id' => $pro->id, 'surface_key' => 'youtube.channel', 'routing_class' => 'content',
        'resource_id' => 'somechannel', 'payload' => ['url' => 'https://youtube.com/@somechannel'],
        'is_active' => true, 'visibility' => 'hidden', 'last_refresh_status' => 'pending',
    ]);
    $connection->save();

    $passes = collect(actingAsUser($pro)->getJson('/api/site/setup')->json('passes'));
    $social = $passes->firstWhere('key', 'platforms.social');
    $row = collect($social['suggestions'])->firstWhere('surfaceKey', 'youtube.channel');

    expect($row)->not->toBeNull()
        ->and($row['id'])->toBe('connection:'.$connection->id)
        ->and($row['preselected'])->toBeTrue()
        ->and($row['syncing'])->toBeTrue()
        ->and($row['connectionId'])->toBeNull()
        ->and($row['actions'])->toBe(['accept', 'dismiss']);
});

it('accepting a connection:<id> row reveals a hidden manual connect (setup-variant, 2026-09-04)', function () {
    $pro = createTenant('setup-manual-reveal');
    $connection = new IntegrationConnection([
        'user_id' => $pro->id, 'surface_key' => 'youtube.channel', 'routing_class' => 'content',
        'resource_id' => 'somechannel', 'payload' => [], 'is_active' => true, 'visibility' => 'hidden',
    ]);
    $connection->save();

    $response = actingAsUser($pro)->postJson('/api/site/setup/accept', [
        'pass' => 'platforms.social',
        'accept' => ['connection:'.$connection->id],
    ]);

    $response->assertOk();
    expect($response->json('errors'))->toBe([])
        ->and($connection->fresh()->visibility)->toBe('visible');
});

it('accepting a connection:<id> for another user\'s row is refused', function () {
    $pro = createTenant('setup-manual-reveal-owner');
    $other = createTenant('setup-manual-reveal-other');
    $connection = new IntegrationConnection([
        'user_id' => $other->id, 'surface_key' => 'youtube.channel', 'routing_class' => 'content',
        'resource_id' => 'somechannel', 'payload' => [], 'is_active' => true, 'visibility' => 'hidden',
    ]);
    $connection->save();

    $response = actingAsUser($pro)->postJson('/api/site/setup/accept', [
        'pass' => 'platforms.social',
        'accept' => ['connection:'.$connection->id],
    ]);

    $response->assertOk();
    expect($response->json('errors'))->toHaveKey('accept:connection:'.$connection->id)
        ->and($connection->fresh()->visibility)->toBe('hidden');
});

it('a connected google-business listing renders as a candidate row without blanking the pass (item 12)', function () {
    $pro = createTenant('setup-listing');
    $connection = new IntegrationConnection([
        'user_id' => $pro->id, 'surface_key' => 'google-business.listing', 'routing_class' => 'business',
        'platform' => 'google-business', 'resource_id' => 'place-1',
        'payload' => ['name' => 'Natalie Anne Hair', 'address' => '1 George St'],
        'is_active' => true, 'visibility' => 'visible',
    ]);
    $connection->save();

    $passes = collect(actingAsUser($pro)->getJson('/api/site/setup')->json('passes'));
    $listing = $passes->firstWhere('key', 'listing');
    $connected = collect($listing['candidates'])->firstWhere('state', 'connected');

    expect($connected)->not->toBeNull()
        ->and($connected['id'])->toBe('connected:'.$connection->id)
        ->and($connected['name'])->toBe('Natalie Anne Hair')
        ->and($connected['preselected'])->toBeTrue();
});

it('a store suggestion with no icon backfills the storefront favicon (item 14)', function () {
    $pro = createTenant('setup-favicon');
    setupSeedIntent((string) $pro->id, [
        'surface_key' => 'shopify.store',
        'routing_class' => 'shop',
        'identifier' => 'natalieannehaircare',
        'canonical_url' => 'https://natalieannehaircare.com',
        'origin' => 'commerce_probe',
    ]);

    $passes = collect(actingAsUser($pro)->getJson('/api/site/setup')->json('passes'));
    $stores = $passes->firstWhere('key', 'platforms.stores');

    expect($stores['suggestions'][0]['avatar'])
        ->toBe('https://www.google.com/s2/favicons?domain=natalieannehaircare.com&sz=128');
});

it('renders a registry-less brand (shopify) in the stores pass via its routing class', function () {
    $pro = createTenant('setup-shopify');
    setupSeedIntent((string) $pro->id, [
        'surface_key' => 'shopify.store',
        'routing_class' => 'shop',
        'identifier' => '64035750019',
        'canonical_url' => 'https://64035750019.myshopify.com',
        'origin' => 'commerce_probe',
    ]);

    $response = actingAsUser($pro)->getJson('/api/site/setup');

    $stores = collect($response->json('passes'))->firstWhere('key', 'platforms.stores');
    expect($stores['suggestions'])->toHaveCount(1)
        ->and($stores['suggestions'][0]['surfaceKey'])->toBe('shopify.store')
        ->and($stores['suggestions'][0]['preselected'])->toBeTrue();
});

// 2026-09-04 (tobiasindarwin test signup): the walk reached "Your services"
// and drew a heading, a subtitle and Continue over empty space. The account's
// booking platform was Timely — a booking LINK with no connector, so no
// service item could ever arrive — and the services pass, unlike an item
// pass, was emitted no matter how empty it was.

it('omits the services pass for a booking platform that can never fill it', function () {
    $pro = createTenant('setup-timely');
    (new IntegrationConnection([
        'user_id' => $pro->id, 'surface_key' => 'timely.book', 'routing_class' => 'booking',
        'resource_id' => 'some-salon', 'payload' => [], 'is_active' => true,
    ]))->save();

    $keys = collect(actingAsUser($pro)->getJson('/api/site/setup')->json('passes'))->pluck('key');

    expect($keys)->not->toContain('services');
});

it('keeps an empty services pass while fresha still owes its team pick', function () {
    $pro = createTenant('setup-fresha-pick');
    (new IntegrationConnection([
        'user_id' => $pro->id, 'surface_key' => 'fresha.book', 'routing_class' => 'booking',
        'resource_id' => 'some-venue', 'payload' => [], 'is_active' => true,
    ]))->save();

    $passes = collect(actingAsUser($pro)->getJson('/api/site/setup')->json('passes'));
    $services = $passes->firstWhere('key', 'services');

    expect($services)->not->toBeNull()
        ->and($services['platform'])->toBe('fresha')
        ->and($services['teamPicked'])->toBeFalse();
});

it('omits the services pass once fresha has its pick and still no services', function () {
    $pro = createTenant('setup-fresha-picked');
    (new IntegrationConnection([
        'user_id' => $pro->id, 'surface_key' => 'fresha.book', 'routing_class' => 'booking',
        'resource_id' => 'some-venue', 'payload' => ['selection' => ['id' => 'emp-1']],
        'is_active' => true,
    ]))->save();

    $keys = collect(actingAsUser($pro)->getJson('/api/site/setup')->json('passes'))->pluck('key');

    expect($keys)->not->toContain('services');
});
