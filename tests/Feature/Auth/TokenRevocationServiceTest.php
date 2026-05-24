<?php

use App\Services\Auth\TokenRevocationService;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::flushdb();
    $this->service = new TokenRevocationService;
});

it('revokes a session and reports it as revoked', function () {
    expect($this->service->isRevoked('sess-1'))->toBeFalse();
    $this->service->revoke('sess-1');
    expect($this->service->isRevoked('sess-1'))->toBeTrue();
});

it('ignores empty session ids', function () {
    $this->service->revoke('');
    expect($this->service->isRevoked(''))->toBeFalse();
});

it('tracks sessions per user and lists them with metadata', function () {
    // SEC-3: raw ip/user_agent inputs are transformed into ip_prefix /
    // browser_family / platform on write (privacy hygiene). The transform is
    // deterministic so the test can assert on the post-truncation values.
    $this->service->trackForUser('user-1', 'sess-a', ['ip' => '1.2.3.4', 'user_agent' => 'Mozilla/5.0']);
    $this->service->trackForUser('user-1', 'sess-b', ['ip' => '5.6.7.8', 'user_agent' => 'Mozilla/5.0 (X11) Chrome/120 Safari/537.36']);

    $list = $this->service->listSessionsForUser('user-1');
    expect($list)->toHaveCount(2);
    expect($list[0]['session_id'])->toBeIn(['sess-a', 'sess-b']);
    expect($list[0]['ip_prefix'])->toBeIn(['1.2.3.0', '5.6.7.0']);
    expect($list[0]['browser_family'])->toBeIn(['Other', 'Chrome']);
});

it('excludes revoked sessions from the list', function () {
    $this->service->trackForUser('user-1', 'sess-a', ['ip' => '1.2.3.4', 'user_agent' => 'A']);
    $this->service->trackForUser('user-1', 'sess-b', ['ip' => '5.6.7.8', 'user_agent' => 'B']);
    $this->service->revoke('sess-a');

    $list = $this->service->listSessionsForUser('user-1');
    expect($list)->toHaveCount(1);
    expect($list[0]['session_id'])->toBe('sess-b');
});

it('revokes all other sessions while keeping the current one', function () {
    $this->service->trackForUser('user-1', 'sess-a', ['ip' => '1', 'user_agent' => 'a']);
    $this->service->trackForUser('user-1', 'sess-b', ['ip' => '2', 'user_agent' => 'b']);
    $this->service->trackForUser('user-1', 'sess-c', ['ip' => '3', 'user_agent' => 'c']);

    $count = $this->service->revokeAllForUser('user-1', exceptSessionId: 'sess-b');

    expect($count)->toBe(2);
    expect($this->service->isRevoked('sess-a'))->toBeTrue();
    expect($this->service->isRevoked('sess-c'))->toBeTrue();
    expect($this->service->isRevoked('sess-b'))->toBeFalse();
});

it('untracks a session without revoking it', function () {
    $this->service->trackForUser('user-1', 'sess-a', ['ip' => '1', 'user_agent' => 'a']);
    $this->service->untrack('user-1', 'sess-a');

    expect($this->service->isRevoked('sess-a'))->toBeFalse();
    expect($this->service->listSessionsForUser('user-1'))->toBeEmpty();
});

it('only writes metadata on first sight (preserves first-seen IP)', function () {
    // LIFE-2 + SEC-3: first writer wins the HSETNX race; the second call
    // sees the _init sentinel and backs off without overwriting. The stored
    // value reflects the FIRST request's metadata, post-transform.
    $this->service->trackForUser('user-1', 'sess-a', ['ip' => '1.1.1.1', 'user_agent' => 'first']);
    $this->service->trackForUser('user-1', 'sess-a', ['ip' => '2.2.2.2', 'user_agent' => 'second']);

    $list = $this->service->listSessionsForUser('user-1');
    expect($list)->toHaveCount(1);
    expect($list[0]['ip_prefix'])->toBe('1.1.1.0');
    expect($list[0]['browser_family'])->toBe('Other'); // 'first'/'second' don't match any UA pattern
});
