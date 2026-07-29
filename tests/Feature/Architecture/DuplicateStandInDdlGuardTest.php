<?php

/**
 * Coverage-hole guard, complementary to NoLocalCanonicalTableDdlTest and SchemaDriftGuardTest:
 * neither of those can see a stand-in table declared MORE THAN ONCE with IF NOT EXISTS.
 *
 * Mechanism: tests/TestCase.php:33 calls DB::purge('pgsql') in setUp(), so the in-memory
 * SQLite database is rebuilt PER TEST. Two declarations in different files can therefore never
 * shadow each other — they never run against the same database. Shadowing only happens when
 * two declarations for the same table EXECUTE IN THE SAME TEST. IF NOT EXISTS makes the second
 * one a SILENT no-op, so the test runs against the FIRST body while a reader believes the
 * second is what's under test. Without IF NOT EXISTS the engine raises "already exists" —
 * loud, self-detecting, and out of scope here.
 *
 * Two findings, both restricted to IF NOT EXISTS declarations only:
 *
 * (a) SAME-FILE DUPLICATE — a file declares the same qualified name more than once. This is
 *     exactly the #PARITY-1 defect shape: DataExportTestCase.php declared
 *     audit.user_deletion_audit twice in the same boot() (fixed in this change — the second,
 *     dead copy was missing ip_address/user_agent/metadata). Fires on ANY same-file duplicate,
 *     identical body or not, since a same-file duplicate is never intentional.
 *
 * (b) SHADOW OF tests/Pest.php — a file other than tests/Pest.php, and NOT under
 *     tests/Postgres/ (see SCOPE below), declares, with IF NOT EXISTS, a qualified name
 *     tests/Pest.php ALSO declares, and the normalised bodies DIFFER. Live example:
 *     SubdomainChangeTest.php's setupCoreSchema() calls setupSitesTable(), which itself calls
 *     setupSubdomainAliasesTable() — so the shared, PERMISSIVE site.site_subdomain_aliases
 *     (nullable columns) is created FIRST, and the file's own STRICTER local copy is a silent
 *     no-op. That test believes it runs under NOT NULL columns and does not. This table set is
 *     derived AUTOMATICALLY from whatever tests/Pest.php declares (99 tables today) — no
 *     hand-curated list to keep in sync.
 *
 * SCOPE (finding (b) only): tests/Postgres/ is excluded by path, mirroring
 * NoLocalCanonicalTableDdlTest's exclusion and for the identical reason. That lane
 * (phpunit.pg.xml / `composer test:pg`, its own CI job) runs its DDL against a REAL disposable
 * Postgres database via PostgresTestCase, never against the SQLite stand-in tests/Pest.php
 * builds — the two can never co-execute in the same test, so a same-named declaration under
 * tests/Postgres/ is a textual name collision, not a live shadow. This was measured directly:
 * widening finding (b)'s in-scope table list once produced 7 "shadow" entries across
 * ItemSlugAllocatorRegressionTest.php, StaffFeatureFlagOverrideEndpointTest.php, and
 * SubdomainAliasCollisionTest.php, all of which merely declare shared Postgres-lane scaffolding
 * (core.users, core.partna_staff, core.feature_flags, site.sites) that happens to share a name
 * with a tests/Pest.php helper — this guard's own fix message ("seed through the shared
 * setup*Table() helper, or tighten the SHARED one") is actively wrong advice for them, since
 * tightening the SQLite stand-in's helper has no bearing on a real-Postgres test. Finding (a)
 * (same-file duplicate) is NOT given this exclusion — a same-file duplicate declaration is a
 * real bug regardless of which lane the file runs in, so tests/Postgres/ stays in scope there.
 *
 * Deliberately NOT implemented: a runtime-reachability filter (does this file's test actually
 * CALL the tests/Pest.php helper that shadows it?). Measured to cut the finding count roughly
 * in half, but with false negatives — helpers reach tests/Pest.php's setup* functions
 * indirectly (createTenant() -> tenantHelpersEnsureTables(), actingAsUser(), etc.), and a
 * static approximation that silently drops real findings is worse than an honest, larger
 * baseline.
 *
 * Normalisation folds exactly what a formatter can change without changing what the database
 * does: SQL comments stripped, whitespace collapsed, case folded OUTSIDE single-quoted
 * literals only (TEXT == text, but DEFAULT 'Pinned' != DEFAULT 'pinned'). Column NAMES, COLUMN
 * ORDER, types, NOT NULL, DEFAULT, CHECK, PRIMARY KEY, UNIQUE, and REFERENCES are all compared
 * byte-for-byte after normalising. Column order is deliberately NOT normalised — a reordered
 * column is observable (SELECT *, a column-list-less INSERT, SQLite's ADD COLUMN position) and
 * sorting it away would also hide a swapped same-typed pair.
 *
 * Extraction (regex alone cannot find a body — bodies nest parens: numeric(10,2), CHECK (kind
 * IN ('a','b'))): match the opening keywords with schema-qualification REQUIRED, then scan
 * forward from the matched '(' counting depth to find the matching ')'. The DDL keywords are
 * assembled from parts (mirroring NoLocalCanonicalTableDdlTest) so this guard's own source can
 * never satisfy its own pattern. The balanced-paren scan is naive — it does not skip quoted
 * literals, so an unmatched paren inside one (e.g. a DEFAULT of a single open-paren character)
 * would run the scan to the end of the file. Mitigated, not prevented: two sanity assertions
 * below require tests/Pest.php to still yield >= 95 declarations and every extracted body to
 * stay under 8KB, so a scanner regression fails LOUDLY instead of silently finding nothing.
 *
 * $exempt (repo-relative):
 * - tests/Unit/SchemaDrift/DriftComparatorTest.php — in-memory comparator fixtures, not a real
 *   schema the suite ever seeds against (same reason NoLocalCanonicalTableDdlTest exempts it).
 *   Excluded from BOTH findings.
 * - tests/Feature/User/AccountDeletion/AccountDeletionTestCase.php,
 *   tests/Feature/User/DataExport/DataExportTestCase.php,
 *   tests/Feature/FeatureFlags/SectionVisibilityTestCase.php — excluded from finding (b) ONLY,
 *   NOT (a). Each boot() repoints 'database.default'/'pgsql' at a FRESH :memory: connection and
 *   calls NO tests/Pest.php setup* helper — they replace the shared stand-in wholesale rather
 *   than augment it, so tests/Pest.php's declarations never run in the same database and can
 *   never actually shadow theirs. PRECONDITION for this exemption: if any of the three ever
 *   starts calling a tests/Pest.php helper, its divergent local copies become live shadows and
 *   this guard goes blind to them — check before reusing this exemption elsewhere. They stay
 *   IN SCOPE for (a): that's how the audit.user_deletion_audit duplicate above was caught.
 * - tests/Postgres/ (by path prefix, not a named list) — excluded from finding (b) ONLY, NOT
 *   (a), for the reason given in SCOPE above.
 *
 * The baseline is a WORK QUEUE, not a resolution — every entry is a test quietly running
 * against a schema copy it did not write. The $drained STDERR note is the signal to shrink it.
 *
 * Known gaps (not attempted here): cross-file duplicates that don't route through
 * tests/Pest.php at all (e.g. many files each declare their own local audit.staff_audit_log
 * with different bodies and no shared helper — a real divergence problem, untouched by this
 * guard); non-IF-NOT-EXISTS shadowing (a DROP-then-CREATE in a beforeEach could still silently
 * replace a shared table); PHP-interpolated bodies; type-synonym false positives (int vs
 * integer — accepted, costs a one-line edit). Neither finding here checks that a stand-in body
 * actually matches real Postgres — that is SchemaDriftGuardTest's job.
 *
 * To (re)generate the baseline: DUP_STANDIN_DDL_BASELINE=1 php artisan test --filter=DuplicateStandInDdlGuardTest
 *
 * Parallel-safety: both findings are pure filesystem scans with no DB access, so they are
 * order- and worker-independent. Do NOT make either introspect a live connection.
 */
