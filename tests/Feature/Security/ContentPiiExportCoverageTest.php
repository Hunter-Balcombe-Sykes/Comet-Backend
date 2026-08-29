<?php

use App\Services\User\DataExport\DataExportPayloadBuilder;
use Tests\Support\SchemaDrift\MigrationColumnReplay;
use Tests\Support\SchemaDrift\SelectStatementParser;

/*
|--------------------------------------------------------------------------
| Content PII Export Coverage (DINT-2)
|--------------------------------------------------------------------------
| Purely static — touches no database, same technique as DataExportCoverageTest's
| T2 (which is why T2 caught the 2026-07-19 streamMedia production breakage
| that a SQLite assertion could not). Asserts that every known content.*
| PII column is either exported or explicitly, reason-documented withheld —
| so a new PII column landing in content.* is a failing build, not a silent
| DSAR gap.
*/

/**
 * Curated (not substring-matched — same reasoning as DataExportCoverageTest's
 * PII_MARKERS docblock) map of table => the columns on it that carry direct
 * PII, either about the account holder or, for f_review, about a third party.
 *
 * @var array<string, list<string>>
 */
const CONTENT_PII_COLUMNS = [
    'content.f_place' => ['venue_name', 'address', 'locality', 'region'],
    // author_uri: slice 6 (migration 20260813110000). This map is CURATED, not
    // discovered — a new PII column is invisible to this guard until it is
    // listed here, so listing it is part of adding it, not a follow-up.
    // staff_name: #W1-PRIV-2 / #W2-DINT-1 (migration 20260828030000). It names
    // the team member a venue-level review was about, which on a storewide
    // source is routinely a COLLEAGUE, not the account holder — so it is PII
    // about a third party as often as about the subject. It is listed here as
    // exported (streamContentFReview selects it) and deliberately NOT in
    // WITHHELD_THIRD_PARTY: the export masks the colleague case row-by-row at
    // runtime, and select-then-mask counts as exported — the guard below fails
    // a column claimed both ways.
    'content.f_review' => ['author_name', 'author_photo_url', 'author_uri', 'text', 'staff_name'],
    'content.f_authored' => ['creator', 'creator_url'],
    'content.f_channel' => ['handle', 'avatar_url'],
    'content.f_text' => ['headline', 'body', 'summary'],
    'content.items' => ['headline_cache'],
    // summary_text: slice 6 (migration 20260813110001). Google-authored prose
    // derived from reviews — same curation caveat as author_uri above, and the
    // reason it is listed in the same commit that adds the table's DSAR
    // section rather than after it.
    'content.source_stats' => ['summary_text'],
];

/**
 * Third-party PII deliberately withheld from the export at the schema layer —
 * mirrors DsarPayloadFilter's withholding of the same class of data from the
 * integrations section. Each entry needs a written reason.
 *
 * @var array<string, array<string, string>>
 */
const WITHHELD_THIRD_PARTY = [
    'content.f_review' => [
        'author_name' => 'Third-party reviewer identity — #PRIV-2, same rule as Google Business reviews in the integrations section.',
        'author_photo_url' => 'Third-party reviewer identity.',
        'author_uri' => 'Third-party reviewer identity — a permanent link to their Google contributor profile, so it identifies them at least as directly as author_name. Slice 6 design spec §2.4.',
        'text' => 'Third-party reviewer\'s verbatim words.',
    ],
    'content.source_stats' => [
        'summary_text' => 'Google-authored prose about the business, derived from third-party reviews — withheld exactly as the legacy `reviewSummary` payload key is (DsarPayloadFilter::THIRD_PARTY_KEYS). rating_avg/rating_count sit beside it and ARE exported: they are business facts about the subject\'s own listing. Slice 6 design spec §5.3/§5.5.',
    ],
];

it('every curated content.* PII table is in COVERED_PII_TABLES', function () {
    $missing = array_values(array_filter(
        array_keys(CONTENT_PII_COLUMNS),
        fn (string $table) => ! in_array($table, DataExportPayloadBuilder::COVERED_PII_TABLES, true),
    ));

    expect($missing)->toBe([], "content.* PII tables missing from COVERED_PII_TABLES:\n  - ".implode("\n  - ", $missing));
});

it('every curated content.* PII table is actually queried by sectionDescriptors()', function () {
    $source = (string) file_get_contents(app_path('Services/User/DataExport/DataExportPayloadBuilder.php'));
    $statements = explode(';', $source);

    $queriedTables = [];
    foreach ($statements as $statement) {
        foreach (SelectStatementParser::tableCallSegments($statement) as [$table, $segment]) {
            $queriedTables[$table] = true;
        }
    }

    $missing = array_values(array_filter(
        array_keys(CONTENT_PII_COLUMNS),
        fn (string $table) => ! isset($queriedTables[$table]),
    ));

    expect($missing)->toBe([], "content.* PII tables not queried by any ->table() call in the builder:\n  - ".implode("\n  - ", $missing));
});

it('every curated content.* PII column is either exported or withheld with a written reason', function () {
    $source = (string) file_get_contents(app_path('Services/User/DataExport/DataExportPayloadBuilder.php'));
    $statements = explode(';', $source);

    // table => list of selected column names (unqualified).
    $selected = [];
    foreach ($statements as $statement) {
        foreach (SelectStatementParser::tableCallSegments($statement) as [$primaryTable, $segment]) {
            if (! preg_match('/->select\(\s*\[(.*?)\]\s*\)/s', $segment, $selectMatch)) {
                continue;
            }

            preg_match_all('/[\'"]([a-zA-Z_][a-zA-Z0-9_.]*)[\'"]/', $selectMatch[1], $colMatches);

            foreach ($colMatches[1] as $col) {
                if (str_contains($col, '.')) {
                    $lastDot = strrpos($col, '.');
                    $tableRef = substr($col, 0, $lastDot);
                    $colName = substr($col, $lastDot + 1);
                } else {
                    $tableRef = $primaryTable;
                    $colName = $col;
                }

                $selected[$tableRef][$colName] = true;
            }
        }
    }

    $violations = [];

    foreach (CONTENT_PII_COLUMNS as $table => $columns) {
        foreach ($columns as $column) {
            $isSelected = isset($selected[$table][$column]);
            $isWithheld = isset(WITHHELD_THIRD_PARTY[$table][$column]);

            if (! $isSelected && ! $isWithheld) {
                $violations[] = "{$table}.{$column} — neither exported nor in WITHHELD_THIRD_PARTY with a reason";
            }

            // A column can't be BOTH exported and withheld — that would mean
            // the withholding is a lie (the data leaks straight back out).
            if ($isSelected && $isWithheld) {
                $violations[] = "{$table}.{$column} — marked WITHHELD_THIRD_PARTY but also appears in a ->select() list";
            }
        }
    }

    expect($violations)->toBe([], "content.* PII columns with no accounted-for export decision:\n  - ".implode("\n  - ", $violations));
});

it('every curated content.* table/column still exists in the real, migration-derived schema (anti-rot)', function () {
    $realSchema = MigrationColumnReplay::tables();
    $violations = [];

    foreach (CONTENT_PII_COLUMNS as $table => $columns) {
        if (! isset($realSchema[$table])) {
            $violations[] = "{$table} — table not found in migration replay";

            continue;
        }

        foreach ($columns as $column) {
            if (! isset($realSchema[$table][$column])) {
                $violations[] = "{$table}.{$column} — not a real column per migration replay";
            }
        }
    }

    expect($violations)->toBe([], "Curated content.* PII columns that have drifted from the real schema:\n  - ".implode("\n  - ", $violations));
});
