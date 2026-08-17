<?php

use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\LinkPoolReader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Convergence Phase 6: an ordering link with no brand home goes to the
    // links POOL (owner ruling 2A), so these cases need the content lane too.
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

// Business + food sector: online ordering is a food-business-only capability
// (2026-07-15 sector gating — partna explicitly lost access). Every test in
// this file exercises the addEntry()/entries endpoints, so this is the one
// persona the whole suite needs; no test here asserts account-type-dependent
// behaviour, so there's no other default to preserve.
function ooUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'business',
        'sector' => 'restaurant',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

/**
 * Seed $count ordering entries for $user, each a distinct store on a distinct
 * BRAND surface (convergence Phase 6 — `partna.order_link` is retired, and one
 * brand holds one store per user, so $count identical-brand rows would not be a
 * reachable state to assert a cap against).
 */
function seedOoEntries(User $user, int $count): void
{
    $surfaces = [
        'uber_eats.order', 'doordash.order', 'menulog.order', 'deliveroo.order', 'bopple.order',
        'ordermate.order', 'square.order', 'chownow.order', 'grubhub.order', 'wolt.order',
    ];

    for ($i = 1; $i <= $count; $i++) {
        $url = "https://www.ubereats.com/store/seed-{$i}";
        $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);
        IntegrationConnection::create([
            'user_id' => $user->id,
            'platform' => $surfaces[($i - 1) % count($surfaces)],
            'resource_id' => $rid,
            'payload' => ['url' => $url, 'provider' => 'custom', 'source' => 'manual'],
            'is_active' => true,
            'last_refresh_status' => 'ok',
        ]);
    }
}

// ── addEntry: async 202 path ──────────────────────────────────────────────────

it('addEntry returns 202, sends no HTTP, and dispatches EnrichLinkCardJob + MenuFetchJob', function () {
    Queue::fake();
    Http::fake();

    $user = ooUser('oo1');

    $res = actingAsUser($user)
        ->postJson('/api/platforms/online-ordering/entries', ['url' => 'https://www.ubereats.com/store/x']);

    $res->assertStatus(202)->assertJsonPath('status', 'pending');
    Http::assertNothingSent();
    Queue::assertPushed(EnrichLinkCardJob::class);
    Queue::assertPushed(MenuFetchJob::class);
});

it('addEntry 202 body includes a statusUrl', function () {
    Queue::fake();
    Http::fake();

    $user = ooUser('oo2');

    actingAsUser($user)
        ->postJson('/api/platforms/online-ordering/entries', ['url' => 'https://www.doordash.com/store/abc'])
        ->assertStatus(202)
        ->assertJsonStructure(['statusUrl']);
});

it('addEntry writes the connection row on the BRAND surface with status pending', function () {
    Queue::fake();
    Http::fake();

    $user = ooUser('oo3');

    actingAsUser($user)
        ->postJson('/api/platforms/online-ordering/entries', ['url' => 'https://www.ubereats.com/store/y'])
        ->assertStatus(202);

    // Convergence Phase 6: an Uber Eats link is an uber_eats.order row, not a
    // `partna.order_link` one. Pinned on surface_key (the identity column) AND
    // routing_class, because routing_class is the axis every read in the family
    // now scopes on — a row that landed with the right surface and no class
    // would be invisible to every one of them.
    $conn = IntegrationConnection::where('user_id', $user->id)
        ->where('routing_class', 'ordering')
        ->first();

    expect($conn)->not->toBeNull();
    expect($conn->surface_key)->toBe('uber_eats.order');
    expect($conn->last_refresh_status)->toBe('pending');
    expect($conn->resource_id)->toBe('order-'.substr(sha1('https://www.ubereats.com/store/y'), 0, 16));

    expect(IntegrationConnection::where('user_id', $user->id)
        ->where('surface_key', 'partna.order_link')->exists())->toBeFalse();
});

