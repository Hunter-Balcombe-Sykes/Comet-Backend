# Partna Phase 4 Database & Queue Scaling — Consolidated Remediation Plan

> **FROZEN as of 2026-05-12.** This plan is no longer the source of truth for status, regressions, or post-baseline annotations. Live status — including all changes from PR #12 onwards and any new findings introduced after this date — lives in `audits/MASTER-REMEDIATION-PLAN.md`. This file is preserved as provenance for `Original ID: Phase 4 Pattern X` references in the master plan. Do not edit; if a status change is needed, update the master instead.

**Date:** 2026-05-11
**Branch:** development
**Source:** 6 audits across `audits/phase-4-database/`, adjudicated by `claude-sonnet-4-6` over `deepseek-v4-pro` drafts
**Lens:** Database & queue scaling — N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure

## Summary

- **23 reported findings**, **22 unique** after deduplication (1 cross-audit duplicate — see matrix below)
- **Tier breakdown (reported):** 0 P0 · 6 P1 · 14 P2 · 3 P3
- **Tier breakdown (unique):** 0 P0 · 6 P1 · 13 P2 · 3 P3
- **Five foundational patterns close 20 of 22 unique findings** (6 P1 · 11 P2 · 3 P3)
- **2 standalone fixes** for the rest (1 P2 · 1 P3)
- **Two cross-phase dependencies** (one with Phase 2, one with Phase 3) — see "Cross-phase coordination" below
- **Estimated total:** ~6 days (1 to 1.5 weeks) of focused work to close all 22 findings

Phase 4 surfaces a different shape from Phases 1–3: the dominant problem is **external I/O held inside critical sections** (DB transactions, webhook handlers, request threads) — 5 of the 6 P1s. These are concurrency-pool risks that ship correct *functionality* today but degrade under load. The second cluster is **migration-safety convention** — currently low-risk on near-empty tables, becomes catastrophic post-launch. The third is **vendor API budget discipline** — protecting Shopify Admin/Storefront/GitHub rate-limit budgets across all call sites.

## Cross-audit duplicates (collapse on fix)

| Finding | Audits | Same root cause |
|---------|--------|-----------------|
| `CREATE INDEX` without `CONCURRENTLY` and `ADD CONSTRAINT`/`SET NOT NULL` without `NOT VALID` inside `BEGIN`/`COMMIT` blocks | DB-A#SCALE-1 (P2) ≡ DB-C#SCALE-1 (P2) + DB-C#SCALE-2 (P2) | Pattern 2 |

DB-A#SCALE-1 bundles two distinct DDL antipatterns under one finding (indexes + CHECK constraints + inline `UPDATE` backfills on `commission_payouts`). DB-C splits them: `SCALE-1` covers index locking across five migrations, `SCALE-2` covers constraint/`NOT NULL` validation across six. **Take the DB-C split as canonical** — it identifies the broader migration set and names the one already-correct example (`20260424120000_add_live_check_index.sql`) as the model to follow. DB-A#SCALE-1 is fully absorbed into Pattern 2.

**Related (overlapping scope, distinct prescriptions — bundle the PR):**

| Findings | Why bundle |
|----------|------------|
| DB-D#SCALE-1 (BrandDesignImporter Storefront API) + DB-D#SCALE-3 (ShopifyAdminClient `usleep` blocking) | Both extend the Shopify rate-limit machinery; D#1 adds Storefront to the budget surface, D#3 changes the Admin retry strategy. Extracting `ShopifyStorefrontClient` (D#1) and reworking `ShopifyAdminClient::graphql()` (D#3) lands cleaner as one PR. |
| DB-F#SCALE-1 (EmbeddedProductSettingsController N+1) + DB-D#SCALE-3 (Admin retry rework) | F#1 is the highest-volume caller of the Admin client; reworking it to use `metafieldsSet` bulk mutations + cost-header awareness happens in lockstep with D#3's retry/throttle refactor. |
| DB-F#SCALE-5 (EmbeddedSetupController DNS in-request) + Phase 3 SCALE-B#CACHE-3 (EmbeddedSetupController::overview commission migration) | **Cross-phase** — same controller file, different methods. Phase 3 fixes `overview()` (lines 316-371); Phase 4 fixes `setupDomain()` and `provisionDomainTxt()` (lines 337-385). One PR cleans the file once. |
| DB-F#SCALE-6 (EmbeddedProductAnalyticsController `resolveActive` catalog fetch) + Phase 3 SCALE-B#CACHE-6 (`EmbeddedProductAnalyticsController::show()` `rememberLocked` wrap) + Phase 3 Pattern 1 Step 2 (`BrandCatalogService::fetchBrandCatalog` migration) | **Cross-phase** — three findings on the same call chain. Phase 3 Step 2 hardens the herd on `fetchBrandCatalog`; Phase 3 Step 4 wraps `show()` properly; Phase 4 D#6 eliminates the per-call-miss full-catalog fetch entirely by caching the `active` flag. Land Phase 3 first; Phase 4 D#6 becomes simpler once Pattern 1 is in. |
| DB-F#SCALE-7 (StaffStatsController partial index) + Pattern 2 | The two new partial indexes (`idx_cm_pending_amount` and `idx_subscriptions_active_count`) are the first user-facing exercise of the Pattern 2 convention. Their migration is the proof artifact that the convention works. |

## Cross-phase coordination

Two Phase 4 findings have prerequisite or extending work in earlier phases. Both are still **unshipped** as of this plan (verified: Cloudflare jobs in `app/Jobs/Cloudflare/*` have `$tries = 3` only — no `$backoff`, `$timeout`, or `failed()`).

| Phase 4 finding | Cross-phase dependency | Sequencing |
|-----------------|------------------------|------------|
| DB-C#SCALE-3 (Cloudflare DNS/KV jobs hygiene) | **Phase 2 Pattern C** adds `$backoff = [10, 30, 60]` to all four Cloudflare jobs and proposes a `HasCloudflareRetryPolicy` trait. | Land Phase 2 Pattern C **first**; Phase 4 then extends the trait/jobs with `$timeout = 30`, `failed(\Throwable $e)`, and `$this->onQueue('integrations')`. Treat as one rolling sweep rather than two passes — fold into a single PR if Phase 2 Pattern C hasn't shipped by the time Phase 4 begins. |
| DB-F#SCALE-6 (EmbeddedProductAnalyticsController `resolveActive`) | **Phase 3 Pattern 1 Step 2** migrates `BrandCatalogService::fetchBrandCatalog()` from `Cache::memo()->remember` to `rememberLocked`. **Phase 3 Pattern 1 Step 4** wraps `EmbeddedProductAnalyticsController::show()` with `rememberLocked`. | Land Phase 3 Pattern 1 first. Phase 4 D#6's "cache the `active` flag locally" fix then becomes a clear improvement on a stable base, rather than a fix on top of a thundering-herd surface. |
| DB-F#SCALE-5 (EmbeddedSetupController DNS in-request) | **Phase 3 Pattern 2** rewrites `EmbeddedSetupController::overview()` to read from `commerce.orders`. | Same file, different methods, no ordering dependency — but the diff is cleaner if one developer touches both methods in one PR. |

