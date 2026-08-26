<?php

use App\Jobs\Platforms\CommerceProbeJob;
use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
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

it('dispatches the enrichment fetch when accepting a content suggestion (F14)', function () {
    // F9 covered the reconciler's AUTO-place path; T9b's parent suggestions
    // are suggest-only by design, so the connections that feature produces
    // are born here — via accept — and were landing as nameless
    // URL-as-account rows until the next scheduled refresh.
    Queue::fake();
    $pro = createTenant('inbox-accept-fetch');
    $intentId = seedIntent($pro->id, [
        'surface_key' => 'youtube.channel', 'routing_class' => 'content',
        'identifier' => 'somechannel', 'canonical_url' => 'https://www.youtube.com/@somechannel',
    ]);

    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intentId}/accept")->assertOk();

    $connection = IntegrationConnection::query()->where('user_id', $pro->id)->first();
    Queue::assertPushed(ConnectFetchJob::class, fn ($job) => $job->connectionId === (string) $connection->id);
});

it('does not dispatch the fetch for a content surface with no fetch capability (F14)', function () {
    // The other half of the guard: mixcloud.player is content-class but
    // declares no capabilities.fetch in the catalog. A regression that kept
    // the class check and dropped the capability check would pass the
    // social-accept test and still dispatch a job no strategy can serve.
    Queue::fake();
    $pro = createTenant('inbox-accept-nofetchcap');
    $intentId = seedIntent($pro->id, [
        'surface_key' => 'mixcloud.player', 'routing_class' => 'content',
        'identifier' => 'someone', 'canonical_url' => 'https://www.mixcloud.com/someone/',
    ]);

    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intentId}/accept")->assertOk();

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->exists())->toBeTrue();
    Queue::assertNotPushed(ConnectFetchJob::class);
});

it('does not re-dispatch the fetch when the accept resolves to an existing row (F14)', function () {
    // The dispatch lives in the CREATE branch only: a matched-existing row
    // came from a lane that already owns its enrichment, and re-fetching it
    // on every accept would be a duplicate job per suggestion.
    Queue::fake();
    $pro = createTenant('inbox-accept-existing');

    $existing = new IntegrationConnection([
        'user_id' => $pro->id, 'surface_key' => 'youtube.channel',
        'routing_class' => 'content', 'resource_id' => 'somechannel',
        'payload' => ['username' => 'somechannel'], 'is_active' => true,
    ]);
    $existing->save();

    $intentId = seedIntent($pro->id, [
        'surface_key' => 'youtube.channel', 'routing_class' => 'content',
        'identifier' => 'somechannel', 'canonical_url' => 'https://www.youtube.com/@somechannel',
    ]);

    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intentId}/accept")->assertOk();

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(1);
    Queue::assertNotPushed(ConnectFetchJob::class);
});

it('does not dispatch the enrichment fetch for a non-content accept (F14)', function () {
    // Same class rule as SourceReconciler::applyIntent: booking enrichment is
    // owned by AutoBookingConnectDispatcher's claimed/unclaimed rule, shop by
    // its own connect jobs, and socials have no fetch capability to run.
    Queue::fake();
    $pro = createTenant('inbox-accept-nofetch');
    $intentId = seedIntent($pro->id);

    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intentId}/accept")->assertOk();

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->exists())->toBeTrue();
    Queue::assertNotPushed(ConnectFetchJob::class);
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

