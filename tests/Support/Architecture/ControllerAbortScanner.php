<?php

namespace Tests\Support\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Source scanner for InlineAuthBypassGuardTest.
 *
 * Uses token_get_all() rather than the old two-stage grep (ci.yml, before
 * COV-GUARD-2) so a call wrapped across lines cannot evade detection — there
 * is no "line" in a token stream. The old `grep -rE ... | grep 403` pipeline
 * caught `abort_unless($a === $b, 403, "msg")` on one line but missed the
 * identical call spread across four, which is exactly what Pint produces once
 * the message argument is long enough to wrap.
 *
 * Paren-depth tracking also prevents the inverse mistake: a 403 nested inside
 * an argument (e.g. `abort_unless($x, 404, foo(403))`) is not a top-level
 * status-code argument and must not be flagged. See
 * PublicDocumentDownloadController, which has wrapped abort_unless(...) calls
 * today that are legitimately 404s.
 */
final class ControllerAbortScanner
{
    private const FUNCTIONS = ['abort', 'abort_if', 'abort_unless'];

    /** Both real controllers (.php) and the guard-test fixtures (.stub) are scanned. */
    private const SCANNED_EXTENSIONS = ['php', 'stub'];

    /**
     * Every abort()/abort_if()/abort_unless() call in $dir that carries a
     * top-level 403 argument.
     *
     * @return list<string> "path:line — fn(…, 403, …)"
     */
    public static function inlineForbiddenAborts(string $dir): array
    {
        $hits = [];

        foreach (self::phpFiles($dir) as $relative => $source) {
            $tokens = token_get_all($source);
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];

                if (! is_array($token) || $token[0] !== T_STRING
                    || ! in_array(strtolower($token[1]), self::FUNCTIONS, true)) {
                    continue;
                }

                // Only a `(` immediately after makes this a call rather than,
                // say, a string mentioning the function name.
                if (self::nextSignificant($tokens, $i) !== '(') {
                    continue;
                }

                if (self::callHasTopLevel403($tokens, $i)) {
                    $hits[] = "{$relative}:{$token[2]} — {$token[1]}(…, 403, …)";
                }
            }
        }

        sort($hits);

        return $hits;
    }

    /**
     * Does the call opening after $nameIndex carry 403 as a standalone
     * top-level argument (not nested inside another call, not part of a
     * larger expression like `$x !== 403`)?
     */
    private static function callHasTopLevel403(array $tokens, int $nameIndex): bool
    {
        $count = count($tokens);
        $openIdx = null;

        for ($j = $nameIndex + 1; $j < $count; $j++) {
            $token = $tokens[$j];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                break;
            }

            if ($token === '(') {
                $openIdx = $j;
            }

            break;
        }

        if ($openIdx === null) {
            return false;
        }

        $depth = 1;
        // The token immediately preceding the argument under inspection —
        // starts as '(' because that is what opens the first argument.
        $prevSignificant = '(';

        for ($j = $openIdx + 1; $j < $count; $j++) {
            $token = $tokens[$j];

            if (is_array($token)) {
                [$id, $text] = [$token[0], $token[1]];

                if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                // Standalone: depth 1 (not nested in another call), the
                // previous significant token opened this argument (`(` or
                // `,`), and — checked below — the next one closes it.
                if ($depth === 1 && $id === T_LNUMBER && $text === '403'
                    && in_array($prevSignificant, ['(', ','], true)) {
                    $next = self::nextSignificant($tokens, $j);

                    if ($next === ',' || $next === ')') {
                        return true;
                    }
                }

                $prevSignificant = $text;

                continue;
            }

            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;

                if ($depth === 0) {
                    return false;
                }
            }

            $prevSignificant = $token;
        }

        return false;
    }

    /**
     * Relative-path => source for every scanned file under $dir.
     *
     * Relative to $dir itself (not its parent) so the same scanner reads
     * naturally whether pointed at app/Http/Controllers or a fixtures
     * directory of loose .stub files.
     *
     * @return array<string, string>
     */
    private static function phpFiles(string $dir): array
    {
        $base = rtrim($dir, '/').'/';
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
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

    /**
     * The next token after $i that is not whitespace or a comment, normalised
     * to its literal text ('(', ',', …) or null at end of file.
     */
    private static function nextSignificant(array $tokens, int $i): ?string
    {
        $count = count($tokens);

        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                return $token[1];
            }

            return $token;
        }

        return null;
    }
}
