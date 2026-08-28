<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function integrationsMetaUser(string $h): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('returns sync metadata per platform in one call', function () {
    $user = integrationsMetaUser('metauser');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'chan-1',
        'payload' => ['handle' => 'metauser'], 'is_active' => true,
        'last_refreshed_at' => now()->subHours(2), 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'spotify', 'resource_id' => 'artist-1',
        'payload' => ['name' => 'Meta'], 'is_active' => true,
        'last_refresh_status' => 'error', 'last_refresh_error' => 'internal scraper detail',
    ]);

    $response = actingAsUser($user)->getJson('/api/platforms/meta')
        ->assertOk()
        ->assertJsonPath('platforms.youtube.last_refresh_status', 'ok')
        ->assertJsonPath('platforms.youtube.has_refresh_error', false)
        ->assertJsonPath('platforms.spotify.has_refresh_error', true);

    // Error text stays server-side — only the boolean/status crosses the API.
    expect($response->json('platforms.spotify'))->not->toHaveKey('last_refresh_error');
});

it('keeps the most recently refreshed row per platform', function () {
    $user = integrationsMetaUser('multirow');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shopify.store', 'resource_id' => 'store-old',
        'payload' => ['name' => 'Old'], 'is_active' => true,
        'last_refreshed_at' => now()->subDays(3), 'last_refresh_status' => 'error',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shopify.store', 'resource_id' => 'store-new',
        'payload' => ['name' => 'New'], 'is_active' => true,
        'last_refreshed_at' => now()->subHour(), 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->getJson('/api/platforms/meta')
        ->assertOk()
        ->assertJsonPath('platforms.shopify.last_refresh_status', 'ok');
});

// SEM-1: two never-refreshed connections on the same multi-account platform
// (e.g. two YouTube channels) both land in the NULLS-LAST tail with no
// timestamp to order by. The old code kept an arbitrary "first" row, so
// has_refresh_error could flip between requests depending on scan order.
// The fix aggregates ANY-across-connections for is_active/has_refresh_error
// and MAX for last_refreshed_at, so the result no longer depends on which
// row the DB happens to return first.
it('deterministically aggregates status across tied never-refreshed connections', function () {
    $user = integrationsMetaUser('multiacct');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'chan-a',
        'payload' => ['handle' => 'a'], 'is_active' => true,
        'last_refreshed_at' => null, 'last_refresh_status' => null,
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'chan-b',
        'payload' => ['handle' => 'b'], 'is_active' => false,
        'last_refreshed_at' => null, 'last_refresh_status' => 'error',
    ]);

    // Run it a few times — with the old first-row-wins logic this would be
    // nondeterministic; the aggregate must be stable every time.
    foreach (range(1, 3) as $_) {
        actingAsUser($user)->getJson('/api/platforms/meta')
            ->assertOk()
            ->assertJsonPath('platforms.youtube.is_active', true) // ANY: chan-a is active
            ->assertJsonPath('platforms.youtube.has_refresh_error', true) // ANY: chan-b errored
            ->assertJsonPath('platforms.youtube.last_refreshed_at', null); // MAX of two nulls
    }
});

// The availability map folds BOTH gates — staff-managed feature availability
// AND the descriptor's requiresCapability predicate — because the consumer's
// question is singular: "can I connect this?". Before 2026-08-04 the
// capability half was enforced only at connect time (a 403 from
// IntegrationConnectionPolicy), so the dashboard offered platforms whose
// connect was guaranteed to fail.
it('reports capability-gated platforms as unavailable', function () {
    // A non-food business: can_use_reservations is false (NULL sector reads
    // as not-food), so the reservations family must read unavailable.
    $business = User::create([
        'handle' => 'bizmeta', 'handle_lc' => 'bizmeta', 'display_name' => 'Bizmeta',
        'first_name' => 'Bizmeta',
        'account_type' => 'business', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'bizmeta@example.com',
    ]);

    $response = actingAsUser($business)->getJson('/api/platforms/meta')->assertOk();
    expect($response->json('availability.opentable'))->toBeFalse()
        ->and($response->json('availability.resdiary'))->toBeFalse()
        ->and($response->json('availability.nowbookit'))->toBeFalse()
        // Ungated platforms stay available.
        ->and($response->json('availability.youtube'))->toBeTrue();

    // A partna account has can_use_reservations, so the same keys read true.
    $partna = integrationsMetaUser('partnameta');
    $response = actingAsUser($partna)->getJson('/api/platforms/meta')->assertOk();
    expect($response->json('availability.opentable'))->toBeTrue();
});

