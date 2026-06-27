# STRIP-LEDGER — Standalone Pages Backend Strip

> **Authoritative.** This ledger supersedes every inline file list in
> `docs/superpowers/plans/2026-05-21-standalone-pages-backend-strip.md`.
> Built by mechanical enumeration (`git ls-files` per subtree) on 2026-05-22 —
> complete by construction. Method: `LEDGER-BUILD-PROMPT.md`.
> **Review corrections applied 2026-05-22** (`LEDGER-REVIEW-RESULT.md`, verdict
> *ready with listed corrections*) — all folded in below; markers read
> "(review correction 2026-05-22)".

## How to use

- Four classifications: **DELETE** (whole file removed), **EDIT** (file
  survives, named symbols severed), **KEEP** (survives untouched), **DB**
  (database objects).
- **Section 0 (OPEN DECISIONS) blocks execution.** ~18 product decisions
  determine the classification of dozens of files. Resolve them first, then
  finalise the affected rows.
- DELETE is grouped by domain to drive plan Task 3 (3a Shopify … 3h worker).
- EDIT carries per-file sever specs — the riskiest work; line numbers are
  approximate, verify before cutting.

## Summary counts (pre-decision; ~40 files float on Section 0)

| Subtree | DELETE | EDIT | KEEP |
|---|---|---|---|
| Controllers | 74 | 13 | 47 |
| Middleware/Requests/Resources | 21 | 14 | 46 |
| Services | 56 | 14 | 19 |
| Jobs + Mail | 47 | 5 | 22 |
| Models | 21 | 7 | 25 |
| Observers/Policies/Listeners/Events/Enums/Exceptions | 30 | 6 | 16 |
| Providers/Console/bootstrap | ~14 | 4 | ~12 |
| routes + config | 0 | ~12 | ~10 |
| factories/emails/worker | 16 | 9 | 19 |
| tests | ~247 | ~52 | ~128 |

---

## Section 0 — DECISIONS (RESOLVED 2026-05-22)

All 18 resolved with Josh. Resolution table is authoritative; the numbered
rationale below is kept for context. **Propagation overrides** (changes vs the
pre-decision classifications in Sections 2–5) follow the table.

| # | Decision | Resolution |
|---|---|---|
| 1 | Service / ServiceCategory | **KEEP** display-only — strip only `square_*`/`fresha_*` columns |
| 2 | Customer CRM | **KEEP** — strip only the Shopify/Square source fields |
| 3 | Manual booking link | **KEEP** — plain external-URL link, manual mode only |
| 4 | ProfessionalIntegration | **DROP** — model, IntegrationPolicy, observer, StaffIntegrationController, table |
| 5 | GDPR data export | **KEEP** |
| 6 | Newsletter / email subscribers | **KEEP** |
| 7 | Invite notifications | **DROP** — InviteNotificationMail, blade, `invites` gate-map + mailables key |
| 8 | Waitlist | **Individual-only** — strip brand fields + the `brand` type |
| 9 | professional_type | **DROP fully** — request field, model, DB column |
| 10 | account_type column | **KEEP** — constant `individual`; resources keep emitting it |
| 11 | cart_events / CartEvent | **DROP** — model, request, route, table |
| 12 | core.gdpr_requests | **DROP** table + GdprRequest model. **GdprPolicy stays KEEP** (still serves DataExportAudit — review correction 2026-05-22) |
| 13 | Wallet | **DROP** — manageWallet gate, wallet_currency_switch_audit, payout_method column |
| 14 | Rate limiters | **KEEP `webhooks`**; DROP `shopify-webhooks` + the 5 other dropped-domain limiters |
| 15 | GBP / brand-design staff | StaffGoogleBusinessProfileController **KEEP**; StaffBrandDesignController **DELETE** |
| 16 | app_backend / RLS | **KEEP BYPASSRLS** |
| 17 | account_type_defaults.affiliate + brand_signup_code config | **DROP** — no partner path survives |
| 18 | notifications.mailables.integrations | **DROP** — all integrations removed |

### Propagation — overrides to Sections 2–5

- **d3 (booking link KEEP) reverses several Section 2 EDIT specs:**
  `ProfessionalSiteController::updateBookingSettings()` is **KEEP, not severed**;
  `UpdateSiteRequest`/`StaffUpdateSiteRequest` **KEEP** the `booking_mode`/
  `manual_booking_url` validation; `SitepageDataResolverService`,
  `IndividualProfilePayloadBuilder`, `SectionVisibilityService` are **KEEP**
  (the manual-booking envelope survives); the `booking` section type stays in
  config (manual mode only). Only Square/Fresha *integration* booking is gone.
- **d4 (ProfessionalIntegration DROP):** `IntegrationPolicy` → **DELETE** (not the
  conditional EDIT in 2.6); `ProfessionalIntegrationObserver` → **DELETE** (not
  EDIT in 2.6); `StaffIntegrationController` → **DELETE**; `AccountDeletionService`
  EDIT — *remove* the ProfessionalIntegration teardown block entirely (table is
  gone). `core.professional_integrations` → **DROP**.
- **d2 (Customer KEEP):** `Customer`, `CustomerPolicy`, `CustomerObserver`,
  `ProfessionalCustomerController`, `StaffCustomerManagementController`, customer
  requests/resources, `core.customers` all **KEEP**. `Jobs/Notifications/
  SyncCustomerMarketingOptInJob` → reclassify **KEEP** (was DELETE). `ContactCapture
  Service` EDIT still strips the `square_booking`/`shopify_order` sources.
- **d1 (Services KEEP):** all `ProfessionalService*`/`StaffService*` controllers,
  `ServicePolicy`, `ServiceObserver`, `ServiceCategoryObserver`, service tests →
  **KEEP**. `Service` model EDIT (square/fresha columns only). `site.services` +
  `site.service_categories` → **KEEP**.
- **d11 (cart_events DROP):** `CartEvent` → **DELETE** (not EDIT in 2.6);
  `CartEventRequest` → **DELETE**; remove `/public/analytics/cart-events`;
  `analytics.cart_events` → **DROP**.
- **d12 (gdpr_requests DROP):** `GdprRequest` model → **DELETE**. **`GdprPolicy`
  stays KEEP** (review correction 2026-05-22) — it is a generic owner-check still
  registered for `DataExportAudit` (KEEP); deleting it orphans that model and
  fails `PolicyCoverageTest`. Sever only the `Gate::policy(GdprRequest::class,…)`
  reg at `AppServiceProvider` l.108; the `DataExportAudit` reg at l.109 SURVIVES.
  `GdprPolicy` therefore STAYS in the Section 1 PRESERVE manifest. The data-export
  feature (d5) survives via `DataExportService` + `core.data_export_audit`. The
  `Exceptions/Gdpr/*` for data export stay KEEP.
- **d13 (Wallet DROP):** `WalletCurrencySwitchAudit` model → **DELETE**; remove
  `Gate::define('manageWallet')`; drop `core.wallet_currency_switch_audit` +
  `core.professionals.payout_method`; remove the WalletCurrencySwitchAudit
  `Gate::policy` reg.
- **d15:** `StaffBrandDesignController` → **DELETE** (3g). `StaffGoogleBusinessProfile
  Controller` → **KEEP** — execution note: verify GBP read/write is not
  `isBrand()`-gated before signing off.
- **d18:** `IntegrationNotificationMail` + `emails/notifications/integrations.blade.php`
  → **DELETE** (3g).

---

### Rationale (numbered, for context)

Each decision flips the classification of multiple files. Numbered for reference.

1. **Do `Service` / `ServiceCategory` survive?** Booking was dropped (memory
   2026-05-11). If services remain as a *display-only* catalog on the
   individual page → KEEP the models/policy/observer/controllers, EDIT only the
   `square_*`/`fresha_*` columns out. If services are gone entirely → DELETE
   `Service`, `ServiceCategory`, `ServicePolicy`, `ServiceObserver`,
   `ServiceCategoryObserver`, all `ProfessionalServiceController*` /
   `StaffService*` controllers + requests + ~20 tests, and DROP `site.services`
   + `site.service_categories`. **Recommendation: KEEP display-only.**
2. **Does `Customer` survive?** Built for the booking/CRM path. If individuals
   keep a contacts/CRM concept → KEEP `Customer`, `CustomerPolicy`,
   `CustomerObserver`, `ProfessionalCustomerController`,
   `StaffCustomerManagementController`, customer requests/resources, `core.customers`.
   If not → DELETE all of those + DROP the table. Note `analytics.lead_submissions`
   has an FK to `core.customers`.
