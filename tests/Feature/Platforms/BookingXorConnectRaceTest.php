<?php

// U1: closes the symmetric Square/Fresha booking-XOR race. Before this unit,
// SquareController::connect() took NO lock at all around its
// hasConflictingConnection(fresha) check + write, and FreshaController::
// connect() took only its own per-platform 'fresha' key — a different string
// from Square's (nonexistent) lock, so the two could never exclude each
// other. "At most one booking provider" is a binary invariant with no
// reconciliation path once violated, so this file proves the fix BY
// INTERLEAVING two real HTTP requests deterministically (never by
// inspection, never by sleep/timing races) — see each case for the exact
// window it forces — plus span cases proving the lock covers the WHOLE
// check-then-write, not half of it.
//
// U1 addendum: BuildsAutoSyncFindings::applyFinding()'s remove-then-write was
// the identical bypass, reachable from the "Change to" endpoints
// (POST /api/platforms/{google-business,instagram}/synced/apply). Folded in
// here too (cases 7+) so acceptance criterion 1 covers every path, not just
// the two connect controllers. The reservations family (same method, same
// bypass shape, orchestrator's "one addition") is covered on the same seam.
//
// HELPER COLLISION WARNING: Pest loads every test file into one process —
// every top-level helper below is a GLOBAL symbol. bxRace* is a fresh prefix:
// squareUser/freshaLockUser/bxUser/bookingXorUser/bookingXorLockKeyFor/
// sacUser are all already taken by sibling files under tests/Feature/Platforms.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\Concerns\BuildsAutoSyncFindings;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\GoogleBusinessAutoSync;
use App\Services\Platforms\InstagramAutoSync;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Fresha's synchronous connect projects into site.services (FreshaServiceProjector
    // ::sync, a per-user pg_advisory_xact_lock) regardless of which side of the
    // race it lands on — mirrors FreshaConnectLockTest's beforeEach.
    setupServicesTable();
    shimPgAdvisoryLockForSqlite();
});

function bxRaceUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function bxRaceSquareUrl(): string
{
    return 'https://book.squareup.com/appointments/abc123/location/L1/services';
}

function bxRaceFreshaUrl(): string
{
    return 'https://www.fresha.com/a/doc-cuts';
}

/** @return array{storeName:string,team:array,services:array} */
function bxRaceFreshaMenu(): array
{
    return ['storeName' => 'Doc Cuts', 'team' => [], 'services' => []];
}

// ── 1-3: the connect-vs-connect interleaves (acceptance criterion 1, connect half) ──

it('interleaved connects — Fresha first: a Square connect that lands between Fresha\'s pre-check and Fresha\'s write cannot leave both providers active', function () {
    $user = bxRaceUser('bxr1');

    $innerStatus = null;
    // Bind BEFORE any row is created — the IntegrationConnection guard-timing
    // gotcha (a late-bound scraper mock can capture a stale instance).
    $this->mock(FreshaScraper::class, function ($m) use ($user, &$innerStatus) {
        $m->shouldReceive('stripLocale')->andReturnUsing(fn ($u) => $u);
        $m->shouldReceive('fetchMenu')->andReturnUsing(function () use ($user, &$innerStatus) {
            // fetchMenu runs at FreshaController.php:~102 — after the 409
            // pre-check, before the bookingXor lock. Precisely the race
            // window a lock-free Square connect could slip through pre-U1.
            $innerStatus = actingAsUser($user)->postJson('/api/platforms/square/connect', [
                'url' => bxRaceSquareUrl(),
            ])->getStatusCode();

            return bxRaceFreshaMenu();
        });
    });

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => bxRaceFreshaUrl()])
        ->assertStatus(409);

    // Fail-before: on today's (pre-U1) code the outer Fresha returns 200 and
    // the count below is 2 — Square's write went unsynchronised.
    expect($innerStatus)->toBe(200);
    expect(IntegrationConnection::whereIn('platform', ['fresha', 'square'])->count())->toBe(1);
});

