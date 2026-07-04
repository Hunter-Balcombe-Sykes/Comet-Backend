<?php

// Architecture guard for the audit pipeline (scripts/audit/). The pipeline has
// two ways of quietly auditing the wrong thing as the codebase evolves — this
// test fails on both so CI catches them, not a human reading a green audit.
//
//   PROBLEM 1 — scope-map holes. `codebase_chunks()` in scripts/audit/audit.sh
//   is a HAND-MAINTAINED list of which directories each lens scans in `--codebase`
//   sweep mode. A lens only audits what its map feeds it. Two drift modes:
//     (a) a mapped path is renamed/removed -> the map points at a dead path
//         (silent: the scanner finds zero files and emits no error);
//     (b) a new feature namespace (e.g. app/Services/Billing, a new
//         app/Http/Controllers/Api/* or tests/Feature/* dir) is added but never
//         wired into any chunk -> it gets ZERO sweep coverage.
//   The scanner recurses (`find <dir>`), so a NEW FILE inside an already-mapped
//   dir is covered automatically; only NEW DIRECTORIES need wiring — hence the
//   watched parents below are the places new namespaces appear.
//
//   PROBLEM 2 — lens freshness. Lenses (and system-prompt.md / adjudicate-prompt.md)
//   encode the architecture as-written. After a shift (skeleton system, SmartLinks
//   removal) a stale lens audits code that no longer exists. The mechanically
//   checkable slice: every file path a lens names in prose must still exist. This
//   catches the "prose points at a deleted class/dir" class of bug. It does NOT
//   catch stale CONCEPTS (a DB column that was renamed, a class whose behaviour
//   changed) — that still needs the human refresh documented in CLAUDE.md "Audits".
//
// When this test fails, the fix is usually a one-line edit to codebase_chunks()
// (+ the lens's .md scope-group), or a corrected/removed prose reference. If a
// directory genuinely should never be swept, add a justified entry to
// $coverageExempt.

/**
 * Every path referenced by codebase_chunks() in audit.sh.
 * Scope-map lines look like: chunk-name|path1 path2 path3  (inside heredocs).
 *
 * @return list<string>
 */
function auditScopePaths(): array
{
    $src = (string) file_get_contents(base_path('scripts/audit/audit.sh'));

    // Isolate the function body so we never match a '|' from shell elsewhere.
    if (preg_match('/^codebase_chunks\(\)\s*\{(.*?)^\}/ms', $src, $m) !== 1) {
        throw new RuntimeException('Could not locate codebase_chunks() in scripts/audit/audit.sh');
    }

    $paths = [];
    foreach (preg_split('/\R/', $m[1]) as $line) {
        // A map line: lowercase chunk name, a pipe, then space-separated paths.
        if (preg_match('/^[a-z0-9-]+\|(.+)$/', trim($line), $mm) !== 1) {
            continue;
        }
        foreach (preg_split('/\s+/', trim($mm[1])) as $p) {
            if ($p !== '') {
                $paths[$p] = true;
            }
        }
    }

    return array_keys($paths);
}

/**
 * File-path references made in lens/prompt prose, mapped to the files that make
 * them. Only tokens that look like real repo paths (start with a known root,
 * no globs/placeholders/calls) are returned.
 *
 * @return array<string, list<string>>
 */
