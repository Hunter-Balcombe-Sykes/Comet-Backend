<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Internal\EmbeddedConnectController;
use App\Http\Controllers\Api\Internal\EmbeddedOrderAnalyticsController;
use App\Http\Controllers\Api\Internal\EmbeddedProductAnalyticsController;
use App\Http\Controllers\Api\Internal\EmbeddedProductSettingsController;
use App\Http\Controllers\Api\Internal\EmbeddedSetupController;
use App\Http\Controllers\Api\Internal\EnvCheckController;
use App\Http\Controllers\Api\Internal\HydrogenAffiliateController;
use App\Http\Controllers\Api\Internal\HydrogenAffiliateProductsController;
use App\Http\Controllers\Api\Internal\HydrogenBrandConfigController;
use App\Http\Controllers\Api\Internal\HydrogenBrandDesignController;
use App\Http\Controllers\Api\Internal\HydrogenDeploymentController;
use App\Http\Controllers\Api\Internal\SupabaseEmailHookController;
use App\Http\Controllers\Api\PublicSite\AnalyticsController;
use App\Http\Controllers\Api\PublicSite\BootstrapController;
use App\Http\Controllers\Api\PublicSite\PublicBookingController;
use App\Http\Controllers\Api\PublicSite\PublicBrandAffiliateInviteController;
use App\Http\Controllers\Api\PublicSite\PublicConfigController;
use App\Http\Controllers\Api\PublicSite\PublicCustomerLeadController;
use App\Http\Controllers\Api\PublicSite\PublicDocumentDownloadController;
use App\Http\Controllers\Api\PublicSite\PublicEmailSubscriptionController;
use App\Http\Controllers\Api\PublicSite\PublicEmailUnsubscribeController;
use App\Http\Controllers\Api\PublicSite\PublicEnquiryController;
use App\Http\Controllers\Api\PublicSite\PublicOpenInviteController;
use App\Http\Controllers\Api\PublicSite\PublicShopifyStorefrontController;
use App\Http\Controllers\Api\PublicSite\PublicSignupAvailabilityController;
use App\Http\Controllers\Api\PublicSite\PublicSiteController;
use App\Http\Controllers\Api\PublicSite\PublicWaitlistController;
use App\Http\Controllers\Api\Shopify\ShopifyAppOAuthController;
use App\Http\Controllers\Api\Webhooks\Shopify\ShopifyAppUninstalledWebhookController;
use App\Http\Controllers\Api\Webhooks\Shopify\ShopifyGdprWebhookController;
use App\Http\Controllers\Api\Webhooks\Shopify\ShopifyOrdersCancelledWebhookController;
use App\Http\Controllers\Api\Webhooks\Shopify\ShopifyOrdersEditedWebhookController;
use App\Http\Controllers\Api\Webhooks\Shopify\ShopifyOrdersUpdatedWebhookController;
use App\Http\Controllers\Api\Webhooks\Shopify\ShopifyOrderWebhookController;
use App\Http\Controllers\Api\Webhooks\Shopify\ShopifyRefundsCreateWebhookController;
use App\Http\Controllers\Api\Webhooks\Shopify\ShopifyShopUpdateWebhookController;
use App\Http\Controllers\Api\Webhooks\Shopify\ShopifyThemePublishedWebhookController;
use App\Http\Controllers\Api\Webhooks\SquareCatalogWebhookController;
use App\Http\Controllers\Api\Webhooks\Stripe\StripeConnectWebhookController;
use App\Http\Controllers\Api\Webhooks\Stripe\StripePlatformWebhookController;
use App\Http\Controllers\Api\Webhooks\Stripe\StripeWebhookController;
use App\Http\Controllers\Api\Webhooks\SupabaseAuthHookController;
use Illuminate\Support\Facades\Route;

// TODO(v1): all routes below should be prefixed /v1/ once frontend is ready for the migration

// Ping
Route::get('/ping', fn () => response()->json(['pong' => true]))->middleware('throttle:health-check');

// Shopify App OAuth (no auth — Shopify redirects here during install)
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/shopify/install', [ShopifyAppOAuthController::class, 'install']);
    Route::get('/shopify/callback', [ShopifyAppOAuthController::class, 'callback']);
    Route::get('/shopify/setup-prefill', [ShopifyAppOAuthController::class, 'setupPrefill'])
        ->middleware('throttle:10,15');
});

