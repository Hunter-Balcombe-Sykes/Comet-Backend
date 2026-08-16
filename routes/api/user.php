<?php

use App\Http\Controllers\Api\Catalog\CatalogSurfacesController;
use App\Http\Controllers\Api\Content\ItemController;
use App\Http\Controllers\Api\Content\ItemLinkController;
use App\Http\Controllers\Api\Content\KindsController;
use App\Http\Controllers\Api\Content\ManualOverrideController;
use App\Http\Controllers\Api\Content\PoolController;
use App\Http\Controllers\Api\Content\PoolItemCreateController;
use App\Http\Controllers\Api\PublicSite\PublicConfigController;
use App\Http\Controllers\Api\PublicSite\SiteVisibilityController;
use App\Http\Controllers\Api\Routing\ConnectionsController;
use App\Http\Controllers\Api\Routing\RoutingController;
use App\Http\Controllers\Api\Routing\SuggestionsController;
use App\Http\Controllers\Api\Site\PageController;
use App\Http\Controllers\Api\Site\RestyleController;
use App\Http\Controllers\Api\Site\SectionController;
use App\Http\Controllers\Api\Site\SectionGroupController;
use App\Http\Controllers\Api\Site\SectionItemController;
use App\Http\Controllers\Api\Site\SectionTraceController;
use App\Http\Controllers\Api\User\Account\MfaController;
use App\Http\Controllers\Api\User\Account\SecurityEventController;
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

        // Compiled platform catalog for pickers — the successor to the FE's
        // hand-maintained platform mirror (plan §1). ETag = artefact digest.
        Route::get('/catalog/surfaces', [CatalogSurfacesController::class, 'index'])
            ->name('user.catalog.surfaces');

        // Link routing (plan §2) — the successor to POST /platforms/custom/links.
        // preview() writes nothing and is called on every keystroke pause, so it
        // gets its own tighter throttle than the shared authenticated bucket.
        Route::post('/routing/preview', [RoutingController::class, 'preview'])
            ->middleware('throttle:60,1')
            ->name('user.routing.preview');
        Route::post('/routing/links', [RoutingController::class, 'store'])
            ->name('user.routing.links');

        // Review-suggestions inbox: what the router recognised but would not
        // act on alone. The suggestion gate is only an improvement over silent
        // writes if the suggestions land somewhere a person can resolve them.
        Route::get('/routing/suggestions', [SuggestionsController::class, 'index'])
            ->name('user.routing.suggestions');
        Route::post('/routing/suggestions/{intent}/accept', [SuggestionsController::class, 'accept'])
            ->whereUuid('intent')->name('user.routing.suggestions.accept');
        Route::post('/routing/suggestions/{intent}/dismiss', [SuggestionsController::class, 'dismiss'])
            ->whereUuid('intent')->name('user.routing.suggestions.dismiss');

        // Connections with per-class is_primary, and the SetPrimarySheet's
        // write: one primary CTA per (user, routing_class), swapped in a
        // single transaction (plan §1).
        Route::get('/routing/connections', [ConnectionsController::class, 'index'])
            ->name('user.routing.connections');
        Route::post('/routing/connections/{connection}/primary', [ConnectionsController::class, 'setPrimary'])
            ->whereUuid('connection')->name('user.routing.connections.primary');

        // ── Surface model (plan §7): pages, sections, pins and excludes. ────
        // Nav is PAGES — one row per page, never per section — so pages own
        // key/label/visibility and sections own membership. Every write here
        // bumps the site's content revision; nothing here publishes.
        Route::get('/site/pages', [PageController::class, 'index'])->name('site.pages.index');
        Route::post('/site/pages', [PageController::class, 'store'])->name('site.pages.store');
        Route::patch('/site/pages/{page}', [PageController::class, 'update'])
            ->whereUuid('page')->name('site.pages.update');
        Route::delete('/site/pages/{page}', [PageController::class, 'destroy'])
            ->whereUuid('page')->name('site.pages.destroy');

        Route::get('/site/sections', [SectionController::class, 'index'])->name('site.sections.index');
        Route::post('/site/sections', [SectionController::class, 'store'])->name('site.sections.store');
        Route::get('/site/sections/{section}', [SectionController::class, 'show'])
            ->whereUuid('section')->name('site.sections.show');
        Route::patch('/site/sections/{section}', [SectionController::class, 'update'])
            ->whereUuid('section')->name('site.sections.update');
        Route::delete('/site/sections/{section}', [SectionController::class, 'destroy'])
            ->whereUuid('section')->name('site.sections.destroy');

        // Diagnostics (plan §7): why is this item on my page, and why isn't
        // that one? On demand — explaining every section on every build would
        // charge every user for a question most never ask.
        Route::get('/site/sections/{section}/trace', [SectionTraceController::class, 'show'])
            ->whereUuid('section')->name('site.sections.trace');

        // Pins and excludes — the ONLY two curation verbs (C4: an ingest run
        // may never write either).
        Route::get('/site/sections/{section}/items', [SectionItemController::class, 'index'])
            ->whereUuid('section')->name('site.sections.items.index');
        Route::put('/site/sections/{section}/items/{item}', [SectionItemController::class, 'upsert'])
            ->whereUuid(['section', 'item'])->name('site.sections.items.upsert');
        Route::delete('/site/sections/{section}/items/{item}', [SectionItemController::class, 'destroy'])
            ->whereUuid(['section', 'item'])->name('site.sections.items.destroy');

        // Group label/order overrides — needed because a group_by other than
        // category produces keys, not rows to hang a label on.
        // "Restyle from brand" (plan §13): preview diff → explicit apply with
        // an undo snapshot → undo. Never fires on its own.
        Route::get('/site/restyle/preview', [RestyleController::class, 'preview'])
            ->name('site.restyle.preview');
        Route::post('/site/restyle', [RestyleController::class, 'store'])
            ->name('site.restyle.apply');
        Route::post('/site/restyle/{restyle}/undo', [RestyleController::class, 'undo'])
            ->whereUuid('restyle')->name('site.restyle.undo');

        Route::get('/site/sections/{section}/groups', [SectionGroupController::class, 'index'])
            ->whereUuid('section')->name('site.sections.groups.index');
        Route::put('/site/sections/{section}/groups/{groupKey}', [SectionGroupController::class, 'upsert'])
            ->whereUuid('section')->name('site.sections.groups.upsert');
        Route::delete('/site/sections/{section}/groups/{groupKey}', [SectionGroupController::class, 'destroy'])
            ->whereUuid('section')->name('site.sections.groups.destroy');

        // ── Content library (plan §5/§6). ──────────────────────────────────
        // The schema itself: kind → facets → overridable columns + wire types,
        // plus the pool each kind belongs to and that pool's curation
        // permissions. Pure registry projection, no user state — it is here
        // rather than on a public route only because everything under
        // /api/content is authenticated and there is no reason to widen that.
        Route::get('/content/kinds', KindsController::class)
            ->name('content.kinds.index');

        // Per-column manual edits — the "edited" chip and its reset-to-source.
        Route::put('/content/items/{item}/overrides', [ManualOverrideController::class, 'upsert'])
            ->whereUuid('item')->name('content.items.overrides.upsert');
        Route::delete('/content/items/{item}/overrides/{facet}/{column}', [ManualOverrideController::class, 'destroy'])
            ->whereUuid('item')->name('content.items.overrides.destroy');

        // ── Pools (platforms-as-sources, 2026-08-05). ──────────────────────
        // One GET for the whole pool (selection + library + Latest tag); the
        // selection verbs write pins/excludes on the pool's section — the
        // only curation store. DELETE items/{item} is the C8 library delete.
        Route::get('/content/pools/{pool}', [PoolController::class, 'show'])
            ->name('content.pools.show');
        Route::post('/content/pools/{pool}/selection/{item}', [PoolController::class, 'select'])
            ->whereUuid('item')->name('content.pools.select');
        Route::delete('/content/pools/{pool}/selection/{item}', [PoolController::class, 'deselect'])
            ->whereUuid('item')->name('content.pools.deselect');
        Route::put('/content/pools/{pool}/order', [PoolController::class, 'reorder'])
            ->name('content.pools.reorder');
        Route::post('/content/pools/{pool}/items', [PoolItemCreateController::class, 'store'])
            ->name('content.pools.items.store');
        Route::delete('/content/items/{item}', [ItemController::class, 'destroy'])
            ->whereUuid('item')->name('content.items.destroy');

        // Hand-saved cross-platform links on a pool item (alternates only).
        Route::put('/content/items/{item}/links/{platform}', [ItemLinkController::class, 'upsert'])
            ->whereUuid('item')->name('content.items.links.upsert');
        Route::delete('/content/items/{item}/links/{platform}', [ItemLinkController::class, 'destroy'])
            ->whereUuid('item')->name('content.items.links.destroy');

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
        // `revocation.strict`: account deletion is the one irreversible action a
        // user can take. During a Redis outage the revocation blocklist cannot
        // be consulted, and everywhere else this app fails OPEN rather than lock
        // customers out — but "a revoked session destroyed the account" has no
        // undo, so here the trade inverts and the request 503s instead. Signed
        // off 2026-08-05; see the plan §3 for why PATCH /site did NOT get this.
        //
        // Ordering note: the textual order below is NOT the execution order.
        // IdempotencyKey is in bootstrap/app.php's priority list and
        // RequireVerifiedRevocation is not, so SortedMiddleware always runs
        // `idempotent` first. A replay of a stored Idempotency-Key therefore
        // re-serves the cached response without the strict gate running. That is
        // benign — a replay returns a stored response and never re-runs the
        // handler, and under a real Redis outage the idempotency Cache::get
        // throws and falls through to the gate anyway. Recorded so a future
        // reader does not "fix" the order and assume it changed anything.
        Route::prefix('me/deletion')->middleware(['idempotent', 'revocation.strict'])->group(function () {
            Route::post('/request', [UserAccountDeletionController::class, 'request'])
                ->middleware('throttle:3,60');
            Route::post('/confirm', [UserAccountDeletionController::class, 'confirm']);
            Route::post('/cancel', [UserAccountDeletionController::class, 'cancel'])
                ->withoutMiddleware([EnforcePendingDeletionReadOnly::class]);
        });

        // Data export — exempt from EnforcePendingDeletionReadOnly so a
        // professional in their grace period can still pull their data
        // (the whole point of GDPR portability). Rate-limited 1/24h.
        // `revocation.strict`: one call returns the entire GDPR PII bundle, so a
        // revoked session here is a complete data exfiltration rather than a
        // recoverable edit. Fails closed (503) when Redis cannot confirm the
        // session is live.
        Route::post('/me/data-export', [UserDataExportController::class, 'store'])
            ->withoutMiddleware([EnforcePendingDeletionReadOnly::class])
            ->middleware(['throttle:1,1440', 'revocation.strict']);
        // MFA self-service — fresh AAL2 enforced inside the controller (tighter
        // 60s window than session-level aal2). No require.aal2 middleware here.
        // `revocation.strict` because stripping a factor is a credential
        // mutation: it removes the victim's second factor for every future
        // login, not just this request.
        Route::prefix('account/mfa')->middleware('revocation.strict')->group(function () {
            Route::delete('/factors/{factorId}', [MfaController::class, 'destroy'])
                ->whereUuid('factorId')
                ->name('account.mfa.factors.destroy');
        });

        // Active session management — list / revoke / logout-everywhere-else.
        // Powered by VerifySupabaseJwt's session tracking and the Redis JTI
        // blocklist in TokenRevocationService. Bypasses pending-deletion gate
        // so a user mid-deletion can still log themselves out.
        Route::prefix('sessions')->withoutMiddleware([EnforcePendingDeletionReadOnly::class])->group(function () {
            // index and logout stay FAIL-OPEN on purpose. Listing your own
            // sessions is a read, and logging YOURSELF out is not damage — a
            // strict gate there would only stop a legitimate user signing out
            // during an outage, for no security gain.
            Route::get('/', [SessionController::class, 'index'])->name('sessions.index');
            Route::post('/logout', [SessionController::class, 'logout'])->name('sessions.logout')
                ->middleware('throttle:session-writes');
            // These two revoke OTHER sessions — the path by which a revoked
            // session could kick the real owner out. They are Redis writes, so
            // during an outage they fail regardless; `revocation.strict` costs
            // no availability and turns a raw 500 into an honest 503.
            Route::post('/logout-others', [SessionController::class, 'logoutOthers'])->name('sessions.logout-others')
                ->middleware(['throttle:session-writes', 'revocation.strict']);
            Route::delete('/{sessionId}', [SessionController::class, 'destroy'])->name('sessions.destroy')
                ->middleware(['throttle:session-writes', 'revocation.strict']);
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

        // Sections
        Route::get('/sections', [UserSectionBlockController::class, 'index']);
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
        Route::get('/enquiries', [UserEnquiryController::class, 'index']);
        Route::post('/enquiries/{id}/read', [UserEnquiryController::class, 'markRead'])->whereUuid('id');
        Route::post('/enquiries/{id}/replied', [UserEnquiryController::class, 'markReplied'])->whereUuid('id');
        Route::post('/enquiries/{id}/archive', [UserEnquiryController::class, 'archive'])->whereUuid('id');
        Route::post('/enquiries/{id}/restore', [UserEnquiryController::class, 'restore'])->whereUuid('id');
        Route::post('/enquiries/{id}/spam', [UserEnquiryController::class, 'markSpam'])->whereUuid('id');

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
        Route::delete('/design-media/{purpose}', [UserDesignMediaController::class, 'destroy'])
            ->where('purpose', '[a-z_]+');

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

        // Security-event pings (password change / MFA enrolment happen
        // client-side against Supabase; the dashboard tells us afterwards).
        Route::post('/me/security-events', [SecurityEventController::class, 'store']);

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
