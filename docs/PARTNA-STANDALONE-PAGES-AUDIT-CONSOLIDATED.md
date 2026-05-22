# Partna Standalone Pages — Consolidated Plan Audit

**Source plan:** `PARTNA-STANDALONE-PAGES-NEW-DIRECTION-1.md` (v1 May 2026, dated 2026-05-19)
**Audit date:** 2026-05-19
**Method:** 14 parallel lens audits — DeepSeek V4 Pro scan, Claude Sonnet 4.6 adjudication. Each finding cites either the plan section it targets or the source file it confirmed against.
**Audit IDs preserved from per-lens reports** in `audits/standalone-pages/` so each can be tracked / re-opened individually.

---

## Executive summary

**41 raw findings across 13 active lenses** (1 lens — brand-status-recent-changes — produced no findings, expected for a planning doc). After de-duplication of cross-lens overlaps: **~36 distinct issues.**

**Tier distribution:**
- **P1 — Fix before pilot launch:** 8
- **P2 — Should fix:** 22
- **P3 — Nice to have:** 11

**Top three structural concerns** the plan author should focus on:

1. **Migration safety (7 findings — MIG-1..7).** The `account_type` migration sequence (§8, §28.1) needs concrete revisions: `CREATE INDEX CONCURRENTLY` cannot sit inside the same transaction as `SET NOT NULL`, the new `CHECK` constraint must use `NOT VALID` + later `VALIDATE`, and the three-file split has a partial-application window where new rows land NULL before NOT NULL is enforced. None are conceptual flaws — they're operational specifics the plan should bake in before SQL is written.
2. **`BrandPartnerLink` soft-delete is a current-state defect, not just a future change.** Three independent lenses (DATA-3 / DINT-1 / SCHEMA-1) confirmed it via direct source reads — `BrandPartnerLink` model lacks `SoftDeletes`, `BrandPartnerLinkService::disconnectBrandFromAffiliate()` hard-deletes today. The plan's §28.16 already calls for this fix; what the plan must also include is the matching RLS policy update (SCHEMA-2) and the `brand_partner_link_events` FK `RESTRICT → SET NULL` migration (part of DATA-3 Part B) so professional hard-deletes don't silently fail.
3. **Two FK-cascade audit-table bugs orthogonal to the plan but in the same area.** `core.brand_status_history` (DATA-1) and `core.handle_change_log` (DATA-2) both use `ON DELETE CASCADE` to `core.professionals`. After 30-day soft-delete purge, all status-transition and handle-rename audit rows vanish — `handle_change_log` explicitly claims a 7-year retention it doesn't deliver. Worth flagging because the plan touches handle lifecycle and adds `brand.signup_code_audit` (§34) — the same `SET NULL` convention should be specified there too.

**One systemic gap:** the plan describes `report($e)` / Nightwatch alerting in places but the existing job suite has 5 jobs (JOB-1 / OBS-2) that already miss it — including `SendTransactionalNotificationEmailJob`, which delivers payout and commission emails. The plan should include an explicit "every new job calls `report($e)` in `failed()`" item.

---

## How to read this document

Each finding has:
- **ID** — original audit ID (e.g. MIG-1). The corresponding per-lens audit lives in `audits/standalone-pages/audit-2026-05-19--<lens>-*.md`.
- **Tier** — P1 / P2 / P3.
- **Effort** — S (≤1h) / M (2–4h) / L (1–2d).
- **Action class:**
  - 🅿 **Plan-side fix** — revise the plan document itself before implementation starts.
  - 🅸 **Implementation-time spec** — add to the plan as an explicit requirement so the implementer doesn't miss it.
  - 🅲 **Existing-code defect** — pre-existing bug the plan must acknowledge or absorb.

---

# Section 1 — Migration Safety (Plan §8, §28.1, §28.16, §34, §36)

> All seven findings target migrations described in the plan but not yet written. Caught at the right moment — before SQL is finalised. The existing migration `20260515000001_validate_preferred_payout_method_check.sql` already applies the safe NOT VALID → VALIDATE pattern on `core.professionals`; the planned migrations must follow suit. Every migration in this repo uses explicit `BEGIN; … COMMIT;`, confirming `CREATE INDEX CONCURRENTLY` will error inside the planned `<ts3>` file.

### MIG-1 · P1 · S · 🅿
**`CREATE INDEX CONCURRENTLY` inside a transaction-wrapped migration will fail, leaving `<ts3>` half-applied**

- **Where:** Plan §8 Step 4 + §28.1 — `<ts3>_enforce_account_type_constraints.sql` (planned)
- **What to do:**
    - Move `CREATE INDEX CONCURRENTLY ON core.professionals (account_type)` into its own dedicated file — `<ts4>_add_account_type_covering_index.sql` — containing nothing else.
    - Leave `<ts3>` with only `SET NOT NULL`, `ADD CONSTRAINT … CHECK`, and the dual-write trigger (all safe inside a transaction).
