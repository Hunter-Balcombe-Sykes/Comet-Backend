# Backend Dead Code — Execution Audit (verified 2026-07-27)

Executable via `execute audit audits/sweeps/2026-07-27-dead-code/BACKEND-DEAD-CODE-VERIFIED.md`
(follows `scripts/audit/fix-flow.md`). Suggested branch slug: **`backend-dead-code`** →
`audit-fix/backend-dead-code-2026-07-27`.

**Scope:** BACKEND only. Every finding below was re-derived from live code in this repo — grepped all
callers across `app/ routes/ config/ tests/`, traced route wiring + the platform registry, and checked
test coupling. Source: this folder's `FULL-CODEBASE-BLOAT-INHERITANCE-AUDIT-PLAN.md`
("Comet-Backend — Bloat & fixes"). Frontend/monorepo items are NOT in this checkout — see the appendix,
do not execute them from here. **Verdict: the source doc's backend list is accurate — zero false
positives.** The structure below just separates clean deletes from the few that carry a test, a
provider edit, a decision, or a migration.

---

## Execution policy
- **Plan model:** Opus
- **Implement model:** Sonnet
- **Review model:** Sonnet (always a separate instance from the implementer)
- **S/XS units → combine plan+implement** in one pass (every unit here is XS/S). Keep separate only if a
  unit gets escalated mid-flight.
- **Blocker-gate pre-clearances** — two auto-run items trip a keyword in the gate but are verified-safe;
  wave them through without a real planning pause:
  - **DC-A6** trips *"money"* — it's a dead currency **formatter** (`Support/Money.php`), no financial
    logic; `ServiceResource` returns raw cents, nothing adopted it.
  - **DC-A4** trips *"auth/redirect"* — it's a dead Stripe-era validation rule with zero callers.
- Everything under `## Suggested Bundled Sessions` **auto-runs** (no sign-off). Everything under
  `## Standalone — do NOT bundle` **pauses for explicit go-ahead** before implementation.

## Progress
- **Total findings: 14** — 12 / 14 done.
- Auto-run: Bundle 1 (8), Bundle 2 (1), Bundle 3 (3) = 12.
- Standalone (pause for sign-off): DC-C2, DC-D1 = 2.
- Decided no-action (not counted): B2 `visibility()` — keep.

---

## Suggested Bundled Sessions

### Bundle 1 — Mechanical dead-code deletes (P2 · XS/S) — AUTO-RUN
One session. Combine plan+implement per item; one independent review over the whole bundle diff.
Delete each target **and its paired test**, then `composer test`.

