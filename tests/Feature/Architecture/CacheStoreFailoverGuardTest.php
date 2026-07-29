<?php

// Architecture guard for the OBS-2/3 analytics fault escalation. Laravel's
// FailoverStore swallows a Throwable from its primary leg and silently returns
// the fallback leg's result — so Cache::add()/increment() never throw when
// CACHE_STORE resolves to a failover-chained store. The EscalatesRepeatedFaults
// trait (app/Services/Analytics/Concerns/EscalatesRepeatedFaults.php), wired into
// AnalyticsCacheService::bumpVersion() and AnalyticsDedupGuard::claim(), depends
// entirely on that catch block firing — under a swallowing store it is dead code.
// LIFE-1 (2026-07-28) added a third dependent: SitepageDataResolverService::
// safeQuery()'s presence-probe escalation on the public sitepage read path.
//
// phpunit.xml forces CACHE_STORE=array for the test run itself, so we cannot
// assert on config('cache.default') at runtime — that would test the harness,
// not the deployed config. Instead this parses .env.example directly, which is
// the template every real deploy env is provisioned from.

it('.env.example does not select a failover-chained CACHE_STORE', function () {
    $env = (string) file_get_contents(base_path('.env.example'));

    // Match an uncommented CACHE_STORE=... line (ignore ones inside comments).
    $matched = preg_match('/^CACHE_STORE=(\S+)\s*$/m', $env, $m);

    expect($matched)->toBe(1, 'CACHE_STORE must be explicitly set in .env.example.');

    $store = $m[1];

    expect($store)->not->toBe(
        'failover',
        'CACHE_STORE=failover in .env.example silently disables the analytics fault escalation: '.
        'FailoverStore swallows the Throwable from its primary (redis) leg and returns the fallback '.
        "(array) leg's result instead, so Cache::add()/increment() never throw. That makes the catch ".
        'blocks in AnalyticsCacheService::bumpVersion() and AnalyticsDedupGuard::claim() unreachable, '.
        'and their EscalatesRepeatedFaults escalation never runs.'
    );

    // Also reject any store NAME that resolves (via config/cache.php's stores map)
    // to the 'failover' driver, in case the store gets renamed/aliased.
    $driver = config("cache.stores.{$store}.driver");

    expect($driver)->not->toBe(
        'failover',
        "CACHE_STORE={$store} in .env.example resolves to the 'failover' driver in config/cache.php, ".
        'which swallows cache-fault Throwables the same way the literal failover store does — this '.
        'silently disables the analytics fault escalation (see EscalatesRepeatedFaults).'
    );
});

it('the failover store definition still exists in config/cache.php', function () {
    // If someone deletes the 'failover' store entirely, the premise behind the
    // guard above (a store NAME can resolve to the failover driver) needs a
    // human to re-examine it, not a silently-passing test.
    expect(config('cache.stores.failover.driver'))->toBe('failover');
});
