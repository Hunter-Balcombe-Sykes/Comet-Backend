<?php

/**
 * Pins the Postgres shape of the follower-count expression. Tests run on
 * SQLite, so nothing else in the suite would catch a regression here until it
 * 500s on real Postgres.
 */

use App\Services\Segments\Criteria\IgFollowersCriterion;

it('guards the numeric cast with a digit regex on postgres', function () {
    $sql = IgFollowersCriterion::followersExpression('pgsql');

    expect($sql)->toContain("~ '^\\d+$'")
        ->and($sql)->toContain('::bigint')
        ->and($sql)->not->toContain('json_extract');

    // The guard MUST precede the cast — Postgres throws on ::bigint over
    // non-numeric text, and CASE is what makes the short-circuit guaranteed.
    expect(strpos($sql, "~ '^\\d+$'"))->toBeLessThan(strpos($sql, '::bigint'))
        ->and($sql)->toStartWith('CASE WHEN ');
});

it('uses json_extract with a digit GLOB guard on sqlite', function () {
    $sql = IgFollowersCriterion::followersExpression('sqlite');

    expect($sql)->toContain('json_extract')
        ->and($sql)->toContain("GLOB '[0-9]*'")
        ->and($sql)->toContain("NOT GLOB '*[^0-9]*'")
        ->and($sql)->not->toContain('::bigint');
});
