<?php

use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

// ── preview: decides and explains, writes nothing ────────────────────────────

it('previews a recognised link without writing anything', function () {
    $pro = createTenant('routing-preview');

    $response = actingAsUser($pro)->postJson('/api/routing/preview', [
        'url' => 'https://www.instagram.com/someone/?hl=en',
    ]);

    $response->assertOk()
        ->assertJsonPath('verdict', 'place')
        ->assertJsonPath('routedTo.surfaceKey', 'instagram.profile')
        ->assertJsonPath('routedTo.identifier', 'someone')
        // The locale param is gone from the canonical form.
        ->assertJsonPath('canonicalUrl', 'https://www.instagram.com/someone');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0)
        ->and(DB::table('routing.source_intents')->count())->toBe(0)
        ->and(DB::table('routing.link_observations')->count())->toBe(0);
});

it('explains an unrecognised link rather than failing', function () {
    $pro = createTenant('routing-unknown');

    // Verdict::Note is "keep as a link item, never dropped" — so the preview
    // must NOT read as a block. The dashboard disables submit whenever
    // blockReason is set; an unrecognised-but-healthy URL has to stay addable.
    actingAsUser($pro)->postJson('/api/routing/preview', ['url' => 'https://joesplumbing.com.au/'])
        ->assertOk()
        ->assertJsonPath('verdict', 'note')
        ->assertJsonPath('routedTo', null)
        ->assertJsonPath('blockReason', null)
        ->assertJsonPath('explanation', "We'll keep this as a link on your site.");
});

it('previews a below-auto-threshold link without a blockReason so it can be submitted for review', function () {
    $pro = createTenant('routing-choose-preview');

    // fresha.com/a/… projects at 75, under booking's auto bar (80) but over
    // suggest (55) → Choose. A Choose is a question for the review inbox, not
    // a block — blockReason non-null here would disable the Add button and the
    // question could never be asked.
    actingAsUser($pro)->postJson('/api/routing/preview', ['url' => 'https://www.fresha.com/a/some-salon-abc123'])
        ->assertOk()
        ->assertJsonPath('verdict', 'choose')
        ->assertJsonPath('routedTo.surfaceKey', 'fresha.book')
        ->assertJsonPath('blockReason', null);
});

// ── store: the full observe → project → place → reconcile pass ───────────────

it('connects a confident link and records the whole decision', function () {
    $pro = createTenant('routing-connect');

    $response = actingAsUser($pro)->postJson('/api/routing/links', [
        'url' => 'https://www.instagram.com/someone',
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('outcome', 'connected')
        ->assertJsonPath('verdict', 'place');

    $connection = IntegrationConnection::query()->where('user_id', $pro->id)->first();
    expect($connection)->not->toBeNull()
        ->and($connection->surface_key)->toBe('instagram.profile')
        ->and($connection->routing_class)->toBe('social')
        ->and($connection->resource_id)->toBe('someone')
        // The generated legacy alias still answers for old readers.
        ->and($connection->platform)->toBe('instagram');

    $intent = DB::table('routing.source_intents')->first();
    expect($intent->state)->toBe('applied')
        ->and($intent->connection_id)->toBe($connection->id)
        ->and($intent->origin)->toBe('paste');

    $observation = DB::table('routing.link_observations')->first();
    expect($observation->verdict)->toBe('place')
        ->and($observation->surface_key)->toBe('instagram.profile')
        ->and($observation->registrable_key)->toBe('instagram.com');
});

it('is idempotent — the same link twice yields one connection', function () {
    $pro = createTenant('routing-idempotent');

    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://x.com/someuser'])->assertStatus(202);
    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://X.com/someuser/'])->assertStatus(202);

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(1)
        ->and(DB::table('routing.source_intents')->count())->toBe(1);
});

it('keeps an unrecognised link as a real link card, not a vanished pending', function () {
    Queue::fake();
    $pro = createTenant('routing-note');

    // Verdict::Note's promise made real: the URL lands as the SAME custom-link
    // row the legacy endpoint writes, so the Links list and the sitepage show
    // it. Before this branch existed the endpoint said 202 "pending" and the
    // URL went nowhere.
    $response = actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://joesplumbing.com.au/']);

    $response->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('outcome', 'link')
        ->assertJsonPath('verdict', 'note')
        ->assertJsonPath('blockReason', null);

    // No platform connection — but exactly one custom link card.
    expect(IntegrationConnection::query()->where('user_id', $pro->id)->where('platform', '!=', 'custom')->count())->toBe(0);
    $card = IntegrationConnection::query()->where('user_id', $pro->id)->where('platform', 'custom')->first();
    expect($card)->not->toBeNull()
        ->and($card->resource_kind)->toBe('link')
        ->and($card->payload['url'])->toBe($response->json('canonicalUrl'));

    // Visible where the dashboard and sitepage actually read links from.
    $links = actingAsUser($pro)->getJson('/api/platforms/custom/links')->assertOk()->json('links');
    expect($links)->toHaveCount(1)
        ->and($links[0]['name'])->toBe('joesplumbing.com.au');

    // The enrichment job upgrades the minimal card, same as a legacy add.
    Queue::assertPushed(EnrichLinkCardJob::class, fn ($j) => $j->platform === 'custom');

    // Still observed: an unmatched link is exactly what the rot report needs.
    expect(DB::table('routing.link_observations')->count())->toBe(1);
});

it('adding the same unrecognised link twice keeps one card and still answers outcome link', function () {
    Queue::fake();
    $pro = createTenant('routing-note-dedupe');

    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://joesplumbing.com.au/'])->assertStatus(202);
    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://joesplumbing.com.au/'])
        ->assertStatus(202)
        ->assertJsonPath('outcome', 'link');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->where('platform', 'custom')->count())->toBe(1);
});

it('answers a structured 422 when the link cap is full', function () {
    Queue::fake();
    $pro = createTenant('routing-note-cap');

    foreach (range(1, 20) as $i) {
        IntegrationConnection::create([
            'user_id' => $pro->id,
            'platform' => 'custom',
            'resource_id' => "link-capfill{$i}",
            'resource_kind' => 'link',
            'payload' => ['kind' => 'link', 'url' => "https://example{$i}.com.au"],
            'is_active' => true,
            'last_refresh_status' => 'ok',
        ]);
    }

    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://joesplumbing.com.au/'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'link_cap_reached');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->where('platform', 'custom')->count())->toBe(20);
});

it('sends a below-auto-threshold link to review rather than connecting or noting it', function () {
    $pro = createTenant('routing-review');

    // fresha.com/a/… projects at 75 — under booking's auto bar (80), over
    // suggest (55) → Choose: an intent for the suggestions inbox.
    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://www.fresha.com/a/some-salon-abc123'])
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('outcome', 'review')
        ->assertJsonPath('verdict', 'choose');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0)
        ->and(DB::table('routing.source_intents')->where('user_id', $pro->id)->value('state'))->toBe('proposed');
});