const DUP_STANDIN_BASELINE_PATH = __DIR__.'/../../../scripts/launch-check/duplicate-stand-in-ddl-baseline.json';

/**
 * Extract every schema-qualified stand-in declaration from $content, keyed by
 * strtolower('schema.table'), body-only (the balanced parenthesised block, exclusive of the
 * parens themselves). Only declarations guarded by IF NOT EXISTS are returned — see the
 * docblock above for why that's the whole of this guard's scope.
 */
function dupStandInExtractDeclarations(string $content): array
{
    $pattern = '/'.'CREATE'.'\s+'.'TABLE'.'\s+((?:'.'IF'.'\s+'.'NOT'.'\s+'.'EXISTS'.'\s+)?)'
        .'"?([a-z_]+)"?\.'.'"?([a-z_]+)"?\s*\(/i';

    preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);

    $declarations = [];
    $count = count($matches[0]);
    for ($i = 0; $i < $count; $i++) {
        if ($matches[1][$i][0] === '') {
            continue; // no IF NOT EXISTS — out of scope
        }

        $schema = strtolower($matches[2][$i][0]);
        $table = strtolower($matches[3][$i][0]);
        $fullMatch = $matches[0][$i][0];
        $fullOffset = $matches[0][$i][1];
        $openParenOffset = $fullOffset + strlen($fullMatch) - 1;

        $depth = 0;
        $length = strlen($content);
        $closeParenOffset = $length; // fallback if never balanced — see docblock
        for ($pos = $openParenOffset; $pos < $length; $pos++) {
            $char = $content[$pos];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    $closeParenOffset = $pos;
                    break;
                }
            }
        }

        $declarations[] = [
            'key' => "{$schema}.{$table}",
            'body' => substr($content, $openParenOffset + 1, $closeParenOffset - $openParenOffset - 1),
        ];
    }

    return $declarations;
}