## Source audit files

- `audit-2026-05-11--database--queue-scaling-n1-unbounded-reads-connec.md` (**DB-A**: 1 P2, 2 P3)
- `audit-2026-05-11--database--queue-scaling-n1-unbounded-reads-connec-3.md` (**DB-B**: 3 P2)
- `audit-2026-05-11--database--queue-scaling-n1-unbounded-reads-connec-4.md` (**DB-C**: 3 P2)
- `audit-2026-05-11--database--queue-scaling-n1-unbounded-reads-connec-5.md` (**DB-D**: 2 P1, 3 P2, 1 P3)
- `audit-2026-05-11--database--queue-scaling-n1-unbounded-reads-connec-7.md` (**DB-E**: 1 P2)
- `audit-2026-05-11--database--queue-scaling-n1-unbounded-reads-connec-8.md` (**DB-F**: 4 P1, 3 P2)

---

# Part 1 — Foundational fixes

Pattern 1 ships first because three P1 webhook handlers are **currently held open across vendor I/O** — Stripe's 10-second webhook deadline and Shopify's retry policy both penalise the current pattern under load. Pattern 4 ships second because DB-D#SCALE-1 is a P1 silent data bug analogous to Phase 3's commission-migration miss (Storefront throttles silently drop logo/colour/slogan data from brand installs). After those two, the remaining order is by fix-leverage: smallest sweep first.

**Order:** Pattern 1 (P1 webhook + transaction critical sections) → Pattern 4 (P1+P2 vendor API budget discipline) → Pattern 5 (P1+P3 N+1 sweep) → Pattern 3 (P2 queue job hygiene) → Pattern 2 (P2 migration convention).

## Pattern 1 — Move external I/O out of critical sections

**Closes 5 unique findings (3 P1 · 2 P2):** DB-F#SCALE-3, DB-F#SCALE-4, DB-F#SCALE-5, DB-D#SCALE-2, DB-E#SCALE-1

**Effort:** ~2 days

### Root cause

Five call sites issue synchronous external I/O (HTTP to Cloudflare/Stripe, R2 PUT, single-statement unbounded DELETE) while holding a shared resource open — a Postgres row lock, a Postgres connection slot, a PHP-FPM worker waiting on Stripe's webhook deadline, or a Horizon worker waiting on Shopify's webhook deadline. The blast radius scales with vendor latency: every concurrent caller for the same resource queues behind the I/O.

| Call site | Critical section | I/O held inside |
|-----------|------------------|-----------------|
| `StripeConnectWebhookController::handleCheckoutSessionCompleted()` (setup mode) | Stripe webhook handler (10s deadline) | `StripeConnectService::syncPaymentMethodFromCheckoutSession()` → `Session::retrieve($sessionId)` |
| `ShopifyAppUninstalledWebhookController::__invoke()` | Shopify webhook handler | Single-statement `DELETE FROM commerce.affiliate_product_selections WHERE brand_professional_id = ?` (potentially 10K rows, table-level row-lock scan) |
| `EmbeddedSetupController::setupDomain()` and `::provisionDomainTxt()` | Embedded wizard request thread + PHP-FPM worker | Cloudflare DNS API (`upsertCname`, `upsertTxt`) — 200–500ms typical |
| `StripeConnectService::creditWalletFromCheckoutSession()` | `DB::transaction(fn () => …)` with `Professional::lockForUpdate()` on the brand row | `$this->stripe->refunds->create(...)` — Stripe API round-trip held inside row lock |
| `ProfessionalDocumentController::storeDocument()` | `DB::transaction(fn () => …)` with `pg_advisory_xact_lock` | `Storage::disk($mediaDisk)->put(...)` to Cloudflare R2 (10MB PDFs, 50–500ms) |

The fix shape is consistent across all five: shrink the critical section to the minimum atomic DB work, release the lock/connection/worker, and perform vendor I/O after. For the two webhook handlers, the vendor-side action belongs in a queue job. For the two transactions, the row lock or advisory lock releases before the I/O runs and the I/O failure is recovered via a flag column or orphan-cleanup path the codebase already uses. `BrandGalleryController::upload()` is the established reference for the R2-outside-transaction shape.

### What to do

- [ ] **Step 1 — Dispatch `SyncPaymentMethodFromCheckoutSessionJob` from `StripeConnectWebhookController`** (`app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php:163–181`).
    - Replace the synchronous `$service->syncPaymentMethodFromCheckoutSession($professional, $session->id)` call inside the `match ($session->mode)` arm with `SyncPaymentMethodFromCheckoutSessionJob::dispatch($professional->id, $session->id)`.
    - Job class config: `$tries = 3`, `$backoff = [5, 15, 30]`, `$timeout = 30`, `failed(\Throwable $e)` handler that `report($e)` plus logs `professional_id` + `checkout_session_id` for ops follow-up. Place on the `integrations` queue (vendor I/O category).
    - Return `response()->json(['received' => true])` immediately after the existing `WebhookEvent` idempotency guard. Stripe's webhook deadline is now bounded by the controller, not by Stripe's own API.
    - Closes **DB-F#SCALE-4** (P1).
- [ ] **Step 2 — Dispatch `PurgeAffiliateProductSelectionsJob` from `ShopifyAppUninstalledWebhookController`** (`app/Http/Controllers/Api/Webhooks/ShopifyAppUninstalledWebhookController.php:99–101`).
    - Remove the inline `AffiliateProductSelection::query()->where('brand_professional_id', ...)->delete()` after `$integration->update(...)`.
    - Job class deletes in chunks: `AffiliateProductSelection::where('brand_professional_id', $brandId)->chunkById(500, fn ($chunk) => $chunk->each->delete())`. Per-chunk locks; full deletion still completes within seconds for 10K rows but no lock is held longer than 500-row batch.
    - Job config: `$tries = 3`, `$backoff = [30, 90, 300]`, `$timeout = 60`, `failed()` reports. Place on `integrations` queue.
    - Return `$this->success(['received' => true])` immediately.
    - Closes **DB-F#SCALE-3** (P1).
- [ ] **Step 3 — Use `ProvisionBrandDnsJob` + create `ProvisionBrandDnsTxtJob`** for `EmbeddedSetupController` (`app/Http/Controllers/Api/Internal/EmbeddedSetupController.php` — `setupDomain()` lines 337–352, `provisionDomainTxt()` lines 371–385).
    - `setupDomain()`: replace `new CloudflareDnsService; $dns->upsertCname(...)` with `ProvisionBrandDnsJob::dispatch($professionalId)`. The local DB write of `oxygen_storefront_id` stays in-request (local-only, no I/O). **`ProvisionBrandDnsJob` already exists** and is correct for this use case — the wizard simply wasn't wired to it.
    - `provisionDomainTxt()`: create new `app/Jobs/Cloudflare/ProvisionBrandDnsTxtJob.php` wrapping `CloudflareDnsService::upsertTxt($recordName, $txtValue)`. Same retry/timeout/failed shape as `ProvisionBrandDnsJob`.
    - Add a per-brand debounce on each dispatch path: `Cache::add("dns:provision:{$professionalId}", true, 30)` returns false → skip dispatch. Mirrors the pattern in `ShopifyBulkOperationLock`.
    - Wizard UX: have the frontend poll `/embedded/domain-status` (reads from DB, not Cloudflare) for completion instead of awaiting the response. Surface DNS state via the existing `oxygen_storefront_id` + a new `dns_status` column on `brand_store_settings` (`pending` → `provisioned` → `verified`).
    - Closes **DB-F#SCALE-5** (P2).
