# Track A Backend — Full All-Lenses Consolidated Audit
**Date:** 2026-05-20
**PRs covered:** #72–#100 (commits `d237f207` → `72050326`, branch `development`)
**Lenses run:** 16 of 17 (see Skipped Lenses)
**Pipeline:** DeepSeek V4 Pro scan → Claude Sonnet adjudication per lens

---

## Progress

| Tier | Count | Surviving (open) | Closed by prior PRs |
|------|-------|-------------------|---------------------|
| P0   | 0     | 0                 | 0                   |
| P1   | 16    | 13                | 3                   |
| P2   | 34    | 31                | 3                   |
| P3   | 24    | 22                | 2                   |
| **Total** | **74** | **66** | **8** |

> All 16 lenses complete. 4 lenses (api-contract, observability, transaction-boundaries, brand-status) required a second pass with tighter scope after hitting adjudication-side context limits on first pass. All retry findings have been incorporated. See Skipped Lenses section for details.

---

## Closed by Prior PRs

| ID | Summary | Closed by |
|----|---------|-----------|
| PROF-1 | Block settings JSON column exposed unfiltered to public API | PR #100 |
| PROF-2 | Design settings passed without key allowlist to public API | PR #100 |
| PROF-3 | Cache stamp frozen at 0 when Professional has no Site record | PR #100 |
| SEC-2  | Missing boot guard for JWT issuer/audience (partial — was SEC-3 in prior audit) | PR #90 + #92 |
| SEC-3  | JWKS alg derived from inbound JWT not per-kid | PR #92 |
| SYNC-3 | KV single-writer rule tested (CapabilityDispatchTest + SubdomainKvWritersTest) | PR #91 |
| CACHE-3 | Dead `siteImages` cache key with no writer | Dropped (no writer confirmed, stale-evidence) |
| SHOP-2 | HMAC sign-fail coverage for 7 secondary Shopify webhooks | PR #93 |

---

## P1 — Fix before pilot launch