/**
 * Normalise a body to exactly what a formatter could change without changing what the
 * database does. See the docblock above for the precise list of what is and is not folded.
 */
function dupStandInNormalizeBody(string $body): string
{
    $body = preg_replace('/--[^\n]*/', '', $body);
    $body = preg_replace('/\/\*.*?\*\//s', '', $body);

    $segments = preg_split("/('(?:[^']|'')*')/", $body, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($segments as $index => $segment) {
        if ($index % 2 === 0) {
            $segments[$index] = strtolower($segment);
        }
    }
    $body = implode('', $segments);

    $body = preg_replace('/\s+/', ' ', $body);
    $body = preg_replace('/\s*([(),])\s*/', '$1', $body);
    $body = trim($body);

    return rtrim($body, ',');
}

/** Every *.php under $testsDir, mapped to its extracted declarations (files with none omitted). */
function dupStandInScanTree(string $testsDir): array
{
    $filesDeclarations = [];
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testsDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $rel = 'tests/'.str_replace('\\', '/', ltrim(substr($file->getPathname(), strlen($testsDir)), '/\\'));
        $declarations = dupStandInExtractDeclarations(file_get_contents($file->getPathname()));
        if ($declarations !== []) {
            $filesDeclarations[$rel] = $declarations;
        }
    }

    return $filesDeclarations;
}

/**
 * A scanner regression must fail LOUDLY, not silently find nothing (or silently truncate).
 * tests/Pest.php declares 99 stand-in tables today with a max raw body around 3.3KB — 95/8KB
 * leaves headroom for new tables while still catching a scanner that stops finding
 * declarations, or runs one to EOF via the unbalanced-paren fallback above.
 */
