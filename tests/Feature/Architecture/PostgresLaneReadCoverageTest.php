<?php

use Tests\Support\Architecture\AppColumnReadScanner;
use Tests\Support\Architecture\PostgresLaneDdlScanner;

/**
 * G — a tests/Postgres/ stand-in must declare every column the app code that
 * file drives actually reads from it.
 *
 * THE COVERAGE HOLE THIS FILLS. PostgresLaneDdlDriftTest checks stand-in ⊆
 * real schema: it catches a column the lane INVENTED. Its own docblock states
 * it cannot catch the other direction, and the other direction is what has
 * taken this lane red twice — slice 5a (7 tests), and da958493e, which added
 * rs.last_seen_run to ProjectionWriter's select and left 55 test classes
 * failing for ~15 consecutive runs while postgres-tests, a REQUIRED check,
 * gave no signal at all. In both cases the schema was right and the code was
 * right; only the hand-written stand-in was behind, and a green SQLite run
 * said nothing about it.
 *
 * SCOPE, and why it is drawn here. Only tables a lane file ALREADY PROVISIONS
 * are checked. This guard never demands a new table, because minimal
 * stand-ins are deliberate — a bare `core.users` stand-in holding only
 * `id uuid PRIMARY KEY` exists purely as an FK target, and forcing full-fat
 * tables everywhere would slow the lane and hide bugs. Eloquent access emits
 * no literal column strings, so a model-driven read never demands a column
 * either. The remaining failure mode — a writer touching a table this lane
 * never provisions at all — stays uncatchable by any static scan, exactly as
 * PostgresLaneDdlDriftTest says.
 *
 * ATTRIBUTION, and its limits (do not over-trust a clean run). A lane file's app-code
 * surface is discovered exactly two ways: every file-level `use App\...;` import, and every
 * `Artisan::call()` / `$this->artisan()` call whose command name resolves to exactly one
 * class under app/Console/Commands/ (an ambiguous or unresolved name is skipped, never
 * guessed — see pgReadCoverageArtisanImports). A resolved command also contributes its OWN
 * file-level App imports, one level deep, so a thin command that delegates its real work to
 * an injected collaborator (IngestProjectCommand -> ProjectionWriter) still surfaces that
 * collaborator's reads. A lane file that reaches app code by any OTHER route — an inline
 * fully-qualified call with no `use` statement, a job dispatched by class-string, a facade
 * macro, a call through a variable — is invisible here even though the table is provisioned
 * and the column is genuinely read. Attribution is also per CLASS, not per METHOD:
 * $appRefs[$fqcn] is the union of every method that class has, so a stand-in built
 * faithfully for the one method a test actually calls can read as "missing" a column a
 * different, uncalled method on the same class writes — see $knownLimitation below for a
 * live instance. A green run of this guard is evidence for "no drift found on these
 * attribution paths", not a general proof of stand-in completeness.
 *
 * THE FIX FOR A FINDING IS ALWAYS ADDITIVE: add the column to the stand-in,
 * preferably via ALTER TABLE … ADD COLUMN IF NOT EXISTS so it heals whichever
 * file loses the first-creator-wins race. Never delete an assertion, and never
 * thin a table to silence this. If a test only passed because a column was
 * missing, that is a finding to report, not to paper over.
 */

/**
 * "file — table.column, read by Class" -> "file|table.column", to match a $findings entry
 * against an $exempt / $knownLimitation key.
 */
function pgReadCoverageFindingKey(string $finding): string
{
    [$file, $rest] = explode(' — ', $finding, 2);
    [$tableColumn] = explode(',', $rest, 2);

    return $file.'|'.$tableColumn;
}

/**
 * command-name (the leading token of $signature, before the first brace or whitespace) =>
 * list of FQCNs that declare it. Built from every *.php directly under
 * app/Console/Commands/ — deliberately shallow (no subdirectories), which is where every
 * command in this codebase lives today. A name mapping to more than one class is left
 * ambiguous on purpose; see pgReadCoverageArtisanImports.
 */
function pgReadCoverageCommandSignatureIndex(string $commandsDir): array
{
    $index = [];
    foreach (glob($commandsDir.'/*.php') ?: [] as $path) {
        $source = (string) file_get_contents($path);

        if (! preg_match('/namespace\s+([\w\\\\]+);/', $source, $ns)
            || ! preg_match('/\bclass\s+(\w+)/', $source, $cls)
            || ! preg_match('/\$signature\s*=\s*\'([^\'{\s]+)/', $source, $sig)) {
            continue;
        }

        $index[$sig[1]][] = $ns[1].'\\'.$cls[1];
    }

    return $index;
}

