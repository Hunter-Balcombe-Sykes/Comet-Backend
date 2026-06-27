Good — all the evidence I need is confirmed. Key corrections from tool verification:
- **SCALE-6 DROPPED**: `ExecuteCommissionPayoutJob` constructor calls `$this->onQueue('stripe')` at line 44 — queue routing is correctly set.
- **SCHEMA-1 DROPPED**: `payout_method` already has `professionals_payout_method_check CHECK (payout_method IN ('card', 'becs'))` from migration `20260513600000_add_payout_method_to_professionals.sql`.
- **LIFE-2 CONFIRMED**: Routes `/stripe/balance` (line 426) and `/stripe/payouts/upcoming` (line 423) sit outside the `affiliate.only` middleware group (which starts at line 439).

---

# Stripe Connect + Billing Full Audit — 2026-05-15

**Branch:** development
**Lens:** Full audit across 5 focused themes: security/policy (SEC-*), lifecycle correctness (LIFE-*), scaling antipatterns / read-side caching (CACHE-*), database/queue scaling — N+1/throughput (SCALE-*), and schema/RLS correctness (SCHEMA-*)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php`
- `app/Services/Stripe/CommissionPayoutRefundService.php`
- `app/Services/Stripe/CommissionPayoutService.php`
- `app/Services/Stripe/ExportService.php`
- `app/Services/Stripe/StripeBillingService.php`
- `app/Services/Stripe/StripeTransactionFetcher.php`
- `app/Http/Requests/Stripe/OnboardRequest.php`
- `app/Http/Requests/Stripe/CreatePaymentMethodSetupRequest.php`
- `app/Policies/CommissionPolicy.php`
- `routes/api/professional.php`
- `supabase/migrations/20260515000000_brand_dual_payment_methods.sql`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 9 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#SCALE-1** · P1 — `DB::transaction` holds a row lock across a live Stripe API call
    - **Where:** `app/Services/Stripe/CommissionPayoutRefundService.php:72` (`handleOrderRefund`) / `app/Services/Stripe/CommissionPayoutRefundService.php:252` (`clawbackCompletedPayout`)
    - **Affects:** Every completed-payout refund path; under any load where two refunds process concurrently, database connection pool exhaustion + 30s+ transaction timeouts are possible.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Move the Stripe API call (`$this->stripe->refunds->create(...)`) entirely outside the `DB::transaction` closure.
        - Restructure `handleOrderRefund` as: (1) read + lock payout row, compute refund amount, **exit transaction**; (2) call Stripe; (3) open a second short transaction to write the `CommissionClawback` row and flag `needs_manual_refund` on Stripe failure.
        - Use the existing idempotency key (`$idempotencyKey`) to make the Stripe call safely retryable between the two DB transactions.
        - The inner `commitClawbackRow` transaction should be a narrow write-only unit: insert `CommissionClawback`, save `payout.needs_manual_refund`, log — nothing else.
    - **Technical:** `DB::transaction` acquires a `FOR UPDATE` row lock on `CommissionPayout` at line 75. That lock is held until the transaction commits, which only happens after `clawbackCompletedPayout` returns — and that method makes a synchronous HTTPS call to Stripe's refunds API (line 252). Under normal latency (~300ms) this is tolerable, but under any Stripe degradation event or network jitter the lock escalates to seconds. Postgres has a default `lock_timeout = 0` (no timeout), so any other transaction needing the same row — a concurrent webhook, a retry, a status check — blocks indefinitely. With 200 brands and Shopify's at-least-once webhook delivery, two `refunds/create` webhooks for the same order arriving within ~300ms is a documented scenario. At pilot scale this is a latent bomb, not a theoretical one.
    - **Plain English:** Imagine taking out a library book and refusing to let anyone else check anything out of the whole building until you've finished reading it at a coffee shop across town. The `DB::transaction` here does exactly that — it holds a database lock (the "building") open while waiting for Stripe to respond (the "coffee shop"). If Stripe is slow or Shopify sends the same webhook twice simultaneously, two requests pile up behind that lock, and the whole queue jams. The fix is simple in concept: do the paperwork (database locking and reading), walk out of the building, call Stripe, then come back for a quick second checkout to record what happened.
    - **Evidence:**
        ```php
        DB::transaction(function () use ($order, $incrementalRefundCents, $shopifyRefundId): void {
            $payout = CommissionPayout::query()
                ->where('id', $order->payout_id)
                ->lockForUpdate()   // <-- row lock acquired here
                ->first();
            // ...
            if ($payout->status === 'completed') {
                $this->clawbackCompletedPayout($payout, $order, $incrementalRefundCents, $shopifyRefundId);
                return;
            }
            // ...
        });

        // inside clawbackCompletedPayout() — still inside the transaction:
        $refund = $this->stripe->refunds->create([   // <-- external HTTP while lock held
            'payment_intent' => $payout->payment_intent_id,
            'amount' => $refundCents,
            'refund_application_fee' => true,
            'reverse_transfer' => true,
            // ...
        ], [
            'idempotency_key' => $idempotencyKey,
        ]);
        ```

---

## P2 — Should fix

- [ ] **#SCALE-2** · P2 — Export materializes entire payout ID list into PHP memory
    - **Where:** `app/Services/Stripe/ExportService.php:105` (`exportDetailedCommissions`)
    - **Affects:** EOFY exports for brands with large payout histories; PHP OOM on the request thread at scale.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$payoutsQuery->pluck('id')->all()` with a subquery: `Order::query()->whereIn('payout_id', $payoutsQuery->select('id'))->...->cursor()`.
        - This lets the DB join inline rather than pulling all IDs to PHP and back.
    - **Technical:** `pluck('id')->all()` on `$payoutsQuery` (a `CommissionPayout::query()`) executes a full `SELECT id FROM commerce.commission_payouts WHERE ...` and returns the result set as a PHP array. That array is then passed to `->whereIn('payout_id', $payoutIds)` on the Orders query. A brand with five years of history could have tens of thousands of payout IDs. The fix is a subquery: `Order::query()->whereIn('payout_id', $payoutsQuery->select('id'))` — Eloquent/PDO keeps this entirely on the DB side.
    - **Plain English:** The current code asks the database "give me all your payout IDs" and loads them into memory like a shopping list, then hands that list back to the database to use as a filter. It's like taking every item out of a filing cabinet to find the ones with red tabs, when you could just ask the cabinet to show you the red-tab items directly.
    - **Evidence:**
        ```php
        $payoutsQuery = $this->scopedPayouts($pro, $role, $filters);
        $payoutIds = $payoutsQuery->pluck('id')->all();
        // ...
        $orders = Order::query()
            ->with([...])
            ->whereIn('payout_id', $payoutIds)
            ->orderBy('occurred_at')
            ->cursor();
        ```

