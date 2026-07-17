# Triaged execute-audit — P3 — reconciled 2026-07-11+17 full-work sweep

> **▶ To run this file:** `execute audit audits/sweeps/2026-07-11-full-work-sweep/TRIAGE-3-P3.md`
> Fires the fix-flow: branch off `development`, then for each work unit (every **bundle** + every **standalone**, in tier order) **plan (Opus) → implement (Sonnet) → independent Sonnet review → commit** — ticking the box only after tests pass AND review says PASS. **Blocker gate:** P0 · auth · money · DB/migration · L/XL → present the plan and WAIT for Josh's sign-off before implementing. Auto-archives when every box is `[x]`. Full runbook: `scripts/audit/fix-flow.md`.

## This file
- **Tier(s):** P3  ·  **Findings:** 67  (37 carried from 07-11 · 30 new from 07-17)
- **IDs ≥ 100 = 2026-07-17** changed-file findings; **IDs < 100 = carried 2026-07-11**. Same file, same lens sections as the master; sliced to this tier.
- Full context + reconciliation ledger: `CONSOLIDATED.md` (same folder).

## Execution policy
- **Plan:** Opus 4.8 · **Implement:** Sonnet 4.6 · **Review:** separate Sonnet 4.6 (never the implementer).
- **Combine plan+impl:** YES for S/XS · NO for P0/P1 or L/XL. Per-item escalate to Opus for gnarly logic/blast radius.

## Execution grouping — P3 (triage 2026-07-17)

**Merge as one fix (duplicates / same root cause):**
- `MIG-105` ← `SCHEMA-105`, `SCHEMA-106`, `MIG-5` — all four are the identical "no `to revert:` rollback comment on a `site.design_kits` `DROP COLUMN` migration" finding, sliced per-file/per-lens. Resolve all four with one `CONVENTIONS.md` note (adopt going forward; do NOT retroactively edit already-applied files).
- `SCHEMA-103` ← `SCHEMA-104` — both are "unindexed `DELETE ... WHERE target_var = ...` on `site.design_kit_contributions`" on sibling migrations. One optional index (or one decision to decline) closes both.

**Work units (themed batches), in recommended order:**
1. **Config hygiene — hardcode → `config()`** — `CFG-2`, `CFG-3`, `CFG-4`, `CFG-5`, `CFG-102` · mechanical `env()`/const extraction into `config/partna.php` + `.env.example` · effort ~S · autonomous
2. **Test-coverage additions** — `TEST-4`, `TEST-5`, `TEST-108`, `TEST-109`, `TEST-110` · pure new-test work, zero prod-code change · effort ~S · autonomous
3. **Code-quality / dead-code / drift cleanup** — `SLOP-4`, `SLOP-5`, `SLOP-6`, `SLOP-102`, `SLOP-103`, `SLOP-104`, `SLOP-105`, `SEM-2`, `DINT-103` · banner deletes, dead vars/methods, copy-paste-constant consolidation, dead enum case, mirror-const promotion · effort ~S · autonomous
4. **Job/queue & Instagram-adjacent hygiene** — `LIFE-111`, `JOB-5`, `SEM-101`, `OBS-102` · `LIFE-111`+`SEM-101` share `InstagramConnectJob.php`; `OBS-102` sibling `InstagramScraper.php` log-noise; `JOB-5` `ShouldBeUnique` guard · effort ~S · autonomous
5. **Caching / query hygiene (latent N+1, key centralization)** — `SCALE-5`, `CCH-6`, `CCG-101`, `SCALE-103` · eager-load/guard-test/route through `CacheKeyGenerator`/de-dupe in-request double-call — all bounded-risk polish · effort ~S · autonomous
6. **Privacy/PII & retention hygiene (non-DB)** — `PRIV-10`, `PRIV-11`, `PRIV-12`, `PRIV-13`, `PRIV-103` · stale docblock, placeholder-domain swap, retention number, `moderation.evidence` DSAR section (`PRIV-13`), log-field swap · effort ~S · autonomous
7. **Resource/Form-Request hygiene — dashboard/staff/content** — `SEC-6`, `API-2`, `API-3`, `API-4`, `API-5`, `API-6`, `API-8`, `API-102` · inline validation → Form Request (`SEC-6`) + hand-built array/`->toArray()` → Resource · effort ~S each · autonomous. **Shared file:** `ContentController.php` also touched by `SLOP-4` (batch 3) — sequence, don't run concurrent.
8. **Cloudflare / Edge-worker hygiene** — `EDGE-5`, `EDGE-6`, `EDGE-104`, `EDGE-105`, `OBS-103`, `SCALE-104`, `CFG-101` · CTA hardcode, staging-KV placeholder, Worker test scaffolding, purge-cap visibility, `CloudflareCustomHostnameService::delete()` missing `->throw()`, KV rate-limit middleware, hardcoded timeouts · effort ~S except `EDGE-104` (M — new Miniflare/Vitest harness) · autonomous
9. **Menu Resource extraction (public + dashboard)** — `API-103`, `API-104` · menu payload hand-built twice with drifted field names; one shared Resource with `$this->when()` gating · effort ~M · autonomous, judgment-heavier — `API-103` touches an **unauthenticated public** endpoint, diff the wire shape carefully
10. **API-10 (standalone)** — `API-10` · adds `paginate()`/`meta` envelope to `StaffNotificationController::index()` — verify no staff-dashboard client depends on the flat shape before shipping · effort ~S · autonomous
11. **DB / schema / migration hygiene (🔒 BLOCKER — individual sign-off)** — `SCHEMA-6`, `SCHEMA-7`, `SCHEMA-8`, `SCHEMA-9`, `SCHEMA-10`, `SCHEMA-11`, `SCHEMA-12`, `SCHEMA-13`, `DINT-3`, `MIG-5`, `SCHEMA-103`, `SCHEMA-104`, `MIG-105`, `SCHEMA-105`, `SCHEMA-106`, `MIG-104`, `DINT-104` · every item touches `supabase/migrations/` or a schema GRANT → per fix-flow each needs plan + sign-off. After merges collapse: ~7-8 tiny migrations (CHECK×3, REVOKE×1, FORCE RLS×3 combinable, RLS policy+test×2 = the only real M work), 1 doc-only convention note, 1 no-op close-out (`MIG-104`) · 🔒 blocker: present as one review pass, rapid-approve/decline each

**Ordering / dependencies:**
- Batches 1–6 have no DB/auth/money surface — run first/in-parallel, any order.
- Batch 11 is the only blocker; doesn't block the others, but surface early (longest to review — 17 items, mostly quick yes/no).
- Within batch 11: trivial CHECK/GRANT/FORCE-RLS first; `SCHEMA-12`/`13` (new RLS policies + regression test) last (only real design work).
- Within batch 8: mechanical items first; `EDGE-104` last (scaffolding decision).
- Do batch 7's `API-102` before batch 9 — smaller Resource-extraction warm-up establishes the pattern batch 9 reuses.
- **Shared-file flags:** `ContentController.php` (`API-8` + `SLOP-4`); `InstagramConnectJob.php` (`LIFE-111` + `SEM-101`, co-batched); `GoogleBusinessAutoSync.php` (`SLOP-102` + `SLOP-105`, co-batched).

**Possibly already addressed / candidate won't-fix:**
- `MIG-104` — finding's own text says "No fix required today"; tick done with existing rationale, no change.
- `SCHEMA-103` / `SCHEMA-104` — findings recommend "add the index only if this pattern keeps recurring" (recurred 6× without incident) — reasonable to accept as-is.
- `CFG-3` / `CFG-4` — both offer "leave as-is if consistency isn't worth the churn" with a working fail-closed net; legit won't-fix candidates.
- `EDGE-6` — given the current Laravel Cloud reality (dev serves both domains, no real staging tier), removing the vestigial `[env.staging]` block is probably the right call over building a staging KV namespace.
- `PRIV-12` — proportionality judgment (730-day waitlist retention), not a bug; reduce the number or document the rationale — Josh's call.

---

<!-- ═══════════ audit-2026-07-11-security.md ═══════════ -->

# Security Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Security: auth boundaries, tenant isolation, mass assignment, inbound callbacks, secrets, injection, SSRF, upload safety, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- app/Http/Middleware/AddPublicCacheHeaders.php
- app/Http/Middleware/VerifyBotToken.php
- app/Http/Middleware/Auth/VerifySupabaseHookSignature.php
- app/Http/Middleware/Auth/RequireAal2.php
- app/Http/Middleware/IdempotencyKey.php
- app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php
- app/Http/Controllers/Concerns/DetectsClientInfo.php
- app/Http/Controllers/Api/User/SiteManagement/UserSectionBlockController.php
- app/Http/Controllers/Api/User/SiteManagement/UserWorkplaceController.php
- app/Http/Controllers/Api/User/Profile/SectorController.php
- app/Http/Controllers/Api/User/Analytics/UserAnalyticsController.php
- app/Http/Controllers/Api/User/Analytics/DevInsightsController.php
- app/Http/Controllers/Api/User/Account/UserSelfController.php
- app/Http/Requests/Api/User/Content/UploadContentImageRequest.php
- app/Http/Requests/Concerns/SniffsFileMimeType.php
- app/Http/Requests/Api/PublicSite/Analytics/ItemSeenRequest.php
- app/Http/Requests/Api/PublicSite/Analytics/SectionDwellRequest.php
- app/Http/Requests/Platforms/UpdateShopBrandRequest.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Http/Controllers/Api/PublicSite/BootstrapController.php
- app/Http/Controllers/Api/PublicSite/PublicEarlyAccessController.php
- app/Http/Controllers/Api/Platforms/BookingController.php
- app/Http/Controllers/Api/Platforms/ReservationsController.php
- app/Http/Controllers/Api/Platforms/OnlineOrderingController.php
- app/Http/Controllers/Api/Platforms/DisplaySettingsController.php
- app/Http/Controllers/Api/Platforms/ShopController.php
- app/Jobs/Platforms/EnrichLinkCardJob.php
- app/Services/Platforms/LinkCardScraper.php
- app/Services/Platforms/GenericShopScraper.php
- app/Services/Platforms/ShopProviderDetector.php
- app/Services/Webhooks/StandardWebhookVerifier.php
- app/Providers/AppServiceProvider.php
- config/cors.php
- routes/api/user.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

## P3 — Nice to have

- [ ] **#SEC-6** · P3 — `setPreviousWebsite` validates inline instead of via a Form Request
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserWorkplaceController.php:212-216
    - **Affects:** `PATCH /site/workplace/previous-website` — same organizational nit as kept separate since it's a single-field, already-adequate validation (`nullable|url|max:2048`).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract into a dedicated `SetPreviousWebsiteRequest` class and type-hint it on the controller method.
    - **Technical:** Same pattern as (inline `$request->validate()` on a mutating route instead of a dedicated `FormRequest`), but split out at P3 rather than merged into that bundle — the validation here is a single well-formed `url` rule with no adjacent security-relevant fields, making this pure code-organization polish rather than a review-visibility risk.
    - **Plain English:** Same "checklist filed in the wrong drawer" issue as the other four endpoints, but for a single simple field (an old website URL) that's already validated correctly — lowest-priority cleanup of the bunch.
    - **Evidence:**
        ```php
        public function setPreviousWebsite(Request $request): JsonResponse
        {
            $validated = $request->validate([
                'previous_website' => ['nullable', 'url', 'max:2048']
            ]);
        ```

## Suggested Bundled Sessions

    - **Why grouped:** Both are `VerifySupabaseJwt` / `authorizeForUser` doctrine-hardening items with no live exploit path today; can land as one review pass over the auth-adjacent controller layer.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Form Request extraction:** #SEC-6
    - **Why grouped:** Identical mechanical fix (inline `$request->validate()` → dedicated `FormRequest` class) across five endpoints in the Platforms controller family.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-11-lifecycle-correctness.md ═══════════ -->

# Lifecycle Correctness Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Lifecycle correctness: race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/EarlyAccess/EarlyAccessService.php
- app/Services/User/UserBootstrapService.php
- app/Services/Site/UpdateSiteAction.php
- app/Services/User/AccountDeletionService.php
- app/Services/Site/ContentSelectionService.php
- app/Http/Middleware/Context/LoadCurrentUser.php
- app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php
- app/Services/Notifications/Dispatchers/PlatformHealthNotifier.php
- app/Services/Notifications/NotificationPublisher.php
- app/Jobs/Platforms/GoogleBusinessEnrichJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Services/Platforms/ShopCatalog.php
- app/Services/Platforms/Strategies/Fetch/GoogleBusinessFetch.php
- app/Services/Platforms/Strategies/Refresh/ScheduledRefresh.php
- app/Services/Platforms/PlatformRefresher.php
- app/Http/Controllers/Api/Platforms/GenericPlatformController.php
- app/Services/Platforms/Strategies/Connect/{Spotify,Bandcamp,Pinterest,Strava,Twitch,Vimeo,Youtube,YoutubeMusic}Connect.php
- app/Services/Platforms/Strategies/Highlights/{Bandcamp,Vimeo,Youtube,YoutubeMusic}Highlights.php
- app/Services/Platforms/WooCommerceScraper.php
- app/Services/Platforms/YoutubeScraper.php
- supabase/migrations/20260711000300_early_access_signups.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 25 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

## P3 — Nice to have

## Suggested Bundled Sessions

    - **Why grouped:** Same file (`EarlyAccessService.php`), same three lifecycle methods (`signupFromMarketing`, `invite`, `markSignedUp`) — one focused session touching one file.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** Both are idempotency/dedup gaps in the notification-dispatch path (job-level cache guard vs. dispatcher-level dedupe key), reviewable together against `NotificationPublisher`'s existing correct dedup pattern.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** All three are narrow-window race/degradation fixes on account-adjacent write paths (deletion confirm, content-selection toggle, auth-middleware cache invalidation); similar size and risk profile, reviewable as one session.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** All three are lock-safety gaps in the same connect/enrich/auto-sync subsystem (`GoogleBusinessEnrichJob`, `InstagramConnectJob`, `GoogleBusinessAutoSync`) writing to the same `IntegrationConnection` model family.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** Identical root cause (synchronous vendor fetch inside `GenericPlatformController::connect()`) and identical fix shape across 8 `ConnectStrategy` implementations — best executed as one mechanical pass establishing the async pattern once, then applied per-file.
    - **Model:** Plan: Opus (design the one shared async pattern) · Implement: Sonnet (apply per-file) · Review: Sonnet.

    - **Why grouped:** Same root cause and fix shape as Bundle 5, but on the `recent()` picker endpoint's `HighlightsStrategy` implementations — natural follow-on once Bundle 5's pattern exists.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** Same fix shape (add discriminating `Log::warning` on existing silent-null paths) across `YoutubeScraper` and `WooCommerceScraper` — cheap, mechanical, low-risk session.
    - **Model:** Plan+Implement combined (Sonnet, XS-per-item) · Review: Sonnet.

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-11-scaling-antipatterns.md ═══════════ -->

# Scaling Antipatterns Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Scaling antipatterns: write amplification, rebuild-on-write, weak caching — per-event fan-out that scales with data cardinality instead of request rate, aggregate rebuilds on single writes, and caches lacking single-flight/jitter/push-invalidation.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Observers/User/UserObserver.php
- app/Services/Analytics/AnalyticsCacheService.php
- app/Services/Analytics/AnalyticsDedupGuard.php
- app/Services/Analytics/ContentFreshness.php
- app/Services/Analytics/ContentPopularityReader.php
- app/Services/Analytics/Ingestors/QueuedIngestor.php
- app/Services/Analytics/RankedActionsComputer.php
- app/Services/Analytics/Writers/PostgresEventWriter.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/UserCacheService.php
- app/Services/Notifications/Dispatchers/AchievementNotifier.php
- app/Services/Notifications/Dispatchers/PlatformHealthNotifier.php
- app/Services/Notifications/NotificationPublisher.php
- app/Services/Segments/SegmentResolver.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

