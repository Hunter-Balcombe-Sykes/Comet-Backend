<?php

use App\Http\Controllers\Api\PublicSite\PublicCustomerLeadController;
use App\Http\Controllers\Api\PublicSite\PublicEmailSubscriptionController;
use App\Http\Controllers\Api\PublicSite\PublicEnquiryController;
use App\Http\Controllers\Api\PublicSite\PublicMarketingPreferenceController;
use App\Http\Controllers\Api\PublicSite\PublicSiteController;
use Illuminate\Support\Facades\Route;

// TODO(v1): all routes in this file should be prefixed /v1/ once frontend is ready for the migration

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

    // Show Site
    Route::get('/site', [PublicSiteController::class, 'show'])
        ->middleware('throttle:public-site');

    // Customer Leads
    Route::post('/customers', [PublicCustomerLeadController::class, 'store'])
        ->middleware(['lead.log', 'throttle:leads', 'bot.token:lead']);

    // Contact Section Enquiries
    Route::post('/enquiry', [PublicEnquiryController::class, 'submit'])
        ->middleware(['lead.log', 'throttle:leads', 'bot.token:enquiry']);

    Route::post('/subscribe', [PublicEmailSubscriptionController::class, 'subscribe'])
        ->middleware(['throttle:public-subscribe', 'bot.token:subscribe']);

    // Marketing Preferences
    Route::get('/marketing-preference', [PublicMarketingPreferenceController::class, 'show'])
        ->middleware('throttle:public-site');

    Route::post('/unsubscribe/{token}', [PublicMarketingPreferenceController::class, 'unsubscribe'])
        ->middleware('throttle:public-site');
});
