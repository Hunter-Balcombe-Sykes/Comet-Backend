<?php

// #W2-LIFE-3 regression. SourceReconciler::reconcile() ran the exclusive-class
// incumbent check (`isExclusiveAuto` -> `incumbentFor`) with no lock at all,
// then wrote through a DB::transaction some statements later. Two concurrent
// auto placements in the same routing class both saw "no incumbent", and the
// loser's `forceFill(['is_primary' => true])->save()` inside applyIntent() hit
// idx_platform_connections_primary_per_class
// (supabase/migrations/20260727110008_connections_idx_primary_per_class.sql).
//
// The audit's STATED impact — "two connections both marked is_primary" — is
// wrong, and worth stating so nobody re-litigates it: that partial unique
// index already makes it impossible. The real damage is that the violation was
// raised UNCAUGHT inside the LIFE-16 transaction, so the whole reconcile rolled
// back into a 500 / failed job and the XOR's "hold the second one as a conflict
// for the suggestions inbox" semantics never ran.
//
// Fix: the incumbent check and the transaction that acts on it run together
// inside one Cache::lock — LOCK OUTER, DB TRANSACTION INNER — keyed on the same
// string every other writer of that family's slot takes. This file pins the
// REFUSAL, not merely that something was refused: a held lock produces the
// named log key `routing.reconcile.exclusive_slot_lock_timeout` and zero rows
// in both tables.
//
// The primary_per_class violation ITSELF is unprovable here: that index is
// absent from the SQLite stand-in (tests/Pest.php mirrors only _canonical and
// _unique_active), so it lives in
// tests/Postgres/SourceReconcilerConnectionRacePgTest.php.

use App\Routing\Iri;
use App\Routing\Placement;
use App\Routing\RoutingContext;
use App\Routing\SourceReconciler;
use App\Routing\Verdict;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\GoogleBusinessAutoSync;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

// UNIQUE GLOBAL PREFIX (`srcSlot`) — Pest loads the whole lane into one
// process, so a name shared with a sibling file is a silent collision.

function srcSlotIri(string $host, string $path): Iri
{
    return new Iri(
        raw: "https://{$host}{$path}",
        canonical: "https://{$host}{$path}",
        scheme: 'https',
        host: $host,
        registrableKey: implode('.', array_slice(explode('.', $host), -2)),
        subdomain: null,
        path: $path,
        query: [],
        port: null,
    );
}

function srcSlotPlacement(string $surfaceKey, string $identifier): Placement
{
    return new Placement(
        verdict: Verdict::Place,
        surfaceKey: $surfaceKey,
        identifier: $identifier,
        blockReason: null,
    );
}

// ── The refusal, pinned by its log key, for all three exclusive families ───