- [ ] **Step 4 — Close `DB::transaction` before Stripe refund in `StripeConnectService`** (`app/Services/Stripe/StripeConnectService.php` — `creditWalletFromCheckoutSession()`).
    - Restructure the closure so the transaction body **only** loads the brand row with `lockForUpdate()`, reads `walletCurrency`, and returns either `(currency_matches: true, ...)` or `(currency_matches: false, payment_intent_id: ..., refund_metadata: [...])`. The transaction commits and releases the row lock at this point.
    - Outside the closure, branch on `currency_matches`. On mismatch, call `$this->stripe->refunds->create(...)` with the existing idempotency key.
    - On Stripe API failure post-release: log `Log::critical(...)` and `report($e)` so Nightwatch surfaces it; mark the `wallet_movements` row as `needs_manual_refund = true` (add column if not already present) so ops can action it. Mirror the manual-refund pattern from `CommissionPayoutService`.
    - Closes **DB-D#SCALE-2** (P1).
- [ ] **Step 5 — Move R2 PUT outside `DB::transaction` in `ProfessionalDocumentController`** (`app/Http/Controllers/Api/Professional/ProfessionalDocumentController.php:85–148`).
    - Reference implementation: `BrandGalleryController::upload()` already follows the correct pattern — review and mirror.
    - Inside the transaction: keep `pg_advisory_xact_lock`, the existing soft-delete of the prior document row, and the `SiteMedia` insert with `path: ''`. End the transaction here.
    - Outside the transaction: perform `Storage::disk($mediaDisk)->put($path, $stream, 'public')` and then `$media->update(['path' => $path])`. The empty-path row is the serialization token — the advisory lock has already released, but the row exists so concurrent flat-replace requests find it.
    - Keep the existing `$newUploadedPath` orphan-cleanup logic in the outer `catch` block; it remains correct (and arguably more useful) when the upload runs outside the transaction.
    - Closes **DB-E#SCALE-1** (P2).
- [ ] **Step 6 — Add the existing-job + new-job sanity-check pattern to the team conventions.**
    - DB-F#SCALE-5 highlighted that `ProvisionBrandDnsJob` already existed but a controller bypassed it. Phase 4's audit insight (DB-F closing remarks) calls this out as a recurring antipattern.
    - Add a one-line note to `CLAUDE.md` (or a `docs/conventions/queue-jobs.md` if one exists): "Before proposing a new queue job, `rg --files-with-matches "<vendor>Service" app/Jobs/` to confirm an analogous job doesn't already exist."
    - Not a code fix; an institutional one. Mark as `[ ]` for completeness.

### Plain English

Five spots in the code hold something open — a database connection, a database row, the line to Stripe's webhook server — while they finish a slow phone call to another company's API. The slow phone call is fine; the "holding the line" part is the problem. When 20 brands try the same action at once, they all queue up behind the slow phone call, and the queue grows faster than it drains. The fix in every case is the same shape: do the quick paperwork first, hang up the resource, *then* make the slow call. Two of the five are webhook handlers — those need the slow call to happen in a background job entirely, so we can say "received" to the vendor immediately. Two are database transactions — we just need to close the transaction before the vendor call starts. One is a file-upload to Cloudflare R2 that already has a working pattern in a sibling controller; we just need to copy the shape.

### Why this is highest priority

All three P1s in this pattern ship correct *functionality* today but degrade under any concurrent load:
- **DB-F#SCALE-4** (Stripe webhook): exceeds Stripe's 10s deadline → Stripe retries → cascade
- **DB-F#SCALE-3** (Shopify uninstall): table-locks `commerce.affiliate_product_selections` during webhook → blocks concurrent product-sync webhooks
- **DB-D#SCALE-2** (Stripe refund inside transaction): row-locks `professionals` for Stripe API duration → starves connection pool on currency-mismatch top-ups

Phase 4 currently runs without customers; these don't *show* as bugs in Nightwatch yet. They will the day after pilot launch. The cost of fixing them while no traffic is on them is low; the cost of fixing them while customers are watching is high.

---

## Pattern 4 — Vendor API budget discipline

**Closes 5 unique findings (2 P1 · 3 P2):** DB-D#SCALE-1, DB-D#SCALE-3, DB-D#SCALE-4, DB-F#SCALE-1, DB-F#SCALE-6

**Effort:** ~2 days

### Root cause

Three vendor APIs (Shopify Admin, Shopify Storefront, GitHub Actions) have rate-limit/cost budgets shared across all call sites for the same tenant. Partna's Shopify Admin client has excellent rate-limit machinery (`ShopifyBudgetTracker` Lua atomic bucket + `ShopifyCostTracker` sliding-window cost learning + `ShopifyThrottledException` retry chain), but **call sites still drain the budget faster than necessary**:

| Call site | Vendor budget drained | Why |
|-----------|----------------------|-----|
| `BrandDesignImporter::fetchBrand()` | Shopify Storefront API | Bypasses `ShopifyAdminClient` entirely — direct `Http::withHeaders()->post()`. Throttled responses silently return `emptyBrand()` → logo/colours/slogan dropped from install. |
| `ShopifyAdminClient::graphql()` THROTTLED path | Worker capacity (and indirectly budget) | `usleep($wait * 1000)` blocks worker for up to 15s/job during throttle. Three concurrent throttled jobs → entire `integrations` supervisor stuck. |
| `EmbeddedProductSettingsController` GET + PATCH | Shopify Admin points (~1000/s shared per-store) | GET fires 1 Admin + 2 Storefront calls; PATCH `saveVariantEnabledStates()` calls `fetchVariants()` redundantly (data already in `fetchProductMetafields()` response), then per-variant mutations (up to 50 individual `metafieldSet` calls). `saveMetafield()` two-call pattern (read existing → update/create). |
| `EmbeddedProductAnalyticsController::resolveActive()` | Shopify Admin points | Full `fetchBrandCatalog()` (paginated GraphQL) to resolve **one** product's `active` boolean. On cache miss + concurrent brand traffic → thundering herd of full-catalog fetches. |
| `HydrogenDeploymentService::dispatchDeployment()` | GitHub Actions rate limit (5K req/hr per token, shared across all brands) | No per-brand debounce; wizard auto-save fires multiple identical workflow dispatches. |

The unifying fix is **make every vendor call go through the budget-aware path, batch where the vendor supports it, and avoid pulling whole collections to answer scalar questions**. Phase 4 also exposed that the THROTTLED retry should bubble up to the queue's `backoff()` (already wired on every Shopify job — `[10, 30, 60]`) instead of being absorbed in-process.

### What to do

