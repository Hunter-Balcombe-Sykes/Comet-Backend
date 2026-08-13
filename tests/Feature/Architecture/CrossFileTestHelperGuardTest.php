<?php

use Tests\Support\Architecture\TestHelperScanner;

/**
 * G — no test file may declare a helper that another test file calls.
 *
 * A global function declared in a Pest test file exists only once PHPUnit has
 * included that file. Serially every test file is included during discovery, so
 * a cross-file helper works. Under `--parallel` each worker includes only its
 * own assigned files, and a caller that lands without its declaring file fatals
 * with "Call to undefined function".
 *
 * This has bitten twice. poolTenant and family (declared in PoolLaneTest) took
 * out 16 tests across the Media* trio; slice 5b then reintroduced the identical
 * shape within a day — shopStore/shopProduct and frameAsset — for 8 more. The
 * second one was caught only because someone was merging behind it.
 *
 * Fix for a violation: move the helper into tests/Helpers/ and require_once it
 * from tests/Pest.php, which every worker loads. Call sites do not change.
 * There is deliberately no allowlist — that remedy always applies.
 */
it('has no helper declared in one test file and called from another', function () {
    $hazards = TestHelperScanner::crossFileHelpers();

    $report = implode("\n", array_map(
        static fn (string $fn, array $h): string => sprintf(
            "  %s()  declared in %s\n      called by %s",
            $fn,
            $h['declaredIn'],
            implode("\n      called by ", $h['calledBy']),
        ),
        array_keys($hazards),
        $hazards,
    ));

    expect($hazards)->toBe([], $hazards === [] ? '' : <<<TXT
    Cross-file test helpers found. Each one fatals under --parallel whenever the
    calling file lands in a worker that was not also assigned the declaring file:

    {$report}

    Move each helper into tests/Helpers/ and require_once it from tests/Pest.php.
    Call sites do not need to change.
    TXT);
});

// The guard above is an assertion that something is ABSENT, which passes just
// as happily when the scanner is broken as when the tree is clean. These pin
// that it can actually see a violation, and that it does not invent one.

it('flags a helper declared in one fixture and called from another', function () {
    $hazards = TestHelperScanner::crossFileHelpers('tests/fixtures/cross-file-helpers', ['stub']);

    expect($hazards)->toHaveKey('fixtureHelper')
        ->and($hazards['fixtureHelper']['declaredIn'])
        ->toBe('tests/fixtures/cross-file-helpers/declares_helper.stub');
});

it('does not flag comments, strings, or method calls that merely name a helper', function () {
    $hazards = TestHelperScanner::crossFileHelpers('tests/fixtures/cross-file-helpers', ['stub']);

    // mentions_only.stub names fixtureHelper in a comment, a docblock, a string
    // literal, a nowdoc, an instance call, a nullsafe call and a static call.
    // Exactly one fixture calls it for real.
    expect($hazards['fixtureHelper']['calledBy'])
        ->toBe(['tests/fixtures/cross-file-helpers/calls_helper.stub']);
});

it('does not treat a class method as a global declaration', function () {
    // declares_helper.stub also declares FixtureHolder::fixtureHelper() and
    // ::alsoNotGlobal(). Neither is global, so alsoNotGlobal must never appear.
    $hazards = TestHelperScanner::crossFileHelpers('tests/fixtures/cross-file-helpers', ['stub']);

    expect($hazards)->not->toHaveKey('alsoNotGlobal');
});
