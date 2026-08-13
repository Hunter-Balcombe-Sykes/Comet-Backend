<?php

namespace Tests\Support\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Source scanner for CrossFileTestHelperGuardTest.
 *
 * A global function declared inside a Pest test file only exists once PHPUnit
 * has included THAT file. Serially that is invisible — discovery includes every
 * test file before any of them run — so a helper declared in file A and called
 * from file B works fine. Under `--parallel` each paratest worker includes only
 * the files assigned to it, so B lands in a worker that never loaded A and
 * fatals with "Call to undefined function".
 *
 * That is not hypothetical. It cost 16 test failures across MediaPoolFramesTest,
 * MediaSectionReshapeTest and MediaUploadBackfillerTest (poolTenant and family,
 * declared in PoolLaneTest), and slice 5b reintroduced the identical pattern
 * within a day — shopStore/shopProduct declared in ShopPoolPayloadTest and
 * called from ShopWireRetirementTest and PoolWireShapeTest, plus frameAsset
 * declared in MediaPoolFramesTest and called from ShopPoolPayloadTest. Eight
 * more failures. It was caught only because someone happened to be merging
 * behind it, which is not a control.
 *
 * The remedy is always available and needs no allowlist: move the helper into
 * tests/Helpers/ and require_once it from tests/Pest.php, which every worker
 * loads. Hence no ALLOWLIST const here — unlike the other scanners in this
 * directory, there is no legitimate reason to declare a shared helper in a
 * test file.
 *
 * Modelled on RawCacheCallScanner/RedisConnectionPinningScanner (GS-1):
 * token_get_all() has no concept of a line or a string literal, so a helper
 * name merely mentioned in a comment or a string cannot trip it, and a call
 * split across lines cannot evade it. An earlier regex version of this scan
 * reported 52 hazards where the truth was 3 — every extra one a false positive
 * that a grep could not tell from a real call.
 */
final class TestHelperScanner
{
    /**
     * Functions PHPUnit/Pest themselves define per-file, or that are otherwise
     * expected to appear in more than one test file without being a hazard.
     * Pest's own DSL (it/test/expect/beforeEach) is provided by the framework,
     * not by a test file, so it never reaches the declared set anyway — this is
     * only for names a test file genuinely declares.
     */
    private const SCANNED_EXTENSIONS = ['php'];

    /**
     * Every global function declared in a test file that is also called from a
     * DIFFERENT file, excluding declarations in bootstrap-loaded files.
     *
     * @return array<string, array{declaredIn: string, calledBy: list<string>}>
     */
    public static function crossFileHelpers(string $root = 'tests', array $extensions = self::SCANNED_EXTENSIONS): array
    {
        $declared = [];   // fn => list of relative paths declaring it
        $called = [];     // relative path => set of fn names

        foreach (self::sourceFiles($root, $extensions) as $relative => $source) {
            $tokens = token_get_all($source);

            foreach (self::declarations($tokens) as $fn) {
                $declared[$fn][] = $relative;
            }

            $called[$relative] = self::calls($tokens);
        }

        $safe = self::bootstrapLoadedFiles();

        $hazards = [];

        foreach ($declared as $fn => $homes) {
            $homes = array_unique($homes);

            // Declared somewhere every worker loads — reachable from anywhere.
            foreach ($homes as $home) {
                if (self::isPrefixedBy($home, $safe)) {
                    continue 2;
                }
            }

            $callers = [];

            foreach ($called as $file => $names) {
                // A file that declares its own copy — typically behind
                // `if (! function_exists(...))` — is self-sufficient in any
                // worker, so calling the name is not a hazard for it.
                if (! in_array($file, $homes, true) && isset($names[$fn])) {
                    $callers[] = $file;
                }
            }

            if ($callers !== []) {
                sort($callers);
                sort($homes);
                $hazards[$fn] = ['declaredIn' => implode(', ', $homes), 'calledBy' => $callers];
            }
        }

        ksort($hazards);

        return $hazards;
    }