- **Why:** PostgreSQL throws `ERROR: CREATE INDEX CONCURRENTLY cannot run inside a transaction block`. Every migration in this repo uses explicit `BEGIN; … COMMIT;`. The plan places `CREATE INDEX CONCURRENTLY` (Step 4) in the same file as `SET NOT NULL` and `ADD CONSTRAINT … CHECK` (Step 3) and the dual-write trigger (Step 5). Running this file will abort mid-way and depending on rollback semantics may leave the DB in a half-applied state with `supabase_migrations.schema_migrations` recording it as attempted.

### MIG-2 · P2 · S · 🅿
**`ADD CONSTRAINT … CHECK` without `NOT VALID` on `core.professionals` holds `ACCESS EXCLUSIVE` during constraint scan**

- **Where:** Plan §8 Step 3 — `<ts3>_enforce_account_type_constraints.sql` (planned)
- **What to do:**
    - In `<ts3>`: use `ADD CONSTRAINT professionals_account_type_check CHECK (account_type IN ('brand', 'partner', 'individual')) NOT VALID`.
    - Add a follow-up file `<ts5>_validate_account_type_check.sql` with `ALTER TABLE core.professionals VALIDATE CONSTRAINT …;` — identical to the existing precedent.
- **Why:** Without `NOT VALID`, the validation pass holds `ACCESS EXCLUSIVE` blocking all reads and writes. The project has already applied this exact safe pattern on the same table — see `20260515000001_validate_preferred_payout_method_check.sql`.

### MIG-3 · P2 · S · 🅿
**`ALTER COLUMN account_type SET NOT NULL` without prior safe-CHECK pattern holds `ACCESS EXCLUSIVE` on `core.professionals`**

- **Where:** Plan §8 Step 3 — same migration as MIG-2.
- **What to do:**
    - Replace direct `SET NOT NULL` with the three-step pattern: (1) `ADD CHECK (account_type IS NOT NULL) NOT VALID` in `<ts3>`; (2) `VALIDATE CONSTRAINT` in a follow-up; (3) `ALTER COLUMN SET NOT NULL` in a third migration — at which point Postgres skips the table scan because the constraint is already enforced.
    - Alternative: if the table is genuinely empty/near-empty at migration time, document that assessment + add a `DO $$ … $$` row-count assertion.
- **Why:** `SET NOT NULL` scans every row to confirm no NULLs, holding `ACCESS EXCLUSIVE`. Even brief lock-holds on a "write-hot during business hours" table are worth eliminating when the safe pattern is already established in the codebase.

### MIG-4 · P2 · S · 🅿
**Three-file split creates a partial-application window where `account_type` column exists with all NULLs**

- **Where:** Plan §28.1 — the `<ts1>` / `<ts2>` / `<ts3>` sequence.
- **What to do:**
    - Merge `<ts1>` (add column) and `<ts2>` (backfill) into a single file. Column addition is metadata-only and the backfill on a sub-10K-row table completes in milliseconds.
    - If keeping the split: add a defensive `DO $$ … $$` block at the top of `<ts3>` that counts NULLs and raises if any exist (the plan already specifies this pattern for `brand_signup_code` in §36 — apply the same here).
    - Add a `-- To revert: …` comment to `<ts1>` per project convention.
- **Why:** If `<ts2>` fails (syntax error, OOM, connection drop), the DB has the column unpopulated. Application code at that point hasn't been updated to write `account_type`. New signups during the gap produce NULL values; `<ts3>`'s `SET NOT NULL` then fails with a genuine constraint violation, not a backfill error. Merging closes the window.

### MIG-5 · P3 · S · 🅸
**`brand.signup_code_audit` uses `gen_random_uuid()` without `CREATE EXTENSION IF NOT EXISTS pgcrypto` guard**

- **Where:** Plan §34 — `<ts>_create_brand_signup_code_audit.sql` (planned).
- **What to do:** Add `CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA extensions;` at the top of the audit table migration.
- **Why:** Supabase enables `pgcrypto` by default but the plan explicitly acknowledges the dependency with a manual verification step. The `IF NOT EXISTS` guard subsumes that step and makes the migration portable to fresh CI databases and restored backups.

### MIG-6 · P3 · S · 🅸
**No rollback comment in migration SQL specs for destructive or irreversible operations**

- **Where:** Plan §28.1, §28.16, §34.
- **What to do:** Add a `-- To revert: …` comment block at the top of each destructive migration file per the convention cited in CLAUDE.md. Specific rollback commands listed in the per-lens audit file.
- **Why:** The plan documents rollback in §8 prose, but not inside the migration files where an operator would find it during an incident. Five-minute addition; saves real time under pressure.

### MIG-7 · P3 · S · 🅸
**`<ts2>` backfill UPDATE lacks `WHERE account_type IS NULL` guard — non-idempotent on retry**

- **Where:** Plan §8 Step 2.
- **What to do:** Add `AND account_type IS NULL` to every UPDATE branch in the backfill, mirroring the established `BrandProfile::whereNull('signup_code')->cursor()` idempotency pattern from §36.
- **Why:** Re-running the migration on an already-backfilled DB would reset `professional_type` for every row — including rows the dual-write trigger may have legitimately updated since the initial backfill.

---

# Section 2 — Data Integrity & Soft-Delete (Plan §11, §28.16; existing code)