it('interleaved connects — Square first: a Fresha connect that lands inside Square\'s write cannot leave both providers active', function () {
    $user = bxRaceUser('bxr2');

    $this->mock(FreshaScraper::class, function ($m) {
        $m->shouldReceive('stripLocale')->andReturnUsing(fn ($u) => $u);
        $m->shouldReceive('fetchMenu')->andReturn(bxRaceFreshaMenu());
    });

    $innerStatus = null;
    $fired = false;
    // 'creating' fires inside updateOrCreate (ManagesIntegrationConnection.php
    // ~154) — i.e. INSIDE Square's new bookingXor lock, before the square row
    // is visible to any hasConflictingConnection() query. Event::listen
    // registrations die with the per-test application instance (unlike
    // Model::creating(), which would leak into sibling tests).
    Event::listen('eloquent.creating: '.IntegrationConnection::class, function ($model) use ($user, &$innerStatus, &$fired) {
        if ($fired || $model->platform !== 'square') {
            return;
        }
        $fired = true;

        $innerStatus = actingAsUser($user)->postJson('/api/platforms/fresha/connect', [
            'url' => bxRaceFreshaUrl(),
        ])->getStatusCode();
    });

    actingAsUser($user)->postJson('/api/platforms/square/connect', ['url' => bxRaceSquareUrl()])
        ->assertStatus(200);

    // Fail-before: Square holds no lock, so the nested Fresha passes its own
    // (stale) check — the square row isn't inserted yet — writes, and
    // returns 200 -> count 2.
    expect($innerStatus)->toBe(423);
    expect(IntegrationConnection::whereIn('platform', ['fresha', 'square'])->count())->toBe(1);
});

it('interleaved connects — deferred Fresha: the flag-on path is equally exclusive and dispatches nothing when it loses', function () {
    config(['partna.connect.deferred' => ['fresha']]);
    Queue::fake();
    Http::fake();

    $user = bxRaceUser('bxr3');

    // Deferred mode never reaches fetchMenu — only stripLocale runs before
    // the (lock-guarded) pending write.
    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldReceive('stripLocale')->andReturnUsing(fn ($u) => $u));

    $innerStatus = null;
    $fired = false;
    Event::listen('eloquent.creating: '.IntegrationConnection::class, function ($model) use ($user, &$innerStatus, &$fired) {
        if ($fired || $model->platform !== 'square') {
            return;
        }
        $fired = true;

        $innerStatus = actingAsUser($user)->postJson('/api/platforms/fresha/connect', [
            'url' => bxRaceFreshaUrl(),
        ])->getStatusCode();
    });

    actingAsUser($user)->postJson('/api/platforms/square/connect', ['url' => bxRaceSquareUrl()])
        ->assertStatus(200);

    expect($innerStatus)->toBe(423);
    // Proves the dispatch is gated behind the $row === null sentinel — a
    // loser (423 from either lock, or the re-asserted 409) never fires
    // ConnectFetchJob.
    Queue::assertNothingPushed();
    expect(IntegrationConnection::whereIn('platform', ['fresha', 'square'])->count())->toBe(1);
});

// ── 4-6: span proofs — the lock covers the WHOLE check-then-write ──

it('square connect: a held booking-XOR lock 423s and writes nothing', function () {
    $user = bxRaceUser('bxr4');

    $lock = Cache::lock(CacheKeyGenerator::bookingXorLock((string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/square/connect', ['url' => bxRaceSquareUrl()])
            ->assertStatus(423)
            ->assertJson(['message' => 'Another change is still saving — please retry in a moment.']);
    } finally {
        $lock->release();
    }

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'square')->exists())->toBeFalse();
});

it('square connect: the conflict check itself is inside the lock, not before it', function () {
    $user = bxRaceUser('bxr5');
    $key = CacheKeyGenerator::bookingXorLock((string) $user->id);

    // Mirrors BookingXorLockTest's "Probe A": fires on the exists() query
    // hasConflictingConnection() issues. If the outer lock is genuinely held
    // across the check (not just around the write), THIS probe lock must
    // fail to acquire.
    $checkLocked = false;
    DB::listen(function ($query) use (&$checkLocked, $key) {
        if (! str_contains($query->sql, 'select exists(') || ! str_contains($query->sql, 'platform_connections')) {
            return;
        }
        $probe = Cache::lock($key, 5);
        $heldNow = ! $probe->get();
        if (! $heldNow) {
            $probe->release(); // MANDATORY — an ArrayLock left held would deadlock the write this same test observes.
        }
        $checkLocked = $checkLocked || $heldNow;
    });

    actingAsUser($user)->postJson('/api/platforms/square/connect', ['url' => bxRaceSquareUrl()])
        ->assertOk();

    // Fail-before: today's code runs this exists() query with no lock held
    // at all, so $checkLocked stays false.
    expect($checkLocked)->toBeTrue();
});

