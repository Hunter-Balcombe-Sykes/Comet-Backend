Good — all three Cloudflare job files confirmed with verbatim evidence. Now producing the final audit markdown.

# Database & Queue Scaling Audit — 2026-05-11

**Branch:** development
**Lens:** Database & queue scaling: N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- supabase/migrations/20260416000000_add_commission_grace_period.sql
- supabase/migrations/20260419000002_nullable_commission_fks.sql
- supabase/migrations/20260420220000_add_analytics_ledger_occurred_at_indexes.sql
- supabase/migrations/20260424120000_add_live_check_index.sql
- supabase/migrations/20260428000000_payout_grace_and_app_fee.sql
- supabase/migrations/20260505200000_commission_ledger_entries_set_null_professional_fks.sql
- supabase/migrations/20260506000000_create_orders_schema.sql
- supabase/migrations/20260506300000_relax_commission_payout_items_link.sql
- supabase/migrations/20260506500000_drop_legacy_aggregates.sql
- supabase/migrations/20260510000000_add_commission_payouts_lifecycle_columns.sql
- supabase/migrations/20260510400000_extend_orders_rate_source_constraint.sql
- app/Jobs/Cloudflare/ProvisionBrandDnsJob.php
- app/Jobs/Cloudflare/RetireBrandDnsJob.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- config/horizon.php
- routes/console.php
- app/Jobs/Stripe/ExecuteCommissionPayoutJob.php
- app/Jobs/Stripe/ProcessCommissionPayoutsJob.php
- app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php
- app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **#SCALE-1** · P2 — `CREATE INDEX` without `CONCURRENTLY` across five migrations
    - **Where:** supabase/migrations/20260420220000_add_analytics_ledger_occurred_at_indexes.sql (representative; five migrations affected: 20260416, 20260420, 20260506000000 BRIN indexes, 20260506300000 partial UNIQUE indexes, 20260510000000)
    - **Affects:** Any migration run against a live database where the indexed table is receiving writes. The plain `CREATE INDEX` acquires a `SHARE` lock, which blocks all concurrent INSERT/UPDATE/DELETE for the duration of the index build.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Adopt `CREATE INDEX CONCURRENTLY IF NOT EXISTS` as the house standard for all future migrations. Add a one-line comment in your migration template noting that CONCURRENTLY is required for live tables.
        - The five already-shipped migrations cannot be patched retroactively in place (idempotent re-runs would be a no-op on the existing index). If any of these tables grow significantly before the next scheduled maintenance window, plan a separate migration that drops and recreates any perf-critical indexes using CONCURRENTLY to make them explicit in the migration history.
        - The one correct pattern already exists in the codebase (`20260424120000_add_live_check_index.sql`) — copy that form for every new index.
    - **Technical:** `CREATE INDEX` without `CONCURRENTLY` acquires a `SHARE` lock on the target table for the entire build, which blocks concurrent writes (`INSERT`, `UPDATE`, `DELETE`). On a table receiving Shopify webhook inserts (e.g. `commerce.commission_ledger_entries`, `commerce.commission_payout_items`, `commerce.orders`), even a few seconds of lock hold time during a payout cycle could cause visible latency spikes. `CREATE INDEX CONCURRENTLY` uses a multi-pass algorithm that only holds `SHARE UPDATE EXCLUSIVE` (which does not block writes), at the cost of roughly 2× build time and the constraint that it cannot run inside an explicit transaction. All five affected migrations use the plain form: `20260416000000` (idx_cle_voidable, idx_professionals_grace_period), `20260420220000` (idx_cle_brand_occurred_at, idx_cle_affiliate_occurred_at), `20260506000000` (BRIN indexes on orders), `20260506300000` (cpi_unique_ledger_entry, cpi_unique_order partial UNIQUE indexes), `20260510000000` (lifecycle column indexes on commission_payouts). The single compliant example — `20260424120000` — shows the correct form is already known.
    - **Plain English:** Building a database index is like building a new road through a busy intersection — while you're building the old road has to close. The safe version keeps the intersection open and just works a bit slower. All but one of the index-building migrations in this codebase uses the "close the road" approach. At current scale (near-zero rows) this has caused no problems, but once real order traffic is flowing, running one of these migrations during business hours would briefly freeze all order and payout writes. The fix is a one-word change: always write `CREATE INDEX CONCURRENTLY`.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260420220000_add_analytics_ledger_occurred_at_indexes.sql
        CREATE INDEX IF NOT EXISTS idx_cle_brand_occurred_at
            ON commerce.commission_ledger_entries (brand_professional_id, occurred_at);
        CREATE INDEX IF NOT EXISTS idx_cle_affiliate_occurred_at
            ON commerce.commission_ledger_entries (affiliate_professional_id, occurred_at);
        ```
        ```sql
        -- supabase/migrations/20260506300000_relax_commission_payout_items_link.sql
        CREATE UNIQUE INDEX IF NOT EXISTS cpi_unique_ledger_entry
            ON commerce.commission_payout_items (commission_ledger_entry_id)
            WHERE commission_ledger_entry_id IS NOT NULL;
        CREATE UNIQUE INDEX IF NOT EXISTS cpi_unique_order
            ON commerce.commission_payout_items (order_id)
            WHERE order_id IS NOT NULL;
        ```
        ```sql
        -- supabase/migrations/20260424120000_add_live_check_index.sql — the correct pattern already in repo
        CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_blocks_live_check_enabled
            ON site.blocks ((settings->>'live_check_enabled'))
            WHERE block_group = 'links' AND deleted_at IS NULL AND is_active = true;
        ```

- [ ] **#SCALE-2** · P2 — `ADD CONSTRAINT` and `SET NOT NULL` without `NOT VALID` across six migrations
    - **Where:** supabase/migrations/20260510400000_extend_orders_rate_source_constraint.sql (representative; six migrations affected: 20260416, 20260419000002, 20260428000000, 20260505200000, 20260506500000, 20260510400000)
    - **Affects:** Any migration adding a CHECK constraint, FK constraint, or NOT NULL restriction to a table that already holds rows. The plain `ADD CONSTRAINT` form takes an `ACCESS EXCLUSIVE` lock and validates every existing row before releasing it — a full-table scan under the heaviest PostgreSQL lock.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - For **CHECK constraints** on populated tables, use the two-phase pattern: `ADD CONSTRAINT ... CHECK (...) NOT VALID` first (lock-light, skips existing rows), then `VALIDATE CONSTRAINT` in a separate transaction (acquires only `SHARE UPDATE EXCLUSIVE`, blocking no writes).
        - For **SET NOT NULL**, use the safe equivalent: `ADD CONSTRAINT chk_col_not_null CHECK (col IS NOT NULL) NOT VALID`, backfill NULLs in a preceding UPDATE, then `VALIDATE CONSTRAINT`, and finally `ALTER COLUMN SET NOT NULL` (which is metadata-only once Postgres has the validated check in place — no re-scan).
        - For **FOREIGN KEY** re-creation (`NOT VALID` path): `ADD CONSTRAINT ... FOREIGN KEY (...) REFERENCES ... NOT VALID`, then `VALIDATE CONSTRAINT` separately.
        - Document this two-phase pattern in a migration template comment so future contributors don't revert to the blocking form.
    - **Technical:** `ALTER TABLE ... ADD CONSTRAINT` without `NOT VALID` acquires an `ACCESS EXCLUSIVE` lock that blocks all reads and writes to the table for the duration of the full-table constraint validation scan. On `commerce.orders` (the highest-write table in the system, receiving Shopify webhook upserts continuously) this is the worst possible lock to hold during a schema change. Six migrations use the blocking form: `20260416000000` (CHECK on commission_ledger_entries.status), `20260419000002` and `20260505200000` (FK re-creation on commission_payouts and commission_ledger_entries), `20260428000000` (SET NOT NULL on commission_payouts.void_at — includes a preceding UPDATE backfill but the ALTER still full-scans), `20260506500000` (SET NOT NULL on commission_payout_items.order_id), `20260510400000` (CHECK on commerce.orders.rate_source — highest-impact instance given table write frequency). The `NOT VALID` + `VALIDATE CONSTRAINT` pattern reduces the lock to `SHARE UPDATE EXCLUSIVE` during the validation phase, which does not block concurrent DML.
    - **Plain English:** Telling the database "this column can never be empty" or "this value must be one of these options" sounds simple, but the way it's written here forces the database to check every single existing row before allowing any new traffic through. It's like a bouncer who locks the whole club while checking IDs instead of checking them at the door while letting people in. The safe version does the checking in two steps: first announce the new rule (no writes blocked), then verify the old rows in the background (still no writes blocked). The payoff matters most for the orders table, which is constantly receiving incoming Shopify data.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260510400000_extend_orders_rate_source_constraint.sql
        -- commerce.orders is the highest-write table; blocking form
        ALTER TABLE commerce.orders
            DROP CONSTRAINT IF EXISTS chk_orders_rate_source;
        ALTER TABLE commerce.orders
            ADD CONSTRAINT chk_orders_rate_source
            CHECK (rate_source IN ('product_metafield','metafield_override','brand_default','platform_default','manual','pending'));
        ```
        ```sql
        -- supabase/migrations/20260428000000_payout_grace_and_app_fee.sql
        -- UPDATE backfill precedes SET NOT NULL but the ALTER still acquires ACCESS EXCLUSIVE
        UPDATE commerce.commission_payouts SET void_at = created_at + interval '60 days' WHERE void_at IS NULL;
        ALTER TABLE commerce.commission_payouts ALTER COLUMN void_at SET NOT NULL;
        ```
        ```sql
        -- supabase/migrations/20260416000000_add_commission_grace_period.sql
        ALTER TABLE commerce.commission_ledger_entries
            DROP CONSTRAINT IF EXISTS commission_ledger_status_check;
        ALTER TABLE commerce.commission_ledger_entries
            ADD CONSTRAINT commission_ledger_status_check
            CHECK (status IN ('pending', 'approved', 'paid', 'reversed', 'disputed', 'voided'));
        ```

