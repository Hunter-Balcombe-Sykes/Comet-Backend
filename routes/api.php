<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Internal\CspReportController;
use App\Http\Controllers\Api\Internal\EnvCheckController;
use App\Http\Controllers\Api\Internal\ManyChatBuildController;
use App\Http\Controllers\Api\Internal\SupabaseEmailHookController;
use App\Http\Controllers\Api\PublicSite\AnalyticsController;
use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Http\Controllers\Api\PublicSite\ClaimController;
use App\Http\Controllers\Api\PublicSite\IndividualProfileController;
use App\Http\Controllers\Api\PublicSite\PreAccountBuildController;
use App\Http\Controllers\Api\PublicSite\PublicConfigController;
use App\Http\Controllers\Api\PublicSite\PublicCustomerLeadController;
use App\Http\Controllers\Api\PublicSite\PublicDocumentDownloadController;
use App\Http\Controllers\Api\PublicSite\PublicEarlyAccessController;
use App\Http\Controllers\Api\PublicSite\PublicEmailSubscriptionController;
use App\Http\Controllers\Api\PublicSite\PublicEmailUnsubscribeController;
use App\Http\Controllers\Api\PublicSite\PublicEnquiryController;
use App\Http\Controllers\Api\PublicSite\PublicIntegrationController;
use App\Http\Controllers\Api\PublicSite\PublicLoginIdentifierController;
use App\Http\Controllers\Api\PublicSite\PublicNotificationEmailUnsubscribeController;
use App\Http\Controllers\Api\PublicSite\PublicSignupAvailabilityController;
use App\Http\Controllers\Api\PublicSite\PublicSignupPrewarmController;
use App\Http\Controllers\Api\PublicSite\PublicSiteController;
use App\Http\Controllers\Api\PublicSite\PublicSiteProgressController;
use App\Http\Controllers\Api\Webhooks\ResendWebhookController;
use App\Http\Controllers\Api\Webhooks\SupabaseAuthHookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Ping — liveness, zero-dependency by design: no throttle, because
// throttle:health-check is cache-backed (~3 sequential Redis ops) and a hung
// Redis turned this into a 9-10s response in drill 03 (2026-08-05), long
// enough for a load balancer to pull the app out of rotation over a cache
// problem, not an app problem.
Route::get('/ping', fn () => response()->json(['pong' => true]));

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

    // Resend bounce/complaint webhook (Svix-signed) — hard bounces + spam
    // complaints add the recipient to core.email_suppressions so the
    // MessageSending gate never re-hits a dead/complaining address. Register the
    // endpoint URL + signing secret in the Resend dashboard → Webhooks.
    Route::post('/internal/webhooks/resend', ResendWebhookController::class)
        ->middleware('resend.webhook')->name('webhooks.resend');

    // ManyChat marketing builds. Static-secret gated (ManyChat cannot sign a
    // body — spec §5.1); throttled again on its own bucket because each call
    // can trigger an Apify-billed scrape.
    Route::post('/internal/webhooks/manychat/builds', ManyChatBuildController::class)
        ->middleware(['manychat.webhook', 'throttle:manychat-build'])
        ->name('webhooks.manychat.builds');
});

// bootstrap uses ONLY JWT middleware
Route::middleware(['supabase.jwt', 'throttle:bootstrap'])->post('/bootstrap', [BootstrapController::class, 'bootstrap']);

// claim uses ONLY JWT middleware — same shape as bootstrap. Binds a Supabase
// auth user with no core.users row yet to an unclaimed pre-account site
// (first-come). Email is read exclusively from the verified JWT claim.
Route::middleware(['supabase.jwt', 'throttle:claim'])->post('/claim', [ClaimController::class, 'store']);

// Split route files (keeps api.php tidy)
require __DIR__.'/api/user.php';
require __DIR__.'/api/staff.php';
require __DIR__.'/api/publicSite.php';
require __DIR__.'/api/platforms.php';