3. **Does the manual booking-URL link survive** (a plain URL link block, distinct
   from Square/Fresha smart-booking which is already dropped)? Drives
   `SitepageDataResolverService`, `IndividualProfilePayloadBuilder`,
   `SectionVisibilityService`, the `booking` key in `IndividualProfileResource`,
   `booking_mode`/`manual_booking_url` config + `UpdateSiteRequest` validation.
4. **Does `ProfessionalIntegration` survive at all?** Shopify, Square, Fresha are
   all dropped — the model/table may be fully vestigial. If nothing survives →
   DELETE the model, `IntegrationPolicy`, `ProfessionalIntegrationObserver`,
   `StaffIntegrationController`, DROP `core.professional_integrations`. **AccountDeletionService
   currently deletes ProfessionalIntegration rows on hard-delete — see EDIT spec.**
5. **Does GDPR data export survive for individuals?** It should (Articles 15/20).
   If yes → KEEP `DataExportService`, `ExportProfessionalDataJob`, the GDPR
   config sub-keys, `RequestDataExportRequest`, `RequestStaffDataExportRequest`.
6. **Do email subscribers / newsletter survive for individuals?** Drives
   `ProfessionalEmailSubscriptionResource`, `StaffEmailSubscriptionResource`,
   `StaffEmailSubscriberController`, `EmailSubscription`, `notifications.email_subscriptions`.
7. **Does any invite concept survive for individuals?** Brand-affiliate invites
   are dropped. If no invites at all → DELETE `InviteNotificationMail`,
   `emails/notifications/invites.blade.php`, the `invites` key in
   `CAPABILITY_GATE_MAP` + `notifications.mailables`.
8. **Waitlist: multi-type or individual-only?** Drives `PublicWaitlistSignupRequest`
   brand fields, `WaitlistSignup` model columns, `waitlist.types` config.
9. **`professional_type` in `BootstrapRequest`** — fully dropped, or kept as a
   forwarded legacy field for current frontend clients? (Frontend contract.)
10. **`account_type` column** — plan keeps it as the surviving source of truth
    even though it is always `'individual'`. Confirm. Resources keep emitting it.
11. **`analytics.cart_events` / `CartEvent`** — no Hydrogen storefronts in
    standalone mode. **Recommendation: DROP table + DELETE model + remove the
    `/public/analytics/cart-events` route + `CartEventRequest`.**
12. **`core.gdpr_requests`** — the table is Shopify-shaped (`shop_domain`,
    `shopify_shop_id`, topic CHECK = Shopify topics). Does standalone keep its
    own GDPR-request tracking? If yes → EDIT (drop Shopify columns, repoint
    topic constraint). If no → DROP table + DELETE `GdprRequest`, `GdprPolicy`.
13. **Wallet concept** — `manageWallet` gate, `WalletCurrencySwitchAudit`,
    `core.professionals.payout_method`. If wallet = Stripe payout wallet → drop
    all. If a standalone balance survives → keep.
14. **`shopify-webhooks` rate limiter** — DROP unless any Shopify webhook route
    survives (none should). **`webhooks` rate limiter — KEEP** (it guards the
    surviving Supabase email hook + MFA verification webhook).
15. **`StaffGoogleBusinessProfileController` / `StaffBrandDesignController`** —
    GBP appears individual-applicable (KEEP); brand design is Shopify-coupled
    (DELETE). Confirm GBP is not `isBrand()`-gated.
16. **DB: `BYPASSRLS`** — migrations contradict each other. **Recommendation:
    keep `ALTER ROLE app_backend BYPASSRLS`** (Option A) — revoking it requires
    auditing an explicit `app_backend` policy onto every KEEP RLS table, and
    several (`analytics.site_visits`, `site_metrics_*`) currently have none.
17. **`account_type_defaults.affiliate` overlay + `brand_signup_code` config** —
    remove only if the partner path is fully dropped.
18. **`notifications.mailables.integrations`** — was this category ever used for
    non-Shopify/Square/Fresha integrations? If not → DELETE the blade + key.

---

## Section 1 — PRESERVE manifest (hardening that must not break)

These survive the strip; any EDIT touching them must not weaken them. STOP and
re-plan if a step seems to require gutting protective logic.

- **Caching SWR core:** `CacheLockService`, `rememberLocked`, `JitteredTtl`,
  single-flight + stale-key path inside `SiteCacheService::getPublicSitePayload()`.
- **Auth/MFA:** `VerifySupabaseJwt`, `RequireAal2`, `BasePolicy::requiresAal2/
  requiresFreshAal2`, the `require.aal2` alias in `bootstrap/app.php`,
  `config/partna.php` `mfa` block, `core.auth_factor_events`.
- **HTTP hardening:** `AddETagHeaders`, `SecureHeaders`, `trustProxies`,
  `AddPublicCacheHeaders` (EDIT-trimmed, not removed).
- **Authorization:** `BasePolicy`, `SitePolicy`, `GdprPolicy`, `NotificationPolicy`,
  `ServicePolicy`, `ProfessionalSelfPolicy`, `FeatureFlagPolicy`, `PartnaStaffPolicy`,
  `PolicyCoverageTest`, the CI inline-403 guard.
- **Notification durability:** `SendEnquiryNotificationJob` idempotency
  (`lockForUpdate` + `email_sent_at` + retry/backoff), `NotificationPublisher`
  dedup.
- **GDPR/deletion:** `AccountDeletionService` (EDIT-trimmed), `EnforcePending
  DeletionReadOnly`, deletion audit trail (`core.professional_deletion_audit`).
- **Rate limiters kept:** `health-check`, `public-profile`, `public-site`,
  `analytics`, `analytics-click`, `leads`, `waitlist`, `authenticated`, `staff`,
  `bootstrap`, **`webhooks`** (decision 14).
- **DB views:** `site.public_site_payload` (clean, port as-is).

---

## Section 2 — EDIT (sever specs)

### 2.1 Boot path — CRITICAL (do these in Task 2 before any Task 3 deletion)

**`bootstrap/app.php`** — parsed on every request and every artisan command;
deleting a referenced class before this file is edited bricks `php artisan`.
Sever lines: 8 (`use VerifyHydrogenApiKey`), 10 (`use VerifyShopifySessionToken`),
13 (`use BrandFundingGate`), 18 (`use RequirePlan`), 78–81 (the
`prependToPriorityList(VerifyShopifySessionToken)` block — review correction
2026-05-22: this block is l.78–81, NOT 74–81; the `VerifySupabaseJwt`
pin at 67–70 SURVIVES), 91 (`'plan'`), 92 (`'hydrogen.key'`), 93
(`'shopify.session'`), 97 (`'brand-funding-gate'`), 98 (`'brand.only'`),
99 (`'affiliate.only'`). **KEEP line 100 `'require.aal2'`.**

**`app/Providers/AppServiceProvider.php`** — sever: top-level `use` for
`ProfessionalIntegration` (l.7) and `IntegrationPolicy` (l.8); Shopify singletons
(l.36–52); `\Stripe\StripeClient` singleton (l.63–68); `Gate::policy` regs at
l.76, 84–92, 95–97, 100, 102, 108, 110 (ProfessionalIntegration, all Commission/
Order/Brand*/Subscription/AffiliateProductSelection; **review correction
2026-05-22: l.100 `WalletCurrencySwitchAudit` and l.108 `GdprRequest` regs were
missing — add both. KEEP l.109 `DataExportAudit` reg — `GdprPolicy` survives**);
`Gate::define` `managePaymentMethod`
(l.119), `startConnect` (l.121), and `manageWallet` (l.120, decision 13);
`SHOPIFY_API_VERSION` guard (l.138–140); `STRIPE_SECRET_KEY` guard (l.164–166);
rate limiters `booking-checkout`, `affiliate-writes`, `brand-catalog-writes`,
`hydrogen-internal`, `embedded-by-shop`, `plans`, `shopify-webhooks` (decision 14).
**KEEP** the JWKS/JWT/Nightwatch guards and the 11 kept rate limiters.

**`app/Providers/EventServiceProvider.php`** — sever the entire `$listen`
`AccountTypeTransitionEvent` block (l.46–56) + its 5 listener imports (l.6–10) +
event import (l.5); `observe()` regs at l.66–72, 74 (BrandAffiliateInvite,
BrandPartnerLink, CommissionMovement, CommissionPayout, ProfessionalIntegration,
BrandProfile, BrandStoreSettings, AffiliateProductSelection) + their `use`
imports (l.11–17, 21, 26–27, 29–33). **Review correction 2026-05-22: the import
range is NOT l.26–34 — l.28 `BlockObserver` and l.34 `CustomerObserver` are
survivors; do NOT remove them.** **KEEP** Professional/Site/Block/SiteMedia
observe regs (Service/ServiceCategory/Customer per decisions 1, 2).

