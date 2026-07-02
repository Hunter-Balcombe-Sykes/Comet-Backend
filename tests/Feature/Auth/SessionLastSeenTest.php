<?php

use App\Services\Auth\TokenRevocationService;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

// Rolling last-activity marker (Active Sessions "Active 2h ago" line).
//
// Deliberately NO Redis::flushdb() here — the parallel test runner shares one
// Redis DB across worker processes, so a flush in this file races other
// Redis-backed suites (TokenRevocationServiceTest, streaming caches). Unique
// per-test ids provide the isolation instead.
beforeEach(function () {
    $this->service = new TokenRevocationService;
    $this->userId = 'user-'.Str::uuid();
    $this->sessionId = 'sess-'.Str::uuid();
});

it('records last_seen_at when a session is tracked', function () {
    $this->service->trackForUser($this->userId, $this->sessionId, ['ip' => '1.2.3.4', 'user_agent' => 'Mozilla/5.0']);

    $list = $this->service->listSessionsForUser($this->userId);

    expect($list)->toHaveCount(1)
        ->and($list[0]['last_seen_at'])->toBeInt()
        ->and($list[0]['last_seen_at'])->toBeGreaterThan(0);
});

it('throttles repeat touches inside the interval', function () {
    $this->service->trackForUser($this->userId, $this->sessionId, ['ip' => '1.2.3.4', 'user_agent' => 'Mozilla/5.0']);
    $first = $this->service->listSessionsForUser($this->userId)[0]['last_seen_at'];

    // Manually age the stored value; a second track inside the interval must
    // NOT overwrite it (the NX marker is still alive).
    Redis::hset('auth:session-meta:'.$this->sessionId, 'last_seen_at', (string) ($first - 5000));
    $this->service->trackForUser($this->userId, $this->sessionId, ['ip' => '1.2.3.4', 'user_agent' => 'Mozilla/5.0']);

    expect($this->service->listSessionsForUser($this->userId)[0]['last_seen_at'])->toBe($first - 5000);
});

it('touches again once the interval marker expires', function () {
    $this->service->trackForUser($this->userId, $this->sessionId, ['ip' => '1.2.3.4', 'user_agent' => 'Mozilla/5.0']);
    Redis::hset('auth:session-meta:'.$this->sessionId, 'last_seen_at', '1000');

    // Simulate the marker lapsing (interval elapsed).
    Redis::del('auth:session-touch:'.$this->sessionId);
    $this->service->trackForUser($this->userId, $this->sessionId, ['ip' => '1.2.3.4', 'user_agent' => 'Mozilla/5.0']);

    expect($this->service->listSessionsForUser($this->userId)[0]['last_seen_at'])->toBeGreaterThan(1000);
});

it('returns null last_seen_at for legacy sessions without the field', function () {
    // Seed a pre-touch session shape directly: tracked set + meta hash
    // without last_seen_at.
    Redis::sadd('auth:user-sessions:'.$this->userId, $this->sessionId);
    Redis::hmset('auth:session-meta:'.$this->sessionId, [
        'user_id' => $this->userId,
        'created_at' => '1700000000',
        'ip_prefix' => '1.2.3.0',
        'browser_family' => 'Chrome',
        'platform' => 'macOS',
    ]);

    $list = $this->service->listSessionsForUser($this->userId);

    expect($list)->toHaveCount(1)
        ->and($list[0]['last_seen_at'])->toBeNull();
});
