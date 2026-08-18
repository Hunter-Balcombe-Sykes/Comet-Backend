<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Brand\StoreBrandSeeder;
use App\Services\Platforms\CustomLinkSeeder;
use App\Services\Platforms\EventsSeeder;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\ShopProductSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

function seedIntent(string $userId, array $overrides = []): string
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
        'origin' => 'website_import',
        'first_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $id;
}

it('lists what the router recognised but would not act on alone', function () {
    $pro = createTenant('inbox-list');
    seedIntent($pro->id);
    seedIntent($pro->id, ['identifier' => 'other', 'state' => 'blocked', 'block_reason' => 'below_threshold']);
    // Applied and dismissed intents are settled: an inbox that re-shows them
    // is an inbox people stop reading.
    seedIntent($pro->id, ['identifier' => 'applied-one', 'state' => 'applied']);
    seedIntent($pro->id, ['identifier' => 'dismissed-one', 'state' => 'dismissed']);

    $response = actingAsUser($pro)->getJson('/api/routing/suggestions');

    $response->assertOk();
    expect($response->json('suggestions'))->toHaveCount(2);
});

it('asks the question in the user\'s words, not the reconciler\'s', function () {
    $pro = createTenant('inbox-question');
    seedIntent($pro->id, ['state' => 'blocked', 'block_reason' => 'below_threshold']);

    $response = actingAsUser($pro)->getJson('/api/routing/suggestions');

    expect($response->json('suggestions.0.question'))->toBe('Is this your Instagram?')
        ->and($response->json('suggestions.0.displayName'))->toBe('Instagram');
});

it('offers replace rather than accept when something already owns the slot', function () {
    $pro = createTenant('inbox-conflict');
    seedIntent($pro->id, [
        'surface_key' => 'opentable.reserve', 'routing_class' => 'reservations',
        'identifier' => '12345', 'state' => 'blocked', 'block_reason' => 'conflict',
        'conflicting_connection_id' => (string) Str::uuid(),
    ]);

    $response = actingAsUser($pro)->getJson('/api/routing/suggestions');

    expect($response->json('suggestions.0.actions'))->toBe(['replace', 'dismiss'])
        ->and($response->json('suggestions.0.question'))->toContain('instead?');
});

it('offers only dismissal for something the account cannot have', function () {
    $pro = createTenant('inbox-gated');
    seedIntent($pro->id, ['state' => 'blocked', 'block_reason' => 'gate']);

    expect(actingAsUser($pro)->getJson('/api/routing/suggestions')->json('suggestions.0.actions'))
        ->toBe(['dismiss']);
});

it('creates the connection when a suggestion is accepted', function () {
    $pro = createTenant('inbox-accept');
    $intentId = seedIntent($pro->id);

    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intentId}/accept")->assertOk();

    $connection = IntegrationConnection::query()->where('user_id', $pro->id)->first();
    expect($connection)->not->toBeNull()
        ->and($connection->surface_key)->toBe('instagram.profile')
        ->and($connection->resource_id)->toBe('someone');

    $intent = DB::table('routing.source_intents')->where('id', $intentId)->first();
    expect($intent->state)->toBe('applied')
        ->and($intent->connection_id)->toBe($connection->id);
});

it('writes the payload through ConnectionPayload so a handle surface carries username', function () {
    // The blank-sitepage regression class: the public allowlist emits
    // {username, url} for socials and Instagram renders from `username`
    // ALONE. A writer that skips ConnectionPayload::forWrite persists a
    // payload the sitepage cannot render — every backend test still passes,
    // and the page is empty.
    $pro = createTenant('inbox-payload');
    $intentId = seedIntent($pro->id);

    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intentId}/accept")->assertOk();

    $payload = IntegrationConnection::query()->where('user_id', $pro->id)->first()->payload;
    expect($payload['username'])->toBe('someone')
        ->and($payload['url'])->toBe('https://www.instagram.com/someone')
        ->and($payload['source'])->toBe('suggestion');
});

it('demotes the incumbent rather than deleting it when replacing', function () {
    // The user asked for a different primary, not for their data to go.
    $pro = createTenant('inbox-replace');

    $incumbent = new IntegrationConnection([
        'user_id' => $pro->id, 'surface_key' => 'resdiary.reserve',
        'routing_class' => 'reservations', 'resource_id' => 'old-venue',
        'payload' => [], 'is_active' => true, 'is_primary' => true,
    ]);
    $incumbent->save();

    $intentId = seedIntent($pro->id, [
        'surface_key' => 'opentable.reserve', 'routing_class' => 'reservations',
        'identifier' => '12345', 'state' => 'blocked', 'block_reason' => 'conflict',
        'conflicting_connection_id' => $incumbent->id,
    ]);

    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intentId}/accept")->assertOk();

    expect($incumbent->fresh()->is_primary)->toBeFalse()
        ->and($incumbent->fresh()->deleted_at)->toBeNull()
        ->and(IntegrationConnection::query()->where('user_id', $pro->id)->where('is_primary', true)->value('surface_key'))
        ->toBe('opentable.reserve');
});

it('never re-asks a question the user already dismissed', function () {
    $pro = createTenant('inbox-dismiss');
    $intentId = seedIntent($pro->id);

    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intentId}/dismiss")->assertOk();

    expect(DB::table('routing.source_intents')->where('id', $intentId)->value('state'))->toBe('dismissed')
        ->and(actingAsUser($pro)->getJson('/api/routing/suggestions')->json('suggestions'))->toBeEmpty()
        // The tombstone is what stops the next harvest simply proposing it again.
        ->and(DB::table('routing.item_tombstones')
            ->where('user_id', $pro->id)
            ->where('source_ref', 'instagram.profile:someone')
            ->exists())->toBeTrue();
});

