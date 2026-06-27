# STRIP-LEDGER Review — Result

Reviewed `STRIP-LEDGER.md` on 2026-05-22 via 11 parallel adversarial agents
(one per subtree), each running checks C1–C5 against the live repo.

## Verdict: **Ready with listed corrections**

No systemic enumeration error — file-completeness held. Every finding below is a
classification fix or a missing/over-broad sever spec. Apply the corrections,
fold them + the ledger into the strip plan, execute behind the per-task gates.
No second ledger review.

The recurring failure mode: **the ledger defers per-line/per-key sever detail to
"the scan output", but that scan output is not present in the repo.** Several
EDIT rows (route files, `config/partna.php`, `routes/console.php`, the larger
tests) therefore have no executable spec. These must be enumerated inline before
execution. The corrections below do that enumeration.

---

## 1. BLOCKING — must fix before execution

### Misclassified KEEP (would 500 at runtime post-rebaseline)

- **`ProfessionalLinkBlockController`** (`...SiteManagement/ProfessionalLinkBlockController.php:47`)
  reads `professional_type` (dropped, d9) and `account_type_defaults.{$type}`
  (dropped, d17). KEEP → needs an **EDIT** row: drop the `professional_type`
  fallback, hard-code the individual `custom_links_allowed` value.
- **`ProfessionalServiceController`** (`...SiteManagement/ProfessionalServiceController.php:53–55`)
  queries `square_variation_id`, dropped from `site.services` (5.1). KEEP →
  needs an **EDIT** row: remove the `?source=` filter / `square`/`smart` arms.
- **`AnalyticsController`** (`Api/PublicSite/AnalyticsController.php:9,13,18,34,200,217,340`)
  imports + injects `AnalyticsCacheService` (DELETE 3e) and uses `CartEvent` /
  `CartEventRequest` (DELETE d11). KEEP → has **no Section 2 sever row at all**.
  Needs an **EDIT** row: drop the `AnalyticsCacheService` injection, delete the
  cart-event handler + route, keep the site-visit/link-click write path.
- **`FeatureFlagOverrideResource`** (`Http/Resources/FeatureFlagOverrideResource.php:15`)
  emits `brand_id`, dropped from `core.feature_flag_overrides` (5.1). KEEP →
  one-line **EDIT** (remove the field).
- **`AnalyticsWeeklyMail`** + `emails/notifications/analytics_weekly.blade.php` —
  only caller is `SendWeeklyAnalyticsNotificationJob` (DELETE 3g). KEEP → **DELETE**.
- **`AnalyticsMilestoneMail`** + `emails/notifications/analytics_milestones.blade.php` —
  only dispatched from `CommerceNotificationService` (DELETE 3c). KEEP → **DELETE**.
- **Tests misclassified KEEP** — will fail post-strip, reclassify:
  - `AccountTypeFoundationTest`, `AccountCapabilitiesTest` → **EDIT** (strip
    brand/partner blocks, `AccountType` enum cases, dual-write trigger tests).
  - `CapabilityGatingTest`, `EffectiveIndustriesTest` → **DELETE** (no surviving
    subject — both only exercise deleted jobs / brand industry inheritance).
  - `SchedulerRegistrationTest`, `ContactSectionConfigTest`,
    `CountdownSectionConfigTest` → **EDIT** (drop deleted-job imports /
    `account_type_defaults.brand` config assertions).

### `GdprPolicy` is wrongly marked DELETE (d12 over-reached)

`GdprPolicy` is a generic owner-check still registered for `DataExportAudit`
(KEEP) at `AppServiceProvider.php:109`. Deleting it leaves `DataExportAudit`
without a policy → `PolicyCoverageTest` fails. **Correction:** `GdprPolicy`
stays **KEEP**; d12 narrows to "DELETE `GdprRequest` model; remove only the
`GdprRequest` `Gate::policy` reg at `AppServiceProvider.php:108`." This also
makes the Section 1 PRESERVE-manifest `GdprPolicy` entry correct again.