- [x] **DC-A1** — Delete `app/Services/Platforms/Strategies/Refresh/OnDemandRefresh.php`; also remove the
  now-orphaned comment block referencing it at
  `app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php:416`.
  *Evidence:* only two refs repo-wide — the class + that comment (already labelled "dead code, no
  constructor references"). No test.
- [x] **DC-A2** — Delete `app/Services/Platforms/Strategies/Fetch/NoFetch.php`; remove its `use …NoFetch;`
  import and the `NoFetch` test case in `tests/Feature/Platforms/Registry/GenericStrategiesTest.php`.
  *Evidence:* only refs are its own file + that one test; never constructed in production.
- [x] **DC-A3** — Delete `app/Services/Http/ParsedUrl.php` **and** `tests/Unit/Http/ParsedUrlTest.php`.
  *Evidence:* only refs are the file + its own test.
- [x] **DC-A4** — Delete the method `allowedRedirectRule()` from `app/Http/Requests/BaseFormRequest.php`
  (≈ line 165; rest of the class stays) **and** delete
  `tests/Unit/Http/Requests/BaseFormRequestAllowedRedirectRuleTest.php`.
  ⚠ *keyword pre-cleared (auth/redirect):* Stripe-era rule, zero Request subclasses call it.
- [x] **DC-A5** — Delete `app/Http/Resources/UserPublicResource.php` **and**
  `tests/Feature/Resources/UserPublicResourceTest.php`; optionally tidy the stale mention in
  `tests/Feature/Resources/UserStaffResourceTest.php`. *Evidence:* no controller returns it; only its
  own test references it.
- [x] **DC-A6** — Delete `app/Support/Money.php` **and** `tests/Unit/Support/MoneyTest.php`.
  ⚠ *keyword pre-cleared (money):* dead formatter, no financial logic.
- [x] **DC-A7** — Delete the `feature()` function from `app/helpers.php` (≈ line 11; leave the rest of the
  file) **and** delete `tests/Unit/FeatureFlags/FeatureHelperTest.php`. *Evidence:* production goes
  through `FeatureFlagService`/`FeatureGate`; only refs are the def, a comment in `FeatureGate.php:17`,
  and that test.
- [x] **DC-B1** — Delete 6 unrouted methods from
  `app/Http/Controllers/Api/Platforms/AppleController.php`: `musicSelection`(86), `musicAccounts`(98),
  `removeMusicAccount`(104), `podcastSelection`(138), `podcastAccounts`(150), `removePodcastAccount`(156).
  File + live methods (`connectMusic`, `musicRecent`, `musicHighlights`, `forget`, `forgetMusic`, …)
  stay. Cosmetic: rename the stale test label at `tests/Feature/Platforms/SessionA2LockTest.php:109`
  (its actual call routes to `GenericPlatformController::removeAccount`). *Evidence:* zero route wiring,
  zero external callers, zero internal self-calls.

### Bundle 2 — Eventbrite/Humanitix resource cleanup (P2 · S) — AUTO-RUN, own review
Separate session because it edits the provider registration + a contract test, not just files.

- [x] **DC-C1** — Delete `app/Http/Resources/Platforms/EventbriteConnectionResource.php` and
  `HumanitixConnectionResource.php`. THEN:
  1. Drop the `->resource(EventbriteConnectionResource::class)` argument from
     `app/Providers/PlatformRegistryServiceProvider.php:334`, and
     `->resource(HumanitixConnectionResource::class)` from `:335` — leaving them would reference deleted
     classes and fatal at boot.
  2. Remove the eventbrite/humanitix cases from `tests/Feature/Platforms/PlatformResourceContractTest.php`
     (7 refs).
  *Evidence:* both platforms default to `PlatformRouteShape::Bespoke`, so the registry-driven route loop
  (`routes/api/platforms.php:275`) skips them; their reads serialize via
  `EventsPlatformController::accountData()`, never the resource. `resourceClass()` is dereferenced only
  in `GenericPlatformController::shape()`, which these two never reach. The descriptor getter is nullable
  and unused for bespoke platforms. Run `composer test`; confirm no registry-coverage test asserts a
  non-null resource (none found).

### Bundle 3 — Stale comments & misleading docblocks (P3 · XS) — AUTO-RUN
Comment-only, cannot break tests. For each dead symbol, **grep repo-wide and fix ALL hits, not just the
cited line** (the source doc's line list is not exhaustive — e.g. `FanOutBrandStatusNotificationJob`
also lives at `SendStaffBroadcastEmailsJob.php:51`).

- [x] **SC-E1** — Correct/remove the comments citing symbols VERIFIED not to exist:
  `RenameSubdomainAction.php:126` (`HydrogenAffiliateController`), `UpdateSiteAction.php:69`
  (`commerce.affiliate_product_selections`), `config/partna.php:1659` **+**
  `SendStaffBroadcastEmailsJob.php:51` (`FanOutBrandStatusNotificationJob`), `config/queue.php:98` +
  `config/partna.php:1759` (`RedactShopJob`), `config/queue.php:43,74-75`
  (`RebuildProfessionalHourlyAggregatesJob`/`RebuildBrandHourlyAggregatesJob`),
  `NotificationPublisher.php:23` (`config/sidest.php` → real source `config('partna.notifications.mailables')`),
  `MediaVariant.php:14` (`core.media_variants` → real table is `site.media_variants`).
- [x] **SC-E2** — Fix the false-claim comments: `EnvCheckService.php:12` (Fresha/Square "dropped" — they're
  live; fix the *reason*, keep the env-key exclusion), `TrackableBlockTypes.php:8-9` (overclaims a
  removed consumer), `FeatureFlagService.php:18-22` (numbering gap / removed tier),
  `UserSectionBlockController.php:18` (docblock claims account-type restrictions the method doesn't have),
  `SubmitFeedbackRequest.php:10-12` (cites a superseded migration; field now has a DB CHECK).
- [x] **SC-E3** — Sweep pre-pivot terminology fossils (comment-only, opportunistic) across the files
  listed under "Reference — E3 file list" below.

---

## Standalone — do NOT bundle (pause for sign-off before implementing)

### DC-C2 — Remove dead `features` config block (P2) — DECISION-BLOCKED
- [x] **DC-C2** — Remove `['smart_booking','square_sync','fresha_sync']` from `config/partna.php`
  (≈ lines 1727-1750) AND update the coupled tests: `tests/Feature/FeatureFlags/FeatureGateMiddlewareTest.php:13`
  asserts these three keys exist; `tests/Feature/FeatureFlags/SectionVisibilityLinkOnlyTest.php` toggles
  `partna.features.smart_booking`. *Evidence:* no route applies `feature:smart_booking` (etc.); a test
  even asserts booking behaviour is identical on/off ("feature dropped"). **BLOCKED on the FeatureGate
  decision** (source doc "Needs a decision"): if `FeatureGate` middleware stays as launch-gate infra, its
  example flags may stay too. Resolve that first. Note: the `partna.features` key itself is live (read by
  `FeatureFlagService` as a fallback) — only these three entries are dead.

### DC-D1 — Drop two dead DB columns (P3) — DB MIGRATION
- [ ] **DC-D1** — Drop `site.site_media.product_gid` and `core.email_subscriptions.qr_slug`, and remove
  their `@property` docblock lines on `SiteMedia` / `EmailSubscription`. *Evidence:* only refs are those
  self-documenting docblocks; zero read/write anywhere. ⚠ **Requires a raw SQL migration in
  `supabase/migrations/`** against the live dev DB (this repo's migration conventions apply) — a DB
  blocker, not a code delete. *Recommendation:* harmless + self-documented; either leave, or do a
  dedicated migration PR — do NOT fold into a code-deletion bundle.

---

## Decided — no action (excluded from execution)
- **B2 `UserSiteController::visibility()`** — unrouted (the live toggle is `SiteVisibilityController::update`),
  but self-documented as deliberate authorization-doctrine scaffolding ("the gate is here so the
  Authorization Doctrine holds if it is ever routed"). **RECOMMEND KEEP** — cheap, intentional. Reverse
  only if you consciously decide to drop the placeholder.

## Out of scope (correctly classified in the source doc — not deletion work)
- **Fixes / real bugs** — incl. a live crash: `StaffFeatureFlagOverrideController::store()` queries the
  dropped `brand_id` column. Higher priority than this cleanup; handle as its own ticket.
- **Needs a decision** — CSAM moderation subsystem, `FeatureGate` middleware (see DC-C2), the two
  domain-routed public controllers, the three YAGNI seam interfaces.
- **Consolidation** — duplicated `absolutize()`, `socialUsername()`, `reorderLayout()`, near-duplicate
  Form Requests. Maintainability refactors.

---

## Reference — E3 file list (for SC-E3)
Pre-pivot terminology fossils, grouped by what they reference (comment-only, zero runtime risk):
- **Removed 3-account-type / brand / affiliate / partner:** `SendEnquiryNotificationJob.php:19-20`,
  `SendTransactionalNotificationEmailJob.php:44-49` (vestigial `CAPABILITY_GATE_MAP`),
  `StaffStoreNotificationRequest.php:47-49`, `UpdateServiceRequest.php:8`, `UpsertWorkplaceRequest.php:61`
  + `UpsertSectionBlockRequest.php:55-59`, `StaffEmailSubscriberController.php:16-18`,
  `config/partna.php:912-913`, `config/services.php:75` + `CloudflareKvService.php:10`.
- **Archived Hydrogen / removed themes:** `SectionView.php:13`, `SiteMediaObserver.php:76`.
- **Shopify (removed Partna-owned integration):** `config/nightwatch.php:25`.
- **Stale "test-mode, no auth" docblocks** (controllers are fully authenticated via route middleware):
  `AppleController.php:21`, `FreshaController.php:27-36`, `SkoolController.php:15`.
- **Misc:** `MediaUploadService.php:249-268` (docblock above the wrong method),
  `UnprocessableImageException.php` (outside `Exceptions/`), `HandleAliasExpiringMail.php` (renders from
  `mail/` not the `emails/` convention).

## Appendix — cross-repo items NOT verified here (do NOT execute from this repo)
The source doc also lists dead code in **Partna-Frontend** and **partna-monorepo**; those files aren't in
this checkout, so they're unverified here and must be re-checked in their own repos before deleting.
Headline candidates: Frontend — ~38 dead-export files (knip), whole dead files (`lib/queries/public.ts`
+ fetchers, `lib/about/api.ts`/`use-about.ts`, `lib/signup-draft.ts`, `lib/hooks/use-session.ts`,
`lib/hooks/optimistic/account.ts`, the `/launch` route), 12 dev-showcase-only `components/ui/` files,
~18MB orphaned `public/fonts|themes|Brand Kit`. Monorepo — `packages/design-system/_archive/` (already
build-excluded, the one zero-risk freebie) + `apps/pages/supabase/.temp/`, and (still live-in-build)
`src/renderers/pdf.ts`, `src/brand/fonts/`, several dead exports, and a per-render "dead computation"
finding.
