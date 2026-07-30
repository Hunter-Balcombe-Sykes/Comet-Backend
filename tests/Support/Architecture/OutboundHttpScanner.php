<?php

namespace Tests\Support\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Source scanner for OutboundHttpGuardTest.
 *
 * Uses token_get_all() rather than regex so comments and doc-blocks are
 * discarded before matching. That distinction is the whole point: the codebase
 * contains `// … Never call Http::get() here.` and a text scanner treats that
 * warning as the very call it forbids.
 */
final class OutboundHttpScanner
{
    /** SafeUrlFetcher and its collaborators define the sanctioned transport. */
    private const EXCLUDED_PREFIX = 'app/Services/Http/';

    /**
     * Every `Http::` facade call site in $appPath, comments excluded.
     *
     * @return array<string, list<int>> relative file path => 1-indexed line numbers
     */
    public static function httpFacadeCallSites(string $appPath): array
    {
        $sites = [];

        foreach (self::phpFiles($appPath) as $relative => $source) {
            $lines = [];

            $tokens = token_get_all($source);
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];

                if (! is_array($token) || ! self::namesHttpFacade($token)) {
                    continue;
                }

                // Only a `::` immediately after makes this a static call rather
                // than a `use Illuminate\Support\Facades\Http;` import or a
                // type-hint mention.
                if (self::nextSignificant($tokens, $i) === '::') {
                    $lines[] = $token[2];
                }
            }

            if ($lines !== []) {
                $sites[$relative] = array_values(array_unique($lines));
            }
        }

        ksort($sites);

        return $sites;
    }

    /**
     * Outbound transports other than the Http facade.
     *
     * @return list<string> "path:line — what was found"
     */
    public static function alternativeTransports(string $appPath): array
    {
        $violations = [];

        foreach (self::phpFiles($appPath) as $relative => $source) {
            $tokens = token_get_all($source);
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];

                if (! is_array($token)) {
                    continue;
                }

                [$id, $text, $line] = [$token[0], $token[1], $token[2]];

                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    continue;
                }

                // ext-curl: no legitimate use here — the Http facade wraps Guzzle already.
                if ($id === T_STRING && str_starts_with(strtolower($text), 'curl_')
                    && self::nextSignificant($tokens, $i) === '(') {
                    $violations[] = "{$relative}:{$line} — {$text}()";

                    continue;
                }

                // Guzzle instantiated directly, bypassing the facade's middleware.
                if (self::isNameToken($id) && str_contains($text, 'GuzzleHttp')) {
                    $violations[] = "{$relative}:{$line} — {$text}";

                    continue;
                }

                // Stream wrappers reading a remote URL. Only a literal http(s)
                // argument is flagged: these functions are used constantly for
                // LOCAL paths, and a heuristic on variable names would make the
                // guard noisy enough to get switched off. A variable-URL read is
                // not caught here — Rule 2 remains the primary control.
                if ($id === T_STRING
                    && in_array(strtolower($text), ['file_get_contents', 'fopen', 'readfile', 'copy'], true)
                    && self::nextSignificant($tokens, $i) === '('
                    && self::firstArgumentIsRemoteUrl($tokens, $i)) {
                    $violations[] = "{$relative}:{$line} — {$text}() on an http(s) URL";
                }
            }
        }

        sort($violations);

        return $violations;
    }

    /**
     * Relative-path => source for every .php file under $appPath, excluding the
     * sanctioned-transport directory.
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
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', str_replace($base, '', $file->getPathname()));

            if (str_starts_with($relative, self::EXCLUDED_PREFIX)) {
                continue;
            }

            $files[$relative] = (string) file_get_contents($file->getPathname());
        }

        return $files;
    }

    /** Does this token name the Http facade — bare, qualified, or fully qualified? */
    private static function namesHttpFacade(array $token): bool
    {
        if (! self::isNameToken($token[0])) {
            return false;
        }

        $name = $token[1];

        return $name === 'Http' || str_ends_with($name, '\\Http');
    }

    private static function isNameToken(int $id): bool
    {
        return $id === T_STRING
            || (defined('T_NAME_QUALIFIED') && $id === T_NAME_QUALIFIED)
            || (defined('T_NAME_FULLY_QUALIFIED') && $id === T_NAME_FULLY_QUALIFIED);
    }

    /**
     * The next token after $i that is not whitespace or a comment, normalised to
     * its literal text ('::', '(', …) or null at end of file.
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

    /** Is the first argument of the call opening after $i a literal http(s) URL? */
    private static function firstArgumentIsRemoteUrl(array $tokens, int $i): bool
    {
        $count = count($tokens);
        $seenParen = false;

        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if ($seenParen) {
                    return $token[0] === T_CONSTANT_ENCAPSED_STRING
                        && preg_match('#^([\'"])https?://#i', $token[1]) === 1;
                }

                return false;
            }

            if ($token === '(') {
                $seenParen = true;

                continue;
            }

            return false;
        }

        return false;
    }
}