### Boot-path: DELETE'd classes still referenced, no sever spec

- **`routes/console.php`** — 14+ `Schedule::job()/command()` entries instantiate
  DELETE'd classes (`ProcessCommissionPayoutsJob`, `ReconcileStuckPayoutsJob`
  ×2, `VoidableCommissionsAndWarningsJob`, `VoidExpiredPayoutsJob`,
  `MonitorManualRefundQueueJob`, `ReconcileStuckShopifyIntegrationsJob`,
  `InviteExpirySweepJob`, `NudgeStuckOnboardingJob`,
  `SendWeeklyAnalyticsNotificationJob`, commands
  `partna:report-stuck-shopify-integrations`, `partna:reconcile-shopify-orders`,
  `commission-exports:sweep-stuck`, `commission-exports:prune-expired`). The
  inline `partna:normalize-professional-types` closure (l.13–61) reads the
  dropped `professional_type` column. **Add an explicit Task-2 sever spec to 2.7
  enumerating every one** — bricks `schedule:run` otherwise.
- **`AppServiceProvider.php`** — Section 2.1 omits two `Gate::policy` regs for
  DELETE'd models: `WalletCurrencySwitchAudit` (l.100) and `GdprRequest`
  (l.108). Add both to the 2.1 sever list.
- **`AccountType` enum** is DELETE'd (3-account-type) but referenced by
  surviving files with no sever spec: `IndividualProfileController` (KEEP — no
  Section 2 row at all; `:5,:83`), `AccountCapabilities` (EDIT 2.4 — import not
  named), `BootstrapController` (EDIT 2.2 — enum ref not named). **Simplest fix:
  keep `AccountType` as an `Individual`-only stub** (one-line change) instead of
  deleting it; otherwise add three sever rows replacing refs with `'individual'`.
- **Route files** (`routes/api.php`, `routes/api/professional.php`,
  `routes/api/staff.php`) `use`-import and bind DELETE'd controllers. The
  ledger defers the list to non-existent scan output — must be enumerated
  inline and run as a Task-2 EDIT before Task-3 deletes.

### EDIT over-severs a survivor

- **`EventServiceProvider.php`** — Section 2.1 says remove observer imports
  "l.26–34", but l.28 (`BlockObserver`) and l.34 (`CustomerObserver`) are
  **survivors** (BlockObserver EDIT, CustomerObserver KEEP per d2). Replace the
  range with explicit lines excluding 28 and 34.

### `Professional` model EDIT spec — incomplete + wrong

- **Wrong:** Section 2.6 says remove `account_type` from
  `$fillable`/`$hidden`/`$casts`. d10 says **KEEP `account_type`** (incl. the
  `AccountType` cast). Strike `account_type` from the removal list — only
  `professional_type` goes.
- **Incomplete:** the compressed phrase "brand/integration/subscription
  relationships" must be expanded to name each item that will fatal at
  class-load after the target models are deleted: `use Billing\Subscription`
  import; `brandProfile()`, `brandAffiliateInvites()`, `brandPartnerLinks()`,
  `brandPartnerLinksAll()`, `primaryBrandPartnerLink()`, `subscription()`,
  `integrations()`, `squareIntegration()`, `freshaIntegration()`,
  `shopifyIntegration()`, `integrationForProvider()`, the `effectiveIndustries()`
  body, and the `'has_historical_partner_links' => 'boolean'` `$casts` entry
  (its backing column is dropped).

### DB re-baseline (Task 7)

- **`core.data_export_audit`** (KEEP) has an FK
  `triggered_by_staff_id REFERENCES core.sidest_staff(id)`
  (`20260425000002:41`). Section 5.2 only flags RLS *policy bodies* for the
  staff-table rename — this **FK declaration** must also be rewritten to
  `core.partna_staff` or the re-baseline fails on FK resolution.