it('never connects a spoofed brand host', function () {
    Queue::fake();
    $pro = createTenant('routing-spoof');

    actingAsUser($pro)->postJson('/api/routing/links', [
        'url' => 'https://opentable.evil.com/restaurant/profile/12345',
    ])->assertStatus(202)->assertJsonPath('verdict', 'note');

    // The spoof registers as evil.com — an unknown domain. It may become a
    // plain link card (the user pasted it), but NEVER an OpenTable connection.
    expect(IntegrationConnection::query()->where('user_id', $pro->id)->where('platform', '!=', 'custom')->count())->toBe(0);
});

it('refuses to write own-infrastructure links', function () {
    $pro = createTenant('routing-owninfra');

    // Own infrastructure is unroutable (Verdict::Reject's own list) — it can
    // never become a link card, so unlike an unrecognised business URL it
    // keeps a blockReason and genuinely blocks.
    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://dev-api.partna.au/api/internal/x'])
        ->assertStatus(202)
        ->assertJsonPath('verdict', 'reject')
        ->assertJsonPath('blockReason', 'own-infra');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);
});

// ── gates ────────────────────────────────────────────────────────────────────

it('gates a reservations link for an account that cannot use reservations', function () {
    Queue::fake();
    // A non-food BUSINESS: can_use_reservations is false, can_use_booking true.
    $pro = createTenant('routing-gate', ['account_type' => 'business', 'sector' => 'hair_beauty']);

    $response = actingAsUser($pro)->postJson('/api/routing/links', [
        'url' => 'https://www.opentable.com.au/restaurant/profile/12345',
    ]);

    // Denied never means dropped — a gate demotes to Note, and a Note never
    // blocks the add (blockReason stays a Reject-only signal). The user still
    // gets their link, as a plain card; the WHY lands on the observation.
    $response->assertStatus(202)
        ->assertJsonPath('verdict', 'note')
        ->assertJsonPath('outcome', 'link')
        ->assertJsonPath('blockReason', null)
        ->assertJsonPath('routedTo', null);

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->where('platform', '!=', 'custom')->count())->toBe(0)
        ->and(IntegrationConnection::query()->where('user_id', $pro->id)->where('platform', 'custom')->count())->toBe(1)
        ->and(DB::table('routing.link_observations')->where('block_reason', 'gate')->count())->toBe(1);
});

it('lets a direct paste supersede a tombstone — the refusal only binds re-imports', function () {
    // Owner decision 2026-07-28: tombstones suppress scan/suggestion origins;
    // the user pasting the URL again is the counter-instruction. Scan
    // suppression is pinned in TombstoneResurrectionTest.
    $pro = createTenant('routing-tombstone');

    DB::table('routing.item_tombstones')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $pro->id,
        'source_ref' => 'x.profile:someuser',
        'scope' => 'this_source',
        'created_at' => now(),
    ]);

    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://x.com/someuser'])
        ->assertStatus(202)
        ->assertJsonPath('verdict', 'place')
        ->assertJsonPath('outcome', 'connected');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(1)
        ->and(DB::table('routing.item_tombstones')->where('user_id', $pro->id)->count())->toBe(0);
});

// ── validation ───────────────────────────────────────────────────────────────

it('rejects an empty or oversized url with a clear message', function () {
    $pro = createTenant('routing-validation');

    actingAsUser($pro)->postJson('/api/routing/links', ['url' => ''])->assertStatus(422);
    actingAsUser($pro)->postJson('/api/routing/links', ['url' => str_repeat('a', 3000)])->assertStatus(422);
});

it('requires authentication', function () {
    $this->postJson('/api/routing/links', ['url' => 'https://x.com/someone'])->assertStatus(401);
});
