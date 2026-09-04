<?php

use App\Http\Controllers\Api\PublicSite\PublicReportController;
use App\Http\Middleware\Moderation\PerTargetReportThrottle;
use Illuminate\Support\Facades\Route;

// The {subdomain}.{public_domain} group was REMOVED 2026-09-04. Its four
// routes each had a flat sibling in routes/api.php with identical middleware,
// and the group was unreachable in production: the Worker claims */* on the
// partna.au zone and forwards to the pages app (measured 2026-08-05, SIGNUP-7).
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