function auditPromptPathRefs(): array
{
    $files = glob(base_path('scripts/audit/lenses/*.md')) ?: [];
    $files[] = base_path('scripts/audit/system-prompt.md');
    $files[] = base_path('scripts/audit/adjudicate-prompt.md');

    $roots = ['app', 'tests', 'config', 'database', 'routes', 'supabase', 'cloudflare-worker', 'bootstrap', 'deploy', '.github'];
    $refs = [];

    foreach ($files as $file) {
        if (! is_file($file)) {
            continue;
        }
        $src = (string) file_get_contents($file);

        // Strip ``` fenced blocks before inline-backtick matching — otherwise
        // fence backticks desync the `...` pairing. Then collect tokens from two
        // sources: inline `path` refs in prose, and `--scope <path>` lines that
        // live inside those fenced scope-group blocks (dead ones = stale too).
        $prose = (string) preg_replace('/```.*?```/s', '', $src);
        preg_match_all('/`([^`]+)`/', $prose, $inline);
        preg_match_all('/^\s*--scope\s+(\S+)/m', $src, $scoped);
        $tokens = array_merge($inline[1], $scoped[1]);

        foreach ($tokens as $tok) {
            $isPath = false;
            foreach ($roots as $r) {
                if (str_starts_with($tok, $r.'/')) {
                    $isPath = true;
                    break;
                }
            }
            if (! $isPath) {
                continue;
            }
            // Reject globs, placeholders (<domain>), calls, braces, whitespace.
            if (preg_match('/[*(){}$<>\s]/', $tok) === 1 || str_contains($tok, '::')) {
                continue;
            }
            $clean = rtrim($tok, '/');
            $clean = rtrim($clean, '.,;:');
            if ($clean === '') {
                continue;
            }
            $refs[$clean][str_replace(base_path().'/', '', $file)] = true;
        }
    }

    return array_map(fn (array $v): array => array_keys($v), $refs);
}

it('audit scope maps reference no dead paths', function () {
    $dead = array_values(array_filter(
        auditScopePaths(),
        fn (string $p): bool => ! file_exists(base_path($p)),
    ));
    sort($dead);

    expect($dead)->toBeEmpty(
        'codebase_chunks() in scripts/audit/audit.sh references paths that no longer exist '
        ."(the lens scans nothing for them). Remove/rename each, and the matching lens .md scope-group:\n - "
        .implode("\n - ", $dead),
    );
});

it('every feature namespace is covered by an audit scope map', function () {
    // Where new feature namespaces appear. A new DIRECTORY here that no chunk
    // references (directly or via an ancestor) gets zero sweep coverage.
    $watchedParents = [
        'app/Services',
        'app/Http/Controllers/Api',
        'app/Jobs',
        'tests/Feature',
    ];

    // Directories deliberately excluded from sweep coverage.
    // Key = repo-relative path, value = written justification.
    $coverageExempt = [
        // 'app/Services/Foo' => 'why it should never be swept',
    ];

    $covered = array_flip(auditScopePaths());

    // A dir is covered if it or any ancestor is a scope-map path (the scanner
    // recurses, so an ancestor mapping covers all descendants).
    $isCovered = function (string $dir) use ($covered): bool {
        $parts = explode('/', $dir);
        while ($parts !== []) {
            if (isset($covered[implode('/', $parts)])) {
                return true;
            }
            array_pop($parts);
        }

        return false;
    };

    $uncovered = [];
    foreach ($watchedParents as $parent) {
        foreach (glob(base_path($parent).'/*', GLOB_ONLYDIR) ?: [] as $abs) {
            $child = str_replace('\\', '/', str_replace(base_path().'/', '', $abs));
            if (isset($coverageExempt[$child]) || $isCovered($child)) {
                continue;
            }
            $uncovered[] = $child;
        }
    }
    sort($uncovered);

    expect($uncovered)->toBeEmpty(
        'These directories get zero audit sweep coverage — add each to the right lens chunk in '
        .'codebase_chunks() (scripts/audit/audit.sh) + its .md scope-group, or add a justified '
        ."\$coverageExempt entry:\n - ".implode("\n - ", $uncovered),
    );
});

it('audit lenses reference no stale file paths', function () {
    $stale = [];
    foreach (auditPromptPathRefs() as $path => $files) {
        // Accept the path itself OR path.php — prose often names a class by its
        // path without the extension (e.g. app/Services/Http/SafeUrlFetcher).
        if (file_exists(base_path($path)) || file_exists(base_path($path.'.php'))) {
            continue;
        }
        $stale[] = $path.'  (in '.implode(', ', $files).')';
    }
    sort($stale);

    expect($stale)->toBeEmpty(
        'Audit lens/prompt prose names file paths that no longer exist — the lens is stale and '
        ."audits code that's been moved or removed. Fix or drop each reference:\n - "
        .implode("\n - ", $stale),
    );
});