**`bootstrap/providers.php`** — no line deletions; all 3 providers survive.

### 2.2 Controllers (19 — review correction 2026-05-22: +6)

| File | Sever |
|---|---|
| `Api/PublicSite/BootstrapController` | All brand/partner/affiliate signup branches, `BrandAffiliateInviteService`/`BrandPartnerLinkService`/`BrandSignupCodeService`/`ShopifySetupTokenService`/`ShopProfileAutoFillService` injections, `syncSiteBrandPartnerSettings()` + 4 call-sites, brand-signup-code rate-limit block, the brand path inside the create transaction. Collapse to individual-only signup. |
| `Api/PublicSite/IndividualProfileController` | Replace `isBrand()/isPartner()` guard with `account_type !== Individual` (or drop entirely once only individuals exist). |
| `Api/Professional/Analytics/ProfessionalAnalyticsController` | **Plan misclassified this KEEP.** Sever `commerceAggregates()`, `commerceCharts()`, `topProducts()`, `shopSummary()`, `checkoutSessions()`; remove all commerce keys from `summary()` response + `GET /analytics/shop` route. Keep the site-visit/link-click path. |
| `Api/Professional/Account/ProfessionalController` | `BrandStatus`/`BrandProfile` imports, `$primaryBrandStatus`/`$primaryBrandName`/`$brandPartnerId` block in `show()`, `primary_brand_*` response keys, `professional_type` dual-write in `update()`. |
| `Api/Professional/Account/ProfessionalDocumentController` | The `isBrand()` brand-exclusion guard block (~l.51–53). |
| `Api/Professional/SiteManagement/ProfessionalSectionBlockController` | `AccountTypeDefaultsService` import + injection + call-sites; use the single user default. **Plus the three direct `professional_type` reads at l.35, l.118, l.307** (review correction 2026-05-22). |
| `Api/Professional/SiteManagement/ProfessionalSiteController` | All 3 `enrichSiteWithBrandPartnerRadius()` calls. **Review correction 2026-05-22: `updateBookingSettings()` is KEEP per d3 — do NOT sever it.** |
| `Api/Professional/SiteManagement/ProfessionalLinkBlockController` | **Review correction 2026-05-22 — was misclassified KEEP.** Reads `professional_type` (dropped, d9) + `account_type_defaults.{$type}` (dropped, d17) at l.47. Drop the `professional_type` fallback; hard-code the individual `custom_links_allowed` value. |
| `Api/Professional/SiteManagement/ProfessionalServiceController` | **Review correction 2026-05-22 — was misclassified KEEP.** Queries `square_variation_id` (dropped from `site.services`, 5.1) at l.53–55. Remove the `?source=` filter logic + the `square`/`smart` source arms; collapse to unconditional query. |
| `Api/PublicSite/AnalyticsController` | **Review correction 2026-05-22 — was KEEP with no Section 2 row.** Sever `AnalyticsCacheService` import + injection (l.18,34), the `CartEvent`/`CartEventRequest` usage (l.9,13,200,217 — d11), and `$this->analyticsCache->invalidateAnalytics()` (l.340). Keep the site-visit/link-click write path. |
| `Api/PublicSite/IndividualProfileController` | **Review correction 2026-05-22 — flagged KEEP; uses `AccountType` (l.5, l.83). With the `AccountType`-stub decision (3-account-type note) this needs NO change — the `Individual` case survives. Listed only so the executor confirms it, not severs it.** |
| `Api/Staff/StaffSite/StaffSiteController` | **Review correction 2026-05-22 — was KEEP.** Reads + emits `professional_type` (l.39, l.87) from the `all_site_data` view (column dropped, 5.5). Remove the `professional_type` response key from both arrays. |
| `Api/Staff/StaffSite/StaffAnalyticsController` | **Review correction 2026-05-22 — was KEEP.** Reads + emits `professional_type` (l.166). Remove that response key. |
| `Api/Professional/Uploads/ProfessionalUploadController` | `BrandDesignMediaService`/`BrandStatusService` injections; all 6 brand-only upload methods + `storeBrandDesignImage()`; brand upload-request imports. |
| `Api/Staff/.../StaffProfessionalController` | `professional_type` query filter, show-response key, update dual-write. |
| `Api/Staff/.../StaffSiteManagementController` | `enrichSiteWithBrandPartnerRadius()` call. |
| `Api/Staff/StaffSite/StaffStatsController` | `billing.subscriptions` query + `subscriptions` key; `commerce.commission_movements` query + `commissions` key. |
| `Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController` | `OverrideScope::forBrand` branch; `forgetBrand()` call (decision 17). |
| `Api/Professional/Notifications/NotificationEmailPreferenceController` | Brand/affiliate-only notification categories from the `AccountCapabilities` filter (keep the `for()` call). |

### 2.3 Middleware/Requests/Resources (15 — review correction 2026-05-22: +1)

| File | Sever |
|---|---|
| `Middleware/AddPublicCacheHeaders` | Remove `brand-affiliate-invites` from `NO_STORE`; remove booking/store/shopify-storefront prefixes from `CACHEABLE`. |
| `Middleware/AddETagHeaders` | Comment-only: drop "Hydrogen" reference (l.9). |
| `Middleware/Context/LoadCurrentProfessional` | `professional_type` Nightwatch context tag (l.84–87) + dual-write comment. |
| `Requests/Api/BootstrapRequest` | Invite/brand-signup-code/Shopify-setup-token/`industries` rules + `prepareForValidation` merges + invite `failedValidation` override; `account_type` rule → `individual` only. **Review correction 2026-05-22: also sever the `professional_type` rule (l.121–125), the `NormalizesProfessionalType` trait use (l.20), and the `prepareForValidation` professional_type-normalization branch (l.200–202)** (decision 9). |
| `Requests/.../Site/UpdateSiteRequest` | `brand_partner`/`brandPartner`/`additional_brand_partners` prohibited rules + messages; `brand_logo_*` design-media rules; `selected_products` rule. **Review correction 2026-05-22: `booking_mode`/`manual_booking_url` rules are KEEP per d3 — do NOT sever them.** |
| `Requests/.../Staff/.../StaffUpdateSiteRequest` | Same as `UpdateSiteRequest` (booking rules KEEP per d3). |
| `Resources/FeatureFlagOverrideResource` | **Review correction 2026-05-22 — was misclassified KEEP.** Emits `brand_id` (l.15), column dropped from `core.feature_flag_overrides` (5.1). One-line EDIT: remove the field. |
| `Requests/.../UpdateProfessionalRequest` + `StaffUpdateProfessionalRequest` | `NormalizesProfessionalType` trait use + import; `professional_type` rule + `prepareForValidation` branch. |
| `Requests/.../PublicWaitlistSignupRequest` | `is_brand_partner_or_ambassador`, `number_of_affiliates_ambassadors`, brand `required_if` conditionals (decision 8). |
| `Requests/BaseFormRequest` | `allowedRedirectRule()` (only Stripe requests used it). |
| `Resources/ProfessionalResource` + `ProfessionalDashboardResource` + `ProfessionalStaffResource` | `professional_type` field; `AccountCapabilities` import + `stripe_connect_status` `when()` block. `ProfessionalDashboardResource` also: `square_connected`/`square_merchant_id` `whenLoaded` blocks. **KEEP `account_type`.** |
| `Resources/ProfessionalPublicResource` | `professional_type` field. KEEP `account_type`. |

### 2.4 Services (13 — review correction 2026-05-22: −1, the d3 booking row removed)

