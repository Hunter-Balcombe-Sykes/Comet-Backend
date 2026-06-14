# Brand-status & embedded-Shopify-app push (Tobias, 2026-04-14 → 2026-05-06): scalability, security, correctness, observability

Audit the brand-status redesign and the entire embedded-Shopify-admin surface pushed by `hubterbalcombeesykes` between **2026-04-14** and **2026-05-06**. The push introduces:

- A new `BrandStatus` enum + state machine (7 cases) wired into observers, controllers, and webhooks.
- A brand-new embedded Shopify-admin API surface (~2,000+ LOC) across 6 new controllers, **2 new auth middlewares** (`VerifyEmbeddedApiKey` and `VerifyShopifySessionToken`), and an embedded OAuth/install flow.
- A 575-LOC `ShopifyTeardownService` for full disconnect/uninstall cleanup.
- A `CloudflareDnsService` that provisions Shopify Oxygen CNAME + domain-verification TXT records on the customer's behalf.
- Hydrogen single-brand redeploy endpoints + on-credential-save deployment trigger.
- A 5-minute catalog cache and several "make it non-fatal" patches to Shopify-side jobs.
- A managed-install OAuth scope flip (`3ffebca` drops the `scope` param from the authorize URL).

None of this code has carried real user load yet — the goal is to catch correctness / scalability / security / observability problems **before** the pilot launches and amplifies them.

The reviewer (you) is the **scan tier** of a dual-worker pipeline. Flag uncertainty rather than guess; the Sonnet adjudicator will dedupe and re-tier.

## Use the lens prefix `BSE` for findings

Number them `BSE-1`, `BSE-2`, … sequentially across the whole audit, regardless of category.

## Background — what changed and why it matters

**Commits in scope** (run `git log --author=hubterbalcombeesykes --since=2026-04-14` to confirm):

### Brand-status state machine + dependent surface (2026-05-02 → 2026-05-06)
- `4ffb80c` feat(brand): add brand status system + simplify affiliate photo permissions
- `fbe2ee1` feat: brand status redesign + affiliate catalog pipeline
- `359da80` fix: reset brand_status to building on Shopify disconnect/uninstall
- `5c349d2` fix(brand): let Shopify-connected brands pass wizard gate without Hydrogen/Oxygen
- `031ea62` fix(embedded): auto-heal wizard flags when storefront is already live
- `7155086` feat(api): gate embedded setup_complete on storefront_status=live
- `20b66b8` feat(api): add storefront_status check to /brand/store-settings
- `e055825` / `14b5625` fix(commerce): expose brand_status in /brand/store-settings response

### Embedded admin extensions + redeploy + analytics (2026-05-02 → 2026-05-06)
- `9f1b134` feat(embedded): backend for Shopify admin UI block extensions (NEW middleware + 2 controllers)
- `fd899c1` feat(embedded): add product-settings endpoint (NEW 653-LOC controller)
- `173091e` feat(api): add Redeploy endpoints for both embedded and dashboard wizards
- `763c8c7` feat(hydrogen): trigger single-brand Oxygen deployment on credential save
- `bdad3e3` fix(embedded): derive setup_complete from actual field state, not just DB flag
- `72838dd` fix(embedded): treat Oxygen-served redirects as live in storefront status check
- `37cf806` fix(embedded): revenue calc, active-only products, syncDesign signature

### Catalog + Shopify-side fixes (2026-05-02 → 2026-05-06)
- `b9ef395` perf(catalog): add 5-minute cache to brand catalog and affiliate catalog queries
- `f54ed32` fix(affiliate-catalog): fall back to all-products query when collection missing
- `f61a9fa` fix(shopify): query brand data from Storefront API instead of Admin API
- `f05717f` fix(shopify): make brand query non-fatal when read_brand scope is missing
- `3028e47` fix: make collection publishing non-fatal when publication is unavailable
- `83fc854` fix: resolve publication dynamically instead of using stale cached ID
- `1a772a9` fix: use app publication_id for collection publishing
- `200c103` fix(embedded): re-dispatch metafields/collections jobs when collection handles are missing
- `9a1df96` fix(collections): return empty list instead of 404 when collection handles not yet set

