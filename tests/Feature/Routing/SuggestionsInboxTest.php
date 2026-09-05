<?php

use App\Catalog\CompiledCatalog;
use App\Jobs\Platforms\CommerceProbeJob;
use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Routing\SuggestionApplier;
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
    seedIntent($pro->id, ['identifier' => 'other', 'state' => 'blocked', 'block_reason' => 'needs_confirmation']);
    // Applied and dismissed intents are settled: an inbox that re-shows them
    // is an inbox people stop reading.
    seedIntent($pro->id, ['identifier' => 'applied-one', 'state' => 'applied']);
    seedIntent($pro->id, ['identifier' => 'dismissed-one', 'state' => 'dismissed']);

    $response = actingAsUser($pro)->getJson('/api/routing/suggestions');

    $response->assertOk();
    expect($response->json('suggestions'))->toHaveCount(2);
});

it('emits band and preselected so the setup dialog ticks the auto band by default', function () {
    $pro = createTenant('inbox-band');
    seedIntent($pro->id, ['identifier' => 'auto-one', 'band' => 'auto']);
    seedIntent($pro->id, ['identifier' => 'suggest-one', 'band' => 'suggest']);
    seedIntent($pro->id, ['identifier' => 'legacy-one']); // pre-A.1 row, band null

    $response = actingAsUser($pro)->getJson('/api/routing/suggestions');

    $response->assertOk();
    $bySuggestion = collect($response->json('suggestions'))->keyBy('identifier');
    expect($bySuggestion['auto-one']['band'])->toBe('auto')
        ->and($bySuggestion['auto-one']['preselected'])->toBeTrue()
        ->and($bySuggestion['suggest-one']['band'])->toBe('suggest')
        ->and($bySuggestion['suggest-one']['preselected'])->toBeFalse()
        ->and($bySuggestion['legacy-one']['band'])->toBeNull()
        ->and($bySuggestion['legacy-one']['preselected'])->toBeFalse();
});

it('asks the question in the user\'s words, not the reconciler\'s', function () {
    $pro = createTenant('inbox-question');
    seedIntent($pro->id, ['state' => 'blocked', 'block_reason' => 'needs_confirmation']);

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
    // soundcloud.player, not youtube.channel: youtube is one of the six
    // surfaces the L2 verifier can check, so accepting one now parks the intent
    // at 202/verifying instead of connecting in the same request (pinned by
    // LinkVerificationLaneTest). SoundCloud is content-class with a fetch
    // capability and no verifier, which is the case THIS test is about.
    $intentId = seedIntent($pro->id, [
        'surface_key' => 'soundcloud.player', 'routing_class' => 'content',
        'identifier' => 'someone', 'canonical_url' => 'https://soundcloud.com/someone',
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
        'user_id' => $pro->id, 'surface_key' => 'soundcloud.player',
        'routing_class' => 'content', 'resource_id' => 'someone',
        'payload' => ['username' => 'someone'], 'is_active' => true,
    ]);
    $existing->save();

    // soundcloud.player for the same reason as the test above: youtube.channel
    // is verifiable now, so an accept there answers 202 before it ever reaches
    // the create-vs-matched branch this test is written to distinguish.
    $intentId = seedIntent($pro->id, [
        'surface_key' => 'soundcloud.player', 'routing_class' => 'content',
        'identifier' => 'someone', 'canonical_url' => 'https://soundcloud.com/someone',
    ]);

    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intentId}/accept")->assertOk();

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(1);
    Queue::assertNotPushed(ConnectFetchJob::class);
});

