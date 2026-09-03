<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Accounts\AccountCapabilities;
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
        ->and($keys)->toContain('menu')
        ->and($keys)->toContain('logo')
        ->and($keys)->not->toContain('listing')
        ->and($keys)->not->toContain('platforms.booking')
        ->and($keys)->not->toContain('services');
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
