<?php

/**
 * Coverage-hole guard: no test file may build its OWN table-creation DDL for a canonical
 * tenant table (core.users, site.sites, site.platform_connections, site.menus,
 * site.design_kits). Such local DDL is invisible to SchemaDriftGuardTest — that gate only
 * introspects the shared setup*Table() helpers in tests/Pest.php — so a local, permissive
 * copy can seed a prod-violating row and still pass CI green (this is how a null resource_id
 * slipped past the T3 drift tightening). The shared helpers are the single source of truth
 * for these tables' schema.
 *
 * Pre-existing offenders are grandfathered in the allowlist JSON below; this guard only fails
 * on a NEW file that introduces local canonical DDL. To (re)generate the allowlist:
 *   NO_LOCAL_DDL_BASELINE=1 php artisan test --filter=NoLocalCanonicalTableDdlTest
 * Preferred fix for a flagged file: seed via the shared setup*Table() helpers instead.
 */
const NO_LOCAL_DDL_BASELINE_PATH = __DIR__.'/../../../scripts/launch-check/no-local-canonical-ddl-baseline.json';

it('no test file builds local DDL for a canonical tenant table (or is baselined)', function () {
    $testsDir = dirname(__DIR__, 2); // repo/tests, from tests/Feature/Architecture

    // Canonical tenant tables. Their bare names are unique in this schema, so the guard
    // matches with or without a schema prefix. The DDL keywords are assembled from parts so
    // this guard's own source can never satisfy the pattern (it holds itself to the rule).
    $tables = ['users', 'sites', 'platform_connections', 'menus', 'design_kits'];
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