function dupStandInAssertPestSanity(array $pestDeclarations): void
{
    expect(count($pestDeclarations))->toBeGreaterThanOrEqual(95, sprintf(
        'The stand-in DDL extractor only found %d IF NOT EXISTS declarations in tests/Pest.php'.
        " — expected >= 95. This usually means the balanced-paren scanner regressed and is\n".
        'silently finding fewer declarations than it should.',
        count($pestDeclarations)
    ));

    foreach ($pestDeclarations as $declaration) {
        expect(strlen($declaration['body']))->toBeLessThan(8 * 1024, sprintf(
            "tests/Pest.php declaration %s has an extracted body of %d bytes (>= 8KB) — this\n".
            "usually means the balanced-paren scanner ran off the end of a declaration (e.g. an\n".
            "unmatched '(' inside a quoted DEFAULT) instead of stopping at its real close.",
            $declaration['key'],
            strlen($declaration['body'])
        ));
    }
}

function dupStandInReadBaseline(): array
{
    return is_file(DUP_STANDIN_BASELINE_PATH)
        ? json_decode(file_get_contents(DUP_STANDIN_BASELINE_PATH), true)
        : [];
}

/** Replace only this $kindPrefix's entries in the shared baseline file, leaving the other kind untouched. */
function dupStandInRegenerateBaseline(string $kindPrefix, array $freshIds): void
{
    $kept = array_values(array_filter(
        dupStandInReadBaseline(),
        fn ($id) => ! str_starts_with($id, $kindPrefix)
    ));
    $merged = array_values(array_unique(array_merge($kept, $freshIds)));
    sort($merged);
    file_put_contents(
        DUP_STANDIN_BASELINE_PATH,
        json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
    );
}

it('no test file declares the same stand-in table twice within itself (or is baselined)', function () {
    $testsDir = dirname(__DIR__, 2); // repo/tests, from tests/Feature/Architecture
    $fileDeclarations = dupStandInScanTree($testsDir);

    dupStandInAssertPestSanity($fileDeclarations['tests/Pest.php'] ?? []);

    $exempt = ['tests/Unit/SchemaDrift/DriftComparatorTest.php'];

    $findings = []; // 'dup:rel:key' => diagnostic
    foreach ($fileDeclarations as $rel => $declarations) {
        if (in_array($rel, $exempt, true)) {
            continue;
        }
        $bodiesByKey = [];
        foreach ($declarations as $declaration) {
            $bodiesByKey[$declaration['key']][] = $declaration['body'];
        }
        foreach ($bodiesByKey as $key => $bodies) {
            if (count($bodies) < 2) {
                continue;
            }
            $normalized = array_unique(array_map('dupStandInNormalizeBody', $bodies));
            $diagnostic = count($normalized) === 1 ? 'bodies identical' : 'bodies differ';
            $findings["dup:{$rel}:{$key}"] = $diagnostic;
        }
    }
    ksort($findings);
    $findingIds = array_keys($findings);

    if (getenv('DUP_STANDIN_DDL_BASELINE') === '1') {
        dupStandInRegenerateBaseline('dup:', $findingIds);
        expect(true)->toBeTrue(); // baseline regenerated — always green

        return;
    }

    $baseline = array_values(array_filter(dupStandInReadBaseline(), fn ($id) => str_starts_with($id, 'dup:')));
    $new = array_values(array_diff($findingIds, $baseline));
    $drained = array_values(array_diff($baseline, $findingIds));

    expect($new)->toBe([], sprintf(
        "NEW same-file duplicate stand-in declaration — a test file declares the SAME table\n".
        "more than once with IF NOT EXISTS in one file. IF NOT EXISTS makes the second\n".
        "declaration a silent no-op, so the test runs against the FIRST body while a reader\n".
        "sees the second (this is how DataExportTestCase.php's audit.user_deletion_audit\n".
        "duplicate went unnoticed):\n  %s\n".
        "Fix: extract one helper and call it from both places.\n".
        "If a same-file duplicate is genuinely intentional, regenerate the baseline:\n".
        '  DUP_STANDIN_DDL_BASELINE=1 php artisan test --filter=DuplicateStandInDdlGuardTest',
        implode("\n  ", array_map(fn ($id) => "{$id} ({$findings[$id]})", $new))
    ));

    if ($drained !== []) {
        fwrite(STDERR, "\n[dup-standin-ddl] ".count($drained).' baselined same-file duplicate(s) no'.
            " longer duplicate — regenerate the baseline to lock them out.\n");
    }
});

