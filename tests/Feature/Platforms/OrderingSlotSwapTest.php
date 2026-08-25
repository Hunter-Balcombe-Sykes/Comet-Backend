<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Platforms\LinkRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owner, 2026-08-19. A harvest that found a SECOND store for an ordering brand
 * used to file it as a links-pool card — a public link the owner never asked
 * for, with no way to say "actually, use that one instead". It now records the
 * cap-blocked intent the suggestions inbox renders as **Swap**, naming the
 * incumbent it would replace.
 *
 * These pin the legacy lane (LinkRouter::seedOnlineOrdering) because that is
 * what the Google Business and Instagram harvests still come through; when they
 * move onto LinkRoutingService the reconciler answers the same way natively
 * (SourceReconciler::capReached), and this file goes with the router.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

function orderingUser(string $handle): User
{
    $user = User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        // business + food is what grants can_use_online_ordering.
        'account_type' => 'business',
        'sector' => 'restaurant',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);

    $site = new Site(['subdomain' => $handle, 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
}

it('offers a swap instead of pooling when the ordering brand already holds a different store', function () {
    $user = orderingUser('ord-swap');

    $first = app(LinkRouter::class)->routeOrdering($user, 'https://www.ubereats.com/au/store/first/abc');
    expect($first->outcome)->toBe('seeded');

    $incumbent = IntegrationConnection::query()->where('user_id', $user->id)->firstOrFail();

    $second = app(LinkRouter::class)->routeOrdering($user, 'https://www.ubereats.com/au/store/second/xyz');

    // Not connected — the brand keeps its one store.
    expect($second->outcome)->not->toBe('seeded')
        ->and(IntegrationConnection::query()->where('user_id', $user->id)->count())->toBe(1);

    // Not published as a link either: the pool is untouched.
    expect(DB::connection('pgsql')->table('content.items')->count())->toBe(0);

    // It is a Swap offer, naming the row it would replace.
    $intent = DB::connection('pgsql')->table('routing.source_intents')
        ->where('user_id', $user->id)->first();
    expect($intent)->not->toBeNull()
        ->and($intent->state)->toBe('blocked')
        ->and($intent->block_reason)->toBe('cap_reached')
        ->and($intent->routing_class)->toBe('ordering')
        ->and($intent->conflicting_connection_id)->toBe($incumbent->id)
        ->and($intent->canonical_url)->toContain('second');
});

it('re-syncing the SAME store is not a second store', function () {
    $user = orderingUser('ord-resync');

    app(LinkRouter::class)->routeOrdering($user, 'https://www.ubereats.com/au/store/only/abc');
    $again = app(LinkRouter::class)->routeOrdering($user, 'https://www.ubereats.com/au/store/only/abc');

    expect($again->outcome)->toBe('seeded')
        ->and(IntegrationConnection::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(DB::connection('pgsql')->table('routing.source_intents')->count())->toBe(0);
});

it('states the same block once when a nightly re-sync finds the second store again', function () {
    // Idempotence matters more here than anywhere: this runs on every sync.
    $user = orderingUser('ord-repeat');

    app(LinkRouter::class)->routeOrdering($user, 'https://www.ubereats.com/au/store/first/abc');
    app(LinkRouter::class)->routeOrdering($user, 'https://www.ubereats.com/au/store/second/xyz');
    app(LinkRouter::class)->routeOrdering($user, 'https://www.ubereats.com/au/store/second/xyz');

    expect(DB::connection('pgsql')->table('routing.source_intents')->count())->toBe(1);
});

it('does not resurrect an ordering store the owner disconnected', function () {
    // SEM-2: the tombstone is built directly (not via a real seed-then-delete
    // round trip) so this test's setup can't itself be blocked by a broken
    // wasDisconnected() — see the mutation table in the plan, row 5. Same url
    // as the tombstone: sharper than a different url, because it proves
    // updateOrCreate() would have INSERTED a second live row past the
    // tombstone (same surface_key + resource_id, which is url-derived here)
    // rather than updating the trashed one.
    $user = orderingUser('ord-tombstone');
    $url = 'https://www.ubereats.com/au/store/ghost/abc';

    $tombstone = new IntegrationConnection([
        'surface_key' => 'uber_eats.order', 'routing_class' => 'ordering',
        'resource_id' => 'order-'.substr(sha1(strtolower($url)), 0, 16),
        'payload' => ['url' => $url, 'provider' => 'Uber Eats', 'name' => 'Uber Eats', 'source' => 'auto'],
        'is_active' => true,
    ]);
    $tombstone->user_id = $user->id;
    // No ->platform assignment, same reason as the M-6 test above: 'uber_eats'
    // is not a legacy platform key and the mutator would overwrite surface_key.
    $tombstone->save();
    $tombstone->delete();

    $result = app(LinkRouter::class)->routeOrdering($user, $url);

    expect($result->outcome)->toBe('custom');
    expect($result->handled)->toBeTrue();
    expect($result->unmatched)->toHaveCount(1);
    expect(IntegrationConnection::query()->where('user_id', $user->id)->count())->toBe(0);
    expect(IntegrationConnection::onlyTrashed()->where('user_id', $user->id)->count())->toBe(1);
    // Distinguishes the tombstone outcome from a cap-block: recordCapBlock
    // must not have fired on this path.
    expect(DB::connection('pgsql')->table('routing.source_intents')->where('user_id', $user->id)->count())->toBe(0);
});
