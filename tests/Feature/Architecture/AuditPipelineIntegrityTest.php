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
 * What under $path is NOT reachable from $covered, as the SHALLOWEST set of paths.
 *
 * Big roots are enumerated child-by-child in codebase_chunks() to keep each chunk
 * under the scan payload ceiling, so `app/Services` can be fully covered without
 * the literal string `app/Services` appearing anywhere — hence the recursion. A
 * subtree with nothing covered inside it collapses to its own root so the failure
 * message names `app/Services/Platforms`, not its 60 files.
 *
 * Returns [] when fully covered.
 *
 * @param  array<string, mixed>  $covered  path => anything
 * @return list<string>
 */
function auditUncoveredUnder(string $path, array $covered): array
{
    if (auditPathIsCovered($path, $covered)) {
        return [];
    }

    $abs = base_path($path);
    if (! is_dir($abs)) {
        return [$path];   // a file (or missing path) that simply isn't mapped
    }

    // Dependency trees are never audit scope, whatever a lens's prose implies.
    static $skip = ['node_modules', 'vendor', '.git', 'dist', 'build'];

    $missing = [];
    $anyCovered = false;
    $anyAuditable = false;
    foreach (glob($abs.'/*') ?: [] as $childAbs) {
        $child = str_replace('\\', '/', str_replace(base_path().'/', '', $childAbs));
        if (in_array(basename($child), $skip, true)) {
            continue;
        }
        // Only dirs and .php files matter — the scanner's glob ignores the rest.
        if (! is_dir($childAbs) && ! str_ends_with($child, '.php')) {
            continue;
        }
        $anyAuditable = true;

        $sub = auditUncoveredUnder($child, $covered);
        if ($sub === []) {
            $anyCovered = true;
        }
        $missing = array_merge($missing, $sub);
    }

    // A dir holding no auditable source (e.g. database/migrations, just a README)
    // is vacuously covered — there is nothing there for a lens to miss.
    if (! $anyAuditable) {
        return [];
    }

    // Nothing inside is covered — report this root rather than every leaf under it.
    if (! $anyCovered) {
        return [$path];
    }
    sort($missing);

    return $missing;
}

/**
 * The `--scope <path>` paths each lens declares in its own
 * "Suggested per-domain scope groups" prose, keyed by lens name.
 *
 * These are the lens author's statement of where the bugs it hunts live. When
 * codebase_chunks() does not feed a lens what its own doc asks for, the lens
 * silently under-reports — see the self-consistency test below.
 *
 * @return array<string, list<string>>
 */
