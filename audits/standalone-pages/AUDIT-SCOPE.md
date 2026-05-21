# Standalone Pages — Audit Scope

**Goal:** audit everything required to launch standalone individual site pages
(individual dashboard + public `<handle>.partna.au` site page). Pilot ships
`account_type = individual` only — no brand, no affiliate, no Shopify, no
commerce. Stripe **subscriptions** are IN scope (individuals pay a plan);
Stripe Connect / payouts / commerce billing are OUT.

Date: 2026-05-21 · expanded with cross-cutting layers.

---

## PART A — FEATURE SURFACE

### 1. Account type system
- `app/Enums/AccountType.php`
- `app/Services/Accounts/` — AccountCapabilities, AccountCapabilitySet, AccountTypeTransitionService, AccountTypeDefaultsService
- `app/Listeners/Accounts/SyncNotificationPreferencesOnTransition.php`
- `app/Console/Commands/BackfillIndividualKvEntries.php`

### 2. Signup / bootstrap (individual path)
- `app/Http/Controllers/Api/PublicSite/BootstrapController.php`
- `app/Http/Controllers/Api/PublicSite/PublicSignupAvailabilityController.php`
- `app/Services/Professional/SiteProvisioningService.php`

### 3. Public individual site page rendering
- `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php`
- `app/Http/Controllers/Api/PublicSite/PublicSiteController.php` *(shared/legacy public path)*
- `app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php`
- `app/Http/Controllers/Api/PublicSite/PublicDocumentDownloadController.php`
- `app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php`
- `app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php`, `PublicEmailUnsubscribeController.php`, `PublicMarketingPreferenceController.php`
- `app/Http/Controllers/Api/PublicSite/QrCodeController.php`, `PublicConfigController.php`
- `app/Services/PublicSite/` — IndividualProfilePayloadBuilder, SitepageDataResolverService, PublicSiteResolver, ResolvesSiteFromRequest *(shared)*
- `app/Http/Resources/PublicSite/IndividualProfileResource.php`
- `app/Services/Cache/SiteCacheService.php` *(shared)*
- `app/Models/Views/PublicSitePayload.php`, `app/Models/Views/AllSiteData.php`

### 4. Cloudflare edge / routing
- `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`, `CloudflareCachePurgeJob.php`
- `app/Services/Cloudflare/`
- `cloudflare-worker/`

### 5. Dashboard sitepage editing (the "onepage" builder)
- `app/Http/Controllers/Api/Professional/SiteManagement/` — ProfessionalSiteController, ProfessionalSectionBlockController, ProfessionalLinkBlockController, ProfessionalServiceController, ProfessionalServiceCategoryController, ProfessionalThemeController, ProfessionalGalleryController, ProfessionalGoogleBusinessProfileController
- `app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php` — EXCLUDE `*BrandLogo*` / `*BrandPlaceholder*` methods
- `app/Http/Controllers/Api/Professional/Account/ProfessionalDocumentController.php`
- `app/Http/Controllers/Api/Professional/Site/HandleReclaimController.php`
- `app/Services/Site/` — UpdateSiteAction, SocialLinkNormalizer, ReclaimHandleAction
- `app/Services/Media/` — ImageVariantService, VideoVariantService, MediaDiskResolver, exceptions (EXCLUDE BrandDesignMediaService)

### 6. Site analytics (view stats — NOT commerce)
- `app/Http/Controllers/Api/PublicSite/AnalyticsController.php` — `pageview`, `click`, `sectionSeen` only (EXCLUDE `cartEvent`)
- `app/Http/Controllers/Api/Professional/Analytics/ProfessionalAnalyticsController.php` — `summary` only
- `app/Http/Controllers/Api/Professional/Analytics/StatsController.php` — view-stats portion
- `app/Services/Analytics/AnalyticsService.php`

### 7. Notifications (individual-relevant)
- `app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php`
- `app/Http/Controllers/Api/Professional/Notifications/` — NotificationController, NotificationEmailPreferenceController, ConfirmationPreferenceController, ProfessionalEmailSubscriptionController

### 8. Subscriptions (Stripe — IN scope)
- `app/Services/Stripe/StripeBillingService.php`
- `app/Services/Billing/` — Create/Cancel/Resume/ChangeProfessionalPlan actions
- `app/Http/Controllers/Api/Professional/Subscription/` — SubscriptionController, PlanController
- `app/Models/Billing/Subscription.php`, `Plan.php`
- Stripe subscription webhook handler

### 9. Account management
- `app/Http/Controllers/Api/Professional/Account/` — ProfessionalController, ProfessionalAccountDeletionController, ProfessionalDataExportController, MfaController

---

## PART B — CROSS-CUTTING LAYERS (added on expansion)

### 10. Authorization — Policies
- `app/Policies/BasePolicy.php`
- `app/Policies/SitePolicy.php`, `ServicePolicy.php`, `ProfessionalSelfPolicy.php`, `SubscriptionPolicy.php`, `NotificationPolicy.php`, `CustomerPolicy.php`
- `app/Providers/AppServiceProvider.php` — `Gate::policy()` + observer registrations
- `tests/Feature/Security/PolicyCoverageTest.php`

