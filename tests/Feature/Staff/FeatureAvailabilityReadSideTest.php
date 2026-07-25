<?php

/**
 * OV-A — FeatureAvailability::for($user) resolution:
 * absence = available · global rows apply to everyone · segment rows beat the
 * global row · disabled wins across multiple segment rows · flush busts cache.
 */

use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Segments\UserSegmentMember;
use App\Models\Core\User\User;
use App\Services\Cache\CacheLockService;
use App\Services\FeatureAvailability\FeatureAvailability;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSegmentsTables();
    setupFeatureAvailabilityTable();

    DB::connection('pgsql')->statement('DELETE FROM core.users');
    DB::connection('pgsql')->statement('DELETE FROM core.feature_availability');
    DB::connection('pgsql')->statement('DELETE FROM core.user_segments');

    FeatureAvailability::flush();

    // CCH-5's escalation counter (EscalatesRepeatedFaults) lives in the cache
    // via RateLimiter::hit() — clear it explicitly so a fault test never
    // leaks a count into a sibling test (see AnalyticsCacheServiceTest for
    // the same precaution on the sibling call site).
    RateLimiter::clear('analytics:fault:resolve_overrides');
});

function ovaAvailUser(): User
{
    $id = (string) Str::uuid();
    $handle = 'avail-'.Str::random(6);
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return User::query()->findOrFail($id);
}

function ovaManualSegmentWith(string $userId): UserSegment
{
    $segment = UserSegment::query()->create(['name' => 'seg-'.Str::random(4), 'filters' => []]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $userId]);

    return $segment;
}

it('defaults every key to available when no rules exist', function () {
    expect(FeatureAvailability::for(ovaAvailUser())->allows('integration.instagram'))->toBeTrue();
});

it('applies a global disabled rule to everyone', function () {
    FeatureAvailabilityRule::query()->create(['feature_key' => 'integration.instagram', 'mode' => 'disabled']);
    FeatureAvailability::flush();

    expect(FeatureAvailability::for(ovaAvailUser())->allows('integration.instagram'))->toBeFalse();
});

it('lets a segment rule beat the global rule for members only', function () {
    $member = ovaAvailUser();
    $outsider = ovaAvailUser();
    $segment = ovaManualSegmentWith($member->id);

    FeatureAvailabilityRule::query()->create(['feature_key' => 'feature.shop', 'mode' => 'disabled']);
    FeatureAvailabilityRule::query()->create(['feature_key' => 'feature.shop', 'mode' => 'enabled', 'segment_id' => $segment->id]);
    FeatureAvailability::flush();

    expect(FeatureAvailability::for($member)->allows('feature.shop'))->toBeTrue()
        ->and(FeatureAvailability::for($outsider)->allows('feature.shop'))->toBeFalse();
});

it('lets disabled win when a user is in several ruled segments', function () {
    $user = ovaAvailUser();
    $segA = ovaManualSegmentWith($user->id);
    $segB = ovaManualSegmentWith($user->id);

    FeatureAvailabilityRule::query()->create(['feature_key' => 'feature.beta', 'mode' => 'enabled', 'segment_id' => $segA->id]);
    FeatureAvailabilityRule::query()->create(['feature_key' => 'feature.beta', 'mode' => 'disabled', 'segment_id' => $segB->id]);
    FeatureAvailability::flush();

    expect(FeatureAvailability::for($user)->allows('feature.beta'))->toBeFalse();
});

it('reflects rule changes immediately after flush', function () {
    $user = ovaAvailUser();

    expect(FeatureAvailability::for($user)->allows('integration.square'))->toBeTrue();

    $rule = FeatureAvailabilityRule::query()->create(['feature_key' => 'integration.square', 'mode' => 'disabled']);
    FeatureAvailability::flush();
    expect(FeatureAvailability::for($user)->allows('integration.square'))->toBeFalse();

    $rule->delete();
    FeatureAvailability::flush();
    expect(FeatureAvailability::for($user)->allows('integration.square'))->toBeTrue();
});

// CCH-1 — for() must route through CacheLockService::rememberLocked (single-flight
// lock + jitter + SWR) rather than a bare Cache::remember, so a staff flush() doesn't
// stampede every concurrent reader into recomputing at once.

it('routes through CacheLockService::rememberLocked with the version-keyed key and configured TTL', function () {
    $user = ovaAvailUser();

    // beforeEach already called flush() once, so the version token isn't the
    // default 0 — read it live rather than assuming a value.
    $version = (int) Cache::get('feature-availability:version', 0);
    $ttl = (new ReflectionClass(FeatureAvailability::class))->getConstant('CACHE_TTL_SECONDS');

    $spy = Mockery::mock(CacheLockService::class);
    $spy->shouldReceive('rememberLocked')
        ->once()
        ->withArgs(function ($key, $ttlArg, $callback) use ($user, $version, $ttl) {
            return $key === "feature-availability:user:{$user->id}:v{$version}"
                && $ttlArg === $ttl
                && $callback instanceof Closure;
        })
        // Run the real closure so the resolved value is still correct — this
        // proves the wiring without faking the underlying resolution logic.
        ->andReturnUsing(fn ($key, $ttlArg, $callback) => $callback());
    app()->instance(CacheLockService::class, $spy);

    expect(FeatureAvailability::for($user)->allows('integration.instagram'))->toBeTrue();
});

