<?php

// Architecture test — GS-1: Cache keys must flow through CacheKeyGenerator
// and a *CacheService to preserve tenant-prefix discipline. A rogue
// Cache::put('user_count', $n) in a controller is one git push away from a
// cross-tenant data leak — this guard blocks that class of bug before it
// reaches review.
//
// Replaces the two-stage `git grep -n -P '\bCache::(put|remember|
// rememberForever|forget|add)\b'` that lived in .github/workflows/ci.yml
// until COV-GUARD-2. git grep matches per line, so splitting `Cache` from
// `::method(...)` across lines makes the literal substring never appear on
// one line and the grep finds nothing. Verified empirically:
// tests/fixtures/guards/raw-cache/wrapped-method-call.stub runs the exact old
// grep pattern against it and gets zero hits.
//
// token_get_all() has no concept of a line, so this evasion does not work
// against it. It also discards T_COMMENT/T_DOC_COMMENT before matching, so a
// comment that merely mentions Cache::put(...) cannot force a false allowlist
// entry the way IndividualProfileController's did (see
// RawCacheCallScanner::ALLOWLIST and the stale-allowlist test below).

use Tests\Support\Architecture\RawCacheCallScanner;

it('has no raw Cache::(put|remember|rememberForever|forget|add) call outside the cache-services allowlist', function () {
    $hits = RawCacheCallScanner::unallowlistedCacheCalls(app_path());

    expect($hits)->toBe([], PHP_EOL.PHP_EOL
        .'Found raw Cache::(put|remember|rememberForever|forget|add) call(s) outside the GS-1 allowlist:'.PHP_EOL
        .implode(PHP_EOL, array_map(fn ($h) => "  {$h}", $hits)).PHP_EOL.PHP_EOL
        .'Use a method on a *CacheService (or CacheLockService::rememberLocked) with CacheKeyGenerator for key construction.'.PHP_EOL
        .'If this is a legitimate exception (webhook idempotency, internal token cache), add the file path to '.PHP_EOL
        .'RawCacheCallScanner::ALLOWLIST with a comment explaining why.'.PHP_EOL);
});

it('proves the guard can fail: every known-bad sample IS caught', function () {
    $hits = RawCacheCallScanner::rawCacheCalls(
        base_path('tests/fixtures/guards/raw-cache')
    );

    // Named individually, not counted — a count assertion would pass even if
    // the scanner only ever caught the easy single-line case (COV-GUARD-2).
    $files = array_unique(array_map(
        fn ($relative) => basename($relative),
        array_keys($hits)
    ));

    expect($files)->toContain('single-line.stub')
        ->and($files)->toContain('wrapped-method-call.stub');
});

it('ignores Cache:: mentions that appear only in a comment', function () {
    // Regression pin: this is exactly how the real allowlist acquired its
    // bogus IndividualProfileController entry — a text scanner treats a
    // comment warning ("never call Cache::put() here") as the call it forbids.
    $hits = RawCacheCallScanner::rawCacheCalls(
        base_path('tests/fixtures/guards/raw-cache')
    );

    $files = array_unique(array_map(
        fn ($relative) => basename($relative),
        array_keys($hits)
    ));

    expect($files)->not->toContain('comment-only.stub');
});

it('has no UNEXPECTED stale allowlist entries', function () {
    // A stale entry is a silent hole: the file keeps a standing exemption it
    // no longer needs, and a later edit reintroducing a raw Cache:: call
    // inherits it unreviewed. Six are already known and marked `// STALE …`
    // in RawCacheCallScanner::ALLOWLIST (five are ordinary code drift since
    // their audit date — a refactor onto a proper *CacheService, a rename
    // onto Cache::lock()/Cache::get() which GS-1 never gated, or a file
    // deleted outright — verified 2026-08-03 by re-running the ORIGINAL
    // ci.yml grep against today's tree; only IndividualProfileController is
    // specific to the token migration itself). This test compares against
    // that EXACT known set, so a NEW, unreviewed staleness still fails loud.
    $expectedStale = [
        'app/Observers/Core/CustomerObserver.php',
        'app/Http/Controllers/Api/Platforms/InstagramController.php',
        'app/Http/Controllers/Api/PublicSite/IndividualProfileController.php',
        'app/Services/Design/Presets/Factors/OwnMediaAccentFactor.php',
        'app/Services/Design/Presets/Factors/StorePricePointFactor.php',
        'app/Routing/Probes/ProbeBudget.php',
    ];

    $stale = RawCacheCallScanner::staleAllowlistEntries(app_path());

    expect($stale)->toBe($expectedStale, PHP_EOL.PHP_EOL
        .'The set of stale ALLOWLIST entries changed. Either:'.PHP_EOL
        .'  - a NEW entry went stale — mark it `// STALE …` in ALLOWLIST and add it to '.PHP_EOL
        .'    $expectedStale above (do not delete the entry in this commit); or'.PHP_EOL
        .'  - a previously-stale entry is no longer stale (the file made a real Cache:: '.PHP_EOL
        .'    call again) — remove it from $expectedStale and drop its `// STALE …` marker.'.PHP_EOL);
});