it('refuses the placement and writes nothing while another writer holds the family lock', function (string $surfaceKey, string $routingClass, string $lockMethod, string $host) {
    $user = createTenant('srcslot-'.$routingClass);
    $identifier = 'slot'.Str::lower(Str::random(6));

    // POSITIVE Mockery expectation on the exact log key. A negative assertion
    // ("nothing was written") alone would pass just as well if the reconcile
    // had failed for some entirely different reason — negative Mockery log
    // asserts are vacuous in this repo, so the refusal REASON is pinned here.
    Log::shouldReceive('warning')
        ->once()
        ->with('routing.reconcile.exclusive_slot_lock_timeout', Mockery::on(
            fn ($ctx) => $ctx['user_id'] === (string) $user->id
                && $ctx['routing_class'] === $routingClass
                && $ctx['surface_key'] === $surfaceKey
        ));
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    // Held by "another writer" — GoogleBusinessAutoSync's seed lock, a connect
    // controller's cross-platform lock, or a second reconcile. All three build
    // this same string.
    $lock = Cache::lock(CacheKeyGenerator::{$lockMethod}((string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        $result = app(SourceReconciler::class)->reconcile(
            srcSlotPlacement($surfaceKey, $identifier),
            RoutingContext::forUser($user, 'bio_harvest'),
            srcSlotIri($host, '/'.$identifier),
        );
    } finally {
        $lock->release();
    }

    expect($result['intent_id'])->toBeNull()
        ->and($result['connection_id'])->toBeNull();

    // Nothing applied — not a half-written intent, not a connection. The next
    // harvest re-proposes the link.
    expect(DB::table('routing.source_intents')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('site.platform_connections')->where('user_id', $user->id)->count())->toBe(0);
})->with([
    'booking' => ['fresha.book', 'booking', 'bookingXorLock', 'www.fresha.com'],
    'reservations' => ['opentable.reserve', 'reservations', 'reservationsXorLock', 'www.opentable.com.au'],
    'ordering' => ['doordash.order', 'ordering', 'orderingFamilyLock', 'www.doordash.com'],
]);

// ── Negative control ──────────────────────────────────────────────────────

it('a non-exclusive class is unaffected by a held booking lock', function () {
    // Without this, the test above would pass just as well against a
    // reconciler that took the booking lock for EVERY placement — which would
    // put a Redis round-trip and a 3s worst case on the whole scan hot path.
    $user = createTenant('srcslot-control');
    $identifier = 'controlartist'.Str::lower(Str::random(6));

    $lock = Cache::lock(CacheKeyGenerator::bookingXorLock((string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        $result = app(SourceReconciler::class)->reconcile(
            srcSlotPlacement('bandcamp.artist', $identifier),
            RoutingContext::forUser($user, 'bio_harvest'),
            srcSlotIri("{$identifier}.bandcamp.com", ''),
        );
    } finally {
        $lock->release();
    }

    expect($result['verdict'])->toBe('place')
        ->and($result['connection_id'])->not->toBeNull();

    expect(DB::table('routing.source_intents')->where('user_id', $user->id)->count())->toBe(1);
});

it('a DIRECT paste bypasses the lock — the XOR is an AUTO-write rule', function () {
    // Same shape as the reconciler's own condition (`! isDirectRequest()`):
    // a user pasting a booking link is not a background harvest racing another
    // one, and holding them behind a stuck seed lock would 423 a human.
    $user = createTenant('srcslot-direct');
    $identifier = 'direct'.Str::lower(Str::random(6));

    $lock = Cache::lock(CacheKeyGenerator::bookingXorLock((string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        $result = app(SourceReconciler::class)->reconcile(
            srcSlotPlacement('fresha.book', $identifier),
            RoutingContext::forUser($user, 'paste'),
            srcSlotIri('www.fresha.com', '/'.$identifier),
        );
    } finally {
        $lock->release();
    }

    expect($result['verdict'])->toBe('place')
        ->and($result['connection_id'])->not->toBeNull();
});

// ── Key identity: the drift this seam exists to prevent ───────────────────

it('the reconciler blocks on exactly the key every other writer of that family takes', function () {
    // Reflection-pinned rather than inferred: exclusiveSlotLockKey() is
    // private, and a suffix typo there would produce a lock that excludes
    // nothing while every "the lock is taken" test above still passed (it
    // would just be holding a different, uncontended string). Same style as
    // BookingXorConnectRaceTest's constant pins.
    $userId = (string) Str::uuid();
    $method = new ReflectionMethod(SourceReconciler::class, 'exclusiveSlotLockKey');

    $key = fn (string $class) => $method->invoke(app(SourceReconciler::class), $class, $userId);

    expect($key('booking'))->toBe(CacheKeyGenerator::bookingXorLock($userId))
        ->and($key('reservations'))->toBe(CacheKeyGenerator::reservationsXorLock($userId))
        ->and($key('ordering'))->toBe(CacheKeyGenerator::orderingFamilyLock($userId))
        ->and($key('content'))->toBeNull();

    // Every class isExclusiveAuto() admits must map to a real key — a class
    // admitted there but unmapped here would silently run unlocked.
    $isExclusive = new ReflectionMethod(SourceReconciler::class, 'isExclusiveAuto');
    foreach (['booking', 'reservations', 'ordering'] as $class) {
        expect($isExclusive->invoke(app(SourceReconciler::class), $class))->toBeTrue()
            ->and($key($class))->not->toBeNull();
    }
});

it('orderingFamilyLock IS the online-ordering platform lock — one string, not two that agree by luck', function () {
    // The ordering family has no XOR key of its own: it has always serialised
    // on the platform-wide 'online-ordering' connection lock, and
    // GoogleBusinessAutoSync::seedOrdering takes the same one. If these two
    // ever diverge, the reconciler and the Google seeder stop excluding each
    // other — exactly the platformConnectionLock suffix-drift bug
    // BuildsAutoSyncFindings warns about.
    $userId = (string) Str::uuid();

    expect(CacheKeyGenerator::orderingFamilyLock($userId))
        ->toBe(CacheKeyGenerator::platformConnectionLock('online-ordering', $userId));

    // And the seeder's own family const still names that platform, so routing
    // seedOrdering through the shared key did not quietly change which lock it
    // takes.
    expect((new ReflectionClass(GoogleBusinessAutoSync::class))->getConstant('ORDERING_FAMILY'))
        ->toBe('online-ordering');
});