> The `BrandPartnerLink` soft-delete fix is a **load-bearing prerequisite for the entire ex-partner mechanism**, confirmed via direct source reads by three separate lenses (DATA-3 / DINT-1 / SCHEMA-1). It's already in the plan as §28.16, but the surrounding work (RLS update, `brand_partner_link_events` FK) is incompletely specified.

### DATA-3 / DINT-1 / SCHEMA-1 · P1 · M · 🅲 + 🅿
**`BrandPartnerLink` has no `SoftDeletes` — ex-partner mechanism is architecturally broken; `brand_partner_link_events` ON DELETE RESTRICT will silently block professional hard-deletes**

- **Where:**
    - `app/Models/Core/Professional/BrandPartnerLink.php` — missing `SoftDeletes` trait.
    - `app/Services/Professional/Brand/BrandPartnerLinkService.php:99` — `$target->delete()` is a hard delete today.
    - `supabase/migrations/20260420000000_add_brand_partner_link_events.sql:6–7` — RESTRICT FKs.
- **Two failure modes, fixed together:**
    - **(A) Soft-delete on `BrandPartnerLink`:** add `deleted_at TIMESTAMPTZ NULL`, partial index `(affiliate_professional_id) WHERE deleted_at IS NULL`, composite `(affiliate_professional_id, deleted_at)`, the `SoftDeletes` trait, datetime cast, and a `brandPartnerLinksAll()` relation using `withTrashed()` on `Professional`.
    - **(B) Fix `brand_partner_link_events` RESTRICT:** convert `brand_professional_id` and `affiliate_professional_id` FKs from `ON DELETE RESTRICT` to `ON DELETE SET NULL`. Otherwise `PurgeSoftDeleted::forceDelete()` throws FK violations and the professional stays permanently soft-deleted — they think they've deleted their account, but the system is still holding their data.
    - **(C) Plus SCHEMA-2** (next finding) — RLS update **must** ship in the same migration.
- **Why:** The plan describes this as "non-negotiable" (§11). It's also a current-state defect: every partner disconnection today permanently destroys the link record. The "previous partnerships" panel cannot be built without this.

### SCHEMA-2 · P1 · S · 🅿
**RLS policies on `brand_partner_links`, `brand_profiles`, and `brand_store_settings` will expose soft-deleted links and brand data to ex-partners once DATA-3 lands**

- **Where:** `supabase/migrations/20260420200000_add_rls_to_remaining_tables.sql:135–155, 116–123, 186–193`.
- **What to do:** In the same migration that adds `deleted_at` to `brand.brand_partner_links`:
    1. `partner_links_party_select` — add `AND deleted_at IS NULL` to the non-staff `USING` predicates (staff path retains access).
    2. `brand_profiles_affiliate_select` — add `AND l.deleted_at IS NULL` to the EXISTS subquery.
    3. `store_settings_affiliate_select` — same.
    - Add a Pest test verifying a non-staff user querying via Supabase REST does not see soft-deleted rows.
- **Why:** Eloquent's `SoftDeletes` global scope protects ORM queries — PostgREST queries bypass Eloquent entirely. Once soft-deleted links exist, ex-partners can query the JS client and read their former brand's profile, commission rates, hold periods, and (once added) `signup_code`. Atomic with DATA-3 to avoid the window.

### DATA-1 · P1 · S · 🅲
**`core.brand_status_history` uses `ON DELETE CASCADE` — audit rows destroyed on professional hard-delete**

- **Where:** `supabase/migrations/20260505000001_create_brand_status_history.sql:4`.
- **What to do:** Make `professional_id` nullable; replace `ON DELETE CASCADE` with `ON DELETE SET NULL` (matches `20260505200000_commission_ledger_entries_set_null_professional_fks.sql`).
- **Why:** `PurgeSoftDeleted` → `AccountDeletionService::forceDelete()` silently wipes status history. The pattern was established for ledger entries on the same day this table was created; this one missed it.

### DATA-2 · P1 · S · 🅲
**`core.handle_change_log` uses `ON DELETE CASCADE` — violates its own 7-year retention spec**

- **Where:** `supabase/migrations/20260519100000_handle_alias_lifecycle.sql:100`.
- **What to do:** Make `professional_id` nullable, replace CASCADE with SET NULL. The append-only `BEFORE UPDATE OR DELETE` trigger does NOT fire on parent-cascade deletes — Postgres executes those as a referential integrity action.
- **Why:** The migration comment says "retained per config (default 7 years)" but the FK delivers 30 days. Includes IP, user-agent, actor data — a real audit-trail gap.

### DATA-4 · P2 · S · 🅲
**Soft-delete purge command misses `Block`; no sweep test enforcing coverage**

- **Where:** `app/Console/Commands/PurgeSoftDeleted.php:33–37`.
- **What to do:** Add `Block::class` to the purge enumeration; add a sweep test (mirroring `PolicyCoverageTest`) that discovers every model with `use SoftDeletes` via reflection and asserts it's either listed in `PurgeSoftDeleted`, has its own prune command, or appears in a `PURGE_EXEMPT` allowlist with a justification.
- **Why:** `Block` uses `SoftDeletes` but is never purged. More importantly, nothing prevents future `SoftDeletes` models from silently joining the purge gap.