| File | Sever |
|---|---|
| `Services/Accounts/AccountCapabilities` | Remove `brandCapabilities()`/`partnerCapabilities()` + Brand/Partner match arms; `for()` always returns the individual set. **Review correction 2026-05-22: also remove the `use App\Enums\AccountType` import (or keep the `AccountType` stub — see 3-account-type note) and collapse the surviving `AccountType::Individual` arm to `default`.** |
| `Services/Accounts/AccountCapabilitySet` | No code deletion — retain all properties as user-constant values so dynamic readers don't fatal; `worker_kv_type` default → `'user'`. Document brand/partner booleans as always-false. |
| `Services/Cache/CacheKeyGenerator` | Delete the ~24 brand/affiliate/Hydrogen/booking/commerce key methods (`hydrogenAffiliate`, `brandActiveCatalog`, `bookingAnalytics`, `affiliateProjections`, `embeddedSetupOverview`, …); keep the professional/site/services/images namespace. |
| `Services/Cache/ProfessionalCacheService` | `getBrandStoreSettings()`, `getBrandPartnerStatus()`, `squareIntegration` eager-load, `brandPartnerStatus` cache-bust, `professional_type` in `toPayload()`. |
| `Services/Cache/SiteCacheService` | `applyBrandImageFallbacks()`, `enrichSiteWithBrandPartnerRadius()`, `forgetHydrogenAffiliate()`, `forgetHydrogenAffiliatesByBrand()`, `forgetHydrogenBrandConfig()`, `forgetHydrogenAffiliateProducts()`, `forgetBrandDesign()` (l.836 — review correction 2026-05-22), `withStorePayload()`, `ensureProfessionalType()`, `resolveBrandPartnerEnrichmentData()`, `safeApplyBrandImageFallbacks()` (the safe-wrapper, l.400 — review correction 2026-05-22) + helpers — **and every call-site** incl. inside `buildPayloadFromDb()`, the cache-heal branch of `getPublicSitePayload()` (the 2nd `safeWithStorePayload`), and `invalidateSite()`'s brand branch incl. the `InvalidateBrandAffiliatesCacheJob::dispatch` at ~l.1142. **Do not touch the SWR/single-flight core.** |
| `Services/Diagnostics/EnvCheckService` | `Shopify`/`Stripe`/`Square` blocks from the REQUIRED/RECOMMENDED maps. |
| `Services/FeatureFlags/FeatureFlagService` | `?BrandProfile $brand` param from all methods; brand-override DB read; brand cache flush. |
| `Services/FeatureFlags/OverrideScope` | `brandId` property + `forBrand()` factory. |
| `Services/Media/BrandDesignMediaService` | `upsertLogoFromBytes()` (Shopify CDN path); keep the upload-file/placeholder methods (used for individual design media). |
| `Services/Notifications/NotificationPublisher` | Comment-only (brand-affiliate-invite reference). |
| `Services/Professional/AccountDeletionService` | `StripeBillingService` import + 2 call-sites; `commerce.commission_payouts` query in `checkObligations()` (unguarded — would 500 every deletion); `brand.brand_profiles` write in `pseudonymiseAccountPii()`; `ProfessionalIntegration` teardown call (decision 4). |
| `Services/Professional/DataExport/DataExportPayloadBuilder` | `billingSubscription()`, `streamCommissionMovements()`, `streamCommissionPayouts()`, `streamBookingEvents()` + call-sites; brand profile/partner-links section in `profile()`; corresponding keys in `build()`/`stream()`. |
| `Services/Professional/SiteProvisioningService` | `ensureFreeSubscription()` method + `Billing\Plan`/`Billing\Subscription` imports (called by `BootstrapController` on every signup). |
| ~~`SitepageDataResolverService` + `IndividualProfilePayloadBuilder` + `SectionVisibilityService`~~ | **Review correction 2026-05-22 — row removed. These three are KEEP per d3** (the manual-booking envelope survives); the prior "conditional EDIT on decision 3" text was stale. No sever. |
| `Services/Site/UpdateSiteAction` | `forgetBrandDesign()` call + `DB::afterCommit` wrapper; comments. |
| `Services/Customers/ContactCaptureService` | **Review correction 2026-05-22: there are no `source='square_booking'`/`'shopify_order'` conditional branches — only a comment at l.39. Remove that comment; no logic change** (decision 2). |

### 2.5 Jobs (5)

| File | Sever |
|---|---|
| `Jobs/Cloudflare/SyncSubdomainToKvJob` | `BrandPartnerLink` import; `isBrand()` branch (l.61 — review correction 2026-05-22: file is 129 lines, the prior ~l.340 was wrong); affiliate-redirect / `BrandPartnerLink` query block (l.72–80); KV write collapses to `{type:"individual"}` + `writeAliasEntries()`. Reassign `onQueue('integrations')` → `'default'`. |
| `Jobs/Cloudflare/CloudflareCachePurgeJob` + `RetireSubdomainFromKvJob` | Reassign `onQueue('integrations')` → `'default'`. |
| `Jobs/Cache/WarmPublicSiteCacheJob` | `isIndividual()` guard → unconditional warm of the §28.8 key. |
| `Jobs/Notifications/SendTransactionalNotificationEmailJob` | Dead `payouts`/`payout_settlement`/`commissions`/`brand_status` keys in `CAPABILITY_GATE_MAP`. **Review correction 2026-05-22: also remove the `invites` key (l.44) — d7 DROPs invites; the prior "keep `invites`" text was wrong.** |
| `Jobs/ProcessImageVariantsJob` | `SiteCacheService::forgetBrandDesign()` calls (decision: does individual edge cache reuse this key?). |

### 2.6 Models / Observers (11 — review correction 2026-05-22: −2, IntegrationPolicy + ProfessionalIntegrationObserver → DELETE)

| File | Sever |
|---|---|
| `Models/Core/Professional/Professional` | `professional_type`/all `stripe_*`/`payout_method` from `$fillable`/`$hidden`/`$casts`. **Review correction 2026-05-22: KEEP `account_type` — d10; it stays in `$fillable` AND keeps its `AccountType` cast. Also remove `'has_historical_partner_links' => 'boolean'` from `$casts` (backing column dropped).** Remove `isBrand/isPartner/isIndividual/isInfluencer/isProfessional`; `effectiveIndustries()` body; and EACH of these methods (delete, not just "relationships"): `brandProfile()`, `brandAffiliateInvites()`, `brandPartnerLinks()`, `brandPartnerLinksAll()`, `primaryBrandPartnerLink()`, `subscription()`, `integrations()`, `squareIntegration()`, `freshaIntegration()`, `shopifyIntegration()`, `integrationForProvider()`; the `use Billing\Subscription` import. (`AccountType` import: keep if the enum is stubbed, else remove — see 3-account-type note.) (Renamed → `User` in Task 5.) |
| `Models/Core/Professional/Service` | `square_*` + `fresha_*` columns from `$fillable`/`$casts` (whole-file fate = decision 1). |
| `Models/Core/FeatureFlagOverride` | `brand_id` from `$fillable`. |
| `Models/Core/Notifications/Notification` | `'BrandPartnerRemoved'` match arm in `severityForFrontendType()`. |
| `Models/Core/Waitlist/WaitlistSignup` | `is_brand_partner_or_ambassador`/`number_of_affiliates_ambassadors`/`currently_sells_products` (decision 8). |
| `Models/Core/Site/SiteMedia` | `POOL_BRAND_GALLERY`/`POOL_PRODUCT` constants; `product_gid` from `$fillable`. |
| `Models/Analytics/CartEvent` | `shopify_product_id` (whole-file fate = decision 11). |
| `Observers/Core/ServiceObserver` | Square/Fresha job dispatches + 4 helper methods; `'booking'` from `reevaluateBooking()` iteration. |
| ~~`Observers/Core/ProfessionalIntegrationObserver`~~ | **Review correction 2026-05-22: d4 resolved → whole file DELETE (see 3g). Row no longer an EDIT.** |
| `Observers/Core/SiteObserver` | `ProvisionBrandDnsJob`/`RetireBrandDnsJob`/`BrandPartnerLink` imports; the two `isBrand()` DNS-dispatch blocks; `cascadeAffiliateKvSync()` + call; the `updating()` `_oldSubdomainPendingRetire` stash. |
| `Observers/Core/SiteMediaObserver` | Delete the entire `bustHydrogenCaches()` method AND its 3 call-sites in `saved()`/`deleted()`/`restored()` (review correction 2026-05-22 — "branches" was imprecise). Keep `touchParentSite()`. |
| `Observers/Professional/ProfessionalObserver` | `|| wasChanged('account_type')` from the KV-sync condition. |
| `Observers/Core/BlockObserver` | Comment-only (Hydrogen reference). |
| ~~`Policies/IntegrationPolicy`~~ | **Review correction 2026-05-22: d4 resolved → whole file DELETE (see 3g). Row no longer an EDIT.** |
| `Policies/BasePolicy` | `Professional` type-hint → `User` (Task 5 rename — include `app/Policies/` in the sweep). |

### 2.7 Console (2) + routes + config

- `Console/Commands/BackfillIndividualKvEntries` → rename to `BackfillUserKvEntries`;
  sever `BrandPartnerLink` import + affiliate-cohort query; collapse account-type
  dual-read.
