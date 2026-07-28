<?php

namespace Tests\Support\SchemaDrift;

/**
 * Splits a PHP statement into one segment per ->table() call, each running
 * from that call to the start of the next ->table() call in the same
 * statement (or to the end of the statement). Used by DataExportCoverageTest's
 * T1/T2 guards and by ContentPiiExportCoverageTest (DINT-2) — moved out of
 * DataExportCoverageTest.php for the same reason MigrationColumnReplay was
 * moved: a function declared inside a Pest test file only exists once PHPUnit
 * has included that file, and include order is not guaranteed under --filter
 * or --parallel, so a different test file could run in a worker where
 * DataExportCoverageTest.php was never loaded and fatal on this function name.
 * PSR-4 autoloading here removes that ordering dependency. PURE MOVE — no
 * parsing logic changed.
 *
 * Known limit: segmentation is purely offset-ordered, so a table whose own
 * ->select() sits textually AFTER a nested ->table() (e.g. an outer query
 * whose where(function () { ... ->table(...) ... }) closure precedes its
 * ->select()) would attribute that ->select() to the nested table's segment.
 * No such shape exists in DataExportPayloadBuilder today — the one closure
 * there (streamEmailSubscriptions) contains no nested ->table() and the outer
 * ->select() precedes it. Revisit if that pattern is ever introduced.
 */
class SelectStatementParser
{
    /**
     * @return array<int, array{0: string, 1: string}> list of [table, segment]
     */
    public static function tableCallSegments(string $statement): array
    {
        if (! preg_match_all(
            '/->table\(\s*[\'"]([a-zA-Z_]+\.[a-zA-Z_]+)[\'"]\s*\)/',
            $statement,
            $matches,
            PREG_OFFSET_CAPTURE
        )) {
            return [];
        }

        $segments = [];
        $count = count($matches[0]);

        for ($i = 0; $i < $count; $i++) {
            $table = $matches[1][$i][0];
            $start = $matches[0][$i][1];
            $end = $i + 1 < $count ? $matches[0][$i + 1][1] : strlen($statement);
            $segments[] = [$table, substr($statement, $start, $end - $start)];
        }

        return $segments;
    }
}
