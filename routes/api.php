<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Internal\CspReportController;
use App\Http\Controllers\Api\Internal\EnvCheckController;
use App\Http\Controllers\Api\Internal\SupabaseEmailHookController;
use App\Http\Controllers\Api\PublicSite\AnalyticsController;
use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Http\Controllers\Api\PublicSite\IndividualProfileController;
use App\Http\Controllers\Api\PublicSite\PublicConfigController;
use App\Http\Controllers\Api\PublicSite\PublicCustomerLeadController;
use App\Http\Controllers\Api\PublicSite\PublicDocumentDownloadController;
use App\Http\Controllers\Api\PublicSite\PublicEmailSubscriptionController;
use App\Http\Controllers\Api\PublicSite\PublicEmailUnsubscribeController;
use App\Http\Controllers\Api\PublicSite\PublicEnquiryController;
use App\Http\Controllers\Api\PublicSite\PublicIntegrationController;
use App\Http\Controllers\Api\PublicSite\PublicLoginIdentifierController;
use App\Http\Controllers\Api\PublicSite\PublicSignupAvailabilityController;
use App\Http\Controllers\Api\PublicSite\PublicSiteController;
use App\Http\Controllers\Api\PublicSite\PublicWaitlistController;
use App\Http\Controllers\Api\Webhooks\SupabaseAuthHookController;
use Illuminate\Support\Facades\Route;

// TODO(v1): all routes below should be prefixed /v1/ once frontend is ready for the migration

// Ping
Route::get('/ping', fn () => response()->json(['pong' => true]))->middleware('throttle:health-check');

// Webhooks (no auth middleware — signature validated by per-route middleware)
Route::middleware('throttle:webhooks')->group(function () {
    // Supabase Send Email Hook — receives auth-email events (password reset,
    // magic link, signup confirm, invite) and dispatches our own Mailable so
    // every Partna email rides the same Resend pipeline + universal template.
    // HMAC verified by middleware; rate-limited under the webhooks bucket.
    Route::post('/internal/email-hooks/supabase', SupabaseEmailHookController::class)
        ->middleware('supabase.email-hook');

    // Supabase Auth Hooks — signature-gated, unauthenticated.
    // Configure URL in Supabase Dashboard → Authentication → Hooks → MFA Verification.
    Route::post(
        '/webhooks/supabase/auth/mfa-verification',
        [SupabaseAuthHookController::class, 'mfaVerification'],
    )->middleware('supabase.auth-hook')->name('webhooks.supabase.auth.mfa-verification');
});

// bootstrap uses ONLY JWT middleware
Route::middleware(['supabase.jwt', 'throttle:bootstrap'])->post('/bootstrap', [BootstrapController::class, 'bootstrap']);

// Split route files (keeps api.php tidy)
require __DIR__.'/api/user.php';
require __DIR__.'/api/staff.php';
require __DIR__.'/api/publicSite.php';
require __DIR__.'/api/integrations.php';