it('offers a swap for a cap-blocked link on a single-account surface, and swapping retires the incumbent', function () {
    // Owner, 2026-08-19: a limited kind of link (one Fresha, one Behance)
    // that is already filled shows Swap, not a dead "you've reached the
    // limit". The incumbent is resolved at read time for intents recorded
    // before the reconciler wrote it, and a swap soft-deletes it — one
    // Behance for another, not two Behances with a demoted flag.
    $pro = createTenant('inbox-swap');

    $incumbent = new IntegrationConnection([
        'user_id' => $pro->id, 'surface_key' => 'behance.profile',
        'routing_class' => 'social', 'resource_id' => 'old-handle',
        'payload' => [], 'is_active' => true,
    ]);
    $incumbent->save();

    $intentId = seedIntent($pro->id, [
        'surface_key' => 'behance.profile', 'routing_class' => 'social',
        'identifier' => 'new-handle', 'canonical_url' => 'https://www.behance.net/new-handle',
        'state' => 'blocked', 'block_reason' => 'cap_reached',
    ]);

    $listed = actingAsUser($pro)->getJson('/api/routing/suggestions')->json('suggestions.0');
    expect($listed['actions'])->toBe(['replace', 'dismiss'])
        ->and($listed['question'])->toContain('swap')
        ->and($listed['conflictingConnectionId'])->toBe($incumbent->id);

    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intentId}/accept")->assertOk();

    expect(IntegrationConnection::withTrashed()->whereKey($incumbent->id)->value('deleted_at'))->not->toBeNull()
        ->and(IntegrationConnection::query()->where('user_id', $pro->id)->where('surface_key', 'behance.profile')->pluck('resource_id')->all())
        ->toBe(['new-handle']);
});

it('keeps a cap-blocked link on a multi-account surface dismiss-only', function () {
    // Ten YouTube channels already: no ONE row a swap could mean. (This case
    // used Instagram until FI-1, 2026-08-20, made every social single-account
    // — a cap-blocked social now correctly OFFERS the swap, so the
    // dismiss-only shape needs a genuinely multi-account surface.)
    $pro = createTenant('inbox-cap-multi');
    foreach (range(1, 10) as $n) {
        (new IntegrationConnection([
            'user_id' => $pro->id, 'surface_key' => 'youtube.channel',
            'routing_class' => 'content', 'resource_id' => "acct-{$n}",
            'payload' => [], 'is_active' => true,
        ]))->save();
    }
    seedIntent($pro->id, [
        'surface_key' => 'youtube.channel', 'routing_class' => 'content',
        'identifier' => 'acct-11', 'canonical_url' => 'https://www.youtube.com/@acct-11',
        'state' => 'blocked', 'block_reason' => 'cap_reached',
    ]);

    expect(actingAsUser($pro)->getJson('/api/routing/suggestions')->json('suggestions.0.actions'))
        ->toBe(['dismiss']);
});

it('un-blocks a cap-blocked link once the cap is no longer reached', function () {
    // The catalog widened under a standing intent (Mixcloud went 1 → 5,
    // 2026-08-19), or the owner disconnected one: it is a plain proposal
    // again, not a dead "you've reached the limit".
    $pro = createTenant('inbox-cap-lifted');
    (new IntegrationConnection([
        'user_id' => $pro->id, 'surface_key' => 'mixcloud.player',
        'routing_class' => 'content', 'resource_id' => 'https://www.mixcloud.com/one',
        'payload' => [], 'is_active' => true,
    ]))->save();
    $intentId = seedIntent($pro->id, [
        'surface_key' => 'mixcloud.player', 'routing_class' => 'content',
        'identifier' => 'https://www.mixcloud.com/two', 'canonical_url' => 'https://www.mixcloud.com/two',
        'state' => 'blocked', 'block_reason' => 'cap_reached',
    ]);

    $listed = actingAsUser($pro)->getJson('/api/routing/suggestions')->json('suggestions.0');
    expect($listed['state'])->toBe('proposed')
        ->and($listed['actions'])->toBe(['accept', 'dismiss']);
    expect(DB::table('routing.source_intents')->where('id', $intentId)->value('state'))->toBe('proposed');
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
    app()->call([new CommerceProbeJob((string) $pro->id, 'https://www.beardbrand.com/', suggestOnly: true), 'handle']);
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
    app()->call([new CommerceProbeJob((string) $pro->id, 'https://www.beardbrand.com/', 'shop'), 'handle']);
    $connection = IntegrationConnection::query()->where('user_id', $pro->id)->where('surface_key', 'shopify.store')->first();
    expect($connection)->not->toBeNull()
        ->and($connection->payload['name'] ?? null)->toBe('Beardbrand')
        ->and(DB::table('routing.source_intents')->where('id', $intent->id)->value('state'))->toBe('applied')
        ->and(DB::table('content.collections')->where('user_id', $pro->id)->where('kind', 'storefront')->where('label', 'Beardbrand')->exists())->toBeTrue();
});

