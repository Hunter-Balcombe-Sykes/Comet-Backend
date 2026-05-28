<?php

use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffCustomerManagementController;
use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffDataExportController;
use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffLinkBlockManagementController;
use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffUserController;
use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffSectionManagementController;
use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffServiceCategoryManagementController;
use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffServiceManagementController;
use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffSiteManagementController;
use App\Http\Controllers\Api\Staff\FeatureFlag\StaffFeatureFlagController;
use App\Http\Controllers\Api\Staff\FeatureFlag\StaffFeatureFlagOverrideController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffAccountDeletionController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffAnalyticsController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffEmailSubscriberController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffEnquiryController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffGoogleBusinessProfileController;
use App\Http\Controllers\Api\Staff\StaffCaseController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffMeController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffNotificationController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffNotificationEmailPolicyController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffSiteController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffStatsController;
use Illuminate\Support\Facades\Route;

// TODO(v1): all routes in this file should be prefixed /v1/ once frontend is ready for the migration

// Authorised Staff Viewing
Route::prefix('staff')
    ->middleware(['supabase.jwt', 'require.email_verified', 'staff', 'require.aal2', 'throttle:staff', 'staff.audit'])
    ->whereUuid('professional')
    ->scopeBindings()
    ->group(function () {

        // Staff Dashboard
        Route::get('/me', [StaffMeController::class, 'show']);

        // Moderation queue
        Route::get('/cases', [StaffCaseController::class, 'index'])->name('staff.cases.index');

        // Platform-wide stats
        Route::get('/stats', [StaffStatsController::class, 'show']);

        // Staff can see Site
        Route::get('/sites/{subdomain}', [StaffSiteController::class, 'show'])
            ->where('subdomain', '[A-Za-z0-9-]+');

        // Search professionals
        Route::get('/professionals', [StaffUserController::class, 'index']);

        // View one professional
        Route::get('/professionals/{professional}', [StaffUserController::class, 'show']);
        // Soft delete (regular staff)
        Route::delete('/professionals/{professional}', [StaffUserController::class, 'destroy']);
        // Restore
        Route::post('/professionals/{professional}/restore', [StaffUserController::class, 'restore'])
            ->withTrashed();

        // View Customers
        Route::get('/professionals/{professional}/customers', [StaffCustomerManagementController::class, 'index']);
        Route::get('/professionals/{professional}/customers/{customer}', [StaffCustomerManagementController::class, 'show'])
            ->whereUuid('customer');
        Route::post('/professionals/{professional}/customers/{customer}/restore', [StaffCustomerManagementController::class, 'restore'])
            ->whereUuid('customer')
            ->withTrashed();

        // View Services
        Route::get('/professionals/{professional}/services', [StaffServiceManagementController::class, 'index']);
        Route::get('/professionals/{professional}/services/{service}', [StaffServiceManagementController::class, 'show'])
            ->whereUuid('service')
            ->withTrashed();
        Route::post('/professionals/{professional}/services/{service}/restore', [StaffServiceManagementController::class, 'restore'])
            ->whereUuid('service')
            ->withTrashed();

        // View Service Categories
        Route::get('/professionals/{professional}/service-categories', [StaffServiceCategoryManagementController::class, 'index']);
        Route::get('/professionals/{professional}/service-categories/{category}', [StaffServiceCategoryManagementController::class, 'show'])
            ->whereUuid('category')
            ->withTrashed();
        Route::post('/professionals/{professional}/service-categories/{category}/restore', [StaffServiceCategoryManagementController::class, 'restore'])
            ->whereUuid('category')
            ->withTrashed();

        // View that professional's site data
        Route::get('/professionals/{professional}/site', [StaffSiteController::class, 'showByProfessional']);

        // View analytics summary
        Route::get('/professionals/{professional}/analytics', [StaffAnalyticsController::class, 'summary']);

        // View Link Blocks
        Route::get('/professionals/{professional}/links', [StaffLinkBlockManagementController::class, 'index']);

        // View Sections
        Route::get('/professionals/{professional}/sections', [StaffSectionManagementController::class, 'index']);

        // View a pro's in-app notifications (read-only mirror of /me/notifications)
        Route::get('/professionals/{professional}/notifications', [StaffNotificationController::class, 'indexForProfessional']);

        // View account deletion state + audit log for support context.
        Route::get('/professionals/{professional}/deletion', [StaffAccountDeletionController::class, 'show'])
            ->withTrashed();

        // Data export — staff-triggered. ?send_to=staff requires admin role
        Route::post('/professionals/{professional}/data-export', [StaffDataExportController::class, 'store']);

        // #GDPR-1 — email subscribers list + CSV export.
        Route::get('/professionals/{professional}/email-subscribers', [StaffEmailSubscriberController::class, 'index']);
        Route::get('/professionals/{professional}/email-subscribers/export', [StaffEmailSubscriberController::class, 'export']);

        // #ENQUIRY-1 — contact-form enquiries inbox (read).
        Route::get('/professionals/{professional}/enquiries', [StaffEnquiryController::class, 'index']);

        // #GBP-1 — Google Business Profile snapshot stored in site.settings.
        Route::get('/professionals/{professional}/site/google-business-profile', [StaffGoogleBusinessProfileController::class, 'show']);
    });