---

# Section 3 — Job & Queue Correctness (Plan §28.6, §28.7; existing code)

### JOB-2 / SCALE-4 · P2 · S · 🅲
**`SyncSubdomainToKvJob` missing `ShouldBeUnique` / `WithoutOverlapping` — stale KV write possible under rapid re-dispatch**

- **Where:** `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:23–75`.
- **What to do:** Add either `ShouldBeUnique` keyed by `professionalId` with `$uniqueFor = 45`, or `WithoutOverlapping($professionalId)->releaseAfter(30)->expireAfter(60)`. Pattern already used by `CreateShopifyMetafieldsJob` and `CreateShopifyAffiliateDiscountJob`.
- **Why:** The job is dispatched on handle change, brand-partner-link change, and brand URL change — all scenarios that produce rapid back-to-back dispatches. Without serialization, two workers can both read DB state, the older job's write to Cloudflare KV can land after the newer one's, and visitor traffic for that handle is routed wrong until the next dispatch overwrites it. The plan increases dispatch frequency by adding the `individual` branch (§28.6) and signup-code acceptance (§28.12). At pilot scale this is a real (if narrow) routing window.

### SCALE-1 · P1 · M · 🅸
**`AccountCapabilities::for()` will issue 2N database queries in every list endpoint and notification fan-out that iterates professionals**

- **Where:** Planned `app/Services/Accounts/AccountCapabilities.php` (§28.3).
- **What to do:**
    - In any controller or job that iterates professionals and calls `AccountCapabilities::for()`, eager-load `brandPartnerLinks` and `brandPartnerLinksAll` on the collection upstream.
    - Memoize the ex-partner boolean on `AccountCapabilitySet` so repeated reads on the same instance don't re-query.
    - For high-frequency read paths, consider pre-computing `has_historical_partner_links` as a boolean column on `core.professionals`, updated by `BrandPartnerLinkObserver`.
- **Why:** `shows_ex_partner_panel` derivation (§28.16) does two separate `exists()` calls per professional. At 10K professionals × notification fan-out → ~20K redundant queries. The plan should specify the eager-loading pattern explicitly.

### SCALE-2 · P2 · S · 🅸
**`AccountTypeTransitionService` must never use sync dispatch for KV/cache-purge jobs inside the DB transaction**

- **Where:** Planned `app/Services/Accounts/AccountTypeTransitionService.php` (§28.4).
- **What to do:**
    - Scope `DB::transaction(...)` to ONLY the Eloquent mutations + the `lockForUpdate()` on the Professional row.
    - Dispatch `SyncSubdomainToKvJob` and `CloudflareCachePurgeJob` AFTER the transaction closes.
    - Add a class-level comment: "Job dispatches must remain outside the transaction boundary. Do not use `::dispatchSync()` within the `DB::transaction` block."
- **Why:** The plan (§28.7) currently leaves dispatch mode to the implementer. `::dispatchSync()` or `QUEUE_CONNECTION=sync` would execute Cloudflare HTTP I/O while the row lock is held — connection-pool starvation pattern. The existing Stripe services correctly keep `DB::transaction` closures DB-only; the new service should follow the same precedent.

### JOB-1 / OBS-2 · P2 · S · 🅲 + 🅸
**Five existing jobs silently fail: `report($e)` missing in `failed()` — plan should require it for all new jobs too**

- **Existing jobs to fix:**
    - `app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php:122`
    - `app/Jobs/Notifications/SendBrandStatusNotificationJob.php:77`
    - `app/Jobs/Notifications/NudgeStuckOnboardingJob.php:137`
    - `app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php:117` — most important; this is the payout/commission email path
    - `app/Jobs/Shopify/CreateShopifyAffiliateDiscountJob.php:194`
- **What to do:**
    - Add `report($e);` as the first line of each `failed()` method, alongside existing `Log::error()` calls.
    - **In the plan:** add an explicit "every new job's `failed()` calls `report($e)`" requirement to the implementation-time checklist for §28.4 / §28.6 / §28.7 jobs.
- **Why:** Nightwatch only sees exceptions via `report($e)`. `Log::error` alone produces a Horizon failed-job count with no exception, no stack trace, and no alert.

### OBS-3 · P2 · S · 🅲
**`ReconcileStuckPayoutsJob` swallows per-payout Stripe API errors without `report($e)`**

- **Where:** `app/Jobs/Stripe/ReconcileStuckPayoutsJob.php:71–80`.
- **What to do:** Add `report($e)` inside the inner per-payout catch before `$errored++`. Continue iteration is still correct.
- **Why:** During a Stripe degradation event the job currently "succeeds" with logs but no Nightwatch alert.

### JOB-3 · P3 · S · 🅲
**`SyncCustomerMarketingOptInJob` has no `failed()` method — completely silent on exhaustion**

- **Where:** `app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php:17`.
- **What to do:** Add a minimal `failed()` with `report($e)` + `Log::error()` (use only `professional_id` + error message to avoid logging the email field as PII).