- `Console/Commands/PurgeSoftDeleted` → remove `BrandPartnerLink` from
  `PURGE_EXEMPT` + its import.
- `routes/api.php`, `routes/api/professional.php`, `routes/api/publicSite.php`,
  `routes/api/staff.php` — all EDIT. **Review correction 2026-05-22: the "scan
  output" with the route lists does not exist — each file must have every
  `use`-import and `Route::` binding for a DELETE'd controller (Section 3:
  Shopify/Square/Fresha/Stripe/Commerce/Brand/Affiliate) removed. This is a
  Task-2 EDIT — a DELETE'd controller left referenced fatals the route file at
  parse.** **KEEP** `EnvCheckController`, `SupabaseEmailHookController`, the
  MFA-verification webhook routes; **KEEP** the staff group-level `require.aal2`.
- **`routes/console.php`** — EDIT, **Task 2 (boot-critical — review correction
  2026-05-22).** Remove every `Schedule::job()`/`Schedule::command()` entry for a
  DELETE'd class: `ProcessCommissionPayoutsJob`, `ReconcileStuckPayoutsJob` (×2),
  `VoidableCommissionsAndWarningsJob`, `VoidExpiredPayoutsJob`,
  `MonitorManualRefundQueueJob`, `ReconcileStuckShopifyIntegrationsJob`,
  `InviteExpirySweepJob`, `NudgeStuckOnboardingJob`,
  `SendWeeklyAnalyticsNotificationJob`; commands
  `partna:report-stuck-shopify-integrations`, `partna:reconcile-shopify-orders`,
  `commission-exports:sweep-stuck`, `commission-exports:prune-expired`. Also
  DELETE the inline `partna:normalize-professional-types` closure (l.13–61 — reads
  the dropped `professional_type` column). Leaving any of these bricks
  `schedule:run`.
- `config/partna.php` — EDIT (review correction 2026-05-22 — explicit per-key
  list). Remove `notifications.mailables` entries for invites/commissions/
  payouts/payout_settlement/integrations/brand_status/subscriptions/brand_links/
  payout_warnings (deleting a mailable class before its config key = `Class not
  found` on dispatch — boot-unsafe); `account_type_defaults.affiliate` + `brand_signup_code`
  (d17); the `billing`, `commerce_analytics`, `store`, `payouts`,
  `exports.commission`, `reconciler`, `professional_types` blocks;
  `gdpr.webhook_max_age_seconds` + `gdpr.queue` (KEEP `export_retention_days`/
  `signed_url_ttl_days`/`dedup_window_minutes` — used by `DataExportService`);
  the brand `cache.ttls.*` keys.
- `config/horizon.php` — EDIT. Remove `supervisor-stripe`/`-integrations`/
  `-exports` from `defaults` and `environments.production`; the matching
  `redis:*` `waits` keys; the dev `supervisor-1` stripe/integrations/exports
  queue entries. The 3 Cloudflare jobs need a surviving `default`-served
  supervisor.
- `config/services.php` — EDIT. Remove the `square`, `fresha`, `stripe`,
  `hydrogen`, `shopify` blocks.
- `config/sidest.php`, `config/auth.php` — EDIT (brand/commerce/Shopify/Stripe/
  booking blocks). `config/subscriptions.php` = KEEP (email-list subscriptions,
  not Stripe — verified).

### 2.8 factories / emails / worker (9)

- `database/factories/ProfessionalFactory` → EDIT (`professional_type`, `withCard()`
  Stripe state) then renamed to `UserFactory` (Task 5; the existing `UserFactory`
  stub is DELETE-and-replace).
- `emails/account/deletion-cancelled.blade.php` + `deletion-scheduled.blade.php`
  → rewrite the "brand configuration / affiliate pages" copy.
- `emails/gdpr/professional-data-export.blade.php` → remove "bookings"/"billing
  history" copy + `bookings.csv` line.
- `cloudflare-worker/src/index.js` → EDIT. **Review correction 2026-05-22: there
  is no explicit `if (entry.type === "brand")` branch — `brand` is implicit
  fallthrough (`return fetch(request)`, ~l.131).** DELETE the `affiliate` redirect
  branch; remove the stale `// type === "brand"` comment at ~l.131; **ADD the
  missing `{type:"alias"}` 301 branch** before the fallthrough (it is genuinely
  absent — alias KV entries currently fall through to origin instead of 301-ing;
  hard functional requirement, not cosmetic). KV-miss 404 + `individual` branch KEEP.
- `cloudflare-worker/README.md`, `package.json`, `wrangler.toml` → comment/
  description text only.

### 2.9 tests (~52 — full per-file sever list)

`tests/Pest.php` is the largest: remove `createBrandTenant`/`createAffiliateTenant`/
`createTwoTenants` + ~15 brand/commerce `setup*Table()` helpers; strip Stripe
columns from the professionals scaffold (both CREATE TABLE blocks). **Review
correction 2026-05-22: KEEP the `account_type` column in `setupProfessionalsTable()`
DDL — d10. Keep `professional_type` in the DDL until ALL test consumers are
edited, then drop it. The `professionals`→`users` rename (Task 5) must be atomic
— DDL + every `createTenant`/`createBrandTenant`/`createAffiliateTenant` caller
in one pass.** Remaining EDIT tests (tenant-
helper swap, brand-assertion trims, POLICY_EXEMPT pruning): the 9 `TenantIsolation/`
keepers, 7 `Analytics/` tests, 6 `FeatureFlags/` tests, `PolicyCoverageTest`,
`SoftDeletePurgeCoverageTest`, `FillableHardeningTest`, `AccountCapabilitiesTest`,
`AccountTypeFoundationTest`, `CapabilityDispatchTest`, `CapabilityGatingTest`,
`ProfessionalResourceTest`, `BootstrapSignupCodeTest`, `BootstrapIndividualWaitlistTest`,
`SchedulerRegistrationTest`, `Contact/Countdown/Newsletter/DocumentConfigTest`,
`LinkCategoriesConfigTest`, `OverrideScopeTest`, `UpdateSiteActionLifecycleTest`,
`VideoUploadsFlagTest`, `MediaUploadFailureHandlingTest`, `AdminInitiatedDeletionTest`,
`EffectiveIndustriesTest`, and the scattered `createBrandTenant→individual` swaps
(`Site/`, `Auth/`, `Notifications/`, `Staff/`, `Media/`). Full table in the scan
output — each row names the exact symbol/helper to swap.

**Review correction 2026-05-22 — test reclassifications (were misclassified KEEP):**

- `tests/Feature/Account/AccountTypeFoundationTest` → **EDIT** — strip brand/
  partner blocks, `AccountType` enum cases, dual-write-trigger tests.
- `tests/Feature/Account/AccountCapabilitiesTest` → **EDIT** — strip brand/
  partner describe blocks + `stripe_connect_status` resource assertions.
- `tests/Feature/Architecture/CapabilityGatingTest` → **DELETE** — only exercises
  deleted brand-status jobs.
- `tests/Unit/Professional/EffectiveIndustriesTest` → **DELETE** — only exercises
  brand/affiliate industry inheritance (removed logic).
- `tests/Feature/Console/SchedulerRegistrationTest` → **EDIT** — remove the 5
  deleted-job imports from the scheduled-jobs assertion table.
- `tests/Feature/Contact/ContactSectionConfigTest` + `tests/Feature/Countdown/
  CountdownSectionConfigTest` → **EDIT** — drop the
  `account_type_defaults.brand.allowed_sections` config assertions (l.18 each).
- `tests/Unit/Models/FillableHardeningTest` (already EDIT) — explicit sever:
  remove `use` imports + test cases for `Billing\Plan`, `Billing\Subscription`,
  `Brand\BrandStoreSettings`, `Brand\BrandTeamMembership`,
  `Commerce\CommissionMovement`, `Commerce\CommissionPayout`.
- `tests/Feature/Security/PolicyCoverageTest` (already EDIT) — remove these 8
  `POLICY_EXEMPT` entries pointing at DELETE'd models: `Billing\Plan` (l.21),
  `Billing\WebhookEvent` (l.22), `Analytics\CartEvent` (l.32),
  `Commerce\CommissionPayoutItem` (l.38), `Commerce\OrderEvent` (l.43),
  `Commerce\CommissionClawback` (l.49), `Commerce\CommissionExportAudit` (l.67),
  `Core\Professional\BrandSignupCodeAuditEntry` (l.74).

---

## Section 3 — DELETE (grouped by Task 3 domain)

