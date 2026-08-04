<?php

namespace Tests\Support\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Source scanner for CacheKeyspaceConstraintsTest (COV-TAIL-3), token-based
 * mirror of RawCacheCallScanner (GS-1) applied to a different invariant:
 * every Cache write in app/ must carry a real, non-null TTL — see the
 * volatile-lru rationale in CacheKeyspaceConstraintsTest's own docblock.
 *
 * A regex cannot express two of the three offence shapes below: "does this
 * call have fewer than 3 arguments" and "is this specific argument the
 * literal value null" are facts about argument-list STRUCTURE, not about a
 * single line of text. This scanner walks bracket depth explicitly (see
 * splitTopLevelArgs()), so a comma inside a nested array or closure body —
 * IdempotencyKey.php:130's real shape, `Cache::put($key, [...multi-line
 * array...], $ttl)` — is never mistaken for an argument separator. A naive
 * comma count over the raw argument text would miscount that call as having
 * more arguments than it does (or fewer, depending on where it stopped),
 * which is exactly the kind of false positive that gets a working guard
 * suppressed — see nested-array-put.stub.
 *
 * Anchored on the `Cache` facade only, matching GS-1's own bound (stated in
 * CacheKeyspaceConstraintsTest's docblock): `$this->cache->put(...)` on an
 * injected Repository is out of scope.
 */
final class CacheTtlScanner
{
    /** Both real app/ source (.php) and the guard-test fixtures (.stub) are scanned. */
    private const SCANNED_EXTENSIONS = ['php', 'stub'];

    /**
     * Every offending Cache::forever()/rememberForever()/put()/remember()
     * call site under $appPath — the three offence shapes documented on the
     * class.
     *
     * @return array<string, list<string>> relative file path => list of "line — reason"
     */
    public static function offenders(string $appPath): array
    {
        $offenders = [];

        foreach (self::phpFiles($appPath) as $relative => $source) {
            $hits = self::scanSource($source);

            if ($hits !== []) {
                $offenders[$relative] = $hits;
            }
        }

        ksort($offenders);

        return $offenders;
    }

    /**
     * Every Cache::forever()/rememberForever()/put()/remember() call site
     * under $appPath, offender or not — the denominator for
     * SweepGuard::assertDenominator(). A near-empty offenders() list is only
     * meaningful if this population is non-trivial; otherwise the scanner
     * could have stopped finding the `Cache` facade at all and offenders()
     * would be "clean" for the wrong reason.
     *
     * @return list<string> "path:line"
     */
    public static function callSites(string $appPath): array
    {
        $sites = [];

        foreach (self::phpFiles($appPath) as $relative => $source) {
            foreach (self::scanSource($source, includeClean: true) as $line) {
                $sites[] = "{$relative}:{$line}";
            }
        }

        sort($sites);

        return $sites;
    }

    /**
     * @return list<string> offenders() gives "line — reason"; callSites()
     *                      (via includeClean) gives bare line numbers instead — same walk,
     *                      different output shape, so the two can never drift apart on
     *                      which call sites they consider.
     */
    private static function scanSource(string $source, bool $includeClean = false): array
    {
        $hits = [];
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || ! self::namesCacheFacade($token)) {
                continue;
            }

            $afterColons = self::indexAfterDoubleColon($tokens, $i);

            if ($afterColons === null) {
                continue;
            }

            $method = $tokens[$afterColons];

            if (! is_array($method) || $method[0] !== T_STRING) {
                continue;
            }

            $name = $method[1];
            $line = $token[2];

            if ($name === 'forever') {
                $hits[] = $includeClean ? (string) $line : "{$line} — Cache::forever() write has no TTL, is never evicted";

                continue;
            }

            if ($name === 'rememberForever') {
                $hits[] = $includeClean ? (string) $line : "{$line} — Cache::rememberForever() write has no TTL parameter at all, is never evicted";

                continue;
            }

            if ($name === 'put') {
                $openParen = self::indexAfterWhitespace($tokens, $afterColons + 1);

                if ($openParen === null || $tokens[$openParen] !== '(') {
                    continue;
                }

                $args = self::splitTopLevelArgs($tokens, $openParen);

                if ($args === null) {
                    continue; // malformed / never closes in this token stream — not our call to make
                }

                if ($includeClean) {
                    $hits[] = (string) $line;
                } elseif (count($args) < 3) {
                    $hits[] = "{$line} — Cache::put() called with ".count($args).' argument(s), needs a 3rd TTL argument (forwards to forever() otherwise)';
                }

                continue;
            }

            if ($name === 'remember') {
                $openParen = self::indexAfterWhitespace($tokens, $afterColons + 1);

                if ($openParen === null || $tokens[$openParen] !== '(') {
                    continue;
                }

                $args = self::splitTopLevelArgs($tokens, $openParen);

                if ($args === null) {
                    continue;
                }

                if ($includeClean) {
                    $hits[] = (string) $line;
                } elseif (isset($args[1]) && self::isLiteralNull($args[1])) {
                    $hits[] = "{$line} — Cache::remember() called with a literal null TTL argument";
                }
            }
        }

        return $hits;
    }

    /** Does this token name the Cache facade — bare, qualified, or fully qualified? */
    private static function namesCacheFacade(array $token): bool
    {
        if (! self::isNameToken($token[0])) {
            return false;
        }

        $name = $token[1];

        return $name === 'Cache' || str_ends_with($name, '\\Cache');
    }

    private static function isNameToken(int $id): bool
    {
        return $id === T_STRING
            || (defined('T_NAME_QUALIFIED') && $id === T_NAME_QUALIFIED)
            || (defined('T_NAME_FULLY_QUALIFIED') && $id === T_NAME_FULLY_QUALIFIED);
    }

    /**
     * Index of the first significant token AFTER a `::` that immediately
     * (modulo whitespace/comments) follows $i, or null if $i is not followed
     * by `::` at all.
     */
    private static function indexAfterDoubleColon(array $tokens, int $i): ?int
    {
        $count = count($tokens);
        $sawDoubleColon = false;

        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if (! $sawDoubleColon && $token[0] === T_DOUBLE_COLON) {
                    $sawDoubleColon = true;

                    continue;
                }

                if (! $sawDoubleColon) {
                    return null;
                }

                return $j;
            }

            if (! $sawDoubleColon) {
                return null;
            }

            return $j;
        }

        return null;
    }

    /** Index of the first token at/after $i that is not whitespace/comment, or null past end-of-stream. */
    private static function indexAfterWhitespace(array $tokens, int $i): ?int
    {
        $count = count($tokens);

        for ($j = $i; $j < $count; $j++) {
            $token = $tokens[$j];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $j;
        }

        return null;
    }

    /**
     * Depth-aware split of a call's argument list into top-level arguments,
     * starting at the index of its opening `(`. Depth increments on ANY of
     * `(` `[` `{` and decrements on its counterpart — PHP syntax guarantees
     * these are always well-nested, so tracking one shared counter (rather
     * than matching bracket TYPES) is sufficient to tell "a comma that
     * separates two arguments" from "a comma inside a nested array/closure/
     * function-call argument, one level down".
     *
     * @return list<list<array|string>>|null each element is the raw token
     *                                       slice for one argument; null if the call never closes its own
     *                                       parens within this token stream (malformed input — the caller
     *                                       treats that as "nothing to say" rather than guessing).
     */
    private static function splitTopLevelArgs(array $tokens, int $openParenIndex): ?array
    {
        $count = count($tokens);
        $depth = 0;
        $current = [];
        $args = [];

        for ($j = $openParenIndex; $j < $count; $j++) {
            $token = $tokens[$j];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;

                if ($depth === 1 && $j === $openParenIndex) {
                    continue; // the call's own opening paren — not part of any argument
                }

                $current[] = $token;

                continue;
            }

            if ($text === ')' || $text === ']' || $text === '}') {
                $depth--;

                if ($depth === 0) {
                    // the call's own closing paren — flush the trailing argument
                    // (empty-arg-list case: nothing was ever pushed to $current
                    // or $args, so this correctly yields a 0-argument call).
                    if ($current !== [] || $args !== []) {
                        $args[] = $current;
                    }

                    return $args;
                }

                $current[] = $token;

                continue;
            }

            if ($depth === 1 && $text === ',') {
                $args[] = $current;
                $current = [];

                continue;
            }

            $current[] = $token;
        }

        return null;
    }

    /**
     * True when $argTokens — the raw token slice for one argument — is
     * nothing but the literal `null` (whitespace/comments discarded). `null`
     * is lexed as an ordinary T_STRING (a bareword), same as `true`/`false`.
     *
     * @param  list<array|string>  $argTokens
     */
    private static function isLiteralNull(array $argTokens): bool
    {
        $significant = array_values(array_filter(
            $argTokens,
            fn ($token) => ! (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
        ));

        if (count($significant) !== 1) {
            return false;
        }

        $only = $significant[0];

        return is_array($only) && $only[0] === T_STRING && strtolower($only[1]) === 'null';
    }

    /**
     * Relative-path => source for every scanned file under $appPath. Mirrors
     * RawCacheCallScanner::phpFiles() exactly.
     *
     * @return array<string, string>
     */
    private static function phpFiles(string $appPath): array
    {
        $base = dirname($appPath).DIRECTORY_SEPARATOR;
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appPath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), self::SCANNED_EXTENSIONS, true)) {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', str_replace($base, '', $file->getPathname()));

            $files[$relative] = (string) file_get_contents($file->getPathname());
        }

        return $files;
    }
}