it('keeps a cap-swap incumbent that IS the same account under the legacy marker (2026-09-05, st_ali)', function () {
    // The swap used to delete the Google-seeded marker (with the scraped
    // name and avatar) and mint a bare handle-keyed duplicate.
    Queue::fake();
    $pro = createTenant('inbox-swap-same');
    $marker = new IntegrationConnection([
        'user_id' => $pro->id, 'surface_key' => 'instagram.profile', 'routing_class' => 'social',
        'resource_id' => 'instagram',
        'payload' => ['username' => 'someone', 'url' => 'https://www.instagram.com/someone', 'fullName' => 'Someone', 'source' => 'google-business'],
        'is_active' => true, 'visibility' => 'visible',
    ]);
    $marker->save();
    $intentId = seedIntent($pro->id, [
        'state' => 'blocked', 'block_reason' => 'cap_reached', 'conflicting_connection_id' => (string) $marker->id,
    ]);

    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intentId}/accept")->assertOk();

    $live = IntegrationConnection::query()->where('user_id', $pro->id)->whereNull('deleted_at')->get();
    expect($live)->toHaveCount(1)
        ->and((string) $live[0]->id)->toBe((string) $marker->id)
        ->and($live[0]->payload['fullName'])->toBe('Someone')
        ->and(DB::table('routing.source_intents')->where('id', $intentId)->value('connection_id'))->toBe((string) $marker->id);
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

    // SCALE-8: the GET reported the incumbent WITHOUT persisting it. Asserted
    // here and not after accept(), because accept()'s own write lands the same
    // value — a GET that started writing again would be invisible from there.
    expect(DB::table('routing.source_intents')->where('id', $intentId)->value('conflicting_connection_id'))
        ->toBeNull();

    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intentId}/accept")->assertOk();

    expect(IntegrationConnection::withTrashed()->whereKey($incumbent->id)->value('deleted_at'))->not->toBeNull()
        ->and(IntegrationConnection::query()->where('user_id', $pro->id)->where('surface_key', 'behance.profile')->pluck('resource_id')->all())
        ->toBe(['new-handle']);

    // Proves resolveSwapIncumbent()'s single-account-at-cap write branch
    // still persists from accept() (unchanged by SCALE-8) — apply()'s own
    // final update touches state/block_reason/connection_id/resolved_at but
    // never conflicting_connection_id, so this column is exactly what that
    // branch wrote.
    expect(DB::table('routing.source_intents')->where('id', $intentId)->value('conflicting_connection_id'))
        ->toBe($incumbent->id);
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

    // SCALE-8: the inbox GET used to persist this resolution — a write on a
    // read endpoint, and never load-bearing since accept() re-resolves
    // before it acts. The ledger row stays exactly as seeded; only the
    // rendered view reflects the lifted cap.
    expect(DB::table('routing.source_intents')->where('id', $intentId)->value('state'))->toBe('blocked');
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
    // Reserved host (RFC 2606) deliberately: SafeUrlFetcher resolves DNS before
    // it fetches, so a real fixture domain fails this test whenever that
    // domain's nameservers do — with ZERO http calls, as a domain-logic error,
    // which reads exactly like a store-detection bug. Cost a session
    // 2026-09-01 (eleventh.com, down for a window, fine again an hour later).
    // Must be a BARE example.com/net/org: .test and .invalid never resolve,
    // and neither do subdomains of the reserved domains. Shop name and id come
    // from the faked meta.json, never from the host.
    Http::fake([
        '*/meta.json' => Http::response(['id' => 2090478, 'name' => 'Beardbrand', 'currency' => 'USD'], 200),
        'https://example.org/' => Http::response('<html><head><title>Beardbrand</title></head><body>Beard care</body></html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);

    // 1. Suggest-only probe (what RoutingController::store dispatches after a
    //    Note): the reconciler writes a PROPOSED intent, no connection.
    app()->call([new CommerceProbeJob((string) $pro->id, 'https://example.org/', suggestOnly: true), 'handle']);
    $intent = DB::table('routing.source_intents')->where('user_id', $pro->id)->where('surface_key', 'shopify.store')->first();
    expect($intent)->not->toBeNull()
        ->and($intent->state)->toBe('proposed')
        // No block reason, and that is the correct answer rather than a lost
        // one: a probe origin is not isConfirmedByUser(), so PlacementPolicy
        // returns a plain Choose and StoreBrandSeeder's suggest-only downgrade
        // has nothing to downgrade. That downgrade still fires — and still
        // stamps 'needs_confirmation' — on the ACCEPT lane for a deep store
        // path, where confirmed and suggestOnly are true together
        // (CommerceProbeJob:323-324); the deep-path test below covers it.
        // Under the confidence system this row read 'below_threshold', naming
        // a bar it had fallen under. There was no bar.
        ->and($intent->block_reason)->toBeNull()
        ->and(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);
    $inbox = actingAsUser($pro)->getJson('/api/routing/suggestions')->assertOk()->json('suggestions');
    expect(collect($inbox)->firstWhere('surfaceKey', 'shopify.store')['question'])->toBe('Is this your Shopify store?');

    // 2. Accept → 202 pending; the store is built by the seeder, not the bare applier.
    Queue::fake();
    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intent->id}/accept")
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('connectionId', null);
    Queue::assertPushed(CommerceProbeJob::class, fn ($j) => $j->userId === (string) $pro->id && $j->category === 'shop' && $j->suggestOnly === false && $j->acceptedIntentId === $intent->id);
    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);

    // 3. That job (probe answer cached from step 1) places the store: connection
    //    named after the shop, storefront collection, intent applied.
    //    acceptedIntentId is what SuggestionsController::accept() actually
    //    dispatches (asserted above) and, since 2026-09-03, the ONLY thing
    //    that sets `confirmed` on the routing context — without it decide()
    //    can never mint Place, no matter how confident the projection is.
    app()->call([new CommerceProbeJob((string) $pro->id, 'https://example.org/', 'shop', acceptedIntentId: $intent->id), 'handle']);
    $connection = IntegrationConnection::query()->where('user_id', $pro->id)->where('surface_key', 'shopify.store')->first();
    expect($connection)->not->toBeNull()
        ->and($connection->payload['name'] ?? null)->toBe('Beardbrand')
        ->and(DB::table('routing.source_intents')->where('id', $intent->id)->value('state'))->toBe('applied')
        ->and(DB::table('content.collections')->where('user_id', $pro->id)->where('kind', 'storefront')->where('label', 'Beardbrand')->exists())->toBeTrue();
});