---

# Section 4 — Caching & Edge Routing (Plan §6, §7, §18, §28.7)

### CACHE-2 · P2 · M · 🅿
**`SiteObserver` does not push-invalidate Cloudflare edge cache on site content changes**

- **Where:** `app/Observers/Core/SiteObserver.php:39–63`.
- **What to do:**
    - Build `app/Services/Cloudflare/CloudflarePurgeService.php` wrapping `POST /zones/{zone_id}/purge_cache`.
    - Build `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php` with its own inlined backoff (NOT `HasCloudflareRetryPolicy`, which is KV-specific).
    - In `SiteObserver::saved()` dispatch `CloudflareCachePurgeJob::dispatch($site->professional->handle)` after the existing Redis `invalidateSite()`, on every save (not only on subdomain change).
    - Same dispatch from `AccountTypeTransitionService::transition()`.
- **Why:** Cloudflare Workers do NOT auto-expire `caches.default` entries based on `Cache-Control` headers — explicit purge API call required. Without it, profile edits take up to `s-maxage=300` (5 min) to propagate to new visitors. Plan §18 calls this out but the wiring needs to be specified down to the observer hook.

### CACHE-3 · P2 · S · 🅿
**`SyncSubdomainToKvJob` hard-deletes the KV entry for professionals with no brand link instead of upserting an `individual` routing record**

- **Where:** `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:58–71`.
- **What to do:**
    - Replace the `$kv->delete($current)` branch with `$kv->put($current, ['type' => 'individual'], null)` when `$siteUrl` is null/empty and the professional is not a brand.
    - Keep genuine delete calls (handle retirement, professional hard-deletion) in `RetireSubdomainFromKvJob`.
    - Run a one-off backfill writing `{type:'individual'}` for every non-brand, non-affiliate professional whose KV entry is currently absent.
    - Plan §5 already states "Changing that delete branch to write `{type:'individual'}` is the only routing-job change" — but this needs to be a written code-level instruction, not just a sentence.
- **Why:** The delete-then-rebuild antipattern in miniature. Once individual sitepages ship, every individual professional and every partner who leaves a brand experiences a 404 window between the delete job completing and the next create job running.

---

# Section 5 — Lifecycle Correctness (existing code on the plan's blast radius)

### LIFE-1 · P1 · S · 🅲
**Legacy affiliate cart-attribute key in out-of-order Shopify webhook stub inserts**

- **Where:** `app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php:596–613`.
- **What to do:** In `resolveAffiliateIdFromPayload`, try `_partna_affiliate_id` (UUID direct-lookup) first, then fall back to the legacy `'affiliate'` handle-based lookup for any pre-migration carts still in flight. Match the canonical pattern in `ProcessShopifyOrderWebhookJob` (line 94–101).
- **Why:** Shopify webhooks are at-least-once and frequently out-of-order. When `orders/cancelled`, `orders/edited`, or `refunds/create` arrives before `orders/paid`, the first-seen stub path fires — and currently looks up the affiliate via the retired `'affiliate'` handle key. For any order placed through the current Hydrogen storefront (which sets `_partna_affiliate_id` only), the lookup returns null, the stub is silently skipped, the event is permanently lost. Affects the plan's account-type work because it touches the same handle-lookup surface — fix together.

### LIFE-2 · P2 · S · 🅲
**`BrandStatusService::sync()` — unguarded read-modify-write produces duplicate audit rows**

- **Where:** `app/Services/Professional/Brand/BrandStatusService.php:105–155`.
- **What to do:** Wrap the `sync()` body in `DB::transaction()` with `BrandProfile::where(...)->lockForUpdate()->first()` as the first read. Optionally add `UNIQUE (professional_id, from_status, to_status, created_at::date)` on `core.brand_status_history` as belt-and-suspenders.
- **Why:** Concurrent callers (reinstall callback + reconcile sweep) can both read the same `currentStatusValue`, compute the same `newStatusValue`, both insert into `brand_status_history` — phantom audit rows. Relevant to the plan because brand status transitions become more frequent under the `account_type` work.

---

# Section 6 — Configuration Hygiene (Plan §18, §28.7, §28.8, §28.14, §33)

### CFG-1 · P2 · S · 🅿
**`SIDEST_INDIVIDUAL_WAITLIST_ENABLED` planned without `.env.example` or `config/sidest.php` entry**

- **What to do:**
    - Add `SIDEST_INDIVIDUAL_WAITLIST_ENABLED=false` to `.env.example` with inline comment.
    - Add `'individual_waitlist_enabled' => env('SIDEST_INDIVIDUAL_WAITLIST_ENABLED', false)` to `config/sidest.php`.
    - In `BootstrapController`, read `config('sidest.individual_waitlist_enabled')` — never `env()` directly (so it participates in `php artisan config:cache`).
- **Why:** Per project convention all `SIDEST_*` feature flags live in `config/sidest.php` with explicit `false` default so fresh environments fail-closed. Direct `env()` calls bypass `config:cache` and read null once the cache is warm.