/**
 * A lane file that drives app code purely through Artisan::call()/$this->artisan() carries
 * no `use App\...;` import at all (see the ATTRIBUTION paragraph above), so the main loop's
 * import regex is blind to it. Resolve each invoked command name against $signatureIndex; an
 * unresolved or ambiguous name is skipped rather than guessed, matching the conservatism of
 * everything else in this guard. For each resolved command, return the command class itself
 * PLUS its own file-level `App\` imports (one level, not recursive) — a command's handle()
 * often reads a table directly (IngestProjectCommand's --dry-run branch does) but delegates
 * the bulk of its work to an injected collaborator, and that collaborator's reads are exactly
 * what this guard exists to catch.
 */
function pgReadCoverageArtisanImports(string $source, array $signatureIndex): array
{
    preg_match_all('/(?:Artisan::call|\$this->artisan)\(\s*[\'"]([a-zA-Z0-9:_-]+)[\'"]/', $source, $calls);

    $imports = [];
    foreach (array_unique($calls[1]) as $name) {
        $candidates = $signatureIndex[$name] ?? [];
        if (count($candidates) !== 1) {
            continue; // unresolved or ambiguous — skip rather than guess
        }

        $commandFqcn = $candidates[0];
        $imports[] = $commandFqcn;

        $commandPath = base_path(str_replace('\\', '/', preg_replace('/^App\\\\/', 'app/', $commandFqcn)).'.php');
        if (is_file($commandPath)) {
            preg_match_all('/^use (App\\\\[\w\\\\]+);/m', (string) file_get_contents($commandPath), $commandImports);
            array_push($imports, ...$commandImports[1]);
        }
    }

    return array_values(array_unique($imports));
}

