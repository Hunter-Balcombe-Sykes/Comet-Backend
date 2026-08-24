<?php

use App\Catalog\DetectorSuspensions;
use App\Routing\Rulepack;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// The read side of the staff kill-switch: catalog.detector_suspensions ->
// Rulepack::withSuspensions(). DetectorSuspensionProjectionTest covers what a
// suspension DOES; this covers where the set comes from.
//
// Two properties here are not obvious and are the reason this file exists:
//
//   1. FAIL-OPEN, loudly. Production has no `catalog` schema at all — it is
//      four schemas behind dev (CLAUDE.md, "Production carries NONE of this").
//      So the very first thing this query does on prod is throw. A kill-switch
//      lookup that 500s the paste preview would be a worse outage than the
//      detector it was meant to disable, so a failed read means "nothing is
//      suspended" plus a log line, never an exception.
//
//   2. The cache MUST carry a TTL. House rule (CacheKeyspaceConstraintsTest):
//      the whole Valkey instance runs maxmemory-policy volatile-lru, under
//      which a key with no TTL is un-evictable. Asserted behaviourally here as
//      well as statically, because the TTL is also what bounds how long a
//      released detector stays dark.

beforeEach(function () {
    setupCatalogRuntimeTables();
    Cache::forget(DetectorSuspensions::CACHE_KEY);
});

it('returns the detector ids of live suspensions', function () {
    DB::connection('pgsql')->table('catalog.detector_suspensions')->insert([
        'detector_id' => 'linktr-ee-profile',
        'reason' => 'rotating false positives on /s/ paths',
        'set_by' => 'staff:josh',
        'set_at' => now()->toDateTimeString(),
        'expires_at' => now()->addDay()->toDateTimeString(),
    ]);

    expect(app(DetectorSuspensions::class)->active())->toBe(['linktr-ee-profile']);
});

it('ignores a suspension whose window has closed', function () {
    // expires_at is NOT NULL by design — a kill-switch with no expiry is how
    // you get a detector that stays dark for a year because the person who
    // suspended it left. An elapsed row is simply not a suspension.
    DB::connection('pgsql')->table('catalog.detector_suspensions')->insert([
        'detector_id' => 'expired-detector',
        'reason' => 'was suspended last week',
        'set_by' => 'staff:josh',
        'set_at' => now()->subDays(8)->toDateTimeString(),
        'expires_at' => now()->subDay()->toDateTimeString(),
    ]);

    expect(app(DetectorSuspensions::class)->active())->toBe([]);
});

it('stays silent when the catalog schema is simply absent', function () {
    // Absence is not a fault HERE: production has no catalog schema at all, so
    // warning about it would fire on every Rulepack build, forever, for a state
    // CLAUDE.md documents as intended. A log line nobody can act on is how real
    // warnings become invisible.
    //
    // The safety cost is real and is paid elsewhere: catalog:suspend-detector
    // surfaces an unreachable table directly to the operator, which is the
    // moment it actually matters.
    DB::connection('pgsql')->statement('DROP TABLE catalog.detector_suspensions');
    Log::spy();

    expect(app(DetectorSuspensions::class)->active())->toBe([]);

    Log::shouldNotHaveReceived('warning');
});

it('still warns when the read fails for a reason that is not mere absence', function () {
    // A dead cache is a genuine fault and must not be swallowed into the same
    // silence as a missing schema — the caller still gets [] either way, so the
    // log line is the ONLY thing that distinguishes them.
    //
    // Deliberately not simulated by giving the table the wrong columns: SQLite
    // treats an unknown double-quoted identifier as a string literal, so
    // `where "expires_at" > ?` on a table without that column raises nothing at
    // all and the test would have passed vacuously.
    bindThrowingCacheStore();
    Log::spy();

    expect(app(DetectorSuspensions::class)->active())->toBe([]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $event) => $event === 'catalog.detector_suspensions.read_failed');
});