### CFG-2 · P2 · S · 🅿
**`CloudflarePurgeService` planned without env var or config key for API token / zone ID**

- **What to do:**
    - `.env.example` additions:
        ```
        CLOUDFLARE_CACHE_PURGE_TOKEN=   # Cloudflare API token — Zone.Cache Purge permission on the partna.au zone
        CLOUDFLARE_ZONE_ID=             # partna.au zone ID
        ```
    - `config/services.php`:
        ```php
        'cloudflare' => [
            'cache_purge_token' => env('CLOUDFLARE_CACHE_PURGE_TOKEN'),
            'zone_id'           => env('CLOUDFLARE_ZONE_ID'),
        ],
        ```
    - Match the pattern of the existing `CloudflareKvService`.

### CFG-3 · P3 · M · 🅿
**Rate-limit values specified as literals rather than `config/sidest.php` entries**

- **Where:** Plan §28.8 (60/min/IP on public profile), §33 (10/min, 100/hr, 5-fail slowdown on signup code).
- **What to do:** Move both rate-limit configurations into `config/sidest.php` under a `rate_limits` key so they can be tuned during traffic spikes without redeploy.

---

# Section 7 — Security (existing code; relevant to plan because plan touches these surfaces)

### SEC-1 · P2 · S · 🅲
**`PolicyCoverageTest` stale POLICY_EXEMPT entry breaks CI coverage; two additional models are unexempted**

- **Where:** `tests/Feature/Security/PolicyCoverageTest.php:38`; `app/Providers/AppServiceProvider.php` (no registration for `CommissionClawback`, `SectionView`).
- **What to do:**
    - Fix line 38: replace `\App\Models\Retail\CommissionPayoutItem::class` with `\App\Models\Commerce\CommissionPayoutItem::class`.
    - Add `SectionView` to POLICY_EXEMPT (public-ingestion pattern, matches `CartEvent`/`LinkClick`/`SiteVisit`).
    - Add `CommissionClawback` to POLICY_EXEMPT (audit record under `CommissionPayout`, access via `CommissionPolicy`).
- **Why:** The CI security gate is currently broken. Fix before adding the policies the plan calls for in §28.11.

### SEC-2 · P2 · S · 🅲
**`SUPABASE_JWKS_FAIL_CLOSED` defaults to `false` with no production boot guard**

- **Where:** `config/supabase.php:18`, `app/Http/Middleware/Auth/VerifySupabaseJwt.php:89–143`, `app/Providers/AppServiceProvider.php:119–130`.
- **What to do:**
    - Add a production boot guard mirroring `PARTNA_THROTTLE_ENABLED`: if `SUPABASE_JWKS_FAIL_CLOSED` is false and `app()->isProduction()`, throw at boot (or at minimum `Log::critical`).
    - Set `SUPABASE_JWKS_FAIL_CLOSED=true` in production.
- **Why:** JWKS outage falls back to Auth-Server, which sets `supabase_aal = 'aal1'` regardless of true claims. Safe but silent — no alert. The config comment already says "Recommended for production."

### SEC-3 · P3 · S · 🅲
**JWKS key warming caches sibling kid entries with the requesting JWT's algorithm rather than the key's own JWK algorithm**

- **Where:** `app/Http/Middleware/Auth/VerifySupabaseJwt.php:231–240`.
- **What to do:** Derive each kid's algorithm from the JWK itself (`$parsedKey->getAlgorithm()`), not from the outer `$alg` variable.
- **Why:** Dormant today (Supabase serves one algorithm at a time). Becomes a transient latency spike during planned key rotation.

---

# Section 8 — Observability (existing code; plan should require `report($e)` for new jobs)

### OBS-1 · P1 · S · 🅲
**Square and Fresha webhook inline-sync fallback silently 200s on failure with no Nightwatch reporting**

- **Where:** `app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php:102–115`, `FreshaCatalogWebhookController.php:112–126`.
- **What to do:**
    - Add `report($syncError)` before the existing `Log::warning` in both controllers.
    - Return 5xx (not 200) when both queue dispatch AND inline sync fail, so the vendor retries.
- **Why:** Note from project memory: booking + Fresha + Square are being dropped, but this fix is trivial and the failure mode is real until those controllers are removed. Worth doing or worth deleting the controllers — flag for the plan author's decision.

### OBS-2 — see JOB-1 above (merged: same finding).
### OBS-3 — see Section 3 above.

---

# Section 9 — API Contract (Plan §28.8, §28.11, §28.13, §46)

### API-1 · P3 · S · 🅸
**`stripe_connect_status` unconditionally present in professional-facing Resources, no capability guard for future individual account type**

- **Where:** `app/Http/Resources/ProfessionalDashboardResource.php:37`, `app/Http/Resources/ProfessionalResource.php:35`.
- **What to do:** Wrap with `$this->when(AccountCapabilities::for($this->resource)->requires_stripe_connect, ...)` once `AccountCapabilities` (§28.3) lands. Do this as part of the §28.11 Resource capability-gating pass.
- **Why:** Individuals will get `stripe_connect_status: null` in their dashboard payload — meaningless field that the frontend `account-capabilities.ts` must handle. Trivial to gate.

