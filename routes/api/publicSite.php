<?php

use App\Http\Controllers\Api\PublicSite\PublicCustomerLeadController;
use App\Http\Controllers\Api\PublicSite\PublicEmailSubscriptionController;
use App\Http\Controllers\Api\PublicSite\PublicEnquiryController;
use App\Http\Controllers\Api\PublicSite\PublicReportController;
use App\Http\Middleware\Moderation\PerTargetReportThrottle;
use Illuminate\Support\Facades\Route;

// Fallback to 'partna.au' so a missing/typo'd PARTNA_PUBLIC_DOMAIN env doesn't
// silently produce an unmatched domain pattern that breaks every public route.
// AppServiceProvider::boot() additionally hard-fails the deploy in production
// if the config resolves to an empty string.
$publicDomain = config('partna.public_domain') ?: 'partna.au';

// Public/Anon
Route::group([
    'domain' => '{subdomain}.'.$publicDomain,
    'where' => ['subdomain' => '[A-Za-z0-9-]+'],
    'prefix' => 'public',
], function () {

    // Customer Leads
    Route::post('/customers', [PublicCustomerLeadController::class, 'store'])
        ->middleware(['lead.log', 'throttle:leads', 'bot.token:lead']);

    // Contact Section Enquiries
    Route::post('/enquiry', [PublicEnquiryController::class, 'submit'])
        ->middleware(['lead.log', 'throttle:leads', 'bot.token:enquiry']);

    Route::post('/subscribe', [PublicEmailSubscriptionController::class, 'subscribe'])
        ->middleware(['throttle:public-subscribe', 'bot.token:subscribe']);

    // The marketing-preference pair that stood here is GONE (plan 05 pass 5,
    // 2026-08-27): zero tests, zero link generators anywhere (mail templates
    // link the flat RFC-8058 lane in api.php), and this domain group is
    // unreachable in production anyway — the Worker forwards every
    // *.partna.au request to the pages app (measured 2026-08-05, SIGNUP-7).
    // Whether the REST of this group should follow is that same open
    // question; the four routes above still have flat siblings + test
    // coverage, so their removal is a daylight decision, not a 4am one.
});

// ── Global (non-subdomain) public endpoints ─────────────────────────────────
Route::middleware([
    'bot.token:report',
    'throttle:partna.moderation.report',
    PerTargetReportThrottle::class,
])->group(function () {
    Route::post('/v1/public/report', [PublicReportController::class, 'submit'])
        ->name('public.moderation.report');
});
