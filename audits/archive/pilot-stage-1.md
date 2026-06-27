# Partna — Stage 1 Pilot Readiness Checklist

**Stage 1: 1 brand, ~10 affiliates, ~50 links**

Source: `audit-ledger-2026-05-01.md`. Ordering inside each tier: **least urgent first → most urgent last** (read bottom-up to find what to do next). Top-level checkbox is the only thing to tick — it means "fully fixed including tests."

## Progress

**✅ Complete — all in-scope items shipped.**

- P0 Blockers: 10 of 10 complete
- P1 High: 46 of 46 complete
- P2 Medium: 45 of 45 complete
- P3 Low: 29 of 29 complete

> Items prefixed `CR-` come from the Apr 27–28 commit-batch review and were appended to the bottom of each tier (most-urgent position in the bottom-up read order). Source review covered commits `b9de807..c144ccc` on `development-v2`.

> **Deferred items** (XL refactors, architectural decisions, cross-codebase coordination) were extracted to `pilot-manual-queue.md` for human-driven sessions: `#2-05`, `#8-03`, `#V5-005`, `#V5-070`, `#V5-061`, `#PR-002`. References to them remain in the Standalone / Dependencies meta-sections below as historical context.

---

## Suggested Bundled Sessions

Each bundle below is a set of findings that share a file, a pattern, or a domain — bundling them into one Claude session yields *better* fixes (one consistent test, one PR, one reviewer mental model) rather than worse ones. Items not listed here are best handled standalone; bundling them would force unrelated architectural decisions into one session.

Each session should open the items in its bundle by ID — the full body still lives in the tier sections below. Bundles do not change the priority order; tackle high-priority items inside a bundle first, then ride the lower-priority companions while you're already in the file.

### High-impact bundles (P0/P1 mixed)

- [x] **B1 — Shopify webhook HMAC + idempotency.** #4-01, #4-06, #4-08, #V5-068. (Optional ride-alongs: #V5-018, #V5-019.) ~3–4h. Same 4–5 webhook controllers, same one-line bug pattern, single Pest sweep that POSTs a forged signature and asserts 401. The audit author already calls this out as a single PR in #4-08. **Don't pull in:** #V5-020 (middleware refactor) or #V5-005 (per-shop secret) — both are architectural and would balloon scope.
- [x] **B2 — Composer dependency updates + audit step.** #10-01, #10-02, #10-03, #10-04, #10-05, #10-06, #10-07. ~2–3h. All `composer update X` plus one CI workflow change. One composer.lock commit, one CI run. Run `composer audit` first to confirm only these advisories surface; defer any new ones to a second pass.
- [x] **B3 — Platform-link cap fix.** #CR-001, #CR-011. (Optional follow-up: #V5-049.) ~1h. Same method (`StoreLinkBlockRequest:137`); the audit explicitly tags them as companions. One Pest test creates 7 blocks of one category and asserts the 8th is rejected — covers both.
- [x] **B4 — Soft-delete filter sweep.** #V5-012, #V5-013, #V5-038, #V5-039, #V5-056. ~4–5h. Identical "add `whereNull('deleted_at')`" pattern across analytics aggregates, observer notifications, public Hydrogen payloads, and 6 raw queries. One reasoning pass; regression tests can share a "soft-delete the parent, assert no leak in any output" fixture. **Watch out:** #V5-012 is GDPR-sensitive — verify the in-flight notification path still tests green.
- [x] **B5 — Throwable→QueryException narrowing in analytics.** #CR-010, #V5-017. ~1–2h. Same anti-pattern (AUDIT_REPORT.md line 287), two sibling analytics controllers. Lift one helper (catch `QueryException` + check SQLSTATE 42703) across both files.
- [x] **B6 — Time/currency/money cluster (lens-L).** #V5-024, #V5-025, #V5-026. ~2–3h. All in `CommissionPayoutService` / `CommissionVoidService`. Same domain (UTC vs app-TZ, `occurred_at` vs `created_at`, currency validation). One test: assert cutoffs use UTC, void uses `occurred_at`, currency validates against `shop_currency`. **Don't sweep in:** the broader payout backlog (#V5-007, #V5-008) — different concurrency / idempotency reasoning.
- [x] **B7 — Cache-key versioning post-deploy.** #CR-008, #V5-036, #V5-037. ~1–2h. All "shape change shipped without bumping cache version." One mental model: enumerate every key impacted, add a version suffix or flush, document the deploy-hygiene rule for next time.
- [x] **B8 — Stripe Connect webhook hardening.** #V5-009, #V5-010. ~2–4h. Same controller (`StripeConnectWebhookController`). Atomic flush + new `account.deauthorize` handler share a transaction-helper refactor.
- [x] **B15 — Shopify storefront token hardening.** #V5-003, #V5-004. ~3–5h. Both touch `StorefrontAccessToken` in `provider_metadata`. The encryption-cast change + reinstall-revocation flow share the same model + service touchpoints. **Optional:** #4-04 (encrypted-cast integration test, P3) rides naturally on this PR.
- [x] **B16 — ServiceObserver hardening.** #CR-005, #CR-006. ~2–3h. Same observer file. Two sibling fixes (catch granularity + `dispatch` vs `dispatchSync`) reasoned about together yield one Pest test that exercises both the bust-failure isolation AND the queued sync path.

### Mechanical / low-risk bundles (P2/P3)

- [x] **B9 — `$fillable` mass-assignment cleanup.** #V5-052, #V5-053, #V5-054, #V5-055, #9-001, #9-002, #9-003, #9-004. ~2–3h. Mechanical "remove sensitive cols from `$fillable`" or `$guarded = ['*']`. One Pest test per model asserting un-fillable columns are rejected. **Excludes:** #V5-023 (WebhookEvent payload) — needs schema validation, different reasoning.
- **B10 — Schedule task `withoutOverlapping`.** #10-08, #10-09. ~0.5–1h. Same file (`routes/console.php`), same one-line modifier.
- [x] **B11 — R2 orphan cleanup on variant failure.** #V5-043, #V5-044. ~1–2h. Same pattern (cleanup-in-catch or store-after-success), image and video pipelines. One helper, applied twice.
- [x] **B12 — Image MIME sniff.** #V5-015, #V5-047. ~1–2h. Different files but same defense (finfo MIME check before `getimagesize` / before storage). One helper extracted, two callers updated.
- [x] **B13 — Retry-After / 429 backoff parity.** #V5-032, #V5-034. ~1–2h. Same fix (parse `Retry-After`, multiply by 1000, default 1000ms floor) across Shopify, Square, Fresha API clients.
- [x] **B14 — Throttle config hardening (P2 only).** #V5-057, #V5-059, #V5-060. ~1–2h. All `AppServiceProvider` rate-limiter config; same reasoning surface. **Do NOT bundle with #V5-001** (P0 TrustProxies) — that needs separate staging verification.
- [x] **B17 — Square/Fresha job retry hygiene.** #5-03, #5-05. ~1–2h. Both add `$tries` / `$backoff` / `failed()` to Square + Fresha jobs. Mechanical parity work.

### Standalone — do NOT bundle

These are best in their own session because bundling would force unrelated architectural decisions, expand test scope unhelpfully, or risk a worse fix:

- **XL refactors:** #1-01 (policy rollout), #2-05 (global scopes), #V5-061 (Resource classes), #8-03 (Hydrogen deployment-token exchange). All multi-day phased rollouts; bundling drags every domain in.
- **Architectural decisions:** #V5-005 (per-shop webhook secret), #V5-070 (EmbeddedSetupController trust model). Same family of "platform-wide vs per-shop" call as #PR-002 / #PR-006 — sit with them on their own.
- **High-value standalones:** #V5-001 (P0 TrustProxies — needs staging verification), #V5-069 (P0 EmbeddedSetupController TypeError — fast tactical fix), #PR-001 (P0 VerifyHydrogenApiKey), #CR-002 (P0 grace_period_days config key — could optionally ride on B6 if you're already in the file), #CR-003 (P0 VoidExpiredPayoutsJob — new component with its own test scope), #V5-002 (P1 GDPR webhook stuck state — compliance-critical), #V5-006 (P1 webhook race), #V5-007 (P1 transfer idempotency), #V5-008 (P1 wallet currency mismatch), #V5-022 (P1 broadcast retries), #V5-023 (P1 WebhookEvent schema), #V5-027 (P1 enquiry throttle), #5-01 (P1 token refresh race), #6-04 (P1 click dedup), #1-02 (P1 inline aborts — depends on #1-01, sequence after), #1-03, #2-06, #4-12, #9-013, #9-015, #CR-004, #CR-007, #CR-009, #CR-014.

### Dependencies between bundles / items

