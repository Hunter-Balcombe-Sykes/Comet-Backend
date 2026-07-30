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
 * SCOPE (jurisdiction, not a hand-picked list): "canonical table" means any table a no-arg
 * setup*() global function DECLARED IN tests/Pest.php builds — exactly the same predicate
 * SchemaDriftGuardTest already uses to decide which helpers to run, now shared via
 * Tests\Support\SchemaDrift\PestSetupHelpers so the two gates cannot silently drift apart on
 * what "canonical" means. This file used to hardcode 13 table names by hand; widening that list
 * by hand produced heavy false positives (the bare-name regex also matches prose like "create
 * table" in comments), so instead the ORACLE was inverted to a measurable jurisdiction.
 * Measured effect of the inversion: 6 offending files under the old 13-name list → ~31 under
 * PestSetupHelpers::tables(), with zero new false positives.
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
 * into the excluded directory is opting into a lane `composer test` never runs. What counts as
 * opting in is decided by fileHasRealUsesPostgresTestCaseCall() below, which requires a real call
 * (not a prose mention) that binds THIS file: the class may be named unqualified or via either
 * qualified form, and a chained `->in()` must target `__FILE__` — `->in('SomeDir')` retargets the
 * binding elsewhere and does NOT count. Read that function's docblock before relaxing anything
 * here; each condition is there because dropping it re-opens a specific hole.
 *
 * Pre-existing offenders (within scope) are grandfathered in the allowlist JSON below; this
 * guard only fails on a NEW file that introduces local canonical DDL. To (re)generate the
 * allowlist:
 *   NO_LOCAL_DDL_BASELINE=1 php artisan test --filter=NoLocalCanonicalTableDdlTest
 * Preferred fix for a flagged file: seed via the shared setup*Table() helpers instead.
 */

use Tests\Support\SchemaDrift\PestSetupHelpers;

const NO_LOCAL_DDL_BASELINE_PATH = __DIR__.'/../../../scripts/launch-check/no-local-canonical-ddl-baseline.json';

/**
 * Token-based (not substring) check for a REAL `uses(PostgresTestCase::class)` call that binds THIS
 * file to the Postgres lane. A bare str_contains($contents, 'PostgresTestCase::class') is defeated
 * by a decoy comment or a string literal that merely mentions the text — token_get_all() is immune
 * to both by construction: PHP's tokenizer emits comments as a single T_COMMENT/T_DOC_COMMENT token
 * and string literals as a single T_CONSTANT_ENCAPSED_STRING (or T_STRING-interpolated) token,
 * neither of which ever decomposes into the name/T_DOUBLE_COLON/T_CLASS sequence a real `Foo::class`
 * reference produces. There are 8 such prose mentions under tests/Postgres/ today, so this is not a
 * hypothetical: the substring form was passing on comments.
 *
 * Three things beyond "the text appears" have to hold, all learned from reviewing the first cut:
 *
 * 1. NAME FLAVOUR. PHP 8.0+ emits a namespaced name as ONE atomic token, so `\Tests\PostgresTestCase`
 *    is a single T_NAME_FULLY_QUALIFIED and `Tests\PostgresTestCase` a single T_NAME_QUALIFIED —
 *    NEITHER is a bare T_STRING. Matching only T_STRING therefore rejected the qualified forms that
 *    Pest's own docs use (`uses(Tests\TestCase::class)->in(...)`), a FALSE POSITIVE whose failure
 *    message would have told the author to do the thing their file already did. Match the last
 *    `\`-delimited segment of any name token instead.
 * 2. CALLEE SHAPE. `Decoy::uses(...)` / `$b->uses(...)` / `function uses(...)` are not Pest's global
 *    uses(), so the token before `uses` must not be `::`, `->`, `?->` or `function`.
 * 3. BINDING TARGET. A chained `->in(...)` RETARGETS the binding — `uses(X::class)->in('Feature')`
 *    binds files under tests/Feature, not the file it is written in. Such a file would sit in the
 *    path-excluded directory while still executing on the SQLite stand-in, which is precisely the
 *    dodge this assertion exists to close (a FALSE NEGATIVE, and one reachable by honest
 *    copy-paste from tests/Pest.php). Only `->in(__FILE__)` — or no `->in()` at all, which binds the
 *    enclosing file — counts. Scanned across the whole statement so chain order does not matter.
 *    The test is "the `->in()` args mention __FILE__", so `->in(dirname(__FILE__))` passes but
 *    `->in(__DIR__)` does NOT, even though it would in fact cover the enclosing file. That is a
 *    known, deliberate false positive: telling the two apart from `->in(__DIR__.'/../Feature')`
 *    needs concat-aware heuristics, and the fix if you ever hit it is to write `->in(__FILE__)`,
 *    which is the convention in all 33 files anyway. Do NOT resolve such a red by moving the file
 *    out of tests/Postgres/ or by regenerating the DDL baseline — both are the drift this catches.
 *
 * Deliberately NOT checked: reachability. `if (false) { uses(PostgresTestCase::class); }` passes.
 * Doing that properly needs a control-flow graph and would still lose to a `return;` above the call.
 * This guard defends the path exclusion against accidental drift and lazy dodges, not against an
 * adversary — anyone willing to write that could more cheaply delete this assertion outright. A
 * bypass harder than deleting the guard is not worth engineering against, and a partial heuristic
 * would read as protection that isn't here.
 */
function fileHasRealUsesPostgresTestCaseCall(string $contents): bool
{
    $tokens = token_get_all($contents);
    $count = count($tokens);
    $skippable = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    // First significant index at or after $idx. Takes the array so it serves both the full token
    // stream and a sliced argument list.
    $skipForward = function (array $arr, int $idx) use ($skippable): int {
        $len = count($arr);
        while ($idx < $len && is_array($arr[$idx]) && in_array($arr[$idx][0], $skippable, true)) {
            $idx++;
        }

        return $idx;
    };

    // Any of PHP 8's four name flavours whose final segment is PostgresTestCase. Prefixing a `\`
    // before strrchr() makes the unqualified case fall out of the same expression.
    $isPostgresTestCaseName = fn ($t): bool => is_array($t)
        && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)
        && substr(strrchr('\\'.$t[1], '\\'), 1) === 'PostgresTestCase';

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'uses') {
            continue;
        }

        // (2) Reject a method/static call or a declaration that merely shares the name `uses`.
        $p = $i - 1;
        while ($p >= 0 && is_array($tokens[$p]) && in_array($tokens[$p][0], $skippable, true)) {
            $p--;
        }
        if ($p >= 0 && is_array($tokens[$p]) && in_array($tokens[$p][0], [
            T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION,
        ], true)) {
            continue;
        }

        $j = $skipForward($tokens, $i + 1);
        if (! isset($tokens[$j]) || $tokens[$j] !== '(') {
            continue; // not a call — e.g. a property/constant named "uses"
        }

        // Collect this call's argument-list tokens up to the matching close paren. $k lands one
        // past that paren, which is where the chained-call scan in (3) picks up.
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

        // (1) Does this call's argument list actually name PostgresTestCase::class?
        $namesTheClass = false;
        $callCount = count($callTokens);
        for ($m = 0; $m < $callCount; $m++) {
            if (! $isPostgresTestCaseName($callTokens[$m])) {
                continue;
            }
            $n = $skipForward($callTokens, $m + 1);
            if (! isset($callTokens[$n]) || ! is_array($callTokens[$n]) || $callTokens[$n][0] !== T_DOUBLE_COLON) {
                continue;
            }
            $n = $skipForward($callTokens, $n + 1);
            if (isset($callTokens[$n]) && is_array($callTokens[$n]) && $callTokens[$n][0] === T_CLASS) {
                $namesTheClass = true;
                break;
            }
        }
        if (! $namesTheClass) {
            continue;
        }

        // (3) Walk the rest of the statement for a chained `->in(...)`; if there is one, it must
        // name __FILE__ or the binding lands on other files and this one never joins the lane.
        $retargetedElsewhere = false;
        $chainDepth = 0;
        for ($q = $k; $q < $count; $q++) {
            $t = $tokens[$q];
            if ($t === '(') {
                $chainDepth++;

                continue;
            }
            if ($t === ')') {
                $chainDepth--;

                continue;
            }
            if ($chainDepth === 0 && $t === ';') {
                break;
            }
            if ($chainDepth !== 0 || ! is_array($t)
                || ! in_array($t[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }

            $r = $skipForward($tokens, $q + 1);
            if (! isset($tokens[$r]) || ! is_array($tokens[$r])
                || $tokens[$r][0] !== T_STRING || $tokens[$r][1] !== 'in') {
                continue; // some other chained method — keep looking for ->in()
            }
            $r = $skipForward($tokens, $r + 1);
            if (! isset($tokens[$r]) || $tokens[$r] !== '(') {
                continue;
            }

            $inDepth = 1;
            $targetsThisFile = false;
            for ($s = $r + 1; $s < $count && $inDepth > 0; $s++) {
                if ($tokens[$s] === '(') {
                    $inDepth++;
                } elseif ($tokens[$s] === ')') {
                    $inDepth--;
                } elseif (is_array($tokens[$s]) && $tokens[$s][0] === T_FILE) {
                    $targetsThisFile = true; // __FILE__, incl. wrapped as dirname(__FILE__)
                }
            }
            $retargetedElsewhere = ! $targetsThisFile;
            break;
        }
        if ($retargetedElsewhere) {
            continue;
        }

        return true;
    }

    return false;
}