it('declares every column the app code a PG-lane file drives reads from a table it provisions', function () {
    $appRefs = AppColumnReadScanner::scanTree(base_path('app'));
    $byFile = PostgresLaneDdlScanner::laneDdlByFile(base_path('tests/Postgres'));
    $signatureIndex = pgReadCoverageCommandSignatureIndex(base_path('app/Console/Commands'));

    // (file, schema.table) pairs whose stand-in is deliberately not faithful.
    $exempt = [
        // DELIBERATELY POISONED: a CHECK that lets every SELECT succeed and
        // rejects every INSERT, so applyIntent() fails mid-transaction and the
        // rollback can be observed. Completing this table defeats the test.
        // NOTE: that file's own comment claims it is "ALLOWLISTED in
        // no-local-canonical-ddl-baseline.json" — it is not, and never needed
        // to be. That baseline holds no tests/Postgres/ entries at all;
        // NoLocalCanonicalTableDdlTest excludes the lane BY PATH. The comment
        // is stale, verified 2026-09-01. This guard has no path exclusion, so
        // the exemption has to be explicit here.
        'SourceReconcilerAtomicityTest.php|site.platform_connections',
        // SourceReconcilerConnectionRacePgTest.php is deliberately NOT exempt,
        // even though it shares the same table with its poisoned sibling
        // above. Its own comment at line 108 reads: "Faithful (NOT poisoned —
        // contrast SourceReconcilerAtomicityTest): every column
        // site.platform_connections really has, so the next column the
        // writer picks up does not cost another red CI cycle." Exempting it
        // would blind this guard on the one file its author built faithful
        // for exactly this guard's purpose — verified 2026-09-01.
    ];

    // Known limitation, not drift: attribution is per CLASS, not per METHOD (see ATTRIBUTION
    // above). ShopContentWriter's cataloguesFor() is what ShopCatalogueCreatedAtTimezoneTest's
    // stand-in is deliberately minimal for; removed_at and updated_at are written by a
    // different method on the same class, retireStaleItems(), which this test never calls.
    // Thinning the app-side scanner to method granularity is out of scope for this guard (see
    // AppColumnReadScanner) — recorded here rather than sent back to Task 1, and NOT fixed by
    // thinning the table. This is not a work queue — it is a permanent false-positive class of
    // the current attribution granularity, and stays subject to the same staleness check below
    // (if cataloguesFor() ever starts reading either column, that entry stops masking a real
    // finding and must come out).
    $knownLimitation = [
        'ShopCatalogueCreatedAtTimezoneTest.php|content.items.removed_at',
        'ShopCatalogueCreatedAtTimezoneTest.php|content.items.updated_at',
    ];

    $findings = [];
    $triplesEvaluated = 0;
    $pairsEvaluated = [];
    foreach (glob(base_path('tests/Postgres').'/*.php') ?: [] as $path) {
        $file = basename($path);
        $standIns = $byFile[$file] ?? [];
        if ($standIns === []) {
            continue;
        }

        $source = (string) file_get_contents($path);
        preg_match_all('/^use (App\\\\[\w\\\\]+);/m', $source, $imports);
        $fqcns = array_unique(array_merge($imports[1], pgReadCoverageArtisanImports($source, $signatureIndex)));

        foreach ($fqcns as $fqcn) {
            foreach ($appRefs[$fqcn] ?? [] as $table => $columns) {
                if (! isset($standIns[$table]) || PostgresLaneDdlScanner::isScratch($table)) {
                    continue;
                }
                if (in_array($file.'|'.$table, $exempt, true)) {
                    continue;
                }

                $pairsEvaluated[$file.'|'.$table] = true;

                foreach ($columns as $column) {
                    $triplesEvaluated++;
                    if (! in_array($column, $standIns[$table], true)) {
                        $findings[] = sprintf(
                            '%s — %s.%s, read by %s',
                            $file, $table, $column, class_basename($fqcn)
                        );
                    }
                }
            }
        }
    }

    $findings = array_values(array_unique($findings));
    sort($findings);

    // F1 — the cross-reference stage itself must be pinned, not only its two inputs (see the
    // "cross-references a meaningful number" test below, which pins the scanners but not the
    // join between them). Neutering ONLY the import regex above (e.g. lane files moving to
    // grouped `use App\{A, B};` imports, or to inline FQCN calls) leaves both scanners intact
    // and that other test green while every triple this loop would have evaluated silently
    // drops toward zero. 1001 triples across 201 (file, table) pairs is today's real figure
    // (984/195 before Artisan-command resolution recovered IngestProjectChunkingTest.php —
    // see ATTRIBUTION above); >500 / >100 have headroom without being slack.
    expect($triplesEvaluated)->toBeGreaterThan(500, sprintf(
        "The cross-reference loop only evaluated %d (file, table, column) triples — expected\n".
        "more than 500. This usually means the `use App\\...;` import regex (or the\n".
        'Artisan-command resolution feeding it) stopped matching, silently making this guard vacuous.',
        $triplesEvaluated
    ))->and(count($pairsEvaluated))->toBeGreaterThan(100, sprintf(
        'The cross-reference loop only evaluated %d (file, table) pairs — expected more than 100.',
        count($pairsEvaluated)
    ));

    $queueKeys = $knownLimitation;
    $rawKeys = array_values(array_unique(array_map('pgReadCoverageFindingKey', $findings)));

    // F2 — a queue entry that stops reproducing (the drift it named got fixed, or a stand-in
    // changed shape under it) must fail the build, not sit there as a permanent silent mask.
    // Without this: fix the drift, leave the entry — $findings for that key goes empty,
    // $remaining below stays [] either way, the suite stays green, and NOTHING says the entry
    // is now stale. Reintroduce that exact drift later (a revert, a copy-paste stand-in) and
    // this guard reports nothing, because the stale entry is still excluding that key.
    $stale = array_values(array_diff($queueKeys, $rawKeys));
    sort($stale);
    expect($stale)->toBe([], "These \$knownLimitation entries no longer reproduce as findings —\n".
        "whatever they excused (real drift or a per-class attribution false-positive) is gone,\n".
        "the code changed shape under them, or they were mistyped:\n  ".
        implode("\n  ", $stale).
        "\n\nDelete them from the array. A stale entry is a silent mask: if the exact same drift\n".
        'is ever reintroduced, this guard will report nothing.');

    $remaining = array_values(array_filter($findings, fn (string $finding) => ! in_array(
        pgReadCoverageFindingKey($finding), $queueKeys, true
    )));

    expect($remaining)->toBe([], "PG-lane stand-ins are missing columns the code under test reads.\n".
        "Each one is an SQLSTATE 42703 that fires BEFORE the assertion it is hiding, and because\n".
        "this lane shares tables, one missing column cascades into dozens of unrelated failures:\n  ".
        implode("\n  ", $remaining).
        "\n\nFix by ADDING the column to the stand-in — ideally\n".
        "  ALTER TABLE <table> ADD COLUMN IF NOT EXISTS <column> <type>\n".
        'so it heals whichever file loses the first-creator-wins race. Never thin a table to silence this.');

    fwrite(STDERR, "\n[pg-read-coverage] ".count($knownLimitation)." known-limitation finding(s) (per-class, not per-method, attribution —\n".
        "not drift) recorded and excluded — see the docblock above for the list.\n");
});

// A scanner that silently matched nothing would make the gate above vacuous and
// permanently green. These pin that both sides actually parsed, and that the
// cross-reference actually evaluated something.
it('cross-references a meaningful number of columns rather than passing on an empty scan', function () {
    $appRefs = AppColumnReadScanner::scanTree(base_path('app'));
    $byFile = PostgresLaneDdlScanner::laneDdlByFile(base_path('tests/Postgres'));

    $appTables = [];
    foreach ($appRefs as $tables) {
        foreach ($tables as $table => $columns) {
            $appTables[$table] = true;
        }
    }

    expect(count($byFile))->toBeGreaterThanOrEqual(50)
        ->and(count($appTables))->toBeGreaterThanOrEqual(40)
        ->and($appRefs['App\Ingest\Projection\ProjectionWriter']['ingest.record_state'] ?? [])
        ->toContain('last_seen_run', 'key', 'stream_id');
});

it('does not mistake a dotted literal for a column reference', function () {
    $refs = AppColumnReadScanner::scanSource("<?php Log::info('analytics.ingest.dropped'); \$h = 'api.deezer.com';");

    expect($refs)->toBe([]);
});