### 11. Observers (the trigger mechanism for KV sync / cache purge)
- `app/Observers/Core/` — SiteObserver, BlockObserver, SiteMediaObserver, ServiceObserver, ServiceCategoryObserver, ProfessionalObserver
- `app/Observers/Concerns/LogsWithRequestContext.php`

### 12. Middleware (route guards on the dashboard + public routes)
- `app/Http/Middleware/Auth/` — VerifySupabaseJwt, RequireEmailVerified, RequireAal2
- `app/Http/Middleware/Context/` — LoadCurrentProfessional, EnforcePendingDeletionReadOnly
- `app/Http/Middleware/` — FeatureGate, RequirePlan, AddETagHeaders, AddPublicCacheHeaders, SecureHeaders, VerifyTurnstileCaptcha
- Throttle / rate-limiter definitions (`RouteServiceProvider` / `AppServiceProvider`) — `public-profile`, `public-analytics`, `authenticated`
- EXCLUDE: EnsureBrandAccount, EnsureAffiliateAccount, BrandFundingGate, VerifyShopifySessionToken, VerifyHydrogenApiKey, staff middleware

### 13. Form Requests (input validation)
- `app/Http/Requests/Api/BootstrapRequest.php`
- `app/Http/Requests/Api/Professional/Site/` — UpdateSiteRequest, UpsertSectionBlockRequest, ReorderBlocksRequest, Store/Update/Destroy/IndexLinkBlockRequest
- `app/Http/Requests/Api/Professional/Services/` — all (Store/Update/Reorder service + category)
- `app/Http/Requests/Api/Professional/Documents/` — Upload/UpdateDocumentRequest
- `app/Http/Requests/Api/Professional/ImageGallery/` — Update/ReorderGalleryImageRequest
- `app/Http/Requests/Api/Professional/Uploads/` — UploadImageRequest, ReorderPoolImagesRequest (EXCLUDE brand logo/placeholder requests)
- `app/Http/Requests/Api/PublicSite/` — PublicSiteShowRequest, Analytics/SectionSeenRequest
- `app/Http/Requests/Concerns/ResolvesPublicSiteSubdomain.php`

### 14. Models
- `app/Models/Core/Site/` — Site, Block, Enquiry, SiteMedia, Theme, ProfessionalHandleAlias, SiteSubdomainAlias
- `app/Models/Core/Professional/` — Professional, Service, ServiceCategory, ProfessionalConfirmationPreference
- `app/Models/Core/` — MediaVariant, HandleChangeLog
- `app/Models/Core/Notifications/` — Notification, NotificationReceipt, NotificationEmailPreference, NotificationEmailPolicy, EmailSubscription
- `app/Models/Core/Waitlist/WaitlistSignup.php`
- `app/Models/BaseModel.php`

### 15. Resources (API response shape)
- `app/Http/Resources/` — ProfessionalDashboardResource, ProfessionalResource, ProfessionalPublicResource, ProfessionalEmailSubscriptionResource
- `app/Http/Resources/Professional/Analytics/`
- Any site / section / service / link / theme resources used by the SiteManagement controllers

### 16. Media processing jobs
- `app/Jobs/ProcessImageVariantsJob.php`, `ProcessVideoVariantsJob.php`, `DeleteMediaArtifactsJob.php`
- `app/Jobs/Streaming/` (HLS for gallery video)
- `app/Jobs/Cache/`

### 17. Database — schema, RLS, triggers, migrations
- `supabase/migrations/` — `2026052000000*` account_type set, `20260521000000_add_account_type_to_all_site_data_view.sql`, `20260519100000_handle_alias_lifecycle.sql`, `20260519110000_backfill_alias_expiry.sql`, `20260525000000_rls_policy_sweep.sql`, `20260420200000_add_rls_to_remaining_tables.sql`, `2026052300000*` handle_change_log FK migrations, `site_media` pool/purpose/caption migrations, `20260422040000_create_site_enquiries.sql`
- RLS policies + `search_path` on schemas `core`, `site`
- `app/Console/Commands/` — `handles:prune-expired-aliases`

### 18. Config & routes
- `config/partna.php` — individual account-type defaults, `individual_waitlist_enabled`, `public_profile.*`, `handle.*`
- `config/sidest.php` — feature flags touching individual / standalone pages
- `routes/api.php` (public + webhook + analytics sections), `routes/api/professional.php`

---

## OUT OF SCOPE
Affiliate, Brand, Store, Shopify, **Square**, **Fresha**, Booking, commerce
analytics (Affiliate/Brand CommerceAnalyticsController, projections, cart
events), payouts/commissions, Stripe Connect, brand logo/placeholder/gallery
uploads, brand-only middleware, embedded Shopify auth, staff routes.

---

## NOTE — audit ≠ completeness
An audit finds defects in code that **exists**. It will not surface a
**missing** backend endpoint. Before/alongside the audit, run a completeness
check: walk the standalone-page user journeys end to end and confirm every
required endpoint is built. The Astro app (`partna-pages`) and `@partna/themes`
package are separate deliverables not covered by this backend audit.
