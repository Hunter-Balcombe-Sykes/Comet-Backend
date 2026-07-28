<?php

// Guards the two ways a single test file can abort the WHOLE run rather than
// just fail itself. Both bit on 2026-07-28: neither produced a summary line, so
// CI reported "no tests ran" instead of a failure anyone could read.

/** Test files that legitimately need a process-global ini_set. Empty by design. */
const PROCESS_GLOBAL_INI_ALLOWLIST = [];

/**
 * Pest test files carry no namespace, so a file-scope `const`/`function` lands in
 * the GLOBAL symbol table shared by every test file loaded into the process. Two
 * files declaring the same name collide: a const redeclaration is a warning that
 * Laravel's handler promotes to ErrorException (fatal under --parallel, where the
 * files land in one worker mid-run), and a function redeclaration is an outright
 * fatal. Neither is caught by running the offending file on its own.
 */
it('declares no global test symbol in more than one file', function () {
    $consts = [];
    $functions = [];

    foreach (testSuiteHygieneFiles() as $path) {
        foreach (file($path) as $line) {
            if (preg_match('/^const\s+([A-Za-z_]\w*)/', $line, $m)) {
                $consts[$m[1]][] = $path;
            }
            if (preg_match('/^function\s+([A-Za-z_]\w*)/', $line, $m)) {
                $functions[$m[1]][] = $path;
            }
        }
    }

    $collisions = collect($consts)->merge($functions)
        ->filter(fn (array $files) => count($files) > 1)
        ->map(fn (array $files) => array_map('testSuiteHygieneRelative', $files));

    expect($collisions->all())->toBe([]);
});

/**
 * ini_set is process-global and PHPUnit does not restore it between tests, so a
 * test that raises memory_limit raises it for every test that runs after it in
 * the same process — silently disabling the cap for an arbitrary, ordering-
 * dependent slice of the suite. Set the limit in phpunit.xml instead.
 */
it('sets no process-global ini from inside a test', function () {
    $offenders = collect(testSuiteHygieneFiles())
        ->filter(fn (string $path) => preg_match('/ini_set\s*\(\s*[\'"]memory_limit/', (string) file_get_contents($path)))
        ->map('testSuiteHygieneRelative')
        ->reject(fn (string $rel) => in_array($rel, PROCESS_GLOBAL_INI_ALLOWLIST, true));

    expect($offenders->values()->all())->toBe([]);
});

/** The cap must clear the observed high-water mark; see the note in phpunit.xml. */
it('keeps a memory_limit with headroom over the suite high-water mark', function () {
    preg_match('/<ini name="memory_limit" value="([^"]+)"/', (string) file_get_contents(base_path('phpunit.xml')), $m);

    expect($m[1] ?? null)->not->toBeNull();

    $bytes = (int) $m[1] * match (strtoupper(substr($m[1], -1))) {
        'G' => 1024 ** 3,
        'M' => 1024 ** 2,
        'K' => 1024,
        default => 1,
    };

    // A serial run peaked at ~870 MB on 2026-07-28. Anything at or under that
    // aborts the run mid-Feature with no summary line.
    expect($bytes)->toBeGreaterThan(1024 ** 3);
});

/** @return list<string> every PHP file under tests/, all lanes included. */
function testSuiteHygieneFiles(): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('tests')));

    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

function testSuiteHygieneRelative(string $path): string
{
    return ltrim(str_replace(base_path(), '', $path), '/');
}
