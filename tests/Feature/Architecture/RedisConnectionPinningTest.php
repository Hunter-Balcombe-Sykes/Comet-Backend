<?php

// Architecture test — caller-side half of the read_timeout incident guarded
// by RedisTimeoutBoundsTest.
//
// Drill 03 (2026-08-05) measured an authenticated request taking 32.01s
// against a hung Redis. RedisTimeoutBoundsTest proved the CONNECTION was the
// problem (`default` at read_timeout 15.0s, a bound sized for queue workers'
// BLPOP), but the connection wasn't the whole story: the actual trigger was
// TokenRevocationService calling the bare `Redis::` facade, which resolves to
// `default` by config('database.redis.default') — nothing forced it onto the
// request-appropriate `app` connection (read_timeout 3.0s). U1 (same date)
// added `app` and repointed every caller it found. That fix is a snapshot,
// not an invariant: nothing stops the next request-path `Redis::get(...)`
// from silently inheriting `default` and reproducing the exact incident.
// This test is that invariant.
//
// Modelled on RawCacheCallGuardTest (GS-1), which solved the identical shape
// of problem for the Cache facade: token_get_all() has no concept of a line
// or a string/comment, so — unlike a line-oriented grep — a comment that
// merely mentions `Redis::eval()` (TokenRevocationService's own class
// docblock, RecordCacheMetrics's __destruct() docblock) cannot trip this, and
// splitting `Redis` from `::method(...)` across lines cannot evade it either.
// See RedisConnectionPinningScanner for the token-walking logic.

use Tests\Support\Architecture\RedisConnectionPinningScanner;

it('has no bare Redis::<command>() call in request-path source outside the job/worker allowlist', function () {
    $hits = RedisConnectionPinningScanner::unallowlistedBareFacadeCalls();

    expect($hits)->toBe([], PHP_EOL.PHP_EOL
        .'Found bare Redis::<command>() call(s) in request-path source:'.PHP_EOL
        .implode(PHP_EOL, array_map(fn ($h) => "  {$h}", $hits)).PHP_EOL.PHP_EOL
        .'The bare `Redis::` facade resolves to config(\'database.redis.default\') — '.PHP_EOL
        .'read_timeout 15.0s, reserved for queue workers\' BLPOP. On the request path '.PHP_EOL
        .'this means a hung Redis blocks the response for 15s instead of 3s (drill 03, '.PHP_EOL
        .'2026-08-05). Use Redis::connection(\'app\') on the request path; see '.PHP_EOL
        .'config/database.php. If this file is genuinely job/worker-only, add it to '.PHP_EOL
        .'RedisConnectionPinningScanner::ALLOWLIST with a comment explaining why.'.PHP_EOL);
});

it('proves the guard can fail: every known-bad fixture IS caught', function () {
    $hits = RedisConnectionPinningScanner::bareFacadeCalls([
        'tests/fixtures/guards/redis-pinning',
    ]);

    // Named individually, not counted — a count assertion would pass even if
    // the scanner only ever caught the easy single-line case.
    $files = array_unique(array_map(
        fn ($relative) => basename($relative),
        array_keys($hits)
    ));

    expect($files)->toContain('single-line.stub')
        ->and($files)->toContain('wrapped-method-call.stub');
});

it('ignores Redis:: mentions that appear only in a comment', function () {
    // Regression pin: this is exactly the trap RawCacheCallScanner's real
    // ALLOWLIST fell into once already (a comment warning "never call
    // Cache::put() here" read as the call it forbids) — TokenRevocationService's
    // and RecordCacheMetrics's docblocks both mention Redis::eval()/Redis::
    // for real, so a text-matching version of this guard would false-positive
    // on files that were already fixed.
    $hits = RedisConnectionPinningScanner::bareFacadeCalls([
        'tests/fixtures/guards/redis-pinning',
    ]);

    $files = array_unique(array_map(
        fn ($relative) => basename($relative),
        array_keys($hits)
    ));

    expect($files)->not->toContain('comment-only.stub');
});

it('does not flag the sanctioned Redis::connection(...) escape hatch', function () {
    $hits = RedisConnectionPinningScanner::bareFacadeCalls([
        'tests/fixtures/guards/redis-pinning',
    ]);

    $files = array_unique(array_map(
        fn ($relative) => basename($relative),
        array_keys($hits)
    ));

    expect($files)->not->toContain('connection-call.stub');
});