- [ ] **#SCALE-3** · P2 — XLSX export loads entire file into PHP memory via `file_get_contents`
    - **Where:** `app/Services/Stripe/ExportService.php:261` (`streamXlsx`)
    - **Affects:** Large XLSX exports (EOFY, detailed-commissions) — PHP memory spike proportional to file size; not streaming despite the streaming writer.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `file_get_contents($tmp)` + `response($contents)` with `response()->download($tmp)->deleteFileAfterSend(true)`.
        - This streams the temp file to the client via PHP's output buffer without loading it entirely into memory.
        - Alternatively, switch to `response()->file($tmp, [...headers...])` and delete the temp file in a `register_shutdown_function`.
    - **Technical:** openspout writes to `$tmp` using a streaming writer specifically to avoid memory accumulation. The final `file_get_contents($tmp)` immediately negates that benefit by loading the entire XLSX binary into a PHP string. For an export with 50k rows at ~200 bytes/row the XLSX is ~10MB — manageable today but a liability as the platform scales. The comment in the code already acknowledges this ("large exports should go through ExecuteExportJob"), but the in-process path should at least not buffer needlessly.
    - **Plain English:** The code carefully packs a box one item at a time to avoid overloading the truck, then picks the whole box up and carries it in one arm anyway before putting it on the truck. Using `response()->download($tmp)` lets the truck back up to the door and load directly.
    - **Evidence:**
        ```php
        $writer->close();

        $contents = file_get_contents($tmp) ?: '';
        @unlink($tmp);

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
        ```

