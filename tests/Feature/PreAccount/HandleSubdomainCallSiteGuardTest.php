<?php

// SIGNUP-1 guard. The runtime guard lives at the ONLY boundary that can create
// divergence — HandleAllocator requires a candidate free as handle AND subdomain,
// and SiteProvisioningService::createSiteForHandle() provisions it verbatim or
// fails loudly. This test pins the call sites so the fix cannot be quietly undone
// by re-deriving a subdomain from a seed.
//
// Why the guard is NOT an equality assertion in SiteObserver / UserObserver / a
// model saving hook: RenameSubdomainAction saves the USER first (forceFill of
// handle/handle_lc, line ~116) and its caller UpdateSiteAction saves the SITE
// afterwards, so there is a legitimate in-transaction window where the two
// disagree. A hook-level assertion would throw on every legitimate rename.
// Creation is the only moment at which divergence can be introduced, so that is
// the only moment guarded.

it('no signup path re-derives a subdomain from a seed instead of the allocated handle', function () {
    // Files that must never call subdomainBaseFromHandle()/createSiteWithRetry():
    // every path that allocates a handle and then provisions a site for it.
    $mustUseExactPath = [
        'app/Services/PreAccount/PreAccountBuildService.php',
        'app/Services/User/UserBootstrapService.php',
    ];

    foreach ($mustUseExactPath as $relative) {
        $source = (string) file_get_contents(base_path($relative));

        // Match the CALL, not the name — the docblocks in these files name both
        // methods when explaining why they no longer use them.
        expect($source)->not->toContain('->subdomainBaseFromHandle(');
        expect($source)->not->toContain('->createSiteWithRetry(');
        expect($source)->toContain('->createSiteForHandle(');
    }
});

it('createSiteWithRetry has no callers left in app/', function () {
    // It stays on the class for any future caller that legitimately needs
    // suffixing, but nothing in the signup surface may use it. A new caller is a
    // deliberate decision that has to update this allowlist.
    $allowed = ['app/Services/User/SiteProvisioningService.php'];

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

        if (str_contains((string) file_get_contents($file->getPathname()), '->createSiteWithRetry(')) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBeEmpty(
        'These files provision sites with silent subdomain suffixing, which re-opens the '
        ."handle/subdomain divergence (SIGNUP-1). Use createSiteForHandle():\n - ".implode("\n - ", $violations)
    );
});

it('HandleAllocator checks both the handle pool and the subdomain pool', function () {
    $source = (string) file_get_contents(base_path('app/Services/User/HandleAllocator.php'));

    // A candidate is only allocatable when it is free in ALL THREE pools; drop
    // any one of them and the two suffix formats become reachable again.
    expect($source)->toContain("where('handle_lc', \$candidate)");
    expect($source)->toContain("table('site.sites')");
    expect($source)->toContain("table('site.site_subdomain_aliases')");
    expect($source)->toContain('reserved_subdomains');
});
