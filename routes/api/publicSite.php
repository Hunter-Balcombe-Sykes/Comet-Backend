<?php

use App\Http\Controllers\Api\PublicSite\PublicReportController;
use App\Http\Middleware\Moderation\PerTargetReportThrottle;
use Illuminate\Support\Facades\Route;

// The {subdomain}.{public_domain} group was REMOVED 2026-09-04. The three
// routes it still held — customers, enquiry, subscribe — each had a flat
// sibling in routes/api.php with identical middleware. (A fourth, GET /site,
// had already gone with the legacy payload lane; it had no flat sibling and was
// deleted outright, not de-duplicated.) The group was unreachable in production
// either way: the Worker claims */* on the partna.au zone and forwards to the
// pages app (measured 2026-08-05, SIGNUP-7).
// Guard: tests/Feature/PublicSite/DomainScopedRouteGroupRetiredTest.php

// ── Global (non-subdomain) public endpoints ─────────────────────────────────
Route::middleware([
    'bot.token:report',
    'throttle:partna.moderation.report',
    PerTargetReportThrottle::class,
])->group(function () {
    Route::post('/v1/public/report', [PublicReportController::class, 'submit'])
        ->name('public.moderation.report');
});
