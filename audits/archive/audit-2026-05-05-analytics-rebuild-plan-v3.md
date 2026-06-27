`★ Insight ─────────────────────────────────────`
The plan has an internal contradiction that DeepSeek missed entirely: the Risks table explicitly states "Backfill writes in `occurred_at` order" as the BRIN mitigation — but the Phase 2 implementation describes `lazyById(1000)` which orders by primary key UUID (no temporal correlation). Both sentences are in the plan, in different sections, directly contradicting each other. The Phase 4 FK ordering issue is another self-defeating sequence that becomes obvious only when you trace the full migration linearly.
`─────────────────────────────────────────────────`

# Analytics Rebuild Plan v3 Audit — 2026-05-05

**Branch:** development-v2
**Lens:** Audit of Plan v3 (Pre-Beta Direct Rebuild) in docs/analytics-rebuild-plan.md. The scope is a markdown planning document, NOT source code. Plan v3 dropped Scientist + dual-write in favor of a direct cutover (justified for pre-beta with zero customers). Find: (1) correctness gaps in proposed schema/migration; (2) missing edge cases (out-of-order webhooks, refund races, stub-creation race, multi-shop brands, payout reconciliation, GDPR redaction); (3) scaling risks (BRIN degradation, trigger throughput during ingest, cache stampede, rollup partial-refund logic); (4) security gaps (RLS policies adequate? cross-tenant leakage? service_role escapes?); (5) operational risks (rollback approach for pre-beta, what breaks in cutover); (6) Shopify-specific gotchas (refunds-before-paid stub, X-Shopify-Event-Id rotation, orders/edited frozen-commission UX); (7) data-integrity risks during backfill (status mapping, line_items reconstruction, trigger-disable then recompute). Be skeptical — this is the third revision and may have new blind spots even while addressing audit findings from prior versions. Quote the plan section for each finding. Use full thinking budget; emit every finding worth fixing.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- docs/analytics-rebuild-plan.md

## Progress

- P1 High: 0 of 3 complete
- P2 Medium: 0 of 5 complete

---

## P1 — Fix before pilot launch