- [ ] **#SHOP-1** · P1 — M — All Shopify webhook controllers trust unsigned `X-Shopify-Shop-Domain` header; any connected brand can trigger disconnect/GDPR-redact against another brand
    - **Where:** `app/Http/Controllers/Concerns/HandlesShopifyWebhook.php:65,93`; `ShopifyAppUninstalledWebhookController.php:22`; `ShopifyGdprWebhookController.php:36`
    - **Affects:** All Shopify webhook endpoints. Highest-impact: `app/uninstalled` (nulls target brand's access token) and `shop/redact` (triggers GDPR redaction job against wrong shop).
    - **What to do:**
        - In `HandlesShopifyWebhook::__invoke()`, extract authoritative shop identity from the HMAC-protected body: `$payloadDomain = strtolower(trim((string) ($payload['myshopify_domain'] ?? $payload['domain'] ?? '')))`. If non-empty and doesn't match `$shopDomain` from header, return `400`.
        - Apply same cross-check in `ShopifyAppUninstalledWebhookController` and `ShopifyGdprWebhookController`.
        - Add test: sign webhook body for Shop A, set header to Shop B, assert 400.
    - **Evidence:** `HandlesShopifyWebhook.php:65` reads `$shopDomain` from `X-Shopify-Shop-Domain` (unsigned); line 93 resolves `ProfessionalIntegration` from it. Shopify's HMAC covers body only — headers are unsigned. Body fields `myshopify_domain`/`domain` are HMAC-protected and identify the true originating store.

- [ ] **#AUTH-1** · P1 — S — `claimsMatchConfig` skips issuer/audience checks when env vars are absent, accepting cross-project JWTs on auth-server fallback path
    - **Where:** `app/Http/Middleware/Auth/VerifySupabaseJwt.php` — `claimsMatchConfig()` and `verifyWithAuthServer()`
    - **Affects:** All authenticated API routes when JWKS verification fails and auth-server fallback triggers. A mis-deployed instance missing `SUPABASE_JWT_ISSUER` / `SUPABASE_JWT_AUDIENCE` accepts valid JWTs from any Supabase project.
    - **What to do:**
        - Add boot-time assertion in `AppServiceProvider::boot()` (non-local, non-testing) that `config('supabase.jwt_issuer')` and `config('supabase.jwt_audience')` are non-empty; throw `RuntimeException` if absent.
        - OR make `claimsMatchConfig` return `false` immediately when either expected value is empty string, failing closed.
    - **Evidence:** `claimsMatchConfig` guards checks with `if ($issExpected && ...)` / `if ($audExpected)`. When env vars absent, `(string) config(...)` returns `""` (falsy) — both checks skipped, method returns `true`. The fallback path decodes JWT body without any crypto verification.

- [ ] **#ACCT-1** · P1 — S — Post-commit dispatches fire unconditionally when inner lock re-check finds race already resolved; phantom `AccountTypeTransitionEvent` fires
    - **Where:** `app/Services/Accounts/AccountTypeTransitionService.php:88–109`
    - **Affects:** Concurrent transition requests (double-tap, staff race). Losing request fires `SyncSubdomainToKvJob`, `CloudflareCachePurgeJob`, `AccountTypeTransitionEvent` for a transition that never happened. Listeners process phantom transition.
    - **What to do:**
        - Change `DB::transaction` closure return type from `void` to `bool`; return `true` on save, `false` on bail-out.
        - Capture: `$mutated = DB::transaction(...)`. Wrap three post-commit dispatches in `if ($mutated)`.
    - **Evidence:** Bail-out path (`if ($currentType === $to) { return; }`) escapes closure but three unconditional dispatches below the transaction still fire. Docblock comment ("callers do not see the after-commit dispatch in this branch") is factually incorrect.

- [ ] **#MIG-1** · P1 — S — `prevent_staff_escalation()` function body references stale table name `core.comet_staff`; every staff record UPDATE fails on new DB sessions
    - **Where:** `supabase/migrations/20260403000000_v2_baseline.sql` (function body ~line 60)
    - **Affects:** Any UPDATE to `core.partna_staff` rows — role changes, email updates. Table renamed twice (`comet_staff→sidest_staff→partna_staff`) but PL/pgSQL body (stored as raw text) not updated. PL/pgSQL recompiles from source text per-session; Supavisor pools recycle connections constantly.
    - **What to do:** Add `20260524000000_fix_prevent_staff_escalation_stale_ref.sql` containing `CREATE OR REPLACE FUNCTION core.prevent_staff_escalation()` replacing `core.comet_staff` → `core.partna_staff`.
    - **Evidence:** `SELECT 1 FROM core.comet_staff cs ...` in function body. Both rename migrations contain no `CREATE OR REPLACE FUNCTION`.

- [ ] **#DATA-1** · P1 — S — `Block` model missing `SoftDeletes` trait despite `deleted_at` column; deletions hard-delete rows, orphan `analytics.link_clicks` FKs, and skip cache-invalidation observer
    - **Where:** `app/Models/Core/Site/Block.php:13`
    - **Affects:** Every block delete — permanently destroys analytics FK relationships and silently skips `BlockObserver::deleted()` → `SiteCacheService::invalidateSite()`, leaving stale public-facing site cache.
    - **What to do:** Add `use Illuminate\Database\Eloquent\SoftDeletes;` + `'deleted_at' => 'datetime'` to `$casts`.
    - **Evidence:** `class Block extends BaseModel { use HasUuids; // SoftDeletes absent }`. V2 baseline DDL includes `deleted_at timestamptz`. `BlockObserver::deleted()` only fires on soft-delete.

- [ ] **#CFG-1** · P1 — S — `.env.example` sets `REDIS_CACHE_DB=0`, colliding with Horizon's queue DB; `Cache::flush()` silently purges pending job queue
    - **Where:** `.env.example:98`
    - **Affects:** Any environment bootstrapped from `.env.example`. `Cache::flush()` issues raw `FLUSHDB` on DB 0, which also holds Horizon job metadata.
    - **What to do:** Change `.env.example` to `REDIS_CACHE_DB=1`; cascade other DB assignments: `REDIS_SESSION_DB=2`, `REDIS_QUEUE_DB=3`, `REDIS_CACHE_LOCKS_DB=4`.
    - **Evidence:** `.env.example:98` has `REDIS_CACHE_DB=0`. `config/database.php` defaults `REDIS_CACHE_DB` to `1`. `config/horizon.php` uses `'use' => 'default'` = DB 0.

- [ ] **#SYNC-1** · P1 — S — Alias TTL silently dropped; all handle aliases become permanent in Cloudflare KV
    - **Where:** `app/Services/Cloudflare/CloudflareKvService.php:36` / `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:124`
    - **Affects:** All professionals who rename their handle — old aliases never auto-expire at edge, accumulate indefinitely, and potentially misroute requests after the intended expiry.
    - **What to do:** Add optional `?int $expirationTtl` parameter to `CloudflareKvService::put()`; append `?expiration_ttl={$expirationTtl}` to the Cloudflare KV REST URL. The alias call site already computes the correct TTL — it just hits a 2-param function and the third arg is silently discarded by PHP.
    - **Evidence:** `put(string $key, array $value): void` — two params only. Job calls `$kv->put($handle, [...], $ttl)` but `$ttl` is silently dropped. PHP doesn't error on extra arguments.

- [ ] **#QUEUE-1** · P1 — M — Export chunk cursor advances before next-chunk dispatch; retry fetches wrong payouts and overwrites part file, producing corrupt export
    - **Where:** `app/Jobs/Exports/ExportChunkJob.php` — `markChunkCompleted` then `ExportChunkJob::dispatch`
    - **Affects:** Commission export data integrity — Redis blip or serialization error after cursor commit causes retry to fetch chunk+1 payouts into chunk+N's part file.
    - **What to do:** Add early-exit guard at top of `handle()`: if `$audit->chunks_completed > $this->chunkIndex`, skip fetch/write and jump to next dispatch or finalizer. This makes retries idempotent.
    - **Evidence:** `markChunkCompleted` commits `last_processed_payout_id` and increments `chunks_completed`. If the subsequent `ExportChunkJob::dispatch(...)` throws, Horizon retries with same `chunkIndex`. `fetchChunkPayouts` now uses the advanced cursor → returns next chunk's payouts → overwrites current part file.

- [ ] **#SCALE-1** · P1 — L — `BackfillBrandHasEnabledVariantsJob` loads entire catalog into memory and makes one Shopify GraphQL call per product; times out for brands with >500 products
    - **Where:** `app/Jobs/Shopify/BackfillBrandHasEnabledVariantsJob.php` — entire `handle()` method
    - **Affects:** Brands with large product catalogs during OAuth install chain. Job times out at 120s, leaves `has_enabled_variants` partially seeded, Active Products smart collection resolves incorrectly.
    - **What to do:** Add cursor checkpointing in `provider_metadata` (`has_enabled_variants_backfill_cursor`) so retries resume rather than restart. Longer-term: batch `metafieldsSet` in groups of 25. Increase `$timeout` to 300s, `$tries` to 5.
    - **Evidence:** Job comment: "120s covers ~500 products comfortably." At 500ms/write × 1000 products = 500s. No checkpoint. Each retry replays from zero.
    - **Cross-reference:** Also raised by `database-and-queue-scaling` lens as `#API-2` — same finding, keep as SCALE-1.

- [ ] **#TEST-1** · P1 — M — `AccountCapabilities` runtime registry (16-flag capability matrix) has zero test coverage
    - **Where:** `app/Services/Accounts/AccountCapabilities.php`
    - **Affects:** Every API request, job, and route guard. A wrong boolean silently grants/denies features across the entire surface — affiliates dashboard exposed, design editor blocked for brands.
    - **What to do:** Create `tests/Unit/Services/Accounts/AccountCapabilitiesTest.php` asserting all flags for all 3 `AccountType` cases + `null` input + `flushCache()` invalidation behaviour.

- [ ] **#TEST-2** · P1 — M — `AccountTypeTransitionService` canonical mutation gateway has zero test coverage
    - **Where:** `app/Services/Accounts/AccountTypeTransitionService.php`
    - **Affects:** All post-signup `account_type` mutations. Regression can corrupt account types, skip Cloudflare KV sync, or leave wrong worker routing.
    - **What to do:** Create `tests/Unit/Services/Accounts/AccountTypeTransitionServiceTest.php`. Cover Brand→anything throws, same-state no-op, individual→partner full dispatch, concurrent race bail-out.

- [ ] **#TEST-4** · P1 — L — `BootstrapController` brand-attach branches (4 paths: invite_token, partner_professional_id, join_handle, signup_code) have zero test coverage
    - **Where:** `app/Http/Controllers/Api/PublicSite/BootstrapController.php` (~lines 150–310)
    - **Affects:** New user signup — the entire partner onboarding funnel. The `by-reference closure variable` (`&$brandSignupCodeError`) pattern that allows transaction commit on partial failure is untested.
    - **What to do:** Create `tests/Feature/PublicSite/BootstrapBrandAttachTest.php` covering happy path + each error branch + cap-exceeded 422 + EMAIL_ALREADY_REGISTERED 409.

---

- [ ] **#BRAND-1** · P1 — S — `SendBrandStatusNotificationJob` match arms use legacy string `'building'` (never dispatched); every `Onboarding` brand transition sends affiliates "program now active" instead of "program paused"
    - **Where:** `app/Jobs/Notifications/SendBrandStatusNotificationJob.php:88-104`
    - **Affects:** All affiliates connected to a brand when it transitions to `BrandStatus::Onboarding`. `BrandProfileObserver` dispatches `->value` (`'onboarding'`), but the match arm looks for `'building'` — falls through to default, sends wrong notification.
    - **What to do:** Replace `'building'` arm with `BrandStatus::Onboarding->value` (`'onboarding'`). Replace `default` arm with explicit `BrandStatus::ReadyForAffiliates->value` arm. Add warning-log default for future enum additions. Update constructor docblock.
    - **Evidence:** `BrandStatus::Onboarding->value === 'onboarding'` (confirmed). `fromLegacy()` maps `'building'→Onboarding` but is NOT on the dispatch path — observer uses raw `->value`. Match never matches `'building'`; `'onboarding'` falls to default "Brand program now active."

## P2 — Should fix

- [ ] **#ACCT-2** · P2 — S — `AccountCapabilities::for()` WeakMap cache serves stale capabilities after `setRawAttributes` mutates Professional in-place; wrong notification prefs and Stripe banner on transition
    - **Where:** `app/Services/Accounts/AccountCapabilities.php:44–62`; `AccountTypeTransitionService.php:97–100`
    - **What to do:** Call `AccountCapabilities::flushCache()` in `AccountTypeTransitionService` immediately after `$pro->setRawAttributes(...); $pro->syncOriginal();`. Method already exists.

- [ ] **#AUTH-2** · P2 — S — `SUPABASE_JWKS_FAIL_CLOSED` defaults to `false`, silently degrading to auth-server fallback during JWKS outages with no operator visibility
    - **Where:** `app/Http/Middleware/Auth/VerifySupabaseJwt.php`
    - **What to do:** Set `SUPABASE_JWKS_FAIL_CLOSED=true` in production. Or flip default in config to `true`, requiring explicit opt-in for fallback. Add Nightwatch alert on JWKS failure log line.

- [ ] **#CACHE-1** · P2 — S — `CommissionPayoutObserver` status transitions don't bust `affiliatePayoutState` cache; affiliate dashboard shows stale "pending" after payout completes
    - **Where:** `app/Observers/Core/CommissionPayoutObserver.php:28–60`
    - **What to do:** In `updated`, after each status-change branch, add: `$key = CacheKeyGenerator::affiliatePayoutState($payout->affiliate_professional_id); Cache::forget($key); Cache::forget($key.':stale');` — mirror pattern from `CommissionPayoutRefundService::bustPayoutCaches()`.

- [ ] **#CACHE-2** · P2 — S — Deploy trigger busts `brandStorefrontStatus` primary key but leaves `:stale` twin alive for up to 10 minutes
    - **Where:** `app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php:285`
    - **What to do:** Replace bare `Cache::forget($key)` with `Cache::deleteMultiple([$key, $key.':stale'])`. The `forceRefresh` path already does this correctly — mirror it.

- [ ] **#CACHE-6** · P2 — M — Brand site edits don't bust Hydrogen affiliate-page caches; affiliates serve stale brand design for up to 60s
    - **Where:** `app/Services/Cache/SiteCacheService.php` — `invalidateSite` brand branch
    - **What to do:** Inside the `$owner?->isBrand()` branch, also walk active `BrandPartnerLink` rows and call `forgetHydrogenAffiliate` per affiliate. OR extend `InvalidateConnectedAffiliateCachesJob` to also clear `hydrogen:affiliate:v1:{brandId}:{slug}` keys.

- [ ] **#CFG-2** · P2 — S — Stripe SDK singleton has no boot guard for missing `STRIPE_SECRET_KEY`; silent empty-dashboard failure mode
    - **Where:** `app/Providers/AppServiceProvider.php`
    - **What to do:** Add production boot guard matching existing 4-guard pattern: `if (app()->isProduction() && empty(config('services.stripe.secret_key'))) { throw new RuntimeException(...); }`

- [ ] **#CFG-3** · P2 — M — `config/` fallback defaults contradict `.env.example`; `STRIPE_API_VERSION` has two different fallback values in `services.php` vs `partna.php`
    - **Where:** `config/database.php`, `config/queue.php`, `config/services.php`, `config/partna.php`, `config/session.php`
    - **What to do:** Align `DB_CONNECTION` default to `pgsql`, `QUEUE_CONNECTION` to `redis`, `SESSION_DRIVER` to `cookie`. Resolve `STRIPE_API_VERSION` split — pick one canonical value (`2025-02-24.acacia` per `.env.example`) and use it in both configs.

- [ ] **#CFG-4** · P2 — S — `NIGHTWATCH_ENABLED` defaults to `true` while every other feature flag defaults to `false`; causes connection-error noise on fresh deploys without a token
    - **Where:** `config/nightwatch.php`
    - **What to do:** Change default to `false`, or add boot guard: `if (production && NIGHTWATCH_ENABLED && empty(NIGHTWATCH_TOKEN)) throw RuntimeException`.

- [ ] **#QUEUE-2** · P2 — S — `FanOutBrandStatusNotificationJob` implements `ShouldBeUnique` without `$uniqueFor`; worker SIGKILL leaves permanent lock, silently blocking all future fan-outs for that brand-status pair
    - **Where:** `app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php`
    - **What to do:** Add `public int $uniqueFor = 600;`

- [ ] **#QUEUE-3** · P2 — S — `SendStaffBroadcastEmailsJob` same permanent-lock problem + misleading comment claiming "no explicit uniqueFor needed"
    - **Where:** `app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php`
    - **What to do:** Add `public int $uniqueFor = 600;` and correct the comment.

- [ ] **#QUEUE-4** · P2 — M — `ReconcileStuckShopifyIntegrationsJob` sequential HTTP calls (up to 200 × 10s each) may exhaust 600s timeout; no wall-clock guard
    - **Where:** `app/Jobs/Shopify/ReconcileStuckShopifyIntegrationsJob.php`
    - **What to do:** Record `$start = microtime(true)` before loop; if `microtime(true) - $start > ($this->timeout * 0.8)`, break early and log remaining-unprocessed count.

- [ ] **#RLS-1** · P2 — S — `site.public_site_payload` view returns zero rows for anonymous Supabase REST callers; `core.professionals` and `site.services` lack anon SELECT policies
    - **Where:** `supabase/migrations/20260403000000_v2_baseline.sql`
    - **What to do:** Add `CREATE POLICY professionals_anon_select ON core.professionals FOR SELECT TO anon USING (status = 'active' AND deleted_at IS NULL)` and equivalent for `site.services`.

- [ ] **#RLS-2** · P2 — S — `core.feature_flags` and `core.feature_flag_overrides` have no RLS enabled
    - **Where:** `supabase/migrations/202605180000000_create_feature_flags.sql`
    - **What to do:** `ENABLE ROW LEVEL SECURITY` + `app_backend` all-rows policy + staff-only SELECT policy.

- [ ] **#RLS-3** · P2 — S — `brand.signup_code_audit` has no RLS enabled; inconsistent with post-`harden_audit_tables` convention
    - **Where:** `supabase/migrations/20260522000001_create_brand_signup_code_audit.sql`
    - **What to do:** `ENABLE ROW LEVEL SECURITY` + `app_backend` policy + staff SELECT + brand-owner SELECT scoped by `brand_profile_id`.

- [ ] **#RLS-4** · P2 — S — `commerce.commission_export_audit` has no RLS enabled; contains financial PII (recipient emails, export scopes)
    - **Where:** `supabase/migrations/20260519200000_create_commerce_commission_export_audit.sql`
    - **What to do:** `ENABLE ROW LEVEL SECURITY` + `app_backend` policy + staff SELECT + tenant SELECT scoped by `professional_id`.

- [ ] **#SCALE-3** · P2 — M — `SiteCacheService::invalidateSite` materialises all affiliate IDs into memory and dispatches O(N) queue jobs per brand save
    - **Where:** `app/Services/Cache/SiteCacheService.php` — `invalidateSite()` final block
    - **What to do:** Replace per-affiliate dispatch with a single `InvalidateConnectedAffiliateCachesJob` that accepts `brand_professional_id` and internally `chunkById(500, ...)`. Or introduce brand-version cache token.

- [ ] **#SYNC-2** · P2 — S — Affiliate redirect non-deterministic when two `BrandPartnerLink` rows share the same `slot` value; KV entry may flap between brand storefronts
    - **Where:** `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:69–72`
    - **What to do:** Add `->orderBy('created_at')` as secondary sort after `->orderBy('slot')`.

- [ ] **#TEST-3** · P2 — S — `IndividualProfileController::filterBlockSettings()` allow-list has zero test coverage; future block types silently expand public exposure surface
    - **Where:** `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:153–167`
    - **What to do:** Unit test `filterBlockSettings` for known type (strips extra keys), unknown type (returns `[]`), and `bio` type (only `['title','body']`).

- [ ] **#TEST-5** · P2 — S — `PublicShopifyStorefrontController` has zero test coverage; 202 pending-token path, both resolution strategies, and TOCTOU dedup gap untested
    - **Where:** `app/Http/Controllers/Api/PublicSite/PublicShopifyStorefrontController.php`
    - **What to do:** Create `tests/Feature/PublicSite/PublicShopifyStorefrontControllerTest.php` covering `shop_domain` resolution, `brand_slug` resolution, pending 202 + dedup, 404 on missing integration, 422 on missing params.

- [ ] **#WEBHOOK-3** · P2 — S — Supabase MFA hook has no delivery dedup; retry from Supabase on timeout double-counts `verify_failed` events, potentially triggering false brute-force cooldown
    - **Where:** `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php:42–95`
    - **What to do:** After signature validation, `Cache::add($id, true, $windowSeconds)`; if returns false, return `['decision' => 'continue']` immediately. Match pattern used by all other webhook controllers.

- [ ] **#PROF-2** · P2 — S — Design settings sub-array passed to public individual profile response without key allowlist
    - **Where:** `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:64`
    - **Note:** PR #100 added the block-settings allow-list (PROF-1) but the design sub-array is returned via `IndividualProfileResource::$design` verbatim. Verify PR #100 also added a design key allowlist — if so, mark CLOSED.

---

- [ ] **#APIC-1** · P2 — S — `IndividualProfileController` extends bare `Controller` not `ApiController`; response shape has no `success` flag / standard envelope, breaking any client that checks `response.success`
    - **Where:** `app/Http/Controllers/Api/PublicSite/IndividualProfileController.php`
    - **What to do:** Change `extends Controller` to `extends ApiController`; use `$this->error(...)` / `$this->success(...)`. Verify Astro Worker parses updated envelope.

- [ ] **#APIC-2** · P2 — S — `StoreLinkBlockRequest` missing `live_check_enabled` per-site cap; create path bypasses guard that `UpdateLinkBlockRequest` enforces
    - **Where:** `app/Http/Requests/Api/Professional/Site/StoreLinkBlockRequest.php` — `withValidator()` missing clause vs `UpdateLinkBlockRequest.php:253-278`
    - **What to do:** Mirror the cap check from `UpdateLinkBlockRequest` into `StoreLinkBlockRequest::withValidator()`, omitting `$currentBlockId` exclusion (no bound block on create). `StaffStoreLinkRequest extends StoreLinkBlockRequest` — fixed for free.

- [ ] **#TRNX-3** · P2 — S — `ResumeProfessionalSubscriptionAction` makes Stripe API call inside `DB::transaction`; holds row lock for full network round-trip, risking connection pool exhaustion under concurrent requests
    - **Where:** `app/Services/Billing/ResumeProfessionalSubscriptionAction.php:47-53`
    - **What to do:** Move `billing->resumeSubscription()` outside the `DB::transaction` closure, using `DB::afterCommit()` for explicit sequencing. Webhook reconciliation already handles any post-commit Stripe failure.

- [ ] **#TRNX-4** · P2 — S — `CancelProfessionalSubscriptionAction` makes Stripe call before local DB update; if local `update` throws after Stripe succeeds, subscription state diverges until webhook reconciles
    - **Where:** `app/Services/Billing/CancelProfessionalSubscriptionAction.php:36-39`
    - **What to do:** Reverse order: local `$subscription->update(['cancel_at_period_end' => true])` first inside `DB::transaction`, then Stripe call outside. If Stripe fails after commit, webhook reconciles — add `Log::warning`.

- [ ] **#TRNX-5** · P2 — S — `ChangeProfessionalPlanAction` relies solely on async webhook for local `plan_id` reconciliation; entitlement gates return old tier in the interim window after a paid plan upgrade
    - **Where:** `app/Services/Billing/ChangeProfessionalPlanAction.php:60-66`
    - **What to do:** After `billing->updateSubscriptionPlan()` succeeds, immediately call `$subscription->update(['plan_id' => $newPlan->id, ...])` and `Entitlements::clearCache()`. Keep webhook path as defense-in-depth.

- [ ] **#OBS-1** · P2 — S — `AggregateCacheMetricsJob` has no `failed()` handler; retry exhaustion is invisible — the cache-metrics job itself is unmonitored
    - **Where:** `app/Jobs/Cache/AggregateCacheMetricsJob.php`
    - **What to do:** Add `failed(Throwable $e)` calling `report($e)` + `Log::error` with bucket key. Wrap `Redis::hGetAll` in try/catch.

- [ ] **#OBS-2** · P2 — S — `SyncStripeAccountStatusJob` has zero logging on all code paths; silent Stripe status drift is invisible until a payout fails
    - **Where:** `app/Jobs/Stripe/SyncStripeAccountStatusJob.php:50-62`
    - **What to do:** Add `Log::info` on successful sync (with `professional_id`, `duration_ms`) and on `not_connected` early-return.

- [ ] **#OBS-3** · P2 — S — `ProvisionBrandDnsTxtJob` has no logging in `handle()`; Shopify domain verification failures leave no trace
    - **Where:** `app/Jobs/Cloudflare/ProvisionBrandDnsTxtJob.php:55-57`
    - **What to do:** Add `Log::info` after successful `upsertTxt` with `professional_id`, `record_name`, `duration_ms`. Mirror sibling `ProvisionBrandDnsJob`.

- [ ] **#OBS-4** · P2 — M — Three daily sweep jobs (`InviteExpirySweepJob`, `NudgeStuckOnboardingJob`, `SendWeeklyAnalyticsNotificationJob`) emit no completion summary; operators can't distinguish healthy zero-item runs from scheduler failures
    - **Where:** respective `handle()` methods
    - **What to do:** Add `Log::info` with processed/sent counts at end of each job. Emit `Log::warning` on zero items processed.

- [ ] **#BRAND-2** · P2 — S — `SendBrandStatusNotificationJob` default arm silently sends wrong notification for unrecognised statuses with no log/alert
    - **Where:** `app/Jobs/Notifications/SendBrandStatusNotificationJob.php:101-111`
    - **What to do:** After fixing BRAND-1, change `default` arm to `Log::warning(...)` + `return` — fail loud, not silently wrong. Future enum additions surface immediately.

## P3 — Nice to have

- [ ] **#OBS-5** · P3 — S — `RetireSubdomainFromKvJob` double-logs every failure (catch block + `failed()` method); doubles alert noise vs every sibling Cloudflare job
    - **Where:** `app/Jobs/Cloudflare/RetireSubdomainFromKvJob.php:47-64`
    - **What to do:** Remove `Log::warning` from catch block; keep only `throw $e` + `failed()` as single log site. Mirror `RetireBrandDnsJob`, `SyncSubdomainToKvJob`.

- [ ] **#BRAND-3** · P3 — S — `SendBrandStatusNotificationJob` doesn't guard against brand being soft-deleted between fan-out dispatch and leaf execution; stale brand name sent to affiliates
    - **Where:** `app/Jobs/Notifications/SendBrandStatusNotificationJob.php:58-70`
    - **What to do:** Add `Professional::find($this->brandProfessionalId)` guard before the `match` block; log debug + return if null. Mirrors existing affiliate-side guard on line 58.

- [ ] **#ACCT-3** · P3 — S — Unused `BrandPartnerLinkService` injection + misleading `brand_id` docblock in `AccountTypeTransitionService`
    - Remove unused constructor param and `$context` param; update docblock.

- [ ] **#ACCT-4** · P3 — S — Case-sensitive `stripe_connect_status` comparison in `ToggleStripeRequirementBannerOnTransition` may show stale Stripe setup banner if value is `'Active'` not `'active'`
    - Normalise to `strtolower()` at comparison site or at write time in Stripe webhook handler.

- [ ] **#CFG-5** · P3 — M — ~30 operational tuning knobs in `config/partna.php` have no `.env.example` entry; incident-response discoverability gap
    - Sweep `config/partna.php` `env()` calls; add commented-out entries to `.env.example`.

- [ ] **#CFG-6** · P3 — S — `config/mail.php` default sender is `info@sight.com` / `Sight`; stale identity breaks SPF/DKIM alignment on fresh deploys
    - Change fallbacks to `hello@partna.au` / `Partna`.

- [ ] **#CFG-7** · P3 — S — `config/auth.php` configures dead-code session guard, Eloquent user model, and password reset flow under Supabase JWT auth
    - Strip to minimal stub with comment pointing to `VerifySupabaseJwt`.

- [ ] **#DB-14** · P3 — S — `VoidExpiredPayoutsJob::fireGraceWarnings` issues three near-identical queries (one per deadline tier) instead of one
    - Collapse to single `void_at <= now()->addDays(30)` query, bucket by `diffInDays` in PHP.

- [ ] **#DB-15** · P3 — S — `ProcessShopifyOrderWebhookJob` loads `$brandProfessional` twice — once in `process()`, once in `dispatchInstantPayoutIfEligible()`
    - Pass already-loaded model as parameter.

- [ ] **#PROF-4** · P3 — S — User-controlled handle embedded unescaped between colons in Redis key; produces ambiguous namespace tree in RedisInsight
    - Hash handle component: `md5($handleLc)`, or enforce colon ban in handle validation.

- [ ] **#QUEUE-5** · P3 — M — Media processing jobs document a "separate cleanup story" (cron for stuck `PROCESSING` rows) that hasn't been implemented
    - **Where:** `app/Jobs/ProcessImageVariantsJob.php`, `app/Jobs/ProcessVideoVariantsJob.php`
    - Implement scheduled command scanning `SiteMedia` rows in `PROCESSING` state with `updated_at` older than `max(timeout) + buffer`, transitioning to `FAILED`.

- [ ] **#RLS-5** · P3 — S — `analytics.lead_submissions` has RLS enabled but no policies; staff can't query via REST
    - Add staff SELECT policy.

- [ ] **#RLS-6** · P3 — S — `site.enquiries` has only `app_backend` policy; professional owner can't read their enquiries via REST
    - Add professional owner SELECT + staff SELECT policies.

- [ ] **#RLS-7** · P3 — S — `site.professional_handle_aliases` and `core.handle_change_log` have RLS enabled but no authenticated policies
    - Add staff SELECT to both; optionally professional owner SELECT for aliases.

- [ ] **#RLS-8** · P3 — S — `core.gdpr_requests` and `core.data_export_audit` have only `app_backend` policies; staff can't inspect via REST
    - Add staff SELECT to both.

- [ ] **#RLS-9** · P3 — S — `billing.webhook_events` has RLS enabled but no policies
    - Add staff SELECT + `app_backend` all-rows policy.

- [ ] **#RLS-10** · P3 — S — `analytics.cart_events` and `analytics.section_views` restrict to `service_role` only; outliers vs rest of analytics schema (staff SELECT)
    - Add `authenticated` staff SELECT policy matching sibling tables.

- [ ] **#RLS-11** · P3 — S — `notifications.broadcast_email_receipts` has no RLS enabled
    - `ENABLE ROW LEVEL SECURITY` + `app_backend` policy + staff SELECT.

- [ ] **#SCALE-2** · P3 — S — `MonitorManualRefundQueueJob` loads all stuck payouts without query limit; oversized log entry during mass Stripe outage
    - Add `->limit(200)` + separate `->count()` for "N more" suffix.

- [ ] **#TEST-6** · P3 — S — `DocumentPolicyEnforcementTest` missing owner-delete happy-path test; every other resource policy test file has this
    - Add `it('allows the owner to delete their own document')`.

- [ ] **#TEST-7** · P3 — S — `AuditTableHardeningTest` is fragile exact-text-match test; breaks on SQL reformatting with no behavioral change
    - Add coupling comment or switch to regex variant tolerating whitespace.

- [ ] **#WEBHOOK-4** · P3 — S — Shopify GDPR webhook accepts any validly-signed payload regardless of age; no timestamp staleness check
    - Check `X-Shopify-Triggered-At` header; reject (422) if older than configurable threshold (e.g. 5 min). Match pattern in `SupabaseAuthHookController`.

- [ ] **#WEBHOOK-5** · P3 — M — Fresha HMAC implementation is an acknowledged placeholder copied from Square; should be removed (booking/Fresha dropped 2026-05-11)
    - Remove `FreshaCatalogWebhookController` and its route entirely.

---

## Suggested Bundled Sessions

### Bundle A — Security / Auth (P1+P2, ~1 day)
SHOP-1, AUTH-1, AUTH-2, WEBHOOK-3

### Bundle B — Lifecycle Correctness (P1+P2, ~0.5 day)
ACCT-1, ACCT-2, TEST-1, TEST-2

### Bundle C — Database / KV Integrity (P1+P2, ~1 day)
MIG-1, DATA-1, SYNC-1, SYNC-2

### Bundle D — Job Queue Correctness (P1+P2, ~1 day)
QUEUE-1, QUEUE-2, QUEUE-3, QUEUE-4

### Bundle E — Cache Hygiene (P2, ~0.5 day)
CACHE-1, CACHE-2, CACHE-6, SCALE-3

### Bundle F — Config Hygiene (P1+P2, ~0.5 day)
CFG-1, CFG-2, CFG-3, CFG-4

### Bundle G — RLS Gaps (P2+P3, ~1 day)
RLS-1, RLS-2, RLS-3, RLS-4, RLS-5→11 (P3 sweep)

### Bundle H — Test Coverage (P1+P2, ~1.5 days)
TEST-1, TEST-2, TEST-3, TEST-4, TEST-5

### Bundle L — Brand Status Notifications (P1+P2, ~0.5 day)
BRAND-1, BRAND-2

### Bundle K — Observability (P2, ~0.5 day)
OBS-1, OBS-2, OBS-3, OBS-4

### Bundle J — Billing Atomicity (P2, ~0.5 day)
TRNX-3, TRNX-4, TRNX-5

### Bundle I — Scaling (P1+P2, ~0.5 day)
SCALE-1 (L — own session), SCALE-3, API-2=SCALE-1

### Standalone — do NOT bundle
- **SCALE-1 / #API-2** (L effort): BackfillBrandHasEnabledVariantsJob requires careful cursor design and Shopify rate-limit handling — own dedicated session.
- **TEST-4** (L effort): BootstrapController brand-attach coverage requires understanding the `&$brandSignupCodeError` partial-commit pattern — own session.

---

## Skipped Lenses (with rationale)

| Lens | Reason |
|------|--------|
| `data-integrity-and-privacy.md` | File does not exist in `scripts/audit/lenses/`. PII/privacy angle partially covered by `data-integrity` (DATA-1) and `security` (AUTH-1, SHOP-1) lenses. |
| `api-contract` (first pass) | Adjudication-side context overflow (~382K tokens + drafts > Sonnet limit). Retry in progress with tighter scope (PublicSite controllers + resources + requests only). |
| `observability` (first pass) | Same overflow. Retry in progress with tighter scope (Jobs/Cache + Jobs/Cloudflare + Jobs/Notifications + Jobs/Stripe + Middleware). |
| `transaction-boundaries` (first pass) | Same overflow. Retry in progress with tighter scope (Services/Accounts + Jobs/Exports + Services/Brand + Services/Billing). |
| `brand-status-recent-changes` (first pass) | Same overflow. Retry in progress with tighter scope (Services/Brand + Jobs/Notifications + Models/Brand + Observers). |

> Retry results from those 4 lenses will be appended below when complete.

---

## Appendix — Source Audit Files

| Lens | Output file |
|------|-------------|
| caching-gold-standard | `audit-2026-05-20-caching-gold-standard.md` |
| configuration-hygiene | `audit-2026-05-20-configuration-hygiene-env-vars-and-feature-flag-cl.md` |
| data-integrity | `audit-2026-05-20-data-integrity-and-model-consistency.md` |
| database-and-queue-scaling | `audit-2026-05-20-database-and-queue-scaling-antipatterns-n1-queries.md` |
| job-queue-correctness | `audit-2026-05-20-job-queue-correctness-retry-idempotency-uniqueness.md` |
| lifecycle-correctness | `audit-2026-05-20-lifecycle-correctness-account-type-transitions-eve.md` |
| migration-safety | `audit-2026-05-20-migration-safety-destructive-changes-index-gaps.md` |
| scaling-antipatterns | `audit-2026-05-20-scaling-antipatterns-memory-leaks-unbounded-collec.md` |
| schema-rls | `audit-2026-05-20-schema-rls-row-level-security-policies.md` |
| security | `audit-2026-05-20-security-auth-injection-feature-flags.md` |
| test-coverage | `audit-2026-05-20-test-coverage-gaps-and-edge-case-test-quality.md` |
| webhook-idempotency | `audit-2026-05-20-webhook-idempotency-replay-protection-hmac-verific.md` |
| public-individual-profile (retroactive) | `audit-2026-05-20-public-individual-profile-endpoint--security-data.md` |
| syncsubdomaintokvjob (retroactive) | `audit-2026-05-20-syncsubdomaintokvjob-branches-and-kv-writer-single.md` |
| api-contract | `audit-2026-05-20-api-contract-response-shape-and-validation-coverag-2.md` |
| observability | `audit-2026-05-20-observability-logging-metrics-instrumentation-2.md` |
| transaction-boundaries | `audit-2026-05-20-transaction-boundaries-atomicity-rollback-correctn-2.md` |
| brand-status-recent-changes | `audit-2026-05-20-brand-status-recent-changes-2.md` |
