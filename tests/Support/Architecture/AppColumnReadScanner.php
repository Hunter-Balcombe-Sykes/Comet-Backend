<?php

namespace Tests\Support\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Reads app/ source and reports, per DB::table()/->table() chain, which
 * columns are read from which table — the app-code half of the PG-lane DDL
 * drift guard (PostgresLaneDdlScanner reads the DDL half: the stand-in
 * tables and the real migrations).
 *
 * Deliberately conservative: an ambiguous attribution — an alias bound to
 * two different tables in the same chain, an unqualified column on a
 * multi-table chain — is DROPPED rather than guessed. A missed catch costs
 * only what we already pay; a false alarm trains people to ignore the guard.
 */
final class AppColumnReadScanner
{
    /** Anchors a chain: the call whose table becomes the chain's primary table. */
    private const CHAIN_START = '/(?:DB::table|->table)\(\s*\'([^\']*)\'/';

    /** Every call inside a chain that names a table, chain-start included. */
    private const TABLE_INTRODUCING = '/(?:DB::table|->(?:table|from|join|leftJoin|rightJoin|joinSub))\(\s*\'([^\']*)\'/';

    /** Unqualified column arguments are only trusted from these calls, and only on a single-table chain. */
    private const BARE_COLUMN_METHODS = ['where', 'orWhere', 'whereNull', 'whereNotNull', 'whereIn', 'whereNotIn', 'select', 'addSelect', 'orderBy', 'groupBy', 'value', 'pluck', 'increment', 'decrement'];

    /** Array-key columns are only trusted from these calls, same single-table condition. */
    private const WRITE_METHODS = ['update', 'insert', 'insertOrIgnore', 'upsert'];

    /**
     * @return array<string, list<string>> "schema.table" => column names, for one file's source
     */
    public static function scanSource(string $php): array
    {
        $clean = self::stripCommentsAndDoubleQuoted($php);

        $refs = [];

        foreach (self::chainSpans($clean) as $chain) {
            foreach (self::scanChain($chain) as $table => $columns) {
                $refs[$table] = array_values(array_unique(array_merge($refs[$table] ?? [], $columns)));
            }
        }

        foreach ($refs as $table => $columns) {
            sort($refs[$table]);
        }

        ksort($refs);

        return $refs;
    }

    /**
     * @return array<string, array<string, list<string>>> fqcn => table => columns
     */
    public static function scanTree(string $appDir): array
    {
        $results = [];
        $base = rtrim($appDir, '/').'/';

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files); // deterministic traversal — this lane runs under --parallel

        foreach ($files as $path) {
            $source = (string) file_get_contents($path);
            $relative = str_replace($base, '', $path);

            $refs = self::scanSource($source);

            if ($refs !== []) {
                $results[self::fqcnOf($source, $relative)] = $refs;
            }
        }

