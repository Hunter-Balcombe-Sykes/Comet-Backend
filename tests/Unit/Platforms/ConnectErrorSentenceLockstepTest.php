<?php

use App\Http\Controllers\Api\Platforms\Concerns\DefersBespokeConnect;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Companion to tests/Feature/Database/ConstraintVocabularyLockstepTest.php's
// idiom, applied to a different kind of drift: two PUBLISHED FRONTEND-CONTRACT
// sentences (docs/frontend-contracts/2026-07-23-platform-connect-async.md) that
// tell the dashboard "our infrastructure failed, offer retry" as distinct from
// a vendor miss. Reword one copy and the contract silently drifts for some
// platforms and not others — the frontend sees no error, just a wrong message.
//
// FetchUnavailableException is the canonical home for both (Phase 3 moved
// STALE_CONNECT_ERROR there precisely because PHP cannot reference a trait
// constant externally and a Job depending on a Controller trait for a string is
// a layering inversion). W9's ShopController and ShopBrandConnectJob both read
// the canonical constants directly, so they are NOT separate copies and are not
// asserted here.
//
// Two hand-typed copies still remain, both in files W9 must not edit (a sibling
// branch owns them). They are what this test exists to pin:
//
//   STALE_CONNECT_ERROR  → GenericPlatformController::connectStatus()'s inline
//                          literal in the stale-pending branch. Predates the
//                          canonical home; left alone deliberately (design §7
//                          R3 accepted the duplication over editing the eight
//                          already-armed registry platforms for a cosmetic win).
//   GENERIC_USER_MESSAGE → DefersBespokeConnect::UNKNOWN_CONNECT_ERROR, still a
//                          `private const` on the trait.
//
// If a future change collapses either remaining copy into the canonical
// constant, delete the corresponding case here rather than weakening it — the
// extractor below throws loudly if the source shape moves, so this test can
// never degrade into a vacuous pass.

/** Read an app/ source file by path relative to app_path(). */
function connectErrorSentenceSource(string $relativeAppPath): string
{
    $path = app_path($relativeAppPath);
    expect(file_exists($path))->toBeTrue("Expected source file [$relativeAppPath] to exist at $path.");

    return (string) file_get_contents($path);
}

/** Extract $pattern's first capture group from $source, or fail loudly. */
function connectErrorSentenceExtract(string $source, string $pattern, string $label): string
{
    if (! preg_match($pattern, $source, $m)) {
        throw new RuntimeException("Could not find the expected literal for [$label] — the source shape has changed; update this test's pattern, do not delete the assertion.");
    }

    return $m[1];
}

it('STALE_CONNECT_ERROR reads identically from its canonical home and GenericPlatformController', function () {
    $canonical = FetchUnavailableException::STALE_CONNECT_ERROR;

    // Double-quoted at the call site — the sentence contains an apostrophe.
    $generic = connectErrorSentenceExtract(
        connectErrorSentenceSource('Http/Controllers/Api/Platforms/GenericPlatformController.php'),
        '/\'error\'\s*=>\s*"([^"]*)"\]\)/',
        'GenericPlatformController::connectStatus() stale-pending literal',
    );

    expect($canonical)->not->toBe('');
    expect($generic)->toBe($canonical);
});

it('GENERIC_USER_MESSAGE reads identically from its canonical home and the DefersBespokeConnect trait', function () {
    $canonical = FetchUnavailableException::GENERIC_USER_MESSAGE;

    // Reflection reads a `private const`, and works directly on a trait — no
    // using-class shim needed.
    $trait = (new ReflectionClass(DefersBespokeConnect::class))->getConstant('UNKNOWN_CONNECT_ERROR');

    expect($trait)->not->toBeFalse('DefersBespokeConnect::UNKNOWN_CONNECT_ERROR has moved or been removed — if it was collapsed into FetchUnavailableException::GENERIC_USER_MESSAGE, delete this case rather than weakening it.');
    expect($canonical)->not->toBe('');
    expect($trait)->toBe($canonical);
});

it('W9 reads the canonical constants rather than keeping its own copies', function () {
    // Regression guard on the W9 → Phase 3 reconciliation: ShopController and
    // ShopBrandConnectJob previously forked both sentences as private consts,
    // because STALE_CONNECT_ERROR lived on the trait and a Job could not reach
    // it. Phase 3's neutral home removed that reason, so a reintroduced fork
    // would be pure drift risk.
    foreach ([
        'Http/Controllers/Api/Platforms/ShopController.php',
        'Jobs/Platforms/ShopBrandConnectJob.php',
    ] as $relative) {
        $source = connectErrorSentenceSource($relative);

        expect($source)->not->toContain(FetchUnavailableException::STALE_CONNECT_ERROR, "[$relative] hand-types the stale-connect sentence; read FetchUnavailableException::STALE_CONNECT_ERROR instead.");
        expect($source)->not->toContain(FetchUnavailableException::GENERIC_USER_MESSAGE, "[$relative] hand-types the generic-failure sentence; read FetchUnavailableException::GENERIC_USER_MESSAGE instead.");
    }
});