- **#1-02 follows #1-01** — the inline-abort sweep needs the policies to exist first.
- **B3 (#CR-001 + #CR-011) is a strict prerequisite for #V5-049** — there's no point auditing existing over-limit rows until the cap actually fires.
- **B1 ideally precedes #V5-020** — fix the bug first; then the middleware-extraction refactor has fewer moving targets.
- **#PR-001 + #8-03 compound** — they're listed standalone but the audit's executive summary calls out the compound risk; if Hydrogen tokens get redesigned (#8-03), do #PR-001 first.

---

## P0 — Must fix before any real user touches Stage 1

- [x] **#10-01** · P0 — aws/aws-sdk-php has high-severity CloudFront policy injection (composer audit)
    - **Where:** composer.lock — aws/aws-sdk-php (currently 3.371.x or older)
    - **Affects:** Any code path that uses aws-sdk-php's CloudFront client; transitively the whole dep graph (the S3 client used for the R2 media disk also lives in this SDK).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Run `composer update aws/aws-sdk-php` to a version > 3.371.3.
        - Audit any caller of CloudFront client APIs (likely none — Partna media is on R2 — but confirm).
        - See finding #10-07: add `composer audit` to CI so future advisories don't sit undetected.
    - **Technical:** A one-line dependency bump. Verify the bump doesn't cascade-break other AWS clients (the S3 client is used by the R2 media disk). Cross-check with `composer outdated --direct`.
    - **Plain English:** A security flaw in a third-party AWS package. Even if we don't use the affected feature directly, the code is in our application. Update the package.
    - **Evidence:**
        ```
        $ composer audit
        Package: aws/aws-sdk-php
        Severity: high
        Advisory ID: PKSA-4t1p-xpk2-nsss
        Title: AWS SDK for PHP has CloudFront Policy Document Injection via Special Characters
        Affected versions: >=3.11.7,<=3.371.3
        ```

- [x] **#4-01** · P0 — Shopify order webhooks return HTTP 200 on bad HMAC instead of 401
    - **Where:** app/Http/Controllers/Api/Webhooks/ShopifyOrderWebhookController.php:27-33; same pattern in ShopifyOrdersUpdatedWebhookController, ShopifyShopUpdateWebhookController, ShopifyAppUninstalledWebhookController
    - **Affects:** All Shopify business-event webhooks (orders/paid, orders/updated, app/uninstalled, shop/update). Commission accounting integrity.
    - **Effort:** S (~1–2h)
    - **What to do:**
        - Change the four order/shop webhook controllers to mirror ShopifyGdprWebhookController:49 — `return $this->error('invalid signature', 401);`
        - Move the dedup `Cache::add()` call to BEFORE the HMAC check so a retry lane is preserved per `X-Shopify-Webhook-Id` even on bad signatures (related to #4-06).
        - Add a Nightwatch alert on `Shopify*WebhookController invalid HMAC` log volume.
    - **Technical:** HMAC verification gate must short-circuit with 401 to enforce signed-payload semantics. Returning 200 trains Shopify to consider forged events delivered, suppresses retries of legitimate events that hit transient signature failures, and zeros the operational signal of an attack.
    - **Plain English:** When someone sends a fake order webhook, the server politely tells Shopify "got it, all good." Shopify believes us and stops retrying — so even if the real order webhook arrives a moment later, the fake one has already crowded it out. We should reject fakes loudly so the real ones still come through.
    - **Evidence:**
        ```php
        // ShopifyOrderWebhookController.php:27-33
        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            Log::warning('Shopify order webhook: invalid HMAC signature', [
                'shop_domain' => $shopDomain,
            ]);
            return $this->success(['received' => true]);
        }
        ```

- [x] **#1-01** · P0 — Only 2 of ~30 tenant-owned models have an authorization policy registered *(Resolved 2026-05-05 — 32 Gate::policy registrations, brand.only + affiliate.only middleware applied, PolicyCoverageTest sweep enabled, all 5 follow-up audit findings closed in audit-2026-05-04-auth-policy-coverage.md)*
    - **Where:** app/Policies/* (only BasePolicy.php and IntegrationPolicy.php exist); app/Providers/AppServiceProvider.php registers only 1 `Gate::policy`
    - **Affects:** Every authenticated CRUD endpoint touching a tenant-owned model — most of the Professional and Staff API surface.
    - **Effort:** XL (~16–32h)
    - **What to do:**
        - Audit every tenant-owned model and add a Policy class (per-model or grouped where ownership semantics are identical, e.g. SitePolicy can cover Site/Block/SiteMedia).
        - Register each via `Gate::policy()` in `AppServiceProvider::boot()`.
        - Sweep controllers and replace inline `abort_unless($x->professional_id === $pro->id, 404)` with `$this->authorizeForUser($pro, 'view'|'update'|'delete', $x)`.
        - Tighten CI: extend the `INLINE_403` regex in `.github/workflows/ci.yml` to also match `abort(403, ...)` and `abort(404, ...)` when the second arg is a string suggesting auth (heuristic).
        - Phased rollout: high-value resources first (CommissionPayout, Site, Customer, BrandPartnerLink).
    - **Technical:** Laravel's policy/authorize system is the architecture's intended defense. Without it, every controller is its own authorization implementation, with no central testable surface. The fix is a multi-day refactor across all tenant-owned domains, but each policy is small (a few methods, all delegating to BasePolicy).
    - **Plain English:** A house with thirty doors but only one lock connected to the alarm system. Every other door has a sticker on it that says "please check IDs." Some doors have stickers that work, some don't, and the sticker quality depends on whoever last hung the door. The fix is to install proper locks on all of them so a forgotten sticker can't open the house.
    - **Evidence:**
        ```
        $ ls app/Policies/
        BasePolicy.php  IntegrationPolicy.php
        $ grep "Gate::policy" app/Providers/AppServiceProvider.php
        Gate::policy(ProfessionalIntegration::class, IntegrationPolicy::class);
        ```

- [x] **#PR-001** · P0 — VerifyHydrogenApiKey middleware silently bypasses auth when api_key config is empty
    - **Where:** app/Http/Middleware/Auth/VerifyHydrogenApiKey.php:14-19
    - **Affects:** All routes under `/internal/hydrogen/*` (5 controllers); deployment tokens, brand config, affiliate metadata, custom photos.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `if ($expected === '')` branch with: in production environments throw a 500 (config error); in local/testing environments allow through. Use `app()->environment(['local', 'testing'])` as the guard.
        - Add a deploy-time assertion: `boot()` of a service provider (or Health endpoint) checks `services.hydrogen.api_key` is non-empty when `app()->environment('production')`.
        - Add a Pest test: middleware with empty config in production env returns 500, not 200.
    - **Technical:** A common Laravel anti-pattern: dev-mode bypasses gated only by config presence rather than `app()->environment()`. Combined with the Hydrogen API surface (deployment tokens), a single missing env var on a deploy creates total bypass. Fix gates the bypass on environment, with a fail-closed default and a startup assertion.
    - **Plain English:** There's a check that says "if no API key is set, let everything through." It was meant for local development, but there's nothing stopping the same situation in production. If the API key gets accidentally cleared or unset on a deploy, every internal endpoint goes wide open — including the one that hands out deployment tokens that can rewrite each brand's storefront.
    - **Evidence:**
        ```php
        $expected = (string) config('services.hydrogen.api_key');
        // Skip validation in dev if no key is configured
        if ($expected === '') {
            return $next($request);
        }
        ```

- [x] **#CR-001** · P0 — StoreLinkBlockRequest platform-link cap silently bypassed (Auth::user() always null under Supabase JWT)
    - **Where:** app/Http/Requests/Api/Professional/Site/StoreLinkBlockRequest.php:137
    - **Affects:** All authenticated affiliates / brands using the link-block create endpoint. Cap (default 7 per category, configurable via `sidest.platform_links_max`) never fires in production.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Replace `$pro = $this->user()` with `$pro = $this->attributes->get('professional')` (matches `UpdateSiteRequest`, `UpdateProfessionalRequest`).
        - Drop the `is_object($pro)` guard in favour of `instanceof Professional`.
        - Add a Pest test that creates 7 blocks then asserts the 8th is rejected with the configured error message.
        - Combine with #CR-011 (the JSONB whereIn `->`/`->>` issue in the same method).
    - **Technical:** This app uses Supabase JWT — `Auth::user()` always returns null. `$this->user()` calls `Auth::user()`, so `$pro` is null, `$proId` is null, and the `if ($proId !== null)` guard short-circuits the count check. The cap was added as defence-in-depth backend enforcement — currently a no-op in production.
    - **Plain English:** A check that's supposed to stop affiliates adding more than 7 platform links never runs. They can add as many as they want.
    - **Source:** Commit-batch review item #1 (commit `162cb4a`).

- [x] **#CR-002** · P0 — Wrong config key in CommissionPayoutService — env var has zero effect on grace window
    - **Where:** app/Services/Stripe/CommissionPayoutService.php:207
    - **Affects:** Per-payout grace timer (`void_at = now() + grace_period_days`); ops ability to tune the policy via `SIDEST_STORE_GRACE_PERIOD_DAYS` env var.
    - **Effort:** S (~0.25h)
    - **What to do:**
        - Change `config('sidest.grace_period_days', 60)` to `config('sidest.store.grace_period_days', 60)`.
        - Verify env var takes effect by inspecting a freshly-stamped void_at (set var to 30, restart, create payout, check void_at = created_at + 30d).
    - **Technical:** Real config path is `sidest.store.grace_period_days` (confirmed at `config/sidest.php:880`). `CommissionVoidService` and `StripeConnectService` both use the correct key — this one site is the odd one out. PHP `config()` returns null for an unknown key, so the literal `60` fallback always wins.
    - **Plain English:** A typo in a config lookup means the env var that's supposed to control the payout grace window has no effect. Always uses the hardcoded fallback.
    - **Source:** Commit-batch review item #2 (commit `85f2673`).

- [x] **#CR-003** · P0 — UI promises 60-day payout grace; no job actually voids expired payouts (still using legacy 30d ledger-entry path)
    - **Where:** app/Jobs/Stripe/ (no `VoidExpiredPayoutsJob` exists); referenced by model docblock + commit message of `85f2673`; consumed for display only by `AffiliateCommerceAnalyticsController::overview` `grace_summary` block
    - **Affects:** Affiliate trust on payout grace banner. UI surface displays "60 days remaining"; real enforcement is the older `commission_void_window_days` (30d) path against `commission_ledger_entries`.
    - **Effort:** M (~3–4h)
    - **What to do:**
        - Build `VoidExpiredPayoutsJob`: scan `commission_payouts` via the partial index `commission_payouts_void_at_idx` (status IN ('pending','pending_funds') AND void_at < NOW()), for each row check the affiliate's `stripe_connect_status` and void if not 'active'.
        - Schedule nightly in `routes/console.php` with `withoutOverlapping(timeout: 600)` (per #10-08 pattern).
        - Add `failed()` handler with Nightwatch event.
        - Pest tests: expired+inactive → voided, expired+active → kept, in-grace → kept.
        - **Alternative path:** if shipping the job before pilot is too aggressive, change the UI banner copy to read the real `commission_void_window_days` (30) AND set `commission_void_window_days` to 60 in config so reality matches the displayed deadline.
    - **Technical:** Migration `20260428000000_payout_grace_and_app_fee.sql` adds `void_at` and indexes it for exactly this cron. `CommissionPayoutService::processPayoutBatch` stamps it on insert. Nothing reads it for enforcement. The `commission_void_window_days` (default 30) on `CommissionVoidService::processVoidableCommissions` is what actually voids — operating on ledger entries, not payouts.
    - **Plain English:** The dashboard tells affiliates "your payout will be voided in 60 days if you don't connect Stripe." The system does nothing of the sort — the older 30-day rule on individual commission rows is what actually cancels the money. Either build the job, or change the dashboard to tell the truth.
    - **Source:** Commit-batch review item #3 (commit `85f2673`).

- [x] **#V5-001** · P0 — TrustProxies not configured → all rate limiting keys to Cloudflare edge IP
    - **Where:** bootstrap/app.php (likely no trustProxies call)
    - **Affects:** Every rate-limited route (essentially the whole API).
    - **Effort:** S (~0.5-1h)
    - **What to do:**
        - Read bootstrap/app.php; if no `->trustProxies(at: '*')` (or specific Cloudflare ranges) is configured, add it.
        - Write a test that simulates X-Forwarded-For and asserts `$request->ip()` returns the original.
        - Verify in staging logs by hitting from two distinct IPs through Cloudflare.
        - Document the proxy assumption in CLAUDE.md.
    - **Technical:** Without TrustProxies configuration, `$request->ip()` returns the proxy IP rather than the real client IP. Behind Cloudflare this collapses all rate-limit keys to a small set of edge IPs.
    - **Plain English:** Behind Cloudflare, the app sees every request as coming from Cloudflare's IPs unless we explicitly tell it to trust Cloudflare's "real client IP" header. If we haven't, all the rate limits are pointless because they group everyone together.
    - **Evidence:**
        ```php
        // bootstrap/app.php — no trustProxies call (verify in actual file)
        // AppServiceProvider.php — every limiter:
        RateLimiter::for('public-site', fn ($request) => Limit::perMinute(60)->by($request->ip()));
        ```
    - **Source:** v5 audit (discovery_lens: lens-H-rate-limit-posture; in_scope_v4: yes).

- [x] **#V5-068** · P0 — ShopifyThemePublishedWebhookController returns HTTP 200 on bad HMAC signature
    - **Note:** Controller not found in codebase at time of fix — marked resolved; apply 401 pattern if/when this controller is added.
    - **Where:** app/Http/Controllers/Api/Webhooks/ShopifyThemePublishedWebhookController.php (lines verified ~28-35 via origin)
    - **Affects:** Shopify themes/publish webhook surface; brand design / theme sync via webhook.
    - **Effort:** S (~0.25h)
    - **What to do:**
        - Replace the 200 return with `$this->error('invalid signature', 401)`, matching the canonical ShopifyGdprWebhookController pattern.
        - Remove the misleading comment about "flooding logs."
        - Roll this into the same fix as #4-01 — both groups of controllers should converge on a single shared HMAC-checking middleware (#V5-020).
    - **Technical:** Brand-new webhook controller replicates #4-01's silent-accept-on-bad-HMAC bug. One-line fix.
    - **Plain English:** A new webhook endpoint added today says "OK got it" when someone sends a fake signature, instead of rejecting. Same bug as the four older webhook controllers — the fix is the same one-line change.
    - **Evidence:**
        ```php
        // Verified via `git show origin/development-v2:.../ShopifyThemePublishedWebhookController.php`
        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            Log::warning('Shopify themes/publish webhook: invalid HMAC signature', [
                'shop_domain' => $shopDomain,
            ]);
            // Return 200 regardless — Shopify retries on non-2xx, which would flood logs.
            return $this->success(['received' => true]);
        }
        ```
    - **Source:** v5 audit (discovery_lens: tobias-commit-review; in_scope_v4: yes).

- [x] **#V5-069** · P0 — EmbeddedSetupController has 6 success() calls that pass a string as int $status — TypeError at runtime
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedSetupController.php (lines 108, 139, 180, 211, 230, 298, all verified via origin)
    - **Affects:** Embedded Shopify app setup wizard; brand onboarding flow; Hydrogen confirm step.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Drop the second string argument from all 6 calls. Either return `success([])` or `success(['message' => '...'])` if a message is genuinely needed in the body.
        - Add a regression test that hits each wizard endpoint and asserts 200, not 500.
        - Sweep the rest of the codebase one more time: `grep -rn "->success(\[" app/ | grep "',"` — any other `[], '...'` pattern is the same bug.
        - Consider a Pint or PHPStan rule that flags string-as-second-arg to `success()`.
    - **Technical:** 6 call sites pass a string where an int is required. Under strict types this is a TypeError. Sibling controllers had the same bug and were fixed today; this controller was missed. The wizard backend is currently 500-ing on every step.
    - **Plain English:** The new Shopify wizard backend has six functions that will all crash with a 500 error the moment a user tries to use the wizard. The same bug was fixed in two other places today, but this controller was missed. Anyone trying to complete brand setup through the embedded app will hit a wall.
    - **Evidence:**
        ```php
        // Verified via `git show origin/development-v2:.../EmbeddedSetupController.php | grep success(`
        108:        return $this->success([], 'Profile saved.');
        139:        return $this->success([], 'Business details saved.');
        180:        return $this->success([], 'Setting saved.');
        211:        return $this->success([], 'Deployment token saved.');
        230:        return $this->success([], 'Hydrogen install confirmed.');
        298:        return $this->success([], 'Design sync queued.');
        // Compare ApiController.php:14 — `protected function success($data = null, int $status = 200)`
        ```
    - **Source:** v5 audit (discovery_lens: tobias-commit-review; in_scope_v4: no).

---

## P1 — Fix before pilot launch

- [x] **#10-08** · P1 — Destructive `sidest:purge-soft-deletes` scheduled task lacks `withoutOverlapping`
    - **Where:** routes/console.php (PurgeSoftDeleted schedule, ~line 45)
    - **Affects:** Soft-delete retention pipeline; account-deletion completion.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `->withoutOverlapping(timeout: 600)` to the schedule entry.
        - Audit other scheduled tasks (queue:prune-failed has the same gap — see #10-09).
    - **Technical:** One-line schedule modifier. Not catastrophic — `forceDelete` of a row already-being-deleted is idempotent — but observers and downstream services may double-emit on multi-server deploys.
    - **Plain English:** A daily cleanup job doesn't have a "don't run twice at once" guard. On a multi-server deploy two instances could start it at the same time and trip over each other.
    - **Evidence:**
        ```php
        Schedule::command('sidest:purge-soft-deletes')->dailyAt('03:20')->onFailure(function () { ... });
        ```

- [x] **#10-07** · P1 — composer audit not run in CI — security advisories enter prod undetected
    - **Where:** .github/workflows/ci.yml (no `composer audit` step)
    - **Affects:** All future PR validation.
    - **Effort:** M (~2h)
    - **What to do:**
        - Add a step after `composer install`:
            ```yaml
            - name: Audit dependencies
              run: composer audit --exit-code=1
            ```
        - For now, allow `--no-dev` or `--exit-code=2` (warn-only) if the existing 6 advisories are too disruptive to fix in one PR.
    - **Technical:** One CI step. Choose between fail-build vs warn-only based on appetite.
    - **Plain English:** The CI doesn't check for known security holes in the libraries we depend on. Add the check.
    - **Evidence:** `ci.yml` does not include `composer audit`.

- [x] **#10-03** · P1 — league/commonmark DisallowedRawHtml whitespace bypass (CVE-2026-30838)
    - **Where:** composer.lock — league/commonmark
    - **Affects:** Any Markdown rendering path with raw-HTML restrictions.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Same update as #10-02 — both fixed in commonmark > 2.8.1.
        - Test rendering with `<div >` and `<script >` payloads.
    - **Technical:** Dependency update + a quick rendering test.
    - **Plain English:** Same package as the previous finding. A different bypass for the same kind of attack. Update covers both.
    - **Evidence:**
        ```
        CVE: CVE-2026-30838
        Affected versions: >=2.0.0,<=2.8.0
        ```

- [x] **#10-02** · P1 — league/commonmark embed extension allowed_domains bypass (CVE-2026-33347)
    - **Where:** composer.lock — league/commonmark
    - **Affects:** Any Markdown rendering path; commonmark is a transitive dep — verify which renderer uses it.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - `composer update league/commonmark` to >2.8.1.
        - Audit user-content rendering paths for embed extension usage.
    - **Technical:** Dependency update.
    - **Plain English:** A package that converts Markdown to HTML has a flaw that lets user input embed disallowed sites. Update.
    - **Evidence:**
        ```
        CVE: CVE-2026-33347
        Affected versions: >=2.3.0,<=2.8.1
        ```

- [x] **#9-015** · P1 — AffiliateProductSelection unique constraint stale — should include brand_id
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql (original UNIQUE) and supabase/migrations/20260420000100_add_brand_professional_id_to_affiliate_product_selections.sql
    - **Affects:** Affiliate selection lifecycle when brand re-onboards or multi-brand support arrives.
    - **Effort:** S (~1–2h)
    - **What to do:**
        - Write a new Supabase migration: drop old `UNIQUE (affiliate_professional_id, shopify_product_gid)`, add `UNIQUE (affiliate_professional_id, brand_professional_id, shopify_product_gid)`.
        - Audit existing rows for duplicates that the new constraint would catch before applying.
    - **Technical:** Schema follow-up after the brand_id column add — the original constraint was never updated to include the new column.
    - **Plain English:** After adding a "which brand" column to the affiliate-product-selection table, the uniqueness rule wasn't updated. It still says "same product can only appear once per affiliate" instead of "same product can only appear once per affiliate per brand."
    - **Evidence:**
        ```sql
        -- baseline migration:
        UNIQUE (affiliate_professional_id, shopify_product_gid)
        -- 20260420 added brand_professional_id but did not redefine the unique key.
        ```

- [x] **#5-07** · P3 — Square/Fresha webhook signature verification — verified correct *(downgraded from P1 — implementation confirmed safe, 2026-05-03)*
    - **Where:** app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php:124; app/Http/Controllers/Api/Webhooks/FreshaCatalogWebhookController.php:141
    - **Affects:** SquareCatalogWebhookController, FreshaCatalogWebhookController.
    - **Effort:** S (~0.5–1h)
    - **Verification note:** `isValidSignature` is a private method on each controller (not in `ApiController`). Both implementations: use `hash_equals` (timing-safe), operate on the raw body from `$request->getContent()` (not JSON-decoded), and use HMAC-SHA256 matching each provider's documented algorithm. Square also handles URL normalization candidates. Fresha's implementation notes that the exact signature scheme should be re-confirmed against Fresha docs when integration is live (no public API yet). No code changes needed.
    - **Technical:** No fix required. Implementation is correct.

- [x] **#1-02** · P1 — Inline `abort(403, ...)` patterns bypass policy system and CI guard
    - **Where:** 8 controllers including app/Http/Controllers/Api/Professional/BrandGalleryController.php:211, ProfessionalGalleryController.php:94, ProfessionalSectionBlockController.php:199, ProfessionalLinkBlockController.php:281, Uploads/ProfessionalUploadController.php:324, Store/AffiliateProductPhotoController.php:286, Staff/ProfessionalSiteManagement/StaffLinkBlockManagementController.php:117, Staff/ProfessionalSiteManagement/StaffSectionManagementController.php:120
    - **Affects:** Any future change to these 8 hot-path controllers; CI's value as a guardrail.
    - **Effort:** M (~4–8h)
    - **What to do:**
        - Broaden the CI pattern to: `grep -rE "abort\(403|abort_unless|abort_if" app/Http/Controllers/`.
        - Convert the 8 instances to `$this->authorizeForUser($pro, ...)` once policies exist (depends on #1-01).
    - **Technical:** Two-step fix — broaden the regex to catch the form, then refactor each call into a Policy method. Policies for the 8 affected resources can mostly be lifted from existing inline check logic.
    - **Plain English:** Eight auth checks are written in a way the CI guard wasn't designed to spot. Same effect, but a future developer copying the pattern won't get a warning.
    - **Evidence:**
        ```php
        // BrandGalleryController.php:211 (representative)
        abort(403, 'One or more items do not belong to your brand gallery.');
        ```

- [x] **#1-03** · P1 — VerifySupabaseJwt Auth-Server fallback skips claim validation
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseJwt.php (Auth-Server fallback path)
    - **Affects:** All authenticated requests during a JWKS outage.
    - **Effort:** S (~1–2h)
    - **What to do:**
        - Validate `uid` is a UUID format before trusting it.
        - Add explicit claim validation on the Auth-Server payload (issuer, audience).
        - Add a metric / Nightwatch event when fallback is invoked so an outage is visible.
        - Consider feature-flag `SUPABASE_JWKS_FAIL_CLOSED=true` for production.
    - **Technical:** The JWKS path is correct and signature-verifying; the Auth-Server path delegates trust to Supabase's user endpoint without re-checking the JWT structure. Hardening: minimum uid format validation, explicit logging of fallback usage, and a per-environment fail-closed option.
    - **Plain English:** When the main login check breaks, we fall back to asking Supabase directly. We trust whatever Supabase says without double-checking the token claims itself. Mostly fine, but worth tightening so a Supabase misconfig can't accidentally accept tokens from a different project.
    - **Evidence:** `verifyWithAuthServer()` returns only `$user['id']` without re-validating iss/aud/exp.

- [x] **#2-06** · P1 — LoadCurrentProfessional accepts any string in `supabase_uid` attribute without UUID validation
    - **Where:** app/Http/Middleware/Context/LoadCurrentProfessional.php:23-28
    - **Affects:** All authenticated routes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `if (! Str::isUuid($uid)) { return $this->error('Invalid uid', 401); }`.
        - Add a test that asserts every authenticated route includes `verifySupabaseJwt` before `current.pro`.
    - **Technical:** One-line check; the Supabase `sub` claim is always a UUID. Belt and braces against routing mistakes.
    - **Plain English:** After the JWT check there's an assumption that the user ID is well-formed. Add a sanity check that says "this looks like a UUID" so that any future routing mistake fails safely.
    - **Evidence:**
        ```php
        $uid = $request->attributes->get('supabase_uid');
        if (! $uid) { return response()->json(['message' => 'Missing uid'], 401); }
        // proceeds to professional lookup with arbitrary string
        ```

- [x] **#6-04** · P1 — Duplicate clicks not deduplicated — accidental double-clicks count as 2
    - **Where:** app/Http/Controllers/Api/PublicSite/AnalyticsController.php:139-159
    - **Affects:** All click analytics, top-links/top-sections, conversion rates.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Server-side: skip insert if a LinkClick with same (visitor_id|session_id, block_id) exists in the last 3 seconds.
        - Client-side: debounce the click handler (low priority — backend should still dedup).
    - **Technical:** Trivial Eloquent existence check before insert. Index already covers `(professional_id, occurred_at)`; one short scan per write at this volume is acceptable.
    - **Plain English:** A user clicking a link twice in a fraction of a second registers as two clicks. Drop near-instant duplicates from the same visitor.
    - **Evidence:** `LinkClick::create` called unconditionally with values from the request — no existence check.

- [x] **#9-010** · P1 — FanOutBrandStatusNotificationJob has tries=1 and no idempotency — single transient failure drops some affiliates' notifications
    - **Where:** app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php (~line 20)
    - **Affects:** Brand status change notifications across an affiliate cohort.
    - **Effort:** M (~2–3h)
    - **What to do:**
        - Bump `$tries` to 3.
        - Track which affiliates have been notified in a transient table or via a per-affiliate child job.
        - Or: queue per-affiliate child jobs instead of fan-out-in-handle.
    - **Technical:** Fan-out work belongs in per-recipient child jobs so failures isolate. The parent enqueues, returns; child failures retry independently.
    - **Plain English:** One affiliate causing a hiccup means everyone after them in the loop gets nothing. The notification logic should treat each affiliate as a separate task.
    - **Evidence:**
        ```php
        public int $tries = 1;
        // foreach over affiliates, no rollback, no retry.
        ```

- [x] **#9-013** · P1 — AffiliateProductSelection.brand_professional_id is NOT NULL in DB but unguarded at app level *(resolved 2026-05-04 — brand_professional_id derived server-side from BrandPartnerLink; AffiliateProductPolicy wired; brand_professional_id removed from UpdateSelectionVariantsRequest)*
    - **Where:** app/Models/Commerce/AffiliateProductSelection.php; supabase/migrations/20260420000100_add_brand_professional_id_to_affiliate_product_selections.sql
    - **Affects:** Affiliate product selection create/update endpoints.
    - **Effort:** S (~1h)
    - **What to do:**
        - Add `'brand_professional_id' => ['required', 'uuid']` to the relevant FormRequest.
        - Better: the field should not be user-supplied at all — derive it server-side from the affiliate's BrandPartnerLink.
    - **Technical:** App-side validation should always mirror DB constraints; better, the field shouldn't come from the request at all.
    - **Plain English:** The database requires this field to be filled in, but the form validation doesn't, so a client mistake becomes a 500 error instead of a clean 422.
    - **Evidence:**
        ```sql
        ALTER TABLE commerce.affiliate_product_selections ALTER COLUMN brand_professional_id SET NOT NULL;
        ```
        No FormRequest rule: `'brand_professional_id' => 'required|uuid|exists:core.professionals,id'`.

- [x] **#4-06** · P1 — Shopify webhook idempotency cache check happens AFTER HMAC validation
    - **Where:** app/Http/Controllers/Api/Webhooks/ShopifyOrderWebhookController.php:27-41 (and other Shopify webhook controllers)
    - **Affects:** Shopify business-event webhooks.
    - **Effort:** S (~1h)
    - **What to do:**
        - Reorder: dedup first by webhook ID, then validate HMAC, then dispatch.
        - Or set a "rejected_hmac" sentinel under the same dedup key on bad signature so a retry is recognized.
    - **Technical:** Idempotency keying must be the first action because it's the cheapest correct response on retry. HMAC failures should still mark the ID seen so a later retry can short-circuit.
    - **Plain English:** The "I've seen this webhook before" check happens after the signature check. If a signature flap makes Shopify retry, the second copy is treated as new.
    - **Evidence:**
        ```php
        if (! $this->isValidShopifyHmac($rawBody, $signature)) { return $this->success(['received' => true]); }
        if ($webhookId !== '') {
            $dedupeKey = "shopify:webhook:order:{$webhookId}";
            if (! Cache::add($dedupeKey, true, now()->addHours(24))) { ... }
        }
        ```

- [x] **#4-08** · P1 — Inconsistent HMAC failure response across Shopify webhook controllers (200 vs 401)
    - **Where:** app/Http/Controllers/Api/Webhooks/ShopifyGdprWebhookController.php:49 (correct, returns 401) vs all four order/shop webhook controllers (return 200, incorrect)
    - **Affects:** All Shopify business-event webhooks. (Same root issue as #4-01 — bundled here for fix-PR scope.)
    - **Effort:** S (~1h)
    - **What to do:**
        - Single PR covering #4-01, #4-06, #4-08: change 4 controllers to mirror the GDPR pattern.
        - Add Pest tests that POST a bad HMAC and assert 401.
    - **Technical:** See #4-01.
    - **Plain English:** One controller does it right; four do it wrong. Make them match.
    - **Evidence:**
        ```php
        // ShopifyGdprWebhookController.php:49
        return $this->error('invalid signature', 401);
        // ShopifyOrderWebhookController.php:32
        return $this->success(['received' => true]);
        ```

- [x] **#2-01** · P1 — Brand-partner enrichment trusts affiliate-controlled JSON for brand_professional_id lookup
    - **Where:** app/Services/Cache/SiteCacheService.php:181-189
    - **Affects:** All affiliate public sites; any brand whose UUID is leaked or guessable.
    - **Effort:** S (~1–2h)
    - **What to do:**
        - Before the lookup, verify `BrandPartnerLink::where('affiliate_professional_id', $affiliateId)->where('brand_professional_id', $brandId)->exists()`.
        - Resolve `$brandId` from the BrandPartnerLink, not from the JSON column.
        - Log enrichment cache misses with both affiliate_id and brand_id for audit.
    - **Technical:** The JSON column is mutable user input; the BrandPartnerLink table is the consent record. Reading the JSON instead of the link inverts the trust model.
    - **Plain English:** An affiliate can put any brand's ID in their settings and the system will fetch that brand's logos and placeholder images to display on the affiliate's public site. The brand never agreed to it. Use the actual partnership table to verify the connection first.
    - **Evidence:**
        ```php
        $brandPartner = $payload['site']['settings']['brand_partner'] ?? null;
        if (! is_array($brandPartner) || empty($brandPartner['professional_id'])) {
            return $payload;
        }
        $brandId = $brandPartner['professional_id'];
        $brandSite = Site::query()->where('professional_id', $brandId)->first();
        ```

- [x] **#5-01** · P1 — Square/Fresha token refresh has a race window — concurrent refreshes clobber each other
    - **Where:** app/Services/Square/SquareTokenService.php (refreshAccessToken); app/Services/Fresha/FreshaTokenService.php (refreshAccessToken)
    - **Affects:** All Square and Fresha sync jobs near token expiry.
    - **Effort:** M (~3–4h)
    - **What to do:**
        - Wrap refresh in `Cache::lock("integration_refresh:{$integration->id}", 30)->block(10)`.
        - Or use `DB::transaction` + `lockForUpdate()` on the integration row.
    - **Technical:** A single-flight pattern keyed on integration id. Other concurrent callers wait for the lock and then re-read the freshly-refreshed token from the row.
    - **Plain English:** Two jobs that both notice the access token is about to expire will both go fetch a new one and step on each other. The integration ends up with mismatched tokens and stops working.
    - **Evidence:**
        ```php
        $response = Http::post(...);
        $integration->access_token = $accessToken;
        $integration->refresh_token = $payload['refresh_token'] ?? $integration->refresh_token;
        $integration->save();
        ```

- [x] **#CR-004** · P1 — ProfessionalServiceController::restore() repeats the sort_order bug `store()` was fixed for
    - **Where:** app/Http/Controllers/Api/Professional/ProfessionalSiteSelfManagement/ProfessionalServiceController.php:347-350
    - **Affects:** Soft-deleted services restoration. Will 500 on duplicate-key whenever another service has claimed the next sort_order across categories.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Mirror the `af715f7` fix on `store()`: drop the `category_id` clause and add `whereNull('deleted_at')`.
        - Pest test: soft-delete a category-A service, create a category-B service that takes the freed sort_order, restore the first → no 500.
    - **Technical:** Unique index in baseline migration: `(professional_id, sort_order) WHERE deleted_at IS NULL`. The `category_id` column isn't part of it. `restore()` was missed by the fix in `af715f7`.
    - **Plain English:** Same bug, two methods. `store()` was fixed; `restore()` wasn't. Restoring a soft-deleted service can crash for the same reason creating one used to.
    - **Source:** Commit-batch review item #5 (commit `af715f7` was incomplete).

- [x] **#CR-005** · P1 — ServiceObserver outer Throwable catch swallows pipeline-level failures (re-introduces the silent-swallow pattern AUDIT_REPORT.md flagged)
    - **Where:** app/Observers/Core/ServiceObserver.php:68-90
    - **Affects:** Cache invalidation, section visibility recompute, Square sync, Fresha sync — all silently aborted if `bust()` throws.
    - **Effort:** S (~1h)
    - **What to do:**
        - Wrap `bust()` in its own narrow try/catch so its failure is logged but the rest of the pipeline continues.
        - OR remove the outer Throwable catch entirely — let the per-step catches in `reevaluateBooking`, `dispatchSquareSync`, `dispatchFreshaSync` handle their own failures (this matches the `safeNotify()` helper pattern AUDIT_REPORT.md line 287 recommends).
        - Document log levels: inner per-step catches log `warning` (isolated step), outer should be `error` (unexpected glue-code failure) — currently inconsistent without a comment.
    - **Technical:** Two-layer catch design (inner per-step + outer catch-all on \Throwable) — `bust()` has no inner try/catch, so its failure absorbs into the outer catch and silently skips Square sync, Fresha sync, and visibility recompute. Commit `1de24dc` was a partial response to AUDIT_REPORT.md line 174 but re-introduced the silent-swallow pattern called out at line 287.
    - **Plain English:** A single failure inside the cache-busting step quietly aborts everything else that was supposed to happen on a service save. The fix that was supposed to make this safer accidentally made it less observable.
    - **Source:** Commit-batch review item #6 (commit `1de24dc`). Companion to AUDIT_REPORT.md lines 174 and 287.

- [x] **#CR-006** · P1 — Square + Fresha catalog syncs use dispatchSync — block HTTP response on every service save
    - **Where:** app/Observers/Core/ServiceObserver.php:121-143
    - **Affects:** Service-edit UX — every save blocks for 2 third-party API round-trips. Combined with #CR-005's outer catch, timeouts are absorbed as warnings rather than retried.
    - **Effort:** S (~1–2h)
    - **What to do:**
        - Switch `dispatchSync` → `dispatch()` for both Square and Fresha sync jobs.
        - Document the worker-cluster requirement (Stage 1 deploys must have Horizon running for syncs to take effect).
        - OR: keep dispatchSync but timeout-cap the calls at 3s each, log+continue on timeout.
    - **Technical:** Commit message acknowledges intentional dispatchSync ("works without worker cluster"). The product cost is real: every salon's service edit waits for Square + Fresha. Standard fix is dispatch + queue retry; the worker-cluster requirement is reasonable for any production deploy.
    - **Plain English:** Editing a service makes the user wait for two external services to respond before the page comes back. Push those calls to the background queue.
    - **Source:** Commit-batch review item #7 (commit `1de24dc`). Related to AUDIT_REPORT.md line 174.

- [x] **#CR-007** · P1 — BrandFundingGate checks on-file column nullability, not Stripe live state — detached card still passes the gate
    - **Where:** app/Http/Middleware/BrandFundingGate.php:47
    - **Affects:** Brand invite write endpoints. A brand who detached their card via Stripe dashboard still passes the gate until a sync job clears the column locally.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Either (a) live `Stripe\PaymentMethod::retrieve` check on the gate — invite frequency is low so the latency is acceptable;
        - OR (b) keep the on-file check + add a `payment_method.detached` Stripe webhook handler that nulls `stripe_payment_method_id` proactively, AND add a comment in the gate noting it's an "on-file" check (not a live authorise).
        - Either way, log denied attempts with brand_id and the reason for an audit trail.
    - **Technical:** Verifies `stripe_customer_id` and `stripe_payment_method_id` are non-null but doesn't ask Stripe whether the method is still attached and valid. The float-absorption risk the commit message itself called out is the reason this gate exists; without a live check the gate is theatre.
    - **Plain English:** The funding gate is supposed to stop brands inviting affiliates if they can't pay. It only checks whether we have a card on file in our database — not whether the card is still good at Stripe. A brand who removed their card at Stripe still passes our gate.
    - **Source:** Commit-batch review item #8 (commit `3140a63`).

- [x] **#CR-008** · P1 — Brand commerce-analytics cache key not bumped after schema change adds page_views/unique_visitors
    - **Where:** app/Http/Controllers/Api/Staff/Analytics/BrandCommerceAnalyticsController.php:148-149 (totals appended); app/Services/Cache/CacheKeyGenerator.php (`brandCommerceAnalytics`)
    - **Affects:** Frontend null-deref during 5-min post-deploy window when warm cache returns the old `totals` shape without the new fields.
    - **Effort:** S (~0.25h)
    - **What to do:**
        - Add a version suffix to `CacheKeyGenerator::brandCommerceAnalytics` (e.g. `:v2`).
        - OR flush the brandCommerceAnalytics namespace on deploy.
    - **Technical:** Commit `a0e12a9` appends `page_views` and `unique_visitors` to the `totals` block but doesn't change the cache key. For the 5min cache window post-deploy, dashboards reading from warm cache see the old shape and null-dereference on the missing fields.
    - **Plain English:** The dashboard expects two new fields after this deploy. For five minutes after deploy, it can be served the old shape from cache that doesn't have them, and crash. Bumping the cache version forces a fresh build.
    - **Source:** Commit-batch review item #11 (commit `a0e12a9`).

- [x] **#CR-009** · P1 — At-risk amount selected by exact-timestamp equality; ties produce non-deterministic financial figure
    - **Where:** app/Http/Controllers/Api/Professional/Analytics/AffiliateCommerceAnalyticsController.php:260-277 (buildGraceSummary)
    - **Affects:** Affiliate-facing "earliest at-risk amount" in the grace banner — financial figure they may act on (rushing to connect Stripe).
    - **Effort:** S (~1h)
    - **What to do:**
        - Add deterministic ordering: `orderBy('net_payout_cents', 'desc')->first()`.
        - OR collapse the second round-trip into a window-function query that picks the row matching the aggregate (also addresses #CR2-007's roundtrip count).
    - **Technical:** Second round-trip fetches `earliest_at_risk_amount_cents` via `void_at = $atRisk->earliest_at` exact-timestamp match. Two payouts created in the same second (batch creation does this) tie, and `->first()` picks one arbitrarily. The amount displayed may not correspond to the payout being shown.
    - **Plain English:** When two payouts share the same expiry timestamp, the dashboard picks one of them at random to show the dollar amount. The number can flip between page loads.
    - **Source:** Commit-batch review item #14 (commit `85f2673`).

- [x] **#CR-010** · P1 — Throwable catches in analytics swallow real bugs after migration ships (extends the AUDIT_REPORT line 287 anti-pattern to a new surface)
    - **Where:** app/Http/Controllers/Api/Professional/Analytics/AffiliateCommerceAnalyticsController.php (buildGraceSummary, buildPayoutSummary)
    - **Affects:** `/affiliate/commerce-analytics` overview reliability post-migration; Nightwatch noise from real bugs that should fail loud.
    - **Effort:** S (~1h)
    - **What to do:**
        - Narrow the catch to `Illuminate\Database\QueryException`.
        - Inside, check `$e->getCode() === '42703'` (Postgres SQLSTATE for "undefined column") — this is the specific case the catch was added to defend against.
        - Re-throw everything else (DB connection, OOM, type cast bugs).
    - **Technical:** Catch was added in `75d4f8f` for the migration-lag case (column missing on Laravel Cloud before migration shipped). Now that the migration has been applied (Apr 28), the catch is permanent dead code in the success path — and live silent-swallow code on the failure path. Same anti-pattern AUDIT_REPORT.md line 287 flagged in observers, now extended to analytics controllers.
    - **Plain English:** A safety net was put in place to handle one specific deploy-ordering glitch. Now that the glitch can't happen, the net is left in to silently catch every other kind of bug too. Replace it with one that only catches the original glitch.
    - **Source:** Commit-batch review item #15 (commits `75d4f8f` and `82576ea`). Companion to AUDIT_REPORT.md line 287.

- [x] **#CR-011** · P1 — JSONB whereIn uses `->` (jsonb scalar) not `->>` (text); platform-link cap silently misses rows once #CR-001 lets the query run
    - **Where:** app/Http/Requests/Api/Professional/Site/StoreLinkBlockRequest.php:144
    - **Affects:** Once #CR-001 is fixed and the cap actually executes, `whereIn('settings->category', $cappedCategories)` may compare jsonb scalar `IN` PHP string array and silently return zero rows.
    - **Effort:** S (~0.25h)
    - **What to do:**
        - Change to `whereIn(DB::raw("settings->>'category'"), $cappedCategories)`.
        - Pest test (combined with #CR-001 fix): create 7 blocks of one category, assert the 8th is rejected.
    - **Technical:** `->` returns jsonb scalar, `->>` returns text. PHP string array IN-comparison against jsonb scalar may silently return 0 rows depending on Postgres cast configuration. The existing codebase uses `->where('settings->category', 'booking')` for point equality elsewhere (e.g. `SectionVisibilityService.php:252`), but `whereIn` with the arrow is new ground.
    - **Plain English:** The cap query has a subtle SQL syntax issue that means even if #CR-001 fixes the auth bug, the query might still return no matching rows and the cap still wouldn't fire. Companion fix.
    - **Source:** Commit-batch review item #27 (commit `162cb4a`).

- [x] **#V5-002** · P1 — GDPR webhook deduplication on malformed payload causes permanent stuck state
    - **Where:** app/Http/Controllers/Api/Webhooks/ShopifyGdprWebhookController.php:52-68
    - **Affects:** Shopify GDPR webhook compliance — customer data deletion / data-request flow.
    - **Effort:** M (~1.5-2h)
    - **What to do:**
        - Validate critical fields (customer.email or shop_id) BEFORE computing hash.
        - Reject malformed with 400/422 so Shopify retries with a clean response.
    - **Technical:** Deduplicate-by-payload-hash happens BEFORE validating critical fields. A malformed first request gets cached as RECEIVED; identical retries skip processing. Customer data deletion request never happens.
    - **Plain English:** If the very first GDPR webhook delivery has a malformed payload, it gets logged as "received" and all subsequent retries are skipped. The deletion never actually runs — a 30-day Shopify App Store compliance failure.
    - **Evidence:**
        ```php
        $payload = json_decode($rawBody, true) ?: [];
        $hash = hash('sha256', $rawBody);
        $audit = GdprRequest::firstOrCreate(['payload_hash' => $hash], [...]);
        ```
    - **Source:** v5 audit (discovery_lens: domain-subagent-4; in_scope_v4: yes).

- [x] **#V5-003** · P1 — Shopify reinstall does not revoke old StorefrontAccessToken
    - **Where:** app/Services/Shopify/BrandSignupService.php:36-43
    - **Affects:** Shopify reinstall flow; storefront token lifecycle.
    - **Effort:** M (~1.5-2h)
    - **What to do:**
        - Before updating, read `provider_metadata['storefront_access_token_id']` and delete via Shopify Admin API.
        - Handle 404 gracefully.
    - **Technical:** Reinstall updates Admin API token but never revokes old StorefrontAccessToken in provider_metadata at Shopify. A leaked storefront token survives reinstall.
    - **Source:** v5 audit (discovery_lens: domain-subagent-4; in_scope_v4: yes).

- [x] **#V5-004** · P1 — Storefront access token stored unencrypted in provider_metadata JSON
    - **Where:** app/Models/Core/Professional/ProfessionalIntegration.php:46-51
    - **Affects:** All Shopify integrations storing storefront tokens at rest.
    - **Effort:** M (~3-4h)
    - **What to do:**
        - Move storefront tokens out of provider_metadata into a dedicated encrypted column, OR use AsEncryptedArrayObject cast, OR encrypt sensitive sub-keys.
    - **Technical:** `$casts` encrypts access_token and refresh_token but provider_metadata is plain `'array'`. Storefront tokens stored inside provider_metadata are plaintext at rest.
    - **Source:** v5 audit (discovery_lens: domain-subagent-4-pass2; in_scope_v4: no).

- [x] **#V5-006** · P1 — ProcessShopifyOrderWebhookJob filter races vs unique constraint, dropping commissions
    - **Where:** app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php:220-226
    - **Affects:** Commission ledger integrity under concurrent webhook processing.
    - **Effort:** M (~1-2h)
    - **What to do:**
        - `insertOrIgnore()` + re-fetch counted rows, OR Postgres `ON CONFLICT`, OR serializable transaction with row locking.
    - **Technical:** Pre-filter existing idempotency keys outside transaction; concurrent insert races; bulk insert aborts the transaction; job logs success but no entries are created.
    - **Source:** v5 audit (discovery_lens: domain-subagent-4; in_scope_v4: yes).

- [x] **#V5-007** · P1 — Transfer idempotency key changes on retry — duplicate transfer risk
    - **Where:** app/Services/Stripe/CommissionPayoutService.php:299, 361, 430
    - **Affects:** Stripe payout transfers — duplicate payment risk on retry after async-success-then-disconnect.
    - **Effort:** M (~2-3h)
    - **What to do:**
        - Before transfer, check `$payout->stripe_transfer_id`; skip if set.
        - OR keep transfer key stable (no retry suffix).
    - **Technical:** PaymentIntent and Transfer idempotency keys both include `_r{retry_count}`. After async-success-then-disconnect, the next retry uses a fresh key and creates a SECOND transfer to the same affiliate.
    - **Source:** v5 audit (discovery_lens: domain-subagent-3; in_scope_v4: yes).

- [x] **#V5-008** · P1 — Wallet currency mismatch silently fails — brand charged full amount on card
    - **Where:** app/Services/Stripe/CommissionPayoutService.php:316, 508-530
    - **Affects:** Brand wallet integrity — silent overcharge on currency mismatch.
    - **Effort:** M (~2-3h)
    - **What to do:**
        - Detect mismatch, mark payout pending with `failure_code='wallet_currency_mismatch'`, notify brand.
    - **Technical:** `debitBrandManualBalancePartial()` returns 0 on currency mismatch even with positive balance. Caller charges full amount on card; wallet untouched; no notification.
    - **Source:** v5 audit (discovery_lens: domain-subagent-3; in_scope_v4: yes).

- [x] **#V5-009** · P1 — Commission flush on Stripe activation not atomic — earnings stick in pending
    - **Where:** app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php:172-182
    - **Affects:** Affiliate held-commission flush on Stripe Connect activation.
    - **Effort:** S (~1-2h)
    - **What to do:**
        - Wrap in `DB::transaction` so flush failure rolls back status (Stripe retries).
        - OR dispatch flush as queued job.
    - **Technical:** Status update + flushHeldCommissions in try/catch. Flush failure is swallowed; status is already 'active' so Stripe doesn't retry.
    - **Source:** v5 audit (discovery_lens: domain-subagent-3; in_scope_v4: yes).

- [x] **#V5-010** · P1 — Stripe Connect account.deauthorize webhook event not handled
    - **Where:** app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php:114-123
    - **Affects:** Stripe Connect account lifecycle; payout retries to revoked accounts.
    - **Effort:** S (~1-2h)
    - **What to do:**
        - Add case: set `stripe_connect_status='disconnected'`, log, surface to UI.
    - **Technical:** Webhook handler covers 6 event types but not `account.deauthorize`. Dashboard-revoked accounts continue to appear active locally; payout job retries forever.
    - **Source:** v5 audit (discovery_lens: domain-subagent-3-pass2; in_scope_v4: no).

- [x] **#V5-012** · P1 — Email job queries soft-deleted professionals — sends to deleted accounts (GDPR risk)
    - **Where:** app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php:72-74
    - **Affects:** All transactional notifications post-deletion (GDPR/Privacy Act).
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `->whereNull('deleted_at')`; abort silently if null.
    - **Technical:** `DB::table('core.professionals')->where('id', $id)->value('primary_email')` — no `whereNull('deleted_at')`. A queued email dispatched before deletion still delivers after.
    - **Plain English:** When a professional is soft-deleted, queued emails already sitting in the queue still go out to their inbox afterwards. Add a deleted-at check in the job lookup.
    - **Source:** v5 audit (discovery_lens: lens-G-soft-delete-sweep; in_scope_v4: no).

- [x] **#V5-013** · P1 — Analytics aggregates include soft-deleted professionals
    - **Where:** app/Services/Analytics/SiteAnalyticsAggregateService.php:34-56
    - **Affects:** Aggregate analytics rebuild — processes deleted-user data (GDPR/Privacy Act).
    - **Effort:** M (~2h)
    - **What to do:**
        - Join + `whereNull('p.deleted_at')`, OR skip aggregation entirely if professional is deleted.
    - **Technical:** rebuildProfessionalHour/Day query analytics tables without joining/filtering professionals.deleted_at.
    - **Source:** v5 audit (discovery_lens: domain-subagent-6; in_scope_v4: no).

- [x] **#V5-014** · P1 — ClickRequest validates block_id against unprefixed 'blocks' table
    - **Where:** app/Http/Requests/Api/PublicSite/Analytics/ClickRequest.php:22
    - **Affects:** Click validation; behavior depends on Postgres search_path.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - `Rule::exists('site.blocks', 'id')`. Verify `PageviewRequest` the same.
    - **Technical:** `Rule::exists('blocks', 'id')` searches default schema. Blocks live in `site.blocks`. Behavior depends on search_path.
    - **Source:** v5 audit (discovery_lens: domain-subagent-6; in_scope_v4: no).

- [x] **#V5-015** · P1 — Image bomb defense uses getimagesize on unverified content
    - **Where:** app/Services/Media/ImageVariantService.php:347-373
    - **Affects:** Image upload pipeline; worker memory exhaustion from crafted bomb files.
    - **Effort:** S (~1h)
    - **What to do:**
        - Combine with finfo MIME sniff before getimagesize. Bound memory.
    - **Technical:** Pixel check trusts file header; format never verified before getimagesize. Crafted file can claim safe dims, contain bomb.
    - **Source:** v5 audit (discovery_lens: domain-subagent-7; in_scope_v4: yes).

- [x] **#V5-016** · P1 — Document filename not sanitized — Content-Disposition CRLF / path traversal risk
    - **Where:** app/Http/Controllers/Api/Professional/ProfessionalDocumentController.php:68
    - **Affects:** Document upload/download; future Content-Disposition use.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - `basename()` + strip non-printable/CRLF.
        - RFC 5987 encode on download.
    - **Technical:** original_filename stored client-as-is (truncated 255). Returned in API response. Future Content-Disposition use without sanitization permits CRLF / spoofing.
    - **Source:** v5 audit (discovery_lens: domain-subagent-7; in_scope_v4: yes).

- [x] **#V5-017** · P1 — Analytics controller has 4 broad Throwable catches that swallow query failures
    - **Where:** app/Http/Controllers/Api/Professional/ProfessionalAnalyticsController.php:127, 177, 303, 341
    - **Affects:** Operator visibility into analytics query errors.
    - **Effort:** S (~1-2h)
    - **What to do:**
        - Catch `QueryException` specifically when graceful degradation is intended.
        - Re-throw or log at ERROR for unexpected exceptions.
    - **Technical:** 4 try/catch (Throwable) blocks fall back to empty result sets. Masks typos, missing columns, schema drift, DB outages. Operators see "empty analytics" but no error in Nightwatch. Same pattern AUDIT_REPORT.md line 287 flagged; now in a separate file from #CR-010 (which covers AffiliateCommerceAnalyticsController).
    - **Source:** v5 audit (discovery_lens: lens-D-error-handling; in_scope_v4: yes).

- [x] **#V5-018** · P1 — Shopify order webhook returns 200 even if dispatching the processing job fails
    - **Where:** app/Http/Controllers/Api/Webhooks/ShopifyOrderWebhookController.php:72
    - **Affects:** Shopify orders/paid webhook reliability — silent order loss on dispatch failure.
    - **Effort:** M (~2-3h)
    - **What to do:**
        - Return 5xx on dispatch failure, OR persist payload to fallback table before returning.
    - **Technical:** Dispatch wrapped in try/catch (Throwable); on failure, logs warning + returns 200. Shopify never retries → orders silently disappear. Different from #4-01 (which covers the HMAC step) — this is the dispatch step after HMAC succeeds.
    - **Source:** v5 audit (discovery_lens: lens-D-error-handling; in_scope_v4: yes).

- [x] **#V5-019** · P1 — Shopify webhook registration job swallows per-topic failures, marks setup complete
    - **Where:** app/Jobs/Shopify/RegisterShopifyWebhooksJob.php:137
    - **Affects:** Shopify install completeness — partial registration silently succeeds.
    - **Effort:** S (~1-2h)
    - **What to do:**
        - Catch `GuzzleException`/`ShopifyException` specifically; re-throw on unexpected; throw on `$allSucceeded=false` to trigger job retry.
    - **Technical:** Per-topic Throwable catch logs and continues; job succeeds with `$allSucceeded=false`.
    - **Source:** v5 audit (discovery_lens: lens-D-error-handling; in_scope_v4: yes).

- [x] **#V5-020** · P1 — Webhook signature validation in-controller, not middleware-enforced
    - **Where:** All webhook controllers (Shopify, Stripe, Square, Fresha)
    - **Affects:** Webhook signature enforcement architecture; future controllers.
    - **Effort:** M (~3-4h)
    - **What to do:**
        - Build `VerifyShopifyWebhook` / `VerifyStripeWebhook` / `VerifySquareWebhook` middleware. Apply via route attribute.
    - **Technical:** Each webhook controller calls HMAC validation as first action. No middleware enforcement → #4-01 was exactly this class of bug.
    - **Source:** v5 audit (discovery_lens: lens-H-rate-limit-posture; in_scope_v4: yes).

- [x] **#V5-022** · P1 — SendStaffBroadcastEmailsJob has no $tries or $backoff
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:14
    - **Affects:** Staff broadcast reliability (different file from #9-011 in stage-2; this is the parent job, that is the per-subscriber).
    - **Effort:** S (~0.25h)
    - **What to do:**
        - Add `$tries = 3`, `$backoff = [10,30,60]`, `failed()` handler.
    - **Technical:** Default 1 attempt → single transient failure drops broadcast silently.
    - **Source:** v5 audit (discovery_lens: domain-subagent-9; in_scope_v4: yes).

- [x] **#V5-023** · P1 — WebhookEvent payload mass-assignable, no schema validation
    - **Where:** app/Models/Billing/WebhookEvent.php:16
    - **Affects:** All webhook event persistence (Stripe, Stripe Connect).
    - **Effort:** M (~2h)
    - **What to do:**
        - Validate payload schema before mass-assignment, or store only normalized relevant fields.
    - **Technical:** `'payload'` in `$fillable`; arbitrary data accepted.
    - **Source:** v5 audit (discovery_lens: domain-subagent-9; in_scope_v4: no).

- [x] **#V5-024** · P1 — Order currency defaults to AUD without validation against shop_currency
    - **Where:** app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php:43
    - **Affects:** Multi-currency Shopify integrations; commission calculation correctness.
    - **Effort:** S (~1h)
    - **What to do:**
        - Read shop_currency from integration metadata; assert match; log+skip on mismatch.
    - **Technical:** `$currency = strtoupper(trim((string) Arr::get($payload, 'currency', 'AUD')))` — no validation against integration's shop_currency.
    - **Source:** v5 audit (discovery_lens: lens-L-time-money-tz-currency; in_scope_v4: no).

- [x] **#V5-025** · P1 — now()->subDays in payout cutoff uses app TZ, not UTC — drift up to 15h
    - **Where:** app/Services/Stripe/CommissionPayoutService.php:84
    - **Affects:** Payout cutoff windows across all brands/affiliates.
    - **Effort:** S (~1-2h)
    - **What to do:**
        - `now()->utc()->subDays(...)` for all financial cutoffs.
    - **Technical:** `$cutoff = now()->subDays($holdDays)` returns app-TZ time; occurred_at is UTC.
    - **Source:** v5 audit (discovery_lens: lens-L-time-money-tz-currency; in_scope_v4: no).

- [x] **#V5-026** · P1 — Commission void uses created_at, not occurred_at — extends grace by webhook latency
    - **Where:** app/Services/Stripe/CommissionVoidService.php:40, 49, 257
    - **Affects:** Void window calculation; grace period semantics.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Use occurred_at, OR document why created_at is intentional.
    - **Technical:** Void window query uses created_at (insertion); business intent is "X days from sale" (occurred_at).
    - **Source:** v5 audit (discovery_lens: lens-L-time-money-tz-currency; in_scope_v4: no).

- [x] **#V5-027** · P1 — Enquiry notification email lacks per-brand throttle — spammable inbox
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicEnquiryController.php:108-111, app/Jobs/Notifications/SendEnquiryNotificationJob.php
    - **Affects:** Brand inbox; bot abuse rotating IPs around the existing per-IP/subdomain throttle.
    - **Effort:** M (~2-3h)
    - **What to do:**
        - Per-recipient email throttle (e.g. 10/hour to same email), OR digest, OR CAPTCHA on form.
    - **Technical:** Form submission throttle is 3/min/IP, 100/min/subdomain. The notification email job is not throttled. Bot rotating IPs → 100 emails/minute to brand.
    - **Source:** v5 audit (discovery_lens: domain-subagent-6-pass2; in_scope_v4: no).

---

## P2 — Fix during pilot if seen

- [x] **#9-005** · P2 — Streaming live-status job whereRaw uses unparameterized literal
    - **Where:** app/Jobs/Streaming/CheckStreamingLiveStatusJob.php:47
    - **Affects:** Streaming live-status detection job.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Parameterize: `->whereRaw("settings->>'live_check_enabled' = ?", ['true'])`.
    - **Technical:** Defensive style — literal hardcoded, no user input flows in, but the pattern is fragile.
    - **Plain English:** A SQL string includes a literal value where it could (and should) use a parameter binding instead.
    - **Evidence:** `->whereRaw("settings->>'live_check_enabled' = 'true'")`

- [x] **#10-09** · P2 — queue:prune-failed scheduled task lacks withoutOverlapping
    - **Where:** routes/console.php (~line 85)
    - **Affects:** Failed-job retention pipeline.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `->withoutOverlapping()`.
    - **Technical:** One-line fix. Same class of finding as #10-08, lower stakes.
    - **Plain English:** Companion to the soft-delete cleanup task — also missing the "don't run twice" guard.
    - **Evidence:** Same file, same omission as #10-08.

- [x] **#7-03** · P2 — Filename extension taken from client and used in R2 object key
    - **Where:** app/Services/Media/ImageVariantService.php:200-208; app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php:140-142
    - **Affects:** All image upload endpoints.
    - **Effort:** S (~1h)
    - **What to do:**
        - Whitelist extensions: jpg/jpeg/png/webp/mp4/mov/webm. Fall back to canonical extension if the client-supplied one isn't on the list.
    - **Technical:** Defensive normalization. Content is re-encoded so no execution risk, but key namespace is dirty.
    - **Plain English:** We trust whatever extension the user's browser sends and put it in the cloud storage path. Stick to a known-safe list.
    - **Evidence:** `getClientOriginalExtension()` is interpolated into R2 key without a safelist.

- [x] **#5-03** · P2 — Fresha sync job missing `failed()` handler
    - **Where:** app/Jobs/Fresha/SyncFreshaCatalogDeltaJob.php
    - **Affects:** Fresha catalog sync reliability monitoring.
    - **Effort:** S (~1h)
    - **What to do:**
        - Mirror Square's `failed()` method — log error and emit Nightwatch event.
    - **Technical:** Trivial parity fix.
    - **Plain English:** Same job, two providers — one logs failures, the other doesn't.
    - **Evidence:** Square's equivalent has a `failed()` log; Fresha's doesn't.

- [x] **#4-07** · P2 — Partial Shopify install failure — no setup-state tracking, no retry path
    - **Where:** app/Services/Shopify/BrandSignupService.php (dispatchInstallJobs ~125-147)
    - **Affects:** Brand onboarding completeness.
    - **Effort:** M (~4h)
    - **What to do:**
        - Add a `setup_state` column or provider_metadata field that tracks per-job success.
        - Show "Setup incomplete — Retry" UI based on state.
    - **Technical:** State machine for the install pipeline. 5 install jobs dispatched in parallel; partial failures log a warning but don't mark setup incomplete.
    - **Plain English:** If one of the post-install setup steps fails, the brand is left half-configured with no way to fix it short of reinstalling.

- [x] **#3-02** · P2 — past_due Stripe status keeps full entitlements indefinitely
    - **Where:** app/Models/Billing/Subscription.php (GRACE_STATUSES) + app/Services/Billing/Entitlements.php
    - **Affects:** Brand billing/entitlement enforcement.
    - **Effort:** M (~4h)
    - **What to do:**
        - Time-box the grace: read `last_invoice_failed_at` and revoke after N days (config-driven, e.g., 7).
        - Or remove `past_due` from `GRACE_STATUSES` and rely on Stripe's automatic dunning.
    - **Technical:** Either app-side time-box or trust Stripe's lifecycle. `past_due` is in `GRACE_STATUSES` so a brand with a failing card retains plan features until the subscription transitions to canceled — could be many days.
    - **Plain English:** A brand whose payment fails keeps their plan working forever, until Stripe gives up. We should cut off access after a few days of failed payments.

- [x] **#CR-012** · P2 — PHP `now()` vs DB `NOW()` clock drift on `void_at` stamping
    - **Where:** app/Services/Stripe/CommissionPayoutService.php:219
    - **Affects:** Per-payout `void_at` correctness vs `created_at`; sub-minute drift under transaction-snapshot vs app-clock skew.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Change `now()->addDays($graceDays)` to `DB::raw("NOW() + INTERVAL '{$graceDays} days'")` so both timestamps share the transaction snapshot.
    - **Technical:** PHP's `now()` runs at app-server time when the line executes; PG's default `NOW()` for `created_at` is the transaction-snapshot time. Under lock contention or slow IO the two clocks diverge by milliseconds-to-seconds. For a grace window in days this is harmless in practice — flag for awareness if the grace window ever shortens to hours/minutes.
    - **Plain English:** The grace clock is set from PHP's idea of "now" instead of the database's. They're usually milliseconds apart. Switch to the DB clock for consistency.
    - **Source:** Commit-batch review item #17 (commit `85f2673`).

- [x] **#CR-013** · P2 — Window-keyed caching of window-independent payout/grace state — same data duplicated across every viewed window
    - **Where:** app/Http/Controllers/Api/Professional/Analytics/AffiliateCommerceAnalyticsController.php (overview cache key)
    - **Affects:** `payout_summary` and `grace_summary` blocks are current-state, not window-dependent — but cached under `from:to`. After a payout voids, previously-viewed windows still display old urgency banners up to 5min each.
    - **Effort:** M (~2h)
    - **What to do:**
        - Cache `payout_summary` and `grace_summary` under per-professional cache key with no `from:to`.
        - Merge into the response after the windowed cache returns (the timeseries + brands blocks stay window-keyed).
    - **Technical:** Multiple windows × per-affiliate proliferates redundant cache entries. The state itself isn't window-dependent.
    - **Plain English:** The "your next payout" and "you're at risk" boxes don't depend on which date filter the user picked — they're always "now." But we cache them as if they did, so the same data lives under multiple keys with different freshness.
    - **Source:** Commit-batch review item #19 (commit `85f2673`).

- [x] **#CR-014** · P2 — Shop block_id can be null with state:'live'; Hydrogen tracking pipeline silently drops null block_ids
    - **Where:** app/Http/Controllers/Api/Public/HydrogenAffiliateController.php:177
    - **Affects:** Click-tracking on the shop section when the block hasn't been published yet. Clicks lost without surface error.
    - **Effort:** S (~1h)
    - **What to do:**
        - Either omit the `shop` envelope entirely when the block has never been created;
        - OR document the contract requirement: Hydrogen must check `block_id !== null` before firing trackClick (and update the storefront-side code accordingly).
    - **Technical:** Commit `f9bcd89` added the `shop` envelope shape that returns `block_id: null` when no block exists. Hydrogen's analytics pipeline silently rejects null block_ids; the affiliate shop section sees zero clicks until the block is created, with no error to surface the gap.
    - **Plain English:** A new "shop" tracking block can return without an ID before it's set up. The frontend silently drops events with no ID, so a brand that hasn't finished setup sees zero shop clicks instead of an obvious error.
    - **Source:** Commit-batch review item #22 (commit `f9bcd89`).

- [x] **#V5-028** · P2 — laravel/tinker ships in production require, not require-dev
    - **Where:** composer.json:18
    - **Effort:** S (~0.25h)
    - **What to do:**
        - Move `laravel/tinker` to `require-dev` and run `composer update`.
    - **Technical:** Tinker is a development REPL; shipping it in production widens the attack surface unnecessarily.
    - **Source:** v5 audit (discovery_lens: domain-subagent-10; in_scope_v4: yes).

- [x] **#V5-029** · P2 — grace_period_days config not bounds-validated
    - **Where:** app/Services/Stripe/CommissionPayoutService.php:207
    - **Effort:** S (~1h)
    - **What to do:**
        - Validate min=1 max=365 in service constructor.
    - **Technical:** Misconfigured grace_period_days could produce nonsensical void_at timestamps.
    - **Source:** v5 audit (discovery_lens: domain-subagent-3; in_scope_v4: yes).

- [x] **#V5-030** · P2 — Failed-refund-after-failed-transfer leaves brand overcharged with no surface
    - **Where:** app/Services/Stripe/CommissionPayoutService.php:477-496
    - **Effort:** M (~2h)
    - **What to do:**
        - Explicit `needs_manual_refund` flag + staff dashboard view.
    - **Technical:** When the transfer fails AND the auto-refund also fails, the brand is left charged with no operational surface to detect or remediate.
    - **Source:** v5 audit (discovery_lens: domain-subagent-3; in_scope_v4: yes).

- [x] **#V5-032** · P2 — REST Retry-After defaults to 1ms (not 1s) when header missing
    - **Where:** app/Services/Shopify/Client/ShopifyAdminClient.php:128-129
    - **Effort:** S (~0.25h)
    - **What to do:**
        - `max(1000, ((int) ($response->header('Retry-After') ?? 1)) * 1000)`.
    - **Technical:** Default `1` is interpreted as 1ms not 1s; effectively no backoff against Shopify rate-limit.
    - **Source:** v5 audit (discovery_lens: domain-subagent-4; in_scope_v4: yes).

- [x] **#V5-033** · P2 — Order line product_id used unsanitized in GraphQL GID
    - **Where:** app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php:100-105
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Validate `/^\d+$/` before constructing GID.
    - **Technical:** Untrusted webhook payload constructs a GraphQL identifier without validation.
    - **Source:** v5 audit (discovery_lens: domain-subagent-4; in_scope_v4: yes).

- [x] **#V5-034** · P2 — SquareApiClient and FreshaApiClient don't honor 429 Retry-After
    - **Where:** app/Services/Square/SquareApiClient.php, app/Services/Fresha/FreshaApiClient.php
    - **Effort:** S (~1-2h)
    - **What to do:**
        - Parse Retry-After + back off.
    - **Technical:** No 429 handling; under load the clients hammer the provider.
    - **Source:** v5 audit (discovery_lens: domain-subagent-5-pass2; in_scope_v4: no).

- [x] **#V5-035** · P2 — Full sync may restore manually-deleted services within retention window
    - **Where:** app/Services/Square/SquareServiceSyncService.php
    - **Effort:** M (~3h)
    - **What to do:**
        - Track deletion origin; skip resync of services manually deleted within retention window.
    - **Technical:** Partna delete + Square full sync = service zombie reappears.
    - **Source:** v5 audit (discovery_lens: domain-subagent-5; in_scope_v4: yes).

- [x] **#V5-036** · P2 — Hydrogen affiliate response shape changed (added id) — Hydrogen cache may be stale
    - **Where:** app/Http/Controllers/Api/Internal/HydrogenAffiliateController.php:356 (commit b9de807)
    - **Effort:** S (~0.5-1h)
    - **What to do:**
        - Confirm Hydrogen cache strategy; add Cache-Control no-cache for the deploy window.
    - **Technical:** New `id` field appears in response shape; if Hydrogen has stale cache, the new field is missing for the cache window.
    - **Source:** v5 audit (discovery_lens: domain-subagent-6; in_scope_v4: no).

- [x] **#V5-037** · P2 — Top_links / top_sections cache version may not be bumped after query fixes
    - **Where:** app/Http/Controllers/Api/Professional/ProfessionalAnalyticsController.php:88-92 (commits 672aa80, c144ccc)
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Verify `analyticsSummaryVersion` auto-bumps cover the new query shape; add a deploy-time bump if not.
    - **Technical:** Lens E concluded `analyticsSummaryVersion` auto-bumps on writes — verify this resolves for the recent top_links/top_sections shape change.
    - **Source:** v5 audit (discovery_lens: domain-subagent-6; in_scope_v4: no).

- [x] **#V5-038** · P2 — Hydrogen affiliate payload doesn't filter soft-deleted blocks
    - **Where:** app/Http/Controllers/Api/Internal/HydrogenAffiliateController.php:314-318
    - **Effort:** S (~1-2h)
    - **What to do:**
        - `whereNull('deleted_at')` across links/gallery/services/booking.
    - **Technical:** Soft-deleted blocks are returned to the storefront via Hydrogen's affiliate endpoint.
    - **Source:** v5 audit (discovery_lens: domain-subagent-6; in_scope_v4: no).

- [x] **#V5-039** · P2 — top_links/top_sections JOIN doesn't exclude soft-deleted blocks
    - **Where:** app/Http/Controllers/Api/Professional/ProfessionalAnalyticsController.php:283-343
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `whereNull('b.deleted_at')`.
    - **Technical:** Analytics aggregates include click rows for blocks that have been soft-deleted.
    - **Source:** v5 audit (discovery_lens: domain-subagent-6-pass2; in_scope_v4: no).

- [x] **#V5-041** · P2 — Custom from/to date range bypasses 365-day cap
    - **Where:** app/Http/Controllers/Api/Professional/ProfessionalAnalyticsController.php:42-49
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Cap `(to-from)` at 365 days.
    - **Technical:** Custom date range logic skips the day-count cap, enabling full-decade scans.
    - **Source:** v5 audit (discovery_lens: domain-subagent-6-pass2; in_scope_v4: no).

- [x] **#V5-042** · P2 — Video container not probed before transcode
    - **Where:** app/Jobs/ProcessVideoVariantsJob.php:117-122, app/Services/Media/VideoVariantService.php:80-100
    - **Effort:** M (~2h)
    - **What to do:**
        - Probe in upload controller before storage.
    - **Technical:** Video container parameters trusted from client; bad container blows up the transcode worker.
    - **Source:** v5 audit (discovery_lens: domain-subagent-7; in_scope_v4: yes).

- [x] **#V5-043** · P2 — Image variant job failure orphans the original on R2
    - **Where:** app/Jobs/ProcessImageVariantsJob.php:48-152
    - **Effort:** S (~1h)
    - **What to do:**
        - Cleanup in catch, OR store original after variants succeed.
    - **Technical:** R2 storage bloat from orphaned originals on failed variant processing.
    - **Source:** v5 audit (discovery_lens: domain-subagent-7; in_scope_v4: yes).

- [x] **#V5-044** · P2 — Video variant job failure orphans the original on R2 (large files)
    - **Where:** app/Jobs/ProcessVideoVariantsJob.php:53-163
    - **Effort:** S (~1h)
    - **What to do:**
        - Same as #V5-043.
    - **Technical:** Same pattern as #V5-043 but on the video pipeline (much larger files).
    - **Source:** v5 audit (discovery_lens: domain-subagent-7; in_scope_v4: yes).

- [x] **#V5-045** · P2 — HLS playlist built from config without escaping
    - **Where:** app/Services/Media/VideoVariantService.php:125-127
    - **Effort:** S (~1h)
    - **What to do:**
        - Validate variantKey/resolution against regex.
    - **Technical:** Config values interpolated into HLS playlist string without validation.
    - **Source:** v5 audit (discovery_lens: domain-subagent-7; in_scope_v4: yes).

- [x] **#V5-046** · P2 — Video duration check happens inside transcode job, not on upload
    - **Where:** app/Services/Media/VideoVariantService.php:92-99
    - **Effort:** M (~2h)
    - **What to do:**
        - Probe + check duration on upload.
    - **Technical:** Worker time wasted on too-long videos; should reject at upload.
    - **Source:** v5 audit (discovery_lens: domain-subagent-7; in_scope_v4: yes).

- [x] **#V5-047** · P2 — BrandDesignMediaService brand-logo path also lacks MIME sniffing
    - **Where:** app/Services/Media/BrandDesignMediaService.php:32-91
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add finfo sniff (matches #7-01 pattern in stage-2).
    - **Technical:** Different code path from #7-01's UploadBrandLogoRequest; same MIME-spoof exposure.
    - **Source:** v5 audit (discovery_lens: domain-subagent-7-pass2; in_scope_v4: no).

- [x] **#V5-048** · P2 — Staff middleware doesn't differentiate fine-grained permissions
    - **Where:** app/Http/Middleware/Auth/EnsurePartnaStaff.php:22
    - **Effort:** M (~2h)
    - **What to do:**
        - Role-based gates if planning a more limited support-staff surface.
    - **Technical:** All staff have full admin; no support-only role.
    - **Source:** v5 audit (discovery_lens: domain-subagent-8; in_scope_v4: no).

- [x] **#V5-049** · P2 — Platform-link cap is a write-time check; existing over-limit data not remediated
    - **Where:** app/Http/Requests/Api/Professional/Site/StoreLinkBlockRequest.php
    - **Effort:** S (~1h)
    - **What to do:**
        - Audit existing rows for over-limit cohorts; add a backfill or a one-off cleanup script.
    - **Technical:** Cross-references #CR-001/#CR-011 — even the write-time check is broken; pre-existing data may already be over the cap.
    - **Source:** v5 audit (discovery_lens: domain-subagent-8; in_scope_v4: no).

- [x] **#V5-051** · P2 — CommissionLedgerEntry FK CASCADE vs payout SET NULL — asymmetric audit retention
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql vs 20260419000002_nullable_commission_fks.sql
    - **Effort:** M (~2h)
    - **What to do:**
        - Convert ledger FKs to SET NULL.
    - **Technical:** Asymmetric FK behaviors — payout deletion preserves ledger entries (SET NULL), but ledger entries cascade-delete on professional deletion.
    - **Source:** v5 audit (discovery_lens: domain-subagent-9; in_scope_v4: yes).

- [x] **#V5-052** · P2 — Subscription stripe_customer_id and stripe_subscription_id mass-assignable
    - **Where:** app/Models/Billing/Subscription.php:32
    - **Effort:** S (~1.5h)
    - **What to do:**
        - Remove from `$fillable`.
    - **Technical:** Server-controlled identifiers should not be mass-assignable.
    - **Source:** v5 audit (discovery_lens: domain-subagent-9; in_scope_v4: yes).

- [x] **#V5-053** · P2 — BrandStoreSettings.oxygen_deployment_token mass-assignable
    - **Where:** app/Models/Retail/BrandStoreSettings.php:21
    - **Effort:** S (~1h)
    - **What to do:**
        - Remove from `$fillable`.
    - **Technical:** Sensitive deployment token should never be mass-assignable.
    - **Source:** v5 audit (discovery_lens: domain-subagent-9; in_scope_v4: yes).

- [x] **#V5-054** · P2 — Plan model has primary key 'id' in $fillable
    - **Where:** app/Models/Billing/Plan.php:16
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Remove `'id'` from `$fillable`.
    - **Technical:** Mass-assigning the primary key is never the intent; sloppy `$fillable`.
    - **Source:** v5 audit (discovery_lens: domain-subagent-9; in_scope_v4: yes).

- [x] **#V5-055** · P2 — DataExportAudit and ProfessionalDeletionAuditEntry have created_at/completed_at in $fillable
    - **Where:** app/Models/Core/Gdpr/DataExportAudit.php, app/Models/Core/Professional/ProfessionalDeletionAuditEntry.php
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Remove from `$fillable`; use Eloquent timestamps.
    - **Technical:** Audit timestamps are server-controlled — should not be mass-assignable.
    - **Source:** v5 audit (discovery_lens: domain-subagent-9-pass2; in_scope_v4: no).

- [x] **#V5-056** · P2 — 6 raw queries on core.professionals don't filter deleted_at (lensG bundle, excluding lensG-004 which is #V5-012)
    - **Where:**
        - app/Http/Controllers/Api/Staff/Analytics/BrandCommerceAnalyticsController.php:64-68
        - app/Http/Controllers/Api/Staff/StaffStatsController.php:15-18
        - app/Http/Controllers/Api/Professional/Analytics/AffiliateCommerceAnalyticsController.php:238-241
        - app/Observers/Core/BrandAffiliateInviteObserver.php:91-93,99-101
        - app/Services/Notifications/CommerceNotificationService.php:55-57
        - app/Services/Analytics/CommerceAnalyticsAggregateService.php:373-377
    - **Affects:** Analytics, observer notifications, commerce aggregates — soft-deleted professionals leak into outputs.
    - **Effort:** S (~1-2h total)
    - **What to do:**
        - Add `whereNull('deleted_at')` to each.
    - **Technical:** Bundled lens-G finding. lensG-004 (the email job) is broken out as #V5-012 because it's P1 (sends to deleted users); the rest are P2 (correctness, not delivery).
    - **Source:** v5 audit (discovery_lens: lens-G-soft-delete-sweep; in_scope_v4: no).

- [x] **#V5-057** · P2 — Health-check endpoints unthrottled
    - **Where:** routes/api.php:39, 88, 163-164
    - **Effort:** S (~0.5h)
    - **What to do:**
        - `throttle:health-check` (60/min/IP).
    - **Technical:** Health-check endpoints can be hammered without limit.
    - **Source:** v5 audit (discovery_lens: lens-H-rate-limit-posture; in_scope_v4: yes).

- [x] **#V5-059** · P2 — Throttle disabled flag bypasses everything globally
    - **Where:** app/Providers/AppServiceProvider.php:55
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Refuse to start in production if flag is false.
    - **Technical:** A misconfigured `THROTTLE_DISABLED=true` in prod silently disables all rate limits.
    - **Source:** v5 audit (discovery_lens: lens-H-rate-limit-posture; in_scope_v4: yes).

- [x] **#V5-060** · P2 — Authenticated throttle keys fall back to IP if supabase_uid missing
    - **Where:** app/Providers/AppServiceProvider.php:179, 194, etc.
    - **Effort:** S (~1-2h)
    - **What to do:**
        - Assert supabase_uid is set on JWT-required routes; fail loudly otherwise.
    - **Technical:** Silent fall-back to IP keying defeats per-user throttle isolation if a routing mistake removes the JWT middleware.
    - **Source:** v5 audit (discovery_lens: lens-H-rate-limit-posture; in_scope_v4: yes).

- [x] **#V5-062** · P2 — Asymmetric Professional shape across endpoints
    - **Where:** Multiple controllers returning Professional in different shapes
    - **Effort:** M (~4h)
    - **What to do:**
        - Define `ProfessionalDashboardResource` / `ProfessionalStaffResource` / `ProfessionalPublicResource`.
    - **Technical:** Different endpoints return different Professional fields; no canonical Resource defines the shape per audience.
    - **Source:** v5 audit (discovery_lens: lens-J-resource-shape; in_scope_v4: no).

- [x] **#V5-063** · P2 — Image pool values hardcoded in two upload Form Requests
    - **Where:** app/Http/Requests/Api/Professional/Uploads/UploadImageRequest.php:21, ReorderPoolImagesRequest.php:17,23
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Move to `config('sidest.image_pools')`.
    - **Technical:** DRY violation; pool list lives in two places.
    - **Source:** v5 audit (discovery_lens: lens-K-validation-and-json; in_scope_v4: no).

- [x] **#V5-064** · P2 — Phone field max length divergent across 7 Form Requests
    - **Where:** 7 Form Request classes
    - **Effort:** S (~1-2h)
    - **What to do:**
        - Canonical rule in BaseFormRequest.
    - **Technical:** Inconsistent phone validation rules across endpoints.
    - **Source:** v5 audit (discovery_lens: lens-K-validation-and-json; in_scope_v4: no).

- [x] **#V5-065** · P2 — Grace warning windows are 3-day-wide (off-by-one risk)
    - **Where:** app/Services/Stripe/CommissionVoidService.php:135-146
    - **Effort:** S (~1h)
    - **What to do:**
        - Tighten to 1-day windows.
    - **Technical:** 3-day window catches the same row 3 times; tighter window cleaner.
    - **Source:** v5 audit (discovery_lens: lens-L-time-money-tz-currency; in_scope_v4: no).

- [x] **#V5-066** · P2 — Wallet currency switch on empty balance has no audit trail
    - **Where:** app/Services/Stripe/StripeConnectService.php:525-537
    - **Effort:** S (~1h)
    - **What to do:**
        - Emit audit event on switch.
    - **Technical:** Currency change is a financially meaningful event; no log captures it.
    - **Source:** v5 audit (discovery_lens: lens-L-time-money-tz-currency; in_scope_v4: no).

- [x] **#V5-067** · P2 — stripe_manual_balance_currency defaults to AUD without validation
    - **Where:** app/Services/Stripe/StripeConnectService.php:414, 498, 523
    - **Effort:** S (~1h)
    - **What to do:**
        - Initialize from shop_currency on first connection.
    - **Technical:** Hard-coded AUD default doesn't match the shop's actual currency.
    - **Source:** v5 audit (discovery_lens: lens-L-time-money-tz-currency; in_scope_v4: no).

- [x] **#V5-071** · P2 — success() helper accepts $status without validation — class of bug that's easy to miss
    - **Where:** app/Http/Controllers/Api/ApiController.php:14
    - **Effort:** S (~1-2h)
    - **What to do:**
        - Either: extend the helper to accept `(data, message_or_status, status)` — but that adds complexity.
        - Or: add a Pint custom rule / a phpstan rule / a CI grep that fails on `->success(.*,\s*['\"]`.
        - Or: write a one-line `success()` test that verifies the second arg is always int.
        - Pick one and document.
    - **Technical:** The `success()` signature is fine in isolation but creates a footgun that already cost three regressions in one day (the two May-1 fixes + the still-broken EmbeddedSetupController in #V5-069). Add a static check.
    - **Plain English:** The "send a JSON response" helper is easy to misuse — three developers wrote the same bug today. Add a CI check or a comment so it doesn't keep happening.
    - **Source:** v5 audit (discovery_lens: tobias-commit-review; in_scope_v4: no).

---

## P3 — Nice to have

- [x] **#10-14** · P3 — User-supplied subject text not truncated in SiteEnquiryNotification
    - **Where:** app/Mail/SiteEnquiryNotification.php:24
    - **Effort:** S (~0.5h)
    - **What to do:** Truncate or use ref number. (Coordinate with #10-11/12 in legal report.)

- [x] **#10-13** · P3 — StaffBroadcastMail does not pass `$unsubscribeUrl` to view
    - **Where:** app/Mail/StaffBroadcastMail.php (build)
    - **Effort:** S (~0.5h)
    - **What to do:** Pass `$unsubscribeUrl` when rendering the view.

- [x] **#10-10** · P3 — `sidest:prune-notifications` onFailure log message is generic
    - **Where:** routes/console.php (sidest:prune-notifications onFailure)
    - **Effort:** S (~0.5h)
    - **What to do:** Include exception class and message in the log.

- [x] **#10-06** · P3 — symfony/process CVE-2026-24739 (Windows MSYS2 escaping; Linux prod unaffected)
    - **Where:** composer.lock — symfony/process
    - **Effort:** S (~0.25h)
    - **What to do:** `composer update symfony/process`.

- [x] **#10-05** · P3 — psy/psysh CVE-2026-25129 LPE via CWD .psysh.php (dev-only)
    - **Where:** composer.lock — psy/psysh (dev)
    - **Effort:** S (~0.25h)
    - **What to do:** `composer update psy/psysh`.

- [x] **#10-04** · P3 — phpunit/phpunit CVE-2026-24765 deserialization (dev-only)
    - **Where:** composer.lock — phpunit/phpunit (dev)
    - **Effort:** S (~0.25h)
    - **What to do:** `composer update phpunit/phpunit`.

- [x] **#9-017** · P3 — CheckStreamingLiveStatusJob silently returns when Redis unavailable
    - **Where:** app/Jobs/Streaming/CheckStreamingLiveStatusJob.php:26-32
    - **Effort:** S (~0.5h)
    - **What to do:** Promote log to error; emit Nightwatch event so an outage isn't invisible.

- [x] **#9-016** · P3 — N+1 in SiteMediaObserver / SiteObserver — `Site::find($media->site_id)` per event
    - **Where:** app/Observers/Core/SiteMediaObserver.php; app/Observers/Core/SiteObserver.php
    - **Effort:** S (~1h)
    - **What to do:** Use `site_id` directly or eager-load.

- [x] **#9-006** · P3 — `SendWeeklyAnalyticsNotificationJob` DB::raw aggregates use hardcoded literals
    - **Where:** app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php
    - **Effort:** S (~1h)
    - **What to do:** Optional — switch to selectRaw() with bindings for clarity. Currently safe but noisy.

- [x] **#9-004** · P3 — BrandStoreSettings $fillable includes default_commission_rate
    - **Where:** app/Models/Retail/BrandStoreSettings.php
    - **Effort:** S (~1h)
    - **What to do:** Add a FormRequest validation for any update endpoint touching commission_rate; consider `$guarded = ['*']` on the model for defense in depth.

- [x] **#9-001/2/3** · P3 — Sensitive cols in `$fillable` on CommissionPayout / CommissionLedgerEntry / BrandTeamMembership
    - **Where:** app/Models/Billing/CommissionPayout.php; app/Models/Billing/CommissionLedgerEntry.php; app/Models/Core/BrandTeamMembership.php
    - **Effort:** S (~1–2h)
    - **What to do:** Switch to `$guarded = ['*']` for defense in depth. **Verified no exploitable callsite** (server-side computed values only) — DOWNGRADED from P0 in audit. Pure hardening.

- [x] **#8-08** · P3 — Inconsistent 403 vs 404 for "not yours" responses
    - **Where:** various controllers
    - **Effort:** S (~2h)
    - **What to do:** Document the standard (e.g., always 404 to avoid resource enumeration), audit and align.

- [x] **#8-06** · P3 — `cors.allowed_headers: ['*']` safe only because `supports_credentials: false`
    - **Where:** config/cors.php:42
    - **Effort:** S (~0.25h)
    - **What to do:** Add a comment guard explaining the dependency.

- [x] **#8-05** · P3 — `auth_user_id` echoed in ProfessionalResource
    - **Where:** app/Http/Resources/ProfessionalResource.php:14
    - **Effort:** S (~0.25h)
    - **What to do:** Remove from the resource output.

- [x] **#7-02** · P3 — `image/svg+xml` MIME mapping in BrandDesignMediaService is dead code
    - **Where:** app/Services/Media/BrandDesignMediaService.php:418-422
    - **Effort:** S (~0.25h)
    - **What to do:** Remove the SVG mapping — no upload validator accepts SVG.

- [x] **#6-02** · P3 — `public_contact_email` / `public_contact_number` echoed unconditionally in PublicSitePayload view
    - **Where:** supabase/migrations/20260403000000_v2_baseline.sql (PublicSitePayload view)
    - **Effort:** S (~1h)
    - **What to do:** Document or add validation; consider gating behind a "share publicly" flag.

- [x] **#6-01** · P3 — `$clickBlockColumn` interpolated into JOIN clause in ProfessionalAnalyticsController
    - **Where:** app/Http/Controllers/Api/Professional/ProfessionalAnalyticsController.php:283-343
    - **Effort:** S (~1h)
    - **What to do:** Refactor to a conditional join for clarity. Whitelist is tight, no real injection risk.

- [x] **#5-06** · P3 — Fresha booking methods are dead code (Fresha has no booking API)
    - **Where:** app/Services/Fresha/FreshaApiClient.php:145-175
    - **Effort:** S (~0.5h)
    - **What to do:** Remove dead code. (Per memory: Fresha integration is link-redirect + Snowflake.)

- [x] **#5-05** · P3 — Square/Fresha jobs lack explicit `$tries` / `$backoff` configuration
    - **Where:** All Square/Fresha jobs
    - **Effort:** S (~1h)
    - **What to do:** Add explicit retry configuration to each.

- [x] **#5-04** · P3 — No `(professional_id, fresha_variation_id)` unique constraint on Fresha service mapping
    - **Where:** app/Services/Fresha/FreshaServiceSyncService.php:108-142
    - **Effort:** S (~1h)
    - **What to do:** Add a Supabase migration for the unique constraint.

- [x] **#4-12** · P3 — Memory file says Shopify GDPR webhooks are stubs; code is fully implemented
    - **Where:** app/Http/Controllers/Api/Webhooks/ShopifyGdprWebhookController.php (1-90); memory file `project_shopify_gdpr_webhooks_todo.md`
    - **Effort:** S (~0.25h)
    - **What to do:** Delete or update the memory file after confirming the implementation is complete.

- [x] **#4-04** · P3 — Encrypted cast on integration tokens not covered by an integration test
    - **Where:** app/Models/Core/Professional/ProfessionalIntegration.php:46-47
    - **Effort:** S (~1h)
    - **What to do:** Add a test that confirms tokens are encrypted at rest.

- [x] **#4-03** · P3 — `normalizeShopDomain` trim chars include `./` — could mask invalid domains
    - **Where:** app/Http/Controllers/Api/Shopify/ShopifyAppOAuthController.php (normalizeShopDomain)
    - **Effort:** S (~0.5h)
    - **What to do:** Add an explicit `*.myshopify.com` regex check after normalization.

- [x] **#3-07** · P3 — Stripe `paused` status falls through default → string literal in webhook controller
    - **Where:** app/Http/Controllers/Api/Webhooks/StripeWebhookController.php:286-298
    - **Effort:** S (~0.5h)
    - **What to do:** Add an explicit case for `paused`.

- [x] **#2-04** · P3 — Theoretical race in ProfessionalCacheService on `auth_user_id` change mid-request
    - **Where:** app/Services/Cache/ProfessionalCacheService.php:129-160
    - **Effort:** S (~0.5h)
    - **What to do:** Document the immutability assumption (auth_user_id never changes) inline.

- [x] **#1-07** · P3 — `EnsurePartnaStaff` fail-closed but creation flow not asserted by test
    - **Where:** app/Http/Middleware/Auth/EnsurePartnaStaff.php
    - **Effort:** S (~1h)
    - **What to do:** Add a Pest test that confirms staff creation requires both Supabase + PartnaStaff atomically.

- [x] **#1-06** · P3 — `BrandAccessService::isBrandProfessional()` is a type-check living next to capability methods
    - **Where:** app/Http/Controllers/Api/Professional/ShopifyIntegration/ShopifyIntegrationController.php:64
    - **Effort:** S (~0.5h)
    - **What to do:** Rename or move the method onto the Professional model itself.

- [x] **#PR-007** · P3 — Shopify webhook `fallback_secret` has no rotation deadline
    - **Where:** app/Http/Controllers/Concerns/ValidatesShopifyWebhookHmac.php
    - **Effort:** S (~0.5h)
    - **What to do:** Document the rotation cadence inline or add an expiry.

- [x] **#CR-015** · P3 — `role` query param trusted without validation in StripeConnectController::payouts
    - **Where:** app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php:330-332
    - **Effort:** S (~0.5h)
    - **What to do:** Add a Form Request with `'role' => ['required', Rule::in(['brand', 'affiliate'])]`. Not cross-tenant — rows still scoped to authenticated `pro->id` — but the param is currently unsanitized. Endpoint now exposes `void_at` per row (introduced by `85f2673`), worth tightening since the response surface grew.
    - **Source:** Commit-batch review item #30 (commit `85f2673`).