// Webhooks (no auth middleware — signature validated in controller)
Route::middleware('throttle:webhooks')->group(function () {
    Route::post('/webhooks/square', SquareCatalogWebhookController::class);
    Route::post('/webhooks/square/catalog', SquareCatalogWebhookController::class);
    Route::post('/webhooks/stripe-connect', StripeConnectWebhookController::class);
    // Platform-scope destination-charge events. Snapshot route handles v1 events
    // (payment_intent.*, charge.*); thin route handles v2 account events.
    Route::post('/webhooks/stripe-platform', StripePlatformWebhookController::class);
    Route::post('/webhooks/stripe-platform-thin', [StripePlatformWebhookController::class, 'thin']);
    Route::post('/webhooks/stripe', StripeWebhookController::class);

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
    )->name('webhooks.supabase.auth.mfa-verification');
    Route::post('/webhooks/shopify/orders', ShopifyOrderWebhookController::class)
        ->middleware('throttle:shopify-webhooks');
    Route::post('/webhooks/shopify/orders-paid', ShopifyOrderWebhookController::class)
        ->middleware('throttle:shopify-webhooks');
    Route::post('/webhooks/shopify/orders-updated', ShopifyOrdersUpdatedWebhookController::class)
        ->middleware('throttle:shopify-webhooks');
    Route::post('/webhooks/shopify/orders-edited', ShopifyOrdersEditedWebhookController::class)
        ->middleware('throttle:shopify-webhooks');
    Route::post('/webhooks/shopify/orders-cancelled', ShopifyOrdersCancelledWebhookController::class)
        ->middleware('throttle:shopify-webhooks');
    Route::post('/webhooks/shopify/refunds-create', ShopifyRefundsCreateWebhookController::class)
        ->middleware('throttle:shopify-webhooks');
    Route::post('/webhooks/shopify/app-uninstalled', ShopifyAppUninstalledWebhookController::class)
        ->middleware('throttle:shopify-webhooks');
    Route::post('/webhooks/shopify/shop-update', ShopifyShopUpdateWebhookController::class)
        ->middleware('throttle:shopify-webhooks');
    Route::post('/webhooks/shopify/themes-publish', ShopifyThemePublishedWebhookController::class)
        ->middleware('throttle:shopify-webhooks');
    Route::post('/webhooks/shopify/gdpr/customers-data-request', [ShopifyGdprWebhookController::class, 'customersDataRequest'])
        ->middleware('throttle:shopify-webhooks');
    Route::post('/webhooks/shopify/gdpr/customers-redact', [ShopifyGdprWebhookController::class, 'customersRedact'])
        ->middleware('throttle:shopify-webhooks');
    Route::post('/webhooks/shopify/gdpr/shop-redact', [ShopifyGdprWebhookController::class, 'shopRedact'])
        ->middleware('throttle:shopify-webhooks');
});

// bootstrap uses ONLY JWT middleware
Route::middleware(['supabase.jwt', 'throttle:bootstrap'])->post('/bootstrap', [BootstrapController::class, 'bootstrap']);

// Split route files (keeps api.php tidy)
require __DIR__.'/api/professional.php';
require __DIR__.'/api/staff.php';
require __DIR__.'/api/publicSite.php';

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

Route::middleware('feature:smart_booking')->group(function () {
    Route::get('/public/booking/config-by-slug', [PublicBookingController::class, 'config'])
        ->middleware('throttle:public-site');
    Route::get('/public/booking/services-by-slug', [PublicBookingController::class, 'services'])
        ->middleware('throttle:public-site');
    Route::post('/public/booking/availability-by-slug', [PublicBookingController::class, 'availability'])
        ->middleware('throttle:public-site');
    Route::post('/public/booking/checkout-by-slug', [PublicBookingController::class, 'checkout'])
        ->middleware('throttle:booking-checkout');
});
Route::get('/public/shopify/storefront-config', [PublicShopifyStorefrontController::class, 'storefrontConfig'])
    ->middleware('throttle:public-site');

// Public document download — 302-redirects to a short-TTL R2 presigned URL
// with a response-content-disposition=attachment override so the browser
// forces a download instead of rendering inline.
Route::get('/public/documents/{document}/download', PublicDocumentDownloadController::class)
    ->whereUuid('document')
    ->middleware('throttle:public-site');

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
Route::post('/public/analytics/cart-events', [AnalyticsController::class, 'cartEvent'])
    ->middleware('throttle:analytics');
Route::post('/public/analytics/section-seen', [AnalyticsController::class, 'sectionSeen'])
    ->middleware('throttle:analytics');

Route::post('/public/subscribe', [PublicEmailSubscriptionController::class, 'subscribe'])
    ->middleware('throttle:public-site');

Route::post('/public/signup/availability', [PublicSignupAvailabilityController::class, 'check'])
    ->middleware('throttle:public-site');
Route::post('/public/waitlist', [PublicWaitlistController::class, 'store'])
    ->middleware(['throttle:waitlist', 'captcha']);
Route::get('/public/brand-affiliate-invites/{token}', [PublicBrandAffiliateInviteController::class, 'show'])
    ->middleware('throttle:public-site');

// §28.8 — Individual public profile (Astro Worker subrequest target).
// Public, unauthenticated. Rate limit + cache key isolated from generic public-site.
Route::get('/public/profiles/{handle}', [\App\Http\Controllers\Api\PublicSite\IndividualProfileController::class, 'show'])
    ->where('handle', '[A-Za-z0-9-]+')
    ->middleware('throttle:public-profile');
Route::get('/public/join/{handle}', [PublicOpenInviteController::class, 'show'])
    ->where('handle', '[A-Za-z0-9][A-Za-z0-9_-]*')
    ->middleware('throttle:public-site');