// GET preserves the existing email-footer link behavior; POST satisfies
// RFC 8058 one-click unsubscribe (List-Unsubscribe-Post: List-Unsubscribe=One-Click),
// required by Gmail/Yahoo bulk-sender rules for marketing mail since Feb 2024.
// The endpoint is intentionally CSRF-exempt because mailbox providers POST it
// directly; it is idempotent and rate-limited via throttle:public-site.
Route::match(['get', 'post'], '/public/unsubscribe/{token}', [PublicEmailUnsubscribeController::class, 'unsubscribe'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:public-site')
    ->name('public.unsubscribe');

// Category-notification variant of the above (P3, 2026-08-12): flips the
// per-user NotificationEmailPreference for one category. Signed URL rather
// than a stored token — the signature covers userId + category, so no new
// column and nothing to prune. Same GET/POST + CSRF-exempt reasoning.
Route::match(['get', 'post'], '/public/notification-unsubscribe/{userId}/{category}', [PublicNotificationEmailUnsubscribeController::class, 'unsubscribe'])
    ->where(['userId' => '[0-9a-fA-F-]+', 'category' => '[a-z_]+'])
    ->middleware(['signed', 'throttle:public-site'])
    ->name('public.notification-unsubscribe');

// Liveness — same zero-dependency reasoning as /ping above: a static JSON
// literal, no DB, no Redis. Left unthrottled deliberately so a hung cache
// backend can never make a load balancer think the app is dead (measured
// 9-10s here under a hung Redis, drill 03, 2026-08-05). /ready and
// /health/scheduler below do real work and stay throttled.
Route::get('/health', fn () => response()->json(['ok' => true]));

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

// public-surface/SEC-1: GET /public/config/integrations (Google Maps key) moved
// behind auth — see routes/api/user.php's '/config/integrations'. The only named
// consumer is the logged-in dashboard's address autocomplete; no product reason
// for it to be reachable pre-auth.

// Header/site-id based fallback for path-based frontend routing.
Route::post('/public/analytics/pageviews', [AnalyticsController::class, 'pageview'])
    ->middleware('throttle:analytics');
Route::post('/public/analytics/clicks', [AnalyticsController::class, 'click'])
    ->middleware(['throttle:analytics', 'throttle:analytics-click']);
Route::post('/public/analytics/section-seen', [AnalyticsController::class, 'sectionSeen'])
    ->middleware('throttle:analytics');
// Per-page dwell (V1 signal) — cumulative visible-time reported on panel-leave,
// GREATEST-merged onto the section impression row; feeds the page score's dwell term.
Route::post('/public/analytics/section-dwell', [AnalyticsController::class, 'sectionDwell'])
    ->middleware('throttle:analytics');
// Item-impression ingest (analytics v2) — one beacon per scored item entering
// the viewport; feeds analytics.item_views + the popularity scoring job.
Route::post('/public/analytics/item-seen', [AnalyticsController::class, 'itemSeen'])
    ->middleware('throttle:analytics');
// Action exposure/tap ingest (2026-07-23 actions rebuild) — one beacon per
// action doorway entering the viewport / receiving a tap; feeds
// analytics.action_events + ActionScorer's composite scoring.
Route::post('/public/analytics/action-seen', [AnalyticsController::class, 'actionSeen'])
    ->middleware('throttle:analytics');
Route::post('/public/analytics/action-tap', [AnalyticsController::class, 'actionTap'])
    ->middleware(['throttle:analytics', 'throttle:analytics-click']);
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
    ->middleware(['throttle:signup-availability', 'bot.token:signup']);

// Pre-account (site-first signup) build creation + poll. Kicks off real
// scraping (Apify-billed) — dedicated tight throttle, not the shared
// signup-availability bucket. The poll route is a cheap opaque-UUID read,
// so it rides the generic public-site throttle like other public GETs.
Route::post('/public/signup/build', [PreAccountBuildController::class, 'store'])
    ->middleware(['throttle:pre-account-build', 'bot.token:pre-account-build']);
// 9g: fire-and-forget scrape pre-warm while the visitor is still typing —
// costs at most one budget-gated scrape per distinct handle per 900s (the
// job's unique window), answers an unconditional 202 (never an existence
// oracle), and rides the build endpoint's bot-token scope with its own
// tighter throttle bucket.
Route::post('/public/signup/prewarm', PublicSignupPrewarmController::class)
    ->middleware(['throttle:signup-prewarm', 'bot.token:pre-account-build']);
Route::get('/public/signup/builds/{build}', [PreAccountBuildController::class, 'show'])
    ->whereUuid('build')
    ->middleware('throttle:public-site');
// Sign-up name-step pre-fill (A.9/B.2, wire §8) — JWT-gated, never on the
// anonymous poll: it returns the provisional person's name.
Route::get('/public/signup/builds/{build}/prefill', [PreAccountBuildController::class, 'prefill'])
    ->whereUuid('build')
    ->middleware(['supabase.jwt', 'throttle:public-site']);
// Industry-step picker (B.2): the static sector taxonomy for a signer-upper
// who has no core.users row yet, so /profile/sector-options can't answer.
Route::get('/public/signup/sector-options', [PreAccountBuildController::class, 'sectorOptions'])
    ->middleware(['supabase.jwt', 'throttle:public-site']);
// Setup progress for the sitepage overlay (2026-09-02): {done, stage} by
// handle — a visitor holds no build id. Nothing about the person beyond what
// the live page already shows; its own throttle bucket.
Route::get('/public/sites/{handle}/progress', PublicSiteProgressController::class)
    ->middleware('throttle:site-progress');
Route::post('/public/auth/resolve-identifier', [PublicLoginIdentifierController::class, 'resolve'])
    ->middleware(['throttle:login-identifier', 'bot.token:login-identifier']);

// OV-A: early-access marketing form (no bot.token — the marketing site posts
// cross-origin without a token bootstrap; honeypot + timing check + the
// dedicated `early-access` throttle carry the abuse load) and the invite-token
// resolve used by /signup?invite=<token> to autofill + lock the email.
Route::post('/public/early-access', [PublicEarlyAccessController::class, 'store'])
    ->middleware('throttle:early-access');
Route::get('/public/early-access/invite/{token}', [PublicEarlyAccessController::class, 'resolveInvite'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:signup-availability');

// §28.8 — Individual public profile (Astro Worker subrequest target).
// Public, unauthenticated. Rate limit + cache key isolated from generic public-site.
Route::get('/public/profiles/{handle}', [IndividualProfileController::class, 'show'])
    ->where('handle', '[A-Za-z0-9-]+')
    ->middleware('throttle:public-profile');

// Public per-user integration connections (sitepage reads this to render
// integration sections). Separate from the profile payload — additive,
// self-contained. The `/platforms` alias was RETIRED 2026-08-20 — guard:
// tests/Feature/PublicSite/PublicPlatformsAliasRetiredTest.php.
Route::get('/public/profiles/{handle}/integrations', [PublicIntegrationController::class, 'show'])
    ->where('handle', '[A-Za-z0-9-]+')
    ->middleware('throttle:public-profile');

// The public menu endpoint (`/public/profiles/{handle}/menu`) was DELETED in
// slice 7, Phase 3 Task 10 — `pools.menus` on `/public/profiles/{handle}`
// replaces it (spec D2). Guard: tests/Feature/PublicSite/PublicMenuRouteRetiredTest.php.

Route::post('/public/customers', [PublicCustomerLeadController::class, 'store'])
    ->middleware(['lead.log', 'throttle:leads', 'bot.token:lead']);

Route::post('/public/enquiry', [PublicEnquiryController::class, 'submit'])
    ->middleware(['lead.log', 'throttle:leads', 'bot.token:enquiry']);

// Self-diagnostic env-var check. Independent of every other auth subsystem on
// purpose — this is the endpoint you hit when something else is misconfigured.
// Auth is a single shared-secret header inside the controller. Throttled like
// the other ops/diagnostic endpoints (health/ready) so the shared-secret header
// can't be brute-forced and a misconfigured monitor can't flood it.
Route::get('/internal/env-check', EnvCheckController::class)
    ->middleware('throttle:health-check');

// CSP violation report sink. Browsers POST here when a page breaks the policy
// set by SecureHeaders. Unauthenticated (browsers send without credentials);
// throttled hard so a single misconfigured page cannot flood logs.
Route::post('/internal/csp-report', CspReportController::class)
    ->middleware('throttle:120,1');

Route::get('/ready', [HealthController::class, 'check'])->middleware('throttle:health-check');
Route::get('/health/scheduler', [HealthController::class, 'scheduler'])->middleware('throttle:health-check');

// DAST self-test canary (scripts/dast/tests/dast-selftest.sh) — a
// deliberately-vulnerable route that reflects unsanitized input, proving
// the active lane's whole pipeline (bring-up -> seed -> scan) actually
// catches a real XSS, not just that ZAP itself can. Registered ONLY when
// DAST_CANARY=1, which nothing sets outside that one script — never
// present in a real deployed env. See docs/superpowers/plans/2026-07-17-
// dast-security-implementation.md Phase 8.
if (env('DAST_CANARY')) {
    Route::get('/__dast_canary', fn (Request $request) => response('<div>'.$request->query('x').'</div>'));
}