it('connects a store whose suggestion carries a deep path, rather than re-proposing it', function () {
    // The live dev row this reproduces (intent df68c566, 2026-08-24):
    // shopify.store / 23504463 / https://stali.com.au/collections/star-wars —
    // (that real URL is the incident record; the fixture below uses a reserved
    // host — see the note in the storefront-suggestion test above.)
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
        'https://example.net/' => Http::response('<html><head><title>ST. ALi</title></head><body>Coffee</body></html>', 200, ['Content-Type' => 'text/html']),
        // REACHABLE and not a product page — the shape that sets $deepPage
        // (CommerceProbeJob::probe, FI-10). A 404 here would take the
        // unreachable arm instead, which probes the ORIGIN and so never
        // records the deep canonical_url the live row carries.
        'https://example.net/collections/star-wars' => Http::response('<html><head><title>Star Wars | ST. ALi</title></head><body>A collection</body></html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);

    $deep = 'https://example.net/collections/star-wars';
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
        'canonical_url' => 'https://example.net/collections/star-wars/products/mug',
        'block_reason' => 'needs_confirmation',
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
    $discovery = new CommerceProbeJob('user-1', 'https://example.net/', 'shop');
    $accepted = new CommerceProbeJob('user-1', 'https://example.net/', 'shop', acceptedIntentId: 'intent-1');

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
        'block_reason' => 'needs_confirmation',
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

it('names a suggested store on the card, rather than showing only its numeric id', function () {
    // The user-visible symptom: a card reading "Shopify store 23504463".
    // displayName is the catalog SURFACE label ("Shopify store") and the
    // identifier slot held the shop id, so the storefront's own name — which
    // the probe already fetched from /meta.json and hands to the seeder —
    // reached the brand row but never the suggestion.
    setupContentTables();
    Cache::flush();
    $pro = createTenant('inbox-store-name');
    Http::fake([
        '*/meta.json' => Http::response(['id' => 23504463, 'name' => 'ST. ALi', 'currency' => 'AUD'], 200),
        'https://example.net/' => Http::response('<html><head><title>ST. ALi</title></head><body>Coffee</body></html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);
    app()->call([new CommerceProbeJob((string) $pro->id, 'https://example.net/', suggestOnly: true), 'handle']);

    $card = collect(actingAsUser($pro)->getJson('/api/routing/suggestions')->assertOk()->json('suggestions'))
        ->firstWhere('surfaceKey', 'shopify.store');

    // Additive: identifier keeps meaning "the id", so no client breaks.
    expect($card['identifier'])->toBe('23504463')
        ->and($card['accountName'])->toBe('ST. ALi');
});

it('leaves accountName null when the probe lane carries no name', function () {
    // The myshopify.com host-detector lane is regex-only and never fetches
    // /meta.json, so there is no name to carry. Null is the honest answer —
    // the frontend falls back to the URL host. Pinned so a later change
    // cannot start blocking nameless suggestions.
    $pro = createTenant('inbox-store-noname');
    seedIntent($pro->id, [
        'surface_key' => 'shopify.store', 'routing_class' => 'shop',
        'identifier' => 'acme', 'canonical_url' => 'https://acme.myshopify.com',
        'block_reason' => 'needs_confirmation',
    ]);

    $card = collect(actingAsUser($pro)->getJson('/api/routing/suggestions')->assertOk()->json('suggestions'))
        ->firstWhere('surfaceKey', 'shopify.store');

    expect($card['accountName'])->toBeNull();
});

it('keeps the reconciler\'s own block reason when an accepted store is refused by the cap', function () {
    // settleAcceptedIntent() fires whenever an accept did not resolve. But
    // seed() only returns early BEFORE the reconciler on a probe MISS; on a
    // Hold it reconciles first and returns 'not_placed', which is also
    // unresolved. Overwriting there replaced a real 'cap_reached' — which
    // renders as "swap it for this one?" with a Replace button — with
    // "we couldn't reach this", whose Try again can only ever fail again.
    // The host must RESOLVE in DNS. SafeUrlFetcher rejects a host that does
    // not resolve to a public IP BEFORE issuing a request, so Http::fake() is
    // never consulted, every commerce probe declines, and the reconciler never
    // runs — the intent then settles 'unservable' instead of 'cap_reached'.
    // This test sat red on `eleventh.com`, which no longer resolves (2026-09-01).
    // example.com is IANA-reserved, so it cannot lapse.
    setupContentTables();
    Cache::flush();
    $pro = createTenant('inbox-store-capped-accept');
    Http::fake([
        '*/meta.json' => Http::response(['id' => 999111, 'name' => 'Example Store', 'currency' => 'AUD'], 200),
        'https://example.com/' => Http::response('<html><head><title>Example Store</title></head><body>x</body></html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);

    // Fill the shopify.store surface to its catalog cap (max_accounts 10).
    for ($i = 0; $i < 10; $i++) {
        IntegrationConnection::query()->create([
            'user_id' => $pro->id, 'platform' => 'shopify', 'surface_key' => 'shopify.store',
            'routing_class' => 'shop', 'resource_id' => "existing-{$i}", 'payload' => [],
        ]);
    }

    $intentId = seedIntent($pro->id, [
        'surface_key' => 'shopify.store', 'routing_class' => 'shop',
        'identifier' => '999111', 'canonical_url' => 'https://example.com/',
        'block_reason' => 'needs_confirmation',
    ]);

    Queue::fake();
    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intentId}/accept")->assertStatus(202);
    $pushed = null;
    Queue::assertPushed(CommerceProbeJob::class, function ($job) use (&$pushed) {
        $pushed = $job;

        return true;
    });
    app()->call([$pushed, 'handle']);

    expect(DB::table('routing.source_intents')->where('id', $intentId)->value('block_reason'))->toBe('cap_reached');
});

it('does not let a dropped already-connected intent unmask the legacy card for its surface', function () {
    // $claimed is built from the RENDERED rows, so filtering an intent out
    // frees its surface slot and SyncFindingsBridge folds the legacy finding
    // for the same platform in behind it — the dedup bug returning through
    // the other door. The INTENT wins the slot whether or not it renders.
    $pro = createTenant('inbox-unmask');

    $ig = new IntegrationConnection([
        'surface_key' => 'instagram.profile', 'routing_class' => 'social', 'resource_id' => 'me',
        'payload' => [
            'username' => 'me',
            'syncFindings' => [[
                'platform' => 'opentable', 'category' => 'reservations', 'label' => 'OpenTable',
                'outcome' => 'conflict', 'foundUrl' => 'https://www.opentable.com/r/legacy-venue',
            ]],
        ],
        'is_active' => true,
    ]);
    $ig->user_id = $pro->id;
    $ig->save();

    // The intent's account is already connected, so the filter drops its card.
    IntegrationConnection::query()->create([
        'user_id' => $pro->id, 'platform' => 'opentable', 'surface_key' => 'opentable.reserve',
        'routing_class' => 'reservations', 'resource_id' => '12345', 'payload' => [],
    ]);
    seedIntent($pro->id, [
        'surface_key' => 'opentable.reserve', 'routing_class' => 'reservations',
        'identifier' => '12345', 'canonical_url' => 'https://www.opentable.com/r/some-venue',
    ]);

    $rows = collect(actingAsUser($pro)->getJson('/api/routing/suggestions')->assertOk()->json('suggestions'));

    expect($rows->pluck('surfaceKey'))->not->toContain('opentable.reserve');
});

// #W2-SEC-12 regression (review round 2): SuggestionsController::findIntent()
// pre-scopes by owner before apply() is ever reached, so this bypasses the
// controller and calls SuggestionApplier::apply() directly with a foreign
// user's intent — the shape a future caller without that pre-scoping would
// produce. Before the fix, this committed a connection under $attacker built
// from $owner's intent data while the final settle-update silently matched 0
// rows (wrong owner in the WHERE), leaving owner's intent 'proposed' and
// re-appliable. Asserts the whole transaction rolls back, not just that it
// throws.
it('rolls back the whole apply() transaction when the intent belongs to a different user', function () {
    $owner = createTenant('sec12-owner');
    $attacker = createTenant('sec12-attacker');

    $intentId = seedIntent($owner->id, [
        'surface_key' => 'instagram.profile', 'routing_class' => 'social',
        'identifier' => 'owner-handle', 'canonical_url' => 'https://www.instagram.com/owner-handle',
    ]);
    $intent = DB::table('routing.source_intents')->where('id', $intentId)->first();
    $surface = CompiledCatalog::surface('instagram.profile');
    expect($surface)->not->toBeNull();

    expect(fn () => app(SuggestionApplier::class)->apply($attacker, $intent, $surface))
        ->toThrow(RuntimeException::class);

    // No connection was minted under the caller who wasn't the intent's owner.
    expect(IntegrationConnection::query()->where('user_id', $attacker->id)->exists())->toBeFalse();

    // And the real owner's intent is untouched — still open, not silently settled.
    $settled = DB::table('routing.source_intents')->where('id', $intentId)->first();
    expect($settled->state)->toBe('proposed')
        ->and($settled->connection_id)->toBeNull();
});
