<?php

// The {subdomain}.partna.au domain group was removed 2026-09-04. It duplicated
// three flat routes with byte-identical middleware, and was unreachable in
// production regardless: the Worker claims */* on the partna.au zone and
// forwards to the pages app without calling Laravel (measured 2026-08-05,
// SIGNUP-7). A second registration a future middleware change could miss is a
// hazard with no upside.

use Illuminate\Support\Facades\Route;

it('registers each public lead route exactly once', function () {
    $counts = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array($r->uri(), [
            'api/public/customers',
            'api/public/enquiry',
            'api/public/subscribe',
        ], true))
        ->countBy(fn ($r) => $r->uri());

    expect($counts['api/public/customers'])->toBe(1);
    expect($counts['api/public/enquiry'])->toBe(1);
    expect($counts['api/public/subscribe'])->toBe(1);
});

// This bans domain scoping application-wide, which is stronger than the finding
// it closes — the finding is the DUPLICATE lead lane above. That breadth is
// deliberate today (there is no legitimate domain-scoped route and a second one
// would most likely be a re-added duplicate), but a future custom-domain lane
// would be a real reason to narrow this to the {subdomain}.{public_domain}
// pattern rather than to delete it.
it('registers no domain-scoped routes at all', function () {
    $domained = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => $r->getDomain() !== null)
        ->map(fn ($r) => $r->uri())
        ->all();

    expect($domained)->toBeEmpty();
});
