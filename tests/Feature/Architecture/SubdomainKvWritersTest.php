<?php

// §51 architecture test — single-writer rule for the Cloudflare KV subdomain
// routing table. ONLY SyncSubdomainToKvJob is allowed to call
// CloudflareKvService::put() / ->bulkPut() / ->delete(). It reconciles both
// upserts and deletes (retirement on user delete) so there is exactly one KV writer.
//
// Why this is load-bearing (§50 non-negotiable rule #5): the KV table is the
// edge Worker's source of truth for subdomain routing. Any other writer
// (controller, observer, ad-hoc command, another job) bypasses the canonical
// branch logic in SyncSubdomainToKvJob and produces routing entries the Worker
// doesn't know how to interpret. Worse, parallel writers fight each other —
// last-write-wins can land on stale state.
//
// This test parses source files looking for `->put(`, `->bulkPut(` or `->delete(`
// calls against a variable typed `CloudflareKvService` (or imported as such). It is
// intentionally strict — adding a new writer requires updating the allowlist
// below with a written justification, forcing a deliberate decision rather
// than a quiet bypass.

it('only SyncSubdomainToKvJob writes to Cloudflare KV', function () {
    $allowed = [
        'app/Jobs/Cloudflare/SyncSubdomainToKvJob.php',
        // The service itself defines put() / delete() — exclude.
        'app/Services/Cloudflare/CloudflareKvService.php',
    ];

    $violations = [];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('app'), RecursiveDirectoryIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iter as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        if (in_array($relative, $allowed, true)) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        // Reference to the service class anywhere — either via use-import or FQN.
        $referencesService = str_contains($source, 'CloudflareKvService');
        if (! $referencesService) {
            continue;
        }

        // Look for ->put( / ->bulkPut( / ->delete( in source — the service surface that
        // mutates KV. Match is intentionally string-cheap; CloudflareKvService is small
        // enough that a false positive (a method named put on a different object) is highly
        // unlikely in a file that ALSO imports CloudflareKvService.
        if (preg_match('/->\s*(bulkPut|put|delete)\s*\(/', $source) === 1) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBeEmpty(
        "Single-writer rule violation: only SyncSubdomainToKvJob may call ->put()/->bulkPut()/->delete() on CloudflareKvService. Offenders: \n - ".implode("\n - ", $violations)
    );
});