### Embedded wizard backend + DNS provisioning (2026-04-28 → 2026-05-02)
- `b82e3dd` feat(shopify): provision full integration from embedded wizard on connect
- `86dc9e9` feat(shopify): clear wizard progress on disconnect/uninstall
- `ed2f42a` feat(shopify): auto-provision Shopify domain TXT verification via Cloudflare
- `c931798` fix(dns): create Shopify Oxygen CNAME as DNS-only (proxied=false)
- `6204561` fix(shopify): connected = shop linked, not access_token present
- `9779d48` feat(shopify): wizard backend — hydrogen confirm, extended profile + store settings (377-LOC additions to `EmbeddedSetupController`)
- `b7196ce` feat(shopify): account linking flow for embedded app (NEW `EmbeddedConnectController`, NEW `ShopifyEmbeddedConnectionController`, NEW `VerifyEmbeddedApiKey` middleware)
- `e7c46a4` / `48156d4` fix(shopify): pass data-only to success() in embedded connect controllers

### Shopify install / OAuth / teardown / Oxygen plumbing (2026-04-14 → 2026-04-21)
- `f8d4797` feat(shopify): full Shopify-side teardown on disconnect + install backfill (NEW 575-LOC `ShopifyTeardownService`, NEW `BackfillBrandHasEnabledVariantsJob`)
- `3ffebca` fix(shopify oauth): drop scope param from authorize URL to get managed-install scopes
- `b485b6a` fix(shopify): default app_handle matches shopify.app.toml ("side-st-hydrogen")
- `0f2d5cc` feat(shopify): resolve custom primary domain to myshopify handle
- `10582d3` fix(shopify): replace regex with strpos in resolveShop domain parser
- `1e009c5` feat(integrations): add Oxygen deployment token + storefront ID to brand settings
- `e728afb` feat(internal): add deployment-targets endpoint for Oxygen CI
- `1eef42e` fix(integrations): skip Shopify sync for oxygen-only patches

**Shape of the change.** A new state machine (`BrandStatus` enum: `Onboarding` / `ShopifyLinked` / `ShopifyConfigured` / `StorefrontLive` / `ReadyForAffiliates` / `Disconnected` / `SystemsDown`) is now load-bearing for the wizard, the dashboard, the affiliate experience, the public site, and webhook reactions. `BrandStatusService::sync()` is called from observers, controllers, and webhooks — so every transition path must be **idempotent, race-safe, and side-effect-correct**.

The embedded admin surface is mostly net-new and adds a Shopify-session-token auth path **distinct from** the existing Supabase JWT path — a fresh attack surface that needs the same authorization rigour as the rest of the API (see Partna Authorization Doctrine in the system prompt).

The 5-min catalog cache (`b9ef395`) was added without the lock+jitter+SWR+push-invalidation pattern that was deployed across commerce on 2026-05-06. Re-flag any divergence.

## Deployment context (matters for "horizontal scaling" findings)

- Runs on **Laravel Cloud** (auto-scaled ephemeral containers, multi-instance), not raw Kubernetes — but the same constraints apply: in-process state does not survive a deploy or a scale-up, and it is **not shared across instances**.
- Cache + queue + sessions all on **Redis** (DB 0 / DB 1 / DB 2). Video on `redis_video`. Anything pinned to file or array driver is a finding.
- Database is a **single Supabase Postgres primary** with no read replicas today — but architecture should not actively prevent moving reads to a replica later (no hard read-after-write requirement on the primary, no transactions that span async boundaries).
- Auth = Supabase JWT — `Auth::user()` always returns null. New embedded routes use Shopify session tokens, so they must resolve a tenant from the session token, not from `Auth::user()`.

## Findings categories

### (1) Brand-status state-machine correctness

