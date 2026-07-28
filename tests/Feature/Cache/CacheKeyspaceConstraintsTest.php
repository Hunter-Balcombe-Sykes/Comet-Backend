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

use Illuminate\Support\Facades\File;

it('no cache write in app/ uses forever()', function () {
    // Files permitted to call forever(). Starts empty and should stay empty.
    // Adding an entry means asserting that the key is genuinely not safe to
    // lose — the same category as a queued job. If it is merely convenient,
    // it is a cache entry and needs a TTL instead.
    $allowlist = [];

    $offenders = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = 'app/'.str_replace('\\', '/', $file->getRelativePathname());

        if (in_array($relative, $allowlist, true)) {
            continue;
        }

        foreach (file($file->getRealPath()) as $index => $line) {
            if (preg_match('/(?:->|::)\s*forever\s*\(/', $line)) {
                $offenders[] = $relative.':'.($index + 1).' — '.trim($line);
            }
        }
    }

    $message = "A cache write with no TTL was found. Every cache key must expire — see the\n"
        ."docblock at the top of this file for why (shared Valkey instance, volatile-lru,\n"
        ."queue-job protection). Replace forever() with Cache::put(\$key, \$value, \$ttl).\n\n"
        .implode("\n", $offenders);

    expect($offenders)->toBe([], $message);
});
