<?php

use App\Http\Controllers\Api\PublicSite\PublicConfigController;
use App\Http\Controllers\Api\PublicSite\SiteVisibilityController;
use App\Http\Controllers\Api\User\Account\MfaController;
use App\Http\Controllers\Api\User\Account\SessionController;
use App\Http\Controllers\Api\User\Account\UserAccountDeletionController;
use App\Http\Controllers\Api\User\Account\UserDataExportController;
use App\Http\Controllers\Api\User\Account\UserDocumentController;
use App\Http\Controllers\Api\User\Account\UserSelfController;
use App\Http\Controllers\Api\User\Analytics\DevInsightsController;
use App\Http\Controllers\Api\User\Analytics\UserAnalyticsController;
use App\Http\Controllers\Api\User\Content\ContentController;
use App\Http\Controllers\Api\User\Customers\UserCustomerController;
use App\Http\Controllers\Api\User\Customers\UserEnquiryController;
use App\Http\Controllers\Api\User\Feedback\FeedbackController;
use App\Http\Controllers\Api\User\Notifications\ConfirmationPreferenceController;
use App\Http\Controllers\Api\User\Notifications\NotificationController;
use App\Http\Controllers\Api\User\Notifications\NotificationEmailPreferenceController;
use App\Http\Controllers\Api\User\Notifications\UserEmailSubscriptionController;
use App\Http\Controllers\Api\User\Onboarding\OnboardingController;
use App\Http\Controllers\Api\User\Profile\SectorController;
use App\Http\Controllers\Api\User\Profile\SectorOptionsController;
use App\Http\Controllers\Api\User\Site\HandleReclaimController;
use App\Http\Controllers\Api\User\Site\SubdomainAvailabilityController;
use App\Http\Controllers\Api\User\SiteManagement\CustomDomainController;
use App\Http\Controllers\Api\User\SiteManagement\UserGalleryController;
use App\Http\Controllers\Api\User\SiteManagement\UserLinkBlockController;
use App\Http\Controllers\Api\User\SiteManagement\UserSectionBlockController;
use App\Http\Controllers\Api\User\SiteManagement\UserServiceCategoryController;
use App\Http\Controllers\Api\User\SiteManagement\UserServiceController;
use App\Http\Controllers\Api\User\SiteManagement\UserSiteActionsController;
use App\Http\Controllers\Api\User\SiteManagement\UserSiteController;
use App\Http\Controllers\Api\User\SiteManagement\UserWorkplaceController;
use App\Http\Controllers\Api\User\Uploads\UserDesignMediaController;
use App\Http\Controllers\Api\User\Uploads\UserUploadController;
use App\Http\Middleware\Context\EnforcePendingDeletionReadOnly;
use Illuminate\Support\Facades\Route;

