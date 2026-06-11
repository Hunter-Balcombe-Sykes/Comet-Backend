<?php

// TEST-5: structural drift guard between request-class design_kit allowlists
// and the actual site.design_kits columns. Fails if a migration adds/drops a
// column without updating both UpdateSiteRequest and StaffUpdateSiteRequest.

use App\Http\Requests\Api\Staff\UserSite\StaffUpdateSiteRequest;
use App\Http\Requests\Api\User\Site\UpdateSiteRequest;
use Illuminate\Support\Facades\DB;

// Extract column names from `design_kit.{column_name}` keys in a rules array.
// The `design_kit` parent key itself (no dot suffix) is skipped — it's the
// array container rule, not a column reference.
function extractDesignKitColumnNames(array $rules): array
{
    $columns = [];

    foreach (array_keys($rules) as $key) {
        if (! str_starts_with($key, 'design_kit.')) {
            continue;
        }

        $columns[] = substr($key, strlen('design_kit.'));
    }

    sort($columns);

    return $columns;
}

// Fetch design var columns from the live PostgreSQL DB.
// Returns null when running against the SQLite in-memory test env (no information_schema).
// Structural columns (PK, timestamps) are excluded — they are not design vars.
function fetchDesignKitDbColumns(): ?array
{
    $conn = DB::connection('pgsql');

    // The test env aliases 'pgsql' to SQLite in-memory (see TestCase::setUp).
    // information_schema is a PostgreSQL feature — skip on SQLite.
    if ($conn->getDriverName() !== 'pgsql') {
        return null;
    }

    $rows = $conn->select("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = 'site'
          AND table_name = 'design_kits'
          AND column_name NOT IN ('site_id', 'created_at', 'updated_at', 'deleted_at')
        ORDER BY column_name
    ");

    return array_column($rows, 'column_name');
}

it('every site.design_kits column has a matching rule in UpdateSiteRequest', function () {
    $dbColumns = fetchDesignKitDbColumns();
    if ($dbColumns === null) {
        $this->markTestSkipped('Requires real PostgreSQL — skipped in SQLite test env.');
    }

    $requestKeys = extractDesignKitColumnNames((new UpdateSiteRequest)->rules());
    $missing = array_values(array_diff($dbColumns, $requestKeys));

    expect($missing)->toBe(
        [],
        "site.design_kits columns missing from UpdateSiteRequest:\n  - ".implode("\n  - ", $missing)."\n\n"
        ."Add a 'design_kit.{column_name}' rule to UpdateSiteRequest::rules()."
    );
});

it('every site.design_kits column has a matching rule in StaffUpdateSiteRequest', function () {
    $dbColumns = fetchDesignKitDbColumns();
    if ($dbColumns === null) {
        $this->markTestSkipped('Requires real PostgreSQL — skipped in SQLite test env.');
    }

    $requestKeys = extractDesignKitColumnNames((new StaffUpdateSiteRequest)->rules());
    $missing = array_values(array_diff($dbColumns, $requestKeys));

    expect($missing)->toBe(
        [],
        "site.design_kits columns missing from StaffUpdateSiteRequest:\n  - ".implode("\n  - ", $missing)."\n\n"
        ."Add a 'design_kit.{column_name}' rule to StaffUpdateSiteRequest::rules()."
    );
});

it('UpdateSiteRequest has no design_kit rules for non-existent columns', function () {
    $dbColumns = fetchDesignKitDbColumns();
    if ($dbColumns === null) {
        $this->markTestSkipped('Requires real PostgreSQL — skipped in SQLite test env.');
    }

    $requestKeys = extractDesignKitColumnNames((new UpdateSiteRequest)->rules());
    $phantom = array_values(array_diff($requestKeys, $dbColumns));

    expect($phantom)->toBe(
        [],
        "UpdateSiteRequest references design_kit columns that don't exist in site.design_kits:\n  - ".implode("\n  - ", $phantom)."\n\n"
        .'Either add the missing column via a SQL migration in supabase/migrations/ or remove the stale rule.'
    );
});

it('StaffUpdateSiteRequest has no design_kit rules for non-existent columns', function () {
    $dbColumns = fetchDesignKitDbColumns();
    if ($dbColumns === null) {
        $this->markTestSkipped('Requires real PostgreSQL — skipped in SQLite test env.');
    }

    $requestKeys = extractDesignKitColumnNames((new StaffUpdateSiteRequest)->rules());
    $phantom = array_values(array_diff($requestKeys, $dbColumns));

    expect($phantom)->toBe(
        [],
        "StaffUpdateSiteRequest references design_kit columns that don't exist in site.design_kits:\n  - ".implode("\n  - ", $phantom)."\n\n"
        .'Either add the missing column via a SQL migration in supabase/migrations/ or remove the stale rule.'
    );
});