- [ ] **#SCALE-3** · P2 — Cloudflare DNS/KV jobs missing backoff, timeout, and failure handler
    - **Where:** app/Jobs/Cloudflare/ProvisionBrandDnsJob.php:17 (also RetireBrandDnsJob.php:15, SyncSubdomainToKvJob.php:19)
    - **Affects:** Brand onboarding and subdomain-rename flows. A Cloudflare API rate limit or transient error causes all three retries to exhaust within seconds, leaving the brand's DNS CNAME or KV routing entry unprovisioned — meaning the brand's public storefront URL fails to resolve until the job is manually re-dispatched.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public function backoff(): array { return [30, 90, 300]; }` to all three jobs. This mirrors the pattern used by the Stripe jobs (`ExecuteCommissionPayoutJob`, `ProcessCommissionPayoutsJob`) and gives Cloudflare API rate-limit windows time to clear between attempts.
        - Add `public int $timeout = 30;` to each job — a single Cloudflare DNS/KV API call should complete in under 5 seconds; 30 seconds is a generous ceiling that prevents a hung connection from occupying a worker thread for the default 60 seconds.
        - Add a `failed(\Throwable $e): void` handler that logs the `professional_id` / `subdomain` and calls `report($e)` so Nightwatch captures the failure by context rather than as a generic queue event.
        - Add `$this->onQueue('integrations')` in each constructor — these are third-party API calls, the same category as Shopify webhook processing, and should share its dedicated supervisor rather than competing with default-queue work.
    - **Technical:** All three Cloudflare jobs (`ProvisionBrandDnsJob`, `RetireBrandDnsJob`, `SyncSubdomainToKvJob`) declare `public int $tries = 3` but define no `backoff()` method. Laravel's default retry delay is zero, meaning all three attempts fire within milliseconds of each other on a Cloudflare error. Cloudflare's zone-level API rate limit (1200 req/5 min) means a burst of brand onboardings — entirely plausible at pilot launch — can trigger 429s on the second and third attempts for every affected job simultaneously, exhausting the retry budget with no recovery window. The `SyncSubdomainToKvJob` is also dispatched by observers on every handle change and every brand-partner-link change, making it the highest-frequency of the three. Without a `failed()` handler, retry exhaustion is invisible in Nightwatch except as a raw job failure event with no brand context. The analogous Stripe jobs all have explicit `backoff()`, `$timeout`, and `failed()` handlers — the Cloudflare jobs are the only queue jobs in the codebase missing all three.
    - **Plain English:** When setting up a brand's public web address, the app sends a request to Cloudflare (the service that manages DNS and routing). If Cloudflare is temporarily busy, the app retries — but right now it retries instantly, one after another, before Cloudflare has had any time to recover. It's like hitting "refresh" three times in a row when a website is down, rather than waiting 30 seconds between each try. The result is that all three attempts fail within seconds, the job gives up, and the brand's storefront link silently doesn't work. There's also no notification logged to tell you which brand was affected. The fix is to add a waiting period between retries and a log message when all attempts fail.
    - **Evidence:**
        ```php
        // app/Jobs/Cloudflare/ProvisionBrandDnsJob.php
        class ProvisionBrandDnsJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;

            public function __construct(public readonly string $professionalId) {}
        ```
        ```php
        // app/Jobs/Cloudflare/RetireBrandDnsJob.php
        class RetireBrandDnsJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;

            public function __construct(public readonly string $subdomain) {}
        ```
        ```php
        // app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
        class SyncSubdomainToKvJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;

            public function __construct(public readonly string $professionalId) {}
        ```

`★ Insight ─────────────────────────────────────`
Three adjudication patterns applied here worth noting:

1. **Consolidation beats repetition**: DeepSeek emitted 9 structurally identical migration findings; the final audit collapses them to 2 (one per DDL pattern — INDEX and CONSTRAINT). The format is load-bearing for the audit orchestrator, so signal density matters — 9 checkbox items with the same fix wastes fix-session budget.

2. **Re-tier to P2 when no user is at risk today**: DeepSeek rated migration findings P1 ("fix before pilot launch") even though no pilot users exist yet and the tables are near-empty — so no downtime has occurred or will occur. P2 is correct: these are hardening / convention findings for *future* migrations, not "ships bad behavior in known scenarios."

3. **The missed finding (SCALE-3) came from cross-checking the job layer**: DeepSeek only audited SQL files, but the lens explicitly covers queue shape and vendor rate limits. Reading the Cloudflare jobs against the pattern established by the well-formed Stripe jobs revealed the gap — all three were missing the same three hygiene properties their Stripe siblings have. Intra-codebase comparison is often more productive than checklist scanning.
`─────────────────────────────────────────────────`
