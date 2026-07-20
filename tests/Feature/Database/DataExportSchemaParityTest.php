<?php

// Verifies every column the GDPR data export SELECTs or FILTERs on actually
// exists in the live PostgreSQL schema. Run against real PostgreSQL only.
//
// WHY THIS EXISTS
// ---------------
// On 2026-07-19 the whole DSAR export was found to be 500ing in production:
// DataExportPayloadBuilder::streamMedia() selected site_media.width/height and
// filtered on site_media.user_id — none of which exist (dimensions live on
// site.media_variants; tenancy runs site_id -> site.sites). It had been broken
// since the FOUND-1 refactor and the suite never noticed, for two reasons:
//
//   1. The SQLite fixture mirrored the QUERY's select list rather than prod.
//   2. More fundamentally, SQLite silently reinterprets an unknown
//      double-quoted identifier as a STRING LITERAL instead of erroring:
//         select "width" ...     -> returns the string 'width'
//         where "user_id" = ?    -> matches zero rows, no error
//      So NO SQLite-backed test can detect a column that doesn't exist. Only
//      real PostgreSQL rejects it (42703).
//
// HOW IT WORKS
// ------------
// Each stream* method is invoked by reflection with a dummy UUID/email and its
// generator drained. PostgreSQL resolves column names at PARSE time, so a
// nonexistent column raises 42703 even though the filter matches zero rows —
// verified. That means this test needs no seeded data and performs NO WRITES,
// and it covers new sections automatically as they are added.
//
// To run against the Supabase dev DB:
//   DB_CONNECTION=pgsql DB_HOST=... ./vendor/bin/pest --filter=DataExportSchemaParityTest

use App\Services\User\DataExport\DataExportPayloadBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function dataExportParitySuiteIsPostgres(): bool
{
    return DB::connection('pgsql')->getDriverName() === 'pgsql';
}

/**
 * Build a dummy argument for a stream* parameter based on its name.
 * Email-ish params get an unroutable address; everything else gets a UUID that
 * matches nothing. Both are only ever used as bound values, never interpolated.
 */
function dataExportParityDummyArg(ReflectionParameter $param): string
{
    return str_contains(mb_strtolower($param->getName()), 'email')
        ? 'nobody@example.invalid'
        : '00000000-0000-0000-0000-000000000000';
}

it('every column the data export reads exists in the live schema', function () {
    if (! dataExportParitySuiteIsPostgres()) {
        $this->markTestSkipped(
            'Column existence can only be validated on PostgreSQL — SQLite treats an '
            .'unknown double-quoted identifier as a string literal instead of erroring.'
        );
    }

    $builder = app(DataExportPayloadBuilder::class);
    $reflection = new ReflectionClass($builder);

    // stream* covers the row sections; site() additionally reads site.sites.
    //
    // The public stream() orchestrator is EXCLUDED deliberately: it resolves the
    // user via loadUser()->firstOrFail(), so a dummy id throws
    // ModelNotFoundException before any section query runs — it would abort the
    // sweep without testing anything.
    //
    // Known limit: site() early-returns when the dummy user owns no site, so its
    // nested site.blocks / site.workplaces reads are not exercised here. Both now
    // use an explicit allow-list (site.blocks: PRIV-2; site.workplaces: PRIV-5),
    // so a future column rename in either could drift undetected until this
    // early return is addressed. (T2 in DataExportCoverageTest.php covers both
    // via migration replay regardless of this early return — that guard is
    // DB-independent and doesn't need a seeded site to run.)
    $methods = array_values(array_filter(
        $reflection->getMethods(),
        fn (ReflectionMethod $m) => (str_starts_with($m->getName(), 'stream') && $m->getName() !== 'stream')
            || $m->getName() === 'site',
    ));

    expect($methods)->not->toBeEmpty('Found no export section methods to check — has the builder been renamed?');

    $failures = [];

    foreach ($methods as $method) {
        $method->setAccessible(true);

        $args = array_map(dataExportParityDummyArg(...), $method->getParameters());

        try {
            $result = $method->invokeArgs($builder, $args);

            // Generators are lazy — the query does not run until drained.
            if ($result instanceof Generator) {
                iterator_to_array($result);
            }
        } catch (QueryException $e) {
            $failures[] = $method->getName().'(): '.explode("\n", $e->getMessage())[0];
        }
    }

    expect($failures)->toBe([], "Data export sections querying columns that do not exist in the live schema:\n  - "
        .implode("\n  - ", $failures)
        ."\n\nFix the section's select()/where() to match supabase/migrations/ DDL. "
        .'Do NOT "fix" this by changing a SQLite fixture — SQLite cannot detect this class of bug.');
});
