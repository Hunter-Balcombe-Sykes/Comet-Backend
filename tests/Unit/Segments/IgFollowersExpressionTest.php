<?php

/**
 * Pins the Postgres shape of the follower-count expression. Tests run on
 * SQLite, so nothing else in the suite would catch a regression here until it
 * 500s on real Postgres.
 */

use App\Services\Segments\Criteria\IgFollowersCriterion;

it('guards the numeric cast with a length-bounded digit regex on postgres', function () {
    $sql = IgFollowersCriterion::followersExpression('pgsql');

    // Bounded to 15 digits, not bare \d+: bigint's ceiling is 19 digits, so
    // an unbounded all-digit match (e.g. a corrupted scrape) would itself
    // overflow ::bigint and throw — 15 digits is comfortably above any real
    // follower count and safely below the ceiling.
    expect($sql)->toContain("~ '^\\d{1,15}$'")
        ->and($sql)->not->toContain('^\\d+$')
        ->and($sql)->toContain('::bigint')
        ->and($sql)->not->toContain('json_extract');

    // The guard MUST precede the cast — Postgres throws on ::bigint over
    // non-numeric text, and CASE is what makes the short-circuit guaranteed.
    expect(strpos($sql, "~ '^\\d{1,15}$'"))->toBeLessThan(strpos($sql, '::bigint'))
        ->and($sql)->toStartWith('CASE WHEN ');
});

it('uses json_extract with a length-bounded digit GLOB guard on sqlite', function () {
    $sql = IgFollowersCriterion::followersExpression('sqlite');

    expect($sql)->toContain('json_extract')
        ->and($sql)->toContain("GLOB '[0-9]*'")
        ->and($sql)->toContain("NOT GLOB '*[^0-9]*'")
        ->and($sql)->not->toContain('::bigint');

    // Bounded to the same 15 digits as the pgsql branch, so the two drivers
    // agree on which values resolve to NULL even though SQLite's CAST
    // itself would silently clamp rather than throw on overflow.
    expect($sql)->toContain('LENGTH(json_extract')
        ->and($sql)->toContain('<= 15');
});