### 3a — Shopify
Controllers: `Api/Shopify/*`, `Api/Internal/Embedded*` (5), `Api/Internal/Hydrogen*` (5),
`Api/Webhooks/Shopify/*` (9), `Api/PublicSite/PublicShopifyStorefrontController`.
Middleware: `Auth/VerifyShopifySessionToken`, `Auth/VerifyHydrogenApiKey`.
Concerns: `DedupesShopifyWebhookEvent`, `HandlesShopifyWebhook`, `ValidatesShopifyWebhookHmac`,
`NormalizesShopDomain`, `ResolveEmbeddedProfessional`.
Requests: `Api/Internal/Embedded/*` (verify the 3 NEEDS-REVIEW with their controllers),
`Api/PublicSite/Analytics/CartEventRequest` (decision 11).
Services: all `Services/Shopify/*` (17). Jobs: all `Jobs/Shopify/*` (17 incl. `Gdpr/*`).
Exceptions: `Exceptions/Shopify/*` (5). Resources: `ShopifyResyncResource`.
Mail: `Gdpr/CustomerDataExportMail` + `emails/gdpr/customer-data-export.blade.php`.
Commands: `Diagnostics/ShopifyTokenDiagnoseCommand`, `MigrateMetafieldNamespaceCommand`,
`ReconcileSmartCollectionRulesCommand`, `ReconcileShopifyOrders`,
`ReportStuckShopifyIntegrationsCommand`, `BackfillHasEnabledVariantsCommand`.
Staff: `StaffShopifyEventReplayController`, `StaffShopifyResyncController`.
Horizon: `supervisor-integrations`.

### 3b — Square
`Api/Professional/SquareIntegration/SquareIntegrationController`,
`Api/Webhooks/SquareCatalogWebhookController` (+ its `routes/api.php` routes),
`Services/Square/*` (4), `Jobs/Square/*` (2), `StaffSquareController`.

### 3c — Fresha / Booking
`Api/Professional/FreshaIntegration/FreshaIntegrationController`,
`Api/Professional/Booking/BookingAnalyticsController`, `Api/PublicSite/PublicBookingController`,
`StaffFreshaController`, `StaffBookingController`, `Services/Fresha/*` (4),
`Jobs/Fresha/*` (2), `Requests/.../Booking/*`, `Services/Notifications/CommerceNotificationService`
(booking notifications), `Mail/Notifications/AnalyticsMilestoneMail` +
`emails/notifications/analytics_milestones.blade.php` (**review correction
2026-05-22 — was misclassified KEEP; only dispatched from the deleted
`CommerceNotificationService`**). Booking section/link fate = decision 3.

### 3d — Stripe / Billing
Controllers: `Api/Professional/Stripe/*` (3), `Api/Professional/Subscription/*` (2),
`StaffStripeConnectController`, `StaffSubscriptionManagementController`.
Middleware: `RequirePlan`, `BrandFundingGate`. Concerns: `ValidatesStripeWebhookPayload`.
Webhooks: `Api/Webhooks/Stripe/*` (3). Services: `Services/Stripe/*` (12),
`Services/Billing/*` (5). Jobs: `Jobs/Stripe/*` (9). Models: `Models/Billing/*` (3).
Policies: `SubscriptionPolicy`. Resources: `SubscriptionResource`, `Stripe/StripeTransactionResource`.
Requests: `Stripe/*`, `StorePlanSubscriptionRequest`, `UpdatePlanSubscriptionRequest`.
Mail: `Notifications/SubscriptionMail` + `emails/notifications/subscriptions.blade.php`.
Listener: `ToggleStripeRequirementBannerOnTransition` (also in 3-account-type). Horizon: `supervisor-stripe`.

### 3e — Commerce / orders / payouts / exports
Models: `Models/Commerce/*` (10). Observers: `Observers/Core/CommissionMovementObserver`,
`CommissionPayoutObserver` (note: in `Observers/Core/`), `Observers/Commerce/AffiliateProductSelectionObserver`.
Policies: `CommissionPolicy`, `CommissionExportAuditPolicy`, `AffiliateProductPolicy`.
Services: `Services/Exports/*` (verify `JsonlPartWriter`), `Services/Analytics/AnalyticsService`,
`Services/Analytics/AffiliateProjectionsService` (+ `ResolvesTimezone` if no other consumer),
`Services/Cache/AnalyticsCacheService`. Jobs: `Jobs/Exports/*` (2).
Controllers: `StatsController`, `AffiliateCommerceAnalyticsController`,
`BrandCommerceAnalyticsController`, `AffiliateProjectionsController`,
`StaffStatsController`?(EDIT not DELETE — see 2.2), `StaffBrandCommerceAnalyticsController`,
`StaffCommissionController`, `StaffCommissionAdjustmentController`, `StaffCommissionPayoutController`,
`StaffCommissionVoidController`, `StaffAffiliateSelectionController`.
Enum: `Services/Professional/Enums/CommissionHandling`. Exception: `CommissionExportInProgressException`.
Mail: `Exports/CommissionExportReadyMail`, `Notifications/{Commission,Payout,PayoutSettlement,
PayoutWarning}Mail` + their blades. Resources: `Affiliate*`/`*Payout*`/`CommissionExportAudit*`/
`Professional/Analytics/AffiliateProjectionsResource`. Factories: `database/factories/Commerce/*` (4).
Commands: `CommissionExportsPruneExpiredCommand`, `CommissionExportsSweepStuckCommand`.
Horizon: `supervisor-exports`.

### 3f — Affiliate / partner / invites
Controllers: `Api/Professional/Affiliate/*` (4), `PublicBrandAffiliateInviteController`,
`PublicOpenInviteController`, `Api/Professional/Store/*` (8), `StaffAffiliateController`,
`StaffAffiliatePhotoController`, `StaffAffiliateStatusController`, `StaffBrandAffiliateLinkController`,
`StaffInviteController`. Models: `BrandPartnerLink`, `BrandPartnerLinkEvent`,
`BrandAffiliateInvite` (all in `Models/Core/Professional/`). Concerns: `ParsesBrandAffiliateInviteCsv`.
Services: `Services/Professional/Brand/*` (9 — BrandPartnerLink*, BrandAffiliateInviteService,
BrandOnboardingReadinessService, BrandSignupCodeService, BrandPartnerSiteSettingsSync),
`Services/Store/*` (6), `Services/Professional/DTO/Disconnect*`, `Services/Professional/Enums/DisconnectActor`.
Jobs: `Jobs/Cache/InvalidateBrandAffiliatesCacheJob`, `Jobs/Store/SeedAffiliateDefaultSelectionsJob`,
`Jobs/Notifications/InviteExpirySweepJob`. Observers: `Observers/Core/BrandAffiliateInviteObserver`,
`BrandPartnerLinkObserver`. Policies: `BrandPartnerLinkPolicy`. Events: `BrandPartnerLinkEvent`.
Mail: `Affiliate/AffiliateInvitedMail`, `Notifications/BrandLinkMail` + `emails/affiliate/*` +
`emails/notifications/brand_links.blade.php`. Middleware: `EnsureAffiliateAccount`.
Command: `InstallAffiliateDiscountCommand`. Requests: `Store/*` requests.

### 3g — Brand dashboard / brand notifications
Controllers: `Api/Professional/Brand/*` (13), `StaffBrandProfileController`,
`StaffBrandCatalogController`, `StaffBrandCollectionController`, `StaffBrandDesignController`
(decision 15), `StaffBrandSetupController`, `StaffStoreSettingsController`, `StaffIntegrationController`
(decision 4). Middleware: `EnsureBrandAccount`. Models: `Models/Brand/*` (2),
`BrandProfile`, `BrandSignupCodeAuditEntry` (in `Models/Core/Professional/`),
`ProfessionalIntegration` (decision 4). Observers: `Observers/Brand/BrandStoreSettingsObserver`,
`Observers/Core/BrandProfileObserver`, `ProfessionalIntegrationObserver` (decision 4).
Policies: `BrandResourcePolicy`, `IntegrationPolicy` (decision 4). Services:
`Services/Store/BrandAccessService`, `Services/Media/BrandDesignImporter`?(in Services/Shopify),
`Services/Professional/Brand/BrandStatusService`. Enum: `Enums/BrandStatus`.
Jobs: `Jobs/Cloudflare/ProvisionBrandDnsJob`, `ProvisionBrandDnsTxtJob`, `RetireBrandDnsJob`,
`Jobs/Notifications/FanOutBrandStatusNotificationJob`, `SendBrandStatusNotificationJob`,
`NudgeStuckOnboardingJob` (**reclassified EDIT→DELETE** — its whole query joins `brand.brand_profiles`),
`Jobs/Notifications/SendWeeklyAnalyticsNotificationJob` (commission digest).
**Review correction 2026-05-22: `SyncCustomerMarketingOptInJob` removed from this
DELETE list — it is KEEP per d2.**
Mail: `Notifications/BrandStatusMail`, `IntegrationNotificationMail` + brand/integration blades
(decision 18), `Notifications/AnalyticsWeeklyMail` + `emails/notifications/analytics_weekly.blade.php`
(**review correction 2026-05-22 — was misclassified KEEP; only caller is the
deleted `SendWeeklyAnalyticsNotificationJob`**). Commands: `BackfillBrandSignupCodes`, `BackfillBrandDnsCommand`.

