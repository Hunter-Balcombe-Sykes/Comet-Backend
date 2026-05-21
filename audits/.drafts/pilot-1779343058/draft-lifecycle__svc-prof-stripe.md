- [ ] **#LIFE-1** · P2 — `catch (QueryException)` + string-code check instead of typed `UniqueConstraintViolationException` in invite upsert retry loop
    - **Where:** app/Services/Professional/Brand/BrandAffiliateInviteService.php:333-342
    - **Affects:** Affiliate invite creation under concurrent brand operations — two brands sending invites that hit the same token-race or pending-uniqueness conflict.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `catch (QueryException $exception)` with `catch (UniqueConstraintViolationException $e)`.
        - Remove the `(string) $exception->getCode() !== '23505'` guard — the typed catch already filters to unique-violation only.
    - **Technical:** The `#STRIPE-3` canonical replacement (`35c6f31`) requires `catch (UniqueConstraintViolationException $e)` because Postgres error-code strings are version-stable identifiers, not guaranteed-format strings. `getCode()` returns the SQLSTATE as a string like `'23505'` today, but comparing it via equality relies on PDO driver behaviour that can shift. The typed exception is provided by Laravel 10+ and is stable across Postgres releases and constraint renames.
    - **Plain English:** The code catches "any database error" and then squints at the error number to figure out if it's a duplicate-entry problem. This is like checking a barcode by eyeballing it instead of using a scanner — it works until the label format changes. The fix uses the scanner (a typed exception) that's guaranteed to read the barcode correctly every time.
    - **Evidence:**
        ```php
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23505') {
                throw $exception;
            }

            // Retry on token/pending uniqueness races.
            if ($attempt === 2) {
                throw new RuntimeException('Unable to create or refresh invite right now. Please try again.');
            }
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#LIFE-2** · P2 — `QueryException` code check instead of `UniqueConstraintViolationException` in subdomain allocation
    - **Where:** app/Services/Professional/SiteProvisioningService.php:113-117
    - **Affects:** New professional signup — subdomain allocation retry loop. Low impact at 200 brands (subdomain collisions are rare), but the anti-pattern propagates.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `catch (QueryException $e)` + `$e->getCode() === '23505'` with `catch (UniqueConstraintViolationException $e)`.
        - Delete the private `isUniqueViolation()` helper method.
    - **Technical:** Same `#STRIPE-3` pattern as LIFE-1. `SiteProvisioningService::tryCreateSite()` catches `QueryException` and delegates to `isUniqueViolation()` which string-compares `$e->getCode()` against `'23505'`. At the scale target of 200 brands, subdomain collisions are infrequent enough that this won't break, but every new developer who copies this pattern into a higher-stakes context (wallet inserts, ledger entries) inherits the fragility.
    - **Plain English:** Same "eyeballing the barcode" problem — the code catches all database errors and checks if the error number looks like a duplicate. The scanner (typed exception) is available and should be used instead.
    - **Evidence:**
        ```php
        private function isUniqueViolation(QueryException $e): bool
        {
            return $e->getCode() === '23505';
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#LIFE-3** · P2 — `QueryException` code check instead of `UniqueConstraintViolationException` in commission adjustment posting
    - **Where:** app/Services/Stripe/CommissionAdjustmentService.php:82-88
    - **Affects:** Staff-admin commission adjustments — duplicate-reference detection for manual corrections. At the scale target, adjustments are rare (staff-only), but the pattern sits on the financial write path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `catch (QueryException $e)` + `$e->getCode() === '23505'` with `catch (UniqueConstraintViolationException $e)`.
        - Re-throw non-duplicate `QueryException` instances (the current code already does this via the `throw $e` fallthrough, but the typed catch makes it cleaner).
    - **Technical:** The `commission_ledger_entries_idempotency_uq` partial-unique index is the source of truth for duplicate-adjustment detection. The current code catches all `QueryException` instances and filters by code. The canonical `UniqueConstraintViolationException` catch is version-stable and self-documenting — no future developer has to wonder "is 23505 really the unique-violation code on Postgres?"
    - **Plain English:** Same pattern as LIFE-1 and LIFE-2 — catching all database errors and checking the error number manually instead of using the purpose-built typed exception. This is on the money-movement path, so correctness matters even though adjustments are infrequent.
    - **Evidence:**
        ```php
        } catch (QueryException $e) {
            // 23505 = unique_violation. The only unique constraint on commission_movements is
            // commission_ledger_entries_idempotency_uq — a duplicate reference for an
            // already-posted adjustment.
            if ($e->getCode() === '23505') {
                throw new DuplicateAdjustmentException($reference, previous: $e);
            }
            throw $e;
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#LIFE-4** · P2 — Swallowed `ApiErrorException` in payment-method detachment hides Stripe failures from operations
    - **Where:** app/Services/Stripe/StripeConnectService.php:702-706, 719-722, 737-739
    - **Affects:** Brand payment-method management — when a brand removes a saved card or BECS mandate, Stripe-side detachment failures are invisible. At 200 brands with churning payment methods, silent failures accumulate undetected.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log the exception with `professional_id`, `payment_method_id`, and the Stripe error message before continuing.
        - Use `Log::warning` (not error — the local state is still cleaned up, and the PM is detached from the brand's perspective regardless).
    - **Technical:** `removeBrandPaymentMethod()` catches `ApiErrorException` with an empty block — the Stripe `paymentMethods->detach()` call fails (network blip, rate limit, Stripe outage) and the code silently proceeds to clear the local columns. The brand sees "card removed" but Stripe still has the PM attached. Future attempts to re-add a card of the same type would work, but the orphaned PM on Stripe is invisible to ops. The canonical `verbatim vendor error capture` pattern requires logging vendor errors verbatim so Nightwatch can surface them.
    - **Plain English:** When a brand removes their saved card, the system tells Stripe "detach this card." If Stripe is having a bad day and the detachment fails, the code shrugs and continues — the card is removed from our database but stays attached on Stripe's side. Nobody knows this happened. The fix is to log the failure so the ops team can see it.
    - **Evidence:**
        ```php
        try {
            $this->stripe->paymentMethods->detach($becsId);
        } catch (ApiErrorException) {
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#LIFE-5** · P1 — `processPayoutBatch` returns `null` for 3+ distinct outcomes; caller cannot distinguish "cancelled" from "in flight"
    - **Where:** app/Services/Stripe/CommissionPayoutService.php:596-670
    - **Affects:** Payout job retry logic and logging — `ExecuteCommissionPayoutJob` receives `null` for "PI accepted, awaiting webhook" (line 665), "revalidation cancelled all orders" (via `revalidatePayoutOrders` returning null at line 598), and "already processing with PI ID" (line 623). At ~10K daily payout jobs, conflated outcomes produce incorrect retry decisions and unactionable logs.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Change return type from `?bool` to a typed result enum or DTO with distinct values: `Completed`, `InFlight`, `Cancelled`, `AlreadyProcessing`.
        - Update `ExecuteCommissionPayoutJob::handle()` to branch on the result type and emit distinct log strings per outcome.
        - The `AlreadyProcessing` case should log at `info` level (re-queue is normal); `Cancelled` should log at `notice` (orders became ineligible); `InFlight` should log at `info` (awaiting webhook).
    - **Technical:** The `#STRIPE-2` canonical fix (`35c6f31`) requires distinct log strings for distinct failure modes. `processPayoutBatch` returns `null` when the payout was cancelled after revalidation AND when the PI was accepted by Stripe and is in flight. The caller sees `null` in both cases and cannot distinguish "the payout is gone, stop retrying" from "the payout is processing, wait for the webhook." At the scale target, re-queued processing payouts are common (BECS T+2 settlement means every BECS payout re-queues at least once), and the logging cannot tell ops whether a `null` return is normal or a bug.
    - **Plain English:** This function says "I'm done" by returning `null`, but `null` means three different things: "everything's fine, Stripe is handling it," "the payout was cancelled because orders changed," and "I already have a Stripe payment in flight, nothing to do." The code that calls this function can't tell which one happened, so it can't log accurately or make smart retry decisions. At scale with thousands of payouts per day, this creates confusion when debugging failures.
    - **Evidence:**
        ```php
        // Three distinct null-return paths:
        
        // Path 1: revalidation cancelled all orders
        if ($payout->status === 'pending') {
            $payout = $this->revalidatePayoutOrders($payout);
            if ($payout === null) {
                return null; // ← "cancelled"
            }
        }
        
        // Path 2: already processing with PI
        if ($payout->status === 'processing' && $payout->payment_intent_id !== null) {
            return null; // ← "already in flight, no-op"
        }
        
        // Path 3: PI accepted, awaiting webhook
        return null; // ← "in flight, webhook will resolve"
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#LIFE-6** · P1 — No daily reconcile job that calls Stripe `PaymentIntent.retrieve` for stuck processing payouts; missed webhooks leave payouts stranded forever
    - **Where:** app/Services/Stripe/CommissionPayoutService.php (absence of reconcile logic); processEligiblePayouts re-queues processing payouts but processPayoutBatch no-ops them at line 623
    - **Affects:** Any payout whose `payment_intent.succeeded` webhook is not delivered — at ~10K daily payout jobs and Stripe's at-least-once (occasionally zero) webhook delivery, even a 0.1% miss rate means ~10 stranded payouts per day. These stay "processing" indefinitely with a live PI and no terminal transition.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a `ReconcileStuckProcessingPayoutsJob` that runs daily, queries payouts with `status = 'processing'` and `payment_intent_id IS NOT NULL` older than N hours, calls `PaymentIntent::retrieve()` on Stripe, and transitions based on the actual PI status.
        - Log when a reconcile pass catches a missed delivery — silent reconcile is invisible drift (the canonical `daily reconcile job` pattern from `0de1f2f`).
        - The reconcile job must NOT re-trigger notifications — use `markPaymentIntentSucceeded` / `markPaymentIntentFailed` which are already idempotent.
    - **Technical:** The `0de1f2f` canonical pattern (`ReconcileStuckTransferringPayoutsJob`) is the template. Under Option A, the sole source-of-truth for payout completion is the `payment_intent.succeeded` webhook. The daily `processEligiblePayouts` re-queues processing payouts, but `processPayoutBatch` has an explicit no-op guard (`if processing && payment_intent_id !== null → return null`). Re-queuing is harmless but useless. Without a reconcile job that calls Stripe's API to retrieve the actual PI status, any webhook delivery gap is permanent. At the scale target, Stripe's at-least-once delivery means webhooks are eventually delivered, but "eventually" can be minutes or hours — and some webhooks are genuinely dropped.
    - **Plain English:** When Stripe finishes processing a payment, it sends us a "payment succeeded" webhook. But webhooks are like postcards — most arrive, some get lost. If the postcard gets lost, the payout is stuck in "processing" forever. The daily job that re-queues stuck payouts currently says "if it's already processing, do nothing." We need a separate job that goes to Stripe and asks "hey, what's the actual status of this payment?" and updates our records accordingly. Without this, lost webhooks mean stranded money.
    - **Evidence:**
        ```php
        // processEligiblePayouts re-queues processing payouts:
        $existingPending = CommissionPayout::query()
            ->whereIn('status', ['pending', 'processing'])
            // ...
            ->get();
        foreach ($existingPending as $pendingPayout) {
            ExecuteCommissionPayoutJob::dispatch($pendingPayout->id);
        }
        
        // But processPayoutBatch no-ops them:
        if ($payout->status === 'processing' && $payout->payment_intent_id !== null) {
            return null; // ← re-queue achieves nothing
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#LIFE-7** · P2 — Periodic payout-grace warnings lack JSONB dedup on the parent row; rely solely on notification-pipeline per-key dedup
    - **Where:** app/Services/Stripe/CommissionVoidService.php:622-663 (`sendPerPayoutWarnings`)
    - **Affects:** Affiliates approaching payout void deadlines — at ~40K daily notifications, if the cron job runs twice (overlap, manual trigger, DST boundary), or if the notification pipeline's dedup cache is evicted, duplicate warnings fire. Harmless at 30 affiliates; annoying at 10K affiliates.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `payout_warnings_sent` JSONB column on `commerce.commission_payouts` with shape `{day10: timestamp, day2: timestamp}` — mirroring the `af90b2e` canonical pattern.
        - Before publishing, check the JSONB: if the warning key already has a timestamp, skip. After publishing, set the timestamp.
        - This dedup survives notification-pipeline rebuilds and is a single read on the payout row (already loaded in the chunk loop).
    - **Technical:** The `af90b2e` canonical pattern stores `{T-30: timestamp, T-7: timestamp, T-1: timestamp}` JSONB on the parent row so dedup is a single read and retry storms cannot double-fire. `sendPerPayoutWarnings` uses `dedupeKey: "stripe_warning.payout.{$key}.{$payout->id}"` which relies on the notification pipeline's internal dedup mechanism. That mechanism works today, but its durability is tied to the notification system's retention config — if that config is shortened, or if the pipeline is refactored, the dedup state is lost and re-runs spam affiliates. The payout row IS the durable source of truth for whether a warning was sent.
    - **Plain English:** The system sends "10 days left" and "2 days left" warnings before an affiliate's commission expires. To avoid sending the same warning twice, it relies on the notification system's built-in duplicate-detection. But that detection lives in a separate system with its own memory — if that memory gets cleared, the warnings go out again. The fix is to write "warning sent" directly on the payout record itself, so the payout IS the permanent record of what warnings have been sent.
    - **Evidence:**
        ```php
        // Dedup relies entirely on NotificationPublisher's per-key dedup:
        $this->publisher->publish(
            professionalId: $payout->affiliate_professional_id,
            frontendType: 'Warning',
            category: 'commissions',
            title: $window['title'],
            body: sprintf($window['body'], Money::format($payout->net_payout_cents, $payout->currency_code)),
            dedupeKey: "stripe_warning.payout.{$key}.{$payout->id}",
            ctaUrl: '/account/settings?section=stripe',
            retentionConfigKey: 'commission',
        );
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#LIFE-8** · P3 — `reevaluateEnabled()` read-modify-write without `lockForUpdate`; two concurrent saves can produce a stale `is_enabled` flag
    - **Where:** app/Services/Professional/SectionVisibilityService.php:307-324
    - **Affects:** Storefront section-block visibility — gallery, booking, services, documents, credentials, experience sections. If the last qualifying item is deleted concurrently with a reevaluation, the block stays `is_enabled = true` until the next write. Cosmetic impact: a section renders empty for one page load.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either wrap the read + check + write in a `lockForUpdate` transaction on the block row, or accept the eventual-consistency behaviour and document it. The race window is a single page load and self-corrects on the next block save.
        - If keeping eventual consistency, add a comment noting the intentional lack of lock.
    - **Technical:** `reevaluateEnabled()` loads the block, calls `checkVisibilityRequirements()` (which does fresh EXISTS queries), then saves the block. Between the EXISTS queries and the save, another transaction can delete the last gallery image / document / service, making the old `is_enabled = true` stale. The stale value persists until the next block-touching write. This is a classic read-modify-write race, but the blast radius is tiny — one section shows as "enabled" but renders empty for a single request, and any subsequent Block save corrects it.
    - **Plain English:** The system checks "does this section have enough content to be visible?" and then saves the answer. But between checking and saving, someone could delete the last piece of content, so the section stays marked "visible" even though it's now empty. The next time anything saves that section, it corrects itself. The window is a fraction of a second and the impact is a briefly-empty section on the storefront.
    - **Evidence:**
        ```php
        $block = Block::query()
            ->where('professional_id', $professionalId)
            ->where('site_id', $siteId)
            ->where('block_group', 'sections')
            ->where('block_type', $blockType)
            ->first();                                     // ← read

        [$canBeEnabled] = $this->checkVisibilityRequirements(
            $professionalId, $siteId, $blockType
        );                                                  // ← independent EXISTS queries

        if ((bool) $block->is_enabled !== $canBeEnabled) {
            $block->is_enabled = $canBeEnabled;
            $block->save();                                 // ← write, no lock
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#LIFE-9** · P2 — Synchronous outbound HTTP call in `isStorefrontReachable()` blocks user-facing status evaluation for up to 5 seconds on cache miss
    - **Where:** app/Services/Professional/Brand/BrandStatusService.php:280-303
    - **Affects:** Brand dashboard and onboarding flow — `determine()` → `isStorefrontReachable()` makes a synchronous `Http::get()` with 5s timeout. Called from `sync()` which runs after every mutation that could change brand status (Shopify OAuth callback, store settings save, image upload). At 200 brands, cold-cache status checks add up to 5s to user-facing responses.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Dispatch the reachability check to a queued job instead of running it synchronously. The job updates a `storefront_reachable_at` timestamp and `storefront_reachable` boolean on `brand.brand_profiles` or `brand.brand_store_settings`.
        - `determine()` reads the cached boolean instead of making an HTTP call.
        - Keep the short-TTL cache as a fallback for the job-based path.
    - **Technical:** The `isStorefrontReachable()` cache (60s TTL for reachable, 15s for unreachable) mitigates the HTTP call for repeated checks, but the first check after a cache miss blocks the calling request. `sync()` is called from `BrandOnboardingReadinessService::getChecklist()` (which returns JSON to the dashboard) and from the Shopify OAuth callback flow. In both cases, the user is waiting for a response. At the scale target, 200 brands onboarding and updating settings means this cache-miss penalty is hit regularly. The canonical pattern from the Stripe payout work keeps vendor calls out of DB transactions (`59655e8d`) — the same principle applies to keeping them out of synchronous user-facing request paths.
    - **Plain English:** When the system checks whether a brand's storefront is live, it makes an HTTP request to the brand's website to see if it responds. This HTTP request happens while the brand is waiting for a dashboard page to load. The first check is cached for 60 seconds, but if the cache is empty, the brand waits up to 5 seconds. At 200 brands all editing their settings, these cache-miss pauses add up. The fix is to move the HTTP check to a background job and let the dashboard read a stored result instead.
    - **Evidence:**
        ```php
        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'timeout' => 5,
                'connect_timeout' => 3,
            ])->get($url);

            $reachable = $response->successful();
        } catch (\Throwable) {
            $reachable = false;
        }

        Cache::put($cacheKey, $reachable, $reachable ? 60 : 15);
        ```
    - `[DRAFT, confidence: 0.8]`
