<?php

use App\Http\Controllers\Api\Staff\Analytics\StaffAggregateAnalyticsController;
use App\Http\Controllers\Api\Staff\EarlyAccess\StaffEarlyAccessController;
use App\Http\Controllers\Api\Staff\FeatureAvailability\StaffFeatureAvailabilityController;
use App\Http\Controllers\Api\Staff\FeatureFlag\StaffFeatureFlagController;
use App\Http\Controllers\Api\Staff\FeatureFlag\StaffFeatureFlagOverrideController;
use App\Http\Controllers\Api\Staff\Feedback\StaffFeedbackController;
use App\Http\Controllers\Api\Staff\Segments\StaffSegmentController;
use App\Http\Controllers\Api\Staff\StaffCaseController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffAccountDeletionController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffAnalyticsController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffEmailSubscriberController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffEnquiryController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffMeController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffNotificationController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffNotificationEmailPolicyController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffSiteController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffStatsController;
use App\Http\Controllers\Api\Staff\StaffSite\StaffWorkplaceController;
use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffCustomerManagementController;
use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffDataExportController;
use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffIntegrationManagementController;
use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffLinkBlockManagementController;
use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffPreAccountBuildController;
use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffSectionManagementController;
use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffServiceCategoryManagementController;
use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffServiceManagementController;
use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffSiteManagementController;
use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffUserController;
use Illuminate\Support\Facades\Route;