- **`core.staff_audit_log`** has a late `GRANT SELECT, INSERT … TO app_backend`
  (`20260517300000:102`) not in Section 5.3's 6-table port list. Omitting it
  leaves `app_backend` unable to write the audit log.
- **`core.prevent_staff_escalation()`** (fires on every staff UPDATE) references
  `core.comet_staff` in the v2 baseline; fixed only in
  `20260524000000`. Section 5.4 doesn't list it as a rewrite target — copying
  the baseline body gives `42P01`. Add it.

---

## 2. SPEC GAPS — name the missing symbols / enumerate the deferred lists

- **`config/partna.php`** (2.7) — enumerate the per-key removals: `notifications.mailables`
  entries for invites/commissions/payouts/payout_settlement/integrations/
  brand_status/subscriptions/brand_links/payout_warnings (deleting mailable
  classes before their config keys = `Class not found` on dispatch);
  `account_type_defaults.affiliate` + `brand_signup_code` (d17); `billing`,
  `commerce_analytics`, `store`, `payouts`, `exports.commission`, `reconciler`,
  `professional_types` blocks; `gdpr.webhook_max_age_seconds` + `gdpr.queue`
  (keep the export sub-keys `export_retention_days`/`signed_url_ttl_days`/
  `dedup_window_minutes` — used by `DataExportService`).
- **`config/horizon.php`** (2.7) — name `supervisor-stripe`/`-integrations`/
  `-exports` removal from `defaults` + `environments.production`, the matching
  `waits` keys, and the dev `supervisor-1` queue entries. The 3 Cloudflare jobs
  need a surviving `default`-served supervisor.
- **`config/services.php`** (2.7) — name the `square`/`fresha`/`stripe`/
  `hydrogen`/`shopify` block removals.
- **`BootstrapRequest`** (2.3) — sever spec omits the `professional_type` rule
  (`:121–125`), the `NormalizesProfessionalType` trait use (`:20`), and the
  `prepareForValidation` normalization branch (`:200–202`). Add them.
- **`Concerns/NormalizesProfessionalType.php`** — orphaned after all 3 consumers
  drop the trait; has no ledger classification. Add to DELETE (3-account-type).
- **`ProfessionalSectionBlockController`** (2.2) — names only the
  `AccountTypeDefaultsService` removal; misses three direct `professional_type`
  reads at `:35,:118,:307`. Add them.
- **`StaffSiteController` / `StaffAnalyticsController`** — read + emit
  `professional_type` (`:39,:87` and `:166`); the column is dropped from the
  `all_site_data` view (5.5). Both are KEEP — add EDIT rows removing the
  response key.
- **`FillableHardeningTest`** (2.9) — instantiates `Plan`, `Subscription`,
  `BrandStoreSettings`, `BrandTeamMembership`, `CommissionMovement`,
  `CommissionPayout` (all DELETE). EDIT spec is deferred to absent scan output —
  enumerate the `use` lines + test cases to cut.
- **`PolicyCoverageTest`** (2.9) — `POLICY_EXEMPT` has 8 entries pointing at
  DELETE'd models (`Billing\Plan` :21, `Billing\WebhookEvent` :22,
  `Analytics\CartEvent` :32, `Commerce\CommissionPayoutItem` :38,
  `Commerce\OrderEvent` :43, `Commerce\CommissionClawback` :49,
  `Commerce\CommissionExportAudit` :67, `BrandSignupCodeAuditEntry` :74). The
  second `it()` asserts each resolves to a real class — name all 8 for removal.
- **`SiteCacheService`** (2.4) — sever list omits `forgetBrandDesign()` (`:836`,
  calls a deleted `CacheKeyGenerator` key) and the safe-wrapper
  `safeApplyBrandImageFallbacks()`. Add both.
