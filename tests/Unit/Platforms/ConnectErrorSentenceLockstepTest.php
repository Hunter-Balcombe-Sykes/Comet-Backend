<?php

use App\Http\Controllers\Api\Platforms\Concerns\DefersBespokeConnect;
use App\Jobs\Platforms\ShopBrandConnectJob;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Companion to tests/Feature/Database/ConstraintVocabularyLockstepTest.php's
// idiom, applied to a different kind of drift: two PUBLISHED FRONTEND-CONTRACT
// sentences (docs/frontend-contracts/2026-07-23-platform-connect-async.md)
// that tell the frontend "our infrastructure failed, offer retry" as distinct
// from a vendor miss. Each sentence exists in THREE places today:
//
//   STALE_CONNECT_ERROR   "We couldn't save your connection just then — please try again."
//     - DefersBespokeConnect::STALE_CONNECT_ERROR (private const)
//     - GenericPlatformController::connectStatus()'s inline literal (stale-pending branch)
//     - ShopBrandConnectJob::STALE_CONNECT_ERROR (private const — this job's own fork)
//
//   UNKNOWN_CONNECT_ERROR "We could not load that account. Please try again."
//     - DefersBespokeConnect::UNKNOWN_CONNECT_ERROR (private const)
//     - ConnectFetchJob::failed()'s inline literal fallback
//     - ShopBrandConnectJob::UNKNOWN_CONNECT_ERROR (private const — this job's own fork)
//
// WHY THE DUPLICATION EXISTS — do not "helpfully" dedupe it, it will conflict:
//   - DefersBespokeConnect's two consts are `private`, so they are reachable
//     ONLY from a class that `use`s the trait. ShopBrandConnectJob (a Job, not
//     a bespoke-connect Controller) deliberately does NOT `use` the trait:
//     doing so would drag deferredConnectResponse()/bespokeConnectStatus() —
//     both shaped for ApiController + ManagesIntegrationConnection — onto a
//     class that needs neither, plus risk PHPStan noise on the pulled-in,
//     never-called methods.
//   - DefersBespokeConnect.php is BYTE-IDENTICAL with the sibling Phase-3
//     branch (audit-fix/connect-async-impl-2026-07-24 — W9 plan §2) and must
//     not be edited to widen visibility: that would turn a guaranteed no-op
//     merge into a real conflict for a cosmetic win.
//   - GenericPlatformController and ConnectFetchJob predate DefersBespokeConnect
//     and were deliberately left alone — the design doc's own §7 Risk R3
//     already accepted a second copy of this exact staleness logic as the
//     cheaper trade over generalising the trait mid-flight.
//
// None of the four sites can be collapsed into one without editing a file
// this branch (or a sibling) must not touch, so the only guard against silent
// wording drift — e.g. someone rewording ShopBrandConnectJob's fork without
// touching the other three, which the frontend would never notice as an error,
// only as a wrong message shown to a user — is a test that reads every live
// copy and diffs them against each other.
//
// HOW IT WORKS — mirrors ConstraintVocabularyLockstepTest exactly: constants
// are read via Reflection (works on `private const`, and directly on a trait —
// no using-class shim needed), the two inline literals are read out of their
// source files as text via regex, the same way lockstepMigrationSql() reads a
// migration file. No copy is hardcoded into this test.

/** Read an app/ source file's contents by path relative to app_path(). */
function connectErrorSentenceSource(string $relativeAppPath): string
{
    $path = app_path($relativeAppPath);
    expect(file_exists($path))->toBeTrue("Expected source file [$relativeAppPath] to exist at $path.");

    return (string) file_get_contents($path);
}

/** Extract the first capture group of $pattern from $source, or fail loudly. */
function connectErrorSentenceExtract(string $source, string $pattern, string $label): string
{
    if (! preg_match($pattern, $source, $m)) {
        throw new RuntimeException("Could not find the expected literal for [$label] — the source shape has changed; update this test's pattern.");
    }

    return $m[1];
}

it('STALE_CONNECT_ERROR reads identically from DefersBespokeConnect, GenericPlatformController and ShopBrandConnectJob', function () {
    $trait = (new ReflectionClass(DefersBespokeConnect::class))->getConstant('STALE_CONNECT_ERROR');
    $job = (new ReflectionClass(ShopBrandConnectJob::class))->getConstant('STALE_CONNECT_ERROR');

    // GenericPlatformController::connectStatus() — double-quoted (the sentence
    // contains an apostrophe), inline in the stale-pending branch's response array.
    $generic = connectErrorSentenceExtract(
        connectErrorSentenceSource('Http/Controllers/Api/Platforms/GenericPlatformController.php'),
        '/\'error\'\s*=>\s*"([^"]*)"\]\)/',
        'GenericPlatformController::connectStatus() stale-pending literal',
    );

    expect($trait)->not->toBeFalse()->not->toBe('');
    expect($job)->toBe($trait);
    expect($generic)->toBe($trait);
});

it('UNKNOWN_CONNECT_ERROR reads identically from DefersBespokeConnect, ConnectFetchJob and ShopBrandConnectJob', function () {
    $trait = (new ReflectionClass(DefersBespokeConnect::class))->getConstant('UNKNOWN_CONNECT_ERROR');
    $job = (new ReflectionClass(ShopBrandConnectJob::class))->getConstant('UNKNOWN_CONNECT_ERROR');

    // ConnectFetchJob::failed() — single-quoted (no apostrophe in this
    // sentence), the ?? fallback when the registry has no descriptor message.
    $connectFetch = connectErrorSentenceExtract(
        connectErrorSentenceSource('Jobs/Platforms/ConnectFetchJob.php'),
        '/\?\?\s*\'([^\']*)\'/',
        'ConnectFetchJob::failed() fallback literal',
    );

    expect($trait)->not->toBeFalse()->not->toBe('');
    expect($job)->toBe($trait);
    expect($connectFetch)->toBe($trait);
});