it('connects a store whose suggestion carries a deep path, rather than re-proposing it', function () {
    // The live dev row this reproduces (intent df68c566, 2026-08-24):
    // shopify.store / 23504463 / https://stali.com.au/collections/star-wars —
    // first seen through a collection link, not the storefront root.
    //
    // CommerceProbeJob's deep-page rule turns an UNASKED auto-connect into a
    // question. On the accept lane the question has already been asked and
    // answered, so applying it there re-proposed the very card the user just
    // said yes to: Add reported "Adding..." forever and nothing connected.
    setupContentTables();
    Cache::flush();
    $pro = createTenant('inbox-store-deep');
    Http::fake([
        '*/meta.json' => Http::response(['id' => 23504463, 'name' => 'ST. ALi', 'currency' => 'AUD'], 200),
        'https://stali.com.au/' => Http::response('<html><head><title>ST. ALi</title></head><body>Coffee</body></html>', 200, ['Content-Type' => 'text/html']),
        // REACHABLE and not a product page — the shape that sets $deepPage
        // (CommerceProbeJob::probe, FI-10). A 404 here would take the
        // unreachable arm instead, which probes the ORIGIN and so never
        // records the deep canonical_url the live row carries.
        'https://stali.com.au/collections/star-wars' => Http::response('<html><head><title>Star Wars | ST. ALi</title></head><body>A collection</body></html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);

    $deep = 'https://stali.com.au/collections/star-wars';
    app()->call([new CommerceProbeJob((string) $pro->id, $deep, suggestOnly: true), 'handle']);

    $intent = DB::table('routing.source_intents')->where('user_id', $pro->id)->where('surface_key', 'shopify.store')->first();
    expect($intent)->not->toBeNull()
        ->and($intent->state)->toBe('proposed')
        ->and($intent->canonical_url)->toContain('/collections/star-wars');

    // Accept, then run exactly the job the controller pushed — not a
    // hand-built one, which is what hid this: the shape of the dispatch IS
    // the bug.
    Queue::fake();
    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intent->id}/accept")->assertStatus(202);

    $pushed = null;
    Queue::assertPushed(CommerceProbeJob::class, function ($job) use (&$pushed) {
        $pushed = $job;

        return true;
    });
    app()->call([$pushed, 'handle']);

    expect(DB::table('routing.source_intents')->where('id', $intent->id)->value('state'))->toBe('applied')
        ->and(IntegrationConnection::query()->where('user_id', $pro->id)->where('surface_key', 'shopify.store')->exists())->toBeTrue();
});

it('settles an accepted store the seeder could not build, instead of leaving the card standing', function () {
    // ProbeGate refuses a URL with 3+ path segments outright
    // (not_a_storefront_root) — no request goes out. seed() then returns on
    // the probe MISS, which is BEFORE the reconciler, so nothing settled the
    // intent: the user pressed Add, a plain link card appeared, and the
    // identical question stayed in the inbox with no account of what happened.
    setupContentTables();
    Cache::flush();
    $pro = createTenant('inbox-store-unservable');
    $intentId = seedIntent($pro->id, [
        'surface_key' => 'shopify.store',
        'routing_class' => 'shop',
        'identifier' => '23504463',
        'canonical_url' => 'https://stali.com.au/collections/star-wars/products/mug',
        'block_reason' => 'below_threshold',
    ]);

    Queue::fake();
    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intentId}/accept")->assertStatus(202);
    $pushed = null;
    Queue::assertPushed(CommerceProbeJob::class, function ($job) use (&$pushed) {
        $pushed = $job;

        return true;
    });
    app()->call([$pushed, 'handle']);

    $settled = DB::table('routing.source_intents')->where('id', $intentId)->first();
    expect($settled->state)->toBe('blocked')
        ->and($settled->block_reason)->toBe('unservable');

    // And it says so, rather than re-asking the question that just failed.
    $card = collect(actingAsUser($pro)->getJson('/api/routing/suggestions')->assertOk()->json('suggestions'))
        ->firstWhere('surfaceKey', 'shopify.store');
    expect($card['question'])->toBe("We couldn't reach this Shopify store. Try again?");
});

it('gives an accept its own uniqueness slot, so a probe in flight cannot swallow it', function () {
    // uniqueFor is 300s and the discovery probe that CREATED the suggestion
    // keys on the same (user, url) pair. A user answering the card inside
    // that window had the accept dropped by ShouldBeUnique while accept()
    // still returned 202 — indistinguishable, from the dashboard, from the
    // deep-path bug above.
    //
    // Asserted on uniqueId() directly: Queue::fake() never takes the unique
    // lock at all, so a dispatch-level test would pass whether or not the
    // two ids collide.
    $discovery = new CommerceProbeJob('user-1', 'https://stali.com.au/', 'shop');
    $accepted = new CommerceProbeJob('user-1', 'https://stali.com.au/', 'shop', acceptedIntentId: 'intent-1');

    expect($accepted->uniqueId())->not->toBe($discovery->uniqueId());
});

it('drops a suggestion for an account the user has already connected by another route', function () {
    // The live dev row this reproduces (intent 154b947d, 2026-08-19):
    // shopify.store / 11461296187 / https://natalieanne.com/ sat 'proposed'
    // in the inbox while a connection with that exact resource_id already
    // existed. resolveSwapIncumbent() only ever ran for 'cap_reached', so a
    // proposed intent recorded BEFORE the user connected the same account
    // through the connect sheet was never re-checked and stayed forever.
    $pro = createTenant('inbox-already-connected');
    seedIntent($pro->id, [
        'surface_key' => 'shopify.store', 'routing_class' => 'shop',
        'identifier' => '11461296187', 'canonical_url' => 'https://natalieanne.com/',
        'block_reason' => 'below_threshold',
    ]);
    // A second, genuinely unconnected suggestion — the filter must remove one
    // card, not empty the inbox.
    seedIntent($pro->id, ['identifier' => 'not-connected-yet']);

    IntegrationConnection::query()->create([
        'user_id' => $pro->id,
        'platform' => 'shopify',
        'surface_key' => 'shopify.store',
        'routing_class' => 'shop',
        'resource_id' => '11461296187',
        'payload' => [],
    ]);

    $suggestions = actingAsUser($pro)->getJson('/api/routing/suggestions')->assertOk()->json('suggestions');

    expect(collect($suggestions)->pluck('surfaceKey'))->not->toContain('shopify.store')
        ->and(collect($suggestions)->pluck('identifier'))->toContain('not-connected-yet');
});

it('folds case when deciding a suggestion is already connected (M-7 surfaces)', function () {
    // tiktok.profile is on ConnectionIdentity's case-fold allowlist, so
    // @STALi and @stali are the same account and must not be re-offered.
    $pro = createTenant('inbox-already-connected-case');
    seedIntent($pro->id, [
        'surface_key' => 'tiktok.profile', 'routing_class' => 'social',
        'identifier' => 'STALi', 'canonical_url' => 'https://www.tiktok.com/@STALi',
    ]);

    IntegrationConnection::query()->create([
        'user_id' => $pro->id,
        'platform' => 'tiktok',
        'surface_key' => 'tiktok.profile',
        'routing_class' => 'social',
        'resource_id' => 'stali',
        'payload' => [],
    ]);

    expect(actingAsUser($pro)->getJson('/api/routing/suggestions')->assertOk()->json('suggestions'))
        ->toBe([]);
});