it('single-flights resolution across a warm read and re-resolves exactly once after flush', function () {
    $user = ovaAvailUser();

    // resolveOverrides() runs exactly one query against core.feature_availability
    // per invocation (no rules exist here, so it never reaches the segment query).
    // Counting those queries proves the cold/warm/flush resolve pattern without
    // reaching into private statics.
    $resolveCount = 0;
    DB::listen(function ($query) use (&$resolveCount) {
        if (str_contains($query->sql, 'feature_availability')) {
            $resolveCount++;
        }
    });

    // Cold read (first call for this version) — resolves once.
    FeatureAvailability::for($user)->allows('integration.instagram');
    expect($resolveCount)->toBe(1);

    // Warm read (same version, still within TTL) — single-flight lock's fast
    // path hits the cache, no second resolve.
    FeatureAvailability::for($user)->allows('integration.instagram');
    expect($resolveCount)->toBe(1);

    // flush() bumps the version token, changing the cache key — forces exactly
    // one fresh resolve, not a stampede of many.
    FeatureAvailability::flush();
    FeatureAvailability::for($user)->allows('integration.instagram');
    expect($resolveCount)->toBe(2);
});

// CCH-5 — a DB fault inside resolveOverrides() must fail open (absence-=-
// enabled, same contract as "no rules exist") WITHOUT poisoning the primary
// cache key: that key is jittered and backed by a 10x stale-while-revalidate
// copy (CacheLockService), so a fault cached there would be indistinguishable
// from real "everything available" data and would silently open every gate
// for up to ~10 minutes. The fault is instead parked on its own short-TTL
// key, and a sustained run escalates to Nightwatch (EscalatesRepeatedFaults).

it('fails open on a DB fault without poisoning the primary cache, and recovers once the sentinel expires', function () {
    $user = ovaAvailUser();
    Log::spy();

    $version = (int) Cache::get('feature-availability:version', 0);
    $primaryKey = "feature-availability:user:{$user->id}:v{$version}";

    // Force resolveOverrides() to fault deterministically — no need to mock
    // Eloquent internals.
    DB::connection('pgsql')->statement('DROP TABLE core.feature_availability');

    expect(FeatureAvailability::for($user)->allows('integration.instagram'))->toBeTrue();

    // The fault must NOT have landed on the primary (jittered + SWR) key —
    // this is the CCH-5 bug: an empty "everything available" array there is
    // indistinguishable from real "no rules" data.
    expect(Cache::get($primaryKey))->toBeNull()
        ->and(Cache::get($primaryKey.':stale'))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $msg, array $ctx) => $msg === 'feature_availability.resolve_overrides_failed' && $ctx['user_id'] === $user->id);

    // Restore the table and seed a disabling rule — but the fail-open
    // sentinel written by the fault above is still warm, so the very next
    // call must STILL short-circuit to fail-open rather than re-querying.
    // Proves the gate is genuinely TTL-bound, not just "retries until it
    // happens to succeed."
    setupFeatureAvailabilityTable();
    FeatureAvailabilityRule::query()->create(['feature_key' => 'integration.instagram', 'mode' => 'disabled']);
    FeatureAvailability::flush();

    expect(FeatureAvailability::for($user)->allows('integration.instagram'))->toBeTrue();

    // Once the short sentinel TTL elapses, the next call resolves real data
    // again — the fault must not have stuck around for anything like the
    // primary cache's ~10-minute stale window.
    $failopenTtl = (new ReflectionClass(FeatureAvailability::class))->getConstant('FAILOPEN_TTL_SECONDS');
    Carbon::setTestNow(Carbon::now()->addSeconds($failopenTtl + 1));

    expect(FeatureAvailability::for($user)->allows('integration.instagram'))->toBeFalse();
});

it('escalates to Nightwatch once a sustained run of resolveOverrides faults crosses the threshold', function () {
    $user = ovaAvailUser();
    Exceptions::fake();

    $threshold = FeatureAvailability::FAULT_THRESHOLD;
    $failopenTtl = (new ReflectionClass(FeatureAvailability::class))->getConstant('FAILOPEN_TTL_SECONDS');

    DB::connection('pgsql')->statement('DROP TABLE core.feature_availability');

    // Every fault short of the threshold stays a breadcrumb. Advance time past
    // the short fail-open gate between calls so each one is a genuine fresh
    // DB attempt (and fault) rather than a short-circuited cache hit.
    for ($i = 1; $i < $threshold; $i++) {
        expect(FeatureAvailability::for($user)->allows('integration.instagram'))->toBeTrue();
        Exceptions::assertNothingReported();
        Carbon::setTestNow(Carbon::now()->addSeconds($failopenTtl + 1));
    }

    // The threshold-th fault inside the window escalates.
    expect(FeatureAvailability::for($user)->allows('integration.instagram'))->toBeTrue();
    Exceptions::assertReported(QueryException::class);
});
