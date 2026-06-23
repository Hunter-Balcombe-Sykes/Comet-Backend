# Triaged Survivors — Consolidated Fix Plan — 2026-06-23

Sources: the May 2026 audit campaigns + `codebase-full-sweep-2026-06-13`, triaged against current
code on 2026-06-23 (FIXED/OBSOLETE findings dropped). · Runnable fixes: 35 (12 bundles) · Standalone: 26 · **Out of scope — do NOT execute: 10**

**Read before fixing.** Every finding keeps its original audit ID (globally unique). The one-line
`→ <path>` backref points at the **archived source audit**, which carries the full
**Technical / Plain-English / Evidence** — read it before implementing. Each finding lives inside
exactly one bundle / standalone / out-of-scope block. Tick the finding when its fix lands and the
bundle box when the whole bundle is done. **Triage caveat:** these survived a re-check against current
code, but re-confirm each premise before coding — the codebase moved since the audits ran. `CCH-2` is
explicitly flagged RE-VERIFY.

> **`execute audit` note:** the work-list is built ONLY from `## Suggested Bundled Sessions` and
> `## Standalone — do NOT bundle`. The `## Out of scope — do NOT execute` section is deliberately
> excluded — those are deferred/decision items and net-new feature builds, NOT mechanical fixes.
> Do not run them; they need a human decision or are separate product work.

## Execution policy

Read by `scripts/audit/fix-flow.md`. Per unit: **plan → implement → independent review**.

- **plan = opus** · **implement = sonnet** · **review = sonnet** (defaults).
- Per-item overrides: bump **review → opus** for anything touching **auth / RLS / the KV single-writer
  contract / transactions / GDPR-PII / schema migrations / mail**. Combine plan+impl for **S/XS** units
  (skip the separate plan session). Escalate **implement → opus** for load-bearing invariant logic.
