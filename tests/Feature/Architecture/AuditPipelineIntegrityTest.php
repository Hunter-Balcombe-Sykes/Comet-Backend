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
//     (c) a path is mapped under SOME lens but not under a BREADTH lens
//         (code-quality-slop, semantic-correctness). Scanning is per lens, so
//         (b) passing does not mean the dead-code lens can see the file. This is
//         the subtlest of the three and the one that shipped a false clean bill:
//         see the breadth-lens test below.
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
 * Scope-map paths from codebase_chunks() in audit.sh, keyed by LENS name.
 * The function is a shell `case`; each arm looks like:
 *     lens-name) cat <<'EOF'
 *     chunk-name|path1 path2 path3
 *     EOF
 *     ;;
 * Per-lens attribution matters because the pipeline scans per lens — a path
 * mapped only under schema-rls is invisible to code-quality-slop.
 *
 * @return array<string, list<string>>
 */
function auditScopePathsByLens(): array
{
    $src = (string) file_get_contents(base_path('scripts/audit/audit.sh'));

    // Isolate the function body so we never match a '|' from shell elsewhere.
    if (preg_match('/^codebase_chunks\(\)\s*\{(.*?)^\}/ms', $src, $m) !== 1) {
        throw new RuntimeException('Could not locate codebase_chunks() in scripts/audit/audit.sh');
    }

    $byLens = [];
    $lens = null;
    foreach (preg_split('/\R/', $m[1]) as $line) {
        $line = trim($line);

        // Case arm opener: `lens-name) cat <<'EOF'` — switches the active lens.
        if (preg_match("/^([a-z0-9-]+)\)\s*cat\s*<<'EOF'/", $line, $lm) === 1) {
            $lens = $lm[1];
            $byLens[$lens] ??= [];

            continue;
        }

        // A map line: lowercase chunk name, a pipe, then space-separated paths.
        if ($lens === null || preg_match('/^[a-z0-9-]+\|(.+)$/', $line, $mm) !== 1) {
            continue;
        }
        foreach (preg_split('/\s+/', trim($mm[1])) as $p) {
            if ($p !== '') {
                $byLens[$lens][$p] = true;
            }
        }
    }

    return array_map(
        fn (array $paths): array => array_keys($paths),
        $byLens,
    );
}

/**
 * Every path referenced by codebase_chunks(), flattened across all lenses.
 *
 * @return list<string>
 */
function auditScopePaths(): array
{
    $all = [];
    foreach (auditScopePathsByLens() as $paths) {
        foreach ($paths as $p) {
            $all[$p] = true;
        }
    }

    return array_keys($all);
}

/**
 * True if $path, or any ancestor of it, is in $covered. The scanner recurses
 * (`find <dir>`), so an ancestor mapping covers every descendant.
 *
 * @param  array<string, mixed>  $covered  path => anything
 */
function auditPathIsCovered(string $path, array $covered): bool
{
    $parts = explode('/', $path);
    while ($parts !== []) {
        if (isset($covered[implode('/', $parts)])) {
            return true;
        }
        array_pop($parts);
    }

    return false;
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

    $uncovered = [];
    foreach ($watchedParents as $parent) {
        foreach (glob(base_path($parent).'/*', GLOB_ONLYDIR) ?: [] as $abs) {
            $child = str_replace('\\', '/', str_replace(base_path().'/', '', $abs));
            if (isset($coverageExempt[$child]) || auditPathIsCovered($child, $covered)) {
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

it('breadth lenses cover the whole product surface', function () {
    // The test above checks coverage by ANY lens. That is not enough for the two
    // BREADTH lenses: the pipeline scans per lens, so app/Models being mapped under
    // schema-rls does nothing for code-quality-slop. Dead code and plausible-but-wrong
    // logic are only findable in files the lens actually reads, and a narrow map
    // produces a confident, empty, wrong report rather than an error.
    //
    // Caught for real on 2026-07-19: the waitlist subsystem (model, public controller,
    // form request, route, config keys, console command) survived a whole-repo
    // dead-code sweep because slop's map reached exactly one of those files.
    $breadthLenses = ['code-quality-slop', 'semantic-correctness'];

    // Roots each breadth lens must reach in full. A root counts as covered when the
    // root itself is mapped, OR every immediate child (dir or .php file) is — which
    // is how the big roots are handled, enumerated child-by-child to stay under the
    // per-chunk size ceiling. Enumerating means a NEW child silently drops out of
    // scope, so this assertion is what makes child-level chunking safe.
    $productSurface = [
        'app/Services',
        'app/Models',
        'app/Http/Controllers/Api',
        'app/Http/Requests',
        'app/Http/Resources',
        'app/Jobs',
        'app/Console',
        'app/Observers',
        'app/Notifications',
        'app/Policies',
        'routes',
        'config',
    ];

    $byLens = auditScopePathsByLens();

    $gaps = [];
    foreach ($breadthLenses as $lens) {
        if (! isset($byLens[$lens])) {
            $gaps[] = "$lens has no arm in codebase_chunks() at all — it cannot run in --codebase mode";

            continue;
        }

        $covered = array_flip($byLens[$lens]);

        foreach ($productSurface as $root) {
            if (auditPathIsCovered($root, $covered)) {
                continue;
            }

            // Root not mapped wholesale — every immediate child must be mapped.
            $missing = [];
            foreach (glob(base_path($root).'/*') ?: [] as $abs) {
                $child = str_replace('\\', '/', str_replace(base_path().'/', '', $abs));
                if (! is_dir($abs) && ! str_ends_with($child, '.php')) {
                    continue;
                }
                if (! auditPathIsCovered($child, $covered)) {
                    $missing[] = $child;
                }
            }

            if ($missing !== []) {
                sort($missing);
                $gaps[] = "$lens is blind to: ".implode(', ', $missing);
            }
        }
    }
    sort($gaps);

    expect($gaps)->toBeEmpty(
        "Breadth lenses (code-quality-slop, semantic-correctness) must read the whole product \n".
        "surface — they report nothing for files they never open, which reads as 'clean'. Add each \n".
        "path to that lens's arm in codebase_chunks() (scripts/audit/audit.sh), keeping chunks under \n".
        "~350KB of source, and mirror it in the lens's .md scope-group:\n - ".implode("\n - ", $gaps),
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