it('fresha connect: a held booking-XOR lock 423s after the scrape and writes nothing', function () {
    $this->mock(FreshaScraper::class, function ($m) {
        $m->shouldReceive('stripLocale')->once()->andReturnUsing(fn ($u) => $u);
        $m->shouldReceive('fetchMenu')->once()->andReturn(bxRaceFreshaMenu());
    });

    $user = bxRaceUser('bxr6');
    $existing = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/original', 'selection' => null],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $lock = Cache::lock(CacheKeyGenerator::bookingXorLock((string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => bxRaceFreshaUrl()])
            ->assertStatus(423);
    } finally {
        $lock->release();
    }

    // Mockery's ->once() on fetchMenu (verified at teardown) proves the
    // scrape ran despite the lock being held — i.e. it stayed OUTSIDE the
    // lock, not accidentally moved inside it. The row is untouched either way.
    expect($existing->fresh()->payload['url'])->toBe('https://www.fresha.com/a/original');
});

// ── 7: the auto-sync half of criterion 1 — applyFinding vs a live connect ──

it('interleaved — a Square connect landing inside applyFinding\'s remove-then-write span cannot leave both providers active', function () {
    $user = bxRaceUser('bxr7');

    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'square', 'resource_id' => 'square',
        'payload' => ['url' => bxRaceSquareUrl()], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $finding = [
        'platform' => 'fresha', 'resourceId' => 'fresha', 'category' => 'booking', 'label' => 'Fresha',
        'foundUrl' => bxRaceFreshaUrl(), 'outcome' => 'conflict',
        'apply' => [
            'remove' => ['fresha', 'square', 'booking'],
            'write' => ['platform' => 'fresha', 'resourceId' => 'fresha', 'payload' => ['url' => bxRaceFreshaUrl(), 'source' => 'google-business']],
        ],
    ];
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'payload' => ['placeId' => 'ChIJapply7', 'name' => 'Doc Cuts', 'syncFindings' => [$finding]],
        'is_active' => true, 'last_refresh_status' => 'ok', 'last_refreshed_at' => now(),
    ]);

    $innerStatus = null;
    $fired = false;
    // 'deleting' fires from inside runApply()'s remove loop — i.e. INSIDE the
    // bookingXorLock applyFinding now holds, after the (no-op) 'fresha'
    // removal attempt and before the 'fresha' WRITE that follows.
    Event::listen('eloquent.deleting: '.IntegrationConnection::class, function ($model) use ($user, &$innerStatus, &$fired) {
        if ($fired || $model->platform !== 'square') {
            return;
        }
        $fired = true;

        $innerStatus = actingAsUser($user)->postJson('/api/platforms/square/connect', [
            'url' => bxRaceSquareUrl(),
        ])->getStatusCode();
    });

    actingAsUser($user)->postJson('/api/platforms/google-business/synced/apply', ['platform' => 'fresha'])
        ->assertOk();

    // Fail-before (§2 shipped, §9 not): applyFinding holds no lock, so the
    // nested Square connect acquires bookingXorLock uncontended and returns
    // 200; apply then writes fresha -> two live providers.
    expect($innerStatus)->toBe(423);
    expect(IntegrationConnection::whereIn('platform', ['fresha', 'square', 'booking'])->count())->toBe(1);
    $fresha = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->firstOrFail();
    expect($fresha->payload['url'])->toBe(bxRaceFreshaUrl());
});

// ── 8-11: contended "Change to" — 423, honest, never terminal, never over-locked ──

it('a held booking-XOR lock makes google-business "Change to" fail honestly, not silently', function () {
    $user = bxRaceUser('bxr8');

    $square = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'square', 'resource_id' => 'square',
        'payload' => ['url' => bxRaceSquareUrl()], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $finding = [
        'platform' => 'fresha', 'resourceId' => 'fresha', 'category' => 'booking', 'label' => 'Fresha',
        'foundUrl' => bxRaceFreshaUrl(), 'outcome' => 'conflict',
        'apply' => [
            'remove' => ['fresha', 'square', 'booking'],
            'write' => ['platform' => 'fresha', 'resourceId' => 'fresha', 'payload' => ['url' => bxRaceFreshaUrl(), 'source' => 'google-business']],
        ],
    ];
    $gb = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'payload' => ['placeId' => 'ChIJapply8', 'name' => 'Doc Cuts', 'syncFindings' => [$finding]],
        'is_active' => true, 'last_refresh_status' => 'ok', 'last_refreshed_at' => now(),
    ]);

    $lock = Cache::lock(CacheKeyGenerator::bookingXorLock((string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/google-business/synced/apply', ['platform' => 'fresha'])
            ->assertStatus(423)
            ->assertJson(['message' => 'Another change is still saving — please retry in a moment.']);
    } finally {
        $lock->release();
    }

    // Not skipped: never reports 'seeded' for a change that never happened.
    expect($gb->fresh()->payload['syncFindings'][0]['outcome'])->toBe('conflict');
    // Not terminal side effects either: nothing removed, nothing written.
    expect($square->fresh())->not->toBeNull();
    expect($square->fresh()->deleted_at)->toBeNull();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
});

