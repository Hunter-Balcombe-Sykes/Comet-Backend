<?php

/**
 * Coverage-hole guard: no SQLite-lane test file may build its OWN table-creation DDL for a
 * canonical tenant/content table. Such local DDL is invisible to SchemaDriftGuardTest — that
 * gate only introspects the SQLite stand-in (SqliteIntrospector over the 'pgsql' alias that
 * tests/TestCase.php:21-33 repoints at in-memory SQLite) via the shared setup*Table() helpers
 * in tests/Pest.php — so a local, permissive copy can seed a prod-violating row and still pass
 * CI green (this is how a null resource_id slipped past the T3 drift tightening). The shared
 * helpers are the single source of truth for these tables' schema.
 *
 * SCOPE: tests/Postgres/ is deliberately excluded from this scan, by path, not just by luck.
 * That lane (phpunit.pg.xml / `composer test:pg`, its own CI job — .github/workflows/ci.yml)
 * runs its DDL against a REAL disposable Postgres database (see PostgresTestCase), never
 * against the SQLite stand-in this guard exists to protect — SchemaDriftGuardTest has no
 * jurisdiction there. Widening the table list once (to cover new content-platform tables)
 * measured that EVERY new offender introduced by the wider list lived under tests/Postgres/,
 * and at least one is a pure false positive with no textual fix: SourceIntentDomainTest.php
 * never creates routing.source_intents (its only CREATE TABLE is
 * routing.source_intent_scratch) — it merely matches a docblock comment and a
 * RuntimeException message that mention the real table name. The prescribed remedy ("seed via
 * the shared setup*Table() helpers") is also inapplicable there: those helpers emit
 * SQLite-flavoured DDL (`id TEXT PRIMARY KEY NOT NULL`), and telling a Postgres-lane author to
 * call them is telling them to break their test. If a tests/Postgres file creating a
 * prod-named table without referencing supabase/migrations becomes a real problem, the answer
 * is a NEW, lane-appropriate guard with its own baseline and remedy text — NOT widening this
 * one back out.
 *
 * The path exclusion is held honest by a companion assertion below: every file under
 * tests/Postgres/ must opt into that lane via `uses(PostgresTestCase::class)`. That closes the
 * obvious dodge ("move the local DDL into tests/Postgres/ to escape the scan") — the only way
 * into the excluded directory is opting into a lane `composer test` never runs.
 *
 * Pre-existing offenders (within scope) are grandfathered in the allowlist JSON below; this
 * guard only fails on a NEW file that introduces local canonical DDL. To (re)generate the
 * allowlist:
 *   NO_LOCAL_DDL_BASELINE=1 php artisan test --filter=NoLocalCanonicalTableDdlTest
 * Preferred fix for a flagged file: seed via the shared setup*Table() helpers instead.
 */
const NO_LOCAL_DDL_BASELINE_PATH = __DIR__.'/../../../scripts/launch-check/no-local-canonical-ddl-baseline.json';

/**
 * Token-based (not substring) check for a REAL `uses(PostgresTestCase::class)` call. A bare
 * str_contains($contents, 'PostgresTestCase::class') is defeated by a decoy comment or a string
 * literal that merely mentions the text — token_get_all() is immune to both by construction:
 * PHP's tokenizer emits comments as a single T_COMMENT/T_DOC_COMMENT token and string literals
 * as a single T_CONSTANT_ENCAPSED_STRING (or T_STRING-interpolated) token, neither of which ever
 * decomposes into the T_STRING/T_DOUBLE_COLON/T_CLASS sequence a real `Foo::class` reference
 * produces. This walks every `uses` T_STRING token, and inside that call's argument list looks
 * for a `PostgresTestCase` T_STRING immediately followed (modulo whitespace/comments) by `::`
 * then `class`.
 */
function fileHasRealUsesPostgresTestCaseCall(string $contents): bool
{
    $tokens = token_get_all($contents);
    $count = count($tokens);
    $skippable = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'uses') {
            continue;
        }

        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], $skippable, true)) {
            $j++;
        }
        if (! isset($tokens[$j]) || $tokens[$j] !== '(') {
            continue; // not a call — e.g. a property/method named "uses"
        }

        // Collect this call's argument-list tokens up to the matching close paren.
        $depth = 1;
        $k = $j + 1;
        for (; $k < $count && $depth > 0; $k++) {
            if ($tokens[$k] === '(') {
                $depth++;
            } elseif ($tokens[$k] === ')') {
                $depth--;
            }
        }
        $callTokens = array_slice($tokens, $j + 1, max(0, $k - $j - 2));

        $callCount = count($callTokens);
        for ($m = 0; $m < $callCount; $m++) {
            $t = $callTokens[$m];
            if (! is_array($t) || $t[0] !== T_STRING || $t[1] !== 'PostgresTestCase') {
                continue;
            }
            $n = $m + 1;
            while ($n < $callCount && is_array($callTokens[$n]) && in_array($callTokens[$n][0], $skippable, true)) {
                $n++;
            }
            if (! isset($callTokens[$n]) || ! is_array($callTokens[$n]) || $callTokens[$n][0] !== T_DOUBLE_COLON) {
                continue;
            }
            $n++;
            while ($n < $callCount && is_array($callTokens[$n]) && in_array($callTokens[$n][0], $skippable, true)) {
                $n++;
            }
            if (isset($callTokens[$n]) && is_array($callTokens[$n]) && $callTokens[$n][0] === T_CLASS) {
                return true;
            }
        }
    }

    return false;
}