it('addEntry sends a link with no ordering brand to the links pool', function () {
    Queue::fake();
    Http::fake();

    $user = ooUser('oo3b');
    // A pool item needs a section, which hangs off the SITE — a siteless user
    // silently gets nothing.
    $site = new Site(['subdomain' => 'oo3b', 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();
    $user->refresh();

    $res = actingAsUser($user)
        ->postJson('/api/platforms/online-ordering/entries', ['url' => 'https://tuckshop.example.com/order'])
        ->assertStatus(202);

    // Owner ruling 2A: preserved as a link, not written to a retired surface
    // and not dropped. `entries` is legitimately empty — it is not an ordering
    // entry any more, and the response says where it went instead.
    $res->assertJsonPath('routedTo.pool', 'custom_links')
        ->assertJsonPath('entries', []);

    expect(IntegrationConnection::where('user_id', $user->id)->count())->toBe(0);
    expect(app(LinkPoolReader::class)->cards($user))->toHaveCount(1);
});

// ── addEntry: MAX_ENTRIES enforced synchronously ──────────────────────────────

it('still enforces MAX_ENTRIES synchronously — 422 before any job is pushed', function () {
    $user = ooUser('oo4');
    // Seed 10 entries (the limit) BEFORE faking the queue: seeding ordering
    // rows now dispatches MenuFetchJob from the observer (F17), and this
    // test is about the 422 path pushing nothing.
    seedOoEntries($user, 10);
    Queue::fake();

    $res = actingAsUser($user)
        ->postJson('/api/platforms/online-ordering/entries', ['url' => 'https://www.doordash.com/store/z']);

    $res->assertStatus(422);
    Queue::assertNotPushed(EnrichLinkCardJob::class);
    Queue::assertNotPushed(MenuFetchJob::class);
});

// ── addEntry: merge-on-add stays sync ────────────────────────────────────────

it('merge-on-add dispatches EnrichLinkCardJob for the existing row', function () {
    Queue::fake();
    Http::fake();

    $user = ooUser('oo5');
    $url = 'https://www.ubereats.com/store/samepath?diningMode=PICKUP';
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);

    // Plant an existing entry for the same store (delivery variant).
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'uber_eats.order',
        'resource_id' => $rid,
        'payload' => ['url' => $url, 'provider' => 'custom', 'source' => 'manual'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    // Add the delivery URL for the same store (same path, different diningMode).
    actingAsUser($user)
        ->postJson('/api/platforms/online-ordering/entries', [
            'url' => 'https://www.ubereats.com/store/samepath?diningMode=DELIVERY',
        ])
        ->assertStatus(202);

    // EnrichLinkCardJob must be dispatched for the merge-targeted row.
    Queue::assertPushed(EnrichLinkCardJob::class);
});

// ── entryStatus: poll endpoint ────────────────────────────────────────────────

it('entryStatus returns pending while the enrichment job is running', function () {
    $user = ooUser('oo6');
    $url = 'https://www.ubereats.com/store/poll1';
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'uber_eats.order',
        'resource_id' => $rid,
        'payload' => ['url' => $url],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    actingAsUser($user)
        ->getJson("/api/platforms/online-ordering/entries/{$rid}/status")
        ->assertOk()
        ->assertJsonPath('status', 'pending');
});

it('entryStatus returns ready with entries when enrichment completes', function () {
    $user = ooUser('oo7');
    $url = 'https://www.ubereats.com/store/poll2';
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'uber_eats.order',
        'resource_id' => $rid,
        'payload' => ['url' => $url, 'name' => 'My Store'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)
        ->getJson("/api/platforms/online-ordering/entries/{$rid}/status")
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonStructure(['entries']);
});

it('entryStatus returns 404 for a resource the user does not own', function () {
    $owner = ooUser('oo8a');
    $other = ooUser('oo8b');

    $url = 'https://www.ubereats.com/store/owned';
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);

    IntegrationConnection::create([
        'user_id' => $owner->id,
        'platform' => 'uber_eats.order',
        'resource_id' => $rid,
        'payload' => ['url' => $url],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    actingAsUser($other)
        ->getJson("/api/platforms/online-ordering/entries/{$rid}/status")
        ->assertStatus(404);
});