it('the instagram "Change to" endpoint behaves identically under a held booking-XOR lock', function () {
    $user = bxRaceUser('bxr9');

    $square = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'square', 'resource_id' => 'square',
        'payload' => ['url' => bxRaceSquareUrl()], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $finding = [
        'platform' => 'fresha', 'resourceId' => 'fresha', 'category' => 'booking', 'label' => 'Fresha',
        'foundUrl' => bxRaceFreshaUrl(), 'outcome' => 'conflict',
        'apply' => [
            'remove' => ['fresha', 'square'],
            'write' => ['platform' => 'fresha', 'resourceId' => 'fresha', 'payload' => ['url' => bxRaceFreshaUrl(), 'source' => 'instagram']],
        ],
    ];
    $ig = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['username' => 'docpizza', 'syncFindings' => [$finding], 'unmatched' => []],
        'is_active' => true, 'last_refresh_status' => 'ok', 'last_refreshed_at' => now(),
    ]);

    $lock = Cache::lock(CacheKeyGenerator::bookingXorLock((string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/instagram/synced/apply', ['platform' => 'fresha'])
            ->assertStatus(423)
            ->assertJson(['message' => 'Another change is still saving — please retry in a moment.']);
    } finally {
        $lock->release();
    }

    expect($ig->fresh()->payload['syncFindings'][0]['outcome'])->toBe('conflict');
    expect($square->fresh()->deleted_at)->toBeNull();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
});