it('returns an empty set and does not throw when the catalog schema is absent', function () {
    // Exactly production's situation today. Simulated by dropping the table
    // rather than by mocking, so the assertion is about a real query failing.
    DB::connection('pgsql')->statement('DROP TABLE catalog.detector_suspensions');

    $suspensions = null;
    $thrown = null;
    try {
        $suspensions = app(DetectorSuspensions::class)->active();
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull($thrown === null ? '' : 'the kill-switch lookup escaped: '.$thrown->getMessage());
    expect($suspensions)->toBe([]);
});

it('claims its cache key with a TTL, never forever', function () {
    // volatile-lru: a key with no TTL cannot be evicted. Also bounds how long
    // a released detector stays dark.
    config(['partna.catalog.suspension_cache_ttl_seconds' => 60]);

    app(DetectorSuspensions::class)->active();
    expect(Cache::has(DetectorSuspensions::CACHE_KEY))->toBeTrue();

    $this->travel(61)->seconds();
    expect(Cache::has(DetectorSuspensions::CACHE_KEY))->toBeFalse();
});

it('serves the cached set rather than re-querying on every paste', function () {
    // The projector runs on every debounced keystroke of a paste preview, so
    // an uncached read would put a query on the hot path.
    $service = app(DetectorSuspensions::class);
    expect($service->active())->toBe([]);

    DB::connection('pgsql')->table('catalog.detector_suspensions')->insert([
        'detector_id' => 'written-after-the-first-read',
        'reason' => 'r',
        'set_by' => null,
        'set_at' => now()->toDateTimeString(),
        'expires_at' => now()->addDay()->toDateTimeString(),
    ]);

    expect($service->active())->toBe([]);
});

it('takes effect immediately when staff suspend a detector', function () {
    // The corollary of caching: the write path must drop the key, or a
    // kill-switch would take up to a TTL to fire — which is precisely when
    // someone is watching it and needs it now.
    $service = app(DetectorSuspensions::class);
    expect($service->active())->toBe([]);

    $service->suspend('urgent-detector', 'placing the wrong brand', 'staff:josh', now()->addHours(6));

    expect($service->active())->toBe(['urgent-detector']);
});

it('restores a detector immediately when staff release it', function () {
    $service = app(DetectorSuspensions::class);
    $service->suspend('temporarily-off', 'checking something', 'staff:josh', now()->addHours(6));
    expect($service->active())->toBe(['temporarily-off']);

    expect($service->release('temporarily-off'))->toBeTrue();
    expect($service->active())->toBe([]);
});

it('re-suspending a detector replaces the existing window rather than failing on the primary key', function () {
    // detector_id is the PRIMARY KEY, so a second suspend() of the same
    // detector is an upsert or an error. Extending a window is the normal
    // staff action ("still broken, give it another day").
    $service = app(DetectorSuspensions::class);
    $service->suspend('flapping-detector', 'first call', 'staff:josh', now()->addHour());
    $service->suspend('flapping-detector', 'still broken', 'staff:sam', now()->addDay());

    expect($service->active())->toBe(['flapping-detector']);

    $row = DB::connection('pgsql')->table('catalog.detector_suspensions')
        ->where('detector_id', 'flapping-detector')->first();
    expect($row->reason)->toBe('still broken');
    expect($row->set_by)->toBe('staff:sam');
});

it('hands the container-built rulepack the live suspension set', function () {
    // The seam that makes the other two halves one feature. Without this
    // binding, DetectorSuspensions reads a table nobody consults and
    // Rulepack::withSuspensions() is never called on the real request path —
    // which is exactly the shape the kill-switch shipped in on 2026-07-27.
    app(DetectorSuspensions::class)->suspend(
        'linktr-ee-profile', 'placing the wrong brand', 'staff:josh', now()->addHours(6)
    );

    // Forget the resolved singleton so the binding runs again, the way the
    // next request would.
    app()->forgetInstance(Rulepack::class);

    expect(app(Rulepack::class)->isSuspended('linktr-ee-profile'))->toBeTrue();
});

it('does not let a broken suspension lookup stop the router from loading', function () {
    // Same fail-open property as active(), asserted at the seam that matters:
    // on production — no catalog schema — the Rulepack must still build.
    DB::connection('pgsql')->statement('DROP TABLE catalog.detector_suspensions');
    app()->forgetInstance(Rulepack::class);

    $pack = null;
    $thrown = null;
    try {
        $pack = app(Rulepack::class);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull($thrown === null ? '' : 'the Rulepack binding threw: '.$thrown->getMessage());
    expect($pack->suspended)->toBe([]);
    // The compiled half must be intact, not an empty shell.
    expect($pack->byRegistrableKey)->not->toBe([]);
});
