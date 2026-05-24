<?php

use App\Http\Controllers\Api\Professional\Account\MfaController;
use App\Http\Controllers\Api\Professional\Account\ProfessionalAccountDeletionController;
use App\Http\Controllers\Api\Professional\Account\ProfessionalController;
use App\Http\Controllers\Api\Professional\Account\ProfessionalDataExportController;
use App\Http\Controllers\Api\Professional\Account\ProfessionalDocumentController;
use App\Http\Controllers\Api\Professional\Account\SessionController;
use App\Http\Controllers\Api\Professional\Analytics\ProfessionalAnalyticsController;
use App\Http\Controllers\Api\Professional\Customers\ProfessionalCustomerController;
use App\Http\Controllers\Api\Professional\Customers\ProfessionalEnquiryController;
use App\Http\Controllers\Api\Professional\Notifications\ConfirmationPreferenceController;
use App\Http\Controllers\Api\Professional\Notifications\NotificationController;
use App\Http\Controllers\Api\Professional\Notifications\NotificationEmailPreferenceController;
use App\Http\Controllers\Api\Professional\Notifications\ProfessionalEmailSubscriptionController;
use App\Http\Controllers\Api\Professional\Site\HandleReclaimController;
use App\Http\Controllers\Api\Professional\SiteManagement\ProfessionalGalleryController;
use App\Http\Controllers\Api\Professional\SiteManagement\ProfessionalGoogleBusinessProfileController;
use App\Http\Controllers\Api\Professional\SiteManagement\ProfessionalLinkBlockController;
use App\Http\Controllers\Api\Professional\SiteManagement\ProfessionalSectionBlockController;
use App\Http\Controllers\Api\Professional\SiteManagement\ProfessionalServiceCategoryController;
use App\Http\Controllers\Api\Professional\SiteManagement\ProfessionalServiceController;
use App\Http\Controllers\Api\Professional\SiteManagement\ProfessionalSiteController;
use App\Http\Controllers\Api\Professional\SiteManagement\ProfessionalThemeController;
use App\Http\Controllers\Api\Professional\Uploads\ProfessionalUploadController;
use App\Http\Controllers\Api\PublicSite\SiteVisibilityController;
use App\Http\Middleware\Context\EnforcePendingDeletionReadOnly;
use Illuminate\Support\Facades\Route;

// TODO(v1): all routes in this file should be prefixed /v1/ once frontend is ready for the migration