## Suggested Bundled Sessions

    - **Why grouped:** Same root-cause pattern (per-event/per-recipient DB or queue round-trip in a `foreach`, currently latent/unreachable in production) across the two write-heavy surfaces named in the lens (analytics ingest, notification fan-out). Neither is urgent; both are mechanical batching fixes that converge on the same chunking idiom.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet — no escalation needed, both are mechanical batching changes with existing in-repo reference implementations (bulk `insertOrIgnore()` for CACHE-1's sibling arrays; chunked dispatch patterns elsewhere in the notification fan-out surface for CACHE-2).

## Standalone — do NOT bundle

None — neither finding touches auth/authorization, money, or a DB migration/schema change, and both are M-effort.


<!-- ═══════════ audit-2026-07-11-database-and-queue-scaling.md ═══════════ -->

# Database & Queue Scaling Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Database & queue scaling: N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Console/Commands/BackfillWebsiteAnalysesCommand.php
- app/Console/Commands/ComputeContentPopularityScores.php
- app/Console/Commands/PruneNotifications.php
- app/Console/Commands/PurgeRawAnalyticsEvents.php
- app/Console/Commands/ResolveAllDesignPresetsCommand.php
- app/Console/Commands/BackfillMediaPaletteCommand.php
- app/Http/Resources/SiteResource.php
- app/Http/Resources/Staff/StaffUserListResource.php
- app/Models/Core/Site/Site.php
- app/Models/Core/Site/ShopBrand.php
- app/Models/Core/Site/IntegrationConnection.php
- app/Jobs/Design/AnalyzeConnectionWebsitesJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Services/Notifications/NotificationPublisher.php
- app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php
- app/Http/Controllers/Api/Platforms/ShopController.php
- app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
- routes/console.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

## P3 — Nice to have

- [ ] **SCALE-5** · P3 — `AnalyzeConnectionWebsitesJob` re-queries `shopBrands()->get()` per connection at three separate call sites instead of eager-loading
    - **Where:** app/Jobs/Design/AnalyzeConnectionWebsitesJob.php:103, 189, 249-254
    - **Affects:** Users with more than one shop-platform connection, during design-analysis job runs (main `handle()` loop, its self-continue check, and the `failed()` kill-recovery re-dispatch check). Bounded by small per-user connection counts and by `MAX_ANALYSES_PER_RUN = 2`, which already caps work per invocation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->with('shopBrands')` to the `IntegrationConnection::query()` calls in both `handle()` and `failed()`.
        - Replace the `$connection->shopBrands()->get()` calls inside `connectionNeedsAnalyses()` and the main loop with property access (`$connection->shopBrands`) so they reuse the eager-loaded collection.
    - **Technical:** `handle()` fetches its connections with `->get()` but never eager-loads `shopBrands`. The main loop then calls `$connection->shopBrands()->get()` once per shop connection (line 189), and the static `connectionNeedsAnalyses()` helper — invoked both from `handle()`'s self-continue check (line 220) and `failed()`'s kill-recovery check (line 254) — independently calls `$connection->shopBrands()->get()` again. For a user with N shop connections this is roughly 2N-3N extra queries per job invocation. Per-user connection counts on this individual-sitepage platform stay small (a handful of platforms), and `MAX_ANALYSES_PER_RUN` already bounds the expensive scraping work, so this is real but low-impact inefficiency, not a scaling risk today.
    - **Plain English:** When the design engine checks a user's connected shops for outdated style analysis, it asks the database for that connection's list of shops separately, more than once, instead of asking once and reusing the answer. Because each user only has a few connections, this wastes a small amount of effort — worth tidying up when convenient, not urgent.
    - **Evidence:**
        ```php
        $connections = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereIn('platform', OutsideWebsitesFactor::SOURCE_PLATFORMS)
            ->get();
        ```
        ```php
        foreach ($connection->shopBrands()->get() as $brand) {
        ```
        ```php
        return $connection->shopBrands()->get()->contains(
            fn (ShopBrand $b) => self::brandNeedsAnalysis($b)
        );
        ```

## Suggested Bundled Sessions

    - **Why grouped:** Same root pattern (unbounded or unscoped work in `app/Console/Commands/`, scheduled or operator-invoked) with the same class of fix (batch/chunk/scope).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** Both are latent/low-impact N+1 traps around the design-kit and shop-brand read paths, same fix shape (eager-load or guard test), no urgency.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-11-schema-rls.md ═══════════ -->

# Schema / RLS / search_path Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Schema / RLS / search_path: database-side correctness, constraint coverage, migration safety
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `supabase/migrations/20260701150000_create_workplaces.sql`
- `supabase/migrations/20260704160000_shop_brands_products.sql`
- `supabase/migrations/20260705150000_workplaces_identity_columns.sql`
- `supabase/migrations/20260705150200_create_content_selection.sql`
- `supabase/migrations/20260707030000_shop_brand_modes.sql`
- `supabase/migrations/20260708120000_sites_shop_global_settings.sql`
- `supabase/migrations/20260709042716_create_content_popularity_scores.sql`
- `supabase/migrations/20260709042911_create_item_views.sql`
- `supabase/migrations/20260710140000_rls_policies_new_tables.sql`
- `supabase/migrations/20260711000000_staff_account_type.sql`
- `supabase/migrations/20260711000100_user_segments.sql`
- `supabase/migrations/20260711000200_feature_availability.sql`
- `supabase/migrations/20260711000300_early_access_signups.sql`
- `supabase/migrations/20260711153000_feedback_type_area_target.sql`
- `supabase/migrations/20260711160000_analytics_force_rls_parity.sql`
- `app/Models/Analytics/ItemView.php`
- `app/Models/Core/Segments/UserSegmentMember.php`
- `app/Models/Core/Site/ShopBrand.php`
- `app/Models/Core/Site/Site.php`
- `app/Console/Commands/PurgeRawAnalyticsEvents.php`
- `scripts/guard-no-unsafe-migrations.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 8 complete

---

## P2 — Should fix

## P3 — Nice to have

- [ ] **#SCHEMA-6** · P3 — `core.feedback.type` has a 4-value vocabulary but no `CHECK` constraint
    - **Where:** supabase/migrations/20260711153000_feedback_type_area_target.sql:46
    - **Affects:** Internal staff feedback-triage tool only — bad `type` values would confuse the staff list but touch no end-user or public surface.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ADD CONSTRAINT feedback_type_check CHECK (type IS NULL OR type IN ('error','good','bad_ui','idea')) NOT VALID` + `VALIDATE`.
    - **Technical:** The migration explicitly documents the 4-value vocabulary and the reasoning for skipping the constraint: the guard script's `NOT VALID`+`VALIDATE` split is "unwarranted complexity for a low-traffic internal tool," and the same table's `page_url`/`user_agent`/`viewport`/`app_version`/`request_id`/`reply_email` columns already carry no CHECK by the same precedent. `SubmitFeedbackRequest` is the enforcement point. This is a defensible, documented judgment call for a nullable column on a staff-only table — lower priority than the P2 findings above where an invalid value reaches a public-facing surface.
    - **Plain English:** The feedback form has four reaction buttons. The database column storing which button was pressed doesn't enforce those four values, so a future frontend bug could write a fifth value and the database would quietly accept it. Low risk (staff-only tool), trivial fix.
    - **Evidence:**
        ```sql
        ALTER TABLE core.feedback
            ADD COLUMN type text NULL
        ```

- [ ] **#SCHEMA-7** · P3 — `core.user_segment_members` is granted `UPDATE, DELETE` for `app_backend` despite being an insert-only table at the model layer
    - **Where:** supabase/migrations/20260711000100_user_segments.sql:55; app/Models/Core/Segments/UserSegmentMember.php:21
    - **Affects:** Defence-in-depth only — no code path issues UPDATE/DELETE against this table today.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `REVOKE UPDATE, DELETE ON core.user_segment_members FROM app_backend;` leaving `SELECT, INSERT` (mirrors the `audit.*` schema's SELECT/INSERT-only posture for append-only tables), or explicitly accept the current grant and drop this as a non-issue.
    - **Technical:** `UserSegmentMember` declares `public const UPDATED_AT = null` with the comment "membership rows are insert-only (created_at column only)" and has no `SoftDeletes` trait or delete method anywhere in the model. The migration's `GRANT SELECT, INSERT, UPDATE, DELETE ON core.user_segment_members TO app_backend;` is the standard boilerplate CRUD grant, not a deliberate append-only choice. The `audit.*` schema achieves append-only enforcement at exactly this privilege layer (SELECT/INSERT only) — this table could follow the same pattern, though the practical risk is nil since no application code path attempts UPDATE/DELETE.
    - **Plain English:** The segment-membership table is meant to be write-once — rows are added, never edited or removed by the app. But the database access card still technically allows edits and deletions. Nothing in the app tries to use that door, so this is tidying up a mismatch between the sign on the table and the keys that open it, not a live risk.
    - **Evidence:**
        ```sql
        GRANT SELECT, INSERT, UPDATE, DELETE ON core.user_segment_members TO app_backend;
        ```
        ```php
        public const UPDATED_AT = null; // membership rows are insert-only (created_at column only)
        ```

- [ ] **#SCHEMA-8** · P3 — `analytics.item_views` has no DB-level dedup key, relying entirely on app-side Redis
    - **Where:** supabase/migrations/20260709042911_create_item_views.sql (entire `CREATE TABLE`); app/Models/Analytics/ItemView.php:19
    - **Affects:** Duplicate rows on Redis outage or event re-delivery would inflate popularity scores until the 90-day purge window rolls them off.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - If the team wants DB-level self-healing regardless of Redis state, add a composite `UNIQUE (site_id, session_id, item_type, item_id)` (or narrower, matching the dedup grain) and switch the telemetry writer to `ON CONFLICT DO NOTHING`. Given the deliberate design tradeoff documented below, this can also be accepted as-is.
    - **Technical:** The model comment states plainly: "Dedup is app-side Redis (AnalyticsDedupGuard, 300s), not a DB column — same pattern as section-seen." This is a documented, deliberate tradeoff (a composite unique index adds write amplification to a high-ingest table) shared with the sibling `analytics.section_views` table, not an oversight. The practical exposure is bounded — duplicates only occur during a Redis outage, and rows purge after 90 days regardless.
    - **Plain English:** The "which items were viewed" table relies on a separate fast-cache service to prevent double-counting. If that cache has a hiccup, duplicate views could write directly to the database and briefly inflate popularity scores. Adding a database-level "no duplicates" rule would close that gap but slow down every write slightly — the team already made this tradeoff deliberately for the sibling table, and it's a defensible choice here too.
    - **Evidence:**
        ```php
        // Dedup is app-side Redis (AnalyticsDedupGuard
        // 300s), not a DB column — same pattern as section-seen.
        ```

- [ ] **#SCHEMA-9** · P3 — `core.user_segments` and `core.user_segment_members` have RLS enabled but not `FORCE ROW LEVEL SECURITY`
    - **Where:** supabase/migrations/20260711000100_user_segments.sql:51-52
    - **Affects:** Forward-looking defence-in-depth only.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE core.user_segments FORCE ROW LEVEL SECURITY;` and `ALTER TABLE core.user_segment_members FORCE ROW LEVEL SECURITY;` in a follow-up migration.
    - **Technical:** Created at timestamp `20260711000100`, before the `20260711160000_analytics_force_rls_parity.sql` hardening sweep landed later the same day. That sweep's own header is the controlling precedent for how this exact class of gap is calibrated in this repo: "the practical security delta here is near-nil today — these tables are owned by `postgres` (a superuser, which bypasses RLS regardless of FORCE) and the app connects as `app_backend` (BYPASSRLS)... This is consistency hygiene / forward-looking defence-in-depth... not a live exposure — re-tiered P3 from the audit's P2." That identical reasoning applies to these two tables, which carry the same owner/connection posture and were simply created a few hours before that sweep's cutoff.
    - **Plain English:** Two new staff-only tables got the first half of the lock installed but not the bolt that stops the table's owner from walking past it. In practice nobody can walk past it today — the owner is a superuser regardless. This is about matching the same pattern the team already applied to sibling tables the same day, so a future ownership change doesn't create a real gap.
    - **Evidence:**
        ```sql
        ALTER TABLE core.user_segments ENABLE ROW LEVEL SECURITY;
        ALTER TABLE core.user_segment_members ENABLE ROW LEVEL SECURITY;
        ```

- [ ] **#SCHEMA-10** · P3 — `core.feature_availability` has RLS enabled but not `FORCE ROW LEVEL SECURITY`
    - **Where:** supabase/migrations/20260711000200_feature_availability.sql:40
    - **Affects:** Same forward-looking defence-in-depth gap as `#SCHEMA-9`; this table controls which features/integrations are gated per segment.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE core.feature_availability FORCE ROW LEVEL SECURITY;` in a follow-up migration.
    - **Technical:** Created at `20260711000200`, same pre-sweep window as `#SCHEMA-9`. Same precedent from `20260711160000_analytics_force_rls_parity.sql` applies — table owner is a superuser, `app_backend` is `BYPASSRLS`, so this is consistency hygiene, not a live exposure.
    - **Plain English:** Same half-installed lock as the segments tables — the feature-gating table needs the same bolt to match every other hardened table in the database.
    - **Evidence:**
        ```sql
        ALTER TABLE core.feature_availability ENABLE ROW LEVEL SECURITY;
        ```

- [ ] **#SCHEMA-11** · P3 — `core.early_access_signups` has RLS enabled but not `FORCE ROW LEVEL SECURITY`
    - **Where:** supabase/migrations/20260711000300_early_access_signups.sql:47
    - **Affects:** Same forward-looking defence-in-depth gap as `#SCHEMA-9`/`#SCHEMA-10`; this table holds PII (email, consent IP hash, invite token hash), slightly raising the stakes of a future ownership-change scenario even though today's exposure is nil.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE core.early_access_signups FORCE ROW LEVEL SECURITY;` in a follow-up migration.
    - **Technical:** Third table in the same pre-sweep batch (`20260711000300`, before `20260711160000`). Same superuser-owner / `BYPASSRLS`-connection precedent applies — no live exposure today, but the PII content makes this the highest-priority item among the three FORCE-only gaps.
    - **Plain English:** The early-access signup list — with emails and invite tokens — has the same half-installed lock as the other two staff tables from the same day. Given it holds people's contact details, it's worth bolting first among this group.
    - **Evidence:**
        ```sql
        ALTER TABLE core.early_access_signups ENABLE ROW LEVEL SECURITY;
        ```

- [ ] **#SCHEMA-12** · P3 — `site.content_selection` has no RLS at all
    - **Where:** supabase/migrations/20260705150200_create_content_selection.sql:13-16
    - **Affects:** Defence-in-depth against PostgREST/Supabase client leakage. `app_backend` carries `BYPASSRLS` for the app path, so exposure is limited to a misconfigured PostgREST role.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `ENABLE ROW LEVEL SECURITY` + `FORCE ROW LEVEL SECURITY` + owner-read/staff-read/`app_backend`-all policies, mirroring `20260710140000_rls_policies_new_tables.sql`'s shape for `analytics.item_views`.
        - Add a regression-guard test in `tests/Feature/Security/` mirroring `DesignKitsRlsTest`.
    - **Technical:** The migration's own comment frames this as a deliberate choice to match `site.workplaces` ("RLS is OFF to match the sibling 1:1 app-managed table site.workplaces — access is gated in the Laravel policy layer... not at the DB"). Per the `20260711160000_analytics_force_rls_parity.sql` precedent (see `#SCHEMA-9`), this repo consistently re-tiers RLS-posture gaps on `postgres`-owned, `app_backend`-only tables down from P2 to P3 — "not a live exposure... consistency hygiene / forward-looking defence-in-depth." The same logic applies here: no RLS at all is a larger gap than missing-FORCE, but the practical exploitability is identical (zero, absent a PostgREST misconfiguration), so the tier should match the repo's own calibration rather than DeepSeek's un-cross-checked P2.
    - **Plain English:** Every tenant-data cupboard in the database has a lock, except this one — the note on it says "we lock it at the front door instead" (the application code), which is true today since nothing can reach this cupboard except through that front door. The lock is still worth adding for when a second door gets built, but it isn't urgent.
    - **Evidence:**
        ```sql
        -- position is 1..15, unique per site. RLS is OFF to match the sibling 1:1
        -- app-managed table site.workplaces — access is gated in the Laravel policy
        -- layer (ContentSelectionPolicy), not at the DB. app_backend gets the standard
        -- CRUD grant.
        ```

- [ ] **#SCHEMA-13** · P3 — `site.workplaces` has no RLS at all
    - **Where:** supabase/migrations/20260701150000_create_workplaces.sql (entire `CREATE TABLE`)
    - **Affects:** Same exposure class as `#SCHEMA-12`; `site.workplaces` holds PII-adjacent identity fields (name, address, phone, opening hours, contact email) — the most sensitive of the RLS-only-gap findings in this audit.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `ENABLE ROW LEVEL SECURITY` + `FORCE ROW LEVEL SECURITY` + owner-read/staff-read/`app_backend`-all policies.
        - Add a regression-guard test in `tests/Feature/Security/` mirroring `DesignKitsRlsTest`.
    - **Technical:** `site.workplaces` is the table `#SCHEMA-12`'s migration names as the pattern it deliberately matches — confirming it too has zero RLS. Same `20260711160000_analytics_force_rls_parity.sql` precedent applies for tier calibration: `postgres`-owned, `app_backend`-only (`BYPASSRLS`) tables get this treatment as P3 consistency hygiene, not P2 live exposure, in this repo's own established practice. Ordered last in this tier because it carries the most sensitive data (business name/address/phone/email) of the group.
    - **Plain English:** The workplace card table — business names, addresses, phone numbers — is the other cupboard without a lock, matched deliberately to the content-selection cupboard next to it. Worth locking given what it holds, but not urgent since nothing can reach it except the application's own front door today.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS site.workplaces (
            site_id          uuid PRIMARY KEY REFERENCES site.sites (id) ON DELETE CASCADE
            name             text
            address          text
        ```

## Suggested Bundled Sessions

None — every finding in this audit is a `supabase/migrations/` schema change, and per the fix-flow doctrine every DB migration/schema change runs standalone with its own plan + sign-off, never bundled.

## Standalone — do NOT bundle

- **#SCHEMA-6 — feedback.type CHECK constraint** · DB migration/schema change.
- **#SCHEMA-7 — user_segment_members grant revoke** · DB migration/schema change (privilege grants).
- **#SCHEMA-8 — item_views dedup key** · DB migration/schema change (index + app writer change).
- **#SCHEMA-9 — user_segments/user_segment_members FORCE RLS** · DB migration/schema change.
- **#SCHEMA-10 — feature_availability FORCE RLS** · DB migration/schema change.
- **#SCHEMA-11 — early_access_signups FORCE RLS** · DB migration/schema change.
- **#SCHEMA-12 — content_selection RLS + policies + test** · DB migration/schema change.
- **#SCHEMA-13 — workplaces RLS + policies + test** · DB migration/schema change.


<!-- ═══════════ audit-2026-07-11-caching-gold-standard.md ═══════════ -->

# Caching Gold-Standard Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Caching: gold-standard adherence — deviations from `CacheLockService::rememberLocked` / `SiteCacheService::getPublicSitePayload` gold standard (single-flight locks, TTL jitter, stale-while-revalidate, push-invalidation, version tokens, lock hygiene, bounded TTLs, centralised key generation)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Middleware/AddPublicCacheHeaders.php
- app/Http/Middleware/Auth/EnsurePartnaAdmin.php
- app/Http/Middleware/Auth/EnsurePartnaStaff.php
- app/Http/Middleware/Auth/RequireAal2.php
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php
- app/Http/Middleware/Context/LoadCurrentUser.php
- app/Http/Middleware/IdempotencyKey.php
- app/Http/Middleware/Logging/LogLeadRateLimits.php
- app/Http/Middleware/Logging/RecordStaffAuditEntry.php
- app/Http/Middleware/Moderation/PerTargetReportThrottle.php
- app/Http/Middleware/VerifyBotToken.php
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Accounts/AccountCapabilitySet.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/SiteCacheService.php
- app/Services/Cache/UserCacheService.php
- app/Services/FeatureAvailability/FeatureAvailability.php
- app/Services/FeatureAvailability/UserFeatureAvailability.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/PublicSite/SiteActionsService.php
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Services/Site/ContentSelectionService.php
- app/Services/Site/UpdateSiteAction.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Observers/User/UserObserver.php
- app/Services/Analytics/AnalyticsCacheService.php
- app/Services/Analytics/AnalyticsDedupGuard.php
- app/Services/Analytics/AnalyticsEvent.php
- app/Services/Analytics/AnalyticsQueryService.php
- app/Services/Analytics/Concerns/EscalatesRepeatedFaults.php
- app/Services/Analytics/ContentFreshness.php
- app/Services/Analytics/ContentPopularityReader.php
- app/Services/Analytics/Ingestors/QueuedIngestor.php
- app/Services/Analytics/InsightEngine.php
- app/Services/Analytics/RankedActionsComputer.php
- app/Services/Analytics/Writers/PostgresEventWriter.php
- app/Services/Notifications/Dispatchers/AchievementNotifier.php
- app/Services/Notifications/Dispatchers/PlatformHealthNotifier.php
- app/Services/Notifications/NotificationPublisher.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

## P3 — Nice to have

- [ ] **#CCH-6** · P3 — `FeatureAvailability` builds cache keys with ad-hoc string concatenation instead of `CacheKeyGenerator`
    - **Where:** app/Services/FeatureAvailability/FeatureAvailability.php:33, 35, 42
    - **Affects:** Maintainability only today (single reader/writer, same class) — future readers (e.g. a staff preview endpoint) risk a silent typo-miss without a shared helper.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `featureAvailability(string $userId, int $version): string` and `featureAvailabilityVersion(): string` to `CacheKeyGenerator` and call them from `FeatureAvailability::for()` / `flush()`.
    - **Technical:** Every other cache-service in `app/Services/Cache/` sources its keys from `CacheKeyGenerator` (per the gold standard's category 8). `FeatureAvailability` defines `'feature-availability:version'` and `"feature-availability:user:{$user->id}:v{$version}"` inline. No drift exists yet because reader and writer are colocated in one class, but it's the one cache-touching class in scope that doesn't follow the central-key-registry convention.
    - **Plain English:** The cache keys for feature flags are handwritten strings inside one file instead of coming from the app's central "key directory." It works today because nothing else reads them, but if a second feature (like a staff preview tool) needs the same keys later, someone could mistype the string and silently miss the cache — like keeping one spare house key in a drawer instead of the labeled key cabinet everyone else uses.
    - **Evidence:**
        ```php
        private const CACHE_VERSION_KEY = 'feature-availability:version';

        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 0);

        $overrides = Cache::remember(
            "feature-availability:user:{$user->id}:v{$version}"
        ```

## Suggested Bundled Sessions

- **Bundle 1 — FeatureAvailability caching hardening:** , , #CCH-6
    - **Why grouped:** Same file (`app/Services/FeatureAvailability/FeatureAvailability.php`), same root fix — routing `for()` through `CacheLockService::rememberLocked` closes the single-flight, jitter, and SWR gaps in one edit; the exception-swallowing () and key-centralisation (#CCH-6) cleanups are trivial adjacent changes to the same ~10 lines.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (all S-effort; no escalation needed).

    - **Why grouped:** Single finding, isolated to `AnalyticsCacheService::computeInsights` — distinct file/service from Bundle 1, no shared fix.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-11-webhook-idempotency.md ═══════════ -->

# Inbound Callbacks & Idempotency Semantics Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Inbound callbacks & idempotency semantics — Supabase auth/email hooks, `bot.token`-gated internal endpoints, and the client-supplied `IdempotencyKey` middleware, measured against the Standard Webhooks gold standard (HMAC-before-parse, atomic idempotency anchors, no silent-200-on-failure, out-of-order tolerance).
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php
- app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php
- app/Http/Controllers/Api/Internal/EnvCheckController.php
- app/Http/Middleware/Auth/VerifySupabaseHookSignature.php
- app/Services/Webhooks/StandardWebhookVerifier.php
- app/Services/Auth/AuthFactorEventRepository.php
- app/Services/Notifications/SupabaseEmailEventService.php
- app/Http/Middleware/IdempotencyKey.php
- app/Http/Middleware/VerifyBotToken.php
- app/Http/Middleware/Logging/LogLeadRateLimits.php
- app/Http/Controllers/Api/User/Account/UserAccountDeletionController.php
- app/Services/User/AccountDeletionService.php
- routes/api.php
- routes/api/user.php
- app/Providers/AppServiceProvider.php (rate limiters)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

## Suggested Bundled Sessions

    - **Why grouped:** same route group (`/me/deletion/*`), same middleware file, same underlying subsystem — fixing enforcement and reordering can land in one pass over `routes/api/user.php` + `IdempotencyKey.php` + `AccountDeletionService.php`.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-11-transaction-boundaries.md ═══════════ -->

# Transaction Boundary Correctness Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Transaction boundary correctness — DB::transaction / DB::beginTransaction sites measured against the gold-standard discipline (no external I/O, no queue dispatch, no cache writes, no side-effecting observers inside the atomic unit; bounded scope; safe retries; intentional nesting; consistent lock ordering)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Observers/Core/IntegrationConnectionObserver.php`
- `app/Observers/User/UserObserver.php`
- `app/Observers/Core/SiteObserver.php` (adjacent — referenced by TXN-1 claim)
- `app/Services/Accounts/AccountCapabilities.php`
- `app/Services/Accounts/AccountCapabilitySet.php`
- `app/Services/Feedback/FeedbackService.php`
- `app/Services/Moderation/EvidenceSnapshotService.php`
- `app/Services/Site/ContentSelectionService.php`
- `app/Services/Site/UpdateSiteAction.php`
- `app/Services/Site/RenameSubdomainAction.php` (adjacent — invoked inside `UpdateSiteAction`'s transaction)
- `app/Services/User/AccountDeletionService.php`
- `app/Jobs/Account/SendAccountDeletionRequestMailJob.php` (adjacent — verifies afterCommit claim)
- `app/Services/User/DataExport/DataExportPayloadBuilder.php`
- `app/Services/User/SectionVisibilityService.php`
- `app/Services/User/SiteProvisioningService.php`
- `app/Services/User/UserBootstrapService.php`
- `app/Jobs/Analytics/RecordAnalyticsEventJob.php`
- `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php`
- `app/Jobs/Design/AnalyzeConnectionWebsitesJob.php`
- `app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php`
- `app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php`
- `app/Jobs/Platforms/GoogleBusinessEnrichJob.php`
- `app/Jobs/Platforms/InstagramConnectJob.php`
- `app/Jobs/Platforms/MenuFetchJob.php`
- `app/Jobs/ProcessLogoVariantsJob.php`
- `app/Services/Platforms/ShopCatalog.php`
- `app/Providers/EventServiceProvider.php` (adjacent — confirms observer registration)
- `vendor/laravel/framework/src/Illuminate/Queue/Queue.php` (adjacent — confirms job-level `afterCommit` semantics)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

## Suggested Bundled Sessions

    - **Why grouped:** Same root-cause pattern in two different subsystems — a flag/status column commits in its own transaction ahead of a dependent child-row rebuild that runs in a second, separate transaction, so a failure in the second leaves the flag/status pointing at content that doesn't exist. Same fix shape (widen the transaction to cover both writes) applies to both.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-11-data-integrity.md ═══════════ -->

# Data Integrity & Privacy Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Data integrity & privacy: FK hygiene, soft-delete coherence, orphan rows, JSONB drift, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- supabase/migrations/20260704160000_shop_brands_products.sql
- supabase/migrations/20260705150200_create_content_selection.sql
- supabase/migrations/20260707030000_shop_brand_modes.sql
- supabase/migrations/20260705150000_workplaces_identity_columns.sql
- supabase/migrations/20260708124853_staff_audit_log_ip_hash_and_get_reads.sql
- supabase/migrations/20260701150000_create_workplaces.sql
- supabase/migrations/20260711000100_user_segments.sql
- supabase/migrations/20260526000000_baseline_standalone_user.sql
- app/Models/Core/EarlyAccess/EarlyAccessSignup.php
- app/Services/User/DataExport/DataExportPayloadBuilder.php
- app/Services/User/AccountDeletionService.php
- app/Models/Core/Site/SiteMedia.php
- app/Observers/Core/SiteMediaObserver.php
- app/Http/Controllers/Api/User/Uploads/UserUploadController.php
- app/Models/Core/Site/IntegrationConnection.php
- app/Http/Controllers/Api/Platforms/ShopController.php
- app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php
- app/Services/User/UserBootstrapService.php
- app/Console/Commands/PurgeSoftDeleted.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

## P3 — Nice to have

- [ ] **#DINT-3** · P3 — `site.shop_brands` TEXT enum columns (`provider`, `selection_mode`, `link_mode`) have no DB CHECK constraint
    - **Where:** supabase/migrations/20260704160000_shop_brands_products.sql:13,21; supabase/migrations/20260707030000_shop_brand_modes.sql:19-22
    - **Affects:** Shop brand data — a direct DB write, buggy job, or migration error can insert an invalid `provider`, `selection_mode`, or `link_mode` value that the app's `toBrandArray()` coalesce logic wasn't written to expect.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `CHECK (selection_mode IN ('manual', 'latest'))` and `CHECK (link_mode IN ('product', 'checkout'))` to `site.shop_brands` — both are documented as closed, two-value vocabularies in the migration's own header comment.
        - Leave `provider` unconstrained if the provider list is expected to keep growing (adding a scraper = adding a provider), or add a CHECK plus a follow-up migration each time a provider is added — a call worth making explicitly rather than by omission.
    - **Technical:** Both migrations state "no CHECK constraints, matching the SQLite-test-mirror convention" as the deliberate rationale. That's a real, repeated architectural choice (also used for `site.sites.shop_link_mode`), not an oversight — but it stands in contrast to the sibling table `site.content_selection`, created the following day, which enumerates its own closed vocabulary with a DB `CHECK (entry_type IN (...))`. `selection_mode` and `link_mode` are both closed two-value sets per the migration's own comment (`'manual' or 'latest'`; `'product' or 'checkout'`), so a CHECK costs nothing at the SQLite-parity level test writers would need to special-case (a CHECK constraint that Postgres enforces and SQLite silently accepts is not a test-breaking difference — CHECK is standard SQLite syntax) and closes the same class of gap this codebase has already been bitten by (per project CLAUDE.md: prod-only CHECK/NOT NULL violations passing CI green on SQLite and then 500ing on Postgres, "bit the async Instagram connect twice").
    - **Plain English:** The database has text fields that are supposed to contain specific words like "manual" or "checkout." The database itself doesn't enforce this — it's like a form field with no validation on the server side. The app's front door checks your input, but if someone uses the side door (a migration, a background job, a direct query), they can write gibberish and the database will happily accept it. A sibling table built the very next week for a similar purpose *does* enforce this at the database level, so this is an inconsistency worth closing rather than a hard architectural constraint.
    - **Evidence:**
        ```sql
        -- provider — no CHECK:
        provider       text NOT NULL
        ```
        ```sql
        -- selection_mode, link_mode — no CHECK, despite a closed 2-value vocabulary:
        ADD COLUMN IF NOT EXISTS selection_mode text NOT NULL DEFAULT 'manual'
        ADD COLUMN IF NOT EXISTS link_mode text NOT NULL DEFAULT 'product'
        ```
        ```text
        -- Migration comment confirms deliberate omission:
        "Values validated in the request layer (UpdateShopBrandRequest) — no CHECK
        constraints, matching the SQLite-test-mirror convention."
        ```

## Suggested Bundled Sessions

    - **Why grouped:** single-file pair (export builder + deletion service), same root cause (new table shipped same-day without GDPR wiring).
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).
    - **Why grouped:** single migration + single guard method; standalone anyway per the DB-migration rule below, listed here only for theming reference.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

- **#DINT-3 — shop_brands CHECK constraints** · DB migration (adds constraints to a live table) — run alone with its own plan + sign-off.


<!-- ═══════════ audit-2026-07-11-job-queue-correctness.md ═══════════ -->

# Job/Queue Correctness Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Job/Queue Correctness — idempotency, retry safety, ShouldBeUnique, missing `$this->fail()`, retry storms
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Design/AnalyzeConnectionWebsitesJob.php
- app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Platforms/GoogleBusinessEnrichJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Jobs/ProcessLogoVariantsJob.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

## P3 — Nice to have

- [ ] **#JOB-5** · P3 — SendTransactionalNotificationEmailJob has no `ShouldBeUnique` guard, so duplicate dispatches contend for its row lock instead of coalescing
    - **Where:** app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php:23-25
    - **Affects:** Transactional email dispatch; wasted worker/DB time when the same notification is dispatched more than once, no data-correctness impact
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `ShouldBeUnique` to the `implements` clause.
        - Add `public function uniqueId(): string { return $this->notificationId; }`.
        - Set a short `$uniqueFor` (e.g. 60–120s, comfortably under the job's own `$timeout = 30`) so a hung worker can't wedge the lock past the job's own execution budget.
    - **Technical:** `handle()` already wraps the read-and-check of `email_sent_at` in `DB::transaction(fn () => Notification::query()->lockForUpdate()->find(...))`, so correctness (no duplicate send) is already guaranteed at the data layer. Without `ShouldBeUnique`, however, duplicate dispatches for the same `notificationId` both get pulled off the queue and one blocks on the pessimistic lock until the other completes, burning a worker slot and a DB round-trip for no benefit. `ShouldBeUnique` would coalesce the duplicate at the queue layer before it ever reaches the lock.
    - **Plain English:** Two workers can currently both pick up the job to send the same notification email — the database lock stops a duplicate email from actually going out, but the second worker still wastes time waiting its turn instead of never being sent at all. Telling the queue "only run one of these per notification" avoids that wasted work.
    - **Evidence:**
        ```php
        class SendTransactionalNotificationEmailJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
        ```

## Suggested Bundled Sessions

    - **Why grouped:** Identical root cause (paid vendor scrape re-run on a job's own retry because no pre-call "processing" marker exists) across three sibling files in `app/Jobs/Platforms/`; the fix pattern is the same state-machine addition in each.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (per file's Execution policy).

- **Bundle 2 — Notification uniqueness hygiene:** #JOB-5
    - **Why grouped:** Single small hygiene fix, no shared file/pattern with other findings.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (combine plan+implement — S effort).

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-11-observability.md ═══════════ -->

# Observability Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Observability: logging gaps, silent failures, missing Nightwatch instrumentation — jobs that swallow exceptions silently, inbound callbacks that 200-but-don't-process, missing Nightwatch coverage, log calls that obscure rather than illuminate
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Console/Commands/ComputeContentPopularityScores.php
- app/Console/Commands/BackfillMediaPaletteCommand.php
- app/Console/Commands/BackfillWebsiteAnalysesCommand.php
- app/Console/Commands/ResolveAllDesignPresetsCommand.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Jobs/Platforms/GoogleBusinessEnrichJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Moderation/Concerns/HasActionLogLifecycle.php
- app/Services/Audit/StaffAuditService.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Services/Media/ImageVariantService.php
- app/Services/Moderation/EvidenceSnapshotService.php
- app/Services/Platforms/FreshaScraper.php
- app/Services/Platforms/ShopCatalog.php
- app/Services/Platforms/AppleSearch.php
- app/Services/Platforms/YoutubeScraper.php
- app/Services/Platforms/WooCommerceScraper.php
- app/Services/Platforms/PlatformRefresher.php
- app/Services/Platforms/Strategies/Fetch/ShopFetch.php
- app/Services/Platforms/Strategies/Fetch/FreshaFetch.php
- app/Services/Platforms/Strategies/Fetch/AppleMusicFetch.php
- app/Services/Platforms/Strategies/Fetch/YoutubeFetch.php
- app/Http/Controllers/Api/Platforms/ShopController.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

## Suggested Bundled Sessions

    - **Why grouped:** Same root-cause pattern (a fetch/scrape failure returns null/empty and is recorded as a quiet non-alerting status) across the Platforms scraper/job layer;  and  additionally require tracing into `PlatformRefresher`/`ShopFetch`/`FreshaFetch`, so reviewing them together avoids re-deriving that context twice.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).  touches the shared `PlatformRefresher` failure-classification path used by every platform — escalate implement → Opus for that item specifically, or split it into its own standalone session (see below) if the plan reveals broader blast radius.

    - **Why grouped:** All three are "add `report()`/`$timeout`, keep existing behavior" changes with no logic changes to the surrounding fail-open design — low-risk, mechanical, and independent of each other.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet); combine plan+impl given the small size of each change.

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-11-caching-coverage-gaps.md ═══════════ -->

# Caching Coverage Gaps Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Caching coverage gaps — hot, expensive reads with no cache at all (public sitepage resolution, handle/profile resolution, account-capability lookups, dashboard controllers, synchronous vendor reads)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Http/Controllers/Api/PublicSite/AnalyticsController.php`
- `app/Http/Controllers/Api/PublicSite/BootstrapController.php`
- `app/Http/Controllers/Api/PublicSite/PublicEarlyAccessController.php`
- `app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php`
- `app/Http/Controllers/Api/PublicSite/PublicMenuController.php`
- `app/Http/Middleware/AddPublicCacheHeaders.php`
- `app/Http/Middleware/Auth/{EnsurePartnaAdmin,EnsurePartnaStaff,RequireAal2,VerifySupabaseJwt}.php`
- `app/Http/Middleware/Context/{EnforcePendingDeletionReadOnly,LoadCurrentUser}.php`
- `app/Http/Middleware/{IdempotencyKey,VerifyBotToken}.php`
- `app/Http/Middleware/Logging/{LogLeadRateLimits,RecordStaffAuditEntry}.php`
- `app/Http/Middleware/Moderation/PerTargetReportThrottle.php`
- `app/Services/Accounts/{AccountCapabilities,AccountCapabilitySet}.php`
- `app/Services/Cache/{CacheKeyGenerator,SiteCacheService,UserCacheService}.php`
- `app/Services/FeatureAvailability/{FeatureAvailability,UserFeatureAvailability}.php`
- `app/Services/PublicSite/{IndividualProfilePayloadBuilder,SiteActionsService,SitepageDataResolverService}.php`
- `app/Services/Site/{ContentSelectionService,UpdateSiteAction}.php`
- `app/Services/Platforms/**/*.php` (all connect/fetch/highlights strategies, scrapers, registry, payloads)
- `app/Services/Analytics/ContentPopularityReader.php` (adjudicator addition)
- `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php` (adjudicator addition)
- `routes/api.php`, `routes/api/publicSite.php`, `bootstrap/app.php` (adjudicator addition — route/middleware verification)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

No findings survived adjudication. Summary of verification performed beyond DeepSeek's three chunks:

- **Public profile payload (`IndividualProfileController::show`)** — confirmed the canonical reference implementation: `handle.resolve` cache (30s, `rememberLocked`) → `public.profile:{handle}:{updated_at_ts}` cache (60s, `rememberLocked`, SWR via `SiteCacheService`/`CacheLockService`) wraps the *entire* `IndividualProfilePayloadBuilder::build()` call, which is where the expensive fan-out (`SitepageDataResolverService::presentPageIds`, `getGallery`, `getLinks`, `ContentSelectionService::resolve`, `ContentPopularityReader::forSite`, design-kit read, etc.) actually lives. None of that fan-out is a coverage gap — it's already inside the cache boundary.
- **Auth path (`LoadCurrentUser` → `UserCacheService::getByAuthId`)** — confirmed two-level cache (30min immutable id-map + 60s SWR hydrated-model cache via `CacheLockService::rememberLockedNullable`), matching the doctrine's canonical reference implementation.
- **`PublicMenuController` / `PublicIntegrationController`** — confirmed both routes (`/public/profiles/{handle}/menu`, `/integrations`, `/platforms`) fall under `AddPublicCacheHeaders::CACHEABLE_PATH_PREFIXES` (`api/public/profiles`), which is appended to the global `api` middleware group in `bootstrap/app.php`. Every response gets `Cache-Control: public, max-age=900, s-maxage=900` — the CDN is genuinely the cache layer here, matching the explicit design comment in `PublicIntegrationController`. `ContentPopularityReader::forSite()` (called directly by both controllers, outside any backend cache) is a single indexed `WHERE site_id = ?` returning a small per-site row set — not an aggregate/join/JSONB-scan, so it doesn't clear the "expensive" bar even setting the CDN aside.
- **`AccountCapabilities::for()`** — per-request `WeakMap` memo; the underlying computation reads already-hydrated `User` attributes with no DB query except `staffRole()` for staff accounts (rare, single indexed lookup) — not a coverage gap under the lens (memoization scope matches the value's actual invalidation lifetime; a Redis-level cache would add invalidation surface for a value cheaper than the lookup that would invalidate it).
- **`FeatureAvailability::for()`** — already implements the category-5 canonical fix pattern exactly (`Cache::remember` behind a version token bumped on write).
- **`MenuSource`, `AppleSearch`** — confirmed per-instance memoization / `Cache::get`+`Cache::put` wrapper respectively; both are single indexed reads or already cached vendor calls.

No read in scope is simultaneously hot, expensive, and repeated with zero cache of any kind. This is a clean result, not an unscanned one.

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-11-privacy-compliance.md ═══════════ -->

# Privacy & Data-Rights Compliance Audit — 2026-07-12

**Branch:** HEAD
**Lens:** Privacy & data-rights compliance: PII inventory, export/delete completeness, retention enforcement, processor flows (bundle: rights-machinery + collection-retention-1/2 + schema-pii chunks)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- `app/Services/User/AccountDeletionService.php`
- `app/Services/User/DataExport/DataExportPayloadBuilder.php`
- `app/Models/Core/EarlyAccess/EarlyAccessSignup.php`
- `app/Services/Audit/StaffAuditService.php`
- `app/Services/Moderation/EvidenceSnapshotService.php`
- `app/Services/Analytics/Writers/PostgresEventWriter.php`
- `app/Services/Analytics/AnalyticsEventSanitizer.php`
- `app/Http/Controllers/Concerns/DetectsClientInfo.php`
- `app/Http/Resources/WorkplaceResource.php`
- `app/Models/Core/User/User.php`
- `app/Console/Commands/PruneNotifications.php`
- `app/Console/Commands/PurgeRawAnalyticsEvents.php`
- `config/partna.php`
- `routes/console.php`
- `routes/api/staff.php`
- `supabase/migrations/20260711000300_early_access_signups.sql`
- `supabase/migrations/20260705150000_workplaces_identity_columns.sql`
- `supabase/migrations/20260705150100_users_sector_columns.sql`
- `supabase/migrations/20260704160000_shop_brands_products.sql`
- `supabase/migrations/20260711153000_feedback_type_area_target.sql`
- `supabase/migrations/20260706000000_add_city_to_site_visits.sql`
- `supabase/migrations/20260707020000_site_visits_lat_lon.sql`
- `supabase/migrations/20260526000000_baseline_standalone_user.sql`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 4 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 4 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

## P3 — Nice to have

- [ ] **PRIV-10** · P3 — Stale Shopify-era docblock and dead `RedactShopJob` reference in the GDPR config section
    - **Where:** `config/partna.php:1468-1479` (`gdpr` section docblock)
    - **Affects:** No live data — documentation-only drift that could mislead a future privacy audit about what the `gdpr` config section actually governs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Rewrite the docblock to describe what `gdpr.*` actually governs today (the Partna professional data-export pipeline: `export_retention_days`, `signed_url_ttl_days`, `dedup_window_minutes`), removing the Shopify/`RedactShopJob` references.
    - **Technical:** The docblock reads "Config for Shopify GDPR webhook handlers... `RedactShopJob` can take several minutes" — `RedactShopJob` does not exist anywhere in `app/` (confirmed via repo-wide search; only archived migrations and historical audit docs reference it). Commerce/Shopify was removed 2026-05-22 per the standalone strip-down. The `export_retention_days`/`signed_url_ttl_days`/`dedup_window_minutes` keys underneath are live and correctly serve the Partna export pipeline (enforced by the `gdpr:prune-completed-exports` scheduled command) — only the prose above them is stale.
    - **Plain English:** The instruction manual for this config section still describes a Shopify feature that was removed months ago, including a cleanup job that no longer exists in the code. The actual settings below the comment are fine and in active use — just the explanation above them is out of date, which could waste a future auditor's time chasing a dead code path.
    - **Evidence:**
        ```php
        /*
        | Config for Shopify GDPR webhook handlers. Jobs dispatch onto a dedicated
        | queue so they don't contend with the default worker on a mature shop
        | (RedactShopJob can take several minutes). The placeholder domain is used
        | when anonymising customer email addresses...
        */
        'gdpr' => [
            'queue' => env('PARTNA_GDPR_QUEUE', env('GDPR_QUEUE', 'gdpr'))
            'redact_placeholder_domain' => env('GDPR_REDACT_PLACEHOLDER_DOMAIN', 'gdpr.partna.au')
            'export_retention_days' => (int) env('GDPR_EXPORT_RETENTION_DAYS', 30)
            ...
        ]
        ```

- [ ] **PRIV-11** · P3 — Default seeded contact card uses a real, platform-uncontrolled domain (`charlie@ai.com`)
    - **Where:** `config/partna.php:852-858` (`account_type_defaults.individual.default_contact`)
    - **Affects:** New individual accounts before the professional customises their public contact card — any code path that acts on the default before it's overwritten sends mail to a real stranger's inbox rather than nowhere.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Replace `charlie@ai.com` with `example@example.com` (RFC 2606 reserved) or a `partna.au` address the platform controls.
        - Replace `1234 567 890` with a reserved fictional-use number.
    - **Technical:** `ai.com` is a real, resolvable domain with an MX record, owned by a third party. The seed value is meant to be overwritten by the professional, but nothing in the codebase guarantees that happens before any code path (e.g. a render of the public contact block, or a future welcome-email touch) could act on it. RFC 2606 reserves `example.com`/`example.org`/`example.net` for exactly this scenario.
    - **Plain English:** Every new account starts with placeholder contact info — a made-up name, "charlie@ai.com," and a fake phone number. The problem is `ai.com` is a domain someone else actually owns. If anything ever emails that placeholder before the professional replaces it, a stranger receives it. Using an address the internet has officially reserved for "this will never go anywhere" removes that risk entirely.
    - **Evidence:**
        ```php
        'default_contact' => [
            'full_name' => 'Charlie'
            'email' => 'charlie@ai.com'
            'phone' => '1234 567 890'
            'source' => 'system_default'
            'subscribed' => true
        ]
        ```

- [ ] **PRIV-12** · P3 — Two-year waitlist retention for non-converting applicants may exceed what's proportionate
    - **Where:** `config/partna.php:816` (`waitlist.retention_days`)
    - **Affects:** Every waitlist signup who never converts to a full account — name, email, and industry retained 730 days past their last activity.
    - **Effort:** S (~0.5h, config-only)
    - **What to do:**
        - Reduce `retention_days` to a period proportionate to the waitlist's actual purpose (e.g. 365 days), or document the business justification for keeping 730.
    - **Technical:** The enforcement mechanism here is already correct — `waitlist:prune-old-signups` runs weekly and reads this config value, unlike the handle-audit and feedback gaps above. This is purely a proportionality judgment under APP 11.2: once the platform launches or an applicant is passed over, the original evaluation purpose is largely fulfilled, and two additional years starts to look like "just in case" retention rather than purpose-bound retention.
    - **Plain English:** If someone signs up for the waitlist but never becomes a user, we keep their name, email, and profession for two full years after they lose interest. The cleanup job that eventually deletes it works correctly — the question is just whether two years is longer than we actually need, versus a shorter, easier-to-justify window.
    - **Evidence:**
        ```php
        'waitlist' => [
            'enabled' => (bool) env('PARTNA_WAITLIST_ENABLED', env('SIDEST_WAITLIST_ENABLED', false))
            // PRIV-8: hard-delete non-converting applicant rows older than this window.
            'retention_days' => (int) env('PARTNA_WAITLIST_RETENTION_DAYS', 730)
            ...
        ]
        ```

- [ ] **PRIV-13** · P3 — Evidence snapshot's captured handle/display_name absent from the GDPR export (deletion side already covered)
    - **Where:** `app/Services/Moderation/EvidenceSnapshotService.php:59-67` and `app/Services/User/DataExport/DataExportPayloadBuilder.php` (no `moderation.evidence` export section)
    - **Affects:** Any user whose site was the subject of a moderation report — their handle and display name are frozen into an immutable evidence row that never surfaces in their own DSAR.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `moderation.evidence` (or content-report-adjacent) export entry that surfaces the redacted-safe fields of a user's own evidence snapshots, or explicitly document the exclusion rationale in `sectionDescriptors()`.
    - **Technical:** `EvidenceSnapshotService::snapshotSite()` captures `handle` and `display_name` into `moderation.evidence.payload` at report time. The deletion side of this is already handled correctly — `AccountDeletionService::purgeReportedUserEvidencePii()` tombstones `handle`/`display_name`/`site_subdomain` to `'[redacted]'` on account purge, so this is narrower than the original draft suggested: only the export-completeness half of the ledger is actually missing, not the erasure half.
    - **Plain English:** When someone's page gets reported, we take a permanent snapshot that includes their handle and display name at that moment. If they ever delete their account, that snapshot already gets properly scrubbed — that part works. But if they ask "what do you have on me" *before* deleting, that snapshot isn't part of what we send them, and it probably should be.
    - **Evidence:**
        ```php
        private function snapshotSite(string $siteId): array
        {
            $site = Site::query()->with(['user', 'blocks'])->findOrFail($siteId);
            return [
                'site_id' => $site->id
                'site_subdomain' => $site->subdomain ?? null
                'user_id' => $site->user_id
                'handle' => $site->user?->handle ?? null
                'display_name' => $site->user?->display_name ?? null
                ...
            ];
        }
        ```

## Suggested Bundled Sessions

    - **Why grouped:** All are additive entries/fields to the same file (`DataExportPayloadBuilder::sectionDescriptors()` / `site()` / `streamFeedback()`) — one coherent pass over the export manifest.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** Identical root-cause pattern (a declared `config/partna.php` retention value with no matching `routes/console.php` command) — same fix shape, same files.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** All are small, low-risk config/ingest-layer cleanups (precision truncation, stale docs, placeholder values, retention-window tuning) with no cross-file coordination needed.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-11-edge-worker.md ═══════════ -->

# Edge Worker Audit — 2026-07-12

**Branch:** HEAD
**Lens:** Cloudflare Worker routing, KV contract, edge-cache correctness, takedown latency, poisoning
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `cloudflare-worker/src/index.js`
- `cloudflare-worker/wrangler.toml`
- `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php`
- `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`
- `app/Services/Cloudflare/CloudflarePurgeService.php`
- `app/Services/Moderation/ModerationActionDispatcher.php`
- `app/Jobs/Moderation/PurgeModerationCacheJob.php`
- `config/partna.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

## P3 — Nice to have

- [ ] **EDGE-5** · P3 — `unclaimedHtml`'s CTA link hardcodes `https://partna.au` regardless of environment (Category 7)
    - **Where:** `cloudflare-worker/src/index.js` — `unclaimedHtml()`
    - **Affects:** Anyone viewing the branded 404 on a non-production deploy of this Worker (e.g., the `[env.staging]` target once it's wired up) — the CTA always points at prod.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Derive the link from `PARTNA_DOMAIN` (or the request's own hostname suffix) instead of the literal string, so a staging deploy links back to staging.
    - **Technical:** `unclaimedHtml(subdomain)` builds `<a href="https://partna.au">${cta}</a>` unconditionally. `PARTNA_DOMAIN` is already a module constant the function could reference; it just doesn't. Low impact since the function is only reachable when `RESERVED`/KV logic has already scoped the request to a `partna.au`-family host.
    - **Plain English:** The "go to the main site" button on the not-found page always sends people to the real production site, even when someone is testing a non-production copy of the router. A small polish item, not a safety issue.
    - **Evidence:**
        ```javascript
        const cta = safe ? "Claim this address" : "Go to partna.au";
        return `<!doctype html>
        ...
            <a href="https://partna.au">${cta}</a>
        ...`;
        ```

- [ ] **EDGE-6** · P3 — Staging KV namespace in `wrangler.toml` is still a placeholder TODO (Category 7)
    - **Where:** `cloudflare-worker/wrangler.toml:42-53`
    - **Affects:** Anyone who runs `wrangler deploy --env staging` before the referenced namespace exists — the deploy fails outright rather than silently writing into production (fail-safe), but the staging override is currently non-functional.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Either create the staging KV namespace (`wrangler kv namespace create SUBDOMAIN_KV_STAGING` + `--preview`) and paste the real IDs in, or remove the `[env.staging]` block entirely if it's vestigial now that the actual deployment model is "development env serves both API domains" with no separate Cloudflare staging tier (per current Laravel Cloud reality).
    - **Technical:** The block is self-documented with a `TODO(josh)` and exact commands, added specifically to prevent a staging deploy from clobbering the production `SUBDOMAIN_KV` namespace (EDGE-10, prior audit). It's a safe placeholder (deploy-time failure, not runtime corruption) but is dead config as written — worth resolving one way or the other so it doesn't linger indefinitely.
    - **Plain English:** There's a setup note left in the routing configuration reminding someone to create a separate practice-run address book before a test deployment can work, so a test run can never accidentally scribble on the real one. It's safe as-is (the test deploy would just fail loudly), but it's an unfinished chore worth closing out or removing.
    - **Evidence:**
        ```toml
        # EDGE-10: staging MUST NOT share the production SUBDOMAIN_KV — without this
        # override a `--env staging` deploy (or a staging backend KV backfill) would
        # write into the production routing table and poison prod. Give staging its own
        # namespace. TODO(josh): create it and paste the id below —
        [[env.staging.kv_namespaces]]
        binding = "SUBDOMAIN_KV"
        id = "REPLACE_WITH_STAGING_KV_NAMESPACE_ID"
        preview_id = "REPLACE_WITH_STAGING_KV_PREVIEW_ID"
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Worker/config sync hygiene:** , #EDGE-6
    - **Why grouped:** All three are documentation/enforcement gaps between the Worker and its backend/config mirrors (RESERVED list, domain/TTL constants, staging namespace) — same files (`index.js` + `wrangler.toml` + `config/partna.php`), no behavioral risk, low effort each.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — KV-outage UX polish:** , #EDGE-5
    - **Why grouped:** Both are minor Worker response-shape tweaks (fail-open branded 404 + CTA link) with no cross-cutting risk; both touch only `index.js`.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-11-configuration-hygiene.md ═══════════ -->

# Configuration Hygiene Audit — 2026-07-12

**Branch:** HEAD
**Lens:** Configuration Hygiene — env() outside config, missing .env.example keys, feature flags without defaults
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- config/partna.php
- config/services.php
- config/supabase.php
- config/cache.php
- .env.example
- bootstrap/app.php
- routes/api.php, routes/api/{platforms,staff,user}.php, routes/console.php
- app/Console/Commands/*.php
- app/Http/Middleware/**/*.php
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Design/AnalyzeConnectionWebsitesJob.php
- app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Platforms/{GoogleBusinessEnrichJob,InstagramConnectJob,MenuFetchJob}.php
- app/Jobs/ProcessLogoVariantsJob.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Services/Diagnostics/EnvCheckService.php
- app/Services/Media/{ImagePaletteExtractor,ImageVariantService}.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 5 complete

---

## P2 — Should fix

## P3 — Nice to have

- [ ] **#CFG-2** · P3 — Analytics endpoint default resolves `config('app.url')` at config-load time
    - **Where:** config/partna.php:946-949 (`public_profile.analytics_endpoint`)
    - **Affects:** Staging/QA environments where `APP_URL` is left unset — the client analytics-beacon endpoint bakes in `http://localhost/api/analytics` at `php artisan config:cache` time.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either accept the current behaviour (document that `PARTNA_PUBLIC_ANALYTICS_ENDPOINT` should be explicit in any env where `APP_URL` isn't also correct), or switch the default to a request-time closure using `url('/')` instead of a config-load-time `config('app.url')` string.
    - **Technical:** Laravel's `LoadConfiguration` bootstrap loads `config/*.php` files in filename order and `set()`s each into the repository sequentially, so `app.php`'s `url` key is already populated by the time `partna.php` evaluates this line — the value is correct in every environment that actually sets `APP_URL`. The only real footgun is an environment that forgets `APP_URL` entirely, in which case this silently resolves to `http://localhost/api/analytics` and gets baked into `config:cache`. Since `.env.example` ships `APP_URL=http://localhost:8000` and every deployed env (Laravel Cloud) sets `APP_URL` explicitly, the practical exposure is narrow — but it's a one-line change to make the fallback path safer.
    - **Plain English:** This is a "what if we forget to fill in this field" scenario. The setting that tells the analytics beacon where to send data normally copies the site's own address automatically. If nobody ever tells it the site's real address (a setup mistake), it quietly defaults to a placeholder that only works on a developer's own laptop. It's a minor safety-net gap, not a live problem today.
    - **Evidence:**
        ```php
        'analytics_endpoint' => env(
            'PARTNA_PUBLIC_ANALYTICS_ENDPOINT'
            rtrim(config('app.url'), '/').'/api/analytics'
        )
        ```

- [ ] **#CFG-3** · P3 — `brand_scan.enabled` defaults `true`, inconsistent with the rest of the `*_ENABLED` fleet
    - **Where:** config/partna.php:1135 (`brand_scan.enabled`); .env.example:249
    - **Affects:** New environment deploys — the brand-scan flag reads "on" without an explicit opt-in, unlike every other `*_ENABLED` flag in this file.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Leave as-is if consistency isn't worth the churn — but if changed, flip to `env('PARTNA_BRAND_SCAN_ENABLED', false)` and update the matching `.env.example` line to `false`, since the client-side fail-closed behaviour doesn't depend on the flag's default.
    - **Technical:** Unlike most `*_ENABLED` flags in this file, `brand_scan.enabled` defaults `true` and is documented as intentionally safe: the accompanying comment ("Enabled defaults true but the client fails closed... until URL + token are set, so this is safe to ship unconfigured") and the matching `.env.example` entry (`PARTNA_BRAND_SCAN_ENABLED=true` with an explanatory comment) show this was a considered decision, not an oversight — `WebsiteStyleAnalyzer`'s client returns `ok:false` and design presets abstain whenever `PARTNA_BRAND_SCAN_URL`/`TOKEN` are empty, so a fresh, unconfigured deploy behaves identically whether this flag is `true` or `false`. The residual risk is purely stylistic: it breaks the fleet-wide "every `*_ENABLED` flag defaults off" convention, so a future refactor that removes the client-side fail-closed check would silently activate this feature everywhere. Downgraded from the draft's P2 given the documented, working safety net.
    - **Plain English:** Most feature switches in this codebase ship "off" until someone deliberately turns them on. This one ships "on," but there's a second safety check further down the line that keeps it harmless until the required web address and access key are filled in — so in practice nothing bad happens today. It's still worth eventually making this switch consistent with all the others, so a future change to that safety check doesn't quietly flip this feature on everywhere.
    - **Evidence:**
        ```php
        'brand_scan' => [
            'enabled' => (bool) env('PARTNA_BRAND_SCAN_ENABLED', true)
        ```

- [ ] **#CFG-4** · P3 — `refresh.conditional.enabled` defaults `true`, same fleet inconsistency as CFG-3
    - **Where:** config/partna.php:1344 (`refresh.conditional.enabled`); .env.example:314
    - **Affects:** New environments — conditional HTTP (ETag/If-None-Match) requests to upstream platforms are active without explicit opt-in.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same call as CFG-3: leave as documented-intentional, or flip to `false` + update `.env.example` for fleet consistency.
    - **Technical:** `ConditionalContext::for()` reads this flag as a master kill-switch; when `false` it returns `null` and every wired strategy fetches unconditionally exactly as before — the flag exists specifically as an emergency "force full fetches" lever, not a feature gate. `ConditionalContext::handle()` only short-circuits on an actual HTTP 304; any other status (including a 200 from an upstream that "mis-answers") is processed as a normal full fetch with fresh validators captured for next time — there is no path where a misbehaving upstream causes silently-wrong data, contradicting the draft's stated failure mode. `.env.example` documents the default as deliberate ("Global kill-switch: set false to force full fetches everywhere if an upstream starts mis-answering conditional requests"). Same fleet-consistency argument as CFG-3 applies; downgraded from the draft's P3-adjacent P2/P3 split to a flat P3 to match CFG-3 (same root cause).
    - **Plain English:** This is the same pattern as the brand-scan switch above — a feature flag that ships "on" instead of "off," but with a built-in safety net (if a supplier's API doesn't play along, the system just does the normal slower thing instead of getting confused). Low priority polish, not a live risk.
    - **Evidence:**
        ```php
        'conditional' => [
            'enabled' => (bool) env('PARTNA_REFRESH_CONDITIONAL_ENABLED', true)
        ]
        ```

- [ ] **#CFG-5** · P3 — Hardcoded queue name bypasses `config('partna.queues.*')` routing convention
    - **Where:** app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php:36
    - **Affects:** Notification delivery — a future queue rename or per-environment override would silently miss this job.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$this->queue = 'notifications';` with `$this->onQueue(config('partna.queues.notifications', 'notifications'));` in the constructor — `onQueue()` sets the same untyped `Queueable::$queue` property, so it doesn't reintroduce the PHP 8.4 trait-conflict this job's comment is guarding against.
        - Update/remove the stale comment accordingly.
    - **Technical:** Most jobs audited in this scope (`ProcessLogoVariantsJob`, `RecordAnalyticsEventJob`, `CloudflareCachePurgeJob`, `GoogleBusinessEnrichJob`, `MenuFetchJob`, `InstagramConnectJob`, `SendTransactionalNotificationEmailJob`) route their queue through `config('partna.queues.*')` with a literal fallback. This job instead assigns `$this->queue` directly in the constructor. Note: this is not unique to this file codebase-wide (several out-of-scope Moderation jobs follow the same direct-assignment pattern) but within this audit's scope it's the one inconsistent case, and the fix is a one-line, safe change — `onQueue()` is a plain method call on the same untyped property the constructor comment is protecting, so switching doesn't reintroduce the trait conflict. Retiered from the draft's P2 to P3 per this lens's own guidance ("P3 for hardcoded values that should be config-driven" — this is squarely category 4, not a P2 missing-`.env.example`/flag-default issue).
    - **Plain English:** Almost every background job in this area of the code looks up which delivery lane to use from a shared settings sheet, so the lane name can be changed in one place. This one job has its lane name written directly into the code instead. It's low risk today since nobody's renaming that lane, but it's an easy fix to bring in line with its neighbors.
    - **Evidence:**
        ```php
        public function __construct(public readonly string $enquiryId)
        {
            // Queueable::$queue is untyped; assign in constructor to avoid PHP 8.4 trait conflict.
            $this->queue = 'notifications';
        }
        ```

## Suggested Bundled Sessions

- **Bundle A — Feature-flag default consistency:** #CFG-3, #CFG-4
    - **Why grouped:** Same root cause — a `*_ENABLED` flag in `config/partna.php` deliberately defaulting `true` with a documented, verified safety net elsewhere. Same file, same decision to make (fix vs. leave documented-as-is).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (trivial change if approved).

- **Bundle B — Config-drive hardcoded operational constants:** #CFG-2, #CFG-5
    - **Why grouped:** Same pattern (a hardcoded value that should route through `config()`), different files, no cross-dependency between them — safe to implement and review together.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-11-migration-safety.md ═══════════ -->

# Migration Safety Audit — 2026-07-12

**Branch:** HEAD
**Lens:** Migration safety: lock-on-deploy risk, backfill ordering, online DDL hygiene, reversibility
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `supabase/migrations/20260704160000_shop_brands_products.sql`
- `supabase/migrations/20260704170000_drop_menu_platform_checks.sql`
- `supabase/migrations/20260704180000_drop_users_about.sql`
- `supabase/migrations/20260704150000_prepilot_p0_schema_expand.sql`
- `supabase/migrations/20260705000000_migrate_retired_font_slugs.sql`
- `supabase/migrations/20260705120000_drop_dead_profile_features.sql`
- `supabase/migrations/20260706000000_add_city_to_site_visits.sql`
- `supabase/migrations/20260707020000_site_visits_lat_lon.sql`
- `supabase/migrations/20260707030000_rename_skeleton_ids.sql`
- `supabase/migrations/20260707120000_rename_skeleton_ids_bento_class.sql`
- `supabase/migrations/20260708000000_add_site_media_palette.sql`
- `supabase/migrations/20260708120000_sites_shop_global_settings.sql`
- `supabase/migrations/20260708124853_staff_audit_log_ip_hash_and_get_reads.sql`
- `supabase/migrations/20260709064322_migrate_retired_font_slugs_one.sql`
- `supabase/migrations/20260710120000_add_section_views_duration_ms.sql`
- `supabase/migrations/20260710160000_design_kit_theme_surface_rework.sql`
- `supabase/migrations/20260710170000_skeleton_id_one_only.sql`
- `supabase/migrations/20260710190000_semantic_text_scale_and_vocab_remap.sql`
- `supabase/migrations/20260710210000_surfaces_backend.sql`
- `supabase/migrations/20260710230000_rename_skeleton_id_to_architecture_id.sql`
- `supabase/migrations/20260711000000_staff_account_type.sql`
- `supabase/migrations/20260711000400_notifications_critical_flag.sql`
- `supabase/migrations/20260711153000_feedback_type_area_target.sql`
- `supabase/migrations/20260711160100_add_analytics_purge_indexes.sql`
- `supabase/migrations/20260711160200_site_sessions_add_composite_unique.sql`
- `supabase/migrations/20260711160300_site_sessions_promote_composite_pk.sql`
- `docs/migration-guidelines.md`
- `scripts/guard-no-unsafe-migrations.php`

## Progress

- P0 Blockers: 0 of 1 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P0 — Must fix before any real user touches the system

## P1 — Fix before pilot launch

## P2 — Should fix

## P3 — Nice to have

- [ ] **#MIG-5** · P3 — Missing rollback-path comment on destructive `site.design_kits` column drops
    - **Where:** `supabase/migrations/20260710160000_design_kit_theme_surface_rework.sql:22-24`
    - **Affects:** Documentation only — the drops themselves are fast, metadata-only operations on data the file itself calls test-only.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a short rollback comment noting the drop is unrecoverable-in-data (structure-only restore), matching the convention used in `20260705120000_drop_dead_profile_features.sql` and `20260704180000_drop_users_about.sql`.
    - **Technical:** Category 5. `color_bg`, `effect_style`, and `motion_entrance` are dropped from `site.design_kits` with no rollback comment. The original scan claimed sibling migration `20260710190000_semantic_text_scale_and_vocab_remap.sql` "includes a full rollback script" by contrast — that's inaccurate; that file (see ) also has no rollback block, so the rollback-comment convention is inconsistently applied across this batch of test-data-only design-kit migrations generally, not uniquely absent here. Given the file's own header states "Test users only — destructive drops are sanctioned" and the drop is metadata-only (no lock/rewrite risk), this is pure documentation hygiene rather than an operational risk — the "no rehearsal path" framing doesn't apply cleanly here since, structurally, every current post-baseline migration is dev-only until the gated prod re-baseline (per CLAUDE.md's "prod-is-behind" caveat), so rehearsal-on-dev is already the de facto process.
    - **Plain English:** This migration throws away three old drawers from a filing cabinet that the team says nobody uses anymore — probably true, and low-risk either way. It's just missing a short note saying "if we're wrong, here's what you'd have to manually restore," the same note other similar cleanups in this codebase already include.
    - **Evidence:**
        ```sql
        alter table site.design_kits drop column if exists color_bg;
        alter table site.design_kits drop column if exists effect_style;
        alter table site.design_kits drop column if exists motion_entrance;
        ```

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

- **#MIG-5 — Missing rollback-path comment:** edits a `supabase/migrations/` file, even though low-risk.


<!-- ═══════════ audit-2026-07-11-api-contract.md ═══════════ -->

# API Contract & Resource Leakage Audit — 2026-07-12

**Branch:** HEAD
**Lens:** API Contract & Resource Leakage: raw model fields bleeding through, over-fetching, inconsistent pagination
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Http/Controllers/Api/User/Content/ContentController.php`
- `app/Http/Controllers/Api/User/Uploads/UserDesignMediaController.php`
- `app/Http/Controllers/Api/User/Account/UserSelfController.php`
- `app/Http/Controllers/Api/User/Analytics/DevInsightsController.php`
- `app/Http/Controllers/Api/User/Analytics/UserAnalyticsController.php`
- `app/Http/Controllers/Api/PublicSite/PublicMenuController.php`
- `app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php`
- `app/Http/Controllers/Api/Staff/Segments/StaffSegmentController.php`
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php`
- `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffIntegrationManagementController.php`
- `app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php`
- `app/Http/Controllers/Api/Platforms/ShopController.php`
- `app/Http/Resources/SiteResource.php`
- `app/Http/Resources/UserDashboardResource.php`
- `app/Http/Resources/UserPublicResource.php`
- `app/Http/Resources/UserStaffResource.php`
- `app/Http/Resources/Staff/StaffUserListResource.php`
- `app/Http/Resources/Content/ContentLibraryUploadResource.php`
- `app/Http/Resources/DesignMediaResource.php`
- `app/Http/Resources/NotificationListingResource.php`
- `app/Http/Resources/PublicSite/IndividualProfileResource.php`
- `app/Http/Controllers/Concerns/ReturnsPaginatedResponse.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 9 complete

---

## P3 — Nice to have

- [ ] **#API-2** · P3 — `SiteResource` exposes `user_id` unconditionally — internal FK surfaced with no dashboard use
    - **Where:** `app/Http/Resources/SiteResource.php:54`
    - **Affects:** Every dashboard endpoint returning `SiteResource` — `UserSelfController::show()`, `UserSiteController::show()`/`update()`/`updateBookingSettings()`, `SiteVisibilityController::update()` (all authenticated-owner routes; verified `SiteResource` has no public-surface call site).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `user_id` from the dashboard payload, or gate it behind `$this->when($request->routeIs('staff.*'), ...)` if staff callers want it explicit (staff already get it separately via `UserStaffResource`/`StaffUserController::show()`).
    - **Technical:** `SiteResource` includes `'user_id' => $this->user_id` unconditionally. All six call sites are authenticated-owner or staff routes (verified via grep — no public-facing usage), so this isn't an audience-confusion leak; it's redundant internal-FK exposure the owner already has via their own user profile response. Same root-cause pattern as (unnecessary internal ID field on an own-resource Resource) — tiered identically.
    - **Plain English:** The site's API response includes a copy of the owner's internal account number, which the owner already sees on their profile page. Printing it twice isn't a secret leak, but it's unneeded duplication that invites confusion later.
    - **Evidence:**
        ```php
        return array_merge([
            'id' => (string) $this->id
            'user_id' => $this->user_id
            'subdomain' => $this->subdomain
        ```

- [ ] **#API-3** · P3 — `ContentLibraryUploadResource` missing `updated_at` — clients can't detect a re-uploaded image
    - **Where:** `app/Http/Resources/Content/ContentLibraryUploadResource.php:27-35`
    - **Affects:** Dashboard content library — re-uploading an image replaces the row in-place (same `id`), and the client has no signal it changed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'updated_at' => $media->updated_at?->toIso8601String()` to the returned array.
    - **Technical:** The Resource emits `created_at` but not `updated_at`. `SiteMedia` rows in the content pool are updated in-place on re-upload (same `id`, same `purpose`), so `updated_at` is the only wire signal a previously-fetched image has changed. Without it, the SPA either skips caching entirely or risks showing a stale variant.
    - **Plain English:** Imagine a photo frame that shows a picture. You swap the photo, but the label still shows the original date, so nothing looks changed. Adding an "updated" date to the label tells the viewer the photo was recently swapped.
    - **Evidence:**
        ```php
        return [
            'id' => (string) $media->id
            'url' => is_string($url) && $url !== '' ? $url : null
            'alt_text' => $media->alt_text
            'caption' => $media->caption
            'media_type' => $media->media_type
            'processing_state' => $media->processing_state
            'created_at' => $media->created_at?->toIso8601String()
        ];
        ```

- [ ] **#API-4** · P3 — `UserAnalyticsController` returns plain arrays with no acknowledgment of the exception, unlike its sibling `DevInsightsController`
    - **Where:**
        - `app/Http/Controllers/Api/User/Analytics/DevInsightsController.php:24-25` (documented exception)
        - `app/Http/Controllers/Api/User/Analytics/UserAnalyticsController.php:116` (undocumented)
    - **Affects:** The authenticated professional's live analytics dashboard (`summary()`, `insights()`, `live()`).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add the same `@see`-style acknowledgment `DevInsightsController` already carries to `UserAnalyticsController`, so future readers understand the plain-array shape is deliberate for aggregate analytics reads.
        - Do not force these into Resource classes: the payloads are hand-aggregated stdClass/array data from `DB::table()` reads over `analytics.*`, not model representations — a Resource here would just be a re-wrapped array with no allowlist benefit.
    - **Technical:** Both controllers read directly from `analytics.*` tables via the query builder (never Eloquent models), so this isn't a raw-model-return violation. `DevInsightsController` already has an explicit docblock ("Plain-array response (no Resource) ... the same ad-hoc norm the sibling UserAnalyticsController uses") acknowledging this is a deliberate, shared pattern — but that acknowledgment lives on the wrong controller. `UserAnalyticsController::summary()` powers the live dashboard and has no comparable note, so a future contributor reading only that file could mistake the plain-array shape for an oversight rather than a convention.
    - **Plain English:** Most endpoints serve data on a standard plate; the two analytics endpoints serve it on a napkin — same food, different container, and that's fine since it's deliberate. Right now only one of the two napkin-stations has a note explaining why. Add the same note to the other one so nobody "fixes" it by accident.
    - **Evidence:**
        ```php
        // DevInsightsController — explicit docblock acknowledgment
        /**
         * Plain-array response (no Resource), no cache — the same ad-hoc norm the sibling
         * UserAnalyticsController uses for its analytics reads.
         */
        ```
        ```php
        // UserAnalyticsController::summary() — no such acknowledgment
        $data['insights'] = $this->analytics->insights($professional, $site);

        return $this->success($data);
        ```

- [ ] **#API-5** · P3 — `StaffSegmentController::users()` manually maps `User` rows instead of a Resource class
    - **Where:** `app/Http/Controllers/Api/Staff/Segments/StaffSegmentController.php:175-182`
    - **Affects:** Staff dashboard segment-membership preview; future staff-facing fields added to `User`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract a small `StaffSegmentMemberResource` (or reuse `StaffUserListResource` if its shape is close enough) so the field allowlist for this view lives in one auditable place alongside the other staff User resources.
    - **Technical:** The endpoint already paginates correctly (`->paginate($perPage)` + `paginatedResponse()`), so this is purely category (2)/(1)-adjacent: the row-mapping bypasses the Resource-class allowlist pattern used by the sibling staff User views (`StaffUserListResource`, `UserStaffResource`). Fields shipped today are limited and non-sensitive, but there's no single `toArray()` to audit against those other two Resources going forward.
    - **Plain English:** Three staff screens show user info — the main list, the detail page, and this segment-members list. The first two use pre-approved field lists; this one builds its list by hand inline, so a sensitive field added to the User model later won't automatically show up here, but it also won't be blocked from being copy-pasted in.
    - **Evidence:**
        ```php
        $users = collect($page->items())->map(fn (User $user) => [
            'id' => $user->id
            'handle' => $user->handle
            'display_name' => $user->display_name
            'account_type' => $user->account_type?->value
            'sector' => $user->sector
            'created_at' => $user->created_at?->toIso8601String()
        ]);
        ```

- [ ] **#API-6** · P3 — `ShopController::selection()` builds a public-compat payload inline, duplicating `ShopBrandResource`'s field contract
    - **Where:** `app/Http/Controllers/Api/Platforms/ShopController.php:415-427`
    - **Affects:** Authenticated `GET /api/platforms/shop/selection` (dashboard/compat Shop-card read); future changes to the brand payload shape.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Route `$primary` through a small dedicated compat Resource (or a static `ShopBrandResource::toCompat()` helper) instead of hand-destructuring — centralizes the field list against the per-brand `ShopBrandResource::toArray()` used elsewhere in this same controller.
    - **Technical:** `selection()` is an authenticated compatibility endpoint (route confirmed under `routes/api/platforms.php`, gated by the standard user auth stack — not a public route despite the "partna-pages" comment referring to the eventual consumer) that flattens the primary brand into `{url, provider, discountCode, products}` via manual array construction, while `removeBrand()`/`setProducts()` in the same file correctly return `ShopBrandResource::collection(...)->resolve()`. Two field lists for the same underlying brand data can drift.
    - **Plain English:** The shop-card summary is hand-picked from the stored brand data in one spot, while the rest of the dashboard reads the same brand data through a proper template. If the template gains a field, this hand-written summary won't automatically get it.
    - **Evidence:**
        ```php
        $selection = $primary ? [
            'url' => $primary['url']
            'provider' => $primary['provider'] ?? 'shopify'
            'discountCode' => $primary['discountCode'] ?? ''
            'products' => $primary['products']
        ] : null;

        return $this->success(['selection' => $selection]);
        ```

- [ ] **#API-8** · P3 — Content/design upload endpoints manually pre-materialize Resources with `->toArray()`, bypassing the app's own inline-Resource pattern
    - **Where:**
        - `app/Http/Controllers/Api/User/Content/ContentController.php:64` (`library()`), `:102` (`storeUpload()`)
        - `app/Http/Controllers/Api/User/Uploads/UserDesignMediaController.php:55` (`index()`), `:86` (`upload()`)
    - **Affects:** Dashboard content-library and design-media upload responses.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Stop calling `->toArray($request)` manually; embed the Resource object directly in the returned array (e.g. `$images[$purpose] = $media instanceof SiteMedia ? new DesignMediaResource($media) : null;`), matching the pattern `UserSelfController::show()` already uses for `'professional' => new UserDashboardResource($pro)`. Laravel's JSON encoder resolves each nested `JsonResource` via `jsonSerialize()`/`resolve()` automatically, which correctly handles any future `when()`/`mergeWhen()` conditional additions — manual `->toArray()` does not.
    - **Technical:** `$this->success()` in this codebase is a thin wrapper over `response()->json($data)` — it does **not** apply Laravel's automatic `data`-key wrapping (that only happens when a `JsonResource` is returned directly as the route's response and its `toResponse()` runs). So the "lost envelope" framing doesn't apply here; both `->toArray($request)` and embedding the Resource object produce the same wire shape today, because neither `ContentLibraryUploadResource` nor `DesignMediaResource` currently uses `$this->when()`/`mergeWhen()`/`merge()`. The real (currently-latent) risk is narrower: `->toArray()` skips the `resolve()`/`jsonSerialize()` step that flattens Laravel's internal `MergeValue`/`MissingValue` conditional-attribute wrappers — if either Resource later adds a `when()`-gated field, the four call sites here would silently serialize a raw internal PHP object into the JSON response instead of a scalar. Fix is mechanical and removes the landmine before it's tripped.
    - **Plain English:** Four spots in the code build a piece of the response by hand instead of letting the standard template do it. It works fine today because the templates are simple, but if someone later adds a conditional field to those templates (a common pattern elsewhere in this codebase), these four spots would silently ship broken data instead of the conditional value. Switching them to the standard hand-off closes that trap before it's ever sprung.
    - **Evidence:**
        ```php
        // ContentController::library()
        ->map(fn (SiteMedia $m) => (new ContentLibraryUploadResource($m))->toArray($request))
        ```
        ```php
        // ContentController::storeUpload()
        return $this->success((new ContentLibraryUploadResource($media))->toArray($request), 201);
        ```
        ```php
        // UserDesignMediaController::index()
        $images[$purpose] = $media instanceof SiteMedia ? (new DesignMediaResource($media))->toArray(request()) : null;
        ```
        ```php
        // UserDesignMediaController::upload()
        return $this->success((new DesignMediaResource($media))->toArray(request()), 201);
        ```

- [ ] **#API-10** · P3 — `StaffNotificationController::index()` uses `->limit()` instead of `->paginate()`, omitting pagination metadata used by every sibling staff list endpoint
    - **Where:** `app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:159-174`
    - **Affects:** Staff dashboard notification history view; clients cannot discover whether more than `limit` rows exist.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->limit($limit)->get()` with `->paginate($limit)` and route the result through the existing `paginatedResponse()` helper (already used by `StaffUserController::index()` and `StaffSegmentController::users()` in this same audit) so `meta.current_page`/`last_page`/`next_page_url` ship consistently.
    - **Technical:** The endpoint caps results at a configurable `limit` (1–200, default 50) via `Notification::query()->orderByDesc('created_at')->limit($limit)->get()` and returns a flat `{ notifications: [...] }` body with no pagination metadata. The codebase already has a standard `ReturnsPaginatedResponse::paginatedResponse()` trait (verified) that other staff list endpoints use — this is the one outlier. A client with 200+ notifications has no way to know a page 2 exists or fetch it.
    - **Plain English:** Every other staff list screen tells the dashboard "here's page 1 of 12, click for more." The notification history screen just says "here are up to 200 notifications" with no indication of whether there's more. If there are 250, the last 50 are invisible with no way to reach them.
    - **Evidence:**
        ```php
        $limit = max(1, min((int) $request->query('limit', 50), 200));

        $query = Notification::query()->orderByDesc('created_at')->limit($limit);
        // ...
        return $this->success([
            'notifications' => $query->get()
                ->map(fn (Notification $n) => (new NotificationListingResource($n))->resolve())
                ->values()
        ]);
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Own-resource internal-FK hygiene:** #API-2
    - **Why grouped:** identical root cause (an internal identity/FK field exposed unconditionally on a self-scoped Resource with no consumer use case) — one small PR touching two Resource files.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

- **Bundle 2 — Analytics response-shape documentation:** #API-4
    - **Why grouped:** single-file docblock addition, no code-behavior change.
    - **Model:** Plan+Implement combinable (S effort) · Review: Sonnet.

- **Bundle 3 — Manual-array-to-Resource cleanup (authenticated/staff surfaces):** #API-5, #API-6, #API-8
    - **Why grouped:** same root-cause pattern (controller hand-builds response arrays instead of routing through a Resource class) across Staff and User surfaces; none touch auth/money/schema, all mechanical extractions with existing sibling Resources to model from.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet). is the largest (M effort, two extractions) — implement it last in the bundle so the reviewer can check it in isolation.

- **Bundle 4 — Content/design upload Resource embedding:** #API-3
    - **Why grouped:** both touch `ContentLibraryUploadResource`'s contract area (one is a field addition, the other is a related consumption-pattern fix on the sibling public menu endpoint) — bundled for reviewer context locality, not a shared root cause with Bundle 3.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

- **#API-10 — Add pagination metadata to `StaffNotificationController::index()`** · standalone because it changes response shape (adds a `meta` envelope) on a live staff endpoint — verify no staff-dashboard client depends on the current flat `{ notifications: [...] }` shape before shipping, independent of the other bundles.


<!-- ═══════════ audit-2026-07-11-test-coverage.md ═══════════ -->

# Test Coverage Audit — 2026-07-13

**Branch:** HEAD
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `tests/Pest.php`, `tests/Feature/**`, `tests/Unit/**`, `tests/Integration/**`, `tests/Helpers/**`
- `app/Policies/**`
- Cross-referenced production files: `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php`, `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php`, `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`, `app/Jobs/Analytics/RecordAnalyticsEventJob.php`, `app/Jobs/ProcessImageVariantsJob.php`, `app/Jobs/ProcessVideoVariantsJob.php`, `app/Jobs/DeleteMediaArtifactsJob.php`, `app/Jobs/Platforms/GoogleBusinessEnrichJob.php`, `app/Services/Cache/CacheLockService.php`

## Note on this adjudication

The DeepSeek draft (8 chunks, ~80 raw findings) systematically **hallucinated an untested codebase** — nearly every "zero coverage" claim (public sitepage resolution, handle-alias 301s, `SyncSubdomainToKvJob`, `RecordAnalyticsEventJob` dedup, moderation state transitions, media processing jobs, `CacheLockService` concurrency, webhook signature/re-delivery, `AccountCapabilities` gating, staff authorization, migration CHECK-constraint invariants, factory determinism) was directly contradicted by extensive existing test files found via `Read`/`Grep`/`Glob`. Those findings are dropped as hallucinated per the adjudication mandate. Only 7 findings survived verification against actual repo state.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 4 complete

---

## P2 — Should fix

## P3 — Nice to have

- [ ] **TEST-4** · P3 — `SupabaseAuthHookController`'s malformed-payload branch (invalid UUID format) is untested
    - **Where:** `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php:75-78`; closest test file `tests/Feature/Webhooks/SupabaseAuthHookBruteForceTest.php`
    - **Affects:** Supabase MFA-verification webhook — a regression in the UUID-format guard would go undetected until a malformed delivery actually arrives in production.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('returns 400 for a non-UUID user_id or factor_id', ...)` posting a signed payload with `user_id: 'not-a-uuid'` and asserting `assertStatus(400)->assertJson(['message' => 'Malformed payload'])`.
    - **Technical:** The controller already defends this path (`if (! preg_match($uuidPattern, $userId) ...) return response()->json(['message' => 'Malformed payload'], 400);`), but no test in `SupabaseAuthHookBruteForceTest.php` exercises it — that file only covers unsigned requests, success/failure recording, redelivery dedup, lockout, and the absent-webhook-id guard (all of which ARE well covered). The sibling `SupabaseEmailHookController` (same `VerifySupabaseHookSignature` middleware) has an equivalent `it('returns 422 when the payload is missing required fields', ...)` test — this is the one gap in an otherwise thoroughly tested webhook surface.
    - **Plain English:** The system already has code to politely reject a garbled sign-in verification message, but nobody has written a test proving that code actually works. It's a small, already-fixed gap in an otherwise well-tested area.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php:75-78
        if (! preg_match($uuidPattern, $userId) || ! preg_match($uuidPattern, $factorId)) {
            return response()->json(['message' => 'Malformed payload'], 400);
        }
        ```

- [ ] **TEST-5** · P3 — `ConditionalFetchStrategiesTest.php` claims three wired strategies but only tests two
    - **Where:** `tests/Feature/Platforms/Strategies/ConditionalFetchStrategiesTest.php:5,34,52`
    - **Affects:** Confidence that the third conditional-fetch strategy correctly raises `FetchNotModifiedException` on a 304.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Identify the third strategy implementing conditional-fetch (grep `app/Services/Platforms` for classes implementing the same interface as `YoutubeMusicFetch`/`OEmbedFetch`) and add a matching `it()` block, or correct the file-header comment if only two strategies are actually wired.
    - **Technical:** The file's header comment reads "304 behaviour for the three wired strategies," but only `it('YoutubeMusicFetch raises FetchNotModifiedException on a 304', ...)` and `it('OEmbedFetch raises FetchNotModifiedException on a 304', ...)` exist — verified via grep, no third `it()` block present.
    - **Plain English:** A code comment promises three safety checks were tested, but only two tests actually exist. Either a test is missing or the comment is stale — either way it's a small gap in an accounting note that should be trustworthy.
    - **Evidence:**
        ```php
        // tests/Feature/Platforms/Strategies/ConditionalFetchStrategiesTest.php:5
        // 304 behaviour for the three wired strategies. Scrapers are mocked (hermetic — no
        // ... only 2 it() blocks follow: YoutubeMusicFetch (line 34), OEmbedFetch (line 52)
        ```

## Suggested Bundled Sessions

    - **Why grouped:** Standalone new-test-file work covering the four untested policy classes; no production code changes, low risk.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** Same root-cause pattern — tests bypassing the standard factory-seeded-SQLite fixture convention — across different files.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** All independent, small (S/M effort), test-only additions with no cross-file dependencies; efficient to knock out in one session.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None — no P0, auth/money/migration-touching, or L/XL-effort findings survived adjudication.


<!-- ═══════════ audit-2026-07-11-code-quality-slop.md ═══════════ -->

# AI Slop & Low-Value Code Audit — 2026-07-12

**Branch:** HEAD
**Lens:** AI Slop & Low-Value Code — comment noise, premature abstraction, dead code, defensive cruft, copy-paste drift
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/User, app/Services/Media, app/Services/Platforms, app/Services/Feedback, app/Services/Diagnostics
- app/Mail, app/Http/Controllers/Api/User, app/Http/Resources, app/Jobs, app/Console, app/Notifications, app/Observers

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 5 complete

---

## P2 — Should fix

## P3 — Nice to have

- [ ] **#SLOP-4** · P3 — Decorative ASCII-art block banner in a controller
    - **Where:** app/Http/Controllers/Api/User/Content/ContentController.php:236-238
    - **Affects:** Developer reading the file — no user-facing impact.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Delete lines 236-238 (the `/* ---...--- */` banner + `/*  Internals */` label). The `private` methods below are already self-identifying.
    - **Technical:** CLAUDE.md: "Avoid decorative banners." A three-line ASCII-art block separator adds scroll distance and zero information beyond what the `private` keyword on the methods below already states.
    - **Plain English:** There's a boxed-in decorative label reading "Internals" made of dashes and asterisks, sitting above some private helper methods. The word "private" already tells the reader that; the box is just decoration.
    - **Evidence:**
        ```php
            /* ------------------------------------------------------------------ */
            /*  Internals */
            /* ------------------------------------------------------------------ */
        ```

- [ ] **#SLOP-5** · P3 — Dead vestigial variable from removed account-type section gating
    - **Where:** app/Http/Controllers/Api/User/SiteManagement/UserSectionBlockController.php:36
    - **Affects:** No user or system impact — purely a maintainer reading the method body.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Delete line 36: `$allSections = $allowedSections;`.
    - **Technical:** `$allSections` is assigned once and never read again anywhere in the file or the wider `app/` tree (confirmed via `grep -n '\$allSections' app/` — the only hit is the assignment itself). The comment above ("All accounts are individual; all configured section types are allowed") explains why the old account-type-gated `$allSections`-vs-`$allowedSections` split collapsed, but the leftover variable wasn't cleaned up with it.
    - **Plain English:** It's like leaving a spare key on the counter after selling the car it opened — the key does nothing now, but anyone who finds it will wonder what it unlocks. This variable was part of an old feature (checking which sections an account type could use) that got simplified away; the line itself was never removed.
    - **Evidence:**
        ```php
            // All accounts are individual; all configured section types are allowed.
            $allowedSections = config('partna.section_block_types', []);
            $allSections = $allowedSections;
            $unavailableSections = [];
        ```

- [ ] **#SLOP-6** · P3 — Inconsistent empty-object coercion patterns in the same resource
    - **Where:** app/Http/Resources/PublicSite/IndividualProfileResource.php:75-88 (three verbose blocks) vs. lines 127, 140 (concise inline casts)
    - **Affects:** No runtime behaviour — all five produce `{}` in JSON when empty. A maintainer reading the file sees two different patterns for the identical operation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the three verbose blocks with inline `(object)` casts matching the existing `popularity`/`ordering` pattern:
          ```php
          'designKit' => (object) ($this->sections['design_kit'] ?? [])
          'publicConfig' => (object) ($this->sections['public_config'] ?? [])
          'siteImages' => (object) ($this->sections['site_images'] ?? [])
          ```
        - Delete the three intermediate variables (`$designKitOut`, `$publicConfigOut`, `$siteImagesOut`) and their four accompanying comment blocks; fold the "empty must serialize as `{}`" note into a single one-line comment kept above the returned array once.
    - **Technical:** The resource converts empty PHP arrays to empty JSON objects in five places: two use a concise `(object) (...)` cast inline (`popularity`, `ordering`), three use a verbose `$x === [] ? new stdClass : $x` ternary with a dedicated intermediate variable and a multi-line comment. Two of the three comments exist only to point at the first ("Same empty-object coercion as $designKit above") — a mild form of "comments that restate the next line" plus unnecessary ceremony (category 5: needless intermediate variables used once). All five expressions produce identical output, so the divergence is pure copy-paste drift, not a functional distinction.
    - **Plain English:** The same small formatting job — making sure an empty list looks like `{}` instead of `[]` in the browser — is done two different ways in the same file. Three spots use a long-winded recipe with its own prep bowl and a comment pointing back to an earlier comment; two spots do it in one line. Picking the shorter form everywhere makes the file shorter and removes the inconsistency.
    - **Evidence:**
        ```php
        // Empty designKit must serialize as `{}` (object), not `[]` (array).
        // PHP's array → JSON encoder emits `[]` for any empty associative
        // array; cast to stdClass when there are no stored vars so the wire
        // payload matches the spec contract (designKit is always an object).
        $designKit = $this->sections['design_kit'] ?? [];
        $designKitOut = $designKit === [] ? new stdClass : $designKit;

        // Same empty-object coercion as $designKit above.
        $publicConfig = $this->sections['public_config'] ?? [];
        $publicConfigOut = $publicConfig === [] ? new stdClass : $publicConfig;

        // Same empty-object coercion as $designKit above.
        $siteImages = $this->sections['site_images'] ?? [];
        $siteImagesOut = $siteImages === [] ? new stdClass : $siteImages;
        ```
        vs. the concise pattern used elsewhere in the same method:
        ```php
        'popularity' => (object) ($this->sections['popularity'] ?? [])
        ```
        ```php
        'ordering' => (object) ($this->sections['ordering'] ?? [])
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Decorative banner removal:** #SLOP-4
    - **Why grouped:** Same root cause (decorative ASCII-art section dividers violating CLAUDE.md's "avoid decorative banners" rule) across `app/Services/Platforms` and `app/Http/Controllers/Api/User` — purely mechanical deletes, no logic touched.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (combine plan+impl given S effort).

- **Bundle 2 — Dead code & drift cleanup:** #SLOP-5, #SLOP-6
    - **Why grouped:** Same "cleanup pass" theme — a dead-code removal, a dead-variable removal, and a copy-paste-drift consolidation, each isolated to one file with no cross-file coupling.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet. Escalate implement → Opus for only if the reviewer wants extra scrutiny that no other caller relies on `hasStoreKey`/`count` (grep already confirms zero call sites, so this is likely unnecessary).

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-11-semantic-correctness.md ═══════════ -->

# Semantic Correctness Audit — 2026-07-12

**Branch:** HEAD
**Lens:** Semantic Correctness — code that compiles and type-checks but does the wrong thing (plausible-but-wrong API/config/logic usage)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/User
- app/Services/Site
- app/Services/PublicSite
- app/Services/Cache
- app/Services/Accounts
- app/Services/Auth
- app/Services/FeatureFlags
- app/Services/FeatureAvailability
- app/Services/Segments
- app/Services/EarlyAccess
- app/Support
- app/Contracts
- app/helpers.php
- app/Jobs
- app/Http/Controllers/Api/User
- app/Policies
- app/DTOs

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

## P3 — Nice to have

- [ ] **#SEM-2** · P3 — `DevInsightsController::CLICK_SECTION_TO_ITEM_TYPE` is a hand-maintained mirror of a private const in the scoring job
    - **Where:** app/Http/Controllers/Api/User/Analytics/DevInsightsController.php:44-56
    - **Affects:** Developers using the `dev-insights` diagnostic endpoint. Currently harmless — verified byte-for-byte identical to the source map — but nothing enforces that identity going forward.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Promote `ComputeContentPopularityScores::CLICK_SECTION_TO_ITEM_TYPE` (app/Console/Commands/ComputeContentPopularityScores.php:105-130) to a shared location both classes reference, or make it `public`/`@internal`.
        - Add a test asserting the two maps are identical so drift fails CI the moment it happens, rather than silently under-reporting clicks on the dev-only endpoint.
    - **Technical:** Category 3 (plausible-but-wrong magic values, drift risk). Confirmed by direct comparison: `DevInsightsController`'s copy (lines 49-56) and `ComputeContentPopularityScores::CLICK_SECTION_TO_ITEM_TYPE` (lines 105-130) match key-for-key and value-for-value today, so there is no active bug. The risk is purely prospective — the const is a "private, not importable" duplication with an explicit "keep in lockstep by hand" comment and no automated guard, so a future edit to one map without the other would silently make the dev-only endpoint under-report `link_clicks` attribution relative to the real scoring job. This is edge-case/dev-tooling-only and currently correct, matching a P3 "harmless-today deviation that will break under a plausible future change," not a live P1/P2 bug.
    - **Plain English:** A settings list for translating "which website section a click came from" into "what kind of item that counts as" is copy-pasted by hand into two different files instead of living in one shared place. Today both copies match perfectly, so nothing is broken. But if someone updates one copy later and forgets the other, the developer diagnostics page would quietly start showing wrong numbers — like two clocks in the same room that currently agree but will drift apart the next time only one gets rewound.
    - **Evidence:**
        ```php
        /**
         * Mirrors ComputeContentPopularityScores::CLICK_SECTION_TO_ITEM_TYPE (a private
         * const there, not importable). Maps a click's section_key → the item_type its
         * clicks score as, so this endpoint can attribute link_clicks to the same item
         * grain the scoring job does. Keep in lockstep by hand.
         */
        private const CLICK_SECTION_TO_ITEM_TYPE = [
            'shop' => 'shop_product', 'shop-products' => 'shop_product', 'shop-tracks' => 'shop_product', 'bandcamp' => 'shop_product'
            'book' => 'service', 'services' => 'service'
            'events' => 'engine_item', 'attend' => 'engine_item'
            'listen' => 'listen_item', 'music' => 'listen_item', 'spotify' => 'listen_item', 'apple-music' => 'listen_item', 'soundcloud' => 'listen_item', 'podcast' => 'listen_item'
            'watch' => 'watch_item', 'youtube' => 'watch_item', 'twitch' => 'watch_item', 'vimeo' => 'watch_item'
            'custom' => 'link_item', 'other' => 'link_item'
        ];
        ```

## Suggested Bundled Sessions

None — the two surviving findings touch unrelated subsystems (content-selection transactions vs. a dev-only analytics const) and neither shares a file, subsystem, or root cause with the other.

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-17-security.md ═══════════ -->

# Security Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Security: auth boundaries, tenant isolation, mass assignment, inbound callbacks, secrets, injection, SSRF, upload safety, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- `app/Policies/EarlyAccessSignupPolicy.php`, `FeatureAvailabilityPolicy.php`, `FeedbackPolicy.php`, `UserSegmentPolicy.php`, `UserSelfPolicy.php`, `IntegrationConnectionPolicy.php`, `SitePolicy.php`
- `app/Providers/AppServiceProvider.php`, `app/Providers/PlatformRegistryServiceProvider.php`
- `app/Http/Controllers/Api/User/Account/UserAccountDeletionController.php`, `UserSelfController.php`
- `app/Http/Controllers/Api/User/Profile/SectorController.php`
- `app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php`
- `app/Http/Requests/Api/Staff/Segments/StoreSegmentRequest.php`, `Api/Staff/UserSite/StaffUpdateSiteRequest.php`, `Api/User/Site/UpdateSiteRequest.php`, `Api/User/Site/UpsertWorkplaceRequest.php`, `Api/User/UpdateUserRequest.php`, `Concerns/DesignKitValidationRules.php`, `Concerns/SiteOrderingValidationRules.php`, `Platforms/ApplyMenuScanRequest.php`
- `app/Http/Controllers/Api/Platforms/{Booking,DisplaySettings,Fresha,GoogleBusiness,Instagram,Menu,OnlineOrdering,Reservations,Square}Controller.php`
- `app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php`, `PublicMenuController.php`
- `app/Http/Controllers/Api/Staff/Analytics/StaffAggregateAnalyticsController.php`, `Api/Staff/Feedback/StaffFeedbackController.php`, `Api/Staff/UserSiteManagement/StaffUserController.php`
- `app/Http/Resources/PublicSite/IndividualProfileResource.php`, `UserDashboardResource.php`, `Staff/StaffUserListResource.php`, `UserStaffResource.php`
- `app/Services/Design/**` (DesignRationaleService, Presets/*, Scan/EvidenceConclusions, ThemeModePalettes)
- `app/Services/Profile/SectorTaxonomy.php`
- `app/Services/Platforms/{BigCartelScraper,DoorDashMenuDriver,GenericShopScraper,GoogleBusinessAutoSync,IdentitySync,InstagramAutoSync,InstagramScraper,MenuMerger,MenuScanApplier,ShopifyScraper,UberEatsMenuDriver,WebsiteLinkHarvester,WooCommerceScraper}.php`, `Normalizers/FacebookNormalizer.php`, `Payloads/InstagramPayload.php`, `PlatformScraper.php`, `Registry/PlatformDescriptor.php`
- `app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php` (read for cross-file verification)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 7 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

Every finding in this audit is an authorization-boundary or PII-exposure fix — per policy, all run standalone with their own plan + sign-off, never bundled.



<!-- ═══════════ audit-2026-07-17-lifecycle-correctness.md ═══════════ -->

# Lifecycle Correctness Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Lifecycle correctness — race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline (account/site lifecycle, Cloudflare KV subdomain sync, and platform-connector auto-sync scope groups)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Services/User/AccountDeletionService.php
- app/Services/User/SiteProvisioningService.php
- app/Services/User/UserBootstrapService.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Services/Cloudflare/CloudflareKvService.php
- routes/console.php
- app/Console/Commands/BackfillSubdomainKvCommand.php
- app/Services/Platforms/IdentitySync.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Services/Platforms/InstagramAutoSync.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Services/Platforms/InstagramScraper.php
- supabase/migrations/20260526000000_baseline_standalone_user.sql
- supabase/migrations/20260602150238_create_platform_connections.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 9 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

## P2 — Should fix

## P3 — Nice to have

- [ ] **#LIFE-111** · P3 — `InstagramConnectJob::markFailed` increments `consecutive_failures` via read-then-write instead of an atomic increment
    - **Where:** app/Jobs/Platforms/InstagramConnectJob.php:434-441
    - **Affects:** The `consecutive_failures` counter on an Instagram integration connection — cosmetic drift only; the job is already serialized per-connection by its own `ShouldBeUnique`/`uniqueId()` (`"{$this->connectionId}:{$this->username}"`), so a genuine concurrent write to the same connection's counter is already very unlikely in practice.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Replace the manual `(int) $connection->consecutive_failures + 1` computation with `$connection->increment('consecutive_failures')` (paired with a separate `forceFill([...])->saveQuietly()` for the other two fields, or a single `update()` call), which issues an atomic `UPDATE ... SET consecutive_failures = consecutive_failures + 1` at the DB level.
    - **Technical:** This is a textbook read-modify-write gap, but the job's own `ShouldBeUnique` already serializes execution per connection, so the practical exposure is near-zero — the fix is worth doing for correctness hygiene and because it's a one-line change, not because a real incident is likely from this alone.
    - **Plain English:** This counter tracks how many times in a row an Instagram sync has failed for one connection. The way it's incremented today reads the number, adds one, and writes it back — which could theoretically undercount if two updates happened at the exact same instant. In practice the system already prevents that overlap elsewhere, so this is a low-risk cleanup rather than an active bug.
    - **Evidence:**
        ```php
        private function markFailed(IntegrationConnection $connection, string $error): void
        {
            $connection->forceFill([
                'last_refresh_status' => 'unavailable'
                'last_refresh_error' => $error
                'consecutive_failures' => (int) $connection->consecutive_failures + 1
            ])->saveQuietly();
        }
        ```

## Suggested Bundled Sessions

    - **Why grouped:** Same root-cause pattern (missing `user_id`/discriminator in a lifecycle log's catch block) across two files; all S-effort, no auth/money/schema involvement.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Platform auto-sync race hardening:** , , #LIFE-111
    - **Why grouped:** All live in `app/Services/Platforms/` + `app/Jobs/Platforms/`, all stem from unlocked read-modify-write races in the Google Business / Instagram auto-sync pipeline, and / share one fix (same lock key) by design.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** Same file (`SyncSubdomainToKvJob.php`), same subsystem (subdomain routing sync), both touch the alias/TTL and uniqueness logic that a single reviewer should reason about together.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-17-scaling-antipatterns.md ═══════════ -->

# Scaling Antipatterns Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Scaling antipatterns: write amplification, rebuild-on-write, weak caching (chunk: write-paths)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Http/Resources/PublicSite/IndividualProfileResource.php`
- `app/Http/Resources/UserDashboardResource.php`
- `app/Observers/User/UserObserver.php`
- `app/Services/Analytics/ContentFreshness.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

DeepSeek's null result was independently re-verified against each of the four scoped files:

- **`UserObserver.php`** dispatches a bounded, fixed set of side effects per single model save (`invalidateUser`, a conditional `touchParentSiteIfPublicFieldChanged`, a conditional `SyncSubdomainToKvJob::dispatch`, a conditional `reevaluatePublicContactSection`, a conditional `cleanupLifestyleConnectionsIfBusiness`). None of these scale with data cardinality or event payload size — they are O(1) per user update, not a per-row loop, not a rebuild-on-write, and not a fan-out job that multiplies per recipient. This is normal observer side-effect wiring, not the write-amplification shape the lens targets (N rows per event where N is unbounded). User-profile edits are also not a hot/write-heavy path per the platform's own scale context (that's public sitepage resolution and analytics ingest) — even if this work were synchronous request-thread cost, it wouldn't clear the bar for a scaling finding here.
- **`ContentFreshness::boostsForSite`** issues two bounded, well-scoped read queries (`IntegrationConnection::query()->where('user_id', ...)->active()->get(...)` and `Service::query()->where(...)->max('created_at')`), both scoped to one site's own data (bounded by a single user's connections/services — small cardinality, not a list/analytics sweep). It performs no writes, no DELETE+INSERT rebuild, and no cache access at all — it's a pure compute service consumed by other layers (a console command and two services outside this chunk's scope), so there's nothing here for categories (1)–(6) to attach to.
- **`IndividualProfileResource.php`** and **`UserDashboardResource.php`** are pure array-shaping Resource classes — no DB queries, no cache calls, no loops proportional to unbounded input. `UserDashboardResource` touches at most one lazy relation (`$this->site`) on a single-record "own profile" response, which is explicitly out of scope per the N+1 threshold (single row, not a list/sweep).

No rebuild-on-write, write-amplification, weak-caching, live-query, hot-path fan-out, or append-only/mutable-confusion pattern is present in verbatim code across these four files. This is a legitimate clean result for this chunk, not an under-scan — the higher-risk surfaces named in the lens background (analytics ingestors/writers, notification fan-out jobs, other observers) are covered by separate chunks in this sweep.

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-17-database-and-queue-scaling.md ═══════════ -->

# Database & Queue Scaling Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Database & queue scaling — N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Models/Core/Site/Site.php`
- `app/Http/Resources/UserDashboardResource.php`
- `app/Http/Resources/PublicSite/IndividualProfileResource.php`
- `app/Services/Cloudflare/CloudflarePurgeService.php`
- `app/Services/Cloudflare/CloudflareCustomHostnameService.php`
- `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`
- `app/Jobs/Platforms/InstagramConnectJob.php`
- `app/Jobs/Platforms/MenuFetchJob.php`
- `app/Models/Core/Site/Menu.php`, `MenuItem.php`
- `app/Services/Analytics/ContentFreshness.php`
- `app/Console/Commands/CleanupOrphanedLifestyleConnections.php`
- `config/horizon.php`
- `app/Services/Http/SafeUrlFetcher.php` (cross-check only)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

## P3 — Nice to have

- [ ] **#SCALE-103** · P3 — `Site::designKitVars()` issues a raw per-instance query with no batch-loading alternative (latent N+1)
    - **Where:** app/Models/Core/Site/Site.php:179-201
    - **Affects:** `SiteResource::toArray()` and `StaffUserController`'s diagnostic endpoint, both of which are single-`Site` responses today (`SiteResource` has zero `::collection()` call sites in the codebase). Purely a latent risk, not a current one.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - No urgent action needed. If a future staff/dashboard multi-site list endpoint is introduced, eager-load `site.design_kits` in bulk (`WHERE site_id IN (...)`) before mapping to `SiteResource` rather than calling `designKitVars()` per row.
        - Cheaper guard in the meantime: add a test asserting `SiteResource` is never constructed via `::collection()`, so the trap can't be introduced silently.
    - **Technical:** `designKitVars()` runs `DB::connection('pgsql')->table('site.design_kits')->where('site_id', $this->id)->first()` unconditionally, bypassing Eloquent relations entirely (by design — writes go through a matching raw builder in `UserSiteController::writeDesignKit`). This is one query per call, and today every call site (`SiteResource`, `StaffUserController`) operates on a single site, so the cost is exactly one query per request. This same finding was already identified and tiered P3 in the 2026-07-10 sweep (`audits/sweeps/2026-07-10-new-work-sweep`) with the same "no live fan-out endpoint" conclusion, which still holds — re-confirmed via `Grep` for `SiteResource::collection` (no matches).
    - **Plain English:** Each site's visual-style settings live in a separate little box from the main site record. Right now we only ever open one box per request because we only ever show one site at a time. Nothing stops a future "show me all my sites" feature from accidentally opening a separate box for every site in the list — worth a lightweight guardrail so that mistake can't happen silently later, but it isn't hurting anyone today.
    - **Evidence:**
        ```php
        public function designKitVars(): array
        {
            try {
                $row = DB::connection('pgsql')
                    ->table('site.design_kits')
                    ->where('site_id', $this->id)
                    ->first();
            } catch (\Throwable) {
                // Fail-closed to "no stored vars": the editor falls back to package
                // defaults exactly as before this field existed. Also covers the
                // SQLite test mirror, which doesn't create site.design_kits.
                return [];
            }

            if ($row === null) {
                return [];
            }
        ```

- [ ] **#SCALE-104** · P3 — `SyncSubdomainToKvJob` has no explicit rate-limit middleware on Cloudflare KV writes, relying only on implicit Horizon worker-count throttling
    - **Where:** app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:56-64, :122
    - **Affects:** Every profile mutation that triggers a KV sync (handle changes, moderation actions, connect/disconnect). A mass moderation event or bulk handle-update could queue many jobs at once.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add an explicit `RateLimited` middleware (mirroring the `platform-connect` pattern already used by `InstagramConnectJob`/`MenuFetchJob`) as defense-in-depth, so KV write throughput stays bounded even if `supervisor-cloudflare`'s `maxProcesses` is ever raised for unrelated reasons.
        - Not urgent: `config/horizon.php`'s `supervisor-cloudflare` already caps this queue at `maxProcesses: 2` (explicitly commented as "Cloudflare's API is rate-limited, not CPU-bound"), and each job issues at most 3 Cloudflare HTTP calls (1 handle `put`, 1 optional custom-domain `put`, 1 batched `bulkPut` for aliases — the alias fan-out was already fixed to a single bulk call, per the file's own "SCALE-6" comment). Two concurrent workers × 3 requests is far below Cloudflare KV's write-rate budget.
    - **Technical:** `SyncSubdomainToKvJob` has no `middleware()` method, so nothing throttles KV write throughput independent of worker count. In practice, `config/horizon.php`'s `supervisor-cloudflare` (`maxProcesses: 2`, `nice: 5`) already provides substantial implicit backpressure — this was designed in deliberately ("Low process count: Cloudflare's API is rate-limited, not CPU-bound"), and the alias-write fan-out is already batched into one `bulkPut`. The remaining gap is that this protection is *implicit* (tied to a supervisor config value someone could change without realizing it removes the rate-limit) rather than *explicit* (a `RateLimited` middleware tied to Cloudflare's actual documented budget, as `InstagramConnectJob`/`MenuFetchJob` already do for the `platform-connect` budget). Worth closing for consistency, not urgent given current bounds.
    - **Plain English:** The system that syncs a user's web address to Cloudflare currently stays safe only because we've limited how many workers can run this job at once — like a shop that only lets two clerks work the till, so the line never gets too long even without a formal "one customer at a time" sign. It works today, but it's an accident of a different setting rather than a deliberate rule, so if someone later increases that worker count for an unrelated reason, this safety net disappears without anyone noticing.
    - **Evidence:**
        ```php
            public function __construct(
                public readonly string $userId
                public readonly ?string $capturedHandle = null
                public readonly ?string $retireCustomDomain = null
            ) {
                // Isolated from user-facing work so a burst of platform-connection writes
                // can't delay notifications or mail delivery.
                $this->onQueue(config('partna.queues.cloudflare', 'cloudflare'));
            }
        ```
        ```php
        $kv->put($current, ['type' => 'individual'], null);
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Cloudflare API rate-limit hardening:** , #SCALE-104
    - **Why grouped:** Same vendor (Cloudflare), same root theme (outbound write-rate defense-in-depth), touch adjacent files in `app/Services/Cloudflare` and `app/Jobs/Cloudflare`.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

- **#SCALE-103 — Site::designKitVars latent N+1 guard** · different subsystem (`app/Models/Core/Site/`), low urgency (purely latent, no live fan-out path), stands alone.


<!-- ═══════════ audit-2026-07-17-schema-rls.md ═══════════ -->

# Schema / RLS / search_path Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Schema / RLS / search_path: database-side correctness, constraint coverage, migration safety
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Models/Core/Site/Menu.php
- app/Models/Core/Site/MenuItem.php
- app/Models/Core/Site/Site.php
- app/Models/Core/User/User.php
- supabase/migrations/20260711170000_users_email_unique_case_insensitive.sql
- supabase/migrations/20260712000000_retire_staff_account_type.sql
- supabase/migrations/20260713120000_reconcile_instagram_gallery_unification.sql
- supabase/migrations/20260714200000_architecture_one_to_staple.sql
- supabase/migrations/20260714210000_drop_effect_surface.sql
- supabase/migrations/20260714220000_add_aesthetic_axes.sql
- supabase/migrations/20260714230000_drop_glass_satellites.sql
- supabase/migrations/20260715090000_menu_item_currency_and_dining_modes.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 4 complete

---

## P2 — Should fix

## P3 — Nice to have

- [ ] **SCHEMA-103** · P3 — Unindexed `DELETE` on `site.design_kit_contributions` in `20260714210000_drop_effect_surface.sql`
    - **Where:** supabase/migrations/20260714210000_drop_effect_surface.sql:11
    - **Affects:** `site.design_kit_contributions` — the `target_var` column carries no index, so this (and every sibling migration below) forces a sequential scan.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Low urgency: `site.design_kit_contributions` is a small, per-site provenance table (bounded by site count × factor count, not a hot read-path table — confirmed no app code queries it on the public sitepage path), and this exact `DELETE ... WHERE target_var = '<retired-column>'` cleanup has already run identically at least five times before this pair (`20260710210000_surfaces_backend.sql`, `20260710160000_design_kit_theme_surface_rework.sql`, `20260710190000_semantic_text_scale_and_vocab_remap.sql`, `20260709064322`/`20260705000000` font-slug migrations) without incident — this is an established, accepted convention, not a novel risk.
        - If the design-kit column-retirement cadence keeps recurring at this rate, add `CREATE INDEX CONCURRENTLY idx_design_kit_contributions_target_var ON site.design_kit_contributions (target_var);` once, rather than re-flagging each future retirement migration.
    - **Technical:** No CI guard covers this pattern (`scripts/guard-no-unsafe-migrations.php` only checks index/constraint/NOT-NULL patterns, not `DELETE`), and the table's only indexes are `idx_design_kit_contributions_site` / `idx_design_kit_contributions_site_integration` plus the `UNIQUE (site_id, source, target_var)` — none has `target_var` as a leading column. Given the table's small, bounded size at current scale and the repeated precedent, this is hardening/write-amplification hygiene rather than an active risk.
    - **Plain English:** This step wipes out old configuration rows for a design option that no longer exists, using a filter the database can't look up quickly — so it has to check every row. On the small housekeeping table involved, that's harmless today, but it's the same shortcut the team has now taken six times in a row; worth a one-time index if this keeps happening.
    - **Evidence:**
        ```sql
        DELETE FROM site.design_kit_contributions WHERE target_var = 'effect_surface';
        ```

- [ ] **SCHEMA-104** · P3 — Unindexed `DELETE` on `site.design_kit_contributions` in `20260714230000_drop_glass_satellites.sql`
    - **Where:** supabase/migrations/20260714230000_drop_glass_satellites.sql:11-12
    - **Affects:** Same table/root cause as SCHEMA-103.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Same as SCHEMA-103 — no action needed beyond the same one-time index if the pattern keeps recurring.
    - **Technical:** Identical root cause to SCHEMA-103 (same migration series, same table, same missing index), tiered identically per the "same root cause, same tier" rule.
    - **Plain English:** Same situation as the previous item, just for three more retired design options removed in the very next migration.
    - **Evidence:**
        ```sql
        DELETE FROM site.design_kit_contributions
        WHERE target_var IN ('effect_scrim_blur', 'effect_glass_blur', 'motion_glass_shine_duration');
        ```

- [ ] **SCHEMA-105** · P3 — `DROP COLUMN` without a rename-to-deprecated cycle in `20260714210000_drop_effect_surface.sql`
    - **Where:** supabase/migrations/20260714210000_drop_effect_surface.sql:13
    - **Affects:** `site.design_kits.effect_surface` — theoretical risk window if this migration is applied to Supabase before the corresponding app-code deploy lands.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - No change needed for this specific pair: confirmed via grep that `app/Services/Design/Presets/PresetTargetableColumns.php`, `PlatformMixFactor.php`, and `Http/Requests/Concerns/DesignKitValidationRules.php` already only reference `effect_surface` in retirement comments, not as an active read/write target — the app-side decoupling landed in the same change as this migration, and Laravel Cloud's deploy model here works in the safe order (code deploys automatically on push; `supabase db push` for this migration is a separate, manual, developer-controlled step run after the code is already live).
        - This exact direct-drop pattern is already the established convention for `site.design_kits` column retirement (`20260528090000_drop_design_kit_row_height.sql`, `20260710210000_surfaces_backend.sql`, `20260527070000_skeleton_system_cleanup.sql`) — if the team wants to formalize it, add a short note to `supabase/migrations/CONVENTIONS.md` codifying "drop directly once app code in the same change stops referencing the column, and apply the migration after the code deploy is confirmed live" so a future reviewer doesn't need to re-derive the safety argument each time.
    - **Technical:** DeepSeek's draft treated this as a live mixed-version-deploy hazard, but that specific risk (old app instances still writing/reading a dropped column) doesn't apply here — the read/write path was already fully decoupled from `effect_surface` before this migration is applied, and the pattern has been used at least three times before on this same table with no reported incident. Downgraded from the draft's P2 to P3: worth documenting the convention, not worth a rename-cycle rework.
    - **Plain English:** Removing a shelf from a warehouse is only dangerous if a worker's shopping list still says "go to that shelf." Here, the list (the app code) was updated in the same change to stop mentioning the shelf before the shelf actually gets removed, and removal happens as a separate, deliberate step the team controls — so nobody collides with a wall. This has been the team's practice for design-option cleanups several times already without a problem.
    - **Evidence:**
        ```sql
        ALTER TABLE site.design_kits DROP COLUMN IF EXISTS effect_surface;
        ```

- [ ] **SCHEMA-106** · P3 — `DROP COLUMN` without a rename-to-deprecated cycle in `20260714230000_drop_glass_satellites.sql`
    - **Where:** supabase/migrations/20260714230000_drop_glass_satellites.sql:14-17
    - **Affects:** `site.design_kits.effect_scrim_blur` / `effect_glass_blur` / `motion_glass_shine_duration` — same root cause and same mitigations as SCHEMA-105.
    - **Effort:** S (~0.5–1h)
    - **What to do:** Same as SCHEMA-105 — no action needed for this pair beyond optionally documenting the convention.
    - **Technical:** Identical root cause to SCHEMA-105 (same migration series, same table, same already-decoupled app code), tiered identically.
    - **Plain English:** Same as the previous item, just three shelves at once instead of one — the workers' list was already updated first, so there's nothing to collide with.
    - **Evidence:**
        ```sql
        ALTER TABLE site.design_kits
            DROP COLUMN IF EXISTS effect_scrim_blur
            DROP COLUMN IF EXISTS effect_glass_blur
            DROP COLUMN IF EXISTS motion_glass_shine_duration;
        ```

## Suggested Bundled Sessions

None — every finding in this audit is a direct DB migration/schema change, which the fix-flow policy always routes to standalone execution (see below), matching how the prior schema-rls audit (`audits/sweeps/2026-07-10-new-work-sweep/CONSOLIDATED.md`) handled the same category of findings.

## Standalone — do NOT bundle

- **SCHEMA-103 — drop_effect_surface.sql DELETE** · DB migration/schema change.
- **SCHEMA-104 — drop_glass_satellites.sql DELETE** · DB migration/schema change.
- **SCHEMA-105 — drop_effect_surface.sql DROP COLUMN** · DB migration/schema change.
- **SCHEMA-106 — drop_glass_satellites.sql DROP COLUMN** · DB migration/schema change.


<!-- ═══════════ audit-2026-07-17-caching-gold-standard.md ═══════════ -->

# Caching Gold-Standard Adherence Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Caching gold-standard adherence — single-flight locks, TTL jitter, stale-while-revalidate, push-invalidation, version tokens, lock/connection hygiene, bounded TTLs, and key-generation drift (categories 1–10), measured against the `CacheLockService` / `SiteCacheService` / `UserCacheService` reference pattern documented in `docs/caching-gold-standard.md`.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Accounts/AccountCapabilitySet.php
- app/Services/Accounts/LifestyleConnectionCleanup.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/PublicSite/SiteActionsService.php
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Observers/User/UserObserver.php
- app/Services/Analytics/ContentFreshness.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

## Suggested Bundled Sessions

    - **Why grouped:** Single file, single root cause (three identical silent-catch occurrences of the same pattern) — one fix session touches `updated()`, `deleted()`, and `restored()` together.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (S-effort, no escalation needed).

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-17-webhook-idempotency.md ═══════════ -->

# Inbound Callbacks & Idempotency Semantics Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Inbound callbacks & idempotency semantics — Supabase auth/email hooks, `bot.token`-gated internal endpoints, and the client-supplied `IdempotencyKey` middleware, measured against the Partna gold-standard callback pattern (HMAC-before-parse, persisted idempotency anchors, 200-only-on-success, no domain mutations outside a job).
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php`
- `app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php`
- `app/Http/Controllers/Api/Internal/EnvCheckController.php`
- `app/Http/Controllers/Api/Internal/CspReportController.php`
- `app/Http/Middleware/Auth/VerifySupabaseHookSignature.php`
- `app/Services/Webhooks/StandardWebhookVerifier.php`
- `app/Http/Middleware/IdempotencyKey.php`
- `app/Http/Middleware/VerifyBotToken.php`
- `app/Services/Auth/AuthFactorEventRepository.php`
- `app/Services/Notifications/SupabaseEmailEventService.php`
- `app/Services/User/AccountDeletionService.php`
- `app/Mail/BaseTransactionalMail.php`, `app/Mail/Auth/EmailConfirmMail.php`
- `routes/api.php`, `routes/api/user.php`, `bootstrap/app.php`
- `routes/api/platforms.php` (original scan scope — no callback surface present)

**Note on scope:** the DeepSeek scan for this chunk was configured with `--scope routes/api/platforms.php`, which contains none of this lens's target surface (no hook controllers, no `IdempotencyKey`/`VerifyBotToken`/`StandardWebhookVerifier`). That "no findings" draft is accurate for the scope it was given, but the scope itself was a pipeline misconfiguration — `routes/api/platforms.php` holds only authenticated dashboard integration routes. Per the adjudicator's mandate to read source against the lens and add missed findings, this audit instead directly reads the Group A–E files the lens actually targets (`SupabaseAuthHookController`, `SupabaseEmailHookController`, `VerifySupabaseHookSignature`, `StandardWebhookVerifier`, `IdempotencyKey`, `VerifyBotToken`, `routes/api.php`).

**Overall finding:** this surface is unusually well-hardened already — in-code annotations (`WHK-101`…`WHK-5`, `OBS-101`, `OBS-4`, `PRIV-101`, `LIFE-102`, `CCH-101`, `SCALE-101/2`) and matching Pest coverage (`SupabaseAuthHookBruteForceTest`, `SupabaseAuthHookSignatureTest`, `SupabaseEmailHookTest`, `IdempotencyKeyMiddlewareTest`, `VerifyBotTokenTest`) show this exact lens has already been through at least one hardening pass. HMAC-before-parse, `hash_equals`, timestamp tolerance, atomic `Cache::add` anchors, anchor-reversal-on-failure, 500-on-dispatch-failure, and stable Message-ID mail dedup are all correctly implemented and verified against source. Two narrower gaps survived review.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

## Suggested Bundled Sessions

None — the two surviving findings touch unrelated files and subsystems (auth-hook audit trail vs. account-deletion lifecycle) with no shared root cause.

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-17-transaction-boundaries.md ═══════════ -->

# Transaction Boundary Correctness Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Transaction boundary correctness — every `DB::transaction`/`DB::beginTransaction` site measured against the gold-standard discipline (no external I/O, no queue dispatch, no cache writes, no side-effecting event/observer hooks inside a transaction; bounded scope; safe retry semantics; intentional nesting; consistent lock ordering).
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Observers/User/UserObserver.php
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Accounts/AccountCapabilitySet.php
- app/Services/Accounts/LifestyleConnectionCleanup.php
- app/Services/User/AccountDeletionService.php
- app/Services/User/SiteProvisioningService.php
- app/Services/User/UserBootstrapService.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Services/Cloudflare/CloudflareCustomHostnameService.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Services/Platforms/BigCartelScraper.php
- app/Services/Platforms/DoorDashMenuDriver.php
- app/Services/Platforms/GenericShopScraper.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Services/Platforms/IdentitySync.php
- app/Services/Platforms/InstagramAutoSync.php
- app/Services/Platforms/InstagramScraper.php
- app/Services/Platforms/MenuMerger.php
- app/Services/Platforms/MenuScanApplier.php
- app/Services/Platforms/Normalizers/FacebookNormalizer.php
- app/Services/Platforms/Payloads/InstagramPayload.php
- app/Services/Platforms/PlatformScraper.php
- app/Services/Platforms/Registry/PlatformDescriptor.php
- app/Services/Platforms/ShopifyScraper.php
- app/Services/Platforms/UberEatsMenuDriver.php
- app/Services/Platforms/WebsiteLinkHarvester.php
- app/Services/Platforms/WooCommerceScraper.php
- app/Jobs/Account/SendAccountDeletionRequestMailJob.php (verification read)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

## Suggested Bundled Sessions

None — the two surviving findings touch unrelated subsystems (menu-scraper sync metadata vs. account-deletion mail dispatch) with no shared file, subsystem, or root cause.

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-17-data-integrity.md ═══════════ -->

# Data Integrity & Privacy Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Data integrity & privacy — FK hygiene, soft-delete coherence, orphan rows, JSONB drift, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Enums/AccountType.php
- app/Enums/SitepageId.php
- database/factories/Core/Site/SiteFactory.php
- supabase/migrations/20260711170000_users_email_unique_case_insensitive.sql
- supabase/migrations/20260712000000_retire_staff_account_type.sql
- supabase/migrations/20260713120000_reconcile_instagram_gallery_unification.sql
- supabase/migrations/20260714200000_architecture_one_to_staple.sql
- supabase/migrations/20260714210000_drop_effect_surface.sql
- supabase/migrations/20260714220000_add_aesthetic_axes.sql
- supabase/migrations/20260714230000_drop_glass_satellites.sql
- supabase/migrations/20260715090000_menu_item_currency_and_dining_modes.sql
- supabase/migrations/20260619050000_menu_relational_redesign.sql
- supabase/migrations/20260617130000_create_menus.sql
- app/Models/Core/Site/Menu.php
- app/Models/Core/Site/MenuItem.php
- app/Models/Core/Site/MenuCategory.php
- app/Models/Core/Site/MenuPlatformLink.php
- app/Models/Core/Site/Site.php
- app/Models/Core/User/User.php
- app/Observers/User/UserObserver.php
- app/Services/User/AccountDeletionService.php
- app/Services/User/SiteProvisioningService.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Console/Commands/PurgeSoftDeleted.php
- app/Console/Commands/PruneExpiredHandleAliases.php
- app/Http/Requests/Concerns/DesignKitValidationRules.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

## P3 — Nice to have

- [ ] **#DINT-103** · P3 — `AccountType::Individual` enum case has outlived its documented purpose and can no longer be written to the DB
    - **Where:** app/Enums/AccountType.php:28
    - **Affects:** Code hygiene only. Confirmed no application write path can currently produce `account_type = 'individual'` — `app/Http/Requests/Api/User/UpdateUserRequest.php` explicitly rejects it at validation, and `database/factories/UserFactory.php` defaults to `'partna'`. All ~90 test-suite references to `'individual'` are explicit fixture overrides running against the SQLite test mirror, which doesn't enforce the Postgres CHECK.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm via Larastan/grep that no remaining code path reads or writes `AccountType::Individual` as a live gating condition.
        - Remove the `case Individual = 'individual';` member and update the pinned `AccountTypeFoundationTest.php` expectation.
        - Leave the test-suite fixture usages of the literal string `'individual'` as-is unless they specifically assert enum-casting behavior — they exercise a different (still-valid) legacy-tolerance code path.
    - **Technical:** The enum's own docblock states `'individual'` is kept "ONLY so Eloquent casting never throws on a row read between the code deploy and the backfill migration (20260612120000_account_type_partna_business)." That backfill ran over a month ago (2026-06-12), and the `core.users` CHECK constraint has excluded `'individual'` since that same migration (further narrowed to drop `'staff'` on 2026-07-12, per `20260712000000_retire_staff_account_type.sql`, verified line 22-23). The stated justification for retaining the case is now factually obsolete. This is genuine cleanup debt, not intentional dormancy — the codebase has already set the precedent of removing dead `AccountType` cases (`AccountType::Staff`, retired 2026-07-12) once their purpose expired.
    - **Plain English:** The system has an old, unused option ("Individual") left over from a one-time data migration that finished more than a month ago. It causes no harm today — nothing can actually pick it or save it — but it's exactly the kind of forgotten leftover that confuses a future developer who wonders why it's still there. Low priority cleanup, not a live risk.
    - **Evidence:**
        ```php
        // app/Enums/AccountType.php:28 — the dead case still present
        case Individual = 'individual';
        ```
        ```sql
        -- supabase/migrations/20260712000000_retire_staff_account_type.sql:22-23
        ALTER TABLE core.users
            ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('partna', 'business')) NOT VALID;
        ```

- [ ] **#DINT-104** · P3 — `site.menus.dining_modes` JSONB has no shape enforcement beyond app-layer normalization
    - **Where:** supabase/migrations/20260715090000_menu_item_currency_and_dining_modes.sql:11-18
    - **Affects:** `site.menus.dining_modes` — any future consumer beyond the current single reader (`MenuController`/public menu payload) must independently defend against a non-array value reaching it via a direct DB write, since the column's documented `["DELIVERY","PICKUP"]` shape is enforced only by `UberEatsMenuDriver::diningModes()` on the write path, not by the schema.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CHECK (dining_modes IS NULL OR jsonb_typeof(dining_modes) = 'array')` — validates the outer shape only.
        - Do **not** constrain the element values to a fixed list. Confirmed `UberEatsMenuDriver::diningModes()` passes through whatever mode-name strings Uber Eats' `supportedDiningModes` API returns, with no app-side vocabulary; an external-vocabulary CHECK (as originally proposed) would reject legitimate new dining-mode strings the moment Uber Eats introduces one, silently failing future scrapes.
    - **Technical:** The column comment documents an array-of-strings shape and `UberEatsMenuDriver::diningModes()` (verified) already normalizes to `list<string>|null`, tolerating both `[{mode, isAvailable}]` and bare string-list API shapes. But this normalization is PHP-side only — nothing at the DB layer stops a non-array value from being written by a future direct-DB path. Since the vocabulary is externally controlled (Uber Eats' API), the fix should validate structure, not content.
    - **Plain English:** The "dining modes" column is supposed to always be a short list of delivery options like "DELIVERY, PICKUP." The one piece of code that writes it already double-checks this, but the database itself doesn't — so a future bug or manual fix could accidentally store something that isn't a list, and the next reader would crash trying to use it. Adding a lightweight "this must be a list" rule at the database level is cheap insurance, without trying to lock down which specific option names are allowed (that list comes from Uber Eats, not from us, and could grow).
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260715090000_menu_item_currency_and_dining_modes.sql
        ALTER TABLE site.menus
            ADD COLUMN IF NOT EXISTS dining_modes jsonb NULL;

        COMMENT ON COLUMN site.menus.dining_modes IS
            'Store-level supported dining modes from the Uber Eats scrape (e.g. ["DELIVERY","PICKUP"]); NULL when unavailable (DoorDash exposes none).';
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Code-only hygiene (no schema change):** , #DINT-103
    - **Why grouped:** Both are code-only changes (a model-level guard + regression test; an enum-case removal) with no DB migration, no auth/money surface, and no shared file — safe to execute together in one low-risk session.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#DINT-104 — `dining_modes` JSONB column lacks shape validation** · standalone: DB migration/schema change (ADD CONSTRAINT).


<!-- ═══════════ audit-2026-07-17-job-queue-correctness.md ═══════════ -->

# Job/Queue Correctness Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Job/Queue Correctness — idempotency, retry safety, ShouldBeUnique, missing `$this->fail()`, retry storms
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Console/Commands/CleanupOrphanedLifestyleConnections.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

## Suggested Bundled Sessions

    - **Why grouped:** Same file (`SyncSubdomainToKvJob.php`), same root-cause pattern (swallowed `Throwable` on a `$pro->site`/`$pro?->site` read during a moderation/takedown-adjacent path), same fix shape (stop catching, let Horizon's existing 3-try backoff handle it).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-17-observability.md ═══════════ -->

# Observability Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Observability: logging gaps, silent failures, missing Nightwatch instrumentation — jobs that swallow exceptions, inbound callbacks that 200-but-don't-process, missing Nightwatch coverage, log calls that obscure rather than illuminate
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Console/Commands/CleanupOrphanedLifestyleConnections.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Services/Cloudflare/CloudflareCustomHostnameService.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Services/Platforms/BigCartelScraper.php
- app/Services/Platforms/DoorDashMenuDriver.php
- app/Services/Platforms/GenericShopScraper.php
- app/Services/Platforms/GoogleBusinessAutoSync.php
- app/Services/Platforms/IdentitySync.php
- app/Services/Platforms/InstagramAutoSync.php
- app/Services/Platforms/InstagramScraper.php
- app/Services/Platforms/MenuMerger.php
- app/Services/Platforms/MenuScanApplier.php
- app/Services/Platforms/Normalizers/FacebookNormalizer.php
- app/Services/Platforms/Payloads/InstagramPayload.php
- app/Services/Platforms/PlatformScraper.php
- app/Services/Platforms/Registry/PlatformDescriptor.php
- app/Services/Platforms/ShopifyScraper.php
- app/Services/Platforms/UberEatsMenuDriver.php
- app/Services/Platforms/WebsiteLinkHarvester.php
- app/Services/Platforms/WooCommerceScraper.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

## P3 — Nice to have

- [ ] **OBS-102** · P3 — `InstagramScraper::latestMedia` emits an unconditional `Log::info` diagnostic on every scrape
    - **Where:** app/Services/Platforms/InstagramScraper.php:208-216
    - **Affects:** `cloud env:logs partna development` signal-to-noise for anyone manually triaging Instagram-related issues — every profile scrape (connect + periodic refresh) writes a structured info entry regardless of outcome.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Gate the `Log::info` behind `app()->isLocal()`, or drop it once the reels-ordering question it was added to answer is confirmed resolved — the comment marks it as a point-in-time diagnostic, not a permanent operational signal.
    - **Technical:** `config/nightwatch.php`'s `filtering.log_level` defaults to `warning`, so this `Log::info` call was never reaching Nightwatch's alert stream in the first place — the cost here is purely operational-log noise in `cloud env:logs`, not a missed alert. The comment ("confirms from the dev logs whether Apify is returning reels at all for this account") reads as a temporary investigation aid left in place after the investigation concluded.
    - **Plain English:** Every time someone connects or refreshes their Instagram, the system writes a diagnostic note into the operational logbook, even when everything worked fine. It doesn't trigger any alerts, but it does mean anyone scrolling through the logs to debug a real problem has to wade through a note for every single successful scrape too.
    - **Evidence:**
        ```php
        Log::info('instagram.latest_media', [
            'user_id' => $userId
            'posts' => count($posts)
            'videos' => count(array_filter($posts, fn ($p) => is_array($p) && data_get($p, 'type') === 'Video'))
            'picked_photo' => $photo !== null
            'picked_video' => $video !== null
        ]);
        ```

- [ ] **OBS-103** · P3 — `CloudflareCustomHostnameService::delete()` never checks the API response, so the 3 call sites that already catch its failures never receive one
    - **Where:** app/Services/Cloudflare/CloudflareCustomHostnameService.php:91-98
    - **Affects:** Custom-domain disconnect/cleanup for users with a connected domain — a failed Cloudflare hostname deletion (bad token, transient outage, rate limit) leaves the certificate/hostname active on Cloudflare's zone while Partna's own `site.custom_domain*` columns show it disconnected. A lingering hostname can also 409 a future `create()` attempt to reuse that same domain.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->throw()` after `->timeout(5)->delete($this->base()."/{$id}")`. All three current call sites in `app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php` (lines 61, 92, 172) already wrap `$this->cf->delete(...)` in `catch (Throwable $e) { report($e); }` — they're dead code today because `delete()` can't throw on a non-2xx response; adding `->throw()` alone makes the failure Nightwatch-visible with zero other changes.
        - Add a test file for `CloudflareCustomHostnameService` — none currently exists (`create()`/`get()`/`delete()` all have zero direct test coverage), including a case asserting a non-2xx `delete()` response throws.
    - **Technical:** Guzzle/`Http::` returns a `Response` object for every status code unless `->throw()` (or an explicit status check) is added — `create()` and `get()` in this same class both call `->throw()` correctly, but `delete()` was written without it, silently discarding 401/404/500 responses. Because every caller of `delete()` already anticipates and reports a `Throwable`, this is a one-line fix that activates existing, already-deployed error handling rather than requiring new call-site changes.
    - **Plain English:** When a user disconnects their custom domain, the app tells Cloudflare to remove the certificate. If Cloudflare's API has a bad day and returns an error instead of succeeding, the current code doesn't notice — it just moves on. The domain stays live on Cloudflare's side while Partna's own records say it's gone, which can later block the same domain from being reconnected. The three places in the app that call this already know how to report a failure loudly — they're just never given the chance to, because this one method never tells them anything went wrong.
    - **Evidence:**
        ```php
        public function delete(string $id): void
        {
            if (! $this->configured || $id === '') {
                return;
            }
            Http::withToken($this->apiToken)->timeout(5)->delete($this->base()."/{$id}");
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Vendor-I/O failure visibility hygiene:** , #OBS-102, #OBS-103
    - **Why grouped:** all three are single-file, S-effort fixes in the Platforms/Cloudflare vendor-I/O layer, all following the same established fix pattern (bump log severity / add `report($e)` / add `->throw()` to activate an already-present catch) plus a small test addition — no shared file, but a coherent one-session batch.
    - **Model:** Plan: Opus (combine plan+impl) · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-17-caching-coverage-gaps.md ═══════════ -->

# Caching Coverage Gaps Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Caching coverage gaps — hot, expensive reads with no cache at all (absence-only; the inverse of the gold-standard-adherence lens)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php
- app/Http/Controllers/Api/PublicSite/PublicMenuController.php
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Accounts/AccountCapabilitySet.php
- app/Services/Accounts/LifestyleConnectionCleanup.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/PublicSite/SiteActionsService.php
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Services/Analytics/ContentPopularityReader.php (pulled in for cross-check — shared by both findings)
- app/Http/Controllers/Api/PublicSite/IndividualProfileController.php, app/Http/Middleware/Context/LoadCurrentUser.php, routes/api.php, app/Http/Controllers/Api/ApiController.php (pulled in to confirm the existing cache boundary before flagging gaps)
- app/Services/Platforms/* (24 files — no findings; reads are job/connect-time, not hot-path)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

## P3 — Nice to have

- [ ] **#CCG-101** · P3 — `presentPageIds()`'s 7-query fan-out runs twice inside one `build()` call
    - **Where:** app/Services/PublicSite/SitepageDataResolverService.php:174-309 (`presentPageIds`), app/Services/PublicSite/IndividualProfilePayloadBuilder.php:97-109 (`build`), app/Services/PublicSite/SiteActionsService.php:94-95 (`pool`)
    - **Affects:** Every public sitepage payload cache miss (behind the 60s `rememberLocked` wrapper) — doubles the presence-probe query fan-out on each miss, though single-flight locking means this cost isn't multiplied across concurrent viewers of the same handle.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Compute `presentPageIds()` once in `IndividualProfilePayloadBuilder::build()` and thread the resulting `list<string>` into both the page-order branch and into `SiteActionsService::pool()` as an optional pre-resolved parameter (mirroring how `$sections`/`$booking`/`$ranks` are already injectable into `pool()`).
        - This is request-scoped de-duplication only — no Redis/`rememberLocked` needed, since the whole `build()` output is already behind the public-profile cache.
    - **Technical:** `IndividualProfilePayloadBuilder::build()` resolves `$pageOrder` via either `buildPageOrder()` (which internally calls `presentPageIds()`) or, in manual-order mode, calls `presentPageIds()` directly — then unconditionally calls `$this->actions->pool($pro, $site, $sections, $booking, $ranks)`, whose first two lines call `AccountCapabilities::for($pro)` (cheap, WeakMap-memoized) and `$this->resolver->presentPageIds($site, $caps, $sections)` again. With identical `$site`/`$caps`/`$sections` inputs, this is a second full run of the ~7-query presence-probe fan-out (`IntegrationConnection` pluck, conditional `ShopProduct` exists, GB `display_settings` first, `Menu` exists, `Service` exists, links `Block` exists, gallery `SiteMedia` exists) inside the same `build()` invocation, every time. Impact is bounded to one cache-miss execution per 60s window per site (not per concurrent viewer), since `IndividualProfileController` wraps the whole build in `CacheLockService::rememberLocked` (confirmed single-flight) — this keeps the finding at P3 rather than P2.
    - **Plain English:** Picture a receptionist who, for one visitor, walks through a checklist of "does this person have a menu? gallery photos? services? links?" — then, thirty seconds later for the exact same visitor, walks through the identical checklist again from scratch before handing over the final folder. Nothing changed between the two passes. It only wastes effort once per cache refresh (not once per website visitor), so it's low-priority polish rather than an urgent fix.
    - **Evidence:**
        ```php
        // app/Services/PublicSite/IndividualProfilePayloadBuilder.php:97-109
        $pageOrder = $ordering['smart_page_order']
            ? $this->resolver->buildPageOrder($site, $caps, $sections, $ranks['page'] ?? [])
            : $this->actions->applyManualPageOrder(
                $this->resolver->presentPageIds($site, $caps, $sections)
                $ordering['manual_page_order']
            );

        $rankedActions = $this->actions->resolveRankedActions(
            $this->actions->pool($pro, $site, $sections, $booking, $ranks)
            $this->popularity->rankedActionsForSite($site?->id)
            $ordering['smart_actions']
            $ordering['manual_actions']
        );
        ```
        ```php
        // app/Services/PublicSite/SiteActionsService.php:94-95 — pool() always recomputes:
        $caps = AccountCapabilities::for($pro);
        $present = $this->resolver->presentPageIds($site, $caps, $sections);
        ```
        ```php
        // app/Services/PublicSite/SitepageDataResolverService.php:194-202 — one of the ~7 probes fanned out per call:
                $platforms = $this->safeQuery(
                    fn () => IntegrationConnection::query()
                        ->where('user_id', $userId)
                        ->where('is_active', true)
                        ->distinct()
                        ->pluck('platform')
                        ->all()
                    []
                );
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Public sitepage read-path caching hygiene:** #CCG-101
    - **Why grouped:** Both are request-scoped/cache-coverage fixes on the same public-profile read family (`IndividualProfilePayloadBuilder` and its sibling public controllers); neither touches auth, money, or schema, and both are small enough to plan+implement together.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-17-privacy-compliance.md ═══════════ -->

# Privacy & Data-Rights Compliance Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Privacy & data-rights compliance — PII inventory completeness, export/delete completeness, retention enforcement, minimisation at collection, processor/third-party flows, and staff access auditing, evaluated against the account-deletion, GDPR-export, and signup-bootstrap machinery.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Resources/PublicSite/IndividualProfileResource.php
- app/Http/Resources/UserDashboardResource.php
- app/Models/Core/Site/Menu.php
- app/Models/Core/Site/MenuItem.php
- app/Models/Core/Site/Site.php
- app/Models/Core/User/User.php
- app/Services/User/AccountDeletionService.php
- app/Services/User/SiteProvisioningService.php
- app/Services/User/UserBootstrapService.php
- app/Console/Commands/CleanupOrphanedLifestyleConnections.php
- app/Mail/Branding/EmailBrandDefaults.php
- app/Services/Analytics/ContentFreshness.php
- supabase/migrations/20260711170000_users_email_unique_case_insensitive.sql
- supabase/migrations/20260712000000_retire_staff_account_type.sql
- supabase/migrations/20260713120000_reconcile_instagram_gallery_unification.sql
- supabase/migrations/20260714200000_architecture_one_to_staple.sql
- supabase/migrations/20260714210000_drop_effect_surface.sql
- supabase/migrations/20260714220000_add_aesthetic_axes.sql
- supabase/migrations/20260714230000_drop_glass_satellites.sql
- supabase/migrations/20260715090000_menu_item_currency_and_dining_modes.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

## P3 — Nice to have

- [ ] **PRIV-103** · P3 — Internal cleanup command writes user handles to log/console output unnecessarily
    - **Where:** app/Console/Commands/CleanupOrphanedLifestyleConnections.php:51-57
    - **Affects:** Users whose account has an orphaned lifestyle-integration connection cleaned up by this one-shot remediation command — their handle (an indirect identifier) is written to console output and, depending on the deployment's stdout capture, to Laravel Cloud log storage.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$user->handle ?? '(no handle)'` with `$user->id` in the `$this->line()` call — the UUID alone is sufficient for operator traceability.
    - **Technical:** The command's per-user progress line interpolates `$user->handle` directly (`'%s %s (%s): %d connection(s)'` with handle as the second `%s`), in addition to already including `$user->id`. The handle adds no diagnostic value beyond the UUID and unnecessarily widens the PII surface of console/log output for what the class docblock itself describes as a "one-shot data remediation" tool. This is a minor, low-traffic finding — the command is not scheduled in `routes/console.php` (confirmed: no reference found there), so it only runs on manual invocation, and `$user->handle` is already a public-facing identifier (it's literally the subdomain), not a sensitive one. The fix is a one-line swap.
    - **Plain English:** When this cleanup tool runs, it prints out affected users' public usernames into its output, which can end up in system logs. Anyone who later pulls those logs — for a security review or an audit — gets a needlessly detailed list tying usernames to a specific internal operation. The user's ID number alone tells the operator the same story without adding an extra personal-identifier trail to log storage.
    - **Evidence:**
        ```php
        $this->line(sprintf(
            '%s %s (%s): %d connection(s)'
            $dryRun ? 'would remove' : 'removed'
            $user->handle ?? '(no handle)'
            $user->id
            $count
        ));
        ```

## Suggested Bundled Sessions

    - **Why grouped:** both live in `App\Services\User` and touch the same account lifecycle (signup / deletion) that the professional's PII flows through; small, independent fixes that don't interact with each other.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** standalone one-line fix in a console command, unrelated subsystem to Bundle 1 — kept separate so it doesn't block on the signup-consent design discussion.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (combine plan+implement given trivial size).

## Standalone — do NOT bundle

None — no finding in this audit is P0, touches auth/authorization or money, involves a DB migration/schema change, or is L/XL effort.


<!-- ═══════════ audit-2026-07-17-edge-worker.md ═══════════ -->

# Edge Worker Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Edge worker: Cloudflare routing, KV contract, edge-cache correctness, takedown latency, poisoning
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- cloudflare-worker/src/index.js
- cloudflare-worker/wrangler.toml
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Moderation/SuspendSiteJob.php
- app/Jobs/Moderation/PurgeModerationCacheJob.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/Services/Cloudflare/CloudflareCustomHostnameService.php
- app/Services/Cloudflare/CloudflareKvService.php
- app/Services/Moderation/ModerationActionDispatcher.php
- app/Observers/User/UserObserver.php
- app/Observers/Core/SiteObserver.php
- app/Services/User/AccountDeletionService.php
- app/Services/Site/RenameSubdomainAction.php
- app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php
- config/partna.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## Note on this adjudication

DeepSeek's draft was scanned against only three files (`SyncSubdomainToKvJob.php`, `CloudflareCustomHostnameService.php`, `CloudflarePurgeService.php`) — it did not have `cloudflare-worker/src/index.js`, the model observers, `AccountDeletionService`, or the moderation dispatcher in scope, and its own EDGE-7 draft admits this outright. With the full picture, five of its seven findings (its EDGE-101 through EDGE-6, minus EDGE-105) turned out to be **false premises**: the takedown/purge chain it says is missing already exists, just in files it didn't see —

- `SiteObserver::saved()`/`::deleted()` dispatch `CloudflareCachePurgeJob` (with custom domain) on every site save/delete/unpublish, including the account-deletion `is_published=false` transition in `AccountDeletionService::executeConfirmation()`.
- Moderation takedowns (`hide_site`, `suspend_user`, `ban_user`, `csam_auto_suspend`) all pair `suspend_site` with `sync_subdomain_kv` in `ModerationActionDispatcher::ACTIONS_BY_DECISION`, and `sync_subdomain_kv` maps to `PurgeModerationCacheJob`, which retires the KV entry **and** unconditionally dispatches `CloudflareCachePurgeJob` — confirmed by `ModerationActionDispatcherTest`.
- The Worker's alias-redirect branch already validates `entry.redirect` is an `https://*.partna.au` URL before trusting it (comment cites `SEC-105`), failing closed to a 404 otherwise — the "poisoned KV → open redirect" claim doesn't hold against the current source.
- The Worker's `RESERVED` set and `config('partna.reserved_subdomains')` are byte-for-byte identical (diffed both files in full) — no drift.
- The Worker's KV-type check runs **before** any cache lookup, so once a renamed handle's KV entry flips to `{type:"alias"}`, its old cache entries become structurally unreachable — there's no window where stale content under the old handle is served to a visitor (rename correctness holds; only a sub-second-to-KV-propagation race exists, and it would serve identical content from the same owner, not stale/wrong content).

These are dropped below rather than re-tiered, since the underlying claim — not just the fix — is what's wrong. Two of DeepSeek's findings (custom-hostname delete, product-purge cap) verified true and are kept. Three genuinely new findings the draft's narrow scope missed are added.

## P2 — Should fix

## P3 — Nice to have

- [ ] **#EDGE-104** · P3 — `cloudflare-worker` router has zero automated test coverage
    - **Where:** cloudflare-worker/ (no test directory alongside `src/index.js`, `wrangler.toml`, `package.json`)
    - **Affects:** Every future change to `index.js` — routing, cache-key logic, alias-redirect validation, security headers — ships with no regression check.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a minimal Miniflare/Vitest smoke suite covering: reserved-subdomain passthrough, KV-miss branded-404, alias redirect (valid target + poisoned-target-rejected-to-404), individual-entry cache hit/miss/stale-shadow paths, and the HTTP→HTTPS redirect.
        - Wire it into CI alongside `composer test` so a Worker change can't silently regress protections that took multiple prior audit rounds to land (the file's own comments cite `EDGE-101/7/8/9/12/13, SEC-105` as previously-fixed findings).
    - **Technical:** `cloudflare-worker/` contains only `src/index.js`, `wrangler.toml`, `package.json`/`package-lock.json`, and a README — no test directory, no Miniflare/Vitest harness. This is the only non-PHP runtime in the repo and fronts 100% of public sitepage traffic, including several security-sensitive branches (alias-redirect host validation, Set-Cookie stripping, query-string-stripped cache keys) that this and prior audit rounds have had to re-verify by hand-reading the source each time. A regression in any of these ships with zero CI signal.
    - **Plain English:** This is the one piece of the whole backend written in a different programming language, and it decides how every visitor's request gets routed and cached — yet nothing automatically re-checks it when it changes. Every other part of the codebase has an automated test suite that runs before code ships; this critical piece relies entirely on a human re-reading the whole file during periodic audits. A small automated test suite would catch an accidental regression immediately instead of it needing to be rediscovered by hand at the next audit.
    - **Evidence:**
        ```
        cloudflare-worker/.gitignore
        cloudflare-worker/README.md
        cloudflare-worker/package-lock.json
        cloudflare-worker/package.json
        cloudflare-worker/src/index.js
        cloudflare-worker/wrangler.toml
        ```

- [ ] **#EDGE-105** · P3 — Product detail page purge is capped at 100 products with no visibility when the cap is hit
    - **Where:** app/Services/Cloudflare/CloudflarePurgeService.php:132-146
    - **Affects:** Individual professionals with more than 100 shop products connected via a platform integration — product pages 101+ stay edge-cached for up to 24h primary / 7d shadow after any purge-triggering mutation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Raise the limit, or at minimum `Log::warning` when the returned row count equals the limit, so it's visible before a real professional's catalog silently exceeds it.
    - **Technical:** The product-handle query added by the recent product-detail-page purge fix (`7c753f7f fix(cache): purgeHandle also purges shop product detail pages`) caps at `->limit(100)` as a safety bound. For the current individual-only, pre-beta platform, 100+ products is very unlikely, but there is no signal today if it's ever exceeded.
    - **Plain English:** The system clears cached copies of a store's product pages when the site updates, but it only looks at the first 100 products. For a typical individual professional's catalog this is fine, but if someone ever has more, the rest would show stale info for up to a day with nobody told it happened.
    - **Evidence:**
        ```php
        $productHandles = DB::connection('pgsql')->table('site.shop_products as p')
            ->join('site.shop_brands as b', 'b.id', '=', 'p.brand_id')
            ->join('site.platform_connections as c', 'c.id', '=', 'b.connection_id')
            ->join('core.users as u', 'u.id', '=', 'c.user_id')
            ->where('u.handle_lc', $h)
            ->whereNull('c.deleted_at')
            ->whereRaw("p.data->>'handle' IS NOT NULL")
            ->selectRaw("DISTINCT p.data->>'handle' AS product_handle")
            ->limit(100)
            ->pluck('product_handle')
            ->all();
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Cloudflare service hygiene (PHP):** , #EDGE-105
    - **Why grouped:** both are small, same-directory (`app/Services/Cloudflare/`) one-liners with no cross-file risk.
    - **Model:** Plan+Implement: Sonnet (S/S effort, combine per policy) · Review: Sonnet.

- **Bundle 2 — Cloudflare Worker repo hygiene (deploy config + tests):** , #EDGE-104
    - **Why grouped:** both are `cloudflare-worker/` repo-hygiene items (deploy config completeness, test scaffolding) rather than application-logic bugs.
    - **Model:** Plan: Opus (EDGE-104 needs a scaffolding decision) · Implement: Sonnet · Review: Sonnet.

    - **Why grouped:** standalone-scoped but S-effort — a single new architecture test, no production code change.
    - **Model:** Plan+Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-17-configuration-hygiene.md ═══════════ -->

# Configuration Hygiene Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Configuration Hygiene — `env()` calls outside config files, missing `.env.example` entries for active config keys, feature flags without safe defaults, and config values used inconsistently (hardcoded in some places, config-driven in others).
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- routes/api/platforms.php
- app/Console/Commands/CleanupOrphanedLifestyleConnections.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Services/Cloudflare/CloudflareCustomHostnameService.php
- app/Services/Cloudflare/CloudflarePurgeService.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 2 complete

---

## P3 — Nice to have

- [ ] **CFG-101** · P3 — Hardcoded HTTP timeouts in `CloudflareCustomHostnameService`
    - **Where:** app/Services/Cloudflare/CloudflareCustomHostnameService.php:56,82,97
    - **Affects:** Operators tuning Cloudflare "Custom Hostnames" API call behavior; any environment where Cloudflare's control plane is slower than the hardcoded budget.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the 10s timeout (`create()`/`get()`) and 5s timeout (`delete()`) into `config('services.cloudflare.custom_hostname_timeout', 10)` / `config('services.cloudflare.custom_hostname_delete_timeout', 5)`.
        - Add corresponding `.env.example` entries with comments, mirroring the existing `CLOUDFLARE_*` block.
    - **Technical:** Category 4 — magic numbers in a service class. `Http::withToken(...)->timeout(10)` / `->timeout(5)` are hardcoded literals. Note this mirrors `SupabaseAdminService`'s own hardcoded `->timeout(10)` (confirmed via grep) — hardcoding a short internal-API timeout is the dominant, apparently deliberate pattern across this codebase's thin API wrappers (`GoogleBusinessApifyScraper`, `InstagramScraper`, `FreshaScraper` all hardcode theirs too), so this is genuinely low-risk polish rather than an isolated oversight. Still, a small number of services (`SafeUrlFetcher`, `LogoProcessorClient`, `BrandScanClient`) do pull timeouts from config, so there's an established config-driven pattern this file could adopt for consistency.
    - **Plain English:** This is like a phone system that hangs up on the other party after a fixed number of seconds, with that number welded into the wiring instead of printed on a settings dial. Most of the time 10 seconds is fine, but if Cloudflare's systems ever run slow, someone has to edit and re-ship the code to wait longer instead of just changing a setting.
    - **Evidence:**
        ```php
        // create() — 10s timeout
        $result = Http::withToken($this->apiToken)
            ->timeout(10)
            ->asJson()
            ->post($this->base(), [

        // get() — 10s timeout
        $result = Http::withToken($this->apiToken)
            ->timeout(10)
            ->get($this->base()."/{$id}")

        // delete() — 5s timeout
        Http::withToken($this->apiToken)->timeout(5)->delete($this->base()."/{$id}");
        ```

- [ ] **CFG-102** · P3 — Hardcoded fetch timeouts and media size caps in `InstagramConnectJob`
    - **Where:** app/Jobs/Platforms/InstagramConnectJob.php:73,76,81,87
    - **Affects:** Operators managing Instagram media-mirroring behavior; environments where CDN latency or media sizes differ from today's assumptions.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `IMAGE_TIMEOUT_SECONDS`, `VIDEO_TIMEOUT_SECONDS`, `MAX_VIDEO_BYTES`, and `MAX_IMAGE_BYTES` to `config('partna.platforms.instagram.*')`.
        - Add matching `.env.example` entries with the current values as defaults and a one-line comment on each limit's purpose.
    - **Technical:** Category 4 — outbound fetch timeouts and per-request size caps hardcoded as private class constants (10s images / 30s video / 50MB video cap / 15MB image cap). Same root-cause pattern as CFG-101 (hardcoded operational limits in an outbound-HTTP code path) — tier consistency applies. Unlike CFG-101's short control-plane calls, these gate a CDN media mirror with real SSRF/size-abuse defenses already layered on (host allowlist, `SafeUrlFetcher::assertSafe`, no-redirect, content-type check, byte cap) — the values themselves are sound, just not adjustable without a deploy.
    - **Plain English:** These are safety rails for downloading a profile's Instagram photos and videos — how long to wait, and how large a file to accept before giving up. They're reasonable numbers today, but they're baked directly into the code rather than kept in a settings file, so if Instagram's file sizes or CDN speed change, a developer has to edit and redeploy code instead of flipping a setting.
    - **Evidence:**
        ```php
        private const IMAGE_TIMEOUT_SECONDS = 10;
        private const VIDEO_TIMEOUT_SECONDS = 30;
        private const MAX_VIDEO_BYTES = 52428800;
        private const MAX_IMAGE_BYTES = 15728640;
        ```

## Suggested Bundled Sessions

    - **Why grouped:** Same root-cause pattern (hardcoded timeout/size-cap literals in outbound-HTTP-calling code) and the same mechanical fix shape (extract to `config('partna.*')` or `config('services.*')` + `.env.example` entries). Both are S-effort and independent of each other.
    - **Model:** Plan Opus · Implement Sonnet · Review Sonnet — no escalation needed, both are small mechanical extractions.

## Standalone — do NOT bundle

None — neither finding touches auth/authorization, money, or a DB migration/schema change, and both are S-effort.


<!-- ═══════════ audit-2026-07-17-migration-safety.md ═══════════ -->

# Migration Safety Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Migration safety: lock-on-deploy risk, backfill ordering, online DDL hygiene, reversibility
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- supabase/migrations/20260711170000_users_email_unique_case_insensitive.sql
- supabase/migrations/20260712000000_retire_staff_account_type.sql
- supabase/migrations/20260713120000_reconcile_instagram_gallery_unification.sql
- supabase/migrations/20260714200000_architecture_one_to_staple.sql
- supabase/migrations/20260714210000_drop_effect_surface.sql
- supabase/migrations/20260714220000_add_aesthetic_axes.sql
- supabase/migrations/20260714230000_drop_glass_satellites.sql
- supabase/migrations/20260715090000_menu_item_currency_and_dining_modes.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

## P3 — Nice to have

- [ ] **MIG-104** · P3 — `ADD CONSTRAINT CHECK` without `NOT VALID` on `site.sites`, already compensated by a documented guard exemption
    - **Where:** supabase/migrations/20260714200000_architecture_one_to_staple.sql:15-34
    - **Affects:** `site.sites` — informational/tracking only; the file's own reasoning already accounts for the lock.
    - **Effort:** S (no code change required now)
    - **What to do:**
        - No fix required today. The file already carries `-- guard:no-unsafe-migrations:disable-file` with a specific, checkable justification: `site.sites` has 10 rows total in dev (a complete census, matching the `site.workplaces` precedent scale), and the preceding `UPDATE site.sites SET architecture_id = 'staple' WHERE architecture_id IS DISTINCT FROM 'staple'` normalizes every row first, so the later `VALIDATE`-equivalent scan can never fail.
        - Re-open only if `site.sites` grows materially before this constraint is touched again — at that point use the `NOT VALID` → `VALIDATE CONSTRAINT` split (`CONVENTIONS.md` §2) instead of the exemption.
    - **Technical:** `scripts/guard-no-unsafe-migrations.php` (Master Pattern 20) exists precisely to catch `ADD CONSTRAINT ... CHECK` without `NOT VALID`; this file opts out via the documented `disable-file` escape hatch with an explicit, checkable justification, following the exact same precedent as `20260612100000_add_custom_platform_and_sync_check.sql` and `20260629120000_drop_platform_connections_check.sql` (both cited in this file's own comment). A prior adjudicated audit (`audits/sweeps/2026-07-08-full-sweep/audit-2026-07-09-migration-safety.md`, finding MIG-105) reviewed the identical pattern on `site.platform_connections` and rated it P3 "compensated by an explicit guard exemption + justification... no fix required now" — this is the same root cause on a different table and should carry the same tier for consistency. The file's `DROP CONSTRAINT` → `UPDATE` → `ALTER COLUMN SET DEFAULT` → `ADD CONSTRAINT` sequence also technically fits category (6)'s "multi-statement hazard," but at 10 rows the cumulative lock time is immaterial and covered by the same exemption reasoning.
    - **Plain English:** This is like putting up a "new items must follow this rule" sign and auditing existing stock later, except here the shop owner checked the shelf first, confirmed there's only a handful of items, and already tidied them to match the new rule before applying it — so the quick inspection that follows genuinely can't turn up a problem. Nothing to fix now; just keep an eye on it if the shop's inventory ever grows before this rule changes again.
    - **Evidence:**
        ```sql
        -- guard:no-unsafe-migrations:disable-file

        ALTER TABLE site.sites DROP CONSTRAINT IF EXISTS sites_architecture_id_check;

        UPDATE site.sites
        SET architecture_id = 'staple'
        WHERE architecture_id IS DISTINCT FROM 'staple';

        ALTER TABLE site.sites ALTER COLUMN architecture_id SET DEFAULT 'staple';

        ALTER TABLE site.sites
            ADD CONSTRAINT sites_architecture_id_check CHECK (architecture_id = 'staple');
        ```

- [ ] **MIG-105** · P3 — `DROP COLUMN` migrations on `site.design_kits` carry no "to revert:" note (repo-wide convention gap, not a regression)
    - **Where:** supabase/migrations/20260714210000_drop_effect_surface.sql:13; supabase/migrations/20260714230000_drop_glass_satellites.sql:14-17
    - **Affects:** `site.design_kits` — if a rollback were ever needed mid-incident, there's no comment documenting the `ADD COLUMN` needed to restore storage (data itself is unrecoverable regardless).
    - **Effort:** S (~0.5h, going forward only)
    - **What to do:**
        - Adopt a one-line "to revert:" comment on future `DROP COLUMN` migrations for design-kit vars, e.g. `-- to revert: ALTER TABLE site.design_kits ADD COLUMN effect_surface TEXT NULL; (restores storage only — values are unrecoverable)`.
        - Don't retroactively edit these two files or the ~13 earlier `site.design_kits` column drops (`20260603000001_drop_orphan_design_kit_typography_cols.sql`, `20260603000004_drop_design_kit_sizing_tablet_header_height.sql`, `20260528090000_drop_design_kit_row_height.sql`, `20260528030000_drop_design_kit_bg_image.sql`, and others) — none of them carry this comment either, so singling out just these two would be inconsistent, and editing an already-applied migration's SQL is a no-op on `development` per `docs/migration-guidelines.md`.
    - **Technical:** Confirmed via grep that `app/Services/Design/Presets/PresetTargetableColumns.php`, `PlatformMixFactor.php`, and `app/Http/Requests/Concerns/DesignKitValidationRules.php` reference `effect_surface` / `effect_scrim_blur` / `effect_glass_blur` / `motion_glass_shine_duration` only inside comments documenting the 2026-07-10/07-15 retirement — no live code path reads or writes any of these columns, so there is no cross-file invariant at risk and no functional data-loss exposure today. `DROP COLUMN IF EXISTS` is a catalog-only operation in Postgres (no table rewrite), so the lock-duration risk is negligible regardless. The gap is purely documentation, and it is the established (if imperfect) norm for this table's entire column-churn history, not something unique to these two files — hence P3, not P1.
    - **Plain English:** This is like clearing out a filing cabinet drawer that's confirmed empty and unused, but not leaving a sticky note on the cabinet saying what used to be there. Nobody needs that data back — the app already stopped reading it days before these files ran — but it's a cheap habit to build for next time, in case a future column drop isn't quite as clean-cut as this one.
    - **Evidence:**
        ```sql
        ALTER TABLE site.design_kits DROP COLUMN IF EXISTS effect_surface;
        ```
        ```sql
        ALTER TABLE site.design_kits
            DROP COLUMN IF EXISTS effect_scrim_blur
            DROP COLUMN IF EXISTS effect_glass_blur
            DROP COLUMN IF EXISTS motion_glass_shine_duration;
        ```

## Suggested Bundled Sessions

None. Every finding above edits a `supabase/migrations/*.sql` file — per the fix-flow's own rule, any item touching a DB migration/schema change always runs standalone with its own plan + sign-off, never bundled with another finding.

## Standalone — do NOT bundle

- **MIG-104 — `site.sites` CHECK exemption (informational)** · DB migration/schema change; no action required, but any future edit to this file's constraint logic is standalone by rule.
- **MIG-105 — Rollback-comment convention for `site.design_kits` drops** · DB migration/schema change.


<!-- ═══════════ audit-2026-07-17-api-contract.md ═══════════ -->

# API Contract & Resource Leakage Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** API Contract & Resource Leakage — raw model fields bleeding through, over-fetching, inconsistent pagination
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Controllers/Api/User/Account/UserAccountDeletionController.php
- app/Http/Controllers/Api/User/Account/UserSelfController.php
- app/Http/Controllers/Api/User/Profile/SectorController.php
- app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php
- app/Http/Resources/PublicSite/IndividualProfileResource.php
- app/Http/Resources/UserDashboardResource.php
- app/Http/Controllers/Api/Platforms/BookingController.php
- app/Http/Controllers/Api/Platforms/DisplaySettingsController.php
- app/Http/Controllers/Api/Platforms/FreshaController.php
- app/Http/Controllers/Api/Platforms/GoogleBusinessController.php
- app/Http/Controllers/Api/Platforms/InstagramController.php
- app/Http/Controllers/Api/Platforms/MenuController.php
- app/Http/Controllers/Api/Platforms/OnlineOrderingController.php
- app/Http/Controllers/Api/Platforms/ReservationsController.php
- app/Http/Controllers/Api/Platforms/SquareController.php
- app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php
- app/Http/Controllers/Api/PublicSite/PublicMenuController.php
- app/Http/Controllers/Api/Staff/Analytics/StaffAggregateAnalyticsController.php
- app/Http/Controllers/Api/Staff/Feedback/StaffFeedbackController.php
- app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 3 complete

---

## P1 — Fix before pilot launch

## P3 — Nice to have

- [ ] **#API-102** · P3 — `StaffUserController::show()` mixes a Resource class and hand-built arrays in one response body
    - **Where:** app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php:96-138
    - **Affects:** Staff dashboard consumers of `GET /api/staff/professionals/{professional}` — the `integrations` and `design_summary` keys bypass the Resource layer entirely.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the `integrations` grouping/mapping into a dedicated `StaffIntegrationSummaryResource`.
        - Extract `design_summary` into a `StaffDesignSummaryResource` wrapping `$designKitVars`.
    - **Technical:** `professional` goes through `UserStaffResource`, but `integrations` is built via `$professional->integrationConnections()->get(...)->groupBy(...)->map(...)` and `design_summary` is a raw associative array built inline from `$designKitVars`. This is staff-only data with no PII exposure risk beyond what `UserStaffResource` already carries — the issue is purely maintainability: a future column rename or removal has to be hunted down in controller code instead of one auditable Resource class.
    - **Plain English:** This staff page returns a professional's info like a neatly packaged box, but two of the sections (their connected integrations and their site's design summary) are loose items tossed in without the same packaging. If the underlying data changes later, someone has to hunt through controller code instead of updating one file.
    - **Evidence:**
        ```php
        $integrations = $professional->integrationConnections()
            ->orderBy('platform')
            ->get(['id', 'platform', 'is_active', 'last_refreshed_at', 'last_refresh_status'])
            ->groupBy('platform')
            ->map(fn ($group, $platform) => [
                'platform' => (string) $platform
                'connection_count' => $group->count()
                'is_active' => $group->contains(fn ($row) => (bool) $row->is_active)
                'last_refreshed_at' => $group->pluck('last_refreshed_at')->max()
                'has_refresh_error' => $group->contains(fn ($row) => $row->last_refresh_status === 'error')
            ])
            ->values();
        ```
        ```php
        'design_summary' => $professional->site ? [
            'architecture_id' => $professional->site->architecture_id
            'stored_var_count' => count($designKitVars)
            'theme_mode' => $designKitVars['theme_mode'] ?? null
            'surface_type' => $designKitVars['surface_type'] ?? null
            'font_heading' => $designKitVars['font_heading'] ?? null
            'font_body' => $designKitVars['font_body'] ?? null
            'accent_color' => $designKitVars['accent_color'] ?? null
            'design_kit' => $designKitVars
        ] : null
        ```

- [ ] **#API-103** · P3 — `PublicMenuController::show()` builds the public menu payload without a Resource class
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicMenuController.php:75-131
    - **Affects:** Unauthenticated public sitepage visitors — the menu payload has no allowlisting guardrail between the Eloquent models and the wire.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `PublicMenuItemResource` / `PublicMenuCategoryResource` wrapping the Eloquent models with an explicit field list.
        - Replace the manual `$menu->categories->map(...)` chain with `PublicMenuCategoryResource::collection($menu->categories)->resolve()`.
    - **Technical:** The response is built entirely by hand (`$item->name`, `$item->description`, `$item->platformLinks->map(...)`, etc.) with no Resource class between the models and the JSON. Every field currently exposed is appropriate for a public visitor, so there is no active leak today — but this is the one endpoint in the file list that is both unauthenticated and has no Resource-layer allowlist, so a future column added to `menu_items` (an internal cost field, a moderation flag) would reach the public wire silently instead of requiring an explicit opt-in.
    - **Plain English:** This is the public menu page — anyone on the internet can see it. The response is built by hand, picking fields one by one. That's fine today, but if someone adds a new internal-only column to the menu table later, nothing stops it from silently showing up here. A Resource class acts like a bouncer at the door — only fields explicitly listed get through, on purpose, every time.
    - **Evidence:**
        ```php
        $categories = $menu->categories
            ->map(fn ($cat) => [
                'name' => $cat->name
                'popularityRank' => $categoryRanks[(string) $cat->id] ?? null
                'items' => $cat->items->map(fn ($item) => [
                    'id' => (string) $item->id
                    'name' => $item->name
                    'description' => $item->description
                    'imageUrl' => $item->image_url
                    'price' => $item->base_price !== null
                        ? number_format((float) $item->base_price, 2)
                        : null
                    'platforms' => $item->platformLinks->map(fn (MenuItemPlatform $p) => [
                        'platform' => $p->platform
                        'pickupUrl' => $this->textOrNull($p->pickup_url)
                        'deliveryUrl' => $this->textOrNull($p->delivery_url)
                        'pickupPrice' => $this->numberOrNull($p->pickup_price)
                        'deliveryPrice' => $this->numberOrNull($p->delivery_price)
                    ])->values()->toArray()
                    'popularityRank' => $itemRanks[(string) $item->id] ?? null
                ])->values()->toArray()
            ])
            ->filter(fn ($cat) => count($cat['items']) > 0)
            ->values()
            ->toArray();
        ```

- [ ] **#API-104** · P3 — `MenuController::show()` builds the authenticated dashboard menu payload by hand, duplicating `PublicMenuController`'s shaping logic under different field names
    - **Where:** app/Http/Controllers/Api/Platforms/MenuController.php:171-222
    - **Affects:** Authenticated dashboard users — the same underlying menu data is manually re-shaped here with different key names (`image` vs `imageUrl`, `basePrice` unformatted vs `price` formatted) than the public surface.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract the category/item shaping in `categories()`/`platforms()` into `MenuItemResource`/`MenuCategoryResource`.
        - Share these Resource classes with `PublicMenuController` (#API-103), using `$this->when(...)` to gate any dashboard-only fields, so both surfaces read from one auditable transformation path instead of two hand-maintained ones.
    - **Technical:** `categories()` and `platforms()` manually iterate the same `MenuItem`/`MenuItemPlatform` relations `PublicMenuController` iterates, but rename fields differently (`image` vs `imageUrl`) and skip the 2dp price formatting the public surface applies. Any future field addition or rename has to be made correctly in both places or the two surfaces drift further apart — this is the same root cause as #API-103 and should be fixed in the same session.
    - **Plain English:** The menu data is shaped by hand in two different files — one for the public page, one for the owner's dashboard. They do almost the same job but with different field names in each. If a new menu field gets added later, someone has to remember to update both places identically. Sharing one Resource class with a simple on/off switch for dashboard-only fields fixes that.
    - **Evidence:**
        ```php
        private function categories(?Menu $menu): array
        {
            if ($menu === null) {
                return [];
            }

            return $menu->categories->map(fn ($category) => [
                'name' => $category->name
                'items' => $category->items->map(fn (MenuItem $item) => [
                    'id' => (string) $item->id
                    'name' => $item->name
                    'description' => $item->description
                    'image' => $item->image_url
                    'rating' => $item->rating
                    'ratingCount' => $item->rating_count
                    'badges' => $item->badges
                    'basePrice' => $item->base_price
                    'pickupPrice' => $item->pickup_price
                    'pickupSource' => $item->pickup_source
                    'deliveryPrice' => $item->delivery_price
                    'deliverySource' => $item->delivery_source
                    'currency' => $item->currency
                    'platforms' => $this->platforms($item)
                ])->all()
            ])->all();
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Menu Resource extraction:** #API-103, #API-104
    - **Why grouped:** Same root cause (no Resource class for menu shaping) across the public and dashboard surfaces, with the same duplicated-field-mapping symptom — fix in one session so both surfaces land on shared Resource classes together.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Staff detail response cleanup:** #API-102
    - **Why grouped:** Single isolated controller, no dependency on the menu work.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle



<!-- ═══════════ audit-2026-07-17-test-coverage.md ═══════════ -->

# Test Coverage Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Policies/*.php` (all 18 policy classes, cross-checked against `tests/Unit/Policies/`, `tests/Feature/Policies/`, `tests/Feature/Security/PolicyEnforcement/`)
- `tests/Pest.php`
- `tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php`, `tests/Feature/PublicSite/*.php`, `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php`
- `tests/Feature/Staff/StaffUserSearchFiltersTest.php`, `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php`
- `tests/Feature/Site/CustomDomainTest.php`, `tests/Feature/Database/CheckConstraintsTest.php`, `tests/Feature/Database/IndexCoverageTest.php`
- `tests/Feature/Media/DesignSingletonMediaTest.php`, `tests/Feature/Api/User/SiteManagement/WriteDesignKitConcurrencyTest.php`
- `tests/Feature/Accounts/LifestyleConnectionCleanupTest.php`, `app/Observers/User/UserObserver.php`
- `tests/Feature/Platforms/ReservationProvidersTest.php`, `tests/Feature/Platforms/MenuTest.php`, `tests/Unit/Jobs/EnrichLinkCardJobTest.php`
- `tests/Unit/Jobs/SyncSubdomainToKvJobTest.php`, `tests/Unit/Analytics/RecordAnalyticsEventJobTest.php`, `tests/Feature/Moderation/StaffCase*.php`
- `tests/Feature/Bootstrap/SiteProvisioningSavepointTest.php`, `tests/Unit/Http/SafeUrlFetcherTest.php`
- Remaining scope files from the original draft bundle (`tests/Feature/Api/**`, `tests/Feature/Account*/**`, `tests/Feature/Design/**`, `tests/Feature/Platforms/**`, `tests/Unit/**` per the source list) — cross-checked via repo-wide `Glob`/`Grep` rather than trusted at face value

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 7 complete
- P3 Low: 0 of 3 complete

---

## P2 — Should fix

## P3 — Nice to have

- [ ] **#TEST-108** · P3 — Custom-domain race-condition test's ad-hoc unique index isn't cross-checked against the real migration
    - **Where:** `tests/Feature/Site/CustomDomainTest.php:143-146`; no matching assertion in `tests/Feature/Database/CheckConstraintsTest.php` or `IndexCoverageTest.php`.
    - **Affects:** Confidence that the TOCTOU race test (`LIFE-105`) is actually exercising the same constraint shape that exists in production.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a grep-based invariant assertion (matching the `CheckConstraintsTest.php`/`WriteDesignKitTest.php` pattern) confirming the real migration defines a unique index on `lower(custom_domain) WHERE custom_domain IS NOT NULL`.
    - **Technical:** The race test creates its own `CREATE UNIQUE INDEX IF NOT EXISTS` statement at test time to simulate the production constraint that triggers the `UniqueConstraintViolationException` path. `CheckConstraintsTest.php` and `IndexCoverageTest.php` (the project's canonical schema-invariant sweeps) contain no reference to `custom_domain`, so nothing guarantees the ad-hoc test index still matches the real migration if either drifts.
    - **Plain English:** This test rehearses a race condition using a copy of the database rule that prevents duplicate custom domains — but it's a hand-built copy, not a check against the real rule in the migration files. The behavior itself is well tested; we just don't have a tripwire that would catch the two definitions drifting apart.
    - **Evidence:**
        ```php
        DB::connection('pgsql')->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS site.sites_custom_domain_unique '.
            'ON sites (lower(custom_domain)) WHERE custom_domain IS NOT NULL'
        );
        ```

- [ ] **#TEST-109** · P3 — No forget/disconnect test for the `nowbookit` reservation provider
    - **Where:** `tests/Feature/Platforms/ReservationProvidersTest.php:135-141` has the pattern for `resdiary`; no equivalent exists for `nowbookit` despite `nowbookit` having full connect/detect/selection coverage elsewhere in the same file.
    - **Affects:** Users who connect NowBookit and later remove it via the provider-agnostic forget endpoint.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('clears a nowbookit reservation via reservations forget (single-slot)')` mirroring the existing resdiary test exactly, swapping the connect call and platform string.
    - **Technical:** The single-slot forget route (`DELETE /api/platforms/reservations`) is provider-agnostic, and `resdiary` already has a dedicated forget test proving the pattern. `nowbookit` is otherwise the most heavily tested reservation provider in this file (connect, detect-routing, selection read-back, 5-key shape) but has no forget test — a one-line gap given the exemplar already exists two providers over.
    - **Plain English:** The reservations page has a "remove" button. We've tested it works for one booking provider (ResDiary) but not for another (NowBookit), even though we thoroughly test everything else about NowBookit. It's a five-minute copy-paste of a test we've already written.
    - **Evidence:**
        ```php
        it('clears a resdiary reservation via reservations forget (single-slot)', function () {
            $user = resUser('rp4');
            actingAsUser($user)->postJson('/api/platforms/resdiary/connect', ['url' => 'https://booking.resdiary.com/widget/Standard/Ollies'])->assertOk();
            actingAsUser($user)->deleteJson('/api/platforms/reservations')->assertOk()->assertJsonPath('connected', false);
            expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'resdiary')->exists())->toBeFalse();
        });
        // No analogous test for nowbookit exists in this file.
        ```

- [ ] **#TEST-110** · P3 — Custom domain connect test doesn't verify the KV sync job carries the right data
    - **Where:** `tests/Feature/Site/CustomDomainTest.php:58` (connect test); compare the disconnect test at line 112 in the same file, which already uses the correct pattern.
    - **Affects:** Confidence that connecting a custom domain queues a KV sync for the *correct* user/site, not just "a" `SyncSubdomainToKvJob`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a callback to the `Queue::assertPushed` call on the connect test verifying the dispatched job targets the acting user's site, mirroring the callback already used in the disconnect test on the very next test in the same file.
    - **Technical:** `Queue::assertPushed(SyncSubdomainToKvJob::class)` with no callback only proves the job class was dispatched, not that it carries the right identifying data. The disconnect test two tests later in the same file already demonstrates the correct pattern (`fn ($job) => $job->retireCustomDomain === 'bookwith.me'`), so this is a one-line fix that brings the connect test up to the standard the file already sets for itself.
    - **Plain English:** This is like confirming a package was shipped without checking the address label. The test proves a sync job was queued after connecting a domain, but not that it's queued for the right user — even though the very next test in the same file already checks the label correctly.
    - **Evidence:**
        ```php
        // Connect test — asserts class only, no payload verification:
        Queue::assertPushed(SyncSubdomainToKvJob::class);

        // Disconnect test in the same file — correctly verifies payload:
        Queue::assertPushed(SyncSubdomainToKvJob::class, fn ($job) => $job->retireCustomDomain === 'bookwith.me');
        ```

## Suggested Bundled Sessions

    - **Why grouped:** Same file (`StaffUserSearchFiltersTest.php`); fixing the HTTP-bypass issue naturally requires rewriting the tests as real HTTP calls, which is also the vehicle for adding the Postgres-only `q` coverage.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Custom domain test hardening:** , #TEST-108, #TEST-110
    - **Why grouped:** All three live in `tests/Feature/Site/CustomDomainTest.php` and touch the same custom-domain subsystem; a single session can add the Cloudflare-failure test, the KV-payload callback, and the migration cross-check together without re-loading context.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#TEST-109 — No forget test for `nowbookit`** · isolated (no shared file with other bundles); trivial but standalone since it has no natural bundle partner.


<!-- ═══════════ audit-2026-07-17-code-quality-slop.md ═══════════ -->

# AI Slop & Low-Value Code Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** AI Slop & Low-Value Code — comment noise, premature abstraction, dead code, defensive cruft, copy-paste drift (house style = `CLAUDE.md` Commenting / Simplicity-first / Do-NOT-over-engineer rules)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Mail/Branding/EmailBrandDefaults.php`
- `app/Services/Platforms/BigCartelScraper.php`
- `app/Services/Platforms/DoorDashMenuDriver.php`
- `app/Services/Platforms/GenericShopScraper.php`
- `app/Services/Platforms/GoogleBusinessAutoSync.php`
- `app/Services/Platforms/IdentitySync.php`
- `app/Services/Platforms/InstagramAutoSync.php`
- `app/Services/Platforms/InstagramScraper.php`
- `app/Services/Platforms/MenuMerger.php`
- `app/Services/Platforms/MenuScanApplier.php`
- `app/Services/Platforms/Normalizers/FacebookNormalizer.php`
- `app/Services/Platforms/Payloads/InstagramPayload.php`
- `app/Services/Platforms/PlatformScraper.php`
- `app/Services/Platforms/Registry/PlatformDescriptor.php`
- `app/Services/Platforms/ShopifyScraper.php`
- `app/Services/Platforms/UberEatsMenuDriver.php`
- `app/Services/Platforms/WebsiteLinkHarvester.php`
- `app/Services/Platforms/WooCommerceScraper.php`
- `app/Services/User/AccountDeletionService.php`
- `app/Services/User/SiteProvisioningService.php`
- `app/Services/User/UserBootstrapService.php`
- `app/Console/Commands/CleanupOrphanedLifestyleConnections.php`
- `app/Http/Controllers/Api/User/Account/UserAccountDeletionController.php`
- `app/Http/Controllers/Api/User/Account/UserSelfController.php`
- `app/Http/Controllers/Api/User/Profile/SectorController.php`
- `app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php`
- `app/Http/Resources/PublicSite/IndividualProfileResource.php`
- `app/Http/Resources/UserDashboardResource.php`
- `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`
- `app/Jobs/Platforms/InstagramConnectJob.php`
- `app/Jobs/Platforms/MenuFetchJob.php`
- `app/Observers/User/UserObserver.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 4 complete

---

## P2 — Should fix

## P3 — Nice to have

- [ ] **SLOP-102** · P3 — Decorative ASCII-art section-separator comments add noise without structural value
    - **Where:** `app/Services/Platforms/GoogleBusinessAutoSync.php:139, 236, 300, 349, 521, 624, 665` (7 separators), `app/Services/Platforms/ShopifyScraper.php:194`, `app/Services/Platforms/WooCommerceScraper.php:280`
    - **Affects:** Readability — the banners are visual noise; the method names they sit above already convey the grouping.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Delete every `// ── … ──` line in the three files.
    - **Technical:** `CLAUDE.md`'s Commenting section explicitly says to "avoid... decorative banners." `GoogleBusinessAutoSync` uses seven (`reservation`/`booking`/`workplace`/`ordering`/`socials`/`findings`/`helpers`); `ShopifyScraper` and `WooCommerceScraper` each frame a single explanatory comment with a `── internals ──` banner, which is a banner around one line rather than a section of code.
    - **Plain English:** Drawing decorative horizontal lines between paragraphs in a document doesn't help anyone find anything — it just makes the document longer to scroll through. The method names already say what each group of code does.
    - **Evidence:**
        ```php
        // GoogleBusinessAutoSync.php — repeated 7×:
        // ── reservation ──────────────────────────────────────────────
        // ── booking ──────────────────────────────────────────────────
        // ── workplace ─────────────────────────────────────────────────

        // ShopifyScraper.php / WooCommerceScraper.php:
        // ── internals ────────────────────────────────────────────────
        ```

- [ ] **SLOP-103** · P3 — `MAX_IMAGES = 25` duplicated across four scraper classes instead of living once on the shared base
    - **Where:** `app/Services/Platforms/BigCartelScraper.php:16`, `GenericShopScraper.php:25`, `ShopifyScraper.php:71`, `WooCommerceScraper.php:22`
    - **Affects:** Maintainers — changing the gallery cap means touching four files; each carries its own copy of a "mirrors ShopifyScraper::MAX_IMAGES" comment confirming the value is meant to be one cross-provider constant.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `protected const MAX_IMAGES = 25;` to `PlatformScraper` (which already carries the shared `USER_AGENT` constant, so this is a same-pattern addition — no constructor/DI impact since constants aren't instance state).
        - Delete the four private copies; the subclasses already inherit from `PlatformScraper` so `self::MAX_IMAGES` resolves unchanged.
    - **Technical:** All four classes extend `PlatformScraper`. The constant is the same value with the same purpose (capping a stored product row's image gallery) in every file, and each copy's comment already says it "mirrors" the others — the duplication is acknowledged, not accidental.
    - **Plain English:** Four rooms each have their own thermostat set to exactly 72°, with a sticky note on each saying "keep at 72° like the other rooms." A single house-wide thermostat avoids someone having to visit every room to change the temperature.
    - **Evidence:**
        ```php
        // BigCartelScraper.php / GenericShopScraper.php / ShopifyScraper.php / WooCommerceScraper.php:
        private const MAX_IMAGES = 25;
        ```

- [ ] **SLOP-104** · P3 — `json()` fetch helper duplicated with near-identical bodies in three scrapers
    - **Where:** `app/Services/Platforms/ShopifyScraper.php:198-207`, `WooCommerceScraper.php:285-294` (byte-identical to each other); `BigCartelScraper.php:99-107` (adds an `Accept` header, skips the `is_array` check, returns `mixed`)
    - **Affects:** Maintainers — the same fetch-decode-validate sequence lives in three places.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a stateless helper to `PlatformScraper` that takes the fetcher explicitly as a parameter rather than storing it: `protected function decodeJson(?array $fetchResult): ?array { ... }`, called as `$this->decodeJson($this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT]))`. This keeps the base class's documented "pure functions... fetching stays in each subclass, which keeps their constructor signatures (and DI) untouched" contract intact (`PlatformScraper.php:8-13`) — do **not** move the `SafeUrlFetcher` call itself into the base class, since that would force every subclass constructor to route through a base constructor it currently doesn't have.
        - Standardize BigCartelScraper's variant on the same `?array` validation the other two already do (it currently returns unvalidated `mixed`).
    - **Technical:** `ShopifyScraper::json()` and `WooCommerceScraper::json()` are identical; `BigCartelScraper::json()` differs only in an extra `Accept` header and omitting the `is_array` guard (its caller compensates with its own `is_array` check, so this isn't a live bug — just an inconsistency). `PlatformScraper`'s own class docblock explains why fetching wasn't already centralized: keeping DI untouched. A helper that accepts the fetch result (rather than owning the fetcher) satisfies that constraint while still removing the duplicated decode/validate logic.
    - **Plain English:** Three rooms have copy-pasted the same recipe card, one with slightly different handwriting. The recipe should live in a shared binder — but the binder can't own the room's own ingredients (that's a deliberate design choice noted in this file), so only the shared "how to read this recipe" step moves, not the fetching itself.
    - **Evidence:**
        ```php
        // ShopifyScraper.php — identical to WooCommerceScraper.php:
        private function json(string $url): ?array
        {
            $res = $this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT]);
            if ($res === null || $res['status'] !== 200) {
                return null;
            }
            $data = json_decode($res['body'], true);

            return is_array($data) ? $data : null;
        }

        // BigCartelScraper.php — differs only in extra header + no is_array validation:
        private function json(string $url): mixed
        {
            $res = $this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json']);
            if ($res === null || $res['status'] !== 200) {
                return null;
            }

            return json_decode($res['body'], true);
        }
        ```

- [ ] **SLOP-105** · P3 — Dead private methods `hasStoreKey` and `count` left behind after eager-load refactor
    - **Where:** `app/Services/Platforms/GoogleBusinessAutoSync.php:492-499` (`hasStoreKey`), `:677-680` (`count`)
    - **Affects:** Maintainers — readers may assume these are still on a live call path, since a comment a few lines above references them by name as if they still ran per-iteration.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Delete `hasStoreKey()` (lines 492-499) and its docblock.
        - Delete `count()` (lines 677-680).
    - **Technical:** `seedOrdering()` was refactored to eager-load existing ordering connections into `$existingStoreKeys`/`$existingCount` in-memory maps, replacing per-iteration queries. Verified zero callers repo-wide: `grep -rn "hasStoreKey"` across the whole repo returns only the method's own definition and a comment mentioning it by name (`app/Services/Platforms/GoogleBusinessAutoSync.php:384`, plus two references inside archived audit markdown files under `audits/`, not live code); `grep -rn "->count(\$userId"` and a scan of the class for `$this->count(` return no matches anywhere in `app/` or `tests/`.
    - **Plain English:** A kitchen was renovated to use a central pantry instead of running to the store for every ingredient. The old shopping list pad and the spare car keys for those store runs are still sitting on the counter — they don't do anything anymore and just confuse the next cook into thinking they're still needed.
    - **Evidence:**
        ```php
        // Defined but never called anywhere in the class or repo:
        private function hasStoreKey(string $userId, string $storeKey): bool
        {
            return IntegrationConnection::query()
                ->where('user_id', $userId)
                ->where('platform', Platform::OnlineOrdering->value)
                ->get()
                ->contains(fn (IntegrationConnection $row) => $this->storeKey(CardPayload::fromArray($row->payload)->url()) === $storeKey);
        }

        private function count(string $userId, string $platform): int
        {
            return IntegrationConnection::query()->where('user_id', $userId)->where('platform', $platform)->count();
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Platform scraper hygiene:** #SLOP-102, #SLOP-103, #SLOP-104
    - **Why grouped:** all three touch `app/Services/Platforms/PlatformScraper.php` plus the same set of subclasses (`ShopifyScraper`, `WooCommerceScraper`, `BigCartelScraper`, `GenericShopScraper`) — one small mechanical-cleanup PR.
    - **Model:** Plan Opus · Implement Sonnet · Review Sonnet (all S-effort, low complexity).

- **Bundle 2 — GoogleBusinessAutoSync consolidation:** , #SLOP-105
    - **Why grouped:** both touch `GoogleBusinessAutoSync.php`; the trait extraction in  is a natural place to also drop the dead `hasStoreKey`/`count` helpers from #SLOP-105 in the same pass.
    - **Model:** Plan Opus · Implement Sonnet · Review Sonnet.

## Standalone — do NOT bundle

None.


<!-- ═══════════ audit-2026-07-17-semantic-correctness.md ═══════════ -->

# Semantic Correctness Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Semantic Correctness — code that compiles and type-checks but does the wrong thing (real-method-wrong-contract, config/flag misuse, plausible-but-wrong magic values, logic contradicting intent, codebase-idiom drift)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Accounts/AccountCapabilitySet.php
- app/Services/Accounts/LifestyleConnectionCleanup.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/PublicSite/SiteActionsService.php
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Services/User/AccountDeletionService.php
- app/Services/User/SiteProvisioningService.php
- app/Services/User/UserBootstrapService.php
- app/Support/BusinessName.php
- app/Http/Controllers/Api/User/Account/UserAccountDeletionController.php
- app/Http/Controllers/Api/User/Account/UserSelfController.php
- app/Http/Controllers/Api/User/Profile/SectorController.php
- app/Http/Controllers/Api/User/SiteManagement/CustomDomainController.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Jobs/Platforms/MenuFetchJob.php
- app/Policies/EarlyAccessSignupPolicy.php
- app/Policies/FeatureAvailabilityPolicy.php
- app/Policies/FeedbackPolicy.php
- app/Policies/UserSegmentPolicy.php
- app/Policies/UserSelfPolicy.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 1 complete

---

## P3 — Nice to have

- [ ] **SEM-101** · P3 — Instagram reel mirror leaks a file descriptor when the R2 `put()` call throws
    - **Where:** app/Jobs/Platforms/InstagramConnectJob.php:397-413
    - **Affects:** Horizon queue workers processing `InstagramConnectJob` during an R2/Storage outage or transient network fault — each failed reel mirror leaks one open file handle on the worker process.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `fopen`/`put` pair in its own `try/finally` (nested inside the existing outer `try`) so `fclose($stream)` always runs regardless of whether `Storage::disk('media')->put()` throws.
        - Alternatively, close the stream in the outer `catch` block before returning null, checking `is_resource($stream)` first (the variable may be undefined if `fopen` itself failed).
    - **Technical:** `mirrorVideo()` opens a read stream on the downloaded temp file (`fopen($tmp, 'r')`) and hands it to `Storage::disk('media')->put($path, $stream)`. The `fclose($stream)` call sits on the line immediately after `put()`, inside the same `try` block. If `put()` throws (R2 unreachable, network timeout, disk-full on the underlying adapter), execution jumps straight to the outer `catch (Throwable $e)`, skipping the `fclose()` call entirely. The `finally` block only does `@unlink($tmp)` — it never touches `$stream`. On a long-running Horizon worker this leaks one file descriptor per failed mirror; a sustained R2 outage across many Instagram-connect attempts would accumulate leaked descriptors until the worker hits the OS `ulimit`. This is a genuine, verified control-flow gap (confirmed by reading the exact code — not an assumption about library behavior), but the practical blast radius is small: Horizon workers recycle periodically and typical `ulimit -n` values are in the thousands, so this would need a sustained outage plus many connect attempts before it manifests as a crash.
    - **Plain English:** Imagine opening a filing-cabinet drawer to grab a folder, then someone yanks the whole cabinet away before you can close the drawer — you walk off, drawer still open. Do that enough times and the room fills with open drawers. This code opens a temporary file handle to upload an Instagram reel to cloud storage; if that upload fails, the handle never gets closed. It's a slow leak that would only become visible after many repeated failures during a cloud-storage outage, not a day-to-day problem.
    - **Evidence:**
        ```php
        // Stream temp file → R2 (no second in-memory copy of the video).
        $stream = fopen($tmp, 'r');
        if ($stream === false) {
            return null;
        }
        Storage::disk('media')->put($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return Storage::disk('media')->url($path);
        } catch (Throwable $e) {
            report($e);

            return null;
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
        ```

## Suggested Bundled Sessions

    - **Why grouped:** Single isolated finding, single file/method — nothing else in this audit shares its root cause.
    - **Model:** Plan: Opus (combine plan+implement given S effort) · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.



---