- [ ] **PLANV3-1** · P1 — `orders/edited` / `orders/cancelled` stub semantics undefined; NOT NULL columns (`commission_cents`, `commission_rate`, `rate_source`) have no fallback values for first-seen non-paid events
    - **Where:** docs/analytics-rebuild-plan.md — "Out-of-order handling" Race 1/2 section; "Topic strategy" section; Phase 3 smoke tests
    - **Affects:** Order write correctness when Shopify delivers `orders/edited` or `orders/cancelled` before `orders/paid` — likely crashes INSERT due to NOT NULL constraints or requires silent event drops, both silently losing data
    - **Effort:** M (~2–4h) to add explicit stub semantics to the plan and implement handlers for first-seen non-paid events
    - **What to do:**
        - Expand the stub-creation contract beyond Race 2 (`refunds/create`): any handler that calls `INSERT ... ON CONFLICT` for a topic that does not compute commission must define what it writes for `commission_cents`, `commission_rate`, and `rate_source` when the order row is absent.
        - For `orders/edited` before `orders/paid`: use `commission_cents = 0`, `commission_rate = 0.0`, `rate_source = 'pending'` — then add `'pending'` as an allowed value in the `rate_source` comment (or use a real sentinel column like `commission_locked boolean DEFAULT false`). The LWW `WHERE EXCLUDED.shopify_updated_at >` guard guarantees `orders/paid` will overwrite.
        - For `orders/cancelled` before `orders/paid`: same stub pattern; status = `'cancelled'`.
        - Add smoke tests for both scenarios to the Phase 3 verification list (currently only Race 2 is listed).
        - Confirm `rate_source` does not have a DB-level CHECK constraint that would block a sentinel value — the plan's column comment lists four allowed values but does not show a CHECK.
    - **Technical:** Race 1 covers "any order topic before order is known" with the INSERT ON CONFLICT upsert, but that SQL pattern only works if the handler actually has values to INSERT. `orders/edited` is explicitly "snapshot-only update; commission frozen — Decision #3" — meaning the handler deliberately does NOT compute commission. If the order row doesn't exist yet, the handler must INSERT with commission_cents/commission_rate/rate_source set to something. The plan never says what. Unlike Race 2 (`refunds/create`), which receives explicit stub treatment, `orders/edited` and `orders/cancelled` are left to implicit behavior. The four `rate_source` values in the column comment (`product_metafield | brand_default | platform_default | manual`) do not include any sentinel for "commission not yet computed," meaning any stub INSERT would be semantically wrong even if it doesn't DB-fail. The fix requires either: (a) adding a `'pending'` sentinel to rate_source, or (b) making commission fields nullable with a separate `commission_locked_at` timestamp, or (c) explicitly documenting that these handlers should silently skip INSERT and rely on the reconciler to backfill the order — with the trade-off that those orders are invisible until reconciliation runs.
    - **Plain English:** The plan has a detailed playbook for one specific "things arrive out of order" scenario (a refund notice beating the payment notice to the door), but no playbook for two others: an "order edited" notice or an "order cancelled" notice arriving before the payment notice. The database table insists on knowing the commission rate at insert time — but those two notice types don't carry commission information, and the plan doesn't say what placeholder to use. Without that, the code either crashes, silently discards the notice, or writes a semantically wrong zero value. The fix is to pick a clear placeholder ("commission not yet calculated") and document it.
    - **Evidence:**
        > **Race 1 — `orders/updated` before `orders/paid`** (or any order topic before order is known):
        > ```sql
        > INSERT INTO commerce.orders (...)
        > VALUES (...)
        > ON CONFLICT (shopify_shop_domain, shopify_order_id)
        > DO UPDATE SET ...
        > ```
        >
        > **Race 2 — `refunds/create` before the parent `orders/paid` is recorded** (gap noted in audit). The refund handler must:
        > 1. Look up the order. If absent: insert a stub `commerce.orders` row (`status='pending'`, totals from refund context, `shopify_updated_at = refund.created_at - 1ms`)…

        _(Race 1 says "any order topic" but supplies no stub semantics; Race 2 is explicit. The orders/edited handler is described only as "snapshot only, no commission change" with no treatment of first-seen behaviour.)_

        ```sql
        commission_cents bigint NOT NULL,                -- frozen at order-paid time (Decision #3)
        commission_rate numeric(7,4) NOT NULL,
        rate_source text NOT NULL,                       -- 'product_metafield' | 'brand_default' | 'platform_default' | 'manual'
        ```

        > `orders/edited` — line-item changes via the Order Editing API (snapshot-only update; commission frozen — Decision #3)

---

- [ ] **PLANV3-2** · P1 — `commerce.order_items.commission_cents` / `commission_rate` are NOT NULL with no stated data source; the trigger that populates this table cannot compute them
    - **Where:** docs/analytics-rebuild-plan.md — `commerce.order_items` schema section; "Separate `order_items` table" rationale bullet
    - **Affects:** Top-products and GMV-by-SKU analytics (the stated reason for the table) — will produce all-zero commission columns, making per-item commission reporting silently wrong from day one
    - **Effort:** M (~2–4h) to decide the strategy and document it; M (~2–4h) to implement
    - **What to do:**
        - Pick one of two approaches and add it explicitly to the plan:
            - **Option A (trigger proportional split):** The `AFTER INSERT OR UPDATE OF line_items` trigger computes per-item commission as `ROUND((NEW_item.line_total_cents::numeric / NULLIF(NEW_order.gross_cents, 0)) * NEW_order.commission_cents)` using the parent row's already-frozen `commission_cents`. This requires the trigger to JOIN back to the orders row (accessible as `NEW` in an orders trigger, or via a lookup in the order_items trigger). Handle zero-division when `gross_cents = 0` (stub orders): write `commission_cents = 0` and flag for recompute when the order is later paid.
            - **Option B (PHP pre-compute):** The webhook handler computes per-item commission before the upsert and serializes it into each element of the `line_items` JSONB. The trigger then reads it out. Requires documenting the JSONB schema for line items (i.e., adding a `commission_cents` and `commission_rate` field to the Shopify line item object before storage).
        - Add the chosen approach to Phase 1 trigger spec and Phase 3 smoke test for `order_items`.
        - Add a test asserting non-zero `commission_cents` on a happy-path order insert.
    - **Technical:** The `order_items` table is populated by a `AFTER INSERT OR UPDATE OF line_items` trigger on `commerce.orders`. The trigger reads the `line_items` JSONB column and reconciles rows. Shopify's `line_items` payload does not include commission information — commission is a Partna concept computed from product metafields, brand defaults, and platform defaults. A Postgres trigger operates in SQL and has no access to PHP business logic or Eloquent models. Therefore, a trigger operating on raw Shopify JSONB can extract quantity, price, sku, title, and discounts — but it cannot derive `commission_cents` or `commission_rate`. Without a defined source for these values, the trigger must either crash (NOT NULL violation) or insert 0, rendering the analytics columns misleading. Option A (proportional split at the trigger layer, using the frozen order-level `commission_cents` already on the parent row) is simpler and keeps business logic in one place; Option B requires an agreed JSONB schema extension.
    - **Plain English:** The plan creates a detail table — one row per product line on each order — and that table is supposed to record how much commission each product line earned. But the database rule says that column can never be blank. The automatic copy mechanism (a database trigger) can read the product name, price, and quantity from the order, but it has no way of knowing what commission rate applies to each product — that calculation depends on business rules that live in the app code, not the database. The plan needs to say: either the app pre-calculates commission per line and stores it before handing off to the database, or the database estimates it as a proportional split. Right now it says neither.
    - **Evidence:**
        > Normalized mirror of `line_items` JSONB, populated by `AFTER INSERT OR UPDATE OF line_items` trigger that diffs JSONB and reconciles rows. Used for top-products / GMV-by-SKU queries.

        ```sql
        commission_cents bigint NOT NULL,
        commission_rate numeric(7,4) NOT NULL,
        ```

        _(No source for these values is described anywhere in the plan — not in the trigger spec, not in the handler spec, not in Phase 3 smoke tests for `order_items`.)_

---

- [ ] **PLANV3-3** · P1 — `brand_affiliate_rollup` trigger function body never defined; `reversed_commission_cents` has no stated population logic; status-transition deltas are undefined
    - **Where:** docs/analytics-rebuild-plan.md — `commerce.brand_affiliate_rollup` schema section; Phase 1 trigger list; Phase 1 verification plan
    - **Affects:** All brand and affiliate dashboard reports that read from the rollup — commission totals, refund totals, order counts. Without a defined trigger, Phase 1 tests cannot be written, the implementation is undefined, and the rollup will be wrong from day one.
    - **Effort:** M (~2–4h) to write the trigger function spec in the plan; L (~1–2d) to implement, test all delta cases, and verify edge cases
    - **What to do:**
        - Write the complete trigger function body as part of the plan (or at minimum a pseudocode contract covering every case). Required cases:
            - **INSERT** (new order, any status): delta = `+1 orders_count`, `+gross_cents`, `+net_cents`, `+commission_cents`, `+0 reversed_commission_cents`.
            - **UPDATE — numeric fields change** (e.g., `refund_cents` increases after `refunds/create`): delta = `NEW.x - OLD.x` for each mutable column; `net_cents` delta = `(NEW.net_cents - OLD.net_cents)`.
            - **UPDATE — status changes to `cancelled` or `voided`** (order cancelled after INSERT counted it): delta = `−1 orders_count`, reverse all previously counted gross/net/commission; set `+reversed_commission_cents` by the full frozen `commission_cents` value.
            - **UPDATE — status changes to `refunded` or `partially_refunded`**: `+reversed_commission_cents` should increment by the proportional commission reversed: `ROUND((NEW.refund_cents - OLD.refund_cents)::numeric / NULLIF(NEW.gross_cents, 0) * NEW.commission_cents)`.
        - Define explicitly that `reversed_commission_cents` does NOT cover post-payout clawbacks (those are a separate gap — see PLANV3-4).
        - Add test cases to Phase 1 verification: cancel-after-approve produces `orders_count = 0`; partial refund produces non-zero `reversed_commission_cents`; duplicate trigger fire on idempotent update produces no delta.
    - **Technical:** The plan says the rollup is "Maintained by `AFTER INSERT OR UPDATE` trigger on `commerce.orders` using signed-delta `INSERT ... ON CONFLICT DO UPDATE`" — but this describes the *shape* of the trigger, not its *definition*. The verification plan lists expected outcomes ("insert order → +1 count; refund event → +refund_cents; reversal → +reversed_commission_cents") but "refund event" and "reversal" are ambiguous: are these status values, column changes, or separate webhook events? The `reversed_commission_cents` column exists in the schema DDL but has no logic attached to it anywhere in the plan. Without the trigger body, the Phase 1 migration cannot be completed, Phase 1 tests cannot be written, and the rollup is silently wrong from launch. This is the most structurally load-bearing undefined piece in the entire plan.
    - **Plain English:** The plan introduces a "live summary table" — a running total per brand + affiliate + day that updates automatically whenever an order changes. It says the table updates automatically and even lists what the correct end-state should be after different events. But it never writes down the actual rule the database should follow to produce those updates. It's like saying "this cell in the spreadsheet will always show the right total" without writing the formula. The rule needs to cover: adding a new order, updating an order (gross up or down), cancelling an order (subtract everything back out), and applying refunds (track how much commission has been reversed). Without that formula, the summary table does nothing.
    - **Evidence:**
        > The only aggregate table we keep. Maintained by `AFTER INSERT OR UPDATE` trigger on `commerce.orders` using signed-delta `INSERT ... ON CONFLICT DO UPDATE`. **Day key is UTC** — brand-local timezone display happens in the read controller, not the storage.

        ```sql
        reversed_commission_cents bigint NOT NULL DEFAULT 0,
        ```

        > `brand_affiliate_rollup` trigger correctly applies signed deltas (insert order → +1 count; refund event → +refund_cents; reversal → +reversed_commission_cents).

        _(No trigger function body, no delta arithmetic, no status-transition semantics, no definition of "refund event" vs "reversal" in this context, and no explanation of how `reversed_commission_cents` is computed.)_

---

## P2 — Should fix

- [ ] **PLANV3-5** · P2 — `order_events.metadata` GDPR redaction paths are not enumerated; free-text PII in event metadata survives redaction
    - **Where:** docs/analytics-rebuild-plan.md — "GDPR redaction (audit-derived)" section
    - **Affects:** GDPR / data-subject deletion compliance; customer PII in refund notes, adjustment reasons, and other free-text event fields is never wiped
    - **Effort:** S (~1h) to audit all event sources that write to `metadata` and enumerate paths; S (~1h) to implement the closed list in `jsonb_strip_pii`
    - **What to do:**
        - For `commerce.orders.shopify_data`, the plan lists specific paths: `customer.email`, `customer.first_name`, `customer.last_name`, `billing_address.*`, `shipping_address.*`, `customer.phone`. Extend this list with Shopify fields that can carry customer-authored text: `note` (customer gift/order note), `note_attributes[*].value` (custom checkout form fields), and line item `properties[*].value` (product customization fields).
        - For `commerce.order_events.metadata`, enumerate all paths that any event writer (webhook handlers, reconciler, manual adjuster) will populate. At minimum: `refund.note` (the customer-visible refund reason), `adjustment.note`, `customer.name` if denormalized for display.
        - Add a test that inserts a synthetic event with PII in both standard and free-text fields, runs the redaction job, and asserts zero identifiable data remains in either JSONB column.
    - **Technical:** The plan's `shopify_data` redaction lists six explicit paths. For `order_events.metadata`, it only says `metadata = jsonb_strip_pii(metadata)` with no path list — `jsonb_strip_pii` "takes a JSONB and a list of paths to NULL out" but that list is never provided for metadata. Shopify's `refunds/create` webhook includes a `note` field on the refund object (the reason given for the refund, which staff may type customer names or emails into). If the webhook handler stores this in `metadata`, it survives any redaction that doesn't name that path. Because `order_events` is an immutable audit log kept indefinitely, PII that leaks in here cannot be removed without a table scan.
    - **Plain English:** The plan says it will erase personal information when a customer requests deletion, and it even lists the specific fields it will wipe in the main order record. But for a second table — the event history log — it just says "run the eraser" without saying which fields the eraser should touch. A refund reason like "customer Jane Smith returned defective item" would survive the erasure because the system doesn't know to look in the "reason" field. Since the event log is kept forever as an audit trail, this is a permanent leak.
    - **Evidence:**
        > 2. `shopify_data = jsonb_strip_pii(shopify_data)` — strip known PII paths (`customer.email`, `customer.first_name`, `customer.last_name`, `billing_address.*`, `shipping_address.*`, `customer.phone`).
        > 3. `metadata = jsonb_strip_pii(metadata)` on `commerce.order_events`.
        >
        > `jsonb_strip_pii` is a new SQL function that takes a JSONB and a list of paths to NULL out.

        _(Paths are enumerated for `shopify_data`. No paths are enumerated for `metadata`. The function signature requires an explicit path list.)_

---

- [ ] **PLANV3-4** · P2 — Post-payout clawbacks recorded in `commission_movements` never reach the rollup trigger; `reversed_commission_cents` in the rollup diverges from reality after payouts
    - **Where:** docs/analytics-rebuild-plan.md — `commerce.commission_movements` scope section; rollup trigger description; architecture diagram
    - **Affects:** Affiliate and brand dashboard commission totals after any post-payout refund — dashboards will overstate net commission earned; payout reconciliation queries reading from the rollup will be wrong
    - **Effort:** M (~2–4h) to design the sync mechanism; M (~2–4h) to implement (trigger on `commission_movements` or scheduled job)
    - **What to do:**
        - Decide whether post-payout clawbacks should: (a) fire an UPDATE to the originating `commerce.orders` row (incrementing `refund_cents`, changing status), which naturally cascades to the rollup trigger, or (b) be handled by a separate trigger on `commission_movements INSERT` for `entry_type='clawback'` that applies a direct signed delta to the rollup.
        - Option (a) is simpler — clawback creation also updates the order row — and keeps the rollup trigger as the single source of truth. Document this as the required sequence in the clawback-creation service.
        - If option (b), add a `AFTER INSERT` trigger on `commission_movements` that, for `entry_type='clawback'`, applies `−clawback_amount` to the appropriate rollup row's `reversed_commission_cents`.
        - Either way, add a test: create an order, payout commission, then create a clawback; assert rollup `commission_cents - reversed_commission_cents` equals the expected net after clawback.
    - **Technical:** The plan explicitly narrows `commission_movements` to money-movement rows only (payouts, clawbacks, adjustments) and keeps the rollup trigger exclusively on `commerce.orders`. A clawback entry written to `commission_movements` does not modify any `orders` row (commission_cents is frozen at order-paid time per Decision #3). Therefore, the rollup trigger never fires, `reversed_commission_cents` in the rollup is never updated, and `SUM(commission_cents - reversed_commission_cents)` in the per-affiliate breakdown query overstates net commission by the clawback amount indefinitely. As clawbacks accumulate, the dashboard diverges from the actual settlement ledger — a reconciliation failure invisible to the affiliate until a manual audit.
    - **Plain English:** When a customer returns a product after the affiliate has already been paid, the system records a "clawback" — essentially taking back part of the commission. But the live dashboard summary table only listens for changes to orders, not for clawbacks. Clawbacks live in a separate accounting ledger. So the dashboard never hears about them and keeps showing the pre-clawback number. Over time, affiliates' dashboards show them as having earned more than they were actually paid, which erodes trust and creates reconciliation headaches.
    - **Evidence:**
        > `entry_type='clawback'` — written when commission is reversed after payout (post-payout refunds)

        > The only aggregate table we keep. Maintained by `AFTER INSERT OR UPDATE` trigger on `commerce.orders` using signed-delta `INSERT ... ON CONFLICT DO UPDATE`.

        _(No trigger or job is described that links a `commission_movements` clawback INSERT to a rollup update. The architecture diagram shows no arrow from `commission_movements` to `brand_affiliate_rollup`.)_

---

- [ ] **PLANV3-7** · P2 — Phase 4 migration executes `DELETE … accrual/reversal` before `DROP COLUMN commission_movement_id`; the FK constraint on `commission_payout_items` will block the delete
    - **Where:** docs/analytics-rebuild-plan.md — "Phase 4 — Drop Old Aggregate Tables" migration steps
    - **Affects:** Phase 4 migration will hard-fail at the DELETE step; the column and FK cannot be dropped after a failed transaction, requiring manual intervention to recover
    - **Effort:** S (~30m) to reorder the two lines in the plan
    - **What to do:**
        - Reverse the order of steps in Phase 4: `DROP COLUMN commission_movement_id` (which cascades to drop the FK constraint) must execute **before** `DELETE FROM commerce.commission_movements WHERE entry_type IN ('accrual','reversal')`.
        - Alternatively, add an explicit `ALTER TABLE commerce.commission_payout_items DROP CONSTRAINT <fk_name>` step before the DELETE.
        - Wrap the Phase 4 migration in a single transaction so any ordering error fails atomically rather than leaving tables in a partial state.
        - Add a Phase 4 pre-flight note: "Verify no payout_items.commission_movement_id references accrual/reversal rows before executing — run: `SELECT count(*) FROM commission_payout_items cpi JOIN commission_movements cm ON cm.id = cpi.commission_movement_id WHERE cm.entry_type IN ('accrual','reversal');`"
    - **Technical:** `commission_payout_items.commission_movement_id` is a FK referencing `commission_movements(id)`. The Phase 2 backfill explicitly joins through this FK: `WHERE cpi.commission_movement_id = cm.id` — meaning at least some payout items reference commission_movements rows. In the current system, payout items link to the accrual entries for the orders they paid out (one accrual per line item per order). Phase 4 wants to DELETE all accrual and reversal rows, but cannot do so while any payout item's FK points at one. Phase 4 then drops the `commission_movement_id` column — but that DROP requires the FK constraint to either be dropped first or the column dropped with CASCADE. Since the migration runs the DELETE before the DROP COLUMN, Postgres will raise a foreign key violation and abort. Phase 4 is a straightforward drop-and-clean step but this sequencing error will cause it to fail in production.
    - **Plain English:** The plan's cleanup step lists four actions in the wrong order. It tells the database to delete certain records first, then remove the column that points to those records. But the database has a rule: you can't delete records that other records are pointing at. The result is the cleanup step crashes on its first action, requiring a developer to log into production and manually untangle the dependency. The fix is two seconds: swap the order of two lines in the plan so the pointer is removed before the records it pointed to are deleted.
    - **Evidence:**
        > Phase 4 — Drop Old Aggregate Tables (1 day, after Phase 3 in production for 24h)
        > Migration:
        > - `DELETE FROM commerce.commission_movements WHERE entry_type IN ('accrual','reversal');` (test data, no longer needed)
        > - `ALTER TABLE commerce.commission_payout_items ALTER COLUMN order_id SET NOT NULL;`
        > - `ALTER TABLE commerce.commission_payout_items DROP COLUMN commission_movement_id;`

        _(The FK from `commission_payout_items.commission_movement_id` to `commission_movements(id)` is implicitly confirmed by the Phase 2 backfill: `WHERE cpi.commission_movement_id = cm.id`. Dropping the referencing column must precede deleting the referenced rows.)_

---

- [ ] **PLANV3-6** · P2 — Backfill uses `lazyById(1000)` (primary-key order over UUID) while the plan's own BRIN caveat requires insertion in `occurred_at` order; the two contradict each other
    - **Where:** docs/analytics-rebuild-plan.md — "BRIN caveat" under `commerce.orders` schema; Phase 2 backfill description; Risks table row "BRIN index degrades after backfill"
    - **Affects:** Long-term query performance on `idx_orders_occurred_brin` — the index degrades silently post-backfill, causing BRIN range scans to miss or scan excessive pages; the mitigation the plan relies on ("backfill writes in `occurred_at` order") is directly contradicted by the implementation
    - **Effort:** S (~1–2h) to fix the backfill command to `ORDER BY occurred_at` and verify insertion order in the test
    - **What to do:**
        - Change the backfill command to iterate `commerce.commission_movements` ordered by `occurred_at` (or by `shopify_order_created_at` if available), not by primary key ID. Replace `lazyById(1000)` with `.orderBy('occurred_at')->chunk(1000, ...)` or equivalent cursor strategy that preserves temporal order.
        - Acknowledge the trade-off: `lazyById` is resume-safe (stable cursor); ordering by `occurred_at` requires either (a) a timestamp-based resume cursor stored in a state file, or (b) accepting that a re-run starts over. For pre-beta test data this is fine.
        - Add a post-backfill verification step: query `pg_stats` for the BRIN index's correlation value; if below 0.9, run `REINDEX INDEX CONCURRENTLY idx_orders_occurred_brin` before Phase 3.
        - Update the Risks table mitigation entry to reference the fixed backfill order.
    - **Technical:** Postgres BRIN effectiveness depends entirely on physical row order correlating with the indexed column. The plan explicitly states "Backfill must insert in `occurred_at` order" and lists BRIN degradation as a risk with "Backfill writes in `occurred_at` order" as the mitigation. But the Phase 2 backfill uses `lazyById(1000)` over `commission_movements`, which chunks by primary key UUID. UUIDs from `gen_random_uuid()` have zero temporal correlation. The resulting heap file will have rows physically ordered by UUID chunk sequence, not by `occurred_at`, making the BRIN index nearly useless. The index will exist and pass CI, but range scans using it will perform at or near sequential scan cost. Because `idx_orders_occurred_brin` is cited as the mechanism for efficient historical range queries (the read path "Brand totals" query uses `occurred_at BETWEEN $2 AND $3`), silent BRIN degradation means those queries degrade to full table scans as order volume grows.
    - **Plain English:** The plan correctly identifies that a certain type of database index (BRIN) only works if records are written to disk in date order — like a physical filing cabinet where the January folders are at the front and the December folders are at the back. Then it says the fill-from-backup process will work through files in alphabetical order (by record ID), not in date order. Those two statements directly contradict each other, and the "alphabetical order" process wins in practice. The index won't break — it just won't help. Queries that should take milliseconds will take seconds as the order table grows.
    - **Evidence:**
        > **BRIN caveat:** Postgres docs are explicit — BRIN only works when physical row order correlates with the indexed value. Backfill must insert in `occurred_at` order. Do NOT `CLUSTER` the table on a different column.

        > `lazyById(1000)` over `commerce.commission_movements` (already-renamed by Phase 1) grouped by `shopify_order_id`.

        > | BRIN index degrades after backfill | Backfill writes in `occurred_at` order. Monitor `pg_stat_user_indexes` for BRIN; if scan ratio drops, run `REINDEX INDEX CONCURRENTLY`. |

        _(The risk mitigation row asserts "Backfill writes in `occurred_at` order" as a fact, but the Phase 2 implementation uses `lazyById` which orders by UUID primary key — no temporal correlation.)_

---

- [ ] **PLANV3-8** · P2 — Race 2 stubs use `status='pending'`; the canonical live brand-totals query excludes only `cancelled / voided / refunded`, so stubs inflate order counts and zero out commission during the stub window
    - **Where:** docs/analytics-rebuild-plan.md — Race 2 stub-creation logic; "Live queries, no aggregate-table joins" read-path examples
    - **Affects:** Brand dashboard totals and order counts during any window where a refund stub exists but `orders/paid` has not yet overwritten it — shows 1 extra order, correct gross (if from refund payload), but zero commission, understating commission on the live dashboard
    - **Effort:** S (~1–2h) to update example queries and document stub exclusion policy
    - **What to do:**
        - Add `AND status != 'pending'` (or equivalently `AND status IN ('approved','partially_refunded','refunded','cancelled','voided')`) to all live commerce queries that report totals intended to reflect real orders. Alternatively, rename the stub status to something unambiguous (`'stub'`) and add it to the schema CHECK constraint so it cannot be confused with a real pending payment.
        - Decide explicitly whether `pending` status is ever intended to appear in brand-facing dashboards or only in internal/staff views. Document this in the plan.
        - Note that the rollup trigger also fires when a stub is INSERT-ed (adding 1 to `orders_count` with zero commission), which means the per-affiliate breakdown from the rollup also transiently shows a wrong count. The trigger should skip rollup writes for `status='pending'` stubs, or the read path should guard against them.
        - Add a Phase 3 smoke test: create a stub via simulated Race 2, immediately query brand totals, assert stub does not appear in commission sum.
    - **Technical:** When `refunds/create` arrives before `orders/paid`, Race 2 creates a stub row with `status='pending'`, `commission_cents = 0`, `commission_rate = 0`. The brand-totals example query filters `status NOT IN ('cancelled','voided','refunded')` — `pending` is not excluded, so the stub appears in `COUNT(*)` and `SUM(commission_cents)` (contributing 0). This means for the duration between the refund webhook and the paid webhook (typically sub-second in practice but potentially hours in degraded Shopify delivery), the dashboard shows: `orders_count` inflated by 1, `commission_cents` deflated by the stub's commission amount. The rollup trigger compounds this: on stub INSERT, it increments `orders_count` in the rollup by 1 with 0 commission; when `orders/paid` UPDATEs the stub, the signed delta adds the correct commission_cents (NEW − OLD = correct − 0 = correct). So the rollup self-corrects, but the `orders_count` is 1 ahead of reality if the stub's initial status had already been counted. Both paths need the stub to be explicitly excluded or clearly semantically distinct.
    - **Plain English:** When a refund notice beats the payment notice to the server, the system creates a placeholder record — an "I know this order exists but I don't have the full details yet" entry. That placeholder record currently has a status that is not on the exclusion list for the live dashboard, so it shows up in order counts and drags the average commission to zero until the payment notice arrives and fills in the real numbers. For most orders this is a fraction of a second. But during that window — or for orders where the payment notice never arrives — the dashboard shows a ghost order. The fix is to explicitly exclude placeholder records from customer-facing totals, or give them a different label that the dashboard filter already knows to ignore.
    - **Evidence:**
        > **Race 2 — `refunds/create` before the parent `orders/paid` is recorded** … insert a stub `commerce.orders` row (`status='pending'`, totals from refund context, `shopify_updated_at = refund.created_at - 1ms`)

        ```sql
        -- Brand totals (current month)
        SELECT
            COUNT(*)                           AS orders_count,
            COALESCE(SUM(gross_cents), 0)      AS gross_cents,
            COALESCE(SUM(net_cents), 0)        AS net_cents,
            COALESCE(SUM(commission_cents), 0) AS commission_cents
        FROM commerce.orders
        WHERE brand_professional_id = $1
          AND status NOT IN ('cancelled','voided','refunded')
          AND occurred_at BETWEEN $2 AND $3;
        ```

        _(The `status='pending'` stub is not in the exclusion set. Schema CHECK constraint confirms `'pending'` is a valid status value alongside `'approved'`, `'cancelled'`, etc.)_
