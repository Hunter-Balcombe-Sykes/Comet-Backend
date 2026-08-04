<?php

// Architecture test — no inline 403 abort in a controller. An inline
// `abort_unless($a === $b, 403)` bypasses the Policy layer entirely; use a
// Policy via authorizeForUser() instead, or abort(422, ...) if the abort is
// really input validation (stale IDs etc.) — see the bulk-id reorder pattern
// in ProfessionalServiceController for precedent.
//
// Replaces the two-stage `grep -rE "abort\(403|abort_unless|abort_if" ... |
// grep 403` that lived in .github/workflows/ci.yml until COV-GUARD-2. That
// pipeline is line-oriented — it needs the literal text "403" on the SAME
// line as the function call. Pint wraps any abort_unless()/abort_if() whose
// message argument is long enough to need multiple lines, and once wrapped
// the two substrings never share a line, so the grep found nothing. Verified:
// tests/fixtures/guards/inline-403/wrapped-args.stub is the IDENTICAL call to
// single-line.stub, reformatted across four lines — the old grep pipeline
// found 0 hits there and 1 in single-line.stub.
//
// token_get_all() has no concept of a line, so this evasion does not work
// against it. Paren-depth tracking also prevents the inverse mistake: a 403
// nested inside another call's argument (`abort_unless($x, 404, foo(403))`)
// must NOT be flagged — see PublicDocumentDownloadController, which has
// wrapped abort_unless(...) calls today that are legitimately 404s, and the
// real sweep below must return [] against it.

use Tests\Support\Architecture\ControllerAbortScanner;

it('has no inline 403 abort in a controller (use a Policy via authorizeForUser(), or abort(422, ...) for real input validation)', function () {
    $hits = ControllerAbortScanner::inlineForbiddenAborts(app_path('Http/Controllers'));

    expect($hits)->toBe([], PHP_EOL.PHP_EOL
        .'Found inline 403 abort(s) in controllers:'.PHP_EOL
        .implode(PHP_EOL, array_map(fn ($h) => "  {$h}", $hits)).PHP_EOL.PHP_EOL
        .'Use a Policy via authorizeForUser() for authorization, or abort(422, ...) '.PHP_EOL
        ."if it's really input validation.".PHP_EOL);
});

it('proves the guard can fail: every known-bad sample IS caught', function () {
    $hits = ControllerAbortScanner::inlineForbiddenAborts(
        base_path('tests/fixtures/guards/inline-403')
    );

    // Named individually, not counted: a count assertion passes if the
    // scanner catches the easy single-line case once and the wrapped cases
    // zero times — which is exactly the bug being fixed (COV-GUARD-2).
    $files = array_unique(array_map(fn ($h) => explode(':', $h)[0], $hits));

    expect($files)->toContain('single-line.stub')
        ->and($files)->toContain('wrapped-args.stub')
        ->and($files)->toContain('wrapped-abort-403.stub')
        ->and($files)->toContain('trailing-message-wrap.stub');
});

it('ignores abort(403)/abort_unless(..., 403, ...) mentions that appear only in a comment', function () {
    // Regression pin for the same false-positive mechanism GS-1's allowlist
    // suffered from (IndividualProfileController was allowlisted for a
    // docblock mention, never a real call).
    $hits = ControllerAbortScanner::inlineForbiddenAborts(
        base_path('tests/fixtures/guards/inline-403')
    );

    $files = array_unique(array_map(fn ($h) => explode(':', $h)[0], $hits));

    expect($files)->not->toContain('comment-only.stub');
});
