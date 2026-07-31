<?php

// Architecture test — every hash in config/checkpoint.php's `suppressed` array must
// still match a live Checkpoint finding.
//
// WHY: Checkpoint keys a finding by its matched source TEXT, not its location —
// ScanCommand::hashFinding() is sha1("{$checkName}|{$detail}") with line numbers
// stripped, truncated to 12 chars. The config's own note calls the hash
// "content-stable", which is true and is precisely the trap: a refactor that only
// shifts LINES keeps the hash, but editing the matched STATEMENT mints a new one.
// The old entry then silences nothing and the finding silently reopens — or, if the
// finding is re-vetted under its new hash without removing the old entry, the list
// quietly accretes corpses.
//
// Both halves had already happened by the time this guard was written (2026-07-31,
// measured at 20601c60 — 58 entries, 3 of them dead):
//   5dd3ec775690  ScanWebsiteCommand:31   — file deleted in e66bb911; config/checkpoint.php
//                                           was its last reference anywhere in the repo
//   7b0f383edf44  ProjectionWriter:675    — superseded by 657930f2f7f9 in the 2026-07-30
//   a15fee82d15b  ProjectionWriter:680    — and 3b8365f2b9b7 re-vet; never removed
//
// The 07-30 re-vet's own comment describes this exact failure mode and still left the
// superseded pair behind. A comment is not a guard.
//
// SCOPE: staleness only — "does every suppression still correspond to something real".
// It deliberately does NOT assert the reverse (that nothing is unsuppressed); that is
// `checkpoint:scan`'s own exit code, and asserting it here would both duplicate CI and
// fail permanently on the six WARN checks that are accepted noise.

use Checkpoint\Checks\ComposerAuditCheck;
use Checkpoint\Checks\NpmAuditCheck;
use Illuminate\Support\Facades\Artisan;

/**
 * Checks disabled for this test because they shell out to the network
 * (`composer audit` → Packagist, `npm audit` → the npm registry). A CVE advisory
 * appearing or disappearing upstream would otherwise flip this test red on a commit
 * that changed nothing.
 *
 * Consequence to keep in mind: a suppression for a finding produced by one of these
 * checks would read as dead here. None exists today — every entry in the array is a
 * SQL-injection, hardcoded-secret, command-injection or debug-function finding — and
 * the failure message below says so, so a future CVE suppression surfaces as a
 * legible message rather than a mystery.
 */
const CHECKPOINT_STALENESS_SKIPPED_CHECKS = [
    ComposerAuditCheck::class,
    NpmAuditCheck::class,
];

/**
 * Every finding hash Checkpoint currently produces, computed by the vendor's own
 * hashFinding() rather than reimplemented here — reimplementing it would silently
 * diverge the day the package changes its algorithm.
 *
 * Blanking `checkpoint.suppressed` first is what makes this the COMPLETE set:
 * applySuppressions() runs before the JSON is emitted, so with the real config in
 * place the suppressed findings would be exactly the ones missing.
 *
 * @return list<string>
 */
function checkpointLiveFindingHashes(): array
{
    $checks = (array) config('checkpoint.checks');

    foreach (CHECKPOINT_STALENESS_SKIPPED_CHECKS as $class) {
        $checks[$class] = false;
    }

    config(['checkpoint.checks' => $checks, 'checkpoint.suppressed' => []]);

    Artisan::call('checkpoint:scan', ['--json' => true]);

    $payload = json_decode(Artisan::output(), true);

    expect($payload)->toBeArray()
        ->and($payload)->not->toBeEmpty('checkpoint:scan --json produced no parseable output');

    return array_values(array_unique(array_merge(
        ...array_map(fn (array $check) => $check['hashes'] ?? [], $payload)
    )));
}

/**
 * The `'hash', // comment` pairs as they are written in the config file. Read from
 * source because the comment is the only record of WHY a finding was accepted, and a
 * dead-hash failure is unactionable without it.
 *
 * @return array<string, string>
 */
function checkpointSuppressionComments(): array
{
    $source = (string) file_get_contents(config_path('checkpoint.php'));

    preg_match_all("/'([0-9a-f]{12})',\s*(?:\/\/\s*(.*))?/", $source, $matches, PREG_SET_ORDER);

    return array_reduce(
        $matches,
        fn (array $carry, array $m) => $carry + [$m[1] => trim($m[2] ?? '') ?: '(no comment)'],
        []
    );
}

it('has no dead entries in checkpoint.suppressed', function () {
    $suppressed = (array) config('checkpoint.suppressed');

    expect($suppressed)->not->toBeEmpty(
        'checkpoint.suppressed is empty — either the config moved or this guard is pointed at the wrong key.'
    );

    $comments = checkpointSuppressionComments();
    $live = checkpointLiveFindingHashes();

    $dead = array_values(array_diff($suppressed, $live));

    $detail = implode(PHP_EOL, array_map(
        fn (string $hash) => "  {$hash}  // {$comments[$hash]}",
        $dead
    ));

    expect($dead)->toBeEmpty(
        count($dead).' suppression(s) in config/checkpoint.php match no live Checkpoint finding:'.PHP_EOL
        .$detail.PHP_EOL.PHP_EOL
        .'Each one is either (a) a finding whose source text was edited, so the entry went dead and the'.PHP_EOL
        .'finding has silently REOPENED under a new hash — re-vet it and replace the entry, or (b) code'.PHP_EOL
        .'that no longer exists, in which case delete the entry.'.PHP_EOL
        .'Do not just delete a dead entry to get green: confirm which case it is first.'.PHP_EOL.PHP_EOL
        .'Census the current state with:'.PHP_EOL
        .'  php artisan tinker --execute="config([\'checkpoint.suppressed\' => []]); \\Artisan::call(\'checkpoint:scan\'); echo \\Artisan::output();"'.PHP_EOL.PHP_EOL
        .'(If the entry suppresses a CVE finding, note that this guard disables '
        .implode(' and ', array_map(fn ($c) => class_basename($c), CHECKPOINT_STALENESS_SKIPPED_CHECKS))
        .' to stay offline, so such an entry reads as dead here. Move it out of scope explicitly rather than deleting it.)'
    );
});