### API-2 · P3 · S · 🅸
**`ProfessionalDashboardResource` returns legacy `professional_type` but not the incoming `account_type` field required by the frontend capability module**

- **Where:** `app/Http/Resources/ProfessionalDashboardResource.php:17`.
- **What to do:** Add `'account_type' => $this->account_type?->value` once the §28.1 migration is applied. Keep `professional_type` in parallel during the dual-write window (§8). Track as part of the same PR that adds the column.
- **Why:** Plan §46 Track A explicitly identifies `account_type` as a required field. Without it, Track B's `account-capabilities.ts` falls back to two-state logic and routes individual users incorrectly.

### TEST-4 · P2 · S · 🅸
**Future `IndividualProfileResource` needs a snapshot / field-exclusion test enforcing plan rule #7 at implementation time**

- **Where:** Planned `app/Http/Resources/PublicSite/IndividualProfileResource.php` (§28.8).
- **What to do:** When implementing, add a feature test asserting the `GET /api/public/profiles/{handle}` response for an individual:
    - **Contains:** bio, services, booking, links, newsletter status, analytics IDs, `site.settings.design`.
    - **Does NOT contain:** `placeholders`, `fallback_gallery`, `brand_logo`, `brand_slogan`.
- **Why:** Plan §50 rule #7 says "Brand-fallback content stays in Hydrogen's data path. The Astro app for individuals never sees them." The enforcement mechanism is a feature test asserting key absence; the plan should reference that test by name in §51.

---

# Section 10 — Test Coverage (Plan-side and existing-code)

### TEST-1 · P2 · M · 🅲
**Shopify secondary webhook controllers missing HMAC signature-fail tests**

- **Where:** `ShopifyOrdersEditedWebhookController`, `ShopifyRefundsCreateWebhookController`, `ShopifyAppUninstalledWebhookController`, `ShopifyOrdersCancelledWebhookController`, `ShopifyThemePublishedWebhookController`.
- **What to do:** Each needs `it('returns 401 when HMAC is invalid', ...)` + `it('accepts valid HMAC and dispatches', ...)` tests, following the pattern in `ShopifyOrderWebhookControllerTest.php`.
- **Why:** If the `VerifyShopifyWebhookSignature` middleware is ever refactored or its config key changes, the only catch point is the controllers that already have HMAC tests. Five don't.

### TEST-2 · P2 · L · 🅲
**Seven policies have no dedicated ability-coverage test (allowed + denied)**

- **Where:** `AffiliateProductPolicy`, `BrandResourcePolicy`, `GdprPolicy`, `ProfessionalSelfPolicy`, `SubscriptionPolicy`, `PartnaStaffPolicy`, `FeatureFlagPolicy`.
- **What to do:** Add tests in `tests/Feature/Security/PolicyEnforcement/` asserting (a) the correct actor is allowed, (b) wrong-actor returns 404 (not 403) for ownership checks per the project doctrine, (c) cross-tenant requests are denied.
- **Why:** `PolicyCoverageTest` asserts every model has a policy *registered* — it does NOT assert every policy method has an allowed + denied test. The MFA Foundation work and the `account_type` capability migration both touch policy gates; regressions in the seven uncovered policies go undetected.

### TEST-3 · P2 · M · 🅸
**Future plan migrations add CHECK / UNIQUE / FK constraints with no described constraint-rejection tests**

- **Where:** Plan §28.1, §28.16, §34, §36.
- **What to do:** For each new constraint, add a Pest test asserting bad inserts are rejected. Follow the pattern in `tests/Feature/Commerce/OrdersSchemaMigrationTest.php`:
    - `_enforce_account_type_constraints.sql`: INSERT with `account_type = 'invalid'` fails the CHECK.
    - `_enforce_brand_signup_code_constraints.sql`: duplicate `signup_code` fails UNIQUE.
    - `_create_brand_signup_code_audit.sql`: `event = 'invalid_event'` fails CHECK; `event = 'claimed'` with NULL `joined_professional_id` fails compound CHECK.
    - `_add_soft_deletes_to_brand_partner_links.sql`: orphan `affiliate_professional_id` fails FK.
- **Why:** A subtly-wrong CHECK constraint passes migration but accepts invalid values silently for months.

### TEST-5 · P3 · S · 🅸
**Future `BrandProfile::creating` Eloquent hook for `signup_code` needs a model creation test confirming the hook fires**

- **Where:** Planned `BrandProfile::creating` hook (§33).
- **What to do:** Add `it('generates signup_code on factory create', ...)` asserting non-null, 16 alphanumeric chars. Negative test using `BrandProfile::create([... 'signup_code' => null ...])` should produce a generated code, not null. Document in test fixtures that `createQuietly()` skips the hook.
- **Why:** Plan §36 already notes the asymmetry: the `creating` hook does NOT fire on existing rows. Test factories have the same gotcha — `createQuietly()` skips model events and produces null `signup_code`, which would fail the NOT NULL constraint added in §36 step 3.

### SCALE-3 · P2 · S · 🅿
**`brand_profiles_signup_code_unique` constraint in §36 step 3 uses synchronous `ADD CONSTRAINT … UNIQUE`**

