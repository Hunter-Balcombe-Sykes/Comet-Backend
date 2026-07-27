<?php

use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\DB;

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

    actingAsUser($pro)->postJson('/api/routing/preview', ['url' => 'https://joesplumbing.com.au/'])
        ->assertOk()
        ->assertJsonPath('verdict', 'note')
        ->assertJsonPath('routedTo', null);
});

// ── store: the full observe → project → place → reconcile pass ───────────────

it('connects a confident link and records the whole decision', function () {
    $pro = createTenant('routing-connect');

    $response = actingAsUser($pro)->postJson('/api/routing/links', [
        'url' => 'https://www.instagram.com/someone',
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('status', 'ok')
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

it('keeps an unrecognised link as a note without creating a connection', function () {
    $pro = createTenant('routing-note');

    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://joesplumbing.com.au/'])
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('verdict', 'note');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);
    // Still observed: an unmatched link is exactly what the rot report needs.
    expect(DB::table('routing.link_observations')->count())->toBe(1);
});

it('never connects a spoofed brand host', function () {
    $pro = createTenant('routing-spoof');

    actingAsUser($pro)->postJson('/api/routing/links', [
        'url' => 'https://opentable.evil.com/restaurant/profile/12345',
    ])->assertStatus(202)->assertJsonPath('verdict', 'note');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);
});

it('refuses to write own-infrastructure links', function () {
    $pro = createTenant('routing-owninfra');

    actingAsUser($pro)->postJson('/api/routing/links', ['url' => 'https://dev-api.partna.au/api/internal/x'])
        ->assertStatus(202)
        ->assertJsonPath('verdict', 'note');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);
});

// ── gates ────────────────────────────────────────────────────────────────────

it('gates a reservations link for an account that cannot use reservations', function () {
    // A non-food BUSINESS: can_use_reservations is false, can_use_booking true.
    $pro = createTenant('routing-gate', ['account_type' => 'business', 'sector' => 'hair_beauty']);

    $response = actingAsUser($pro)->postJson('/api/routing/links', [
        'url' => 'https://www.opentable.com.au/restaurant/profile/12345',
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('verdict', 'note')
        ->assertJsonPath('blockReason', 'gate');

    // Denied never means dropped — the user still gets their link, and we
    // recorded exactly why it was not connected.
    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0)
        ->and(DB::table('routing.link_observations')->where('block_reason', 'gate')->count())->toBe(1);
});

it('honours a tombstone — a refused link never comes back', function () {
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
        ->assertJsonPath('verdict', 'reject');

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);
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