// Authorised Staff Admin Editing
Route::prefix('staff')
    ->middleware(['supabase.jwt', 'require.email_verified', 'staff', 'require.aal2', 'staff.admin', 'throttle:staff', 'staff.audit'])
    ->whereUuid('professional')
    ->scopeBindings()
    ->group(function () {

        // Suspend / unsuspend professional
        Route::patch('/professionals/{professional}/status', [StaffUserController::class, 'updateStatus']);
        Route::patch('/professionals/{professional}', [StaffUserController::class, 'update']);
        // Hard delete (admin only)
        Route::delete('/professionals/{professional}/force', [StaffUserController::class, 'forceDestroy']);

        // Bulk suspend/reactivate a wave of professionals (compliance sweep, admin only).
        Route::middleware('throttle:5,1')
            ->post('/professionals/bulk-status', [StaffUserController::class, 'bulkUpdateStatus']);

        // Admin edit/delete customers for a professional
        Route::patch('/professionals/{professional}/customers/{customer}', [StaffCustomerManagementController::class, 'update'])
            ->whereUuid('customer');
        Route::delete('/professionals/{professional}/customers/{customer}', [StaffCustomerManagementController::class, 'destroy'])
            ->whereUuid('customer');
        Route::delete('/professionals/{professional}/customers/{customer}/hard', [StaffCustomerManagementController::class, 'forceDestroy'])
            ->whereUuid('customer');

        // Edit Services
        Route::post('/professionals/{professional}/services', [StaffServiceManagementController::class, 'store']);
        Route::patch('/professionals/{professional}/services/{service}', [StaffServiceManagementController::class, 'update'])
            ->whereUuid('service');
        Route::delete('/professionals/{professional}/services/{service}', [StaffServiceManagementController::class, 'destroy'])
            ->whereUuid('service');
        Route::delete('/professionals/{professional}/services/{service}/hard', [StaffServiceManagementController::class, 'forceDestroy'])
            ->whereUuid('service');
        Route::post('/professionals/{professional}/services/reorder', [StaffServiceManagementController::class, 'reorder']);

        // Edit Service Categories
        Route::post('/professionals/{professional}/service-categories', [StaffServiceCategoryManagementController::class, 'store']);
        Route::patch('/professionals/{professional}/service-categories/{category}', [StaffServiceCategoryManagementController::class, 'update'])
            ->whereUuid('category');
        Route::delete('/professionals/{professional}/service-categories/{category}', [StaffServiceCategoryManagementController::class, 'destroy'])
            ->whereUuid('category');
        Route::delete('/professionals/{professional}/service-categories/{category}/hard', [StaffServiceCategoryManagementController::class, 'forceDestroy'])
            ->whereUuid('category');

        // Reorder categories
        Route::post('/professionals/{professional}/service-categories/reorder', [StaffServiceCategoryManagementController::class, 'reorder']);

        // Full UI layout reorder (categories + services) for staff admin
        Route::post('/professionals/{professional}/services/reorder-layout', [StaffServiceManagementController::class, 'reorderLayout']);

        // Edit site
        Route::patch('/professionals/{professional}/site', [StaffSiteManagementController::class, 'update']);

        // Edit Link Blocks
        Route::post('/professionals/{professional}/links', [StaffLinkBlockManagementController::class, 'store']);
        Route::patch('/professionals/{professional}/links/{linkBlock}', [StaffLinkBlockManagementController::class, 'update'])
            ->whereUuid('linkBlock');
        Route::delete('/professionals/{professional}/links/{linkBlock}', [StaffLinkBlockManagementController::class, 'destroy'])
            ->whereUuid('linkBlock');
        Route::post('/professionals/{professional}/links/reorder', [StaffLinkBlockManagementController::class, 'reorder']);

        // Edit Sections
        Route::put('/professionals/{professional}/sections/{blockType}', [StaffSectionManagementController::class, 'upsert'])
            ->where('blockType', '[a-z0-9_-]+');
        Route::post('/professionals/{professional}/sections/reorder', [StaffSectionManagementController::class, 'reorder']);
        Route::delete('/professionals/{professional}/sections/{blockType}', [StaffSectionManagementController::class, 'remove'])
            ->where('blockType', '[a-z0-9_-]+');

        // Notifications
        Route::post('/notifications', [StaffNotificationController::class, 'store']);

        Route::withoutScopedBindings()->group(function (): void {
            Route::post('/professionals/{professional}/notifications/{notification}/read', [StaffNotificationController::class, 'markReadForProfessional'])
                ->whereUuid('notification');
            Route::post('/professionals/{professional}/notifications/{notification}/dismiss', [StaffNotificationController::class, 'dismissForProfessional'])
                ->whereUuid('notification');
        });

        // Notification email policies
        Route::get('/notification-email-policies', [StaffNotificationEmailPolicyController::class, 'indexGlobal']);
        Route::patch('/notification-email-policies', [StaffNotificationEmailPolicyController::class, 'updateGlobal']);
        Route::get('/professionals/{professional}/notification-email-policies', [StaffNotificationEmailPolicyController::class, 'indexProfessional']);
        Route::patch('/professionals/{professional}/notification-email-policies', [StaffNotificationEmailPolicyController::class, 'updateProfessional']);

        // GDPR-triggered erasure
        Route::post('/professionals/{professional}/deletion/initiate', [StaffAccountDeletionController::class, 'initiate'])
            ->withTrashed();
        Route::post('/professionals/{professional}/deletion/cancel', [StaffAccountDeletionController::class, 'cancel'])
            ->withTrashed();

        // Feature flag admin — create/update/delete flags and per-tenant overrides.
        Route::prefix('feature-flags')->group(function (): void {
            Route::get('/', [StaffFeatureFlagController::class, 'index']);
            Route::post('/', [StaffFeatureFlagController::class, 'store']);
            Route::patch('{key}', [StaffFeatureFlagController::class, 'update'])
                ->where('key', '[a-z][a-z0-9_]*');
            Route::delete('{key}', [StaffFeatureFlagController::class, 'destroy'])
                ->where('key', '[a-z][a-z0-9_]*');
            Route::get('{key}/overrides', [StaffFeatureFlagOverrideController::class, 'index'])
                ->where('key', '[a-z][a-z0-9_]*');
            Route::post('{key}/overrides', [StaffFeatureFlagOverrideController::class, 'store'])
                ->where('key', '[a-z][a-z0-9_]*');
            Route::delete('overrides/{id}', [StaffFeatureFlagOverrideController::class, 'destroy'])
                ->whereUuid('id');
        });
    });