it('a social "Change to" is unaffected by a held booking-XOR lock', function () {
    $user = bxRaceUser('bxr10');

    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'facebook', 'resource_id' => 'facebook',
        'payload' => ['username' => 'mine', 'url' => 'https://facebook.com/mine', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $finding = [
        'platform' => 'facebook', 'resourceId' => 'facebook', 'category' => 'social', 'label' => 'Facebook',
        'foundUrl' => 'https://facebook.com/found', 'outcome' => 'conflict',
        'apply' => [
            'remove' => ['facebook'],
            'write' => ['platform' => 'facebook', 'resourceId' => 'facebook', 'payload' => ['url' => 'https://facebook.com/found', 'source' => 'google-business']],
        ],
    ];
    $gb = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'payload' => ['placeId' => 'ChIJapply10', 'name' => 'Doc Cuts', 'syncFindings' => [$finding]],
        'is_active' => true, 'last_refresh_status' => 'ok', 'last_refreshed_at' => now(),
    ]);

    $lock = Cache::lock(CacheKeyGenerator::bookingXorLock((string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/google-business/synced/apply', ['platform' => 'facebook'])
            ->assertOk();
    } finally {
        $lock->release();
    }

    $facebook = IntegrationConnection::where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail();
    expect($facebook->payload['url'])->toBe('https://facebook.com/found');
    expect($gb->fresh()->payload['syncFindings'][0]['outcome'])->toBe('seeded');
});

it('applyFinding never holds the XOR lock across an Instagram dispatch — a hybrid recipe degrades to the unlocked path and canaries', function () {
    Exceptions::fake();
    $user = bxRaceUser('bxr11');

    // Structurally should never happen — GB's Instagram recipe is ALWAYS
    // category:'social' (GoogleBusinessAutoSync.php ~628), never 'booking'.
    // If it ever were, this MUST degrade to today's unlocked path: never hold
    // a 10s lock across applyFindingHandled()'s ~110s inline Apify scrape.
    $finding = [
        'platform' => 'instagram', 'resourceId' => 'instagram', 'category' => 'booking', 'label' => 'Instagram',
        'foundUrl' => 'https://instagram.com/docpizza', 'outcome' => 'conflict',
        'apply' => [
            'remove' => ['fresha', 'square', 'booking'],
            'instagram' => ['username' => 'docpizza'],
        ],
    ];

    $lock = Cache::lock(CacheKeyGenerator::bookingXorLock((string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        // If this took the locked branch, it would contend against the lock
        // THIS SAME test already holds (self-held key) and block ~3s before
        // returning false — a fast `true` is itself part of the proof.
        $result = app(GoogleBusinessAutoSync::class)->applyFinding((string) $user->id, $finding);
    } finally {
        $lock->release();
    }

    expect($result)->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'apply.instagram'));
});

// ── 12: Trap 2 — the third booking-platform list must not drift ──

// Reshaped 2026-07-25 (link classification consolidation). There used to be
// THREE booking lists to keep in union: GoogleBusinessAutoSync's,
// InstagramAutoSync's, and the trait's slot list. InstagramAutoSync no longer
// has one — its seed path routes through LinkRouter, and the shared
// BuildsAutoSyncFindings::resolveBookingLink() both of them now use reads
// BOOKING_SLOT_PLATFORMS directly. So the only pair that can still drift is
// GB's const vs the slot list.
it('BOOKING_SLOT_PLATFORMS still covers GoogleBusinessAutoSync\'s own BOOKING_PLATFORMS', function () {
    $traitConst = (new ReflectionClass(BuildsAutoSyncFindings::class))->getConstant('BOOKING_SLOT_PLATFORMS');
    $gbConst = (new ReflectionClass(GoogleBusinessAutoSync::class))->getConstant('BOOKING_PLATFORMS');

    sort($traitConst);
    sort($gbConst);

    expect($traitConst)->toBe($gbConst);
});

it('the trait declares NO BOOKING_PLATFORMS constant — one there fatals every class that declares its own', function () {
    // Regression pin for a real outage: Phase 2.5 briefly added
    // `protected const BOOKING_PLATFORMS` to BuildsAutoSyncFindings while
    // GoogleBusinessAutoSync already declared `private const BOOKING_PLATFORMS`
    // with the SAME values. PHP compares a trait-vs-class constant clash by
    // DEFINITION — visibility included — not by value, so private-vs-protected
    // alone is "incompatible" and fatals at class composition. That made
    // GoogleBusinessAutoSync unloadable: every Google Business enrichment path
    // died, and three test files crashed with no output at all.
    expect((new ReflectionClass(BuildsAutoSyncFindings::class))->hasConstant('BOOKING_PLATFORMS'))->toBeFalse();

    // The proof it stays fixed: the class actually composes.
    expect(new ReflectionClass(GoogleBusinessAutoSync::class))->toBeInstanceOf(ReflectionClass::class);
});

it('InstagramAutoSync no longer declares a booking list of its own — LinkRouter owns that path now', function () {
    expect((new ReflectionClass(InstagramAutoSync::class))->hasConstant('BOOKING_PLATFORMS'))->toBeFalse();
});

// ── Trap 3: both arms of the predicate are load-bearing, not just the primary one ──

it('isBookingSlotApply — category alone is sufficient even when remove is empty (arm 1: category)', function () {
    $user = bxRaceUser('bxr15');
    $finding = [
        'platform' => 'fresha', 'category' => 'booking', 'outcome' => 'conflict',
        'apply' => ['remove' => [], 'write' => ['platform' => 'fresha', 'resourceId' => 'fresha', 'payload' => ['url' => bxRaceFreshaUrl()]]],
    ];

    $lock = Cache::lock(CacheKeyGenerator::bookingXorLock((string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        $result = app(GoogleBusinessAutoSync::class)->applyFinding((string) $user->id, $finding);
    } finally {
        $lock->release();
    }

    expect($result)->toBeFalse();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
});

it('isBookingSlotApply — the remove-list fallback locks a legacy finding with no category (arm 2: remove)', function () {
    $user = bxRaceUser('bxr16');
    $finding = [
        'platform' => 'fresha', 'outcome' => 'conflict', // no 'category' key at all — legacy stored shape
        'apply' => ['remove' => ['square'], 'write' => ['platform' => 'fresha', 'resourceId' => 'fresha', 'payload' => ['url' => bxRaceFreshaUrl()]]],
    ];

    $lock = Cache::lock(CacheKeyGenerator::bookingXorLock((string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        $result = app(GoogleBusinessAutoSync::class)->applyFinding((string) $user->id, $finding);
    } finally {
        $lock->release();
    }

    expect($result)->toBeFalse();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
});

it('a finding matching NEITHER arm stays unlocked even while the booking-XOR lock is held', function () {
    $user = bxRaceUser('bxr17');
    $finding = [
        'platform' => 'facebook', 'category' => 'social', 'outcome' => 'conflict',
        'apply' => ['remove' => ['facebook'], 'write' => ['platform' => 'facebook', 'resourceId' => 'facebook', 'payload' => ['url' => 'https://facebook.com/found']]],
    ];

    $lock = Cache::lock(CacheKeyGenerator::bookingXorLock((string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        $result = app(GoogleBusinessAutoSync::class)->applyFinding((string) $user->id, $finding);
    } finally {
        $lock->release();
    }

    expect($result)->toBeTrue();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'facebook')->exists())->toBeTrue();
});

// ── Orchestrator's "one addition": the reservations family gets the same seam ──

it('a reservations "Change to" behaves identically to booking under a held RESERVATIONS-XOR lock — same seam, symmetric fold-in', function () {
    $user = bxRaceUser('bxr13');

    $opentable = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'opentable', 'resource_id' => 'opentable',
        'payload' => ['url' => 'https://www.opentable.com/r/doc-cuts', 'name' => 'Doc Cuts'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $finding = [
        'platform' => 'resdiary', 'resourceId' => 'resdiary', 'category' => 'reservations', 'label' => 'ResDiary',
        'foundUrl' => 'https://www.resdiary.com/doc-cuts', 'outcome' => 'conflict',
        'apply' => [
            'remove' => ['opentable', 'resdiary', 'nowbookit', 'reservations'],
            'write' => ['platform' => 'resdiary', 'resourceId' => 'resdiary', 'payload' => ['url' => 'https://www.resdiary.com/doc-cuts', 'source' => 'google-business']],
        ],
    ];
    $gb = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'payload' => ['placeId' => 'ChIJresv13', 'name' => 'Doc Cuts', 'syncFindings' => [$finding]],
        'is_active' => true, 'last_refresh_status' => 'ok', 'last_refreshed_at' => now(),
    ]);

    $lock = Cache::lock(CacheKeyGenerator::reservationsXorLock((string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/google-business/synced/apply', ['platform' => 'resdiary'])
            ->assertStatus(423)
            ->assertJson(['message' => 'Another change is still saving — please retry in a moment.']);
    } finally {
        $lock->release();
    }

    expect($gb->fresh()->payload['syncFindings'][0]['outcome'])->toBe('conflict');
    expect($opentable->fresh())->not->toBeNull();
    expect($opentable->fresh()->deleted_at)->toBeNull();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'resdiary')->exists())->toBeFalse();
});

it('a reservations "Change to" is unaffected by a held booking-XOR lock — independent slot families, different keys', function () {
    $user = bxRaceUser('bxr14');

    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'opentable', 'resource_id' => 'opentable',
        'payload' => ['url' => 'https://www.opentable.com/r/doc-cuts', 'name' => 'Doc Cuts'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $finding = [
        'platform' => 'resdiary', 'resourceId' => 'resdiary', 'category' => 'reservations', 'label' => 'ResDiary',
        'foundUrl' => 'https://www.resdiary.com/doc-cuts', 'outcome' => 'conflict',
        'apply' => [
            'remove' => ['opentable', 'resdiary', 'nowbookit', 'reservations'],
            'write' => ['platform' => 'resdiary', 'resourceId' => 'resdiary', 'payload' => ['url' => 'https://www.resdiary.com/doc-cuts', 'source' => 'google-business']],
        ],
    ];
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'payload' => ['placeId' => 'ChIJresv14', 'name' => 'Doc Cuts', 'syncFindings' => [$finding]],
        'is_active' => true, 'last_refresh_status' => 'ok', 'last_refreshed_at' => now(),
    ]);

    // NOTE: booking, not reservations — the two families must never share a key.
    $lock = Cache::lock(CacheKeyGenerator::bookingXorLock((string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/google-business/synced/apply', ['platform' => 'resdiary'])
            ->assertOk();
    } finally {
        $lock->release();
    }

    $resdiary = IntegrationConnection::where('user_id', $user->id)->where('platform', 'resdiary')->firstOrFail();
    expect($resdiary->payload['url'])->toBe('https://www.resdiary.com/doc-cuts');
});

it('RESERVATIONS_SLOT_PLATFORMS mirrors GoogleBusinessAutoSync::RESERVATION_PLATFORMS exactly (its only producer)', function () {
    $traitConst = (new ReflectionClass(BuildsAutoSyncFindings::class))->getConstant('RESERVATIONS_SLOT_PLATFORMS');
    $gbConst = (new ReflectionClass(GoogleBusinessAutoSync::class))->getConstant('RESERVATION_PLATFORMS');

    sort($traitConst);
    sort($gbConst);

    expect($traitConst)->toBe($gbConst);
});
