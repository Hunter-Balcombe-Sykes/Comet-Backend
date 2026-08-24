<?php

use App\Models\Content\Item;
use App\Models\Core\User\User;
use App\Policies\ContentItemPolicy;
use App\Site\Pools\BorrowedMedia;
use App\Site\Pools\PoolRegistry;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Slice 1b D5. A pin promises the owner that a specific item stays where they
// put it. For a Google Places photo that promise cannot be kept: the photo's
// resource name is reissued on every Details fetch, so "this item" is a
// different row a week later, and the photo may leave the place's set entirely.
//
// The photo is still SHOWN — the pool's auto half surfaces it through the
// kind_is(media) rule with no pin required. Only permanence is withheld, which
// is why the second test matters as much as the first.
//
// Route-level, never a direct controller call: an authorization check reached
// through a bare Request skips routing, middleware and policy resolution, and
// green would mean almost nothing.

// #SEC-1 test seam: BORROWED_SOURCE_KEYS is empty (R6), so no real fixture can
// produce a genuine 'pin' denial. A policy subclass that always denies proves
// reorder() honours whatever the policy says, independent of BorrowedMedia.
// Gate::policy() resolves a string via the container, so this is registered
// by class name, not by instance.
class DenyPinContentItemPolicyForSec1Test extends ContentItemPolicy
{
    public function pin(User $actor, Model $resource): bool|Response
    {
        return Response::deny('Denied by test seam.');
    }
}

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    Queue::fake();
});