// GET preserves the existing email-footer link behavior; POST satisfies
// RFC 8058 one-click unsubscribe (List-Unsubscribe-Post: List-Unsubscribe=One-Click),
// required by Gmail/Yahoo bulk-sender rules for marketing mail since Feb 2024.
// The endpoint is intentionally CSRF-exempt because mailbox providers POST it
// directly; it is idempotent and rate-limited via throttle:public-site.
Route::match(['get', 'post'], '/public/unsubscribe/{token}', [PublicEmailUnsubscribeController::class, 'unsubscribe'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:public-site')
    ->name('public.unsubscribe');

Route::get('/health', fn () => response()->json(['ok' => true]))->middleware('throttle:health-check');

// Header-based fallback for path-based frontend routing (e.g. /shloom).
// When the frontend cannot use subdomain DNS, it sends the subdomain
// via the X-Site-Subdomain header through the Next.js proxy.
Route::get('/public/site-by-slug', [PublicSiteController::class, 'showByHeader'])
    ->middleware('throttle:public-site');

// Public document download — 302-redirects to a short-TTL R2 presigned URL
// with a response-content-disposition=attachment override so the browser
// forces a download instead of rendering inline.
Route::get('/public/documents/{document}/download', PublicDocumentDownloadController::class)
    ->whereUuid('document')
    ->middleware(['throttle:public-site', 'throttle:document-download']);

// Static frontend config (social platform registry, etc). Aggressively cacheable.
// See docs/social-links.md for the social platforms contract.
Route::get('/public/config/social-platforms', [PublicConfigController::class, 'socialPlatforms'])
    ->middleware('throttle:public-site');

// Client-safe third-party integration keys (Google Maps, etc). Consumed by
// the Hydrogen storefront — provider-side restrictions (HTTP referrer, etc)
// keep exposure safe.
Route::get('/public/config/integrations', [PublicConfigController::class, 'integrations'])
    ->middleware('throttle:public-site');

// Header/site-id based fallback for path-based frontend routing.
Route::post('/public/analytics/pageviews', [AnalyticsController::class, 'pageview'])
    ->middleware('throttle:analytics');
Route::post('/public/analytics/clicks', [AnalyticsController::class, 'click'])
    ->middleware(['throttle:analytics', 'throttle:analytics-click']);
Route::post('/public/analytics/section-seen', [AnalyticsController::class, 'sectionSeen'])
    ->middleware('throttle:analytics');
// Session heartbeat (analytics v2) — upserts analytics.site_sessions; powers
// avg-session-duration and the dashboard's live-now counter.
Route::post('/public/analytics/ping', [AnalyticsController::class, 'ping'])
    ->middleware('throttle:analytics');
// Real-user monitoring beacon — receives perf timings from the
// partna-pages dispatcher's inline script. Same throttle group as the
// other analytics endpoints; no DB writes, log-only.
Route::post('/public/analytics/rum', [AnalyticsController::class, 'rum'])
    ->middleware('throttle:analytics');

Route::post('/public/subscribe', [PublicEmailSubscriptionController::class, 'subscribe'])
    ->middleware(['throttle:public-subscribe', 'bot.token:subscribe']);

Route::post('/public/signup/availability', [PublicSignupAvailabilityController::class, 'check'])
    ->middleware(['throttle:public-site', 'bot.token:signup']);
Route::post('/public/auth/resolve-identifier', [PublicLoginIdentifierController::class, 'resolve'])
    ->middleware(['throttle:public-site', 'bot.token:login-identifier']);
Route::post('/public/waitlist', [PublicWaitlistController::class, 'store'])
    ->middleware(['throttle:waitlist', 'bot.token:waitlist']);

// §28.8 — Individual public profile (Astro Worker subrequest target).
// Public, unauthenticated. Rate limit + cache key isolated from generic public-site.
Route::get('/public/profiles/{handle}', [IndividualProfileController::class, 'show'])
    ->where('handle', '[A-Za-z0-9-]+')
    ->middleware('throttle:public-profile');

// Public per-user integration connections (sitepage reads this to render
// integration sections). Separate from the profile payload — additive,
// self-contained. /platforms is a legacy alias kept until the sitepage flips.
Route::get('/public/profiles/{handle}/integrations', [PublicIntegrationController::class, 'show'])
    ->where('handle', '[A-Za-z0-9-]+')
    ->middleware('throttle:public-profile');
Route::get('/public/profiles/{handle}/platforms', [PublicIntegrationController::class, 'show'])
    ->where('handle', '[A-Za-z0-9-]+')
    ->middleware('throttle:public-profile');

Route::post('/public/customers', [PublicCustomerLeadController::class, 'store'])
    ->middleware(['lead.log', 'throttle:leads', 'bot.token:lead']);

Route::post('/public/enquiry', [PublicEnquiryController::class, 'submit'])
    ->middleware(['lead.log', 'throttle:leads', 'bot.token:enquiry']);

// Self-diagnostic env-var check. Independent of every other auth subsystem on
// purpose — this is the endpoint you hit when something else is misconfigured.
// Auth is a single shared-secret header inside the controller.
Route::get('/internal/env-check', EnvCheckController::class);

// CSP violation report sink. Browsers POST here when a page breaks the policy
// set by SecureHeaders. Unauthenticated (browsers send without credentials);
// throttled hard so a single misconfigured page cannot flood logs.
Route::post('/internal/csp-report', CspReportController::class)
    ->middleware('throttle:120,1');

Route::get('/ready', [HealthController::class, 'check'])->middleware('throttle:health-check');
Route::get('/health/scheduler', [HealthController::class, 'scheduler'])->middleware('throttle:health-check');