- [ ] **#LIFE-1** · P2 — `payoutDetail` uses inline ownership check instead of `CommissionPolicy`
    - **Where:** `app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php:421–428` (`payoutDetail`)
    - **Affects:** Authorization pattern consistency; a future Policy change (e.g., staff override, admin view) won't propagate here.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `$isBrand`, `$ownsAsBrand`, `$ownsAsAffiliate` variables and the manual `if (!$payout || ...)` guard.
        - Replace with `Gate::forUser($pro)->authorize('view', $payout)` after the `find()` call — `CommissionPolicy::view()` already handles 404 for missing/cross-tenant payouts via `denyAsNotFound()`.
        - Handle the `$payout === null` case first (return 404 before the Gate call) or rely on the Policy's null guard.
    - **Technical:** `CommissionPolicy::view()` already implements the exact ownership logic — brand/affiliate cross-check, `denyAsNotFound()` for anything that isn't owned by the caller — but `payoutDetail()` duplicates it inline. The duplication means if the ownership rule ever changes (e.g., staff accounts get read access, or the brand can delegate), the Policy gets the update and this controller silently drifts. Per CLAUDE.md doctrine: "Authorization through Policies, never inline."
    - **Plain English:** The company has a formal security policy written down, but this one door has a handwritten note stuck to it instead of following the policy. Both say the same thing today, but if the policy gets updated, someone has to remember to update the sticky note separately.
    - **Evidence:**
        ```php
        $isBrand = ($pro->professional_type ?? null) === 'brand';
        $ownsAsBrand = $payout && $isBrand
            && (string) $payout->brand_professional_id === (string) $pro->id;
        $ownsAsAffiliate = $payout && ! $isBrand
            && (string) $payout->affiliate_professional_id === (string) $pro->id;

        if (! $payout || (! $ownsAsBrand && ! $ownsAsAffiliate)) {
            return response()->json(['error' => 'not_found'], 404);
        }
        ```

- [ ] **#LIFE-2** · P2 — `balance` and `upcomingPayouts` use inline role guards instead of `affiliate.only` middleware
    - **Where:** `app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php:548–550` (`balance`) and `573–575` (`upcomingPayouts`); `routes/api/professional.php:423,426`
    - **Affects:** Authorization pattern consistency; these routes are reachable by brands and return a 403 from the controller rather than being gated at the router.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `routes/api/professional.php`, move the `/stripe/balance` and `/stripe/payouts/upcoming` routes inside the existing `Route::middleware(['affiliate.only'])->group(...)` block (currently at line 439) — or create a dedicated group for Stripe affiliate-only routes adjacent to it.
        - Remove the inline `if (($pro->professional_type ?? null) === 'brand')` checks from both controller methods.
    - **Technical:** Per CLAUDE.md: "Brand-only routes use `brand.only` middleware, not inline `professional_type` checks. Affiliate-only routes use `affiliate.only`." The inline guard works correctly today but bypasses the canonical enforcement layer. The `affiliate.only` middleware is already defined and used at line 439 of the routes file for product selection routes. A middleware gate also gives the correct 403 response before the controller even runs, which is the right shape per the 403-vs-404 standard.
    - **Plain English:** The building has a keycard reader at the front door for staff-only areas, but these two rooms have a bouncer inside the room instead of at the door. The bouncer does the same job today, but the keycard system is what gets maintained, audited, and logged — the bouncer is a one-off who could be bypassed if someone refactors the room.
    - **Evidence:**
        ```php
        // balance():
        if (($pro->professional_type ?? null) === 'brand') {
            return response()->json(['error' => 'affiliate_only'], 403);
        }

        // upcomingPayouts():
        if (($pro->professional_type ?? null) === 'brand') {
            return response()->json(['error' => 'affiliate_only'], 403);
        }

        // routes/api/professional.php — these routes sit OUTSIDE the affiliate.only group:
        Route::get('/stripe/payouts/upcoming', [StripeConnectController::class, 'upcomingPayouts']);
        Route::get('/stripe/balance', [StripeConnectController::class, 'balance']);
        // affiliate.only group begins at line 439, after these registrations
        ```