- **Blocker gate (pause for Josh's sign-off before implementing):** any P0 · auth/authorization · money
  · DB migration / schema change · L-XL effort · or anything under `## Standalone`.

## Progress

- Bundles: 7 / 12 complete
- Standalone: 0 / 26 complete
- Out of scope (not counted as work): 10

---

## Suggested Bundled Sessions

### Bundle B1: Observer cache-write amplification (7 items) — Effort: M
- [x] **Bundle B1 complete**
- Models: plan=sonnet · impl=sonnet · review=sonnet
- Findings:
    - [x] **#CACHE-1** · P2 — OBSOLETE (premise no longer holds): batch inserts already correct; session upserts are inherently per-session; driver-sniff is not per-event — `app/Services/Analytics/Writers/PostgresEventWriter.php:67-69` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-scaling-antipatterns.md`
    - [x] **#CACHE-2** · P2 — FIXED: `SiteMediaObserver` save split into created()/updated(); updated() gated on cache-affecting cols (is_active, processing_state, pool, media_type, path, sort_order) — `app/Observers/Core/SiteMediaObserver.php` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-scaling-antipatterns.md`
    - [x] **#CACHE-3** · P2 — OBSOLETE: bulk reorder bypasses Eloquent observers (mass `update()` + one explicit `$site->touch()` in controller) — `app/Observers/Core/BlockObserver.php:44-62` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-scaling-antipatterns.md`
    - [x] **#CACHE-4** · P2 — OBSOLETE: double-bust already fixed (`invalidateUser(..., bustSite: false)`, pinned by ServiceObserverSingleSiteBustTest) — `app/Observers/Core/ServiceObserver.php:68-82` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-scaling-antipatterns.md`
    - [x] **#CACHE-7** · P2 — FIXED: raw `Cache::forget`×2 routed through new `UserCacheService::invalidateCustomerCount()` (GS-1); increment/decrement rejected (soft-delete-aware + SWR :stale key) — `app/Observers/Core/CustomerObserver.php` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-scaling-antipatterns.md`
    - [x] **#CACHE-6** · P3 — FIXED: `SmartLinkObserver::runHooks` now passes `bustSite: false` (same double-bust ServiceObserver already fixed) — `app/Observers/Core/SmartLinkObserver.php` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-scaling-antipatterns.md`
    - [x] **#P3-27** · P3 — FIXED: `ServiceObserver` save split into created()/updated(); reevaluateBooking gated on visibility-affecting cols (is_active, price_cents, title) — `app/Observers/Core/ServiceObserver.php` → `audits/archive/foundation-audit-v3/audit-2026-05-31-CONSOLIDATED.md`
- Rationale: one family — per-row full invalidation instead of a dirty-flag/conditional guard. A shared "only bust on relevant change" pattern fixes them coherently.

### Bundle B2: Job failure semantics (2 items) — Effort: S
- [x] **Bundle B2 complete**
- Models: plan=— · impl=sonnet · review=opus (GDPR-PII override)
- Findings:
    - [x] **#JOB-3** · P2 — FIXED: `ExportUserDataJob` null-audit path now `throw`s (queue idiom) instead of report()+return; lost GDPR export lands in `failed_jobs` + retries — `app/Jobs/Gdpr/ExportUserDataJob.php` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-job-queue-correctness.md`
    - [x] **#JOB-10** · P2 — FIXED: `SendFeedbackEmailJob` null-feedback path now `throw`s (empty-recipients fire-and-forget path kept as no-op) — `app/Jobs/Notifications/SendFeedbackEmailJob.php` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-job-queue-correctness.md`
- Rationale: both `report()` but `return` instead of `$this->fail()`, so Horizon shows green. Same one-line swap.

### Bundle B3: Media & scaling memory footprint (4 items) — Effort: M
- [x] **Bundle B3 complete**
- Models: plan=sonnet · impl=sonnet · review=sonnet
- Findings:
    - [x] **#SCALE-3** · P2 — FIXED: lazy `getDriver()->listContents('videos', true)` + FileAttributes skip (was `allFiles()` materialising whole listing); same path strings, orphan/delete logic unchanged — `app/Console/Commands/GcOrphanedVideoArtifactsCommand.php` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-database-and-queue-scaling.md`
    - [x] **#SCALE-5** · P2 — FIXED: `readStream`+`stream_copy_to_stream` to temp file (was `$disk->get()` full-string copy), mirrors video job; GD reads same path — `app/Jobs/ProcessImageVariantsJob.php` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-database-and-queue-scaling.md`
    - [x] **#P3-28** · P3 — FIXED: structured `media.original_stored` breadcrumb (media_id+disk+path) before DB write so orphan R2 objects are traceable — `app/Services/Media/MediaUploadService.php` → `audits/archive/foundation-audit-v3/audit-2026-05-31-CONSOLIDATED.md` (follow-up noted: `uploadSingleton()` path)
    - [x] **#P3-32** · P3 — FIXED: `limit(500)` services / `limit(200)` categories on staff grouped index, mirroring user-facing caps — `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceManagementController.php` → `audits/archive/foundation-audit-v3/audit-2026-05-31-CONSOLIDATED.md` (follow-up noted: sibling StaffServiceCategoryManagementController)
- Rationale: full-blob/full-listing loads + unbounded reads; cap and stream.

### Bundle B4: HTTP status & response-shape correctness (3 items) — Effort: S
- [x] **Bundle B4 complete**
- Models: plan=— · impl=sonnet · review=opus (auth-adjacent MfaController + API contracts)
- Findings:
    - [x] **#P3-21** · P3 — FIXED: invalid `blockType` now 422 (was 403); ownership-missing still 404 — `app/Http/Controllers/Api/User/SiteManagement/UserSectionBlockController.php` → `audits/archive/foundation-audit-v3/audit-2026-05-31-CONSOLIDATED.md`
    - [x] **#P3-29** · P3 — FIXED: dropped duplicate `pagination` shim (Josh confirmed FE reads `meta`); `docs/api.md` updated to `meta` shape — `app/Http/Controllers/Api/User/Customers/UserCustomerController.php` → `audits/archive/foundation-audit-v3/audit-2026-05-31-CONSOLIDATED.md`
    - [x] **#API-8** · P3 — FIXED: `ApiController::error()` gained `array $extra` (clobber-guarded); MfaController emits `code: mfa_fresh_required`@401 and PublicReportController emits `error: INVALID_TARGET`@422 / `DUPLICATE_REPORT`@409 — both routed through ApiController, contracts byte-preserved — `app/Http/Controllers/Api/PublicSite/PublicReportController.php`, `app/Http/Controllers/Api/User/Account/MfaController.php` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-api-contract.md`
- Rationale: API-contract correctness; keep the FE-critical `code` value when normalising MfaController.

### Bundle B5: Public-endpoint rate limiting & enumeration (2 items) — Effort: M
- [x] **Bundle B5 complete**
- Models: plan=sonnet · impl=sonnet · review=opus
- Findings:
    - [x] **#P2-44** · P2 — FIXED: dedicated `throttle:signup-availability` (10/min) + `throttle:login-identifier` (20/min) limiters (was shared `public-site` 60/min); independent buckets — `routes/api.php`, `app/Providers/AppServiceProvider.php`, `config/partna.php` → `audits/archive/foundation-audit-v3/audit-2026-05-31-CONSOLIDATED.md`
    - [x] **#P3-10** · P3 — FIXED: `signup-availability` is an ANDed `[perMinute(10), perHour(60)]` per-IP (CF-Connecting-IP) gate — the per-hour cap is the secondary anti-enumeration gate — `app/Providers/AppServiceProvider.php` → `audits/archive/foundation-audit-v3/audit-2026-05-31-CONSOLIDATED.md`
- Rationale: both are public-surface abuse vectors on the signup path; dedicated limiters.

### Bundle B6: Config hygiene (3 items) — Effort: S
- [x] **Bundle B6 complete**
- Models: plan=— · impl=sonnet · review=sonnet
- Findings:
    - [x] **#STRIP-4** · P2 — FIXED: removed dead `'shop'` from `allowed_sections` + `default_sections`; `'booking'` KEPT (premise partially wrong — booking is live via SectionVisibilityService/resolver/analytics) — `config/partna.php` → `audits/archive/loose-may-2026/audit-2026-05-22-dead-code-broken-references-and-consistency-gaps-a.md`
    - [x] **#FF-4** · P3 — FIXED: `config('partna.features')[$key] ?? false` (array access) replaces dot-path interpolation so a dotted `$key` can't traverse — `app/Services/FeatureFlags/FeatureFlagService.php:61,245` → `audits/archive/loose-may-2026/audit-2026-05-18-security-auth-and-injection-risks-feature-flags.md`
    - [x] **#CFG-11** · P3 — FIXED: `KICK_RATE_LIMITED_TTL` now reads `config('partna.streaming.kick_rate_limited_ttl', 300)` matching the other 5 streaming TTLs — `app/Services/Streaming/LiveStatusPoller.php` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-configuration-hygiene.md`
- Rationale: config-as-source-of-truth cleanups; low risk.

### Bundle B7: Validation, hashing & logging hygiene (5 items) — Effort: S
- [x] **Bundle B7 complete**
- Models: plan=— · impl=sonnet · review=sonnet
- Findings:
    - [x] **#SEC-12** · P3 — FIXED: extracted inline validate() into `StaffBulkUpdateStatusRequest` FormRequest — `app/Http/Controllers/Api/Staff/UserSiteManagement/StaffUserController.php` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-security.md`
    - [x] **#SEC-14** · P3 — FIXED: both sites now `hash_hmac('sha256', $ip, config('app.key'))` (shared key → cross-system correlation works); B10 feedback pepper left separate — `app/Http/Middleware/VerifyBotToken.php`, `app/Services/Moderation/ContentReportService.php` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-security.md`
    - [x] **#P3-09** · P3 — FIXED: `Rule::exists('pgsql.core.users', 'id')` added to `user_id` (kept uuid+nullable) — `app/Http/Requests/Api/Staff/Notifications/StaffStoreNotificationRequest.php` → `audits/archive/foundation-audit-v3/audit-2026-05-31-CONSOLIDATED.md`
    - [x] **#P3-05** · P3 — FIXED: `sanitizeOutput()` strips `/...` paths + tail-truncates ffmpeg output before logging (encodeMp4 + extractPoster) — `app/Services/Media/VideoVariantService.php` (follow-up noted: `probe()` line ~78 has same raw-output pattern, not cited) → `audits/archive/foundation-audit-v3/audit-2026-05-31-CONSOLIDATED.md`
    - [x] **#P3-01** · P3 — FIXED: docblock corrected to say `notification_categories` is informational, no enforcement — `app/Services/Accounts/AccountCapabilitySet.php` → `audits/archive/foundation-audit-v3/audit-2026-05-31-CONSOLIDATED.md`
- Rationale: small, independent hygiene fixes.

### Bundle B8: Code reuse — analytics queries (1 item) — Effort: S
- [ ] **Bundle B8 complete**
- Models: plan=— · impl=sonnet · review=sonnet
- Findings:
    - [ ] **#P3-24** · P3 — `StaffAnalyticsController` duplicates `AnalyticsQueryService` query logic inline — `app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php:98-153` → `audits/archive/foundation-audit-v3/audit-2026-05-31-CONSOLIDATED.md`
- Rationale: route the inlined click-analytics blocks through the existing service.

### Bundle B9: KV / subdomain write efficiency (2 items) — Effort: S
- [ ] **Bundle B9 complete**
- Models: plan=sonnet · impl=sonnet · review=opus
- Findings:
    - [ ] **#SCALE-6** · P2 — `SyncSubdomainToKvJob::writeAliasEntries` one KV HTTP call per alias → write-rate exhaustion on backfill — `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:182-204` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-database-and-queue-scaling.md`
    - [ ] **#P3-31** · P3 — Alias TTL floor grants ~60s extra KV lifetime to already-expired aliases (no skip-guard) — `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:199` → `audits/archive/foundation-audit-v3/audit-2026-05-31-CONSOLIDATED.md`
- Rationale: same file (the single KV writer). review=opus — KV single-writer contract.

### Bundle B10: Feedback service hardening (3 items) — Effort: S
- [ ] **Bundle B10 complete**
- Models: plan=— · impl=sonnet · review=sonnet
- Findings:
    - [ ] **#SEC-1c** · P2 — `user_agent` header read directly as a fallback, bypassing FormRequest validation — `app/Services/Feedback/FeedbackService.php:61` → `audits/archive/loose-may-2026/audit-2026-05-25-core.md`
    - [ ] **#LIFE-2c** · P2 — `hashIp()` silently returns null when pepper empty; no `AppServiceProvider::boot()` production assertion — `app/Services/Feedback/FeedbackService.php:83-89` → `audits/archive/loose-may-2026/audit-2026-05-25-core.md`
    - [ ] **#LIFE-4c** · P2 — `SendFeedbackEmailJob` early-exit + `failed()` logs omit `user_id` — `app/Jobs/Notifications/SendFeedbackEmailJob.php:36-37,46,56,107` → `audits/archive/loose-may-2026/audit-2026-05-25-core.md`
- Rationale: feedback-pipeline hardening (the DB-race sibling `#LIFE-1c` is Standalone — needs a UNIQUE constraint). IDs suffixed `c` = the May "core" audit, to disambiguate from sweep `SEC-1`/`LIFE-x`.

### Bundle B11: Supabase email-hook delivery id (1 item) — Effort: S
- [ ] **Bundle B11 complete**
- Models: plan=— · impl=sonnet · review=opus
- Findings:
    - [ ] **#WHK-5** · P2 — `Mail::queue()` carries no event-id → a Horizon retry can re-send auth emails (no `Message-ID` from webhook id) — `app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php:107` → `audits/archive/loose-may-2026/audit-2026-05-25-email-change-flow.md`
- Rationale: thread the webhook id into the mailable for idempotent delivery. review=opus — mail. (Forensic-trail `#WHK-3` + replay `#WHK-4` are Standalone — they need a new table.)

### Bundle B12: Test-coverage gaps (5 items) — Effort: M
- [ ] **Bundle B12 complete**
- Models: plan=— · impl=sonnet · review=sonnet
- Findings:
    - [ ] **#TEST-4** · P2 — No test asserts `IndividualProfileResource` excludes `placeholders`/`fallback_gallery`/`brand_logo`/`brand_slogan` — `tests/Feature/PublicSite/IndividualProfileControllerTest.php` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-test-coverage.md`
    - [ ] **#TEST-9** · P2 — `FeedbackPolicy` pending-deletion-can-create invariant untested — `app/Policies/FeedbackPolicy.php:35` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-test-coverage.md`
    - [ ] **#FFLAG-1** · P2 — `allFor()` → `allForFromDb()` degraded path (real DB flags + overrides) untested — `tests/Unit/FeatureFlags/RedisDownFallbackTest.php` → `audits/archive/loose-may-2026/audit-2026-05-18-test-coverage-gaps-and-edge-case-test-quality-2.md`
    - [ ] **#FFLAG-2** · P2 — `enabled()` degraded path with an actual `FeatureFlag` DB row untested — `tests/Unit/FeatureFlags/RedisDownFallbackTest.php` → `audits/archive/loose-may-2026/audit-2026-05-18-test-coverage-gaps-and-edge-case-test-quality-2.md`
    - [ ] **#FFLAG-6** · P3 — `seedProAndSite()` returns a floating `$siteId` (no `site.sites` row) — `tests/Feature/FeatureFlags/SectionVisibilityLinkOnlyTest.php:15` → `audits/archive/loose-may-2026/audit-2026-05-18-test-coverage-gaps-and-edge-case-test-quality-2.md`
- Rationale: pure test additions; no production code change.

---

## Standalone — do NOT bundle

Run individually, P0→P3 order. **Every item here hits the blocker gate** (auth / DB-migration / GDPR-PII / mail / re-verify) → produce the plan, present it, wait for Josh's sign-off before implementing.

- [ ] **#CCH-2** · P1 — ⚠️ **RE-VERIFY FIRST.** `NotificationPublisher` does call `self::forget()` (`:356`); open question is whether publish also busts `NotificationListingService::bustIndexCache()` (`:181`). Confirm it's a real gap before any change — `app/Services/Notifications/NotificationPublisher.php` → `audits/archive/loose-may-2026/caching-fix-plan-2026-05-21.md`
- [ ] **#SEC-1** · P1 — Analytics ingest IDOR: a `site_id`-only POST records fabricated events against any site (tenant isolation) — `app/Http/Controllers/Concerns/ResolvesSiteFromRequest.php:22-38`, `app/Http/Requests/Api/PublicSite/Analytics/PageviewRequest.php:22-23` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-security.md`
- [ ] **#PRIV-2** · P1 — 30-day GDPR export retention declared but no enforcer (no `gdpr:prune-completed-exports`) — `config/partna.php:1150` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-privacy-compliance.md`
- [ ] **#PRIV-3** · P1 — 7-year handle-audit retention declared but no `handles:prune-audit-logs` for `audit.handle_change_log` — `config/partna.php:38` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-privacy-compliance.md`
- [ ] **#PRIV-4** · P1 — Reported-user PII in `moderation.evidence.payload` never redacted on account deletion / resolved-case cleanup — `app/Services/.../AccountDeletionService.php:517-523` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-privacy-compliance.md`
- [ ] **#LIFE-1c** · P2 — Feedback non-atomic read-then-write duplicate guard; needs a UNIQUE constraint (migration) — `app/Services/Feedback/FeedbackService.php:34-48` → `audits/archive/loose-may-2026/audit-2026-05-25-core.md`
- [ ] **#WHK-3** · P2 — No forensic trail for failed auth-email deliveries (needs `core.supabase_email_events` table) — `app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php` → `audits/archive/loose-may-2026/audit-2026-05-25-email-change-flow.md`
- [ ] **#WHK-4** · P3 — No `supabase:replay-emails` artisan command — **depends on #WHK-3** — → `audits/archive/loose-may-2026/audit-2026-05-25-email-change-flow.md`
- [ ] **#PRIV-7** · P2 — Cross-tenant email subscriptions never purged on account deletion; unsubscribed rows never time-pruned — `app/Services/.../AccountDeletionService.php:699-703` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-privacy-compliance.md`
- [ ] **#PRIV-8** · P2 — Waitlist PII retained indefinitely (no retention config/command/schedule) — `config/partna.php:755` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-privacy-compliance.md`
- [ ] **#DINT-1** · P2 — `notifications.email_subscriptions` PII never nulled post-unsubscribe — → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-data-integrity.md`
- [ ] **#DINT-2** · P2 — `broadcast_email_receipts.subscription_id` has no FK → orphan receipts (migration) — `supabase/migrations/20260526000000_baseline_standalone_user.sql:1120` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-data-integrity.md`
- [ ] **#DINT-3** · P2 — `audit.auth_factor_events.user_id` NOT NULL with no FK → silent orphans (migration) — `supabase/migrations/20260527010000_reorganize_schemas.sql:43` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-data-integrity.md`
- [ ] **#SCHEMA-2** · P2 — `site.site_subdomain_aliases.id` has no `DEFAULT gen_random_uuid()` (migration) — `supabase/migrations/20260526000000_baseline_standalone_user.sql:865` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-schema-rls.md`
- [ ] **#MIG-2** · P2 — Unbatched inline `UPDATE site.sites` in 3 migrations, no `SET LOCAL statement_timeout` (none applied to prod) — `supabase/migrations/20260527070000_skeleton_system_cleanup.sql:62` (+2) → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-migration-safety.md`
- [ ] **#MIG-5** · P2 — `DROP INDEX` without `CONCURRENTLY` (brief ACCESS EXCLUSIVE on `site_media`) — `supabase/migrations/20260527000000_fix_sort_order_unique_constraints.sql:11-12` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-migration-safety.md`
- [ ] **#EDGE-10** · P2 — Staging Worker `wrangler.toml` has placeholder KV namespace IDs (`REPLACE_WITH_...`) — needs the real staging KV IDs from Josh — `cloudflare-worker/wrangler.toml:50-53` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-edge-worker.md`
- [ ] **#P3-11** · P3 — Auth fallback returns 401 for network/upstream outages (should be 503) — `app/Http/Middleware/Auth/VerifySupabaseJwt.php:~224` → `audits/archive/foundation-audit-v3/audit-2026-05-31-CONSOLIDATED.md`
- [ ] **#DINT-6** · P3 — `moderation.case_signals.reporter_email` no timed retention for non-account reporters — → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-data-integrity.md`
- [ ] **#DINT-7** · P3 — `analytics.section_views.block_id` FK column has no index (migration) — → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-data-integrity.md`
- [ ] **#DINT-8** · P3 — `core.feature_flag_overrides.created_by` FK column has no index (migration) — → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-data-integrity.md`
- [ ] **#DINT-10** · P3 — `site.platform_connections` missing a BEFORE UPDATE `updated_at` trigger (migration) — `supabase/migrations/20260609000000_harden_platform_connections.sql` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-data-integrity.md`
- [ ] **#DINT-11** · P3 — `site_media_pool_check` still lists dead `'brand_gallery'` (migration) — `supabase/migrations/20260526000000_baseline_standalone_user.sql:810` → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-data-integrity.md`
- [ ] **#SCHEMA-7** · P3 — `core.prevent_staff_escalation` + `core.enforce_site_gallery_max6` use `SET search_path TO 'pg_catalog'` not `= ''` (migration) — → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-schema-rls.md`
- [ ] **#MIG-6** · P3 — No `SET LOCAL lock_timeout/statement_timeout` guards on hot-table migrations (`20260612120000`, `20260612140000`, + MIG-2 files) — → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-migration-safety.md`
- [ ] **#GS-8** · P3 — Cloudflare Cache Rules on `/api/public/*` not added (headers correct; CF won't cache JSON without an explicit rule) — `app/Http/Middleware/AddPublicCacheHeaders.php` (infra config, not code) → `audits/archive/loose-may-2026/audit-2026-05-07-caching-foundation.md`

---

## Out of scope — do NOT execute

**These are NOT fix units. `execute audit` must skip this section.** Two kinds:

**(a) Deferred / decision-pending** — need a human call on approach before any code:
- [ ] **#SCHEMA-5 / #DINT-5** — `site.smart_links.type`/`.platform` CHECK constraints — *deferred pending a closed-enum decision*. → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-schema-rls.md`
- [ ] **#SCHEMA-6** — FORCE-RLS "batch S5" across ~25 tenant tables (owner role bypasses RLS) — *deferred to a batch migration; security-relevant — decide + schedule.* → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-schema-rls.md`
- [ ] **#DINT-12** — `createWelcomeNotification` firstOrCreate race — *deferred to S8 per code comment*. → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-data-integrity.md`
- [ ] **#OBS-11** — `CheckStreamingLiveStatusJob` Redis-down recorded as Horizon "succeeded" — *by design* (sync/unit path null `$this->job`); `report()` already fires. Decision: change the counter or accept. → `audits/archive/codebase-full-sweep-2026-06-13/audit-2026-06-13-observability.md`
- [ ] **#DEFER-2** — Laravel Pulse cache hit/miss dashboards — *intentionally deferred (50 req/sec trigger)*.

**(b) Additions, not fixes** — net-new staff-admin features (build as product work, not via the fix flow):
- [ ] **#OPS-1** — Staff impersonation ("act as user") — unbuilt feature.
- [ ] **#ENQUIRY-1** — Staff enquiries DELETE — read half shipped; admin-delete unbuilt.
- [ ] **#UPLOAD-1 / #B7** — Staff-side media management — unbuilt feature.
- [ ] **#MSG-1** — Staff transactional email send-on-behalf + template registry — unbuilt.
- [ ] **#FAILED-JOB-1** — Per-user failed-job inspector — unbuilt feature.