function auditLensDeclaredScopes(): array
{
    $declared = [];
    foreach (glob(base_path('scripts/audit/lenses/*.md')) ?: [] as $file) {
        $lens = basename($file, '.md');
        $paths = [];
        foreach (preg_split('/\R/', (string) file_get_contents($file)) as $line) {
            if (preg_match('/^\s*--scope\s+(\S+)\s*$/', $line, $m) === 1) {
                $paths[rtrim($m[1], '/')] = true;
            }
        }
        if ($paths !== []) {
            $declared[$lens] = array_keys($paths);
        }
    }

    return $declared;
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
            $missing = auditUncoveredUnder($root, $covered);
            if ($missing !== []) {
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

it('every lens is fed the scope its own doc asks for', function () {
    // Each lens's "Suggested per-domain scope groups" section is the author's
    // statement of where the bugs it hunts actually live. codebase_chunks() is what
    // the lens is really handed in --codebase mode. When those two disagree, the
    // lens reports on a subset of its own remit and the gap is invisible — the run
    // still says "clean", just about less code than anyone thinks.
    //
    // This is the mechanically checkable half of scope drift. It caught, on
    // 2026-07-19: database-and-queue-scaling declaring `--scope supabase/migrations`
    // for its whole CONCURRENTLY / lock_timeout / NOT VALID category while its arm
    // mapped no migrations at all; test-coverage declaring 8 production-code dirs
    // and being handed none of them; and security asking to sweep app/Models for
    // mass-assignment while its arm omitted app/Models entirely.
    //
    // A lens with NO --scope prose (foundational-durability) declares nothing and is
    // skipped here — the dead-path and breadth guards still cover it.
    $byLens = auditScopePathsByLens();

    // Lenses intentionally allowed to be fed less than they declare.
    // Key = lens name, value = written justification.
    $declarationExempt = [
        // 'some-lens' => 'why its arm is deliberately narrower than its doc',
    ];

    $gaps = [];
    foreach (auditLensDeclaredScopes() as $lens => $declaredPaths) {
        if (isset($declarationExempt[$lens])) {
            continue;
        }

        if (! isset($byLens[$lens])) {
            $gaps[] = "$lens declares ".count($declaredPaths).' scope path(s) in its .md but has NO '.
                'arm in codebase_chunks() — it cannot run in --codebase mode at all';

            continue;
        }

        $covered = array_flip($byLens[$lens]);

        $unfed = [];
        foreach ($declaredPaths as $declared) {
            foreach (auditUncoveredUnder($declared, $covered) as $missing) {
                $unfed[$missing] = true;
            }
        }

        if ($unfed !== []) {
            $unfed = array_keys($unfed);
            sort($unfed);
            // Drop entries already implied by a shallower one in the same list
            // (a lens can declare both a dir and a file inside it).
            $unfed = array_values(array_filter(
                $unfed,
                fn (string $p): bool => ! auditPathIsCovered(
                    dirname($p),
                    array_flip(array_diff($unfed, [$p])),
                ),
            ));
            $gaps[] = "$lens declares but is never fed: ".implode(', ', $unfed);
        }
    }
    sort($gaps);

    expect($gaps)->toBeEmpty(
        "Lens .md scope-groups and codebase_chunks() disagree. Each lens must be handed the \n".
        "paths its own doc says its bugs live in, or it under-reports silently. Fix by widening \n".
        "the lens's arm in codebase_chunks() (scripts/audit/audit.sh, chunks under ~350KB of \n".
        "source), OR — if the doc overreaches — narrowing the .md's --scope lines to match \n".
        "reality, OR adding a justified \$declarationExempt entry:\n - ".implode("\n - ", $gaps),
    );
});

it('every lens is reachable from a bundle', function () {
    // A lens file that no bundle lists can only be run by someone who happens to
    // read the lenses/ directory — it will never appear in a sweep. test-prod-parity
    // sat like this until 2026-07-19, so the SQLite-vs-Postgres write drift it hunts
    // (the class behind two real Instagram incidents) was covered by nothing.
    $src = (string) file_get_contents(base_path('scripts/audit/audit.sh'));

    preg_match_all('#lenses/([a-z0-9-]+)\.md#', $src, $m);
    $referenced = array_flip($m[1]);

    $orphaned = [];
    foreach (glob(base_path('scripts/audit/lenses/*.md')) ?: [] as $file) {
        $lens = basename($file, '.md');
        if (! isset($referenced[$lens])) {
            $orphaned[] = $lens;
        }
    }
    sort($orphaned);

    expect($orphaned)->toBeEmpty(
        'These lens files are referenced by no bundle in scripts/audit/audit.sh — they can never '
        ."run in a sweep. Add each to the bundle(s) it belongs in:\n - ".implode("\n - ", $orphaned),
    );
});

it('a full sweep reads every auditable file in the repo', function () {
    // The invariant behind "run a full sweep and it covers the backend". Per-lens
    // maps are each partial by design; what must hold is that the UNION of the
    // full-sweep bundle's lenses leaves nothing unread. Without this, a new
    // top-level file (or a whole dir like tests/Support) is silently never audited
    // by anything — and nobody finds out, because a sweep that skipped it still
    // reports success.
    $src = (string) file_get_contents(base_path('scripts/audit/audit.sh'));

    // The full-sweep arm's lens list. Anchored to the real `case` arm — the usage
    // header also contains the string "full-sweep)", and matching that instead
    // silently captures the WRONG bundle (caught in review: it grabbed core's 8).
    if (preg_match('/^\s*full-sweep\)(.*?)META_PREFIXES/ms', $src, $m) !== 1) {
        throw new RuntimeException('Could not locate the full-sweep bundle in scripts/audit/audit.sh');
    }
    preg_match_all('#lenses/([a-z0-9-]+)\.md#', $m[1], $lm);
    $sweepLenses = array_unique($lm[1]);

    $byLens = auditScopePathsByLens();
    $covered = [];
    foreach ($sweepLenses as $lens) {
        foreach ($byLens[$lens] ?? [] as $p) {
            $covered[$p] = true;
        }
    }

    // Where auditable source lives. Mirrors the roots the sweep is expected to reach.
    $roots = [
        'app', 'routes', 'config', 'database', 'supabase/migrations',
        'cloudflare-worker/src', 'tests', '.github/workflows', 'deploy',
    ];

    // Generated / vendored output that is correctly never audited.
    $exempt = [
        'bootstrap/cache' => 'framework-generated caches, not source',
    ];

    $unread = [];
    foreach ($roots as $root) {
        if (! file_exists(base_path($root))) {
            continue;
        }
        foreach (auditUncoveredUnder($root, $covered) as $missing) {
            if (! isset($exempt[$missing])) {
                $unread[] = $missing;
            }
        }
    }
    sort($unread);

    expect($unread)->toBeEmpty(
        "These paths are read by NO lens in the full-sweep bundle — `--codebase --bundle full-sweep` \n".
        "would silently skip them and still report success. Add each to the most relevant lens's arm \n".
        "in codebase_chunks() (scripts/audit/audit.sh), or add a justified \$exempt entry:\n - "
        .implode("\n - ", $unread),
    );
});

it('every lens sees the code carrying the signals it hunts', function () {
    // The "all APPLICABLE lenses" guard. The other coverage tests enforce that
    // SOMETHING reads each file; this one enforces that the RIGHT lens does.
    //
    // "Applicable" can't be judged by a test — no assertion can decide whether code
    // "looks insecure". But a large slice of applicability is not a judgement at all,
    // it's an observable fact: a file containing DB::transaction demonstrably belongs
    // in transaction-boundaries whatever anyone thinks. Each signal below is a
    // mechanical marker plus the lens that owns it.
    //
    // Caught the motivating case: ClaimSiteService (pre-account signup, merged
    // 2026-07-19) runs DB::transaction + lockForUpdate + a savepoint-wrapped save,
    // and transaction-boundaries could not see the file.
    //
    // LIMITS — read before trusting this:
    //   - It is a FLOOR, not a guarantee. A security bug with no grep signature is
    //     invisible here. Placing code by hand for reasons the signals miss is still
    //     required; this only stops the mistakes that ARE mechanically detectable.
    //   - Signals are deliberately high-confidence. Resist adding fuzzy ones
    //     (e.g. "mentions user input") — a noisy guard gets suppressed, and a
    //     suppressed guard protects nothing.
    $signals = [
        [
            'label' => 'DB::transaction / lockForUpdate / advisory lock',
            'lens' => 'transaction-boundaries',
            'pattern' => '/DB::transaction|->lockForUpdate\(|pg_advisory/',
            'exempt' => [],
        ],
        [
            'label' => 'Cache:: read or write',
            'lens' => 'caching-gold-standard',
            'pattern' => '/Cache::(remember|put|forget|lock|get)\b/',
            'exempt' => [],
        ],
        [
            'label' => 'queued job or mailable (ShouldQueue)',
            'lens' => 'job-queue-correctness',
            'pattern' => '/implements\s+ShouldQueue|,\s*ShouldQueue\b/',
            'exempt' => [],
        ],
        [
            'label' => 'mass-assignment surface ($fillable / $guarded)',
            'lens' => 'security',
            'pattern' => '/protected\s+\$(fillable|guarded)\b/',
            'exempt' => [],
        ],
        [
            'label' => 'env() read outside the config layer',
            'lens' => 'configuration-hygiene',
            'pattern' => '/[^_a-zA-Z]env\(/',
            'exempt' => [],
        ],
    ];

    $byLens = auditScopePathsByLens();

    // Every PHP file under app/, read once and tested against all signals.
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $files[] = str_replace(base_path().'/', '', str_replace('\\', '/', $f->getPathname()));
        }
    }
    sort($files);

    $violations = [];
    foreach ($signals as $signal) {
        $covered = array_flip($byLens[$signal['lens']] ?? []);
        $exempt = array_flip($signal['exempt']);

        $unseen = [];
        foreach ($files as $file) {
            if (isset($exempt[$file])) {
                continue;
            }
            if (preg_match($signal['pattern'], (string) file_get_contents(base_path($file))) !== 1) {
                continue;
            }
            if (! auditPathIsCovered($file, $covered)) {
                $unseen[dirname($file)] = true;
            }
        }

        if ($unseen !== []) {
            $dirs = array_keys($unseen);
            sort($dirs);
            $violations[] = $signal['lens'].' cannot see code with '.$signal['label'].' in: '
                .implode(', ', $dirs);
        }
    }
    sort($violations);

    expect($violations)->toBeEmpty(
        "Code carries a signal a lens exists to hunt, but that lens's scope map cannot reach it — \n".
        "the sweep will report clean on exactly the code the lens was written for. Add the paths to \n".
        "that lens's arm in codebase_chunks() (scripts/audit/audit.sh), or add a justified per-signal \n".
        "'exempt' entry above:\n - ".implode("\n - ", $violations),
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