// Authorised Staff Viewing
Route::prefix('staff')
    // `revocation.strict` fails the request closed (503) when Redis could not
    // answer "is this session revoked?". Every other route in the app fails
    // OPEN there, deliberately — but staff act on EVERY user (purge accounts,
    // export data, moderate), so a revoked staff session is the highest-value
    // target in the system and the availability trade inverts. It does not
    // duplicate `require.aal2`: that proves MFA at login, this proves the
    // session has not been revoked since. See
    // docs/superpowers/plans/2026-08-05-auth-selective-failclosed-PLAN.md §3.
    //
    // ⚠️ THIS FILE HAS THREE top-level Route::prefix('staff') groups (here, ~192,
    // ~346), each declaring its own COMPLETE middleware array. "Add it to the staff
    // routes" means all three — the first draft of this change edited only this one
    // and left 58 of 104 staff routes, including
    // DELETE /professionals/{id}/force, failing open. StrictRevocationTest now
    // asserts every api/staff* route carries the gate, so a fourth group cannot
    // silently miss it.
    ->middleware(['supabase.jwt', 'require.email_verified', 'staff', 'require.aal2', 'revocation.strict', 'throttle:staff', 'staff.audit'])
    ->whereUuid('professional')
    ->scopeBindings()
    ->group(function () {

        // Staff Dashboard
        Route::get('/me', [StaffMeController::class, 'show']);

        // Moderation queue — list + detail
        Route::get('/cases', [StaffCaseController::class, 'index'])->name('staff.cases.index');
        Route::get('/cases/{case}', [StaffCaseController::class, 'show'])->name('staff.cases.show');

        // Moderation case lifecycle mutations
        Route::post('/cases/{case}/triage', [StaffCaseController::class, 'triage'])->name('staff.cases.triage');
        Route::post('/cases/{case}/take', [StaffCaseController::class, 'take'])->name('staff.cases.take');
        Route::post('/cases/{case}/release', [StaffCaseController::class, 'release'])->name('staff.cases.release');
        Route::post('/cases/{case}/decide', [StaffCaseController::class, 'decide'])->name('staff.cases.decide');
        Route::post('/cases/{case}/escalate', [StaffCaseController::class, 'escalate'])->name('staff.cases.escalate');

        // Platform-wide stats
        Route::get('/stats', [StaffStatsController::class, 'show']);

        // Pre-account (site-first) build trigger — the ManyChat/marketing surface.
        // Any staff role may fire one (PreAccountBuildPolicy::staffCreate); builds
        // publish by default since the site IS the pitch.
        Route::post('/builds', [StaffPreAccountBuildController::class, 'store']);

        // Manual claim-invite send for a staff-built site (spec §5). Any staff
        // role; guards enforce ready+published+contact_email+not-already-sent.
        Route::post('/builds/{build}/invite', [StaffPreAccountBuildController::class, 'invite'])
            ->whereUuid('build');

        // CSV batch marketing builds (spec §6): one requestBuild per row.
        Route::post('/builds/batch', [StaffPreAccountBuildController::class, 'batch']);

        // Staff can see Site
        Route::get('/sites/{subdomain}', [StaffSiteController::class, 'show'])
            ->where('subdomain', '[A-Za-z0-9-]+');

        // Search professionals
        Route::get('/professionals', [StaffUserController::class, 'index']);

        // View one professional
        Route::get('/professionals/{professional}', [StaffUserController::class, 'show'])
            ->name('staff.professionals.show');
        // Soft delete (regular staff)
        Route::delete('/professionals/{professional}', [StaffUserController::class, 'destroy']);
        // Restore
        Route::post('/professionals/{professional}/restore', [StaffUserController::class, 'restore'])
            ->withTrashed();

        // View Customers
        Route::get('/professionals/{professional}/customers', [StaffCustomerManagementController::class, 'index'])
            ->name('staff.customers.index');
        Route::get('/professionals/{professional}/customers/{customer}', [StaffCustomerManagementController::class, 'show'])
            ->whereUuid('customer')
            ->name('staff.customers.show');
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
        Route::get('/professionals/{professional}/service-categories/{serviceCategory}', [StaffServiceCategoryManagementController::class, 'show'])
            ->whereUuid('serviceCategory')
            ->withTrashed();
        Route::post('/professionals/{professional}/service-categories/{serviceCategory}/restore', [StaffServiceCategoryManagementController::class, 'restore'])
            ->whereUuid('serviceCategory')
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
        Route::get('/professionals/{professional}/email-subscribers', [StaffEmailSubscriberController::class, 'index'])
            ->name('staff.email-subscribers.index');
        Route::get('/professionals/{professional}/email-subscribers/export', [StaffEmailSubscriberController::class, 'export'])
            ->name('staff.email-subscribers.export');

        // #ENQUIRY-1 — contact-form enquiries inbox (read).
        Route::get('/professionals/{professional}/enquiries', [StaffEnquiryController::class, 'index'])
            ->name('staff.enquiries.index');

        // Workplace snapshot stored at site.settings.workplace.
        Route::get('/professionals/{professional}/site/workplace', [StaffWorkplaceController::class, 'show']);

        // ── OV-A: staff-dashboard read surface (any staff role; policies add
        //    the role gate — UserSegmentPolicy / EarlyAccessSignupPolicy /
        //    FeatureAvailabilityPolicy ::staffView). ──────────────────────────

        // Aggregate analytics — all users or ?segment_id= scope.
        Route::get('/analytics/summary', [StaffAggregateAnalyticsController::class, 'summary']);

        // Notification history (global + fan-out rows) for the composer view.
        Route::get('/notifications', [StaffNotificationController::class, 'index']);

        // Segments — read side.
        Route::get('/segments', [StaffSegmentController::class, 'index']);
        Route::get('/segments/{segment}', [StaffSegmentController::class, 'show'])->whereUuid('segment');
        Route::get('/segments/{segment}/users', [StaffSegmentController::class, 'users'])->whereUuid('segment');

        // Feature availability — read side.
        Route::get('/feature-availability', [StaffFeatureAvailabilityController::class, 'index']);

        // Early access — read side.
        Route::get('/early-access', [StaffEarlyAccessController::class, 'index']);

        // OV-D: feedback triage list — all users, filterable by type/area/date.
        // Named + registered in RecordStaffAuditEntry::PII_READ_ROUTE_NAMES —
        // the response includes submitter email/handle + ip_hash (PRIV-4).
        Route::get('/feedback', [StaffFeedbackController::class, 'index'])
            ->name('staff.feedback.index');

        // Feedback triage — status write. Support or admin; FeedbackPolicy::
        // staffTriage adds the role gate (this group has no staff.admin).
        Route::patch('/feedback/{feedback}', [StaffFeedbackController::class, 'update'])
            ->whereUuid('feedback')
            ->name('staff.feedback.update');
    });