it('never lets one user resolve another user\'s suggestion', function () {
    $mine = createTenant('inbox-mine');
    $theirs = createTenant('inbox-theirs');
    $intentId = seedIntent($theirs->id);

    actingAsUser($mine)->postJson("/api/routing/suggestions/{$intentId}/accept")->assertStatus(404);
    actingAsUser($mine)->postJson("/api/routing/suggestions/{$intentId}/dismiss")->assertStatus(404);

    expect(DB::table('routing.source_intents')->where('id', $intentId)->value('state'))->toBe('proposed');
});

it('requires authentication', function () {
    $this->getJson('/api/routing/suggestions')->assertStatus(401);
});

// #TEST-1 / #DRIFT-1: SuggestionApplier::apply()'s capability re-check
// (RoutingCapabilityGate) exercised at the HTTP layer, via the real accept
// endpoint — CapabilityGateParityTest.php already covers the applier
// directly, this covers the controller wiring (status code, response body,
// nothing left half-applied). Every OTHER fixture in this file uses a
// createTenant() default (account_type 'partna'), for which can_use_booking
// is unconditionally true — the denial branch is structurally unreachable
// with that fixture, so this needs the food-sector business account
// (SectorTaxonomy::FOOD_SECTORS) that makes can_use_booking false.
it('403s accepting a booking suggestion the account cannot have, and blocks the intent instead of applying it', function () {
    $pro = createTenant('inbox-gate-booking', ['account_type' => 'business', 'sector' => 'restaurant']);
    $intentId = seedIntent($pro->id, [
        'surface_key' => 'fresha.book', 'routing_class' => 'booking',
        'identifier' => 'doc-cuts', 'canonical_url' => 'https://www.fresha.com/a/doc-cuts',
    ]);

    $response = actingAsUser($pro)->postJson("/api/routing/suggestions/{$intentId}/accept");

    $response->assertStatus(403)
        ->assertJsonPath('message', 'booking is not available for this account');

    $intent = DB::table('routing.source_intents')->where('id', $intentId)->first();
    expect($intent->state)->toBe('blocked')
        ->and($intent->block_reason)->toBe('gate');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->exists())->toBeFalse();
});

// ── Storefront offered from a pasted link (owner ask, 2026-08-18) ────────────

it('offers a probed Shopify storefront as a suggestion from a paste (never a placed store), and accepting it builds the store through the seeder', function () {
    setupContentTables();
    Cache::flush();
    $pro = createTenant('inbox-store');
    // The probe cascade sees a Shopify storefront on the user's own domain.
    Http::fake([
        '*/meta.json' => Http::response(['id' => 2090478, 'name' => 'Beardbrand', 'currency' => 'USD'], 200),
        'https://www.beardbrand.com/' => Http::response('<html><head><title>Beardbrand</title></head><body>Beard care</body></html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);

    // 1. Suggest-only probe (what RoutingController::store dispatches after a
    //    Note): the reconciler writes a PROPOSED intent, no connection.
    (new CommerceProbeJob((string) $pro->id, 'https://www.beardbrand.com/', suggestOnly: true))->handle(
        app(GenericShopScraper::class),
        app(StoreBrandSeeder::class),
        app(ShopProductSeeder::class),
        app(EventsSeeder::class),
        app(CustomLinkSeeder::class),
    );
    $intent = DB::table('routing.source_intents')->where('user_id', $pro->id)->where('surface_key', 'shopify.store')->first();
    expect($intent)->not->toBeNull()
        ->and($intent->state)->toBe('proposed')
        ->and($intent->block_reason)->toBe('below_threshold')
        ->and(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);
    $inbox = actingAsUser($pro)->getJson('/api/routing/suggestions')->assertOk()->json('suggestions');
    expect(collect($inbox)->firstWhere('surfaceKey', 'shopify.store')['question'])->toBe('Is this your Shopify store?');

    // 2. Accept → 202 pending; the store is built by the seeder, not the bare applier.
    Queue::fake();
    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intent->id}/accept")
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('connectionId', null);
    Queue::assertPushed(CommerceProbeJob::class, fn ($j) => $j->userId === (string) $pro->id && $j->category === 'shop' && $j->suggestOnly === false);
    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);

    // 3. That job (probe answer cached from step 1) places the store: connection
    //    named after the shop, storefront collection, intent applied.
    (new CommerceProbeJob((string) $pro->id, 'https://www.beardbrand.com/', 'shop'))->handle(
        app(GenericShopScraper::class),
        app(StoreBrandSeeder::class),
        app(ShopProductSeeder::class),
        app(EventsSeeder::class),
        app(CustomLinkSeeder::class),
    );
    $connection = IntegrationConnection::query()->where('user_id', $pro->id)->where('surface_key', 'shopify.store')->first();
    expect($connection)->not->toBeNull()
        ->and($connection->payload['name'] ?? null)->toBe('Beardbrand')
        ->and(DB::table('routing.source_intents')->where('id', $intent->id)->value('state'))->toBe('applied')
        ->and(DB::table('content.collections')->where('user_id', $pro->id)->where('kind', 'storefront')->where('label', 'Beardbrand')->exists())->toBeTrue();
});
