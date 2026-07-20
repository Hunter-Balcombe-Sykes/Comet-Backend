<?php

/*
|--------------------------------------------------------------------------
| Reserved Subdomain / Worker RESERVED Set Sync Guard (EDGE-2)
|--------------------------------------------------------------------------
| config('partna.reserved_subdomains') and cloudflare-worker/src/index.js's
| `RESERVED` Set encode the SAME list twice, by hand — the Worker is plain JS
| with no build-time link to Laravel config and no JS test harness of its own
| (cloudflare-worker/ has zero test files), so this is the ONLY automated
| cross-check between the two. A subdomain reserved on one side but not the
| other either lets someone claim a route the Worker treats as infrastructure
| (KV entry never checked, 404s forever) or silently 404s a subdomain PHP
| still thinks is free.
|
| This test does NOT hand-maintain a third copy of the list — it parses the
| literal array straight out of the Worker source file and diffs it against
| the PHP config, so it goes red the moment either side drifts.
*/

/** Parse the `const RESERVED = new Set([...]);` literal out of the Worker
 *  source and return its string entries. Throws if the literal can't be
 *  found — a rename/restructure of that block must not silently stop this
 *  guard from checking anything. */
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

    preg_match_all('/"([a-z0-9-]+)"/', $match[1], $entries);

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