it('scopes metadata to the authenticated user', function () {
    $user = integrationsMetaUser('lonely');
    $other = integrationsMetaUser('busy');
    IntegrationConnection::create([
        'user_id' => $other->id, 'platform' => 'youtube', 'resource_id' => 'chan-x',
        'payload' => ['handle' => 'busy'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $response = actingAsUser($user)->getJson('/api/platforms/meta')->assertOk();

    expect($response->json('platforms'))->toBe([]);
});

/**
 * The item-count query joins four content.* tables. Production has NO content
 * schema at all (CLAUDE.md), so there the catch fires on every call and every
 * platform reads item_count 0 — indistinguishable from a genuinely empty
 * account, and invisible in Nightwatch because the swallow was silent.
 *
 * This test file deliberately never calls setupContentTables(), so the join
 * faults here exactly as it does in prod. Fail-open stays; the silence does not.
 */
it('logs and reports the swallowed item-count failure instead of silently serving zeroes', function () {
    $user = integrationsMetaUser('metaswallow');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'chan-swallow',
        'payload' => ['handle' => 'metaswallow'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    Exceptions::fake();
    Log::spy();

    actingAsUser($user)->getJson('/api/platforms/meta')
        ->assertOk()
        // Fail-open is the REQUIRED behaviour, not an accident — a meta
        // endpoint must not 500 because the content lane is absent.
        ->assertJsonPath('platforms.youtube.item_count', 0);

    // withArgs BEFORE once(): Mockery applies the count to the expectation as
    // matched, and this request logs a second, unrelated warning.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'platforms.meta.item_counts_unavailable'
            && $context['user_id'] === $user->id
            && $context['exception'] === QueryException::class
            && is_string($context['error']) && $context['error'] !== '')
        ->once();

    Exceptions::assertReported(QueryException::class);
});

/**
 * The happy path had NO coverage at all before this — item_count shipped
 * 2026-08-27 (plan 04 step B) with only the fail-open branch reachable in
 * tests, which is how a permanently-zero count stayed invisible. A
 * CHARACTERIZATION test: it passes against the code as it stands. Its job is
 * to stop the join silently reading 0 the way the swallowed catch did.
 */
it('counts the distinct items a connection actually feeds', function () {
    setupContentTables();

    $user = integrationsMetaUser('metacount');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'chan-count',
        'payload' => ['handle' => 'metacount'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $pg = DB::connection('pgsql');
    $sourceId = (string) Str::uuid();
    $pg->table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $user->id, 'kind' => 'connection',
        'connection_id' => $connection->id, 'priority' => 100,
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    // Two source_items on ONE item — the count is DISTINCT on i.id, so this
    // also pins that a re-ingested record does not inflate the number.
    $itemId = addItem($user->id, 'video', 'Counted');
    foreach (['yt:vid-1', 'yt:vid-1-dupe'] as $coord) {
        $pg->table('content.source_items')->insert([
            'id' => (string) Str::uuid(), 'source_id' => $sourceId, 'coord' => $coord,
            'kind' => 'video', 'projector_version' => 1,
            'first_seen_at' => now()->toDateTimeString(), 'last_seen_at' => now()->toDateTimeString(),
        ]);
        $pg->table('content.item_anchors')->insert([
            'coord' => $coord, 'user_id' => $user->id, 'item_id' => $itemId,
            'bound_at' => now()->toDateTimeString(),
        ]);
    }

    // No negative log assertion here on purpose: Mockery matches the FULL
    // argument list, so shouldNotHaveReceived('warning', ['platforms.meta.…'])
    // would match nothing and pass whatever the code did. The count itself is
    // the honest proof — the swallowed branch yields 0, so asserting 1 is what
    // shows the join ran.
    actingAsUser($user)->getJson('/api/platforms/meta')
        ->assertOk()
        ->assertJsonPath('platforms.youtube.item_count', 1);
});