// Authorised user logged in
Route::middleware(['user.api', EnforcePendingDeletionReadOnly::class, 'throttle:authenticated'])
    ->group(function () {

        // Show & Edit Details
        Route::get('/me', [UserSelfController::class, 'show']);
        Route::patch('/me', [UserSelfController::class, 'update']);

        // Client-safe third-party integration keys (Google Maps, etc) for the
        // dashboard. public-surface/SEC-1: moved here from /public/config/integrations
        // — its only named consumer is this authenticated dashboard, so there's no
        // reason to serve it pre-auth.
        Route::get('/config/integrations', [PublicConfigController::class, 'integrations'])
            ->name('user.config.integrations');

        // Profile sector/industry — curated picker options + manual set. The
        // sector is also fillable by the Google Business precedence sync
        // (IdentitySync); a manual PUT stamps sector_source='manual'.
        Route::get('/profile/sector-options', [SectorOptionsController::class, 'show']);
        Route::put('/profile/sector', [SectorController::class, 'update']);

        // Post-claim signup setup steps (signup-v2 E/F) — flags + ≤2 sector
        // suggestions, server-computed for both account types.
        Route::get('/onboarding/suggestions', [OnboardingController::class, 'suggestions']);

        // Account Deletion — self-service lifecycle.
        // `idempotent` middleware closes the concurrent-double-submit race that
        // would otherwise let a browser refresh or mobile double-tap persist
        // duplicate audit rows and queue duplicate confirmation mails (#P2-43).
        // Frontend must send a per-action `Idempotency-Key: <uuid-v4>` header.
        // WHK-2: nesting `idempotent` inside this group (itself nested under the
        // outer `throttle:authenticated` group above) does NOT determine execution
        // order — Laravel's SortedMiddleware re-sorts the fully merged stack by the
        // priority list in bootstrap/app.php (`prependToPriorityList(ThrottleRequests,
        // IdempotencyKey)`), regardless of textual group nesting. Do not "fix" this
        // by reordering groups.
        Route::prefix('me/deletion')->middleware('idempotent')->group(function () {
            Route::post('/request', [UserAccountDeletionController::class, 'request'])
                ->middleware('throttle:3,60');
            Route::post('/confirm', [UserAccountDeletionController::class, 'confirm']);
            Route::post('/cancel', [UserAccountDeletionController::class, 'cancel'])
                ->withoutMiddleware([EnforcePendingDeletionReadOnly::class]);
        });

        // Data export — exempt from EnforcePendingDeletionReadOnly so a
        // professional in their grace period can still pull their data
        // (the whole point of GDPR portability). Rate-limited 1/24h.
        Route::post('/me/data-export', [UserDataExportController::class, 'store'])
            ->withoutMiddleware([EnforcePendingDeletionReadOnly::class])
            ->middleware('throttle:1,1440');
        // MFA self-service — fresh AAL2 enforced inside the controller (tighter
        // 60s window than session-level aal2). No require.aal2 middleware here.
        Route::prefix('account/mfa')->group(function () {
            Route::delete('/factors/{factorId}', [MfaController::class, 'destroy'])
                ->whereUuid('factorId')
                ->name('account.mfa.factors.destroy');
        });

        // Active session management — list / revoke / logout-everywhere-else.
        // Powered by VerifySupabaseJwt's session tracking and the Redis JTI
        // blocklist in TokenRevocationService. Bypasses pending-deletion gate
        // so a user mid-deletion can still log themselves out.
        Route::prefix('sessions')->withoutMiddleware([EnforcePendingDeletionReadOnly::class])->group(function () {
            Route::get('/', [SessionController::class, 'index'])->name('sessions.index');
            Route::post('/logout', [SessionController::class, 'logout'])->name('sessions.logout')
                ->middleware('throttle:session-writes');
            Route::post('/logout-others', [SessionController::class, 'logoutOthers'])->name('sessions.logout-others')
                ->middleware('throttle:session-writes');
            Route::delete('/{sessionId}', [SessionController::class, 'destroy'])->name('sessions.destroy')
                ->middleware('throttle:session-writes');
        });

        // View Site Details
        Route::get('/site', [UserSiteController::class, 'show']);
        // Action pool + ranked actions + ordering settings — powers the design
        // page's "Pages" / "Action buttons" controls (read-only; writes via
        // PATCH /site settings).
        Route::get('/site/actions', [UserSiteActionsController::class, 'show'])
            ->name('professional.site.actions');
        // Live availability check for the URL-change flow — same code path as
        // the PATCH /site subdomain validation. Dedicated limiter (typing-speed
        // debounced client-side, but still per-user capped).
        Route::get('/site/subdomain-availability', SubdomainAvailabilityController::class)
            ->middleware('throttle:subdomain-availability')
            ->name('professional.site.subdomain-availability');
        Route::get('/site/workplace', [UserWorkplaceController::class, 'show']);
        Route::get('/site/workplace/previous-website', [UserWorkplaceController::class, 'showPreviousWebsite']);

        // Update Site Details
        Route::patch('/site', [UserSiteController::class, 'update']);
        Route::post('/me/site/reclaim-handle', [HandleReclaimController::class, 'store'])
            ->name('professional.site.reclaim-handle');
        Route::put('/site/workplace', [UserWorkplaceController::class, 'upsert']);
        Route::delete('/site/workplace', [UserWorkplaceController::class, 'destroy']);
        Route::patch('/site/workplace/previous-website', [UserWorkplaceController::class, 'setPreviousWebsite']);
        Route::patch('/site/visibility', [SiteVisibilityController::class, 'update']);

        // Custom domains (Cloudflare for SaaS) — connect your own domain instead of
        // <handle>.partna.au. KV writes flow through SyncSubdomainToKvJob.
        Route::get('/site/custom-domain', [CustomDomainController::class, 'show']);
        Route::put('/site/custom-domain', [CustomDomainController::class, 'store']);
        Route::post('/site/custom-domain/verify', [CustomDomainController::class, 'verify']);
        Route::post('/site/custom-domain/primary', [CustomDomainController::class, 'setPrimary']);
        Route::delete('/site/custom-domain', [CustomDomainController::class, 'destroy']);

        // Booking settings (manual mode — plain external-URL link)
        Route::patch('/booking/settings', [UserSiteController::class, 'updateBookingSettings']);

        // Service Details and Edit
        Route::get('/services', [UserServiceController::class, 'index']);
        Route::post('/services', [UserServiceController::class, 'store']);
        Route::get('/services/{service}', [UserServiceController::class, 'show'])
            ->whereUuid('service');
        Route::patch('/services/{service}', [UserServiceController::class, 'update'])
            ->whereUuid('service');
        Route::delete('/services/{service}', [UserServiceController::class, 'destroy'])
            ->whereUuid('service');
        Route::post('/services/reorder', [UserServiceController::class, 'reorder']);
        // Fresha revert ("resync"): un-break the live sync on an owner-edited
        // (is_manual) projected service — single, or bulk (ids optional = all).
        Route::post('/services/resync', [UserServiceController::class, 'resyncBulk']);
        Route::post('/services/{service}/resync', [UserServiceController::class, 'resync'])
            ->whereUuid('service');
        Route::post('/services/{service}/restore', [UserServiceController::class, 'restore'])
            ->whereUuid('service')
            ->withTrashed();

        // Service Categories (CRUD + reorder)
        Route::get('/service-categories', [UserServiceCategoryController::class, 'index']);
        Route::post('/service-categories', [UserServiceCategoryController::class, 'store']);
        Route::get('/service-categories/{category}', [UserServiceCategoryController::class, 'show'])
            ->whereUuid('category')
            ->withTrashed();
        Route::patch('/service-categories/{category}', [UserServiceCategoryController::class, 'update'])
            ->whereUuid('category');
        Route::delete('/service-categories/{category}', [UserServiceCategoryController::class, 'destroy'])
            ->whereUuid('category');
        Route::post('/service-categories/reorder', [UserServiceCategoryController::class, 'reorder']);
        Route::post('/service-categories/{category}/restore', [UserServiceCategoryController::class, 'restore'])
            ->whereUuid('category')
            ->withTrashed();
        Route::post('/services/reorder-layout', [UserServiceController::class, 'reorderLayout']);
        // Move a single service into a category (or Uncategorized). Dedicated,
        // narrow endpoint — never exposes sort_order as writable input; appends
        // at global max(sort_order)+1, same advisory-lock key as reorder-layout.
        Route::patch('/services/{service}/category', [UserServiceController::class, 'updateCategory'])
            ->whereUuid('service');

        // View Analytics
        Route::get('/analytics', [UserAnalyticsController::class, 'summary']);
        Route::get('/analytics/live', [UserAnalyticsController::class, 'live']);
        // Derived insights (also embedded on /analytics under `insights`) — a
        // dedicated resource for the dashboard's Insights card.
        Route::get('/analytics/insights', [UserAnalyticsController::class, 'insights']);

        // Dev Insights — raw popularity scores + the analytics feeding them (dev/testing
        // surface; explains + demos the scoring, own-site only). Path carries the
        // `professional/` segment to read as a scoped dev tool; still a plain authed GET.
        Route::get('/professional/dev-insights', [DevInsightsController::class, 'index']);

        // Links
        Route::get('/links', [UserLinkBlockController::class, 'index']);
        Route::post('/links', [UserLinkBlockController::class, 'store']);
        Route::patch('/links/{linkBlock}', [UserLinkBlockController::class, 'update'])
            ->whereUuid('linkBlock');
        Route::delete('/links/{linkBlock}', [UserLinkBlockController::class, 'destroy'])
            ->whereUuid('linkBlock');
        Route::post('/links/reorder', [UserLinkBlockController::class, 'reorder']);

        // Sections
        Route::get('/sections', [UserSectionBlockController::class, 'index']);
        Route::post('/sections/reorder', [UserSectionBlockController::class, 'reorder']);
        Route::put('/sections/{blockType}', [UserSectionBlockController::class, 'upsert'])
            ->where('blockType', '[a-z0-9_-]+');
        Route::delete('/sections/{blockType}', [UserSectionBlockController::class, 'remove'])
            ->where('blockType', '[a-z0-9_-]+');

        // Customer View, Add, Edit
        Route::get('/customers', [UserCustomerController::class, 'index']);
        Route::get('/customers/{customer}', [UserCustomerController::class, 'show'])
            ->whereUuid('customer');
        Route::post('/customers', [UserCustomerController::class, 'store']);
        Route::patch('/customers/{customer}', [UserCustomerController::class, 'update'])
            ->whereUuid('customer');
        Route::delete('/customers/{customer}', [UserCustomerController::class, 'destroy'])
            ->whereUuid('customer');
        Route::post('/customers/{customer}/restore', [UserCustomerController::class, 'restore'])
            ->whereUuid('customer')
            ->withTrashed();

        // Contact section enquiry inbox
        Route::get('/enquiries/counts', [UserEnquiryController::class, 'counts']);
        Route::get('/enquiries', [UserEnquiryController::class, 'index']);
        Route::get('/enquiries/{id}', [UserEnquiryController::class, 'show'])->whereUuid('id');
        Route::patch('/enquiries/{id}', [UserEnquiryController::class, 'update'])
            ->whereUuid('id');
        Route::delete('/enquiries/{id}', [UserEnquiryController::class, 'destroy'])
            ->whereUuid('id');
        Route::post('/enquiries/{id}/read', [UserEnquiryController::class, 'markRead'])->whereUuid('id');
        Route::post('/enquiries/{id}/replied', [UserEnquiryController::class, 'markReplied'])->whereUuid('id');
        Route::post('/enquiries/{id}/archive', [UserEnquiryController::class, 'archive'])->whereUuid('id');
        Route::post('/enquiries/{id}/restore', [UserEnquiryController::class, 'restore'])->whereUuid('id');
        Route::post('/enquiries/{id}/spam', [UserEnquiryController::class, 'markSpam'])->whereUuid('id');

        // UI Confirmation Preferences ("don't ask again" toggles)
        Route::get('/confirmation-preferences', [ConfirmationPreferenceController::class, 'show']);
        Route::patch('/confirmation-preferences', [ConfirmationPreferenceController::class, 'update']);

        // Image Upload (server-side processing → WebP variants via queue)
        Route::post('/uploads', [UserUploadController::class, 'upload']);

        // Image Management (pool-based: gallery / content)
        Route::get('/images', [UserUploadController::class, 'index']);
        Route::post('/images/reorder', [UserUploadController::class, 'reorder']);
        Route::delete('/images/{image}', [UserUploadController::class, 'destroy'])
            ->whereUuid('image');

        // Design-layer singleton images (brand logos + per-integration covers).
        Route::get('/design-media', [UserDesignMediaController::class, 'index']);
        Route::post('/design-media', [UserDesignMediaController::class, 'upload']);

        // Content library + selection (sitepage background picks). Library =
        // content-pool uploads + referenced Google Business photos; Selection =
        // ordered list (≤15) of uploads / google photos / Instagram reel+post.
        Route::get('/content/library', [ContentController::class, 'library']);
        Route::post('/content/uploads', [ContentController::class, 'storeUpload'])
            ->middleware('throttle:30,1');
        Route::delete('/content/uploads/{upload}', [ContentController::class, 'destroyUpload'])
            ->whereUuid('upload')
            ->middleware('throttle:30,1');
        Route::get('/content/selection', [ContentController::class, 'selection']);
        Route::put('/content/selection', [ContentController::class, 'replaceSelection'])
            ->middleware('throttle:60,1');
        Route::put('/content/instagram-auto', [ContentController::class, 'setInstagramAuto'])
            ->middleware('throttle:30,1');
        Route::put('/content/google-photos', [ContentController::class, 'setGooglePhotos'])
            ->middleware('throttle:30,1');

        // Image Gallery (gallery-pool ordering & legacy routes)
        Route::get('/gallery', [UserGalleryController::class, 'index']);
        Route::patch('/gallery/{image}', [UserGalleryController::class, 'update'])
            ->whereUuid('image')
            ->middleware('throttle:30,1');
        Route::delete('/gallery/{image}', [UserGalleryController::class, 'destroy'])
            ->whereUuid('image');
        Route::post('/gallery/reorder', [UserGalleryController::class, 'reorder']);

        // Documents (one file per site — PDF/JPG/PNG, 10 MB max)
        Route::get('/documents', [UserDocumentController::class, 'index']);
        Route::post('/documents', [UserDocumentController::class, 'store'])
            ->middleware('throttle:10,1');
        Route::patch('/documents/{document}', [UserDocumentController::class, 'update'])
            ->whereUuid('document')
            ->middleware('throttle:30,1');
        Route::delete('/documents/{document}', [UserDocumentController::class, 'destroy'])
            ->whereUuid('document')
            ->middleware('throttle:30,1');

        // Notifications
        Route::get('/me/notifications', [NotificationController::class, 'index']);
        Route::post('/me/notifications/{notification}/read', [NotificationController::class, 'markRead'])
            ->whereUuid('notification');
        Route::post('/me/notifications/{notification}/dismiss', [NotificationController::class, 'dismiss'])
            ->whereUuid('notification');

        // Notification email preferences
        Route::get('/me/notification-email-preferences', [NotificationEmailPreferenceController::class, 'index']);
        Route::patch('/me/notification-email-preferences', [NotificationEmailPreferenceController::class, 'update']);

        // Email subscribers (marketing list)
        Route::get('/email-subscribers', [UserEmailSubscriptionController::class, 'index']);
        Route::get('/email-subscribers/export', [UserEmailSubscriptionController::class, 'export']);

        // In-app feedback. POST is exempt from EnforcePendingDeletionReadOnly so
        // accounts in their grace period can still submit (they may be giving
        // feedback about the deletion flow itself). Per-user throttle applied
        // directly on the POST via the dedicated `feedback-submit` limiter.
        Route::get('/me/feedback', [FeedbackController::class, 'index']);
        Route::get('/me/feedback/{feedback}', [FeedbackController::class, 'show'])
            ->whereUuid('feedback');
        Route::post('/me/feedback', [FeedbackController::class, 'store'])
            ->withoutMiddleware([EnforcePendingDeletionReadOnly::class])
            ->middleware('throttle:feedback-submit');
    });
