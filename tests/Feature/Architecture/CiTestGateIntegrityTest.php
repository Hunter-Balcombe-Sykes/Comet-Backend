<?php

/**
 * Pins the seam that let CI report red for a day without running a single test.
 *
 * `composer test` deliberately bundles the guards ahead of `artisan test` so a
 * local run fails fast. Composer aborts a script at the first non-zero step, so
 * pointing CI at that same script means ANY guard failure swallows the whole
 * suite — the `test` check goes red, looks like "tests failed", and is actually
 * "tests never started". That is exactly what happened on development from
 * 6a7613d81 (2026-08-19): an unsafe-migration guard failure hid 8,565 tests for
 * a day, and the required status check gated on nothing.
 *
 * CI therefore runs each guard as its OWN step (independently reported) and
 * runs the suite through a guard-free script. These assertions keep it that way.
 */

use Illuminate\Support\Str;

function ciWorkflow(): string
{
    $path = base_path('.github/workflows/ci.yml');
    expect(file_exists($path))->toBeTrue('.github/workflows/ci.yml is missing');

    return file_get_contents($path);
}

/** @return array<string, list<string>> */
function composerScripts(): array
{
    return json_decode(file_get_contents(base_path('composer.json')), true)['scripts'];
}

/** The `test` job's steps, from its declaration to the next top-level job. */
function ciTestJob(): string
{
    $yml = ciWorkflow();
    $start = strpos($yml, "\n  test:\n");
    expect($start)->not->toBeFalse('ci.yml has no `test:` job');

    $rest = substr($yml, $start + 1);
    $end = preg_match('/\n  [a-z][a-z0-9-]*:\n/', $rest, $m, PREG_OFFSET_CAPTURE, 10)
        ? $m[0][1]
        : strlen($rest);

    return substr($rest, 0, $end);
}

it('runs the CI suite through a script that cannot be aborted by a guard', function () {
    $scripts = composerScripts();

    // toHaveKey/toContain are variadic — a second argument is another needle,
    // NOT a failure message. Diagnostics go through toBe(), which does take one.
    expect(array_key_exists('test:ci', $scripts))
        ->toBe(true, 'composer.json has no `test:ci` script for CI to call');

    $steps = implode(' ', (array) $scripts['test:ci']);
    expect(str_contains($steps, 'guard:'))
        ->toBe(false, 'test:ci must not run guards — a failing guard would abort composer before artisan test');
    expect($steps)->toContain('artisan test');
});

it('points the CI test step at the guard-free script, not the bundled one', function () {
    $job = ciTestJob();

    expect($job)->toContain('composer test:ci');
    // The bundled script must not be what runs the suite in CI. Matching on the
    // line end is deliberate: `composer test:ci` also starts with `composer test`.
    expect(preg_match('/run:\s*composer test\s*$/m', $job))->toBe(0,
        'the CI test step runs bare `composer test` — a guard failure will swallow the suite again');
});

it('reports every guard bundled in composer test as its own CI step', function () {
    $bundled = array_values(array_filter(
        array_map(
            fn (string $step) => Str::after($step, '@composer '),
            array_filter((array) composerScripts()['test'], fn ($s) => is_string($s) && str_contains($s, '@composer guard:'))
        )
    ));

    expect($bundled)->not->toBeEmpty('composer test bundles no guards — this pin has drifted');

    $job = ciTestJob();
    foreach ($bundled as $guard) {
        expect(str_contains($job, "composer {$guard}"))->toBe(true,
            "guard `{$guard}` runs in composer test but has no independent CI step — moving the suite off `composer test` would silently drop it");
    }
});
