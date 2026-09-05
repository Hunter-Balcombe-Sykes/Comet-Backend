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

/**
 * The prose-only gate (2026-09-05). Eight required checks now skip their
 * expensive steps when the diff touches only docs/ or a root-level *.md, so a
 * CLAUDE.md typo costs seconds instead of ~20 minutes. That is a saving with a
 * sharp edge: gate it wrong and a required check reports green having done
 * nothing. These assertions pin the three ways it could go wrong quietly.
 */
function ciWorkflowJob(string $name): string
{
    $yml = ciWorkflow();
    $start = strpos($yml, "\n  {$name}:\n");
    expect($start)->not->toBeFalse("ci.yml has no `{$name}:` job");

    $rest = substr($yml, $start + 1);
    $end = preg_match('/\n  [a-z][a-z0-9-]*:\n/', $rest, $m, PREG_OFFSET_CAPTURE, 10)
        ? $m[0][1]
        : strlen($rest);

    return substr($rest, 0, $end);
}

/** The required checks, minus supply-chain, which is exempt by design. */
const PROSE_GATED_JOBS = [
    'test', 'worker-tests', 'worker-static', 'postgres-tests',
    'schema-tests', 'schema-drift', 'outbound-http-guard', 'checkpoint-suppressions',
];

it('never lets the secret scanner stand down on a prose-only change', function () {
    // gitleaks reads full history including docs/ and *.md. A credential pasted
    // into a runbook is the exact case this catches, so supply-chain is the one
    // required check with no gate — and must stay that way.
    expect(str_contains(ciWorkflowJob('supply-chain'), 'prose_only'))->toBe(false,
        'supply-chain is gated on prose_only — a key committed in a markdown file would now merge unscanned');
});

it('keeps every prose-gated job runnable when the changes job fails', function () {
    // `needs: changes` without a status function means the implicit success()
    // applies: a red `changes` job SKIPS the dependent, and a required check that
    // never reports either blocks the merge or renders green while guarding
    // nothing — the failure mode the `No paths: filter` notes exist to avoid.
    foreach (PROSE_GATED_JOBS as $name) {
        $job = ciWorkflowJob($name);

        // Anchored at FOUR spaces — job level. `\s*` would also match the
        // step-level `!cancelled() && steps.copy_env...` conditions inside `test`,
        // which pass while the job-level guard is absent. (Caught by mutation,
        // 2026-09-05: dropping every job-level !cancelled() left this test green.)
        expect(preg_match('/^    needs: changes$/m', $job))->toBe(1,
            "job `{$name}` does not declare `needs: changes`");
        expect(preg_match('/^    if:.*!cancelled\(\)/m', $job))->toBe(1,
            "job `{$name}` needs `changes` but its JOB-level `if:` has no status function — a failed changes job would skip this required check");
        expect(str_contains($job, 'prose_only'))->toBe(true,
            "job `{$name}` is wired to `changes` but gates nothing on its output");
    }
});

it('treats only docs/ and root-level markdown as prose', function () {
    $path = base_path('.github/scripts/prose-only-diff.sh');
    expect(file_exists($path))->toBeTrue('.github/scripts/prose-only-diff.sh is missing');

    $src = (string) file_get_contents($path);

    // The allowlist is the whole safety argument. audits/ is read by
    // AuditPipelineIntegrityTest and scripts/audit/lenses/*.md by the same file,
    // so neither is inert however much it looks like documentation.
    preg_match('/case "\$\{file\}" in(.*?)esac/s', $src, $m);
    expect($m)->not->toBeEmpty('the prose allowlist case block has moved — re-pin this test');

    foreach (['audits/', '.github/', 'scripts/', 'tests/'] as $notProse) {
        expect(str_contains($m[1], $notProse))->toBe(false,
            "`{$notProse}` appears in the prose allowlist — a change there can turn the suite red, so it is code");
    }

    // Fail-closed is load-bearing: an unreadable diff must mean "run everything".
    expect($src)->toContain('empty file list — cannot prove the change is prose');
    expect(str_contains($src, 'set -e') && ! str_contains($src, 'set -uo pipefail'))->toBe(false,
        'the script must not abort before writing a verdict — a missing output reads as an empty string downstream');
});