- **Transition coverage.** `BrandStatusService::sync()` (and any direct writes to `brand.brand_profiles.brand_status` outside it) must cover every legal source state × event combination. Find any controller, observer, or webhook handler that writes the column directly without going through the service. Find any transition path that is unreachable from the seven-case enum.
- **Idempotency.** Webhook re-delivery (`shop/update`, `app/uninstalled`), retried jobs, and observer re-fires must not produce double transitions, double notifications, or status flapping. The `commerce.order_events` model (append-only, dedup'd by `shopify_event_id`) is the canonical pattern for webhook idempotency — flag any status-mutating handler that doesn't follow an equivalent dedup.
- **Race conditions.** Two webhook deliveries (e.g. `app/uninstalled` + a parallel `shop/update`), an admin redeploy + an observer-triggered sync, or a brand re-connecting Shopify while a fan-out from the prior state is mid-flight. Either lock or define a deterministic last-writer-wins. Look for missing `lockForUpdate` / `SELECT ... FOR UPDATE` / advisory locks where the read-modify-write spans more than one statement.
- **`brand_status_history` correctness.** New table from `20260505000001_create_brand_status_history.sql` — verify every transition writes a history row and that history rows are immutable (no UPDATE paths). Missing inserts on a transition are a P1; UPDATEable history rows are a P1.
- **Reset on disconnect / uninstall.** `359da80` resets `brand_status` to `building` on Shopify disconnect / uninstall. Verify (a) it doesn't strand `ReadyForAffiliates` brands in a partial state if Shopify reconnects later; (b) it doesn't dispatch a `FanOutBrandStatusNotificationJob` that emails affiliates "your brand is broken" on every transient webhook blip.
- **`hasShopifyConnected` and similar in-process caches.** `BrandStatusService::$shopifyConnectedCache` is a per-request memoization — fine within a single request, but flag it if anything writes a value the same request later relies on (stale in-process read).

### (2) Scalability, statelessness, decoupling

- **In-process state that does not survive deploy / scale-up.** Any private array / static cache used as a multi-request store, file-driver caches on hot paths, in-process queues, sticky-session assumptions. Per-request memoization is fine; cross-request is a finding.
- **Cache stampede on the new 5-min catalog cache (`b9ef395`).** Verify `AffiliateProductCatalogService` and `BrandCatalogService` use `CacheLockService::rememberLocked` (not bare `Cache::remember`), have TTL jitter (±20%), have SWR, and are push-invalidated on every relevant write (catalog change, product publish/unpublish, brand status flip). Bare `Cache::remember` on a hot path is the antipattern Phase 4 just removed from commerce — do not let it back in here.
- **Fan-out unbounded by tenant size.** `FanOutBrandStatusNotificationJob` already chunks by 500 — verify the new uncommitted change to use `Bus::batch()` actually reduces Redis-pipeline pressure (batch size = 200) and that `allowFailures()` preserves per-job retry semantics. Flag any other status-triggered fan-out without bounded chunking.
- **Synchronous heavy work in webhook / controller handlers.** The "auto-heal wizard flags" path (`031ea62`) and the redeploy endpoints (`173091e`) — do they do Shopify GraphQL calls / Oxygen API calls on the request thread, or is that work queued? Inline external calls on a request thread are a P1 — they hold a worker, fail under p99 latency, and bloat tail latency.
- **Database load on hot reads.** New live queries in `BrandStatusService::sync()`, `EmbeddedSetupController` derived flags, `EmbeddedProductSettingsController` joins. Confirm indexes exist for every `WHERE` and `ORDER BY` clause introduced. Missing indexes on hot reads are P1.
- **Read-replica readiness.** Anything that does `INSERT/UPDATE` on the primary and immediately reads the same row on the assumption it sees its own write is fine on a single primary today, but locks us out of read replicas later — flag as P3.
- **Single-brand Oxygen deployment (`763c8c7`).** Confirm it cannot be triggered concurrently for the same brand (lock) and that a flapping `credential save` storm doesn't produce N parallel deployments.

### (3) Security / authorization on the new embedded surface

This category is **the highest-priority** in the audit because the embedded surface introduces TWO new auth middlewares and a brand-new OAuth/install entry point. A bug in any of these is a tenant-boundary failure.

- **`VerifyShopifySessionToken` middleware (NEW, 9f1b134).** Shopify-issued JWT auth for admin UI block extensions. Wrong logic here is the difference between every brand and any-brand-can-read-any-brand. Verify: signature check (against the correct shared secret), audience check (`aud` = our app's client ID), issuer check (`iss` = expected shop), expiry check (`exp` + clock skew tolerance), `nbf` check, replay protection (jti/dedup if applicable), and that the resolved tenant on `$request->attributes` is **derived from the session token's `dest`/`iss` claim**, not from a body/header parameter the client controls. Any path that resolves the brand from a query string / body / header is a P0.
- **`VerifyEmbeddedApiKey` middleware (NEW, b7196ce).** A second new auth middleware for the embedded connect flow. Verify: timing-safe comparison (`hash_equals`, not `==`/`===`), no log emit of the key value, no fallback-to-bypass when the env var is empty (the `VerifyHydrogenApiKey` example in pilot-stage-1.md is the cautionary tale), and that the env var is required at boot in production.
- **Embedded OAuth / install flow (`b7196ce`, `b82e3dd`).** `EmbeddedConnectController` and `ShopifyEmbeddedConnectionController` are the install entry points. Verify: state/nonce on the OAuth callback, HMAC verification on every Shopify-redirected request, no open redirect from `host`/`shop` params, and that the post-install handoff cannot be replayed to attach a different professional to the shop.
- **Managed-install scope flip (`3ffebca`).** Dropping the `scope` param relies on `shopify.app.toml` for scope grant. Verify the toml's scope list is intentional and that scope reduction doesn't leave existing installs with stale-too-broad tokens (or scope upgrade doesn't silently re-prompt without explicit consent).
- **Authorization on `EmbeddedProductSettingsController` (653 LOC, fd899c1).** Every action must call `$this->authorizeForUser($pro, ...)` against a Policy — never inline `abort_unless` or direct `BrandAccessService` capability calls. CI enforces this; flag any survivor.
- **Authorization on `EmbeddedOrderAnalyticsController` and `EmbeddedProductAnalyticsController`.** Same pattern. Analytics endpoints are particularly dangerous because financial data is involved.
- **Authorization on `EmbeddedSetupController` (which got a 377-LOC expansion in 9779d48 plus the auto-heal in 031ea62).** Same pattern. Wizard endpoints write Shopify integration state — a missing policy gate here is a tenant-overwrite vector.
- **Tenant isolation.** Every query in the new embedded controllers must constrain to the resolved professional's brand — flag any `where('id', $request->id)` that doesn't also constrain by `professional_id` or `brand_professional_id`.
- **403 vs 404.** Per CLAUDE.md, missing-or-not-yours = 404 (not 403). Public endpoints must always 404. Flag any 403 where a 404 would prevent enumeration.
- **Hydrogen redeploy + on-credential-save deploy (`173091e`, `763c8c7`).** Verify rate-limiting (a brand spamming redeploy or rapid credential edits is a DoS against Oxygen), authorization, idempotency, and that disconnected brands cannot trigger a redeploy.
- **`CloudflareDnsService` (NEW, `c931798` + `ed2f42a`).** Provisions Shopify Oxygen CNAME and domain-verification TXT on the customer's behalf. Verify: tenant scoping (one brand cannot create/modify DNS records for another brand's domain), CF API token scope (zone-level vs account-level — flag if it's broader than needed), no log emit of the API token, idempotency (re-running the wizard must not duplicate or corrupt records), and that record creation failures roll back cleanly without leaving orphaned DNS state.
- **`ShopifyTeardownService` (NEW 575 LOC, f8d4797).** Disconnect/uninstall fully tears down Shopify-side state. Verify: every teardown step is idempotent, partial failure is recoverable (a half-torn-down brand must be re-runnable), no step relies on data the prior step already deleted, and the overall service is **gated on a confirmed disconnect/uninstall event** — not callable from an unauthenticated path.
- **`resolveShop` domain parser (`10582d3`, `0f2d5cc`).** Domain-to-shop-handle resolution is a parsing-trust boundary. Verify: no SSRF if the domain resolution fetches anything, strict validation against `*.myshopify.com`, and no open redirect built on top of `resolveShop`'s output.
- **Storefront API vs Admin API switch (`f61a9fa`).** Confirm the switch doesn't expose data fields visible to a Storefront token but not to an Admin token, and that the `read_brand` non-fatal path (`f05717f`) doesn't leave callers with stale or missing data they later treat as authoritative.
- **Webhook auth.** `ShopifyAppUninstalledWebhookController` changes — confirm HMAC verification still runs and that the reset-to-building / wizard-clear / teardown paths are not reachable from an unverified payload.

### (4) Caching, locking, invalidation

- **Catalog cache (`b9ef395`).** As above — must match the `CacheLockService::rememberLocked` + TTL + jitter + SWR + push-invalidation pattern, not bare `Cache::remember`. Push-invalidation must fire on: product publish/unpublish, collection update, brand status transition to/from `ReadyForAffiliates`, Shopify disconnect, and uninstall.
- **`storefront_status` derivation in `EmbeddedSetupController` and `BrandStoreSettingsController`.** Flag any per-request HTTP probe (Oxygen reachability check) that runs on the request thread without a Redis-cached short-TTL result. p95 spike on Oxygen would otherwise propagate to every dashboard load.
- **`setup_complete` derivation (`bdad3e3`).** Now derived from actual field state — confirm the derivation isn't an N+1 across embedded routes, and that it's cached at the right granularity (per-brand, not per-request).
- **Cache key design.** Verify any new keys go through `CacheKeyGenerator` (or are namespaced equivalently) and that no key includes mutable state that would prevent push-invalidation from finding it.
- **Cache pinned to Redis.** Any `Cache::store('file')` / array-driver fallback on a hot path is a P1 — file drivers are per-instance and break under multi-container.

### (5) Job / queue / fan-out hygiene

- **Idempotency on retry.** Every job in scope (`CreateShopifyCollectionsJob`, `FanOutBrandStatusNotificationJob`, `SendBrandStatusNotificationJob`, `ProcessShopifyOrderWebhookJob`, deployment jobs) must be re-runnable. Look for INSERTs without ON CONFLICT, multi-step writes without idempotency keys, external API calls without idempotency keys.
- **Failure handling.** "Make it non-fatal when X is missing" patches (`3028e47`, `f05717f`) — flag any path that silently swallows the failure without a `Log::warning` carrying enough context for Nightwatch to correlate (brand_id, shop, request_id, the operation that was skipped). Silent skip = invisible bug forever.
- **Queue selection.** Status fan-out should land on the `notifications` queue; deployments should not contend with notifications. Flag any job that lands on the default queue when a domain queue exists.
- **`failed()` handlers.** Long-running / external-API jobs without `failed()` handlers leak silent failures. Flag missing handlers.
- **Backoff and tries.** Verify retries on Shopify / Oxygen calls have explicit backoff and bounded `$tries`, not infinite retry storms on a vendor outage.

### (6) Webhook / re-dispatch correctness

- **`200c103`, `9a1df96` re-dispatch when collection handles missing.** Confirm the re-dispatch is idempotent — a handle that races into existence between check and dispatch must not double-publish a collection. Confirm the bailout path doesn't loop forever on a permanently broken state.
- **`83fc854` resolve publication dynamically.** Was the prior cached ID a source of staleness across instances? Confirm the new dynamic resolve is itself cached (per-request or short-TTL) so we don't hammer the publication API on every job invocation.
- **`031ea62` auto-heal wizard flags.** Auto-heal that runs on every embedded request is a write-on-read antipattern — flag if the heal write fires more than once per `setup_complete=false → true` transition per brand.

### (7) Observability / Nightwatch readiness

The audit pipeline does not consume Nightwatch directly — but it can flag **observability gaps** that would make Nightwatch noisy or blind:

- **Missing context on `Log::warning` / `Log::error`.** Every log line in scope should carry `brand_professional_id` (or `professional_id`), `request_id`, and the operation that failed. Lines without context are noise in Nightwatch.
- **Swallowed exceptions.** `try { ... } catch (\Throwable $e) { return null; }` without a log emit is a finding — Nightwatch will never see it.
- **Generic exception messages.** `throw new \RuntimeException('something failed')` without a discriminator string makes Nightwatch grouping useless.
- **Untagged slow queries.** New live queries in `BrandStatusService::sync()`, `EmbeddedSetupController`, `EmbeddedProductSettingsController` — confirm they will surface in Nightwatch's slow-route / slow-job views (no DB query in a route handler that lacks a recognisable controller method to attribute it to).
- **Per-brand cardinality on log fields.** Logging untrimmed Shopify response bodies in a fan-out path will OOM Nightwatch indices — flag any `Log::*` call that passes a full GraphQL response or full webhook payload.

### (8) Migration / schema correctness

- `20260503000000_expand_brand_status.sql` and `20260505000000_redesign_brand_status_stages.sql` — verify the rename + value migration is reversible, that no enum CHECK constraint orphans existing rows, and that downstream code reads the new value space (no string `'live'` / `'building'` left in code that should be enum cases).
- `20260505000001_create_brand_status_history.sql` — verify NOT NULL + index on the lookup columns (`brand_professional_id`, `transitioned_at DESC`).

## Per-finding requirements

For every finding:
- Cite the category number (1–8).
- Name the canonical replacement: `CacheLockService::rememberLocked` + jitter + SWR + push-invalidate, OR `Bus::batch()` chunked fan-out, OR Policy-based authorization, OR session-token-derived tenant resolution, OR Log-with-context, etc.
- Quantify impact at the **pre-beta scaling target**: 30 brands × ~50 affiliates × ~100 orders/affiliate/year, plus ~3-5 status transitions per brand per month, ~20 dashboard loads per brand per day, ~2 redeploys per brand per month.

## Out of scope — do NOT re-flag

- The commerce schema (`commerce.orders` / `order_events` / `order_items` / `brand_affiliate_rollup` / `commission_movements`) — already audited, already shipped 2026-05-06.
- `app/Services/Stripe/CommissionPayoutService` and `CommissionVoidService` — already audited.
- The deleted `commission_ledger_entries` model.
- Audit findings that exist solely because Partna runs on Laravel Cloud rather than raw Kubernetes — Laravel Cloud is the deployment target, do not propose Dockerfile / k8s YAML.
- Findings about adding read replicas / sharding today — current load doesn't justify it; only flag code that **actively prevents** moving to replicas later.

## Files in scope

```
# Brand-status state machine
app/Enums/BrandStatus.php
app/Services/Professional/BrandStatusService.php
app/Services/Professional/BrandOnboardingReadinessService.php
app/Observers/Core/BrandProfileObserver.php
app/Models/Retail/BrandStoreSettings.php

# Embedded Shopify-admin surface (mostly new)
app/Http/Controllers/Api/Internal/EmbeddedSetupController.php
app/Http/Controllers/Api/Internal/EmbeddedConnectController.php
app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php
app/Http/Controllers/Api/Internal/EmbeddedOrderAnalyticsController.php
app/Http/Controllers/Api/Internal/EmbeddedProductAnalyticsController.php
app/Http/Controllers/Api/Internal/HydrogenDeploymentController.php
app/Http/Controllers/Api/Professional/ShopifyEmbeddedConnectionController.php

# New auth middlewares for the embedded surface (HIGHEST priority)
app/Http/Middleware/Auth/VerifyShopifySessionToken.php
app/Http/Middleware/Auth/VerifyEmbeddedApiKey.php

# Shopify install / OAuth entry points
app/Http/Controllers/Api/Shopify/ShopifyAppOAuthController.php

# DNS provisioning + Oxygen deployment
app/Services/Cloudflare/CloudflareDnsService.php
app/Services/Hydrogen/HydrogenDeploymentService.php

# Brand store settings / catalog exposure
app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php
app/Http/Requests/Professional/Store/UpdateBrandStoreSettingsRequest.php
app/Http/Controllers/Api/Professional/Store/BrandCollectionController.php
app/Http/Resources/BrandStoreSettingsResource.php
app/Http/Controllers/Api/PublicSite/PublicShopifyStorefrontController.php

# Notifications around status transitions
app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php
app/Jobs/Notifications/SendBrandStatusNotificationJob.php

# Catalog + caching
app/Services/Store/AffiliateProductCatalogService.php
app/Services/Store/BrandCatalogService.php
app/Services/Store/CustomPhotoPermissionService.php

# Shopify writers / readers touched by the push
app/Services/Shopify/BrandDesignImporter.php
app/Services/Shopify/BrandSignupService.php
app/Services/Shopify/ShopifyTeardownService.php
app/Jobs/Shopify/CreateShopifyCollectionsJob.php
app/Jobs/Shopify/CreateShopifyAffiliateDiscountJob.php
app/Jobs/Shopify/BackfillBrandHasEnabledVariantsJob.php
app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php
app/Http/Controllers/Api/Professional/ShopifyIntegration/ShopifyIntegrationController.php
app/Http/Controllers/Api/Webhooks/ShopifyAppUninstalledWebhookController.php

# Migrations
supabase/migrations/20260414020518_add_oxygen_fields_to_brand_store_settings.sql
supabase/migrations/20260428020000_add_brand_domain_settings.sql
supabase/migrations/20260428030000_add_hydrogen_install_confirmed.sql
supabase/migrations/20260428040000_add_domain_wizard_complete.sql
supabase/migrations/20260428050000_add_domain_txt_confirmed.sql
supabase/migrations/20260502000000_add_storefront_token_to_professional_integrations.sql
supabase/migrations/20260503000000_expand_brand_status.sql
supabase/migrations/20260505000000_redesign_brand_status_stages.sql
supabase/migrations/20260505000001_create_brand_status_history.sql
```

## Exhaustiveness directive

Do NOT stop after the first finding in a category. Walk every file in scope and emit a finding for every distinct instance you can quote evidence for. If three controllers each have an unauthorized `where('id', ...)`, that is three findings (`BSE-1`, `BSE-2`, `BSE-3`), not one consolidated finding. If a single file has both a missing policy call and a swallowed exception, that is two findings. The adjudicator will dedupe and re-tier; **under-reporting is the failure mode to avoid**. Aim for breadth — keep going until every file in scope has been read and every distinct quotable instance is recorded.
