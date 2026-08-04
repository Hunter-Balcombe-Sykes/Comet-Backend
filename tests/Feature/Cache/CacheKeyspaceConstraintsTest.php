<?php

// Enforces the cache-keyspace TTL invariant:
//
//   Every key that is safe to lose carries a TTL.
//   Every key that is not safe to lose does not.
//
// Why this matters here specifically: all five Redis connections in
// config/database.php share one REDIS_HOST/REDIS_PORT and differ only by
// database index — 0 (queue + Horizon), 1 (cache), 2 (sessions), 4 (cache
// locks). Redis and Valkey apply maxmemory-policy per INSTANCE, not per
// database. The only safe policy for a shared instance is volatile-lru,
// under which "has a TTL" is exactly the line between data Valkey may
// discard under memory pressure and data it must not. A queued Horizon job
// payload has no TTL and is therefore protected. A cache entry written with
// Cache::forever() would be equally protected — which is wrong: it becomes
// permanent, inevictable ballast that eats the headroom real cache entries
// need, and pushes the instance toward the OOM-on-write state volatile-lru
// exists to avoid.
//
// The guard is deliberately narrow. It matches the CALL form only — `->forever(`
// or `::forever(` — not the bare word, which appears in seven ordinary English
// comments in app/ ("squats its slug forever (…)"). The broader rule — "a raw
// Redis write with no paired expiry" — is not statically checkable without flow
// analysis, and would flag every legitimate two-call write in
// TokenRevocationService, LiveStatusPoller and EnquirySpamBlocklist. A noisy
// guard gets suppressed, and then it protects nothing.
//
// Scope is app/ only. Test files legitimately use Cache::forever() to seed
// fixtures; they never run against a production keyspace.
//
// Design: docs/superpowers/specs/2026-07-28-cache-eviction-policy-hardening-design.md
// Research: docs/superpowers/research/cache-gold-standard-2026-07-28.md §2.1
//
// COV-TAIL-3 (2026-08-04): the "CALL form only" regex above missed a second,
// equally real way to write a permanent key — `Cache::put($key, $value)` with
// no 3rd TTL argument silently forwards to forever() inside the framework,
// and `Cache::remember($key, null, $callback)` is cached forever too, just
// spelled as a literal null TTL instead of a different method name. Neither
// is expressible as a per-line regex: "does this call have fewer than 3
// arguments" and "is this specific argument the literal value null" are
// facts about argument-list STRUCTURE, and a naive comma count over the raw
// text miscounts the moment an argument is itself a multi-line array or a
// closure with its own parameter list (IdempotencyKey.php:130's real shape —
// see CacheTtlScanner's docblock and nested-array-put.stub). The
// file()-based line-by-line loop this test used to run is also the same
// line-oriented shape COV-GUARD-2 already removed from two CI greps for
// exactly this reason — replaced here with CacheTtlScanner, a token-based
// scanner (token_get_all(), comments discarded, bracket-depth aware) that
// mirrors RawCacheCallScanner (GS-1).

use Tests\Support\Architecture\CacheTtlScanner;
use Tests\Support\Architecture\SweepGuard;

it('no cache write in app/ has a missing or null TTL (COV-TAIL-3)', function () {
    // Files permitted to skip this guard. Starts empty and should stay empty.
    // Adding an entry means asserting that the key is genuinely not safe to
    // lose — the same category as a queued job. If it is merely convenient,
    // it is a cache entry and needs a TTL instead.
    $allowlist = [];

    $offenders = CacheTtlScanner::offenders(app_path());

    foreach ($allowlist as $exempt) {
        unset($offenders[$exempt]);
    }

    // COV-GUARD-4: `$offenders === []` is ALSO true when the sweep examined
    // nothing (a PSR-4 move, a renamed facade alias, a changed Finder glob).
    // Assert the denominator — every Cache::(forever|rememberForever|put|
    // remember) call site under app/, offender or not — before asserting the
    // numerator. 20 call sites exist today across 12 files; floor set well
    // below that so ordinary code drift doesn't need a floor bump.
    SweepGuard::assertDenominator(CacheTtlScanner::callSites(app_path()), 15, 'cache TTL sweep over app/');

    $message = "A cache write with a missing or null TTL was found. Every cache key must\n"
        ."expire — see the docblock at the top of this file for why (shared Valkey\n"
        ."instance, volatile-lru, queue-job protection). Use Cache::put(\$key, \$value, \$ttl)\n"
        ."with a real TTL, never forever()/rememberForever()/a null TTL argument.\n\n"
        .implode("\n", array_map(
            fn ($file, $hits) => implode("\n", array_map(fn ($hit) => "{$file}:{$hit}", $hits)),
            array_keys($offenders),
            $offenders
        ));

    expect($offenders)->toBe([], $message);
});

it('proves the guard can fail: every known-bad TTL sample IS caught', function () {
    // Named individually, not counted — a count assertion passes when the
    // scanner only ever catches the easy case repeatedly and the hard cases
    // zero times (COV-GUARD-2).
    $offenders = CacheTtlScanner::offenders(base_path('tests/fixtures/guards/cache-ttl'));
    $files = array_map(fn ($relative) => basename($relative), array_keys($offenders));

    expect($files)->toContain('forever.stub')
        ->and($files)->toContain('wrapped-forever.stub')
        ->and($files)->toContain('two-arg-put.stub')
        ->and($files)->toContain('null-ttl-remember.stub');
});

it('does not flag a correct 3-arg put, a nested-array put, or a comment-only mention', function () {
    // These matter as much as the four above: an over-matching guard gets
    // suppressed, and then it protects nothing.
    //   - three-arg-put.stub: a normal, correct write — over-matching.
    //   - nested-array-put.stub: IdempotencyKey.php:130's real shape (3 args,
    //     arg 2 a multi-line array with its own internal commas) — the naive
    //     comma-counting false positive this scanner's bracket-depth walk
    //     exists to avoid.
    //   - comment-only.stub: the failure mode that put a bogus entry in the
    //     GS-1 allowlist (RawCacheCallScanner::ALLOWLIST).
    $offenders = CacheTtlScanner::offenders(base_path('tests/fixtures/guards/cache-ttl'));
    $files = array_map(fn ($relative) => basename($relative), array_keys($offenders));

    expect($files)->not->toContain('three-arg-put.stub')
        ->and($files)->not->toContain('nested-array-put.stub')
        ->and($files)->not->toContain('comment-only.stub');
});
