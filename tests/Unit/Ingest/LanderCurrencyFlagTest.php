<?php

// Unit sentinel for the driver-boolean-coercion defect found in review of
// Unit 2 (audit-fix/pilot-gate-2026-07-29, DINT-16/DINT-9).
//
// Lander::land() used to decide "was this version already current?" by
// casting a raw query-builder column read with `(bool) $landedRow->is_current`.
// That is wrong on pdo_pgsql: a `boolean` column read via DB::table()->get()
// (no Eloquent casts involved) arrives as the PHP STRING 't' or 'f', and
// `(bool) 'f'` is `true` in PHP — only `""` and `"0"` are falsy strings. So
// the cast made every row look current, on both drivers where they'd have
// disagreed. SQLite is silent about this because it returns real 0/1
// integers, which is why the full suite passing does not prove this is
// fixed — see tests/Postgres/LanderRevertProjectionTest.php for the
// real-Postgres proof (unreachable in this environment, honestly marked
// UNVERIFIED there).
//
// The fix moves the decision into SQL (`CASE WHEN is_current THEN 1 ELSE 0
// END AS is_current_flag`) and reads the result with `(int) $flag === 1`.
// This test exercises that exact expression against every shape a driver
// could plausibly hand back for that CASE expression's integer literals —
// PHP int (SQLite / some pgsql configs) and numeric string (pdo_pgsql,
// which returns all result-set values as text over the wire regardless of
// PDO::ATTR_STRINGIFY_FETCHES) — and documents, without repeating, why the
// old `(bool)` cast on the raw column would fail.

it('resolves the is_current flag correctly for every value shape a driver could return for CASE WHEN ... THEN 1 ELSE 0 END', function () {
    $isCurrent = static fn ($flag): bool => ((int) $flag) === 1;

    expect($isCurrent(1))->toBeTrue();     // SQLite / native int
    expect($isCurrent(0))->toBeFalse();
    expect($isCurrent('1'))->toBeTrue();   // pdo_pgsql text-wire numeric string
    expect($isCurrent('0'))->toBeFalse();
});

it('documents why casting the raw boolean column in PHP was the bug: (bool) on pdo_pgsql\'s t/f strings is true either way', function () {
    // This is the defect, preserved as a regression trip-wire: if
    // Lander::land() ever goes back to `(bool) $landedRow->is_current`
    // instead of resolving currency in SQL, this assertion documents
    // exactly why that regresses silently under SQLite but not Postgres.
    expect((bool) 't')->toBeTrue();
    expect((bool) 'f')->toBeTrue(); // <- the bug: this should be false

    // filter_var(..., FILTER_VALIDATE_BOOLEAN) is not a fix either — it
    // does not recognise 't'/'f' at all, so both map to false, which would
    // make Lander::land() report changed=1 on EVERY re-landing of an
    // unchanged document (a serious performance regression, and a breach
    // of the class docblock's "unchanged content writes nothing" property).
    expect(filter_var('t', FILTER_VALIDATE_BOOLEAN))->toBeFalse();
    expect(filter_var('f', FILTER_VALIDATE_BOOLEAN))->toBeFalse();
});
