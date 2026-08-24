<?php

/**
 * M-12 (B6 live): a bespoke origin string survived to the intent INSERT and
 * the routing.source_intents origin CHECK rolled back the whole LIFE-16
 * apply transaction — the router had already decided to connect the
 * Instagram profile, and the ledger write took it down. RoutingContext now
 * fails at CONSTRUCTION, where the bug is visible to the caller (and to any
 * test driving that caller), instead of deep inside a DB transaction that
 * SQLite tests can't see constraints for.
 */

use App\Models\Core\User\User;
use App\Routing\RoutingContext;

it('constructs for every ledger-accepted origin', function () {
    foreach (RoutingContext::ORIGINS as $origin) {
        expect(RoutingContext::forUser(new User, $origin)->origin)->toBe($origin);
    }
});

it('throws on an origin the routing ledger CHECK constraints would reject', function () {
    expect(fn () => RoutingContext::forUser(new User, 'google_business_website'))
        ->toThrow(InvalidArgumentException::class, 'google_business_website');
});

it('throws for pre-account builds with an unknown origin too', function () {
    expect(fn () => RoutingContext::preAccountBuild('made_up'))
        ->toThrow(InvalidArgumentException::class);
});
