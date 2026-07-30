<?php

namespace Tests\Support\SchemaDrift;

use ReflectionFunction;

/**
 * The single source of truth for "what tables does the shared SQLite stand-in
 * build" — the same jurisdiction SchemaDriftGuardTest already uses to decide
 * which setup*Table() helpers to run (see that file's discovery loop, ~line
 * 32): a no-arg global function named setup* and physically declared in
 * tests/Pest.php. A file-local `setup*` helper inside some other test is a
 * fixture for ONE test, not a claim about the shared schema, so it is
 * deliberately excluded here exactly as it is there.
 *
 * Consumed by NoLocalCanonicalTableDdlTest to widen its canonical-table list
 * from a hardcoded 13 names to every table the shared stand-in actually
 * builds (measured: 6 → 31 files caught, zero new false positives) — so the
 * two guards cannot drift apart about what "canonical" means.
 */
final class PestSetupHelpers
{
    /**
     * No-arg global functions named setup* and declared in tests/Pest.php.
     *
     * Returned in DECLARATION order, deliberately un-sorted: SchemaDriftGuardTest
     * CALLS these, and sorting would silently change the order 59 helpers run in.
     * No helper currently ALTERs a table another one CREATEs (verified across all
     * 59), so order happens not to matter today — but that is a property of the
     * fixtures, not a guarantee, and it is not this class's to quietly change.
     * Callers wanting a stable order sort their own output; tables() ksort()s.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = [];

        foreach (get_defined_functions()['user'] as $fn) {
            $ref = new ReflectionFunction($fn);
            if ($ref->getFileName() !== base_path('tests/Pest.php')) {
                continue;
            }
            $short = str_contains($fn, '\\') ? substr($fn, strrpos($fn, '\\') + 1) : $fn;
            if (str_starts_with($short, 'setup') && $ref->getNumberOfRequiredParameters() === 0) {
                $names[] = $fn;
            }
        }

        return $names;
    }

    /**
     * Every schema.table a setup*() helper CREATE TABLEs, mapped back to the
     * helper that builds it — e.g. 'site.menu_items' => 'setupSitesTable'.
     *
     * Reads each helper's own source (getStartLine()..getEndLine()) rather
     * than the whole file, so a table built by one helper is never blamed on
     * another. The DDL keywords are assembled from parts so this file's own
     * source can never satisfy the pattern it is scanning for.
     *
     * @return array<string, string>
     */
    public static function tables(): array
    {
        $pattern = '/'.'CREATE'.'\s+'.'TABLE'.'\s+(?:IF\s+NOT\s+EXISTS\s+)?'
            .'"?([a-z_]+)"?\s*\.\s*"?([a-z_]+)"?/i';

        $tables = [];

        foreach (self::names() as $fn) {
            $ref = new ReflectionFunction($fn);
            $file = $ref->getFileName();
            $start = $ref->getStartLine();
            $end = $ref->getEndLine();
            if ($file === false || $start === false || $end === false) {
                continue;
            }

            $lines = file($file);
            if ($lines === false) {
                continue;
            }
            $source = implode('', array_slice($lines, $start - 1, $end - $start + 1));

            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $table = strtolower($match[1]).'.'.strtolower($match[2]);
                    $tables[$table] = $fn;
                }
            }
        }

        ksort($tables);

        return $tables;
    }
}
