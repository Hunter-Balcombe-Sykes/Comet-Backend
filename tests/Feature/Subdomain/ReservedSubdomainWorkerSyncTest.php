<?php

/*
|--------------------------------------------------------------------------
| Reserved Subdomain / Worker RESERVED Set Sync Guard (EDGE-2)
|--------------------------------------------------------------------------
| config('partna.reserved_subdomains') and cloudflare-worker/src/index.js's
| `RESERVED` Set encode the SAME list twice, by hand — the Worker is plain JS
| with no build-time link to Laravel config. cloudflare-worker/test/ now has its
| own Miniflare/vitest suite, but it runs the Worker inside workerd with no access
| to Laravel config, so it structurally CANNOT see this mirror: this test remains
| the ONLY automated cross-check between the two. A subdomain reserved on one side
| but not the other either lets someone claim a route the Worker treats as
| infrastructure (KV entry never checked, 404s forever) or silently 404s a
| subdomain PHP still thinks is free.
|
| This test does NOT hand-maintain a third copy of the list — it parses the
| literal array straight out of the Worker source file and diffs it against
| the PHP config, so it goes red the moment either side drifts.
*/

/** Parse the `const RESERVED = new Set([...]);` literal out of the Worker
 *  source and return its string entries. Throws if the literal can't be
 *  found — a rename/restructure of that block must not silently stop this
 *  guard from checking anything.
 *
 *  Comments are stripped BEFORE extracting quoted strings. Without that, a
 *  commented-out entry still counted as present — the most plausible real
 *  edit to that file, and a drift detector that silently stops detecting is
 *  worse than none.
 *
 *  Known limitation (deliberate): the block-comment pass runs first and can't
 *  tell a real block comment from the characters `/*` and `*​/` appearing as
 *  plain text inside two separate `//` comments — it would strip everything
 *  between them and report live entries as missing. That needs an edit this
 *  file's style (single-slash `// --- Section ---` headers) would never
 *  organically produce, and it fails LOUD rather than silently passing, so
 *  it's left as-is. Fix by only honouring `/*` outside a `//`-stripped span
 *  if it ever bites. */
function extractWorkerReservedSubdomains(): array
{
    $path = base_path('cloudflare-worker/src/index.js');
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Could not read {$path}");
    }

    if (preg_match('/const RESERVED = new Set\(\[(.*?)\]\);/s', $contents, $match) !== 1) {
        throw new RuntimeException(
            'Could not locate `const RESERVED = new Set([...]);` in cloudflare-worker/src/index.js — '
            .'has it been renamed or restructured? Update extractWorkerReservedSubdomains() to match.'
        );
    }

    // Strip comments BEFORE extracting quoted strings — otherwise a functionally
    // removed entry (commented out, e.g. `// "dashboard",`) still counts as
    // present and this guard silently stops detecting drift (EDGE-2). Block
    // comments first (non-greedy, so one `/* */` can't eat past its own close),
    // then line comments — the section-header comments already in this file
    // (`// --- Platform infrastructure / DNS ---`) are exactly this shape.
    $withoutComments = preg_replace('#/\*.*?\*/#s', '', $match[1]);
    $withoutComments = preg_replace('#//[^\n]*#', '', $withoutComments);

    preg_match_all('/"([a-z0-9-]+)"/', $withoutComments, $entries);

    return $entries[1];
}

it('keeps config(partna.reserved_subdomains) in sync with the Worker RESERVED set (EDGE-2)', function () {
    $workerReserved = extractWorkerReservedSubdomains();
    $configReserved = config('partna.reserved_subdomains');

    expect($workerReserved)->not->toBeEmpty();
    expect($configReserved)->not->toBeEmpty();

    sort($workerReserved);
    sort($configReserved);

    expect($workerReserved)->toBe(
        $configReserved,
        "cloudflare-worker/src/index.js RESERVED and config('partna.reserved_subdomains') have diverged.\n"
        .'In Worker but not config: '.implode(', ', array_diff($workerReserved, $configReserved))."\n"
        .'In config but not Worker: '.implode(', ', array_diff($configReserved, $workerReserved))
    );
});
