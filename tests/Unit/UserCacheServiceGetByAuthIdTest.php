<?php

use App\Services\Cache\CacheLockService;
use App\Services\Cache\UserCacheService;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\TestCase;

// Unit tests for UserCacheService::getByAuthId — specifically the fix for #P2-15:
// when User::find returns null (deleted user), the null must be cached via
// rememberLockedNullable so subsequent requests don't re-acquire the lock and
// re-query Postgres on every request for the full TTL window.
uses(TestCase::class)->in(__FILE__);

it('returns null and calls rememberLockedNullable when the user model lookup returns null', function () {
    // Arrange: mock CacheLockService so we can assert WHICH method is called.
    // getIdByAuthId (the first step) can return null directly; we only care about
    // the second-level call to rememberLockedNullable for the model cache.
    //
    // Strategy: let getIdByAuthId succeed (returns a fake id via rememberLockedNullable),
    // then assert the model-level call also uses rememberLockedNullable and returns null.
    $authUserId = 'auth-uid-test-deleted';
    $userId = 'user-id-test-deleted';

    /** @var MockInterface&CacheLockService $lockMock */
    $lockMock = Mockery::mock(CacheLockService::class);

    // First call: getIdByAuthId → rememberLockedNullable → returns the fake userId.
    $lockMock->shouldReceive('rememberLockedNullable')
        ->once()
        ->withArgs(fn ($key, $ttl, $cb) => str_contains((string) $key, $authUserId))
        ->andReturn($userId);

    // Second call: the model cache in getByAuthId → must also be rememberLockedNullable
    // (not rememberLocked). The closure returns null (simulating a deleted user).
    // We capture the closure to verify it returns null, and return null ourselves.
    $lockMock->shouldReceive('rememberLockedNullable')
        ->once()
        ->withArgs(fn ($key, $ttl, $cb, ...$rest) => str_contains((string) $key, $userId))
        ->andReturnUsing(function ($key, $ttl, Closure $cb) {
            // Execute the real closure — it would call User::find(id) which returns null.
            // We just return null here to simulate the cached-null path.
            return null;
        });

    // rememberLocked must NOT be called at all — that's the old broken behaviour.
    $lockMock->shouldNotReceive('rememberLocked');

    $service = new UserCacheService($lockMock);
    $result = $service->getByAuthId($authUserId);

    expect($result)->toBeNull();
});

it('does not call rememberLocked for the model cache layer (regression guard)', function () {
    // This test fails with the OLD code (rememberLocked) and passes with the fix
    // (rememberLockedNullable). It acts as a permanent regression guard.
    $authUserId = 'auth-uid-regression';
    $userId = 'user-id-regression';

    /** @var MockInterface&CacheLockService $lockMock */
    $lockMock = Mockery::mock(CacheLockService::class);

    // ID lookup returns a valid-looking id.
    $lockMock->shouldReceive('rememberLockedNullable')
        ->once()
        ->withArgs(fn ($key) => str_contains((string) $key, $authUserId))
        ->andReturn($userId);

    // Model cache — return null (deleted user path). This is the call we're asserting
    // is rememberLockedNullable and NOT rememberLocked.
    $lockMock->shouldReceive('rememberLockedNullable')
        ->once()
        ->withArgs(fn ($key) => str_contains((string) $key, $userId))
        ->andReturn(null);

    // The old code called rememberLocked for the model. Assert it is never called.
    $lockMock->shouldNotReceive('rememberLocked');

    $service = new UserCacheService($lockMock);
    $result = $service->getByAuthId($authUserId);

    expect($result)->toBeNull();
});

it('passes a nullTtl argument when calling rememberLockedNullable for the model cache', function () {
    // Confirms that the null-TTL is forwarded so cached nulls expire in 30s, not full TTL.
    $authUserId = 'auth-uid-ttl-check';
    $userId = 'user-id-ttl-check';

    /** @var MockInterface&CacheLockService $lockMock */
    $lockMock = Mockery::mock(CacheLockService::class);

    // ID lookup — allow via any rememberLockedNullable call matching the auth key.
    $lockMock->shouldReceive('rememberLockedNullable')
        ->once()
        ->withArgs(fn ($key) => str_contains((string) $key, $authUserId))
        ->andReturn($userId);

    // Model cache — capture all arguments so we can inspect nullTtl.
    $capturedArgs = [];
    $lockMock->shouldReceive('rememberLockedNullable')
        ->once()
        ->withArgs(fn ($key) => str_contains((string) $key, $userId))
        ->andReturnUsing(function () use (&$capturedArgs) {
            $capturedArgs = func_get_args();

            return null;
        });

    $service = new UserCacheService($lockMock);
    $service->getByAuthId($authUserId);

    // $capturedArgs[3] is the named nullTtl argument (4th positional).
    // It should be a DateTimeInterface (Carbon instance) approximately 30 seconds from now.
    expect($capturedArgs)->not->toBeEmpty();
    expect(isset($capturedArgs[3]))->toBeTrue('nullTtl was not passed to rememberLockedNullable');
    expect($capturedArgs[3])->toBeInstanceOf(DateTimeInterface::class);

    $secondsFromNow = $capturedArgs[3]->getTimestamp() - now()->timestamp;
    // Allow a small window for test execution time (25–35s is acceptable).
    expect($secondsFromNow)->toBeGreaterThanOrEqual(25);
    expect($secondsFromNow)->toBeLessThanOrEqual(35);
});
