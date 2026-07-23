<?php

use App\Services\Auth\TokenRevocationService;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

// Backend-owned reconciliation of the tracked-session set against Supabase's
// live sessions (core.live_session_ids). The RPC is Postgres-only and cannot
// run on the SQLite test DB, so we override the single seam — fetchLiveSessionIds()
// — to inject a canned live set (or an error) instead of hitting Postgres.
//
// Deliberately NO Redis::flushdb() here — the parallel test runner shares one
// Redis DB, so a flush races sibling Redis-backed suites. Unique per-test ids
// provide isolation instead (mirrors SessionLastSeenTest).

/**
 * A TokenRevocationService whose live-session lookup returns a canned set (or
 * throws, to exercise the fail-open path) instead of querying Postgres.
 */
function reconcilingService(?array $live, bool $throw = false): TokenRevocationService
{
    return new class($live, $throw) extends TokenRevocationService
    {
        public function __construct(private ?array $live, private bool $throw) {}

        protected function fetchLiveSessionIds(string $userId): ?array
        {
            if ($this->throw) {
                throw new RuntimeException('live lookup failed');
            }

            return $this->live;
        }
    };
}

beforeEach(function () {
    $this->userId = 'user-'.Str::uuid();
});

it('prunes tracked sessions Supabase no longer considers live', function () {
    $live = 'sess-'.Str::uuid();
    $dead = 'sess-'.Str::uuid();
    $setKey = 'auth:user-sessions:'.$this->userId;

    $svc = reconcilingService([$live]);
    $svc->trackForUser($this->userId, $live, ['ip' => '1.2.3.4', 'user_agent' => 'A']);
    $svc->trackForUser($this->userId, $dead, ['ip' => '5.6.7.8', 'user_agent' => 'B']);

    $list = $svc->listSessionsForUser($this->userId);

    expect(array_column($list, 'session_id'))->toBe([$live]);
    expect((bool) Redis::sismember($setKey, $dead))->toBeFalse();
    expect((bool) Redis::sismember($setKey, $live))->toBeTrue();
    // untrack() also clears the dead session's metadata hash.
    expect((int) Redis::exists('auth:session-meta:'.$dead))->toBe(0);
});

it('leaves the list unpruned when the live lookup is unavailable (fail-open, null)', function () {
    $a = 'sess-'.Str::uuid();
    $b = 'sess-'.Str::uuid();

    $svc = reconcilingService(null); // lookup unavailable
    $svc->trackForUser($this->userId, $a, ['ip' => '1.2.3.4', 'user_agent' => 'A']);
    $svc->trackForUser($this->userId, $b, ['ip' => '5.6.7.8', 'user_agent' => 'B']);

    expect($svc->listSessionsForUser($this->userId))->toHaveCount(2);
});

it('leaves the list unpruned when the live lookup throws (fail-open, error)', function () {
    $a = 'sess-'.Str::uuid();
    $b = 'sess-'.Str::uuid();

    $svc = reconcilingService(null, throw: true);
    $svc->trackForUser($this->userId, $a, ['ip' => '1.2.3.4', 'user_agent' => 'A']);
    $svc->trackForUser($this->userId, $b, ['ip' => '5.6.7.8', 'user_agent' => 'B']);

    // Must not throw — an error in the live lookup can never break the list.
    expect($svc->listSessionsForUser($this->userId))->toHaveCount(2);
});

it('never untracks the current request session even when absent from the live set', function () {
    $current = 'sess-'.Str::uuid();
    $dead = 'sess-'.Str::uuid();
    $setKey = 'auth:user-sessions:'.$this->userId;

    // Live set contains NEITHER the current session nor the dead one.
    $svc = reconcilingService([]);
    $svc->trackForUser($this->userId, $current, ['ip' => '1.2.3.4', 'user_agent' => 'A']);
    $svc->trackForUser($this->userId, $dead, ['ip' => '5.6.7.8', 'user_agent' => 'B']);

    $list = $svc->listSessionsForUser($this->userId, $current);

    // dead pruned, current preserved by the guard.
    expect(array_column($list, 'session_id'))->toBe([$current]);
    expect((bool) Redis::sismember($setKey, $current))->toBeTrue();
    expect((bool) Redis::sismember($setKey, $dead))->toBeFalse();
});

it('sets the tracked-set TTL on first track and does not extend it on later tracks', function () {
    $setKey = 'auth:user-sessions:'.$this->userId;
    $svc = new TokenRevocationService;

    $svc->trackForUser($this->userId, 'sess-'.Str::uuid(), ['ip' => '1.2.3.4', 'user_agent' => 'A']);
    expect(Redis::ttl($setKey))->toBeGreaterThan(0);

    // Age the TTL down, then track again. A correct implementation sets the TTL
    // only when the set has none, so a later track must NOT bump it back up.
    Redis::expire($setKey, 100);
    $svc->trackForUser($this->userId, 'sess-'.Str::uuid(), ['ip' => '5.6.7.8', 'user_agent' => 'B']);

    expect(Redis::ttl($setKey))->toBeLessThanOrEqual(100);
});