- **`SiteMediaObserver`** (2.6) — spec says "the `bustHydrogenCaches()`
  branches"; clarify to "delete the entire `bustHydrogenCaches()` method + its 3
  call-sites in `saved/deleted/restored`".
- **`ContactCaptureService`** (2.4) — spec says strip `source='square_booking'`/
  `'shopify_order'` *branches*; no such branches exist — only a comment at
  `:39`. Correct the spec.
- **Cloudflare worker** (`cloudflare-worker/src/index.js`) — there is no
  `if (entry.type === "brand")` branch to DELETE; brand is implicit fallthrough
  (`return fetch(request)`, ~l.131). Reword: "remove the stale `brand` comment;
  ADD the missing `{type:"alias"}` 301 branch." The alias branch genuinely is
  absent — alias KV entries currently fall through to origin instead of 301-ing.
  Treat the ADD as a hard functional requirement.
- **DB stale-but-harmless:** `core.professionals.qr_slug` (5.1) was already
  dropped in `20260508600000`; `site.compute_professional_url()` (5.4) is
  already brand-free since `20260509100000` — only `trg_recompute_partna_url()`
  still touches brand. Fix the rationale text so the executor doesn't doubt the
  spec. `feature_flag_overrides.created_by` FK was repointed to `partna_staff`
  in `20260519010001` — re-baseline must use that, not the v2-baseline target.

---

## 3. STALE TEXT — Sections 2–5 contradicting Section 0 (Section 0 wins)

- **2.2 / 2.4** `ProfessionalSiteController` / `SitepageDataResolverService` +
  `IndividualProfilePayloadBuilder` + `SectionVisibilityService` /
  `UpdateSiteRequest` + `StaffUpdateSiteRequest` — all still list the
  manual-booking path (`updateBookingSettings()`, `booking_mode`,
  `manual_booking_url`) as sever targets. **d3 says KEEP.** An executor
  following the literal 2.x text would delete live d3-mandated functionality.
  Rewrite these rows to KEEP the booking-link path.
- **3g** still lists `SyncCustomerMarketingOptInJob` as DELETE — **d2 says KEEP.**
- **2.6** still carries conditional "if `ProfessionalIntegration` survives …
  else DELETE" rows for `IntegrationPolicy` and `ProfessionalIntegrationObserver`
  — **d4 resolved both to DELETE.** Collapse to DELETE-only.
- **Section 1 PRESERVE** lists `GdprPolicy` — consistent again once the
  blocking correction above keeps `GdprPolicy`. (If d12 were left as-is it would
  be stale; the fix resolves it.)
- **Section 4 KEEP prose** lists "wallet-audit" — **d13 DELETEs
  `WalletCurrencySwitchAudit`.** Strike it from the Section 4 text (the file is
  already correctly in the 3g DELETE list).
- **2.5** `SendTransactionalNotificationEmailJob` says "keep `invites` per
  decision 7" — **d7 DROPs invites.** The `invites` key in `CAPABILITY_GATE_MAP`
  (`:44`) must be removed too.
- **Comment-only / cosmetic:** `SyncSubdomainToKvJob` line numbers in 2.5 are
  ~280 off (file is 129 lines); `bootstrap/app.php` Shopify block is l.78–81 not
  l.74–81; `SectionView` + `StaffGoogleBusinessProfileController` carry stale
  Hydrogen/"brand" comments. Fix opportunistically — not load-bearing.

---

## Next step

Fold these corrections + the ledger into
`docs/superpowers/plans/2026-05-21-standalone-pages-backend-strip.md` and execute
behind the per-task gates (`composer test` + `route:list` + `php artisan about`
+ request-path smoke). Two corrections are sequencing-critical: the
`routes/console.php` + route-file + `AppServiceProvider`/`EventServiceProvider`
edits and the `AccountType`-stub decision must land in **Task 2 (boot path)**
before any Task 3 deletion, or `php artisan` is bricked mid-strip.
