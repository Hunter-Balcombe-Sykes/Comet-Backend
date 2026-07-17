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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSegmentsTables();
    setupFeatureAvailabilityTable();

    DB::connection('pgsql')->statement('DELETE FROM core.users');
    DB::connection('pgsql')->statement('DELETE FROM core.feature_availability');
    DB::connection('pgsql')->statement('DELETE FROM core.user_segments');

    FeatureAvailability::flush();
});

function ovaAvailUser(): User
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => 'avail-'.Str::random(6),
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