it('no test file builds local DDL for a canonical tenant table (or is baselined)', function () {
    $testsDir = dirname(__DIR__, 2); // repo/tests, from tests/Feature/Architecture

    // Canonical tenant/content tables. Their bare names are unique in this schema, so the
    // guard matches with or without a schema prefix. The DDL keywords are assembled from parts
    // so this guard's own source can never satisfy the pattern (it holds itself to the rule).
    $tables = [
        'users', 'sites', 'platform_connections', 'menus', 'design_kits',
        'items', 'sections', 'effects', 'anomalies', 'source_intents',
        'source_items', 'item_merges', 'section_items',
    ];
    $pattern = '/'.'CREATE'.'\s+'.'TABLE'.'\s+(?:IF\s+NOT\s+EXISTS\s+)?'
        .'(?:"?[a-z_]+"?\.)?"?('.implode('|', $tables).')"?\b/i';

    // Exempt from the scan (repo-relative). tests/Pest.php owns the canonical shared schema;
    // the comparator test's DDL strings are in-memory fixtures fed to the comparator, not a
    // real schema the suite ever seeds against.
    $exempt = [
        'tests/Pest.php',
        'tests/Unit/SchemaDrift/DriftComparatorTest.php',
    ];

    $offenders = [];
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testsDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $rel = 'tests/'.str_replace('\\', '/', ltrim(substr($file->getPathname(), strlen($testsDir)), '/\\'));
        if (in_array($rel, $exempt, true)) {
            continue;
        }
        if (str_starts_with($rel, 'tests/Postgres/')) {
            continue;
        }
        if (preg_match($pattern, file_get_contents($file->getPathname()))) {
            $offenders[] = $rel;
        }
    }
    sort($offenders);

    if (getenv('NO_LOCAL_DDL_BASELINE') === '1') {
        file_put_contents(
            NO_LOCAL_DDL_BASELINE_PATH,
            json_encode($offenders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
        );
        expect(true)->toBeTrue(); // allowlist regenerated — always green

        return;
    }

    $baseline = is_file(NO_LOCAL_DDL_BASELINE_PATH)
        ? json_decode(file_get_contents(NO_LOCAL_DDL_BASELINE_PATH), true)
        : [];
    $new = array_values(array_diff($offenders, $baseline));
    $drained = array_values(array_diff($baseline, $offenders));

    expect($new)->toBe([], sprintf(
        "NEW local canonical-table DDL — these test files build their own table for a canonical\n".
        "tenant table, which SchemaDriftGuardTest cannot see (so a prod-violating seed can pass green):\n  %s\n".
        "Fix: seed through the shared setup*Table() helpers in tests/Pest.php instead of local DDL.\n".
        "If a file genuinely needs a bespoke table, regenerate the allowlist:\n".
        '  NO_LOCAL_DDL_BASELINE=1 php artisan test --filter=NoLocalCanonicalTableDdlTest',
        implode("\n  ", $new)
    ));

    if ($drained !== []) {
        fwrite(STDERR, "\n[no-local-ddl] ".count($drained).' allowlisted file(s) no longer build local canonical'.
            " DDL — regenerate the allowlist to lock them out.\n");
    }
});

it('every tests/Postgres file opts into the real-Postgres lane (anti-dodge for the path exclusion above)', function () {
    $testsDir = dirname(__DIR__, 2); // repo/tests, from tests/Feature/Architecture
    $postgresDir = $testsDir.'/Postgres';

    $offenders = [];
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($postgresDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        if (! fileHasRealUsesPostgresTestCaseCall($contents)) {
            $offenders[] = 'tests/Postgres/'.str_replace('\\', '/', ltrim(
                substr($file->getPathname(), strlen($postgresDir)), '/\\'
            ));
        }
    }
    sort($offenders);

    expect($offenders)->toBe([], sprintf(
        "File(s) under tests/Postgres/ do not opt into the real-Postgres lane via\n".
        "uses(PostgresTestCase::class) — the guard above excludes this directory BY PATH from\n".
        "the canonical-table scan, so a file that lands here without actually running under\n".
        "PostgresTestCase would dodge the scan while still executing on the SQLite stand-in:\n  %s",
        implode("\n  ", $offenders)
    ));
});