        return $results;
    }

    /** Namespace + first class/interface/trait/enum declaration; falls back to the filename. */
    public static function fqcnOf(string $php, string $path): string
    {
        $clean = self::stripCommentsAndDoubleQuoted($php);

        $namespace = '';
        if (preg_match('/\bnamespace\s+([A-Za-z0-9_\\\\]+)\s*[;{]/', $clean, $m) === 1) {
            $namespace = $m[1];
        }

        if (preg_match('/\b(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/', $clean, $m) === 1) {
            $name = $m[1];
        } else {
            $name = pathinfo($path, PATHINFO_FILENAME);
        }

        return $namespace === '' ? $name : $namespace.'\\'.$name;
    }

    /**
     * Every DB::table()/->table() chain in $clean, as raw chain text.
     *
     * @return list<string>
     */
    private static function chainSpans(string $clean): array
    {
        $spans = [];

        if (preg_match_all(self::CHAIN_START, $clean, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return $spans;
        }

        foreach ($matches[0] as $match) {
            $start = $match[1];
            $end = self::chainEnd($clean, $start);
            $spans[] = substr($clean, $start, $end - $start);
        }

        return $spans;
    }

    /**
     * Scans forward from a chain start tracking paren depth (skipping single-
     * quoted literals) and stops at the first `;` at depth 0, or the first
     * point depth goes negative — whichever comes first. The negative-depth
     * case is a chain that began inside an enclosing call (`if (DB::table(…)
     * ->exists())`): without it, depth never returns to 0 and the scan runs
     * on through the rest of the file (a real regression once ran 17499
     * chars past the statement).
     */
    private static function chainEnd(string $clean, int $start): int
    {
        $depth = 0;
        $length = strlen($clean);

        for ($i = $start; $i < $length; $i++) {
            $char = $clean[$i];

            if ($char === "'") {
                $i = self::skipSingleQuoted($clean, $i);

                continue;
            }

            if ($char === '(') {
                $depth++;

                continue;
            }

            if ($char === ')') {
                $depth--;

                if ($depth < 0) {
                    return $i;
                }

                continue;
            }

            if ($char === ';' && $depth === 0) {
                return $i;
            }
        }

        return $length;
    }

    /**
     * Builds this chain's table set and alias map from the primary table plus
     * every ->from(/->join(/->leftJoin(/->rightJoin(/->joinSub(/->table( call
     * inside it, and returns the chain text with each such table-naming
     * literal blanked out — otherwise a bare `'schema.table'` argument (two
     * dot-segments, same shape as `alias.column`) gets re-read as a column
     * reference to itself.
     *
     * @return array{0: list<string>, 1: array<string, string|false>, 2: string}
     */
    private static function tableDeclarations(string $chainText): array
    {
        $tableSet = [];
        $aliasMap = [];
        $masked = $chainText;

        if (preg_match_all(self::TABLE_INTRODUCING, $chainText, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [$tableSet, $aliasMap, $masked];
        }

        foreach ($matches[1] as [$content, $offset]) {
            [$table, $alias] = self::parseTableClause($content);

            if (! in_array($table, $tableSet, true)) {
                $tableSet[] = $table;
            }

            if ($alias !== null) {
                if (! array_key_exists($alias, $aliasMap)) {
                    $aliasMap[$alias] = $table;
                } elseif ($aliasMap[$alias] !== $table) {
                    $aliasMap[$alias] = false; // same alias, two different tables — poisoned
                }
            }

            $maskStart = $offset - 1; // include the opening quote
            $maskLength = strlen($content) + 2; // both quotes
            $masked = substr_replace($masked, str_repeat(' ', $maskLength), $maskStart, $maskLength);
        }

        return [$tableSet, $aliasMap, $masked];
    }

    /** @return array{0: string, 1: string|null} */
    private static function parseTableClause(string $content): array
    {
        if (preg_match('/^(.*?)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)\s*$/i', $content, $m) === 1) {
            return [trim($m[1]), $m[2]];
        }

        return [trim($content), null];
    }

    /**
     * Columns attributed within one chain.
     *
     * @return array<string, list<string>>
     */
    private static function scanChain(string $chainText): array
    {
        [$tableSet, $aliasMap, $masked] = self::tableDeclarations($chainText);

        if ($tableSet === []) {
            return [];
        }

        $refs = self::scanDottedLiterals($masked, $tableSet, $aliasMap);

        // Unqualified columns are only unambiguous when there is exactly one
        // table in the chain to attribute them to — a join makes them a guess.
        if (count($tableSet) === 1) {
            $primary = $tableSet[0];

            foreach (self::callArgumentSpans($masked, self::BARE_COLUMN_METHODS) as $span) {
                foreach (self::bareColumnsIn($span) as $column) {
                    $refs[$primary][] = $column;
                }
            }

            foreach (self::callArgumentSpans($masked, self::WRITE_METHODS) as $span) {
                foreach (self::writeKeysIn($span) as $column) {
                    $refs[$primary][] = $column;
                }
            }
        }

        foreach ($refs as $table => $columns) {
            $refs[$table] = array_values(array_unique($columns));
        }

        return $refs;
    }

    /**
     * `'alias.column'` and `'schema.table.column'` literals, matched wherever
     * they appear in the chain (not gated to specific calls, unlike the bare-
     * column and write-key rules below) — an alias or schema-qualified
     * reference is unambiguous by construction, so there is nothing to guess.
     *
     * @param  list<string>  $tableSet
     * @param  array<string, string|false>  $aliasMap
     * @return array<string, list<string>>
     */
    private static function scanDottedLiterals(string $masked, array $tableSet, array $aliasMap): array
    {
        $refs = [];

        foreach (self::singleQuotedLiterals($masked) as [, $content]) {
            $parts = explode('.', $content);

            if (count($parts) === 2) {
                $alias = $parts[0];

                if (! array_key_exists($alias, $aliasMap) || $aliasMap[$alias] === false) {
                    continue;
                }

                $column = self::normaliseColumn($parts[1]);

                if ($column !== null) {
                    $refs[$aliasMap[$alias]][] = $column;
                }

                continue;
            }

            if (count($parts) === 3) {
                $table = $parts[0].'.'.$parts[1];

                if (! in_array($table, $tableSet, true)) {
                    continue;
                }

                $column = self::normaliseColumn($parts[2]);

                if ($column !== null) {
                    $refs[$table][] = $column;
                }
            }
        }

        return $refs;
    }

    /** @return list<string> unqualified column names among the single-quoted literals in $argSpan */
    private static function bareColumnsIn(string $argSpan): array
    {
        $columns = [];

        foreach (self::singleQuotedLiterals($argSpan) as [, $content]) {
            if (str_contains($content, '.')) {
                continue;
            }

            $column = self::normaliseColumn($content);

            if ($column !== null) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /** @return list<string> unqualified `'column' =>` array keys in $argSpan */
    private static function writeKeysIn(string $argSpan): array
    {
        $columns = [];

        if (preg_match_all('/\'([^\']*)\'\s*=>/', $argSpan, $matches) > 0) {
            foreach ($matches[1] as $content) {
                if (str_contains($content, '.')) {
                    continue;
                }

                $column = self::normaliseColumn($content);

                if ($column !== null) {
                    $columns[] = $column;
                }
            }
        }

        return $columns;
    }

    /**
     * Strips a JSON arrow path down to its column (`display_settings->
     * auto_sync_latest` contributes `display_settings` only), then rejects
     * anything left that does not look like a plain identifier — the
     * conservative default for a shape this scanner does not recognise.
     */
    private static function normaliseColumn(string $raw): ?string
    {
        $arrow = strpos($raw, '->');
        $column = $arrow === false ? $raw : substr($raw, 0, $arrow);

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) === 1 ? $column : null;
    }

    /**
     * Every `->method(` call among $methods in $text, as its balanced-paren
     * argument text.
     *
     * @param  list<string>  $methods
     * @return list<string>
     */
    private static function callArgumentSpans(string $text, array $methods): array
    {
        $pattern = '/->('.implode('|', array_map('preg_quote', $methods)).')\(/';

        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $spans = [];

        foreach ($matches[0] as [$full, $matchStart]) {
            $openParen = $matchStart + strlen($full) - 1;
            $close = self::matchingParen($text, $openParen);

            if ($close === null) {
                continue;
            }

            $spans[] = substr($text, $openParen + 1, $close - $openParen - 1);
        }

        return $spans;
    }

    /** Index of the paren matching the one at $open, skipping single-quoted contents; null if it never closes. */
    private static function matchingParen(string $text, int $open): ?int
    {
        $depth = 0;
        $length = strlen($text);

        for ($i = $open; $i < $length; $i++) {
            $char = $text[$i];

            if ($char === "'") {
                $i = self::skipSingleQuoted($text, $i);

                continue;
            }

            if ($char === '(') {
                $depth++;

                continue;
            }

            if ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** @return list<array{0: int, 1: string}> [position, content] for every single-quoted literal in $text */
    private static function singleQuotedLiterals(string $text): array
    {
        $literals = [];
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            if ($text[$i] !== "'") {
                continue;
            }

            $end = self::skipSingleQuoted($text, $i);
            $literals[] = [$i, substr($text, $i + 1, $end - $i - 1)];
            $i = $end;
        }

        return $literals;
    }

    /**
     * Comments and double-quoted string bodies are removed entirely — an
     * apostrophe inside a comment ("the mapper's per-platform pass") would
     * otherwise open a phantom single-quoted literal for every scan below it.
     */
    private static function stripCommentsAndDoubleQuoted(string $php): string
    {
        $length = strlen($php);
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $php[$i];
            $next = $i + 1 < $length ? $php[$i + 1] : '';

            if ($char === '/' && $next === '/') {
                $i = self::indexOfLineEnd($php, $i) - 1;

                continue;
            }

            if ($char === '#' && $next !== '[') { // #[Attribute] is not a comment
                $i = self::indexOfLineEnd($php, $i) - 1;

                continue;
            }

            if ($char === '/' && $next === '*') {
                $close = strpos($php, '*/', $i + 2);
                $i = $close === false ? $length - 1 : $close + 1;

                continue;
            }

            if ($char === "'") {
                $end = self::skipSingleQuoted($php, $i);
                $out .= substr($php, $i, $end - $i + 1);
                $i = $end;

                continue;
            }

            if ($char === '"') {
                $i = self::skipDoubleQuoted($php, $i);

                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    private static function indexOfLineEnd(string $php, int $from): int
    {
        $pos = strpos($php, "\n", $from);

        return $pos === false ? strlen($php) : $pos;
    }

    /** Index of the closing quote for the single-quoted literal opening at $quoteIndex. `''` inside is an escaped quote. */
    private static function skipSingleQuoted(string $text, int $quoteIndex): int
    {
        $length = strlen($text);
        $i = $quoteIndex + 1;

        while ($i < $length) {
            if ($text[$i] === '\\') {
                $i += 2;

                continue;
            }

            if ($text[$i] === "'") {
                if ($i + 1 < $length && $text[$i + 1] === "'") {
                    $i += 2;

                    continue;
                }

                return $i;
            }

            $i++;
        }

        return $length - 1;
    }

    /** Index of the closing quote for the double-quoted literal opening at $quoteIndex. */
    private static function skipDoubleQuoted(string $php, int $quoteIndex): int
    {
        $length = strlen($php);
        $i = $quoteIndex + 1;

        while ($i < $length) {
            if ($php[$i] === '\\') {
                $i += 2;

                continue;
            }

            if ($php[$i] === '"') {
                return $i;
            }

            $i++;
        }

        return $length - 1;
    }
}
