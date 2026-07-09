<?php

use App\Http\Middleware\VerifyBotToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Bot Protection Coverage Sweep
|--------------------------------------------------------------------------
| Every public mutation endpoint (POST/PUT/PATCH on public/* or v1/public/*)
| must either use bot.token middleware or appear in BOT_PROTECTION_EXEMPT
| below with a justification.
|
| Mirrors PolicyCoverageTest. Prevents silent regression when new public
| endpoints are added.
*/

const BOT_PROTECTION_EXEMPT = [
    // Analytics — write volume too high for interactive CAPTCHA; rate-limit + UA filter cover it.
    'api/public/analytics/pageviews',
    'api/public/analytics/clicks',
    'api/public/analytics/section-seen',
    'api/public/analytics/item-seen',
    'api/public/analytics/ping',
    'api/public/analytics/rum',
    // Resolve-identifier — enumeration defence via constant-time jitter, not interactive CAPTCHA.
    'api/public/auth/resolve-identifier',
    // Signup-availability — deferred to Tier 3 hardening.
    'api/public/signup/availability',
    // Unsubscribe — RFC 8058 token-gated.
    'api/public/unsubscribe/{token}',
];

const BOT_PROTECTION_URI_PREFIXES = ['api/public/', 'api/v1/public/'];
const BOT_PROTECTION_METHODS = ['POST', 'PUT', 'PATCH'];

function bot_protection_route_has_token_middleware($route): bool
{
    $middleware = collect($route->gatherMiddleware());

    return $middleware->contains(fn ($m) => $m === 'bot.token'
        || str_starts_with((string) $m, 'bot.token:')
        || $m === VerifyBotToken::class
        || str_starts_with((string) $m, VerifyBotToken::class.':')
    );
}

it('every public mutation endpoint is either bot-protected or explicitly exempted', function () {
    $publicMutations = collect(Route::getRoutes())
        ->filter(fn ($r) => ! empty(array_intersect(BOT_PROTECTION_METHODS, $r->methods()))
            && collect(BOT_PROTECTION_URI_PREFIXES)->some(fn ($p) => str_starts_with($r->uri(), $p))
            && ! has_auth_middleware($r));

    expect($publicMutations->count())
        ->toBeGreaterThan(0, 'Route collection appears empty — verify test bootstrap loads routes.');

    foreach ($publicMutations as $route) {
        $isProtected = bot_protection_route_has_token_middleware($route);
        $isExempt = in_array($route->uri(), BOT_PROTECTION_EXEMPT, true);

        expect($isProtected || $isExempt)
            ->toBeTrue("Route {$route->uri()} is public mutation without bot.token middleware. Add bot.token:<action> or add to BOT_PROTECTION_EXEMPT with justification.");
    }
});

it('every BOT_PROTECTION_EXEMPT entry matches a registered route', function () {
    $allUris = collect(Route::getRoutes())->map(fn ($r) => $r->uri())->all();
    foreach (BOT_PROTECTION_EXEMPT as $exemptUri) {
        expect(in_array($exemptUri, $allUris, true))
            ->toBeTrue("BOT_PROTECTION_EXEMPT entry '{$exemptUri}' does not match any registered route. Remove stale entry.");
    }
});