- [ ] **Step 1 — Extract `ShopifyStorefrontClient` and migrate `BrandDesignImporter::fetchBrand()`** (`app/Services/Shopify/BrandDesignImporter.php`).
    - Create `app/Services/Shopify/Client/ShopifyStorefrontClient.php` mirroring `ShopifyAdminClient`'s shape: budget pre-acquire, cost tracking (Storefront uses a separate points system — track separately), and `ShopifyThrottledException` on `THROTTLED` extension code (same Storefront response shape as Admin GraphQL).
    - Replace the inline `Http::withHeaders($storefrontToken)->post(...)` in `fetchBrand()` with `$this->storefront->graphql($shopDomain, $storefrontToken, self::STOREFRONT_BRAND_QUERY)`.
    - Crucially: **stop swallowing `$errors` into `emptyBrand()`**. On `THROTTLED`, throw `ShopifyThrottledException` and let the install job's existing `backoff()` chain handle retry. On other errors, throw `ShopifyGraphQLException` so the job fails loudly rather than installing a no-logo brand.
    - Register the Storefront query hash with `ShopifyCostTracker` so cost learning applies to future syncs.
    - Closes **DB-D#SCALE-1** (P1 silent data bug).
- [ ] **Step 2 — Rework `ShopifyAdminClient::graphql()` THROTTLED path to bubble to queue `backoff()`** (`app/Services/Shopify/Client/ShopifyAdminClient.php`).
    - On the first `THROTTLED` response, immediately throw `ShopifyThrottledException` — do not `usleep` and retry in-process. Every production Shopify job already defines `backoff()`, so the queue handles retry delay across workers.
    - Keep at most **one** immediate in-process retry without sleep, to absorb single-packet transient blips (where the bucket refills before a queue round-trip).
    - In `preAcquireBudget()`, after the first `usleep()` + retry, accept the deficit on second-miss and proceed — the `THROTTLED` response path already handles over-commitment correctly.
    - Closes **DB-D#SCALE-3** (P2 worker-pool starvation).
- [ ] **Step 3 — Refactor `EmbeddedProductSettingsController` to batch and reuse** (`app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php`).
    - `saveVariantEnabledStates()` (lines 473–489): accept the variant list as a parameter from the caller (which already obtained it via `fetchProductMetafields()`); delete the redundant `fetchVariants()` call.
    - Replace the per-variant `saveVariantMetafield()` loop with a single `metafieldsSet` bulk mutation. Shopify's `metafieldsSet` accepts an array of `{ownerId, namespace, key, type, value}` triples and upserts by owner+namespace+key — no need to look up existing IDs first.
    - `saveMetafield()` (lines 358–468): replace the two-call (query-then-create/update) pattern with a single `metafieldsSet` mutation. `metafieldsSet` upserts.
    - `show()` (lines 88–141): keep the current 3-call shape for now (collections lookups have different cache lifecycles); revisit if Shopify GraphQL exposes a combined query node. Mark with a `// TODO: collapse to single GraphQL operation once Shopify schema permits` comment so the next reader understands the deliberate choice.
    - Track `X-Shopify-Graphql-Cost` response headers and surface budget exhaustion as 429 with `Retry-After` to the embedded client (uses existing `ShopifyBudgetTracker` headers).
    - Closes **DB-F#SCALE-1** (P1 rate-limit drain).
- [ ] **Step 4 — Eliminate full-catalog fetch in `EmbeddedProductAnalyticsController::resolveActive()`** (`app/Http/Controllers/Api/Internal/EmbeddedProductAnalyticsController.php:161–180`).
    - **Sequencing note:** Phase 3 Pattern 1 Step 4 wraps `show()` with `rememberLocked`; Phase 3 Pattern 1 Step 2 hardens `fetchBrandCatalog()`. Land both before this fix — otherwise the interim `resolveActive()` thundering-herd is left worse.
    - **Preferred (long-term):** mirror the `sidest.active` metafield to a local column on `commerce.order_items` or a dedicated `brand_product_states` table, populated by the catalog sync job. `resolveActive()` becomes `DB::table('...')->where('shopify_product_id', $productId)->where('brand_professional_id', $professionalId)->value('active')`.
    - **Minimum (P2 close):** cache the active flag independently under `embedded:product-active:{professionalId}:{productId}` (10m TTL, `rememberLocked`), populated lazily on miss. This is one-product scope per cache miss, not full-catalog scope.
    - Either path eliminates the per-call-miss full-catalog fetch.
    - Closes **DB-F#SCALE-6** (P2).
- [ ] **Step 5 — Add per-brand debounce to `HydrogenDeploymentService::dispatchDeployment()`** (`app/Services/Hydrogen/HydrogenDeploymentService.php`).
    - At the top of the method: `if (! Cache::add("hydrogen:deploy:debounce:{$professionalId}", true, 60)) { Log::info('HydrogenDeployment: debounced rapid dispatch', [...]); return; }`.
    - The 60-second window collapses any wizard auto-saves into a single deploy per minute per brand. GitHub's rate-limit budget is preserved for other brands.
    - Closes **DB-D#SCALE-4** (P2).

### Plain English

Three outside companies (Shopify Admin, Shopify Storefront, GitHub) put a cap on how many times we can knock on their door per second. Partna's main door-knocking system (for Shopify Admin) is excellent — it counts knocks, slows down before they kick us out, and waits its turn during traffic. But five specific spots in the code bypass that system or use it inefficiently:

1. The Shopify Storefront API has its own door, and one importer goes straight there with no counter. Worse, when Shopify says "slow down," that importer silently records "this brand has no logo" and moves on — every brand who installs during a Shopify slowdown gets a logo-less store.
2. When Shopify slows us down, the worker that hit the wall just sits and waits up to 15 seconds before trying again — instead of putting the job back in the queue and letting the queue's "try again in 10 seconds" feature handle it.
3. The product settings panel in the Shopify admin sidebar asks Shopify the same question twice when saving, then sends individual save messages for each of (up to 50) variants — even though Shopify accepts batch-save messages.
4. The product analytics panel asks "is this one product active?" by downloading the brand's entire product catalog from Shopify. Caching helps on the second viewer, but the first viewer after any cache clear triggers a full download.
5. The deployment wizard fires off a GitHub deploy every time the user clicks Save — three saves in 30 seconds means three GitHub deploys.

The fixes are: route Storefront through the same counter system, batch Shopify saves, store the "active" flag locally instead of asking Shopify each time, and add a 60-second cooldown between deploys.

### Why this is the second-priority pattern

DB-D#SCALE-1 is a P1 silent data bug currently shipping logo-less brand installs whenever Shopify Storefront API is degraded — same class of failure as Phase 3 Pattern 2's silent commission zeroes. DB-F#SCALE-1 is the highest-volume Shopify API consumer in the codebase; once pilot brands are using the embedded panel, an over-budget event there starves background webhook processing for *all* brands on the same Shopify store. The remaining three are P2 but compound — Pattern 4 ships them together because the budget-discipline mental model applies uniformly.

---

## Pattern 5 — N+1 / lazy-load defence sweep

**Closes 3 unique findings (1 P1 · 2 P3):** DB-F#SCALE-2, DB-A#SCALE-2, DB-A#SCALE-3