// Authorised Staff Admin Editing
// `revocation.strict` — see the note on the first staff group. These admin groups
// are strictly MORE dangerous than the read-mostly group above (hard delete, status
// change, feature flags), so omitting them would invert the intent: reads fail
// closed while destructive writes fail open.
Route::prefix('staff')
    ->middleware(['supabase.jwt', 'require.email_verified', 'staff', 'require.aal2', 'staff.admin', 'revocation.strict', 'throttle:staff', 'staff.audit'])
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
        Route::patch('/professionals/{professional}/service-categories/{serviceCategory}', [StaffServiceCategoryManagementController::class, 'update'])
            ->whereUuid('serviceCategory');
        Route::delete('/professionals/{professional}/service-categories/{serviceCategory}', [StaffServiceCategoryManagementController::class, 'destroy'])
            ->whereUuid('serviceCategory');
        Route::delete('/professionals/{professional}/service-categories/{serviceCategory}/hard', [StaffServiceCategoryManagementController::class, 'forceDestroy'])
            ->whereUuid('serviceCategory');

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

        // The two on-behalf notification writes live OUTSIDE this group — see the
        // unscoped group at the foot of this file. A nested withoutScopedBindings()
        // here does not override the group's scopeBindings() above.

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

        // ── OV-A: staff-dashboard write surface (staff.admin group; policies
        //    ::staffManage add defence-in-depth). ──────────────────────────────

        // Segments — CRUD + manual members.
        Route::post('/segments', [StaffSegmentController::class, 'store']);
        Route::patch('/segments/{segment}', [StaffSegmentController::class, 'update'])->whereUuid('segment');
        Route::delete('/segments/{segment}', [StaffSegmentController::class, 'destroy'])->whereUuid('segment');
        Route::post('/segments/{segment}/members', [StaffSegmentController::class, 'addMembers'])->whereUuid('segment');
        Route::delete('/segments/{segment}/members', [StaffSegmentController::class, 'removeMembers'])->whereUuid('segment');

        // Feature availability — upsert + delete.
        Route::put('/feature-availability', [StaffFeatureAvailabilityController::class, 'upsert']);
        Route::delete('/feature-availability/{rule}', [StaffFeatureAvailabilityController::class, 'destroy'])->whereUuid('rule');

        // Early access — manual add, edit, delete, invite sends.
        Route::post('/early-access', [StaffEarlyAccessController::class, 'store']);
        Route::post('/early-access/invite', [StaffEarlyAccessController::class, 'invite']);
        Route::patch('/early-access/{signup}', [StaffEarlyAccessController::class, 'update'])->whereUuid('signup');
        Route::delete('/early-access/{signup}', [StaffEarlyAccessController::class, 'destroy'])->whereUuid('signup');

        // Approve — allow this lead(s) to claim: re-scrape/heal the linked
        // build, open its claim window, notify (Task 7, spec Flow 3).
        Route::post('/early-access/{signup}/approve', [StaffEarlyAccessController::class, 'approve'])->whereUuid('signup');
        Route::post('/early-access/approve-bulk', [StaffEarlyAccessController::class, 'approveBulk']);

        // Feedback — junk/spam removal (soft delete; purged after 30 days).
        // FeedbackPolicy::staffDelete adds defence-in-depth on top of staff.admin.
        Route::delete('/feedback/{feedback}', [StaffFeedbackController::class, 'destroy'])
            ->whereUuid('feedback')
            ->name('staff.feedback.destroy');

        // Integrations — view + enable/disable per platform for a professional.
        Route::get('/professionals/{professional}/integrations', [StaffIntegrationManagementController::class, 'index']);
        Route::patch('/professionals/{professional}/integrations/{platform}', [StaffIntegrationManagementController::class, 'update'])
            ->where('platform', '[a-z][a-z0-9_-]*');

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

// Authorised Staff Admin Editing — UNSCOPED bindings.
//
// Same gate as the admin group above, minus ->scopeBindings(). Scoping resolves a
// child param through a parent relation named after it, so {notification} would
// resolve via $professional->notifications() — which is Laravel's Notifiable trait
// relation to Illuminate\Notifications\DatabaseNotification (table `notifications`),
// not App\Models\Core\Notifications\Notification (table `notifications.notifications`).
// It also cannot express a global broadcast, whose user_id is NULL and so belongs to
// no per-user relation. Both writes therefore need plain binding, and the controller
// enforces the professional<->notification relationship itself via assertVisibleTo().
//
// This must stay a SEPARATE top-level group: a nested Route::withoutScopedBindings()
// inside the scoped group does not take effect (the route still reports
// enforcesScopedBindings=true), which 500'd both endpoints.
//
// `revocation.strict` — third and last staff group; see the note on the first.
Route::prefix('staff')
    ->middleware(['supabase.jwt', 'require.email_verified', 'staff', 'require.aal2', 'staff.admin', 'revocation.strict', 'throttle:staff', 'staff.audit'])
    ->whereUuid('professional')
    ->group(function () {

        Route::post('/professionals/{professional}/notifications/{notification}/read', [StaffNotificationController::class, 'markReadForProfessional'])
            ->whereUuid('notification');
        Route::post('/professionals/{professional}/notifications/{notification}/dismiss', [StaffNotificationController::class, 'dismissForProfessional'])
            ->whereUuid('notification');
    });