it('no test file builds local DDL for a canonical tenant table (or is baselined)', function () {
    $testsDir = dirname(__DIR__, 2); // repo/tests, from tests/Feature/Architecture

    // Jurisdiction = every table a setup*() helper in tests/Pest.php builds (see class
    // docblock). Bare names are matched too (preserves the original 13-name behaviour) because
    // several local-DDL offenders write an unqualified table-creation statement with no schema
    // prefix. Bare names collide harmlessly here — multiple schemas can share a bare table name
    // (e.g. "items") and matching any of them is still correct: SOME setup*() helper claims
    // that bare name. The DDL keywords are assembled from parts, and this comment deliberately
    // avoids writing the two keywords adjacently, so this guard's own source can never satisfy
    // the pattern it scans for (it holds itself to the rule).
    $helperTables = PestSetupHelpers::tables(); // 'site.menu_items' => 'setupSitesTable'
    $bareToQualified = [];
    foreach ($helperTables as $qualified => $helper) {
        $bare = substr($qualified, strpos($qualified, '.') + 1);
        $bareToQualified[$bare][] = "{$qualified} ({$helper}())";
    }
    $bareNames = array_keys($bareToQualified);
    sort($bareNames);
    $pattern = '/'.'CREATE'.'\s+'.'TABLE'.'\s+(?:IF\s+NOT\s+EXISTS\s+)?'
        .'(?:"?[a-z_]+"?\.)?"?('.implode('|', $bareNames).')"?\b/i';

    // Exempt from the scan (repo-relative). tests/Pest.php owns the canonical shared schema;
    // the comparator test's DDL strings are in-memory fixtures fed to the comparator, not a
    // real schema the suite ever seeds against; tests/Support/SchemaDrift/ is drift-gate
    // infrastructure (this file's own PestSetupHelpers among it), not a fixture.
    $exempt = [
        'tests/Pest.php',
        'tests/Unit/SchemaDrift/DriftComparatorTest.php',
    ];
    $exemptPrefixes = [
        'tests/Postgres/',
        'tests/Support/SchemaDrift/',
    ];

    $offenders = [];
    $offenderTables = []; // rel path => matched bare table names, for the failure message
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
        $isExemptPrefix = false;
        foreach ($exemptPrefixes as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                $isExemptPrefix = true;
                break;
            }
        }
        if ($isExemptPrefix) {
            continue;
        }
        if (preg_match_all($pattern, file_get_contents($file->getPathname()), $matches)) {
            $offenders[] = $rel;
            $offenderTables[$rel] = array_values(array_unique(array_map('strtolower', $matches[1])));
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

    // Name the specific helper(s) each new offender collides with, so the remedy is
    // copy-pasteable rather than "go find the right setup*Table() yourself".
    $newDescribed = array_map(function (string $rel) use ($offenderTables, $bareToQualified) {
        $tables = $offenderTables[$rel] ?? [];
        $remedies = [];
        foreach ($tables as $bare) {
            foreach ($bareToQualified[$bare] ?? [] as $qualifiedHelper) {
                $remedies[] = $qualifiedHelper;
            }
        }
        $remedies = array_unique($remedies);

        return $remedies === [] ? $rel : "{$rel} — matches: ".implode(', ', $remedies);
    }, $new);

    expect($new)->toBe([], sprintf(
        "NEW local canonical-table DDL — these test files build their own table for a canonical\n".
        "tenant table, which SchemaDriftGuardTest cannot see (so a prod-violating seed can pass green):\n  %s\n".
        "Fix: seed through the shared setup*Table() helper named above instead of local DDL.\n".
        "If a file genuinely needs a bespoke table, regenerate the allowlist:\n".
        '  NO_LOCAL_DDL_BASELINE=1 php artisan test --filter=NoLocalCanonicalTableDdlTest',
        implode("\n  ", $newDescribed)
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

/**
 * Fixtures for the walker itself — the assertion above is only as good as this function, and every
 * ACCEPT case below is a form that an earlier cut of it wrongly rejected (or vice versa).
 *
 * These are inline source strings on purpose, NOT fixture files. A .php fixture under tests/ would
 * be scanned by the canonical-DDL assertion at the top of this file, scanned by the lane assertion
 * above if placed under tests/Postgres/, and autoloaded as a test by Pest. There is nowhere under
 * tests/ to put one safely, so the source is passed straight to the function.
 */
it('the lane opt-in walker accepts real bindings and rejects prose, decoys and retargeted ones', function (string $source, bool $expected) {
    expect(fileHasRealUsesPostgresTestCaseCall($source))->toBe($expected);
})->with([
    // ACCEPT — real calls that bind this file.
    'repo convention: import + bare name + ->in(__FILE__)' => [<<<'PHP'
        <?php
        use Tests\PostgresTestCase;
        uses(PostgresTestCase::class)->in(__FILE__);
        PHP, true],
    'bare name, no ->in() at all (binds the enclosing file)' => [<<<'PHP'
        <?php
        uses(PostgresTestCase::class);
        PHP, true],
    'fully-qualified name (one T_NAME_FULLY_QUALIFIED token)' => [<<<'PHP'
        <?php
        uses(\Tests\PostgresTestCase::class)->in(__FILE__);
        PHP, true],
    'qualified name (one T_NAME_QUALIFIED token — the form Pest docs use)' => [<<<'PHP'
        <?php
        uses(Tests\PostgresTestCase::class);
        PHP, true],
    'alongside another base class in the same call' => [<<<'PHP'
        <?php
        uses(Tests\TestCase::class, PostgresTestCase::class)->in(__FILE__);
        PHP, true],
    '->in(dirname(__FILE__)) still covers this file' => [<<<'PHP'
        <?php
        uses(PostgresTestCase::class)->in(dirname(__FILE__));
        PHP, true],
    'chain order does not matter' => [<<<'PHP'
        <?php
        uses(PostgresTestCase::class)->group('pg')->in(__FILE__);
        PHP, true],

    // REJECT — prose. The original str_contains() check passed on all three of these.
    'line comment mentioning the class' => [<<<'PHP'
        <?php
        // Runs for real against Postgres — see uses(PostgresTestCase::class) in the sibling files.
        uses(Tests\TestCase::class)->in(__FILE__);
        PHP, false],
    'docblock mentioning the class' => [<<<'PHP'
        <?php
        /** Lane: uses(PostgresTestCase::class). */
        uses(Tests\TestCase::class)->in(__FILE__);
        PHP, false],
    'string literal mentioning the class' => [<<<'PHP'
        <?php
        throw new RuntimeException('needs uses(PostgresTestCase::class)');
        PHP, false],

    // REJECT — a callee that merely shares the name `uses`.
    'static call on another class' => [<<<'PHP'
        <?php
        Decoy::uses(PostgresTestCase::class);
        PHP, false],
    'method call on an object' => [<<<'PHP'
        <?php
        $builder->uses(PostgresTestCase::class);
        PHP, false],
    'a function declaration named uses' => [<<<'PHP'
        <?php
        function uses(string $class) { return $class; }
        PHP, false],

    // REJECT — the binding never lands on this file.
    '->in() retargeted at another directory' => [<<<'PHP'
        <?php
        uses(PostgresTestCase::class)->in('Feature');
        PHP, false],
    '->in() retargeted, class named later in the chain' => [<<<'PHP'
        <?php
        uses(PostgresTestCase::class)->group('pg')->in(__DIR__.'/../Feature');
        PHP, false],

    // ACCEPT — a retargeted call must not poison a later valid one. Guards the `continue` in (3):
    // turning it into `return false` (a natural-looking simplification) still passes every other
    // case here and only breaks this one.
    'a retargeted call followed by a valid one' => [<<<'PHP'
        <?php
        uses(PostgresTestCase::class)->in('Feature');
        uses(PostgresTestCase::class)->in(__FILE__);
        PHP, true],

    // REJECT — a different class entirely, incl. one whose name merely ends with the target.
    'some other base class' => [<<<'PHP'
        <?php
        uses(Tests\TestCase::class)->in(__FILE__);
        PHP, false],
    'a longer class name ending in the target' => [<<<'PHP'
        <?php
        uses(NotPostgresTestCase::class)->in(__FILE__);
        PHP, false],
    'named but not as ::class' => [<<<'PHP'
        <?php
        uses(PostgresTestCase::WHATEVER)->in(__FILE__);
        PHP, false],
]);