- [ ] **#LIFE-3** · P2 — Billing checkout session created without an idempotency key
    - **Where:** `app/Services/Stripe/StripeBillingService.php:56` (`createCheckoutSession`)
    - **Affects:** Any network retry or double-submit on plan subscription creates duplicate Stripe Checkout Sessions for the same professional+plan.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Pass an `idempotency_key` as a second argument to `$this->stripe->checkout->sessions->create(...)`.
        - Key should encode: `"checkout_{$professional->id}_{$plan->id}_{$bucketHour}"` where `$bucketHour = floor(time() / 3600)` — this deduplicates within the same hourly window without locking the user into a stale session forever.
        - Contrast with `ensureStripeCustomer()` in the same class, which already passes `['idempotency_key' => "customer_{$professional->id}"]` correctly.
    - **Technical:** Stripe's idempotency keys deduplicate requests with the same key within a 24-hour window. Without one, a frontend double-submit (back button, network retry, duplicate tab) creates two checkout sessions for the same subscription intent. Only one can complete, but the user now has a stale session link floating somewhere and Stripe's session list grows noisy. `ensureStripeCustomer()` in the same file correctly uses an idempotency key — `createCheckoutSession()` is inconsistent. The hour-bucket approach (`floor(time() / 3600)`) is a standard pattern here: it deduplicates within the same intent window while allowing legitimate re-attempts the following hour.
    - **Plain English:** When you fill out a form and click Submit, but your internet is slow so you click again, a good system knows the second click is a duplicate and ignores it. Right now, the billing checkout doesn't have that protection — each click would create a new checkout form in Stripe. Adding an idempotency key is like putting a unique stamp on each form so Stripe can recognize "I already processed this one."
    - **Evidence:**
        ```php
        // ensureStripeCustomer() — correctly uses idempotency_key:
        $customer = $this->stripe->customers->create([...], [
            'idempotency_key' => "customer_{$professional->id}",
        ]);

        // createCheckoutSession() — no idempotency_key:
        $session = $this->stripe->checkout->sessions->create([
            'customer' => $customerId,
            'mode' => 'subscription',
            'line_items' => [[
                'price' => $plan->stripe_price_id,
                'quantity' => 1,
            ]],
            // ... no idempotency_key option passed
        ]);
        ```

