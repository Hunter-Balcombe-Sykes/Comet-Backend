`★ Insight ─────────────────────────────────────`
Three patterns shaped this adjudication: (1) DeepSeek consistently over-tiers "no safety guard" findings when every actual call site is already correct — `whenLoaded` guards belong at P3, not P1. (2) The migration pattern (indexes wrapped in `BEGIN`/`COMMIT` blocks can't use `CONCURRENTLY`) is a systemic future-launch risk that DeepSeek missed entirely. (3) A method with zero live call sites fails the verification test and should be dropped regardless of confidence score.
`─────────────────────────────────────────────────`

# Database & Queue Scaling Audit — 2026-05-11

**Branch:** development
**Lens:** Database & queue scaling: N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Models/** (all models provided)
- app/Http/Resources/** (all resources provided)
- app/Jobs/** (key jobs read for queue-shape verification)
- routes/console.php
- supabase/migrations/202605*.sql (recent migrations read)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **SCALE-1** · P2 — Index creation inside transaction blocks on commission_payouts will take exclusive locks at deploy time
    - **Where:** supabase/migrations/20260510000000_add_commission_payouts_lifecycle_columns.sql:28–37; supabase/migrations/20260511000000_add_commission_payouts_grace_started_at.sql:29–31
    - **Affects:** All Stripe payout writes during future migration deploys — every `INSERT` and `UPDATE` on `commerce.commission_payouts` blocks for the duration of each index build once the table has real rows. At 10K daily payout jobs this table will be permanently hot.
    - **Effort:** S (~0.5–1h per future migration — adopt a convention, not a backfill)
    - **What to do:**
        - Adopt a two-file convention for future index additions to hot tables (`commission_payouts`, `commission_movements`, `orders`, `order_events`): one migration file for the schema change (inside `BEGIN`/`COMMIT`), a second file for index creation **outside** any transaction block so `CREATE INDEX CONCURRENTLY` is permitted.
        - For new CHECK constraints on live tables, use `ADD CONSTRAINT … NOT VALID` in the transaction, then a separate `VALIDATE CONSTRAINT` step (Postgres validates with a weaker lock).
        - Inline `UPDATE` backfills inside migration transactions should be extracted to a separate one-shot job dispatched after the schema migration lands, so a large table doesn't hold the transaction lock while rows are being written.
        - Document the pattern in a `supabase/migrations/CONVENTIONS.md` so every future migration author knows the rule.
    - **Technical:** PostgreSQL's `CREATE INDEX` (non-concurrent) acquires `ShareLock` on the table, blocking all concurrent writes for its entire duration. `CONCURRENTLY` avoids this by building the index in background passes, but it **cannot be used inside a transaction block** — and all Partna migrations wrap their DDL in `BEGIN`/`COMMIT`. Migrations `20260510000000` (3 indexes + 2 CHECK constraints + 1 inline backfill) and `20260511000000` (1 index + 1 inline backfill) all follow this pattern against `commerce.commission_payouts`. These were safe to deploy pre-launch on an empty table, but the pattern will cause write outages on the payout pipeline once real data exists. The one migration that already uses `CONCURRENTLY` correctly (`20260424120000_add_live_check_index.sql`) is the model to follow — it runs the `CREATE INDEX CONCURRENTLY` outside a transaction block.
    - **Plain English:** Imagine you're reorganising a busy restaurant's seating chart. Doing it the current way is like locking the front door — no new customers can enter — while you rearrange every table. There's a way to do it while the restaurant stays open (creep in the changes between seatings), but it only works if you're not holding the door locked at the same time. Right now, every database reorganisation locks the door. This is fine today when the restaurant is empty; once it's full, every deployment blocks real payouts for however long the reorganisation takes.
    - **Evidence:**
        ```sql
        BEGIN;
        -- ...
        CREATE INDEX IF NOT EXISTS idx_cp_completed_status
            ON commerce.commission_payouts (id) WHERE status = 'completed';

        CREATE INDEX IF NOT EXISTS idx_cp_pending_funds_next_retry
            ON commerce.commission_payouts (next_retry_at)
            WHERE status = 'pending_funds';

        CREATE INDEX IF NOT EXISTS idx_cp_transferring_updated_at
            ON commerce.commission_payouts (updated_at)
            WHERE status = 'transferring';
        -- ...
        COMMIT;
        ```

---

## P3 — Nice to have

- [ ] **SCALE-2** · P3 — SubscriptionResource accesses `plan` relation directly with no `whenLoaded` safety net
    - **Where:** app/Http/Resources/SubscriptionResource.php:17–25
    - **Affects:** Any future endpoint that wraps a `Subscription` in `SubscriptionResource` without first eager-loading `plan` — silent N+1, one extra query per subscription row in that response.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the inline `'plan' => [...]` block with `$this->whenLoaded('plan', fn () => [...])` so the key is omitted (rather than triggering a lazy load) when the caller forgets `->with('plan')`.
        - Verify all existing call sites continue to include `->with('plan')` or `->load('plan')` (all four current call sites in `SubscriptionController` and `StaffSubscriptionManagementController` already do this — the guard is defence-in-depth for future callers, not a fix for an active bug).
    - **Technical:** Every current call site of `SubscriptionResource` correctly eager-loads the `plan` relation: `SubscriptionController::show()` uses `->with('plan')` at query time; all mutation actions use `$result->load('plan')` before construction. The resource itself, however, directly dereferences `$this->plan->id` through seven property accesses. Without `whenLoaded`, a future endpoint that adds `SubscriptionResource::collection($subs)` without the eager load triggers seven lazy queries per subscription. The `whenLoaded` pattern — standard in every other resource that handles relations (`AffiliatePayoutResource`, `BrandPayoutResource`, `ProfessionalDashboardResource`) — is the canonical Laravel defence against this failure mode. Adding it here aligns the resource with the project convention.
    - **Plain English:** Think of `whenLoaded` as a checklist item on the resource's door: "only fill in the plan section if someone already handed you the plan details." Right now the door has no checklist — it always expects the plan details to be there. Every developer who uses this resource today remembered to bring the details, but there's nothing stopping a future developer from forgetting. Adding the checklist costs nothing and makes the failure obvious instead of slow.
    - **Evidence:**
        ```php
        'plan' => [
            'id' => $this->plan->id,
            'plan_key' => $this->plan->plan_key,
            'name' => $this->plan->name,
            'price_cents' => $this->plan->price_cents,
            'currency_code' => $this->plan->currency_code,
            'billing_interval' => $this->plan->billing_interval,
            'entitlements' => $this->plan->entitlements,
        ],
        ```

- [ ] **SCALE-3** · P3 — SiteMedia::variantUrls() silently triggers lazy load when `mediaVariants` is not pre-loaded
    - **Where:** app/Models/Core/Site/SiteMedia.php:122–127
    - **Affects:** Any future code path that iterates SiteMedia items and calls `variantUrls()` without first eager-loading `mediaVariants` — silent per-item query per gallery item.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `relationLoaded` guard at the top of `variantUrls()`:
            ```php
            if (! $this->relationLoaded('mediaVariants')) {
                return [];
            }
            ```
        - Update the docblock to explicitly state the eager-load contract: "Caller must eager-load `mediaVariants` before calling this method."
        - All current callers already honour the contract (verified: `BrandGalleryController`, `ProfessionalGalleryController`, `HydrogenAffiliateProductsController`, `HydrogenBrandConfigController`, `HydrogenAffiliateController`, `HydrogenBrandDesignController`, `BrandDesignMediaService`, `AffiliateProductPhotoController`, and `ProfessionalUploadController` all use `->with('mediaVariants')` or `->load('mediaVariants')` before calling `variantUrls()`). The guard is defence-in-depth for future callers.
    - **Technical:** `$this->mediaVariants` on an Eloquent model without an eager-loaded relation triggers a `SELECT * FROM site.media_variants WHERE media_id = ?` per invocation. At 200 brands × ~50 gallery items each, a single list endpoint that forgets `with('mediaVariants')` would generate up to 10 000 lazy-load queries. The method's docblock already signals the contract ("from the already-loaded mediaVariants relation") but the code doesn't enforce it. A `relationLoaded` guard makes the contract machine-checkable: the caller gets an empty array instead of a slow response, surfaces the bug immediately in testing, and matches how every other method in the codebase that depends on a relation contract behaves.
    - **Plain English:** The method has a hidden rule: "you must grab all the photo prints before calling this." Every developer who uses it today remembered the rule. But the rule is only written in a comment — if someone forgets it, the code works silently and slowly instead of failing loudly. Adding a check that returns "no prints available" when the prints weren't fetched upfront makes forgetting the rule immediately obvious during testing rather than quietly slow in production.
    - **Evidence:**
        ```php
        public function variantUrls(): array
        {
            return $this->mediaVariants
                ->filter(fn (MediaVariant $v) => $v->artifact_type === 'webp')
                ->mapWithKeys(fn (MediaVariant $v) => [$v->variant_key => $v->url])
                ->all();
        }
        ```