Route::post('/public/customers', [PublicCustomerLeadController::class, 'store'])
    ->middleware(['lead.log', 'throttle:leads', 'captcha']);

Route::post('/public/enquiry', [PublicEnquiryController::class, 'submit'])
    ->middleware(['lead.log', 'throttle:leads', 'captcha']);

// Account-linking endpoint — called before a shop is linked to a professional,
// so it uses shopify.session:lenient (JWT validated, shop resolution skipped).
// The controller consumes the brand-side connection code to perform the link.
Route::post('/internal/embedded/connect-account', [EmbeddedConnectController::class, 'connect'])
    ->middleware(['shopify.session:lenient', 'throttle:embedded-by-shop']);

// Internal Shopify embedded app endpoints — consumed by the Partna embedded
// setup wizard. JWT-validated end-to-end via shopify.session middleware;
// throttle keyed by the resolved shop domain (`dest` claim).
Route::middleware(['shopify.session', 'throttle:embedded-by-shop'])->prefix('internal/embedded')->group(function () {
    Route::get('/brand-profile', [EmbeddedSetupController::class, 'brandProfile']);
    Route::post('/brand-identity', [EmbeddedSetupController::class, 'saveIdentity']);
    Route::post('/brand-details', [EmbeddedSetupController::class, 'saveBusinessDetails']);
    Route::patch('/brand-settings', [EmbeddedSetupController::class, 'updateSetting']);
    Route::post('/deployment-token', [EmbeddedSetupController::class, 'saveDeploymentToken']);
    Route::post('/deploy', [EmbeddedSetupController::class, 'deployNow']);
    Route::get('/domain-status', [EmbeddedSetupController::class, 'domainStatus']);
    Route::get('/overview', [EmbeddedSetupController::class, 'overview']);
    Route::get('/products', [EmbeddedSetupController::class, 'embeddedProducts']);
    Route::post('/sync-design', [EmbeddedSetupController::class, 'syncDesign']);
    Route::post('/domain/setup', [EmbeddedSetupController::class, 'setupDomain']);
    Route::post('/domain/provision-txt', [EmbeddedSetupController::class, 'provisionDomainTxt']);
    Route::post('/provision-integration', [EmbeddedSetupController::class, 'provisionShopifyIntegration']);
    Route::post('/confirm-hydrogen', [EmbeddedSetupController::class, 'confirmHydrogenInstall']);
});

// Shopify admin UI extensions (block extensions on order/product pages).
// Same shopify.session + throttle:embedded-by-shop as the wizard group above,
// kept separate only so the route set can be reasoned about independently.
Route::middleware(['shopify.session', 'throttle:embedded-by-shop'])->prefix('internal/embedded')->group(function () {
    Route::get('/orders/{shopify_order_id}', [EmbeddedOrderAnalyticsController::class, 'show'])
        ->where('shopify_order_id', '[A-Za-z0-9_/.:-]+');
    Route::get('/products/{shopify_product_id}/analytics', [EmbeddedProductAnalyticsController::class, 'show'])
        ->where('shopify_product_id', '[A-Za-z0-9_/.:-]+');
    Route::get('/product-settings', [EmbeddedProductSettingsController::class, 'show']);
    Route::patch('/product-settings', [EmbeddedProductSettingsController::class, 'update']);
});

// Self-diagnostic env-var check. Independent of every other auth subsystem on
// purpose — this is the endpoint you hit when something else is misconfigured.
// Auth is a single shared-secret header inside the controller.
Route::get('/internal/env-check', EnvCheckController::class);

// Internal Hydrogen endpoints (server-to-server, API key auth)
Route::middleware(['hydrogen.key', 'throttle:hydrogen-internal'])->prefix('internal/hydrogen')->group(function () {
    Route::get('/brand-config', [HydrogenBrandConfigController::class, 'show']);
    Route::get('/deployment-targets', [HydrogenDeploymentController::class, 'targets']);
    Route::get('/brand-design/{slug}', [HydrogenBrandDesignController::class, 'show'])
        ->where('slug', '[a-zA-Z0-9][a-zA-Z0-9_-]{0,62}');
    Route::get('/affiliate-services', [HydrogenAffiliateController::class, 'services']);
    Route::get('/affiliate-products', [HydrogenAffiliateProductsController::class, 'show']);
});

// INTENTIONALLY UNAUTHENTICATED — enumeration mitigated by controller link verification.
// HydrogenAffiliateController::show() enforces a 404 when no verified BrandPartnerLink
// exists; unknown shop_domain or slug values never return affiliate data.
// Accessory endpoints (services, products) remain behind hydrogen.key since they
// add server load with no client-side initiator.
Route::get('/internal/hydrogen/affiliate', [HydrogenAffiliateController::class, 'show'])
    ->middleware('throttle:hydrogen-internal');

Route::get('/ready', [HealthController::class, 'check'])->middleware('throttle:health-check');
Route::get('/health/scheduler', [HealthController::class, 'scheduler'])->middleware('throttle:health-check');