### 3-account-type — Account-type machinery
`Events/Accounts/AccountTypeTransitionEvent`,
`Listeners/Accounts/*` (5 — `LogAccountTypeTransition`, `SyncNotificationPreferencesOnTransition`,
`ToggleStripeRequirementBannerOnTransition`, `SetTransitionBannerOnTransition`,
`InvalidateProfessionalCacheOnTransition`), `Services/Accounts/AccountTypeTransitionService`,
`Services/Professional/AccountTypeDefaultsService`, `Exceptions/InvalidAccountTypeTransition`,
`Http/Requests/Concerns/NormalizesProfessionalType` (**review correction
2026-05-22 — orphaned once `BootstrapRequest`/`UpdateProfessionalRequest`/
`StaffUpdateProfessionalRequest` drop the trait; add to DELETE**).

**`Enums/AccountType` — review correction 2026-05-22: NOT a full DELETE.**
`Professional` casts the kept `account_type` column to this enum (d10) and
resources emit it — deleting the enum fatals the cast. **Reduce it to a stub
with only the `Individual` case** (EDIT, not DELETE): drop the `Brand`/`Partner`
cases, keep the file. Surviving readers (`AccountCapabilities`,
`IndividualProfileController`, `BootstrapController`, the model cast) then
resolve cleanly.

### 3h — Cloudflare worker
No file deletions — `cloudflare-worker/src/index.js` is EDIT (see 2.8).

### Tests (DELETE — ~247)
Whole directories: `tests/Feature/Stripe/` (~40), `tests/Feature/Brand/` (~12),
`tests/Feature/Shopify/` (~13), `tests/Feature/Store/` (~9), `tests/Feature/Webhooks/
{Shopify,Stripe,Square}*` (~20), `tests/Feature/Billing/` (3), `tests/Feature/Commerce/` (~5),
`tests/Feature/Subscription/` (1), `tests/Feature/Services/{Square,Fresha,Shopify}/`,
`tests/Feature/Professional/{Booking,Stripe}/`, plus ~50 scattered files in
`Staff/`, `Services/`, `Policies/`, `Jobs/`, `Analytics/`, `FeatureFlags/`
(`BookingRoutesGatedTest`, `PosSyncRoutesGatedTest`), `tests/Unit/Jobs/ServiceJobTrashedGuardTest`.

---

## Section 4 — KEEP (directory-level)

Untouched, on the surviving path. Spot-verify against Section 0 decisions.

- Controllers: `Api/ApiController`, `HealthController`, all `Api/PublicSite/Public*`
  (config, leads, documents, email-sub/unsub, enquiry, marketing-pref, signup-
  availability, site, waitlist, qr, visibility), `Api/PublicSite/AnalyticsController`,
  `Api/Internal/EnvCheckController` + `SupabaseEmailHookController`,
  `Api/Webhooks/SupabaseAuthHookController`, all `Professional/SiteManagement/*`
  (gallery, GBP, link/section/service/category, site, theme), `Professional/Account/
  {Mfa,Deletion,DataExport}`, `Professional/Notifications/*`, `Professional/Site/
  HandleReclaimController`, and the user-path staff controllers (`StaffSite`,
  `StaffProfessional`*, `StaffAnalytics`, `StaffEnquiry`, `StaffNotification*`,
  `StaffMe`, `StaffDataExport`, `StaffAccountDeletion`, `StaffEmailSubscriber`,
  `StaffFeatureFlag`, `Staff{Link,Section,Service,ServiceCategory}Management`).
- Middleware: `Auth/{EnsurePartnaAdmin,EnsurePartnaStaff,RequireAal2,RequireEmailVerified,
  VerifySupabaseEmailHookSignature,VerifySupabaseJwt}`, `Context/EnforcePendingDeletionReadOnly`,
  `FeatureGate`, `Logging/*`, `SecureHeaders`, `VerifyTurnstileCaptcha`.
- Services: `Services/{Audit,Auth,Email,Streaming}/*`, `Services/Cache/{CacheLockService,
  Concerns/JitteredTtl}`, `Services/Cloudflare/{CloudflareKvService,CloudflarePurgeService}`,
  `Services/Media/*` (except `BrandDesignMediaService`=EDIT), `Services/Notifications/
  NotificationListingService`, `Services/Professional/{ConfirmationPreferenceService,
  DataExport/DataExportService,DataExport/DataExportZipWriter}`, `Services/PublicSite/
  PublicSiteResolver`, `Services/Site/{ReclaimHandleAction,SocialLinkNormalizer}`.
- Jobs/Mail: cache-metrics, video/image (except `ProcessImageVariantsJob`=EDIT),
  GDPR export, streaming, enquiry/broadcast notifications, auth + account-deletion +
  policy/incident/feature-announcement/profile-task mails, `HandleAliasExpiringMail`.
- Models: `BaseModel`, `FeatureFlag`, `HandleChangeLog`, `MediaVariant`, all
  `Notifications/*` (except `Notification`=EDIT), `Site/*`, `Staff/*`, `Analytics/
  {SiteVisit,LinkClick,LeadSubmission,SectionView}`, `Views/*`, professional
  confirmation/deletion-audit. (**Review correction 2026-05-22: "wallet-audit"
  struck — `WalletCurrencySwitchAudit` is DELETE per d13, in 3g.**)
- Policies/Observers: `BasePolicy`(EDIT-rename), `Site/Gdpr/Notification/Service/
  ProfessionalSelf/FeatureFlag/PartnaStaff` policies; non-brand observers
  (`Block`, `ServiceCategory`, etc. — several EDIT, see 2.6).
- routes/config: `web.php`, `config/{app,cache,cors,database,filesystems,logging,
  mail,nightwatch,queue,session,subscriptions,supabase}.php`.
- factories/emails/worker: `StaffAuditEntryFactory`, auth + deletion + generic
  notification blades (`analytics_*`, `policy_update`, `incident`,
  `feature_announcement`, `profile_tasks`, `_partial-content`, `staff_broadcast`,
  `enquiry-notification`), `cloudflare-worker/{.gitignore,package-lock.json}`.
- tests: ~128 — all `tests/Unit` and `tests/Feature` covering surviving subjects
  with no brand coupling.

---

## Section 5 — DB (re-baseline / Task 7)

### 5.1 Tables

**KEEP (with EDIT):** `core.professionals` (drop `professional_type`, all
`stripe_*`, `payout_method`, `qr_slug`; drop `pro_stripe_*`/`professionals_
professional_type_check` constraints; drop the `account_type` dual-write trigger +
keep `account_type` column itself per decision 10), `core.feature_flag_overrides`
(drop `brand_id` + the `scope_xor` constraint + 2 brand indexes; new constraint
`professional_id IS NOT NULL`), `site.services` (drop 5 `square_*` + 5 `fresha_*`
columns + their indexes/uniques — decision 1).
Note: `core.professional_legal_contents` was dropped in a migration prior to this strip and is absent from the new baseline — no action required.

**Review corrections 2026-05-22:** (a) `core.professionals.qr_slug` was already
dropped in `20260508600000` — it won't be in the re-baseline column list, no
action needed. (b) `core.feature_flag_overrides.created_by` FK was repointed to
`core.partna_staff` in `20260519010001` — the re-baseline must use that target,
NOT the v2-baseline `core.professionals` target.

**KEEP (clean):** `core.{partna_staff, waitlist_signups,
professional_confirmation_preferences, data_export_audit, professional_deletion_audit,
feature_flags, staff_audit_log, auth_factor_events, handle_change_log}`,
`site.{themes, sites, blocks, site_media, media_variants, site_subdomain_aliases,
professional_handle_aliases, service_categories, enquiries}`,
`notifications.{notifications, notification_receipts, notification_email_preferences,
notification_email_policies, email_subscriptions, broadcast_email_receipts}`,
`analytics.{site_visits, link_clicks, lead_submissions, section_views,
site_metrics_daily, site_metrics_hourly}`, `public.{failed_jobs, job_batches}`.
NEEDS-REVIEW tables: `core.{professional_integrations (d4), customers (d2),
gdpr_requests (d12)}`, `analytics.cart_events` (d11), `core.professional_confirmation_preferences`.