- [ ] **#SCALE-5** · P2 — `payoutDetail` loads all orders for a payout with an unbounded `->get()`
    - **Where:** `app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php:431–434` (`payoutDetail`)
    - **Affects:** Detail view for large payouts — a payout batch covering hundreds of orders materializes the full set into memory on the request thread.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->limit(config('sidest.payouts.detail_orders_limit', 500))` to the order query.
        - Return a `has_more` flag in the response so the frontend can show a "load more" or "export for full list" prompt.
        - Long-term: paginate via cursor (the `payouts` endpoint already demonstrates the `{t, id}` cursor pattern).
    - **Technical:** `Order::query()->where('payout_id', $payoutId)->orderBy('occurred_at')->get()` has no ceiling. Today's average payout batch is small, but large brands with high-volume affiliates can accumulate hundreds of approved orders between grace-period cutoffs. A single `->get()` on 500+ orders loads all columns into Eloquent model instances before the response serializer runs. Adding a sensible cap (500 rows) matches the export service's own cap and keeps the response time predictable.
    - **Plain English:** The payout detail page asks the database "give me every single order in this payout" with no upper limit. For a brand doing thousands of sales a month, that's like asking a warehouse to bring every box from one shelf to the counter at once. Capping at 500 rows and offering a "download the rest" option keeps the page fast.
    - **Evidence:**
        ```php
        $orders = \App\Models\Commerce\Order::query()
            ->where('payout_id', $payoutId)
            ->orderBy('occurred_at')
            ->get();
        ```

- [ ] **#LIFE-4** · P2 — Cache invalidation is incomplete after payout refund mutations
    - **Where:** `app/Services/Stripe/CommissionPayoutRefundService.php:92–116` (`handleOrderRefund`)
    - **Affects:** (a) After a completed payout clawback: affiliate's payout state cache and analytics version cache are never invalidated — dashboard shows stale payout status and commission totals until TTL expires. (b) After a pending payout shrink/remove: the `affiliatePayoutState` key is forgotten but its `:stale` SWR twin is left dirty, causing `CacheLockService::rememberLocked` to serve the stale copy to any request that races the rebuild window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In the `completed` branch (after `clawbackCompletedPayout` succeeds or records a `needs_manual_refund`): add the same four invalidation lines that the `pending` branch already runs — `bumpAnalyticsVersion` for both `affiliate_professional_id` and `brand_professional_id`, plus `Cache::forget(affiliatePayoutState(...))`.
        - In the `pending` branch: add `Cache::forget(CacheKeyGenerator::affiliatePayoutState($order->affiliate_professional_id) . ':stale')` immediately after the existing `Cache::forget(...)` call.
        - Consider extracting a private `bustPayoutCaches(Order $order)` helper to avoid future drift between branches.
    - **Technical:** The `pending` path runs `bumpAnalyticsVersion` + `Cache::forget(affiliatePayoutState(...))` (lines 113–115). The `completed` path (`clawbackCompletedPayout`) returns at line 95 before those lines execute — zero cache invalidation despite mutating a `CommissionClawback` row and potentially setting `needs_manual_refund`. SWR semantics (`CacheLockService::rememberLocked`) use a `:stale` twin key: when the primary key is forgotten, the stale twin persists until TTL expires and is served to clients during the rebuild window. Forgetting only the primary key is therefore a half-fix; both keys must be cleared together. See `CacheLockService::rememberLocked` for the `:stale` twin key pattern.
    - **Plain English:** After a refund is processed on a completed payout, the affiliate's dashboard still shows the old payout balance because the "cached copy" of their data is never cleared. It's like updating a price on a shelf but forgetting to update the price tag — the shelf is right, the tag still says the old number. The fix is to clear the tag whenever the shelf changes. There's also a backup tag (the `:stale` copy) that also needs to be cleared, which the code does forget to handle on the simpler refund path.
    - **Evidence:**
        ```php
        if ($payout->status === 'completed') {
            $this->clawbackCompletedPayout($payout, $order, $incrementalRefundCents, $shopifyRefundId);
            return;  // exits before cache bust — zero invalidation on completed path
        }

        // ... processing branch also returns early ...

        // pending path only — cache bust lines never reached by completed/processing:
        $this->analyticsCache->bumpAnalyticsVersion($order->affiliate_professional_id);
        $this->analyticsCache->bumpAnalyticsVersion($order->brand_professional_id);
        Cache::forget(CacheKeyGenerator::affiliatePayoutState($order->affiliate_professional_id));
        // missing: Cache::forget(CacheKeyGenerator::affiliatePayoutState(...) . ':stale')
        ```

- [ ] **#SCALE-4** · P2 — One Stripe API call per payout in `forBrand`/`forAffiliate`; limit=500 on export path
    - **Where:** `app/Services/Stripe/StripeTransactionFetcher.php:42–44` (`forBrand`), `84–86` (`forAffiliate`); `app/Services/Stripe/ExportService.php:38–39` (`exportTransactions`)
    - **Affects:** The `/stripe/exports/transactions.{csv|xlsx}` endpoint — up to 500 sequential Stripe API calls per export request, risking request timeouts (~100–250s total) and degraded Stripe API throughput for all platform tenants.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - For the export path: push the transaction fetch into a queued job (`ExecuteExportJob` — already noted in `ExportService` comments) and deliver the file via a signed Supabase Storage URL. This is the right long-term fix; the in-process path should be used only for small page-level requests.
        - For the interactive transactions endpoint (already cached by `CacheLockService`): the default limit=25 is acceptable at pilot scale — keep as-is but document the throughput ceiling.
        - Short-term before the job is built: add a hard cap of 100 on the export path (`'limit' => 100`) and document it clearly in the API error response when truncated.
    - **Technical:** `StripeTransactionFetcher::scopedPayouts()` returns at most `$filters['limit']` payouts, then `forBrand()`/`forAffiliate()` make one `paymentIntents->retrieve()` or `charges->retrieve()` call per payout — sequential, synchronous, in the request thread. `ExportService::exportTransactions` passes `'limit' => 500`, meaning up to 500 consecutive Stripe API calls, each ~200–500ms, for a ceiling of ~250s. PHP's default `max_execution_time` is typically 30–60s; the response will timeout before completing. Stripe's dashboard rate limit is 100 req/s per secret key; 500 calls in rapid succession from a single request doesn't hit per-second limits but will spike the key's rolling window.
    - **Plain English:** The transactions export currently works by calling Stripe's servers once for every single payout in the date range, one after another. If a brand has 500 payouts in the period, that's 500 separate phone calls to Stripe — taking minutes — before sending any response to the user. The right fix is to move the export to a background job that runs when no one's waiting, then emails or notifies the user with a download link when it's done.
    - **Evidence:**
        ```php
        // ExportService — limit=500 passed to fetcher:
        $rows = $role === 'brand'
            ? $this->transactionFetcher->forBrand($pro, array_merge($filters, ['limit' => 500]))
            : $this->transactionFetcher->forAffiliate($pro, array_merge($filters, ['limit' => 500]));

        // StripeTransactionFetcher::forBrand — one Stripe call per payout in foreach:
        foreach ($payouts as $payout) {
            if (! $payout->payment_intent_id) { continue; }
            $pi = $this->stripe->paymentIntents->retrieve($payout->payment_intent_id, [
                'expand' => ['latest_charge.refunds'],
            ]);
        }
        ```

- [ ] **#SCHEMA-1** · P2 — Migration backfill runs without `SET LOCAL lock_timeout`
    - **Where:** `supabase/migrations/20260515000000_brand_dual_payment_methods.sql:41–57`
    - **Affects:** Production deploy of this migration — if a long-running transaction holds a row lock on `core.professionals`, the backfill `UPDATE` will queue behind it and hold an implicit table-level lock that blocks all other writes to the table for the duration.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `SET LOCAL lock_timeout = '3s';` immediately before each `UPDATE core.professionals ...` statement inside the `BEGIN`/`COMMIT` block.
        - If the lock isn't acquired within 3s, the migration fails fast with a `lock_not_available` error rather than silently blocking production traffic.
        - Pair with `SET LOCAL statement_timeout = '30s';` for belt-and-suspenders.
        - If the table is large enough that the UPDATE might genuinely take >30s, document a batched backfill strategy (loop with `LIMIT`/`OFFSET` or a `pg_sleep` between batches in a DO block).
    - **Technical:** `ALTER TABLE ... ADD COLUMN` acquires `ACCESS EXCLUSIVE`, which is expected and fast since the new columns have no DEFAULT (PostgreSQL 11+ handles this with a catalog-only update). The two `UPDATE` statements after it are `ROW EXCLUSIVE` — less severe but still capable of stalling behind a long-running `SELECT FOR UPDATE` or `UPDATE` on another session. Without `lock_timeout`, the migration blocks indefinitely while holding the lock, which cascades: any subsequent transaction touching `core.professionals` queues behind the stalled UPDATE, and the queue grows. This is the Master Pattern 20 for migration safety documented in the project's migration standards.
    - **Plain English:** The migration runs on the production database while real users may be active. Without a timeout on the lock request, if one slow background process is using the user table at the same moment, this migration silently waits — and while it waits, it holds a door shut that every other operation needs to pass through. Setting a 3-second timeout means the migration fails quickly with a clear error instead of causing a silent outage, and you can retry during a quieter window.
    - **Evidence:**
        ```sql
        BEGIN;

        ALTER TABLE core.professionals
            ADD COLUMN IF NOT EXISTS stripe_card_payment_method_id text,
            -- ...
            ADD COLUMN IF NOT EXISTS preferred_payout_method text;

        -- no SET LOCAL lock_timeout before these writes:
        UPDATE core.professionals
        SET stripe_card_payment_method_id = stripe_payment_method_id,
            stripe_card_brand              = stripe_payment_method_brand,
            stripe_card_last4              = stripe_payment_method_last4,
            preferred_payout_method        = 'card'
        WHERE payout_method = 'card'
          AND stripe_payment_method_id IS NOT NULL
          AND stripe_card_payment_method_id IS NULL;

        UPDATE core.professionals
        SET stripe_becs_payment_method_id = stripe_payment_method_id,
            -- ...
        WHERE payout_method = 'becs'
          AND stripe_payment_method_id IS NOT NULL
          AND stripe_becs_payment_method_id IS NULL;

        COMMIT;
        ```

---

## P3 — Nice to have

- [ ] **#LIFE-5** · P3 — `ensureStripeCustomer` creates a new Stripe Customer on every call
    - **Where:** `app/Services/Stripe/StripeBillingService.php:31–42` (`ensureStripeCustomer`)
    - **Affects:** Stripe customer list cleanliness; each plan checkout attempt creates a new Customer object even if one already exists for this professional.
    - **Effort:** M (~2–4h) — requires a new column or metadata query strategy
    - **What to do:**
        - Track this as planned Phase 3 work (the TODO comment already documents this).
        - When Phase 3 lands: add a `stripe_billing_customer_id` column to `core.professionals`, populate it on first customer creation, and check it before creating a new one in `ensureStripeCustomer`.
        - The existing `idempotency_key` on the `customers->create()` call provides correctness (Stripe returns the same customer within 24h for the same key) but doesn't help with the proliferating customer objects beyond the 24h window.
    - **Technical:** The idempotency key `"customer_{$professional->id}"` ensures Stripe returns the same `Customer` object within a 24-hour window — so repeated checkout attempts on the same day don't create duplicates. But across days (or if the idempotency key expires), a new Customer is silently created. Phase 3 must store the returned `$customer->id` on `core.professionals` and do a `stripe_billing_customer_id IS NOT NULL` check before calling `customers->create()`. Until then, the dedup window is 24h — adequate for pre-beta but not for production.
    - **Plain English:** Every time a user starts a billing checkout, the app registers them as a brand-new customer with Stripe, even if they've done this before. Stripe's deduplication catches this if it happens twice in the same day, but not across days. The planned fix (Phase 3) is to save the customer ID the first time and reuse it — like writing your name in an address book the first time you visit, rather than re-introducing yourself every visit.
    - **Evidence:**
        ```php
        /**
         * TODO[stripe-v2]: stripe_customer_id column dropped from professionals.
         * Each call currently creates a new Stripe Customer (deduplicated within the
         * idempotency window by professional ID). Phase 3 will add proper storage
         * for the customer ID so it can be reused across calls.
         */
        public function ensureStripeCustomer(Professional $professional): string
        {
            $customer = $this->stripe->customers->create([
                'email' => $professional->primary_email,
                'name' => $professional->display_name,
                'metadata' => [
                    'sidest_professional_id' => $professional->id,
                    'professional_type' => $professional->professional_type,
                ],
            ], ['idempotency_key' => "customer_{$professional->id}"]);

            return $customer->id;
        }
        ```