- **Where:** Planned `<ts>_enforce_brand_signup_code_constraints.sql` (§36 step 3).
- **What to do:** Replace with the non-blocking two-step form:
    ```sql
    CREATE UNIQUE INDEX CONCURRENTLY brand_profiles_signup_code_unique
        ON brand.brand_profiles (signup_code);

    ALTER TABLE brand.brand_profiles
        ADD CONSTRAINT brand_profiles_signup_code_unique
        UNIQUE USING INDEX brand_profiles_signup_code_unique;
    ```
    Precede with `SET lock_timeout = '2s'; SET statement_timeout = '60s';` so the migration fails fast if blocked.
- **Why:** `ADD CONSTRAINT … UNIQUE` builds the backing index under `ACCESS EXCLUSIVE`. Tiny table today; the pattern being established here will be copied to future migrations on larger, hotter tables. Establishing CONCURRENTLY as the default now prevents the future incident. Note that the commit `526c9f80` exemption for `CREATE INDEX` on brand-new columns does NOT apply here — by step 3 the column has been populated by the backfill.

---

## Appendix A — Findings cross-referenced by audit ID

| ID | Tier | Source lens | Action class |
|----|------|-------------|--------------|
| MIG-1..7 | P1×1, P2×3, P3×3 | migration-safety | 🅿 / 🅸 |
| DATA-1 | P1 | data-integrity-and-privacy | 🅲 |
| DATA-2 | P1 | data-integrity-and-privacy | 🅲 |
| DATA-3 | P1 | data-integrity-and-privacy | 🅲 + 🅿 |
| DATA-4 | P2 | data-integrity-and-privacy | 🅲 |
| DINT-1 | P1 | data-integrity | 🅲 (= DATA-3) |
| SCHEMA-1 | P1 | schema-rls | 🅲 (= DATA-3) |
| SCHEMA-2 | P1 | schema-rls | 🅿 |
| SCALE-1 | P1 | database-and-queue-scaling | 🅸 |
| SCALE-2 | P2 | database-and-queue-scaling | 🅸 |
| SCALE-3 | P2 | database-and-queue-scaling | 🅿 |
| SCALE-4 | P2 | database-and-queue-scaling | 🅲 (= JOB-2) |
| CFG-1 | P2 | configuration-hygiene | 🅿 |
| CFG-2 | P2 | configuration-hygiene | 🅿 |
| CFG-3 | P3 | configuration-hygiene | 🅿 |
| JOB-1 | P2 | job-queue-correctness | 🅲 + 🅸 |
| JOB-2 | P2 | job-queue-correctness | 🅲 (= SCALE-4) |
| JOB-3 | P3 | job-queue-correctness | 🅲 |
| SEC-1 | P2 | security | 🅲 |
| SEC-2 | P2 | security | 🅲 |
| SEC-3 | P3 | security | 🅲 |
| OBS-1 | P1 | observability | 🅲 |
| OBS-2 | P2 | observability | 🅲 (= JOB-1) |
| OBS-3 | P2 | observability | 🅲 |
| LIFE-1 | P1 | lifecycle-correctness | 🅲 |
| LIFE-2 | P2 | lifecycle-correctness | 🅲 |
| CACHE-2 | P2 | scaling-antipatterns | 🅿 |
| CACHE-3 | P2 | scaling-antipatterns | 🅿 |
| API-1 | P3 | api-contract | 🅸 |
| API-2 | P3 | api-contract | 🅸 |
| TEST-1 | P2 | test-coverage | 🅲 |
| TEST-2 | P2 | test-coverage | 🅲 |
| TEST-3 | P2 | test-coverage | 🅸 |
| TEST-4 | P2 | test-coverage | 🅸 |
| TEST-5 | P3 | test-coverage | 🅸 |

## Appendix B — Source per-lens audit files

All in `audits/standalone-pages/`:
- `audit-2026-05-19--migration-safety-lock-on-deploy-risk-backfill-ord.md`
- `audit-2026-05-19--test-coverage-critical-paths-idempotency-race-saf.md`
- `audit-2026-05-19--database--queue-scaling-n1-unbounded-reads-connec.md`
- `audit-2026-05-19--data-integrity--privacy-fk-hygiene-soft-delete-co.md`
- `audit-2026-05-19--data-integrity--privacy-fk-hygiene-soft-delete-co-2.md`
- `audit-2026-05-19--configuration-hygiene-env-outside-config-missing.md`
- `audit-2026-05-19--job-queue-correctness-idempotency-retry-safety-sh.md`
- `audit-2026-05-19--security-auth-boundaries-tenant-isolation-webhook.md`
- `audit-2026-05-19--observability-logging-gaps-silent-failures-missin.md`
- `audit-2026-05-19--lifecycle-correctness-race-safety-idempotency-anc.md`
- `audit-2026-05-19--scaling-antipatterns-write-amplification-rebuild.md`
- `audit-2026-05-19--schema---rls---searchpath-database-side-correctne.md`
- `audit-2026-05-19--api-contract--resource-leakage-raw-model-fields-b.md`