    /**
     * tests/Pest.php is the Pest bootstrap — every worker loads it — so any
     * file it require_once's is equally safe. Derived rather than hardcoded, so
     * adding a require to Pest.php does not need a matching edit here.
     *
     * @return list<string>
     */
    public static function bootstrapLoadedFiles(): array
    {
        $pest = base_path('tests/Pest.php');

        if (! is_file($pest)) {
            return ['tests/Pest.php'];
        }

        preg_match_all(
            "/require(?:_once)?\s+__DIR__\s*\.\s*'([^']+)'/",
            (string) file_get_contents($pest),
            $matches,
        );

        return array_merge(
            ['tests/Pest.php', 'tests/Helpers/'],
            array_map(static fn (string $rel): string => 'tests'.$rel, $matches[1]),
        );
    }

    /**
     * Named functions declared at global scope. Methods are excluded by
     * tracking class/interface/trait/enum bodies; closures and arrow functions
     * never match because the token after `function` is `(`, not a name.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @return list<string>
     */
    private static function declarations(array $tokens): array
    {
        $names = [];
        $depth = 0;
        $classBodyDepths = [];
        $pendingClassBody = false;

        foreach ($tokens as $i => $token) {
            if ($token === '{') {
                $depth++;

                if ($pendingClassBody) {
                    $classBodyDepths[] = $depth;
                    $pendingClassBody = false;
                }

                continue;
            }

            if ($token === '}') {
                if ($classBodyDepths !== [] && end($classBodyDepths) === $depth) {
                    array_pop($classBodyDepths);
                }

                $depth--;

                continue;
            }

            if (! is_array($token)) {
                continue;
            }

            // `Foo::class` is T_DOUBLE_COLON + T_CLASS, not a declaration.
            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $prev = self::previousMeaningful($tokens, $i);

                if (! (is_array($prev) && $prev[0] === T_DOUBLE_COLON)) {
                    $pendingClassBody = true;
                }

                continue;
            }

            if ($token[0] !== T_FUNCTION || $classBodyDepths !== []) {
                continue;
            }

            $next = self::nextMeaningful($tokens, $i, skipAmpersand: true);

            if (is_array($next) && $next[0] === T_STRING) {
                $names[] = $next[1];
            }
        }

        return $names;
    }

    /**
     * Bare function calls — `foo(`. Method calls (`->foo(`, `?->foo(`),
     * static calls (`Foo::foo(`), declarations (`function foo(`) and
     * instantiations (`new Foo(`) are all excluded.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @return array<string, true>
     */
    private static function calls(array $tokens): array
    {
        $names = [];

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            if (self::nextMeaningful($tokens, $i) !== '(') {
                continue;
            }

            $prev = self::previousMeaningful($tokens, $i);

            if (is_array($prev) && in_array($prev[0], [
                T_OBJECT_OPERATOR,
                T_NULLSAFE_OBJECT_OPERATOR,
                T_DOUBLE_COLON,
                T_FUNCTION,
                T_NEW,
                T_ATTRIBUTE,
            ], true)) {
                continue;
            }

            $names[$token[1]] = true;
        }

        return $names;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function nextMeaningful(array $tokens, int $from, bool $skipAmpersand = false): array|string|null
    {
        $count = count($tokens);

        for ($i = $from + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($skipAmpersand && $token === '&') {
                continue;
            }

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function previousMeaningful(array $tokens, int $from): array|string|null
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /** @param list<string> $prefixes */
    private static function isPrefixedBy(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return iterable<string, string> relative path => source */
    private static function sourceFiles(string $root, array $extensions): iterable
    {
        $base = base_path($root);

        if (! is_dir($base)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), $extensions, true)) {
                continue;
            }

            $relative = str_replace(base_path().'/', '', $file->getPathname());

            yield $relative => (string) file_get_contents($file->getPathname());
        }
    }
}