**DROP:** entire `brand.*` (7 tables), `commerce.*` (13), `billing.*` (3);
`core.{wallet_currency_switch_audit, brand_status_history}`;
`analytics.{cart_events, booking_*, brand_*, professional_metrics_*, professional_customer_daily}`.

### 5.2 RLS — port ALL, fix stale staff name

RLS on KEEP tables is spread across **three** migrations, not two:
`20260403000000_v2_baseline.sql`, `20260420200000_add_rls_to_remaining_tables.sql`
(9+ policy blocks on kept tables — `notifications.*`, `site.media_variants`,
`site.service_categories`, `core.waitlist_signups`, `public.failed_jobs`,
`public.job_batches`), `20260525000000_rls_policy_sweep.sql`. **~32 policies across
~20 tables reference the stale staff table name `core.comet_staff` (baseline) or
`core.sidest_staff` (20260420200000)** — every one must be rewritten to
`core.partna_staff` in the re-baseline. Full per-table policy list is in the scan
output; missing any → silent default-deny. **Review correction 2026-05-22: the
rename is not RLS-policy-bodies-only — `core.data_export_audit` (KEEP) has an FK
`triggered_by_staff_id REFERENCES core.sidest_staff(id)` (`20260425000002:41`)
that must also be rewritten to `core.partna_staff`, else the re-baseline fails on
FK resolution.**

### 5.3 GRANTs — 9 late GRANTs not in the v2 baseline

Port (between `20260422040000` and `20260525000000`): `site.enquiries`,
`core.gdpr_requests`, `core.data_export_audit`, `core.professional_deletion_audit`,
`core.handle_change_log` (INSERT+SELECT only), `notifications.broadcast_email_receipts`.
**Review correction 2026-05-22: also port `GRANT SELECT, INSERT ON
core.staff_audit_log TO app_backend` (`20260517300000:102`) and confirm the
`billing.webhook_events` GRANT (`20260407000000:18`, before the stated window) —
both KEEP tables; omitting either leaves `app_backend` unable to write them.**
Preserve the intentional `REVOKE UPDATE,DELETE` on `core.staff_audit_log`,
`core.handle_change_log` (append-only), `core.auth_factor_events`.
Skip the 3 GRANTs on dropped tables (`wallet_movements`, `wallet_currency_switch_audit`,
`brand_status_history`).

### 5.4 Triggers / functions — rewrite, don't copy

- **`core.trg_professional_handle_change()`** — remove the
  `PERFORM site.trg_recompute_affiliate_path(...)` call (that function writes
  `brand.brand_partner_links`).
- **`site.trg_recompute_partna_url()`** — rewrite: it queries
  `brand.brand_store_settings`. New body: derive `https://<subdomain>.partna.au`
  from `site.sites` only. **Review correction 2026-05-22:
  `site.compute_professional_url()` is ALREADY brand-free since
  `20260509100000_drop_custom_domain.sql` — it queries only `site.sites`; do not
  expect a `brand.brand_store_settings` reference in it.**
- **`core.prevent_staff_escalation()`** — **review correction 2026-05-22: rewrite
  target.** The v2-baseline body references `core.comet_staff`; the fix is in
  `20260524000000_fix_prevent_staff_escalation_stale_ref.sql`. The re-baseline
  must carry the FIXED (`core.partna_staff`) body — it fires on every staff
  UPDATE, so a stale body gives `42P01`.
- **`site.trg_recompute_affiliate_path()`** — DROP (only caller was the trigger above).
- **`core.professionals_account_type_dual_write` trigger** — DROP (it writes the
  dropped `professional_type` column → every user write would fail).
- **`core.validate_brand_team_membership()`** — DROP (reads `professional_type`).
- DROP all `brand.*` triggers with their tables. Carry forward all `set_updated_at`
  / timestamp / `prevent_staff_escalation` / `enforce_site_gallery_max6` /
  `handle_change_log_append_only` / `reject_staff_audit_log_mutation` triggers on
  KEEP tables.

### 5.5 Views

- `site.public_site_payload` — clean, port as-is.
- `site.all_site_data` — the `20260521000000` version projects `p.professional_type`
  **and** `p.account_type`. **Drop `professional_type` from the SELECT list**; keep
  `account_type`. Copying it verbatim fails `db push`.

### 5.6 Role / search_path / BYPASSRLS

- `app_backend` created `NOLOGIN`; runbook `ALTER ROLE … WITH LOGIN PASSWORD`
  step still required on the fresh project.
- No role-level `search_path` is set in migrations — tables are schema-qualified;
  no action, but confirm function bodies use qualified names.
- **`BYPASSRLS` contradiction** (decision 16): `20260420200000` sets
  `ALTER ROLE app_backend BYPASSRLS`; `20260525000000`'s header claims the
  opposite. **Recommendation: keep `BYPASSRLS`** — revoking it needs an explicit
  `app_backend` policy on every KEEP RLS table and several have none.

### 5.7 Cross-cut FK drop order

Drop `core.feature_flag_overrides.brand_id` (FK→`brand.brand_profiles`) before
dropping `brand_profiles`. `commerce.wallet_movements` and
`commerce.commission_payouts` have `ON DELETE RESTRICT` FKs to `core.professionals`
— drop those commerce tables before any professional purge. Full 32-step DROP
ordering (brand.signup_code_audit first → schemas last) is in the scan output.

---

## Section 6 — Plan corrections this ledger forces

1. `bootstrap/app.php` must be an explicit Task 2 EDIT step (boot-fatal otherwise).
2. `ProfessionalAnalyticsController` is **EDIT, not KEEP** — plan line 77 is wrong.
3. `NudgeStuckOnboardingJob` is **DELETE, not EDIT**.
4. The plan's "5 listeners" count is correct (`Listeners/Accounts/` has exactly 5).
5. Task 7 must name `20260420200000` as an RLS source and fix the
   `sidest_staff`→`partna_staff` rename, the two brand-referencing functions,
   the `all_site_data` view columns, and the `BYPASSRLS` decision.
6. The 3 Cloudflare jobs need an explicit target queue (`default`) and a
   surviving supervisor that serves it.
7. AccountDeletionService / DataExportPayloadBuilder have **unguarded** raw
   queries on dropped tables — these EDITs are GDPR-blocking, not cosmetic.

### Review corrections folded in 2026-05-22 (`LEDGER-REVIEW-RESULT.md`)

8. **Boot path is Task 2, expanded.** Beyond `bootstrap/app.php`: the route
   files and `routes/console.php` must have all DELETE'd-class references
   severed, and `AppServiceProvider` must lose the `WalletCurrencySwitchAudit`
   (l.100) + `GdprRequest` (l.108) policy regs — all before any Task 3 deletion.
9. **`AccountType` enum is a stub, not a DELETE** — `account_type` (d10) is cast
   to it. Reduce to the `Individual` case only.
10. **`GdprPolicy` stays KEEP** — d12 only deletes the `GdprRequest` model; the
    policy still serves `DataExportAudit`.
11. **Misclassified KEEPs now EDIT/DELETE:** `ProfessionalLinkBlockController`,
    `ProfessionalServiceController`, `AnalyticsController`,
    `FeatureFlagOverrideResource`, `StaffSiteController`,
    `StaffAnalyticsController` (→EDIT); `AnalyticsWeeklyMail`,
    `AnalyticsMilestoneMail` + blades (→DELETE); 7 test files reclassified.
12. **`NormalizesProfessionalType` trait** added to DELETE (3-account-type).
13. **Stale Section 2–5 text corrected to match Section 0:** booking-link path
    (d3) is KEEP everywhere; `SyncCustomerMarketingOptInJob` (d2) is KEEP;
    `invites` gate-map key (d7) is dropped; `IntegrationPolicy` /
    `ProfessionalIntegrationObserver` (d4) are DELETE.
14. **DB:** the `sidest_staff`→`partna_staff` rename also covers the
    `data_export_audit` FK; `prevent_staff_escalation()` needs its fixed body;
    `staff_audit_log` + `billing.webhook_events` GRANTs must be ported;
    `feature_flag_overrides.created_by` FK points at `partna_staff`.