// Authorised Professional Logged In
Route::middleware(['professional.api', EnforcePendingDeletionReadOnly::class, 'throttle:authenticated'])
    ->group(function () {

        // Show & Edit Details
        Route::get('/me', [ProfessionalController::class, 'show']);
        Route::patch('/me', [ProfessionalController::class, 'update']);

        // Account Deletion — self-service lifecycle
        Route::prefix('me/deletion')->group(function () {
            Route::post('/request', [ProfessionalAccountDeletionController::class, 'request'])
                ->middleware('throttle:3,60');
            Route::post('/confirm', [ProfessionalAccountDeletionController::class, 'confirm']);
            Route::post('/cancel', [ProfessionalAccountDeletionController::class, 'cancel'])
                ->withoutMiddleware([EnforcePendingDeletionReadOnly::class]);
        });

        // Data export — exempt from EnforcePendingDeletionReadOnly so a
        // professional in their grace period can still pull their data
        // (the whole point of GDPR portability). Rate-limited 1/24h.
        Route::post('/me/data-export', [ProfessionalDataExportController::class, 'store'])
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
        Route::get('/site', [ProfessionalSiteController::class, 'show']);
        Route::get('/site/google-business-profile', [ProfessionalGoogleBusinessProfileController::class, 'show']);

        // Update Site Details
        Route::patch('/site', [ProfessionalSiteController::class, 'update']);
        Route::post('/me/site/reclaim-handle', [HandleReclaimController::class, 'store'])
            ->name('professional.site.reclaim-handle');
        Route::put('/site/google-business-profile', [ProfessionalGoogleBusinessProfileController::class, 'upsert']);
        Route::patch('/site/visibility', [SiteVisibilityController::class, 'update']);

        // Booking settings (manual mode — plain external-URL link)
        Route::patch('/booking/settings', [ProfessionalSiteController::class, 'updateBookingSettings']);

        // Service Details and Edit
        Route::get('/services', [ProfessionalServiceController::class, 'index']);
        Route::post('/services', [ProfessionalServiceController::class, 'store']);
        Route::get('/services/{service}', [ProfessionalServiceController::class, 'show'])
            ->whereUuid('service');
        Route::patch('/services/{service}', [ProfessionalServiceController::class, 'update'])
            ->whereUuid('service');
        Route::delete('/services/{service}', [ProfessionalServiceController::class, 'destroy'])
            ->whereUuid('service');
        Route::post('/services/reorder', [ProfessionalServiceController::class, 'reorder']);
        Route::post('/services/{service}/restore', [ProfessionalServiceController::class, 'restore'])
            ->whereUuid('service')
            ->withTrashed();

        // Service Categories (CRUD + reorder)
        Route::get('/service-categories', [ProfessionalServiceCategoryController::class, 'index']);
        Route::post('/service-categories', [ProfessionalServiceCategoryController::class, 'store']);
        Route::get('/service-categories/{category}', [ProfessionalServiceCategoryController::class, 'show'])
            ->whereUuid('category')
            ->withTrashed();
        Route::patch('/service-categories/{category}', [ProfessionalServiceCategoryController::class, 'update'])
            ->whereUuid('category');
        Route::delete('/service-categories/{category}', [ProfessionalServiceCategoryController::class, 'destroy'])
            ->whereUuid('category');
        Route::post('/service-categories/reorder', [ProfessionalServiceCategoryController::class, 'reorder']);
        Route::post('/service-categories/{category}/restore', [ProfessionalServiceCategoryController::class, 'restore'])
            ->whereUuid('category')
            ->withTrashed();
        Route::post('/services/reorder-layout', [ProfessionalServiceController::class, 'reorderLayout']);

        // View Analytics
        Route::get('/analytics', [ProfessionalAnalyticsController::class, 'summary']);

        // Links
        Route::get('/links', [ProfessionalLinkBlockController::class, 'index']);
        Route::post('/links', [ProfessionalLinkBlockController::class, 'store']);
        Route::patch('/links/{linkBlock}', [ProfessionalLinkBlockController::class, 'update'])
            ->whereUuid('linkBlock');
        Route::delete('/links/{linkBlock}', [ProfessionalLinkBlockController::class, 'destroy'])
            ->whereUuid('linkBlock');
        Route::post('/links/reorder', [ProfessionalLinkBlockController::class, 'reorder']);

        // Sections
        Route::get('/sections', [ProfessionalSectionBlockController::class, 'index']);
        Route::post('/sections/reorder', [ProfessionalSectionBlockController::class, 'reorder']);
        Route::put('/sections/{blockType}', [ProfessionalSectionBlockController::class, 'upsert'])
            ->where('blockType', '[a-z0-9_-]+');
        Route::delete('/sections/{blockType}', [ProfessionalSectionBlockController::class, 'remove'])
            ->where('blockType', '[a-z0-9_-]+');

        // Customer View, Add, Edit
        Route::get('/customers', [ProfessionalCustomerController::class, 'index']);
        Route::get('/customers/{customer}', [ProfessionalCustomerController::class, 'show'])
            ->whereUuid('customer');
        Route::post('/customers', [ProfessionalCustomerController::class, 'store']);
        Route::patch('/customers/{customer}', [ProfessionalCustomerController::class, 'update'])
            ->whereUuid('customer');
        Route::delete('/customers/{customer}', [ProfessionalCustomerController::class, 'destroy'])
            ->whereUuid('customer');
        Route::post('/customers/{customer}/restore', [ProfessionalCustomerController::class, 'restore'])
            ->whereUuid('customer')
            ->withTrashed();

        // Contact section enquiry inbox
        Route::get('/enquiries', [ProfessionalEnquiryController::class, 'index']);
        Route::patch('/enquiries/{id}', [ProfessionalEnquiryController::class, 'update'])
            ->whereUuid('id');
        Route::delete('/enquiries/{id}', [ProfessionalEnquiryController::class, 'destroy'])
            ->whereUuid('id');

        // UI Confirmation Preferences ("don't ask again" toggles)
        Route::get('/confirmation-preferences', [ConfirmationPreferenceController::class, 'show']);
        Route::patch('/confirmation-preferences', [ConfirmationPreferenceController::class, 'update']);

        // Theme Selection
        Route::get('/themes', [ProfessionalThemeController::class, 'index']);
        Route::post('/themes/{theme}/select', [ProfessionalThemeController::class, 'select'])
            ->whereUuid('theme');

        // Image Upload (server-side processing → WebP variants via queue)
        Route::post('/uploads', [ProfessionalUploadController::class, 'upload']);

        // Image Management (pool-based: gallery / content)
        Route::get('/images', [ProfessionalUploadController::class, 'index']);
        Route::post('/images/reorder', [ProfessionalUploadController::class, 'reorder']);
        Route::delete('/images/{image}', [ProfessionalUploadController::class, 'destroy'])
            ->whereUuid('image');

        // Image Gallery (gallery-pool ordering & legacy routes)
        Route::get('/gallery', [ProfessionalGalleryController::class, 'index']);
        Route::patch('/gallery/{image}', [ProfessionalGalleryController::class, 'update'])
            ->whereUuid('image')
            ->middleware('throttle:30,1');
        Route::delete('/gallery/{image}', [ProfessionalGalleryController::class, 'destroy'])
            ->whereUuid('image');
        Route::post('/gallery/reorder', [ProfessionalGalleryController::class, 'reorder']);

        // Documents (one file per site — PDF/JPG/PNG, 10 MB max)
        Route::get('/documents', [ProfessionalDocumentController::class, 'index']);
        Route::post('/documents', [ProfessionalDocumentController::class, 'store'])
            ->middleware('throttle:10,1');
        Route::patch('/documents/{document}', [ProfessionalDocumentController::class, 'update'])
            ->whereUuid('document')
            ->middleware('throttle:30,1');
        Route::delete('/documents/{document}', [ProfessionalDocumentController::class, 'destroy'])
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
        Route::get('/email-subscribers', [ProfessionalEmailSubscriptionController::class, 'index']);
        Route::get('/email-subscribers/export', [ProfessionalEmailSubscriptionController::class, 'export']);
    });