it("no test file's local stand-in DDL silently shadows a tests/Pest.php declaration with a different body (or is baselined)", function () {
    $testsDir = dirname(__DIR__, 2); // repo/tests, from tests/Feature/Architecture
    $fileDeclarations = dupStandInScanTree($testsDir);

    $pestDeclarations = $fileDeclarations['tests/Pest.php'] ?? [];
    dupStandInAssertPestSanity($pestDeclarations);

    $pestBodyByKey = [];
    foreach ($pestDeclarations as $declaration) {
        // tests/Pest.php has no same-file duplicates of its own (see the sibling assertion
        // above) — first-seen body per key is authoritative.
        $pestBodyByKey[$declaration['key']] ??= $declaration['body'];
    }

    $exemptFull = ['tests/Pest.php', 'tests/Unit/SchemaDrift/DriftComparatorTest.php'];
    $exemptWholesaleStandIns = [
        'tests/Feature/User/AccountDeletion/AccountDeletionTestCase.php',
        'tests/Feature/User/DataExport/DataExportTestCase.php',
        'tests/Feature/FeatureFlags/SectionVisibilityTestCase.php',
    ];

    $findings = []; // 'shadow:rel:key' => true
    foreach ($fileDeclarations as $rel => $declarations) {
        if (in_array($rel, $exemptFull, true) || in_array($rel, $exemptWholesaleStandIns, true)) {
            continue;
        }
        if (str_starts_with($rel, 'tests/Postgres/')) {
            continue; // see docblock: tests/Postgres/ can never co-execute with tests/Pest.php's stand-in
        }
        $seenKeys = [];
        foreach ($declarations as $declaration) {
            $key = $declaration['key'];
            if (! isset($pestBodyByKey[$key]) || isset($seenKeys[$key])) {
                continue; // not a shadow candidate, or already recorded (pair-level, not occurrence-level)
            }
            $seenKeys[$key] = true;
            if (dupStandInNormalizeBody($declaration['body']) === dupStandInNormalizeBody($pestBodyByKey[$key])) {
                continue; // identical — not a finding; becomes one the moment either side diverges
            }
            $findings["shadow:{$rel}:{$key}"] = true;
        }
    }
    $findingIds = array_keys($findings);
    sort($findingIds);

    if (getenv('DUP_STANDIN_DDL_BASELINE') === '1') {
        dupStandInRegenerateBaseline('shadow:', $findingIds);
        expect(true)->toBeTrue(); // baseline regenerated — always green

        return;
    }

    $baseline = array_values(array_filter(dupStandInReadBaseline(), fn ($id) => str_starts_with($id, 'shadow:')));
    $new = array_values(array_diff($findingIds, $baseline));
    $drained = array_values(array_diff($baseline, $findingIds));

    expect($new)->toBe([], sprintf(
        "NEW stand-in DDL shadow — these test files declare, with IF NOT EXISTS, a table\n".
        "tests/Pest.php ALSO declares, with a DIFFERENT normalised body. tests/Pest.php's shared\n".
        "helper runs first in setup order, so IF NOT EXISTS makes the file's own (often\n".
        "stricter) copy a silent no-op — the test believes it runs under its own schema and\n".
        "does not:\n  %s\n".
        "Fix: seed through the shared setup*Table() helper instead of a local copy, or if the\n".
        "shared helper is missing a constraint, tighten the SHARED one so every caller benefits.\n".
        "If the divergence is genuinely intentional, regenerate the baseline:\n".
        '  DUP_STANDIN_DDL_BASELINE=1 php artisan test --filter=DuplicateStandInDdlGuardTest',
        implode("\n  ", $new)
    ));

    if ($drained !== []) {
        fwrite(STDERR, "\n[dup-standin-ddl] ".count($drained).' baselined shadow pair(s) no longer'.
            " shadow tests/Pest.php — regenerate the baseline to lock them out.\n");
    }
});