**Effort:** ~0.5 day

### Root cause

One actual N+1 in `HydrogenDeploymentController::targets()` (DB-F#SCALE-2) plus two defensive guards on resource/model methods that already work today but assume an eager-load contract their callers may forget tomorrow. The actual N+1 is the immediate concern; the defensive guards are the institutional one — the canonical Laravel pattern (`whenLoaded` / `relationLoaded`) is used everywhere else in the codebase, but two surfaces predate the convention.

### What to do

- [ ] **Step 1 — Fix the active N+1 in `HydrogenDeploymentController::targets()`** (`app/Http/Controllers/Api/Internal/HydrogenDeploymentController.php:44–57`).
    - Replace the per-row `ProfessionalIntegration::query()->where('professional_id', $row->professional_id)->where('provider', ...)->value('shopify_shop_domain')` lookup inside `$settings->map(...)` with a single bulk pluck:
        ```php
        $shopDomains = ProfessionalIntegration::query()
            ->whereIn('professional_id', $settings->pluck('professional_id'))
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->pluck('shopify_shop_domain', 'professional_id');
        ```
    - In the `map()`, use `$shopDomains[$row->professional_id] ?? null` instead of the inline query.
    - 201 queries → 2 queries at 200 brands.
    - Closes **DB-F#SCALE-2** (P1).
- [ ] **Step 2 — Add `whenLoaded` guard to `SubscriptionResource`** (`app/Http/Resources/SubscriptionResource.php:17–25`).
    - Replace the inline `'plan' => [...]` block with `$this->whenLoaded('plan', fn () => [...])`. The key is omitted on miss rather than silently triggering a lazy load.
    - All four current callers (`SubscriptionController::show()` + three `StaffSubscriptionManagementController` mutation actions) eager-load via `->with('plan')` or `->load('plan')` — verified. This is defence-in-depth for future callers, not a fix for an active bug.
    - Closes **DB-A#SCALE-2** (P3).
- [ ] **Step 3 — Add `relationLoaded` guard to `SiteMedia::variantUrls()`** (`app/Models/Core/Site/SiteMedia.php:122–127`).
    - Insert at top of method:
        ```php
        if (! $this->relationLoaded('mediaVariants')) {
            return [];
        }
        ```
    - Update the docblock: "Caller must eager-load `mediaVariants` before calling this method. Returns empty array if relation is not loaded (machine-checkable contract)."
    - All 9 current callers (verified in DB-A#SCALE-3) honour the contract.
    - Closes **DB-A#SCALE-3** (P3).
- [ ] **Step 4 — Test coverage.** Step 1's bulk pluck has a behavioural contract: brands with no Shopify integration must still appear in the response with `shop_domain: null`. Add a Pest test fixture to `tests/Feature/Hydrogen/HydrogenDeploymentControllerTest.php` (create the file if it doesn't exist) covering: (a) all-with-Shopify case, (b) mixed case, (c) all-without-Shopify case. Steps 2 + 3 are defensive — existing tests cover them so long as no test fixtures lazy-load.

### Plain English

Most of the codebase uses a Laravel safety check called `whenLoaded` that says "only include this related data if someone bothered to fetch it upfront." Two spots forgot the check. They work today because every current caller happens to fetch upfront — but the next developer to use them won't have any signal if they forget, and the page will silently get slower instead of failing. Adding the safety check makes forgetting the rule obvious during testing instead of slow in production.

There's also one place that has the actual problem the safety check is designed to catch: the deployment-pipeline endpoint asks the database 200 separate times "what's this brand's Shopify domain?" — once per brand. That's the one we fix immediately; the other two are checklist items.

### Why this is the third-priority pattern

DB-F#SCALE-2 is a P1 active N+1 with a clear fix (~30 minutes). The two P3 guards are 5-minute changes each. The whole pattern is small, mechanical, and unblocks no other work — so it slots in after Pattern 1's webhook safety work and Pattern 4's vendor discipline. Land it in a day; reviewer fatigue is low.

---

## Pattern 3 — Queue job hygiene sweep

**Closes 5 unique findings (5 P2):** DB-B#SCALE-9, DB-B#SCALE-10, DB-B#SCALE-11, DB-C#SCALE-3, DB-D#SCALE-5

**Effort:** ~0.5 day (after Phase 2 Pattern C lands)

### Root cause

Five queue jobs are missing one or more hygiene properties (`$tries`, `$backoff`, `$timeout`, `failed()`). Laravel's defaults are wrong for all five contexts — `$tries = 1` on `default` supervisor silently drops cache jobs; `$backoff = 0` produces instant retry storms during vendor degradations; missing `$timeout` lets jobs hang or get killed by supervisor defaults the job author never tuned. Phase 2 Pattern C already proposes the `$backoff` half of this fix for the four Cloudflare jobs; Phase 4 extends to four other jobs and rounds out the remaining three properties on the Cloudflare jobs themselves.

| Job | Queue | Missing | Phase 4 finding |
|-----|-------|---------|------------------|
| `SendEnquiryNotificationJob` | `notifications` | `$backoff`, `$timeout`, `failed()` | DB-B#SCALE-9 |
| `InvalidateConnectedAffiliateCachesJob` | `default` (supervisor default `$tries = 1`) | `$tries`, `$backoff`, `$timeout`, `failed()` | DB-B#SCALE-10 |
| `WarmPublicSiteCacheJob` | `default` (supervisor default `$tries = 1`) | `$tries`, `$backoff`, `$timeout`, `failed()` | DB-B#SCALE-10 |
| `ProcessShopifyOrderWebhookJob` | `integrations` | `$backoff` (others present) | DB-B#SCALE-11 |
| `ProcessShopifyOrderUpdatedWebhookJob` | `integrations` | `$backoff` (others present) | DB-B#SCALE-11 |
| `ProvisionBrandDnsJob` | `default` (should be `integrations`) | `$backoff` (Phase 2 Pattern C), `$timeout`, `failed()`, queue routing | DB-C#SCALE-3 + Phase 2 Pattern C |
| `RetireBrandDnsJob` | `default` (should be `integrations`) | `$backoff` (Phase 2 Pattern C), `$timeout`, `failed()`, queue routing | DB-C#SCALE-3 + Phase 2 Pattern C |
| `SyncSubdomainToKvJob` | `default` (should be `integrations`) | `$backoff` (Phase 2 Pattern C), `$timeout`, `failed()`, queue routing | DB-C#SCALE-3 + Phase 2 Pattern C |
| `ProcessVideoVariantsJob` | `videos` on `redis_video` | `$timeout` (660s max FFmpeg encoding) | DB-D#SCALE-5 |

### What to do

- [ ] **Step 1 — Confirm Phase 2 Pattern C status.** If `$backoff = [10, 30, 60]` and the `HasCloudflareRetryPolicy` trait have not landed on the four Cloudflare jobs (`ProvisionBrandDnsJob`, `RetireBrandDnsJob`, `RetireSubdomainFromKvJob`, `SyncSubdomainToKvJob`) — verify with `rg "backoff" app/Jobs/Cloudflare/` — fold Phase 2 Pattern C into this PR. **As of 2026-05-11, none of the Cloudflare jobs have `$backoff` set.**
- [ ] **Step 2 — Add `$tries = 3`, `$backoff = [5, 15, 30]`, `$timeout = 10`, `failed()` to cache jobs.**
    - `app/Jobs/Cache/InvalidateConnectedAffiliateCachesJob.php`
    - `app/Jobs/Cache/WarmPublicSiteCacheJob.php`
    - `failed()` calls `report($e)` so a persistent Redis failure surfaces in Nightwatch.
    - Closes **DB-B#SCALE-10**.
- [ ] **Step 3 — Add `$backoff = [30, 90, 180]`, `$timeout = 30`, `failed()` to `SendEnquiryNotificationJob`** (`app/Jobs/Notifications/SendEnquiryNotificationJob.php`).
    - `failed()` calls `report($e)` and logs `enquiry_id` + `notification_email` context so ops can manually forward the missed notification.
    - Closes **DB-B#SCALE-9**.
- [ ] **Step 4 — Add `$backoff = [10, 30, 60]` to Shopify order webhook jobs.**
    - `app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php`
    - `app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php`
    - Both already have `$tries = 3`, `$timeout = 30`, and `failed()` handlers — only `$backoff` is missing.
    - **Important:** use `[10, 30, 60]` (not the `[30, 90, 180]` from `BackfillBrandHasEnabledVariantsJob`). Shopify's p99 webhook-side recovery is under 60s; longer first-delay is counter-productive at peak webhook volume.
    - Closes **DB-B#SCALE-11**.
- [ ] **Step 5 — Round out Cloudflare jobs** (`app/Jobs/Cloudflare/ProvisionBrandDnsJob.php`, `RetireBrandDnsJob.php`, `SyncSubdomainToKvJob.php`).
    - Add `public int $timeout = 30;`.
    - Add `failed(\Throwable $e): void` handler that `report($e)` and logs `professional_id`/`subdomain` context.
    - Add `$this->onQueue('integrations')` in each constructor. These are third-party API calls; they belong on `supervisor-integrations` alongside Shopify webhook processing, not on `supervisor-default`.
    - If Step 1 confirms Phase 2 Pattern C hasn't shipped, also add `public array $backoff = [10, 30, 60];` here.
    - Closes **DB-C#SCALE-3** + (conditionally) **Phase 2 Pattern C**.
- [ ] **Step 6 — Add `public int $timeout = 720;` to `ProcessVideoVariantsJob`** (`app/Jobs/ProcessVideoVariantsJob.php`).
    - 720s = 660s max FFmpeg encoding (`max(120, durationMs/1000 * 2 + 60)` for 300s videos) + 60s upload buffer.
    - Ensure the `redis_video` supervisor in `config/horizon.php` also sets `timeout` ≥ 720 (the job-level property governs; the supervisor is a floor).
    - The job docstring already says `--timeout=3600` — the job-class property is the canonical encoding of the contract.
    - Closes **DB-D#SCALE-5**.
- [ ] **Step 7 — Extract `HasStandardRetryPolicy` (or similar) trait** if Phase 2 Pattern C did not already extract `HasCloudflareRetryPolicy`. Five jobs in this pattern alone now share `[10, 30, 60]` retry shapes; the next contributor will copy-paste. A `tests/Feature/Queue/JobHygienePolicyTest.php` sweep asserting every `app/Jobs/` class has `$tries`, `$backoff`, `$timeout`, and (if `failed()` is empty) at least a `report()` call, is a stronger guard.

### Plain English

Background jobs need three pieces of survival gear: a retry count, a waiting period between retries, and a maximum runtime. Without these, three different things can go wrong: a temporary glitch makes the job retry too fast and overwhelm the recovering service; a slow job gets killed before it finishes; or a failing job dies silently with nobody alerted. Eight jobs are missing one or more of these. The fix is mechanical — add three lines to each file. Phase 2 Pattern C already proposed half this fix; Phase 4 finishes the job.

### Why this is the fourth-priority pattern

Five P2 findings, no architectural risk, ~5 minutes per file. The whole sweep is one PR after Phase 2 Pattern C lands. Sequencing it after Patterns 1, 4, 5 means none of those patterns' new jobs (PurgeAffiliateProductSelectionsJob, SyncPaymentMethodFromCheckoutSessionJob, ProvisionBrandDnsTxtJob) need a follow-up — they inherit the hygiene shape from day one.

---

## Pattern 2 — Migration safety convention

**Closes 2 unique findings (2 P2):** DB-C#SCALE-1, DB-C#SCALE-2 (which fully absorb DB-A#SCALE-1)

**Effort:** ~0.5 day

### Root cause

Eleven migrations across the `commerce.*` and `billing.*` schemas use plain `CREATE INDEX` and `ADD CONSTRAINT` inside `BEGIN`/`COMMIT` transaction blocks. PostgreSQL's locking semantics make this convention silently catastrophic post-launch:

- **`CREATE INDEX`** (non-concurrent) acquires `SHARE` lock on the target table, blocking all INSERT/UPDATE/DELETE for the duration of the build.
- **`ADD CONSTRAINT ... CHECK (...)`** and **`ALTER COLUMN ... SET NOT NULL`** acquire `ACCESS EXCLUSIVE` lock — the heaviest PostgreSQL lock — and validate every existing row before releasing it (full-table scan under the lock).
- **`ADD CONSTRAINT ... FOREIGN KEY`** (without `NOT VALID`) takes `ACCESS EXCLUSIVE` and validates every row.

The codebase already contains the correct pattern in **`20260424120000_add_live_check_index.sql`** — `CREATE INDEX CONCURRENTLY` outside any transaction block. That migration is the exemplar. The fix for Phase 4 is to *codify the convention so every future migration inherits it*, not to retroactively patch the shipped ones (they were safe to deploy pre-launch on empty tables; patching idempotent re-runs would be a no-op).

| Affected migrations (representative — full list in DB-C audit) |
|----------------------------------------------------------------|
| `20260416000000_add_commission_grace_period.sql` (2 indexes + 1 CHECK constraint) |
| `20260419000002_nullable_commission_fks.sql` (FK re-creation) |
| `20260420220000_add_analytics_ledger_occurred_at_indexes.sql` (2 indexes) |
| `20260428000000_payout_grace_and_app_fee.sql` (SET NOT NULL with inline UPDATE backfill) |
| `20260505200000_commission_ledger_entries_set_null_professional_fks.sql` (FK re-creation) |
| `20260506000000_create_orders_schema.sql` (BRIN indexes on orders) |
| `20260506300000_relax_commission_payout_items_link.sql` (partial UNIQUE indexes) |
| `20260506500000_drop_legacy_aggregates.sql` (SET NOT NULL on `commission_payout_items.order_id`) |
| `20260510000000_add_commission_payouts_lifecycle_columns.sql` (3 indexes + 2 CHECK constraints + inline UPDATE backfill) |
| `20260510400000_extend_orders_rate_source_constraint.sql` (CHECK constraint on highest-write table `commerce.orders`) |
| `20260511000000_add_commission_payouts_grace_started_at.sql` (1 index + inline UPDATE backfill) |

### What to do

- [ ] **Step 1 — Write `supabase/migrations/CONVENTIONS.md`** documenting the four rules:
    - **Index creation:** Always `CREATE INDEX CONCURRENTLY IF NOT EXISTS`, outside any transaction block. Two-file convention for migrations that need both schema changes (BEGIN/COMMIT block) and indexes: one file per concern, prefixed with the same timestamp +1.
    - **CHECK constraints on populated tables:** `ADD CONSTRAINT ... CHECK (...) NOT VALID` first (lock-light), then `VALIDATE CONSTRAINT` in a separate transaction (acquires only `SHARE UPDATE EXCLUSIVE` — doesn't block writes).
    - **`SET NOT NULL` on populated tables:** `ADD CONSTRAINT chk_col_not_null CHECK (col IS NOT NULL) NOT VALID` → backfill NULLs in a preceding `UPDATE` (outside the schema transaction) → `VALIDATE CONSTRAINT` → `ALTER COLUMN SET NOT NULL` (metadata-only once Postgres has a validated check). The final `SET NOT NULL` step is fast.
    - **Foreign keys:** `ADD CONSTRAINT ... FOREIGN KEY (...) NOT VALID` first, then `VALIDATE CONSTRAINT` separately.
    - **Inline `UPDATE` backfills:** never inside the migration transaction. Extract to a separate one-shot job dispatched after the migration lands. A migration holding a transaction lock while millions of rows update is the worst variant of this pattern.
    - Reference `20260424120000_add_live_check_index.sql` as the canonical example.
- [ ] **Step 2 — Add a migration template** at `supabase/migrations/TEMPLATE.sql.example` showing the two-file pattern for any migration that includes both DDL and index creation, with comments explaining each lock implication.
- [ ] **Step 3 — Add a CI lint** (composer guard alongside `guard:no-laravel-migrations`) that fails on:
    - `CREATE INDEX` (without `CONCURRENTLY`) inside a `.sql` migration file (regex: `^\s*CREATE\s+(UNIQUE\s+)?INDEX\s+(?!CONCURRENTLY)` after stripping comments).
    - `ADD CONSTRAINT.*FOREIGN KEY` without a corresponding `NOT VALID` clause.
    - `ALTER COLUMN.*SET NOT NULL` (must use the four-step pattern above).
    - The lint allows the patterns the team prefers and fails closed on the patterns that block production traffic.
- [ ] **Step 4 — Document migration testing.** Add to `CONVENTIONS.md`: any migration touching a `commerce.*` table after pilot launch must be tested against a staging snapshot with at least 100K rows in the target table to surface lock-contention issues *before* prod. List `commerce.orders`, `commerce.order_events`, `commerce.commission_movements`, `commerce.commission_payouts`, `commerce.brand_affiliate_rollup` as the hot tables that demand the discipline.
- [ ] **Step 5 — Decide on backfill of shipped migrations.** Don't patch the shipped 11 in place (idempotent re-run is a no-op). **If** any of the hot tables grow significantly before the next scheduled maintenance window, schedule a separate migration that drops and recreates the perf-critical indexes via `CONCURRENTLY` to make them explicit in history. Track in a follow-up TODO; do not block this PR on it.

### Plain English

Building a database index is like adding a lane to a highway. The current code rebuilds the highway with no traffic allowed — fine when the highway is empty (today), catastrophic during rush hour (post-launch). PostgreSQL has a "build the new lane while traffic flows" variant — `CONCURRENTLY` — but it can't be used inside a transaction, and every migration here is wrapped in one. Same story for "this column can't be empty" rules and "this value must be one of these" rules: the current code stops all traffic to verify every existing row; the safe pattern stops nothing.

Eleven migrations follow the unsafe pattern. They've shipped fine because no traffic exists yet. The fix is not to retroactively patch them — that's mostly a no-op — but to write down the rule so every future migration uses the safe variant, and to add an automated check that yells if anyone forgets.

### Why this is the fifth-priority pattern

Lowest-severity pattern (P2 only, no current customer impact at near-zero table sizes), but it's the most institutional one. Land it last so the convention is in place for any new migrations Patterns 1 and 3 introduce (e.g., the `dns_status` column for DB-F#SCALE-5, the `needs_manual_refund` column for DB-D#SCALE-2, the partial indexes for DB-F#SCALE-7). Sequencing this last also lets reviewer attention focus on functional changes first; the convention PR is itself a single doc + one composer guard + one template.

---

# Part 2 — Standalone fixes

Both standalones can be picked up in any order after Part 1. DB-F#SCALE-7 is the first user-facing exercise of Pattern 2's convention — landing it under the new convention is the proof artifact.

## DB-F#SCALE-7 · P2 — `StaffStatsController` missing partial indexes for pending-commission SUM + active-subscriptions count

- **Where:** `app/Http/Controllers/Api/Staff/StaffSite/StaffStatsController.php:76–78`
- **Effort:** S (~0.5–1h)
- **What to do:**
    - Add a new migration: `supabase/migrations/<timestamp>_add_staff_stats_partial_indexes.sql`. Outside any `BEGIN`/`COMMIT` block (Pattern 2 convention):
        ```sql
        CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_cm_pending_amount
            ON commerce.commission_movements (amount_cents)
            WHERE status = 'pending';

        CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_subscriptions_active_count
            ON billing.subscriptions (id)
            WHERE ended_at IS NULL;
        ```
    - Both indexes reduce the staff-dashboard `SUM` and `COUNT` queries to index-only scans. The 60s `CacheLockService` TTL on `StaffStatsController` caps query frequency at once per minute; but at 2M-10M-row table sizes (1M orders/year × 2-10 commission rows each), the per-miss cost without these indexes scales linearly with table growth.
- **Plain English:** The staff dashboard's "pending commissions" total and "active subscriptions" count both ask the database to scan large tables to compute one number. Adding a partial index is like keeping a running sticky-note total updated every time a new row qualifies — the dashboard reads the sticky note instead of recounting from scratch. The query already only runs once per minute (cached), but that one-per-minute cost grows with table size; the partial indexes bound the growth.

## DB-D#SCALE-6 · P3 — `ShopifyMetrics::graphqlError` logs unbounded error payload

- **Where:** `app/Services/Shopify/Client/ShopifyMetrics.php` — `graphqlError()`
- **Effort:** S (~0.5–1h)
- **What to do:**
    - Truncate `$errors` to the first 3 entries before logging; strip `extensions` sub-keys (they contain query snippets that compound on incident).
    - The full `$errors` array is already passed to `ShopifyGraphQLException` (thrown immediately after this call), which Nightwatch captures separately as an exception with a stack trace. The structured log entry only needs enough context to correlate `shop_domain` + `query_hash` with the exception record.
- **Plain English:** When a Shopify call fails, we currently write the complete failure report (with every detail Shopify sends back) into the monitoring system's structured log stream. During a Shopify outage with thousands of failing calls, this floods the log indices with redundant detail — the same detail is already captured as a separate "exception" record. The fix is to keep only the short summary (which store, which query, top 3 error messages) in the log so it can be cross-referenced with the exception.

---

# Appendix A — Suggested PR bundling

Group findings by file overlap and dependency so each PR has a focused diff. PRs are listed in the order they should land.

| PR bundle | Findings | Pattern | Notes |
|-----------|----------|---------|-------|
| `webhook-controllers-async` | DB-F#SCALE-3 + DB-F#SCALE-4 | Pattern 1 | Both webhook controllers; same dispatch-job shape. |
| `embedded-setup-controller-cleanup` | DB-F#SCALE-5 + Phase 3 SCALE-B#CACHE-3 (cross-phase) | Pattern 1 + Phase 3 Pattern 2 | Same file; bundle the DNS-async fix with Phase 3's `overview()` rewrite. |
| `stripe-refund-transaction-scope` | DB-D#SCALE-2 | Pattern 1 | Single file; isolated change. |
| `document-upload-r2-outside-transaction` | DB-E#SCALE-1 | Pattern 1 | Mirror `BrandGalleryController::upload()`. |
| `shopify-storefront-client` | DB-D#SCALE-1 + DB-D#SCALE-3 | Pattern 4 | Bundle: new `ShopifyStorefrontClient` class + retry-strategy rework in `ShopifyAdminClient`. |
| `embedded-product-settings-batch` | DB-F#SCALE-1 | Pattern 4 | Single file; rework metafield save calls. |
| `embedded-product-analytics-active-flag` | DB-F#SCALE-6 + Phase 3 SCALE-B#CACHE-6 (cross-phase) | Pattern 4 + Phase 3 Pattern 1 | Same file; bundle Phase 3's `show()` wrap with Phase 4's `resolveActive()` rework. |
| `hydrogen-deploy-debounce` | DB-D#SCALE-4 | Pattern 4 | Single service method. |
| `hydrogen-deployment-controller-n+1` | DB-F#SCALE-2 | Pattern 5 | Bulk pluck refactor. |
| `resource-relation-guards` | DB-A#SCALE-2 + DB-A#SCALE-3 | Pattern 5 | Two defensive guards; 5-minute changes. |
| `queue-job-hygiene-sweep` | DB-B#SCALE-9 + DB-B#SCALE-10 + DB-B#SCALE-11 + DB-C#SCALE-3 (+ Phase 2 Pattern C if unshipped) + DB-D#SCALE-5 | Pattern 3 | Single sweep across 8 job files. Bundle Phase 2 Pattern C if not already in `development`. |
| `migration-safety-conventions` | (DB-C#SCALE-1, DB-C#SCALE-2 — closed by docs/lint, no migration patching) | Pattern 2 | Adds `CONVENTIONS.md`, `TEMPLATE.sql.example`, composer guard, and `idx_cm_pending_amount` + `idx_subscriptions_active_count` migration (uses Pattern 2 convention from day one). |
| `staff-stats-partial-indexes` | DB-F#SCALE-7 | Standalone | Folded into `migration-safety-conventions` if landing together; otherwise its own PR. |
| `shopify-metrics-log-truncation` | DB-D#SCALE-6 | Standalone | Smallest change in the plan. |

`webhook-controllers-async` and `embedded-setup-controller-cleanup` are both Pattern 1, but they touch different files and have different cross-phase dependencies. Separating them keeps each diff focused.

# Appendix B — Verification

After each pattern lands, verify with:

```bash
composer test                              # full Pest suite
php artisan test --compact --filter=Webhook       # webhook controller tests
php artisan test --compact --filter=Shopify       # Shopify integration tests
php artisan test --compact --filter=Queue         # queue job hygiene sweep
```

Pattern-specific spot checks:

- **Pattern 1 (external I/O outside critical sections):**
    - **Webhook latency:** in Nightwatch, confirm `webhook.stripe_connect.checkout.session.completed` p99 drops below 200ms after Step 1 ships (currently bounded by Stripe API latency).
    - **Transaction scope:** `rg "DB::transaction" app/Services/Stripe/ app/Http/Controllers/Api/Professional/` should show no `->refunds->create` / `->put(` inside the closure body.
- **Pattern 4 (vendor budget discipline):**
    - **Storefront throttle:** with debug logging on, force a `THROTTLED` Storefront response (via a stub) and assert `ShopifyThrottledException` is thrown, not silently swallowed into `emptyBrand()`.
    - **Bulk metafield save:** in Tinker, save 5 variant flags on a product and confirm exactly one Admin API call fires (not 5). Use `ShopifyMetrics::graphqlCall` count via the logged correlation IDs.
    - **Catalog fetch elimination:** `rg "fetchBrandCatalog" app/Http/Controllers/Api/Internal/EmbeddedProductAnalyticsController.php` should return zero matches after Step 4 lands (replaced by local DB read or per-product cache).
- **Pattern 5 (N+1 sweep):**
    - **HydrogenDeploymentController:** load `/hydrogen/deploy/targets` with Laravel Debugbar enabled, confirm 2 DB queries (was 201 at 200 brands).
- **Pattern 3 (queue job hygiene):**
    - `rg "public int \$tries|public array \$backoff|public int \$timeout|public function failed" app/Jobs/` — every job file should match all four lines. Add the `tests/Feature/Queue/JobHygienePolicyTest.php` sweep (Pattern 3 Step 7) to make this machine-checkable.
- **Pattern 2 (migration convention):**
    - `vendor/bin/composer guard:no-blocking-migrations` (Step 3) should pass on `development`; introduce a deliberately-failing test migration and confirm it fails the build.

# Appendix C — What this plan does NOT cover

For continuity with the Phase 1–3 plan format:

- **No Phase 5 work is implied here.** Lenses still unaudited: streaming-vendor edge cases, cron/scheduled-job correctness (`routes/console.php` was sampled but not audited end-to-end), payment-method lifecycle across Stripe Connect re-onboarding, and the Cloudflare KV fan-out blast radius beyond the three jobs covered here.
- **No retroactive patching of shipped migrations.** Pattern 2 establishes the convention; the 11 already-shipped migrations are left in place per the DB-C audit's own recommendation (idempotent re-runs would be no-ops). A separate one-shot remediation migration may be scheduled post-launch if the hot tables grow rapidly.
- **No rework of the Shopify Admin retry strategy beyond what DB-D#SCALE-3 prescribes.** A larger redesign (queue-only retry, no in-process loop) is out of scope; the proposed change keeps one immediate retry to absorb single-packet blips.
- **No deployment of `ShopifyStorefrontClient` to other call sites yet.** DB-D#SCALE-1 extracts the class and migrates `BrandDesignImporter`. Other Storefront callers (currently zero in the audited code) should follow the new client when added.
- **No fix for legacy `RetireSubdomainFromKvJob`** — DB-C#SCALE-3 named three Cloudflare jobs (`ProvisionBrandDnsJob`, `RetireBrandDnsJob`, `SyncSubdomainToKvJob`). The fourth job named in Phase 2 Pattern C (`RetireSubdomainFromKvJob`) is included in Phase 2's scope; verify it gets the same `$timeout`/`failed()` treatment when Phase 2 Pattern C is reconciled into Pattern 3 above.