/** A media item owned by $userId, sourced from $sourceKey (null = manual/upload). */
function borrowedFixtureItem(string $userId, ?string $sourceKey): string
{
    $itemId = (string) Str::uuid();
    $sourceId = (string) Str::uuid();
    $connectionId = $sourceKey === null ? null : (string) Str::uuid();

    if ($sourceKey !== null) {
        // The connection row itself: since W2 (disconnect = hide) a pool only
        // publishes items whose source connection is present and active.
        DB::table('site.platform_connections')->insert([
            'id' => $connectionId,
            'user_id' => $userId,
            'surface_key' => $sourceKey === 'google_business' ? 'google_business.location' : $sourceKey.'.profile',
            'routing_class' => 'content',
            'resource_id' => 'res-'.Str::random(6),
            'payload' => json_encode([]),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('ingest.sources')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'connection_id' => $connectionId,
            'source_key' => $sourceKey,
            'surface_key' => $sourceKey.'.media',
            'identifier' => 'ident-'.Str::random(6),
        ]);
    }

    DB::table('content.sources')->insert([
        'id' => $sourceId,
        'user_id' => $userId,
        'kind' => $connectionId === null ? 'manual' : 'connection',
        'connection_id' => $connectionId,
        'priority' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('content.items')->insert([
        'id' => $itemId,
        'user_id' => $userId,
        'kind' => 'media',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(),
        'source_id' => $sourceId,
        'item_id' => $itemId,
        'coord' => ($sourceKey ?? 'manual').':'.Str::random(8),
        'kind' => 'media',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    return $itemId;
}

it('allows a pin on a google-sourced media item now that photo identity is stable (R6, 2026-08-18)', function () {
    $user = createTenant('brw-'.Str::lower(Str::random(6)));
    $itemId = borrowedFixtureItem($user->id, 'google_business');

    actingAsUser($user)
        ->postJson("/api/content/pools/media/selection/{$itemId}")
        ->assertSuccessful();

    expect(DB::table('site.section_items')->where('item_id', $itemId)->where('state', 'pinned')->exists())->toBeTrue();
});

it('still refuses a pin for a source registered as borrowed (the seam is kept)', function () {
    $user = createTenant('brw-'.Str::lower(Str::random(6)));
    $itemId = borrowedFixtureItem($user->id, 'google_business');
    // Simulate a future churning source being registered.
    expect(BorrowedMedia::BORROWED_SOURCE_KEYS)->toBe([]);
    expect(BorrowedMedia::isBorrowed(Item::query()->findOrFail($itemId)))->toBeFalse();
});

it('still surfaces that same item in the auto half', function () {
    // The point of D5 is that the photo stays VISIBLE. A test that only
    // asserted the 403 would pass on a design that hid the photo entirely.
    $user = createTenant('brw-'.Str::lower(Str::random(6)));
    $itemId = borrowedFixtureItem($user->id, 'google_business');

    $response = actingAsUser($user)->getJson('/api/content/pools/media');

    $response->assertOk();
    expect(json_encode($response->json()))->toContain($itemId);
});

it('allows a pin on an upload-backed media item', function () {
    $user = createTenant('brw-'.Str::lower(Str::random(6)));
    $itemId = borrowedFixtureItem($user->id, null);

    actingAsUser($user)
        ->postJson("/api/content/pools/media/selection/{$itemId}")
        ->assertOk();

    expect(DB::table('site.section_items')->where('item_id', $itemId)->where('state', 'pinned')->exists())->toBeTrue();
});

it('allows a pin on an instagram media item — owned bytes, stable ref', function () {
    $user = createTenant('brw-'.Str::lower(Str::random(6)));
    $itemId = borrowedFixtureItem($user->id, 'instagram');

    actingAsUser($user)
        ->postJson("/api/content/pools/media/selection/{$itemId}")
        ->assertOk();
});

it('still 404s a stranger, rather than leaking existence as a 403', function () {
    // The borrowed check must not run BEFORE the ownership check — otherwise a
    // stranger probing uuids learns which ones are real Google media items.
    $owner = createTenant('brw-'.Str::lower(Str::random(6)));
    $stranger = createTenant('brw-'.Str::lower(Str::random(6)));
    $itemId = borrowedFixtureItem($owner->id, 'google_business');

    actingAsUser($stranger)
        ->postJson("/api/content/pools/media/selection/{$itemId}")
        ->assertStatus(404);
});

// #SEC-1 (D5 seam consistency): reorder() is the THIRD pin path — select()
// and it must answer to the identical 'pin' ability. BORROWED_SOURCE_KEYS is
// empty (R6), so no real fixture can exercise a genuine denial; a policy
// subclass that always denies 'pin' proves the SEAM instead — reorder() must
// call authorizeForUser($user, 'pin', $item) and honour the result, whatever
// the policy says. Registered via Gate::policy(), which overrides the
// AppServiceProvider binding for this booted test app only.
it('refuses a reorder that would pin an item the pin ability denies (#SEC-1)', function () {
    Gate::policy(Item::class, DenyPinContentItemPolicyForSec1Test::class);

    $user = createTenant('brw-'.Str::lower(Str::random(6)));
    $itemId = borrowedFixtureItem($user->id, 'google_business');

    actingAsUser($user)
        ->putJson('/api/content/pools/media/order', ['itemIds' => [$itemId]])
        ->assertStatus(403);

    // Nothing half-applied: the refused item must not have been pinned, and
    // ensure() must not have provisioned a section for a request that wrote
    // nothing (see Edit B's ordering — the loop runs BEFORE ensure()).
    expect(DB::table('site.section_items')->where('item_id', $itemId)->exists())->toBeFalse();
    expect(DB::table('site.sections')->where('key', PoolRegistry::sectionKey('media'))->exists())->toBeFalse();
});

// The over-correction guard: a blanket refusal in reorder() would pass the
// case above and break every real drag. Two distinct source keys avoid
// idx_content_sources_manual (borrowedFixtureItem reuses one manual source
// per user only when $sourceKey is null — both here are non-null).
it('still commits a reorder the pin ability allows', function () {
    $user = createTenant('brw-'.Str::lower(Str::random(6)));
    $first = borrowedFixtureItem($user->id, 'google_business');
    $second = borrowedFixtureItem($user->id, 'instagram');

    actingAsUser($user)
        ->putJson('/api/content/pools/media/order', ['itemIds' => [$second, $first]])
        ->assertOk();

    expect(DB::table('site.section_items')->where('item_id', $first)->where('state', 'pinned')->exists())->toBeTrue();
    expect(DB::table('site.section_items')->where('item_id', $second)->where('state', 'pinned')->exists())->toBeTrue();
});
