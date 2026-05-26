
<!-- ═══ CHUNK: infra ═══ -->

- [ ] **#LIFE-1** · P2 — `staffAnalyticsSummary` cache key does not embed the version token internally; relies on every caller to append `:v{version}` manually
    - **Where:** app/Services/Cache/CacheKeyGenerator.php (staffAnalyticsSummary method)
    - **Affects:** Staff-facing analytics dashboards — stale summaries survive `bumpAnalyticsVersion()` if any caller forgets the version suffix. Every other analytics key (`brandCommerceAnalytics`, `affiliateCommerceAnalytics`) embeds the version internally.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Embed the version token inside `staffAnalyticsSummary` itself (mirror `brandCommerceAnalytics`: read `analyticsSummaryVersion`, interpolate into the key).
        - Audit the one or two call sites that currently append `:v{version}` manually and remove the suffix — the key generator owns versioning now.
    - **Technical:** Every other analytics cache key in this class reads `Cache::get(analyticsSummaryVersion(...), 0)` and includes the version in the returned string. `staffAnalyticsSummary` is the sole exception — it returns an unversioned key and instructs callers to append the version themselves. This violates the single-point-of-truth discipline that makes `bumpAnalyticsVersion()` atomically invalidate every windowed key. At the scale target (200 brands with staff dashboards), a single forgotten suffix at a call site means staff see frozen data for up to 24h of TTL. Canonical replacement: **version-keyed cache** (`27c1b7a`).
    - **Plain English:** Imagine every door in a building auto-locks when the security guard presses one button — except the staff kitchen door, which only locks if whoever used it last remembers to turn the deadbolt. Same guard, same button, but the kitchen door's lock isn't wired in. When the guard presses the button, the kitchen stays unlocked. That's this cache key — when the system bumps the analytics version to invalidate all dashboards, the staff dashboard key doesn't get the memo unless the developer writing the dashboard code remembered to glue it on.
    - **Evidence:**
        ```php
        // ✅ brandCommerceAnalytics — version embedded internally
        public static function brandCommerceAnalytics(string $professionalId, string $from, string $to): string
        {
            $version = \Illuminate\Support\Facades\Cache::get(self::analyticsSummaryVersion($professionalId), 0);
            return "analytics:commerce:brand:v7:{$professionalId}:{$version}:{$from}:{$to}";
        }

        // ❌ staffAnalyticsSummary — version left to caller
        public static function staffAnalyticsSummary(string $professionalId, string $from, string $to): string
        {
            return "staff:analytics:summary:{$professionalId}:{$from}:{$to}";
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#LIFE-2** · P2 — `SiteObserver::cascadeAffiliateKvSync` dispatches `SyncSubdomainToKvJob` in a synchronous `->each()` loop; no chunking, no single-job fan-out
    - **Where:** app/Observers/Core/SiteObserver.php (cascadeAffiliateKvSync method)
    - **Affects:** Brand site saves (subdomain change, publish toggle) — every linked affiliate gets a job dispatched synchronously inside the observer. At 50 affiliates this is ~50ms of Redis writes; at 500+ (scale target upper bound) the observer blocks the HTTP response for hundreds of milliseconds.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract the affiliate-ID enumeration + dispatch loop into a single `CascadeAffiliateKvSyncJob` that chunks `BrandPartnerLink` rows internally (500 per chunk), mirroring `InvalidateBrandAffiliatesCacheJob`.
        - Replace the `->each(... dispatch ...)` loop with a single `CascadeAffiliateKvSyncJob::dispatch($brandProfessionalId)`.
    - **Technical:** `SiteObserver::saved` already ends with `InvalidateBrandAffiliatesCacheJob::dispatch($professionalId)` for cache invalidation — that job chunks internally, avoiding the O(N) synchronous-dispatch anti-pattern. The KV sync path one method earlier (`cascadeAffiliateKvSync`) was never updated to match and still does `BrandPartnerLink::pluck(...)->each(fn => SyncSubdomainToKvJob::dispatch(...))`. Each `dispatch()` call is a Redis LPUSH + serialization; at 500 affiliates that's ~500 sequential Redis commands inside a single observer execution. `SyncSubdomainToKvJob` already has `ShouldBeUnique` with a 45s lock, so the duplicated work is bounded, but the dispatch storm itself is unnecessary. Canonical replacement: **jittered per-tenant invalidation** (`38ff4fb`) — the cache-invalidation sibling already uses the chunking pattern; KV sync should match.
    - **Plain English:** When a brand changes their website address, the system needs to tell every one of their affiliates "your routing record needs updating." Right now it picks up the phone and calls each affiliate one at a time, waiting for the phone to ring before dialing the next. At 50 affiliates this takes a blink. At 500 affiliates the brand sits waiting while the system works through its phone list. The fix is to hand the whole list to one assistant who makes all the calls — the brand can hang up and get on with their day.
    - **Evidence:**
        ```php
        // SiteObserver.php — synchronous O(N) dispatch loop
        private function cascadeAffiliateKvSync(string $brandProfessionalId): void
        {
            if ($brandProfessionalId === '') {
                return;
            }

            BrandPartnerLink::query()
                ->where('brand_professional_id', $brandProfessionalId)
                ->pluck('affiliate_professional_id')
                ->each(function (string $affiliateId): void {
                    SyncSubdomainToKvJob::dispatch($affiliateId);
                });
        }

        // Same file, same observer — the cache-invalidation sibling already
        // uses the correct chunking pattern:
        InvalidateBrandAffiliatesCacheJob::dispatch($professionalId);
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#LIFE-3** · P2 — `BrandAffiliateInviteObserver` sends invite emails synchronously via `Mail::send()` instead of queuing
    - **Where:** app/Observers/Core/BrandAffiliateInviteObserver.php (created method)
    - **Affects:** Bulk CSV invite imports — each `Mail::send()` blocks the observer (and thus the HTTP response) for the duration of an SMTP round-trip. At 100+ invites in a single CSV upload, this adds seconds of latency. Single invites are negligible.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `Mail::send(new AffiliateInvitedMail(...))` to `Mail::to($recipientEmail)->queue(new AffiliateInvitedMail(...))`.
        - The `AffiliateInvitedMail` Mailable already supports queuing (it's a standard Laravel Mailable); no Mailable-side changes needed.
    - **Technical:** The observer uses `Mail::send()` directly, which resolves the Mailable, renders the template, and talks to the SMTP server synchronously. The rest of the codebase already uses the queue pattern — `NotifyHandleAliasExpiry` does `Mail::to($email)->queue(...)`. The fix is a one-line change. This is not a race condition or correctness bug, but at the scale target (200 brands doing seasonal affiliate CSV imports), a brand uploading 200 invites would see their request hang for the cumulative SMTP latency of all 200 renders + sends. Canonical replacement: the **fan-out dedup** pattern — notifications should fan out through the queue, not block the write path. The `NotificationPublisher` path in the same observer already publishes asynchronously; the email path was simply missed.
    - **Plain English:** When a brand uploads a spreadsheet of 100 affiliate invites, the system sends each invite email before it says "done." If each email takes half a second to send, the brand stares at a loading spinner for 50 seconds. The rest of the system already knows how to hand emails to a queue and move on — this one spot just forgot. The fix is switching from "deliver now" to "hand to the delivery person and keep moving."
    - **Evidence:**
        ```php
        // BrandAffiliateInviteObserver.php — synchronous Mail::send()
        Mail::send(new AffiliateInvitedMail(
            recipientEmail: $recipientEmail,
            recipientFirstName: is_string($invite->first_name ?? null) && trim((string) $invite->first_name) !== ''
                ? trim((string) $invite->first_name)
                : null,
            brandName: $brandName,
            acceptUrl: $acceptUrl,
            expiresInDays: $expiresInDays,
        ));

        // Elsewhere in the codebase — the correct queued pattern is already in use:
        // NotifyHandleAliasExpiry.php
        Mail::to($email)->queue(new HandleAliasExpiringMail($alias, $bucket));
        ```
    - `[DRAFT, confidence: 0.95]`

<!-- ═══ CHUNK: svc-prof-stripe ═══ -->

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

<!-- ═══ CHUNK: svc-commerce ═══ -->

- [ ] **LIFE-1** · P1 — Affiliate catalog queries bypass the ShopifyAdminClient, including its throttling, retry, and budget tracker
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php — `queryAdminCatalog()` method
    - **Affects:** Every affiliate browsing products at peak (up to 40K daily notifications / catalog reads). Calls flood Shopify’s API without respecting the shared budget, risking rate‑limit errors for ALL tenants using the same Shopify app.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the inline `Http::post` with a call to `$this->client->graphql()` (the injected `ShopifyAdminClient`), so the single‑shop budget, cost estimation, and THROTTLED retry apply.
        - Remove the fallback `$fallback` logic that re‑constructs the URL; `ShopifyAdminClient` handles the endpoint.
    - **Technical:** `queryAdminCatalog` builds its own `Http` request directly against `https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json`, bypassing `ShopifyAdminClient::graphql()` which pre‑acquires budget from the Redis‑backed token bucket, reconciles throttle state, and retries on `THROTTLED`. At the scale target of 200 brands × ~50 affiliates browsing catalogs concurrently, these ungoverned requests can exhaust Shopify’s cost budget and trigger HTTP 429 for all other operations (webhook registration, metafield writes, teardown). The fix is to call `$this->client->graphql(ShopDomain::fromUntrusted($shopDomain), $accessToken, $apiVersion, $query, $variables)` — the client already exists in sibling methods.
    - **Plain English:** Think of a warehouse with a shared dock for all tenants. Every brand’s delivery trucks go through a central traffic controller that schedules them so no one jam occurs. But when an affiliate wants to see the product catalog, we send a truck straight to the dock without telling anyone. At a few brands it works; with hundreds, the dock gets overloaded and nobody’s deliveries get through. The fix is to route the affiliate’s truck through the same traffic controller already in place.
    - **Evidence:**
        ```php
        // AffiliateProductCatalogService.php, in queryAdminCatalog:
        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->withHeaders([
                    'X-Shopify-Access-Token' => $accessToken,
                ])
                ->post($url, [
                    'query' => $query,
                    'variables' => $variables,
                ]);
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **LIFE-2** · P2 — Concurrent resyncs can overwrite each other’s metadata in ShopifyDataResyncService
    - **Where:** app/Services/Shopify/ShopifyDataResyncService.php — `resync()` method, inside the `DB::transaction()`
    - **Affects:** Brand settings that are merged into `provider_metadata` (e.g. `webhook_ids`, `storefront_token`, `last_resynced_at`). Two near‑simultaneous resyncs (e.g. manual + automated) can silently lose one’s changes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Call `$integration->lockForUpdate()` inside the transaction before reading any part of `provider_metadata`.
        - Re‑read the metadata after locking to avoid a stale snapshot.
    - **Technical:** The `resync` fetches shop data outside the transaction, then inside the transaction calls `$integration->mergeProviderMetadata(['last_resynced_at' => …])`. `mergeProviderMetadata` loads the current JSONB column, merges in the new key, and saves — a classic read‑modify‑write. Without `lockForUpdate`, two concurrent transactions can both read the same metadata, each merge their own timestamp, and the second save completely overwrites the first’s merge (lost update). The canonical `lockForUpdate + UNIQUE` pattern requires locking the row before the read phase so that PostgreSQL serialises the two merges.
    - **Plain English:** Imagine two people editing the same spreadsheet cell at the same time. Each grabs the current value, adds their note, and saves. The last save wins and the first person’s note disappears. The fix is to put a lock on the cell so only one person can edit at a time — the second person waits and then sees the updated value.
    - **Evidence:**
        ```php
        // In resync():
        $diff = DB::connection('pgsql')->transaction(function () use ($integration, …) {
            $diff = $this->autoFill->resyncFromShopData($integration, $shopData);
            // Race window: another resync can read metadata here
            $integration->mergeProviderMetadata([
                'last_resynced_at' => $lastResyncedAt,
            ]);
            …
        });
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-3** · P2 — Swallowed exceptions in BrandDesignImporter lack any logging, making theme‑fetch failures invisible
    - **Where:** app/Services/Shopify/BrandDesignImporter.php — `fetchActiveThemeSettings()` method, two `catch (\Throwable)` blocks
    - **Affects:** On‑boarding brands where the Shopify Admin GraphQL or Asset API is temporarily failing — the brand imports successfully but receives no theme settings, and nobody knows why.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log each caught exception at `warning` level with context: `['shop_domain' => $shopDomain, 'integration_id' => ..., 'step' => 'themesQuery|assetFetch', 'exception' => $e]`.
        - Ensure the log includes `professional_id` (or integration UUID) for Nightwatch correlation.
    - **Technical:** Both `catch (\Throwable)` blocks return an empty `['_theme_name' => null, 'current' => []]` without a single `Log::` call. A transient Shopify outage or permission problem therefore silently degrades the brand design import, leaving the brand with no corner radius / spacing hints and no indication that anything went wrong. The canonical `Log-with-context` pattern requires that any swallowed exception be recorded so Nightwatch can surface it and operators can trace the root cause.
    - **Plain English:** It’s like a team member who quietly drops a broken part in the bin without telling anyone. The production line keeps moving, but the final product has a weird wobble, and nobody can trace it back to the dropped piece because there’s no note. Adding a quick note to the log says “at step X, this part broke; we carried on, but here’s why.”
    - **Evidence:**
        ```php
        // In fetchActiveThemeSettings:
        try {
            $themesResponse = $this->client->graphql(…);
        } catch (\Throwable) {
            return ['_theme_name' => null, 'current' => []];
        }
        // Same for asset fetch later.
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-4** · P2 — ShopifyBulkOperationLock TTL (3600s) can stall a shop’s bulk operations for an hour after a worker crash
    - **Where:** app/Services/Shopify/Client/ShopifyBulkOperationLock.php — `acquire()` method
    - **Affects:** Every bulk operation (metafield backfill, product sync) that uses `ShopifyAdminClient::bulkQuery` / `bulkMutation`. A single worker crash blocks all subsequent bulk work for that shop until the Redis key expires.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Lower the default `bulk_lock_ttl_seconds` to 600s (the maximum `waitForBulkOperation` timeout) plus a small margin (e.g. 610s) so the lock auto‑cleans even if the release path is missed.
        - Alternatively, implement a heartbeat extension inside `waitForBulkOperation` that bumps the key’s TTL while the operation is still running.
    - **Technical:** The lock is acquired with `Redis::set(key, '1', 'EX', 3600, 'NX')`. The happy path always releases after `waitForBulkOperation` finishes (at most 600s). If the worker crashes before reaching the release, the lock stays for the full 3600 seconds, during which any `bulkQuery` or `bulkMutation` for the same shop immediately throws `“bulk operation already in progress”`. This is a soft‑lockout that can persist across Horizon restarts; the canonical remedy is to set the TTL no longer than the maximum expected operation time, so the lock naturally expires.
    - **Plain English:** Imagine a building with a master key that gets left inside the only room it opens. The room is cleaned within 10 minutes, but the key’s timer says it’s lost for an hour. For that hour nobody can enter. The fix is to tell the timer “if nobody has come back after 10 minutes, the key is free anyway.”
    - **Evidence:**
        ```php
        public function acquire(string $shopDomain, ?int $ttlSeconds = null): bool
        {
            $ttl = $ttlSeconds ?? (int) config(
                'services.shopify.throttle.bulk_lock_ttl_seconds', 3600);
            $result = Redis::set($this->key($shopDomain), '1', 'EX', $ttl, 'NX');
            ...
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **LIFE-5** · P2 — Brand reinstall can leave webhook registration permanently “queued” if job dispatches fail silently
    - **Where:** app/Services/Shopify/BrandSignupService.php — `handleReinstall()` method
    - **Affects:** Any brand that reinstalls while the Redis queue is down or the Horizon worker is unavailable. Its `webhook_registration_state` stays `queued`, but no webhooks are ever registered, causing silent miss of order/payment webhooks.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Introduce a daily reconcile job (`ReconcileQueuedWebhookRegistrations`) that scans `professional_integrations` with `webhook_registration_state = 'queued'` and re‑dispatches `RegisterShopifyWebhooksJob` (or marks them `failed` after N days).
        - Add a stuck‑state alert for integrations that have been `queued` for > 1 hour.
    - **Technical:** `handleReinstall` updates the integration row to `webhook_registration_state = 'queued'`, then calls `dispatchInstallJobs`. Inside that method, each job dispatch is wrapped in a try‑catch that logs a warning but continues. If the entire queue is unreachable, all dispatches fail silently, yet the integration remains in `queued` forever. The canonical `daily reconcile job` pattern (`0de1f2f`) ensures that any state that depends on a vendor webhook has a sibling cron job that fills in missed deliveries — here the “delivery” is our own dispatch, but the same concept applies.
    - **Plain English:** After you plug in a new lamp, you flip the switch and assume it turned on. If the power is out you just walk away, and the lamp sits dark. A maintenance run should check the lamp once a day and try the switch again. The fix is a daily check that looks for lamps still marked “waiting for power” and tries the switch.
    - **Evidence:**
        ```php
        $integration->update([
            …
            'webhook_registration_state' => 'queued',
        ]);

        $this->dispatchInstallJobs((string) $integration->id);
        // Inside dispatchInstallJobs:
        foreach ($jobs as $jobClass) {
            try {
                $jobClass::dispatch($integrationId);
            } catch (\Throwable $e) {
                Log::warning('Failed to dispatch Shopify install job', …);
            }
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-6** · P3 — ShopifyMetrics logs omit professional ID and request context, breaking Nightwatch correlation
    - **Where:** app/Services/Shopify/Client/ShopifyMetrics.php — every log call
    - **Affects:** Operators debugging a single brand’s Shopify API experience; all logs appear as anonymous global traffic.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `professional_id` (or `brand_professional_id`) and `request_id` to the structured context accepted by `ShopifyMetrics` methods; pass them from callers where available.
        - At minimum, accept an `integration_id` so the log can join back to a tenant.
    - **Technical:** The `shopify.client.*` log lines are the primary diagnostic surface for Shopify API health, but they contain only `shop_domain`, `wait_ms`, `actual_cost`, etc. In a multi‑tenant system, Nightwatch cannot group these events by brand or trace them back to an API request. The canonical `Log-with-context` pattern requires that every `Log::` call from a vendor client carries a tenant identifier so operators can filter to “what happened for brand X.”
    - **Plain English:** The dashboard of a delivery van shows speed, fuel, and location, but never the van’s licence plate. When 50 vans are on the road and one is struggling, you can’t tell which van to help. The fix is to write the licence plate on every dashboard screen.
    - **Evidence:**
        ```php
        public function throttled(string $shopDomain, int $waitMs, int $attempt): void
        {
            Log::warning('shopify.client.throttled', [
                'shop_domain' => $shopDomain,
                'wait_ms' => $waitMs,
                'attempt' => $attempt,
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **LIFE-7** · P2 — Affiliate product selection seeding can create duplicate rows under concurrent operations
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php — `seedDefaultSelections()` method
    - **Affects:** Affiliates whose brand connection triggers seeding from two sources (e.g. connection job + manual UI action) simultaneously; they end up with duplicated product selections.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the check‑then‑insert loop in a database transaction with `lockForUpdate` on the relevant `affiliate_product_selections` rows, or add a `UNIQUE` constraint on `(affiliate_professional_id, brand_professional_id, shopify_product_gid)`.
        - Alternatively, replace the `in_array` + `create` with an upsert (`INSERT … ON CONFLICT DO NOTHING`) to make the operation idempotent.
    - **Technical:** `seedDefaultSelections` fetches all existing GIDs, then iterates over defaults and creates any that aren’t in the list. Without a lock or unique constraint, two concurrent calls will both observe the same missing GIDs, both exit the `in_array` guard, and both call `create`, producing duplicate rows. The canonical `lockForUpdate + UNIQUE` pattern would either serialize the two calls with a row lock or let the database reject the duplicate via a `UNIQUE` constraint.
    - **Plain English:** Two club bouncers each check the guest list at the same time. Both see that Alice isn’t on the list, so they both add her name. Now the list has two Alices. The fix is either to have one bouncer hold the list while the other waits, or to tell the paper “if Alice is already there, don’t write her again.”
    - **Evidence:**
        ```php
        $existingGids = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $affiliate->id)
            ->where('brand_professional_id', $brandProfessionalId)
            ->pluck('shopify_product_gid')->all();

        foreach ($defaultGids as $gid) {
            if (in_array($gid, $existingGids, true)) {
                continue;
            }
            AffiliateProductSelection::create([
                'affiliate_professional_id' => $affiliate->id,
                'brand_professional_id' => $brandProfessionalId,
                'shopify_product_gid' => $gid,
                …
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-8** · P3 — Hydrogen deployment dispatch may cause duplicate GitHub Actions runs if debounce window is exceeded
    - **Where:** app/Services/Shopify/HydrogenDeploymentService.php — `dispatchDeployment()` method
    - **Affects:** Brands that trigger a deploy (e.g. saving Oxygen credentials), then immediately trigger another before the 60‑second debounce expires — the second call skips, but if the first fails and is retried after >60s, a duplicate deploy lands.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Include a deterministic `client_payload` containing a UUID derived from the `professional_id` + a deploy counter, so GitHub Actions can recognise and skip duplicate runs.
        - Alternatively, extend the debounce TTL to match the maximum expected deploy time (e.g. 5 minutes).
    - **Technical:** The service uses a `Cache::add` debounce with a 60‑second lock to collapse rapid saves, but after the lock expires there is nothing stopping a retried dispatch from firing a second workflow. GitHub Actions `workflow_dispatch` does not natively deduplicate; if two dispatches land with the same inputs, they create two separate workflow runs, potentially resulting in two concurrent deployments that clash. The canonical `lockForUpdate + UNIQUE` pattern for external API calls suggests passing a client‑side idempotency key (here a unique `client_payload`) so the receiving side can ignore duplicates.
    - **Plain English:** It’s like asking a builder to start a job. You say “if you’re already building, ignore me” for one minute. But if you call back two minutes later, the builder starts a second crew on the same house. The fix is to include a unique job number with your request, so even if you call twice, the builder sees “oh, job #42 is already open” and doesn’t start a second gang.
    - **Evidence:**
        ```php
        if (! Cache::add("hydrogen:deploy:debounce:{$professionalId}", true, 60)) {
            Log::info('HydrogenDeployment: debounced rapid dispatch.');
            return;
        }
        // …
        $response = Http::withToken($token)
            ->withHeaders([…])
            ->post($url, [
                'ref' => $ref,
                'inputs' => ['professional_id' => $professionalId],
            ]);
        ```
    - `[DRAFT, confidence: 0.70]`

<!-- ═══ CHUNK: svc-rest-models ═══ -->

- [ ] **LIFE-1** · P1 — `upsertCatalogObject` uses non-deterministic idempotency key
    - **Where:** app/Services/Square/SquareApiClient.php:253
    - **Affects:** Square service push from Partna — every `pushServiceToSquare` call that retries creates a duplicate catalog object on Square rather than recognising the prior attempt.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Derive the key from `$service->id . ':' . ($catalogObject['version'] ?? 'new')` instead of `(string) Str::uuid()` so retries produce the same key.
        - Add a comment referencing **race-safe wallet credit** ('5735525') — idempotency key must be deterministic.
    - **Technical:** `Str::uuid()` generates a fresh value on every invocation. Square uses the `idempotency_key` to dedup retried mutations for 72 hours; a fresh UUID on retry means Square sees two independent create/update calls and applies both, leaving a stale catalog object that the original request already overwrote. At the scale target (200 brands × 50 services typical = 10K services), even a 0.1% retry rate produces 10 duplicate catalog objects per full-sync cycle, which then re-sync back into Partna as phantom rows.
    - **Plain English:** Every time Partna pushes a service change to Square, it includes a "receipt number" so Square knows "I've already handled this one." Right now that number is a random UUID that changes every time — so if the push is retried (network hiccup, Square busy), Square treats it as a brand-new request and creates a duplicate. The fix is to stamp the receipt number from the service's own ID instead of rolling dice.
    - **Evidence:**
        ```php
        public function upsertCatalogObject(Professional $professional, array $catalogObject): array
        {
            $response = $this->request($professional, 'POST', '/v2/catalog/object', [], [
                'idempotency_key' => (string) Str::uuid(),
                'object' => $catalogObject,
            ]);
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **LIFE-2** · P1 — Fresha API client missing vendor version pin
    - **Where:** app/Services/Fresha/FreshaApiClient.php (makeRequest method)
    - **Affects:** All Fresha API calls — every service sync, push, and token operation. A Fresha API version upgrade silently changes response shapes, breaking field mapping in `fetchServices` and `pushServiceToFresha`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `Fresha-Version` header (or equivalent) to `makeRequest()` pinned from `config('services.fresha.api_version')`.
        - Add the config key to `EnvCheckService::RECOMMENDED` so a missing version surfaces at deploy time.
        - Reference **Vendor API version pinning** (`9a9b107`) — every vendor SDK call must pin its API version.
    - **Technical:** SquareApiClient correctly pins `Square-Version: 2025-10-16` in its `makeRequest`. FreshaApiClient has no equivalent header. Without an explicit version pin, Fresha's API auto-upgrades apply new field names and response shapes; the field-mapping code in `fetchServices` (which already carries `// NOTE: Map these fields based on actual Fresha API response structure` comments) would silently break. At 200 brands, a single Fresha API version bump could corrupt sync for every connected brand simultaneously before anyone notices.
    - **Plain English:** Square gets told "use the API from October 2025" so we always see the same response shape. Fresha doesn't — it just takes whatever the latest version is. If Fresha changes how they name fields tomorrow, every brand connected to Fresha starts getting corrupted service data. The fix is one header line, same as Square already has.
    - **Evidence:**
        ```php
        // SquareApiClient::makeRequest — has version pin:
        ->withHeaders([
            'Square-Version' => '2025-10-16',
        ]);

        // FreshaApiClient::makeRequest — no version pin:
        $request = Http::acceptJson()
            ->asJson()
            ->timeout(30)
            ->withToken($accessToken);
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-3** · P1 — Fresha create/update service calls lack idempotency key
    - **Where:** app/Services/Fresha/FreshaApiClient.php (createService, updateService methods)
    - **Affects:** `FreshaServiceSyncService::pushServiceToFresha` — retried pushes create duplicate services on Fresha.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an idempotency key derived from the Partna `$service->id` to both `createService` and `updateService` request bodies.
        - Follow the **race-safe wallet credit** pattern (`5735525`) — deterministic key on every external write.
    - **Technical:** `pushServiceToFresha` does a version-conflict retry (fetches latest, re-upserts). If the second attempt succeeds but the first one also landed on Fresha after a network partition, Fresha has no idempotency key to recognise it as the same mutation. Without the key, Fresha may create a duplicate service row that syncs back into Partna as a phantom. Square's `upsertCatalogObject` includes an idempotency key (even if non-deterministic); Fresha's `createService`/`updateService` pass none at all.
    - **Plain English:** When Partna pushes a service update to Fresha and the network hiccups, it retries. Without a unique receipt number, Fresha can't tell "this retry is the same request I already processed" and may create a second copy. Every service gets doubled.
    - **Evidence:**
        ```php
        public function createService(Professional $professional, array $serviceData): array
        {
            return $this->request($professional, 'POST', '/v1/businesses/'.$this->businessId($professional).'/services', [], $serviceData);
        }

        public function updateService(Professional $professional, string $serviceId, array $serviceData): array
        {
            return $this->request($professional, 'PUT', '/v1/businesses/'.$this->businessId($professional).'/services/'.$serviceId, [], $serviceData);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-4** · P1 — Square sync transaction lacks row lock on integration cursor
    - **Where:** app/Services/Square/SquareServiceSyncService.php (applySquareSnapshot, DB::transaction)
    - **Affects:** Concurrent sync invocations (manual trigger + cron overlap, or two workers picking up the same job) race on `catalog_latest_time`, producing duplicate services or lost cursor updates.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `lockForUpdate()` on the integration row at the top of `syncFromSquare` before entering the transaction, following the **race-safe wallet credit** pattern (`5735525`).
        - Alternatively, use an advisory lock keyed on `'square_sync:' . $professional->id` so only one sync per professional runs at a time.
    - **Technical:** `syncFromSquare` reads `$integration->catalog_latest_time` outside the transaction, then enters `applySquareSnapshot` inside `DB::transaction` but never locks the integration row. Two concurrent syncs for the same professional both read the same `catalog_latest_time`, both fetch the same delta from Square, and both upsert the same services. The second upsert's `$syncedVariationIds` list and full-sync deletion logic produce torn state — services the first upsert deleted may be re-created, or the cursor may be set to an older `latest_time` depending on commit order.
    - **Plain English:** If two sync processes run at the same time for the same professional (say a manual "sync now" while the hourly cron is also running), they both read the same "last time I checked" bookmark, both pull the same changes from Square, and both try to write them at the same time. Like two people editing the same spreadsheet — whoever saves last wins, and the other's changes get scrambled.
    - **Evidence:**
        ```php
        // cursor read outside transaction:
        $beginTime = $beginTimeOverride;
        if ($beginTime === null && ! $fullSync && $integration->catalog_latest_time) {
            $beginTime = CarbonImmutable::parse($integration->catalog_latest_time)->toIso8601String();
        }

        try {
            $fetched = $this->squareApiClient->fetchAppointmentServiceVariations($professional, $beginTime);
            $stats = $this->applySquareSnapshot($professional, $fetched['services'] ?? [], $fullSync);

            // cursor write:
            $integration->catalog_latest_time = ...;
            $integration->save();
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **LIFE-5** · P1 — Cloudflare DNS ensureCname / upsertCname has TOCTOU race
    - **Where:** app/Services/Cloudflare/CloudflareDnsService.php (ensureCname, upsertCname, upsertTxt)
    - **Affects:** Subdomain provisioning during brand storefront setup — two concurrent deployments or a retry storm both call `ensureCname` and produce duplicate DNS records or skipped records on conflict.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `ensureCname` in a Redis lock scoped to the zone+name (`'cf_dns:' . $name`) so only one caller creates the record. Follow the **single-flight lock** pattern from `SquareTokenService::refreshAccessToken`.
        - For `upsertCname` / `upsertTxt`, use the Cloudflare API's built-in idempotency by passing a deterministic `id` or using `PATCH` only (which is naturally idempotent), rather than `findRecord` then decide create-vs-patch.
    - **Technical:** `ensureCname` does `findRecord` then `post` — two callers racing both call `findRecord`, both see `null`, both `post`. Cloudflare creates the first CNAME and returns success; the second `post` either fails with a duplicate error (silently swallowed, returns `null`) or creates a second record with a suffixed name depending on Cloudflare's duplicate-handling behaviour. The caller receives `null` and assumes DNS provisioning failed, when the record actually exists. At 200 brands, brand storefront setup is the primary path that hits this — every concurrent deploy is a race.
    - **Plain English:** When a brand's storefront is being set up, the code checks "does this DNS entry already exist?" and if not, creates it. If two setup processes run at the same time, both check, both see "nope, doesn't exist," and both try to create it. One succeeds, the other either fails silently or creates a duplicate — and the setup thinks DNS failed when it actually worked.
    - **Evidence:**
        ```php
        public function ensureCname(string $name, string $target, bool $proxied = true): ?string
        {
            if (! $this->hasCredentials()) {
                return null;
            }

            $existing = $this->findRecord('CNAME', $name);
            if ($existing !== null) {
                return $existing['id'];
            }

            $response = Http::withToken($this->apiToken)
                ->post($this->zonesUrl('/dns_records'), [...]);
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-6** · P2 — Fresha syncFromFresha lacks row lock on cursor, same race class as Square
    - **Where:** app/Services/Fresha/FreshaServiceSyncService.php (syncFromFresha)
    - **Affects:** Same concurrent-sync race as LIFE-4 but for Fresha integrations. Lower blast radius today (fewer Fresha brands) but same code shape.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Apply the same `lockForUpdate` or advisory-lock fix from LIFE-4 to `syncFromFresha`.
    - **Technical:** Identical TOCTOU shape: reads `catalog_latest_time` outside the transaction, fetches delta from Fresha, enters `DB::transaction` without locking the integration row. Two concurrent syncs produce torn state. Impact at scale: as Fresha adoption grows toward the 200-brand target, this becomes P1.
    - **Plain English:** Same spreadsheet-editing problem as the Square sync — if two Fresha syncs run at once for the same professional, the catalog cursor gets scrambled.
    - **Evidence:**
        ```php
        $beginTime = $beginTimeOverride;
        if ($beginTime === null && ! $fullSync && $integration->catalog_latest_time) {
            $beginTime = $integration->catalog_latest_time->toIso8601String();
        }

        try {
            $result = $this->freshaApiClient->fetchServices($professional, $fullSync ? null : $beginTime);
            // ...
            DB::transaction(function () use ($professional, $rows, &$syncedCount, &$deletedCount) {
                // upserts without locking integration row
            });
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **LIFE-7** · P2 — KickApiClient swallows auth failure; callers can't distinguish "no handles live" from "auth broken"
    - **Where:** app/Services/Streaming/KickApiClient.php:getLiveHandles
    - **Affects:** Live status display on public profiles — a revoked/expired Kick OAuth token silently shows every streamer as offline with no alerting.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Throw a typed exception (e.g. `StreamingAuthException`) on auth failure instead of returning `[]`. Let the poller catch it and trigger Nightwatch alerting.
        - Follow the **distinct logs for distinct failure modes** pattern (`#STRIPE-2`, `35c6f31`) — an empty array currently means both "no one is live" and "auth is broken."
    - **Technical:** `getLiveHandles` returns `[]` when `$this->tokens->getToken('kick')` is null and logs `Log::critical('streaming.auth_failure')`. The caller (`LiveStatusPoller::pollKick`) writes `isLive = false` for every handle in the batch — the public profile shows every Kick streamer as offline. At the scale target (~40K daily notifications and public profile loads), a Kick auth failure during a peak streaming window silently blanks the live-status feature for every handle on the platform. `Log::critical` is a breadcrumb; Nightwatch alerts on exceptions, not log queries. The caller needs a distinguishable return so it can raise an exception.
    - **Plain English:** When Kick's login token expires, instead of alerting us that something's broken, the system just tells everyone "all your streamers are offline." Every fan sees empty live indicators. The fix is to throw a specific error when auth fails so Nightwatch pages us, instead of pretending nothing's wrong.
    - **Evidence:**
        ```php
        $token = $this->tokens->getToken('kick');
        if (! $token) {
            Log::critical('streaming.auth_failure', ['platform' => 'kick']);
            return [];
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-8** · P2 — TwitchApiClient has same swallowed auth failure as Kick
    - **Where:** app/Services/Streaming/TwitchApiClient.php:getLiveHandles
    - **Affects:** Same as LIFE-7 but for Twitch — larger blast radius (more Twitch streamers than Kick).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply identical fix as LIFE-7: throw `StreamingAuthException` instead of returning `[]`.
    - **Technical:** Same shape — `getToken('twitch')` returns null → `Log::critical` → return `[]`. At the scale target, Twitch is the dominant platform; a silent auth failure there blanks live indicators for the majority of streaming handles.
    - **Plain English:** Same as Kick — Twitch auth failure silently shows everyone as offline instead of alerting us.
    - **Evidence:**
        ```php
        $token = $this->tokens->getToken('twitch');
        if (! $token) {
            Log::critical('streaming.auth_failure', ['platform' => 'twitch']);
            return [];
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-9** · P2 — Square and Fresha API retry loops use linear backoff, no exponential
    - **Where:** app/Services/Square/SquareApiClient.php:request (line ~172); app/Services/Fresha/FreshaApiClient.php:request
    - **Affects:** 429 throttling during peak sync windows — at 200 brands with hourly syncs, a burst of brand signups triggers a retry storm against Square/Fresha APIs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the fixed 1s wait with exponential backoff: `$wait = max(1000, ((int) ($response->header('Retry-After') ?? 1)) * 1000) * pow(2, $attempt)`.
        - Cap max wait at 30s so a single 429 doesn't stall the job for minutes.
    - **Technical:** Both clients retry 429 with `max(1000, Retry-After * 1000)` microseconds — the same wait on every attempt. If Square/Fresha rate-limit by IP or account-wide, three retries at the same interval all hit the same throttled window, and the request fails permanently after `$maxRetries`. At the scale target, hourly sync for 200 brands produces a dense burst window; linear backoff guarantees every throttled brand exhausts its retries simultaneously. Exponential backoff spreads the retry load.
    - **Plain English:** When Square says "too fast, wait a second," the code waits exactly one second and tries again. If Square is still busy, it waits one more second — same as before. The third attempt also fails for the same reason. Spreading those wait times out (1s, then 2s, then 4s) gives Square more breathing room and more retries succeed.
    - **Evidence:**
        ```php
        // SquareApiClient::request:
        if ($response->status() === 429 && $attempt < $maxRetries) {
            $wait = max(1000, ((int) ($response->header('Retry-After') ?? 1)) * 1000);
            usleep($wait * 1000);
            $attempt++;
            continue;
        }

        // FreshaApiClient::request — identical:
        if ($response->status() === 429 && $attempt < $maxRetries) {
            $wait = max(1000, ((int) ($response->header('Retry-After') ?? 1)) * 1000);
            usleep($wait * 1000);
            $attempt++;
            continue;
        }
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **LIFE-10** · P2 — Cloudflare DNS service returns null on failure; callers can't distinguish "provisioned elsewhere" from "API down"
    - **Where:** app/Services/Cloudflare/CloudflareDnsService.php (ensureCname, upsertCname, upsertTxt — all return `?string`)
    - **Affects:** Hydrogen storefront subdomain provisioning — a Cloudflare API 5xx during brand deploy silently returns null, the deploy continues without DNS, and the storefront is unreachable with no alert.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Throw a typed `CloudflareDnsException` on non-404 API failures. Only return null for the expected "credentials not configured" dev path.
        - The calling job should catch the exception and either retry with backoff or fail the deployment with a clear error.
        - Reference **distinct logs for distinct failure modes** (`#STRIPE-2`) — `null` currently conflates "dev mode, no credentials", "findRecord failed", "create failed", and "patch failed."
    - **Technical:** Every public method in `CloudflareDnsService` returns `null` on failure, and every caller treats null as "DNS provisioning didn't happen — skip it." The `hasCredentials()` guard is the only legitimate null path (local dev). Cloudflare 5xx, network timeout, and permission errors all return null with only a `Log::error` breadcrumb. At 200 brands, a single Cloudflare outage during a deploy wave silently leaves storefront subdomains unresolvable with no Nightwatch alert.
    - **Plain English:** When the DNS service fails to create a subdomain entry (Cloudflare is down, network error, bad permissions), it quietly returns "nothing" and the deployment continues as if nothing's wrong. The brand's new storefront goes live but nobody can reach it. The code treats "we're in dev mode with no credentials" the same as "Cloudflare is on fire" — they should be very different outcomes.
    - **Evidence:**
        ```php
        if (! $response->successful()) {
            Log::error('CloudflareDnsService: failed to create CNAME record.', [
                'name' => $name,
                'target' => $target,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **LIFE-11** · P3 — CloudflareDnsService logs full API response bodies — log index pressure at scale
    - **Where:** app/Services/Cloudflare/CloudflareDnsService.php (all error log calls)
    - **Affects:** Nightwatch log ingestion volume — every failed DNS call logs the full Cloudflare response body.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Truncate the logged body to first 500 chars. Full body is needed for debugging — store it on the failing job's DB record instead (if one exists).
        - Follow the **heavy log payloads** observation from category 10 — fan-out jobs should never log full vendor responses.
    - **Technical:** Every `Log::error` in `CloudflareDnsService` includes `'body' => $response->body()`. Cloudflare error responses can include verbose HTML or JSON payloads. At 200 brands with periodic subdomain provisioning, DNS record updates, and TXT verification, failed calls produce unbounded log payloads. Not an issue at 10 brands but worth trimming before the scale target.
    - **Plain English:** When a DNS call fails, the system writes the entire error response (which can be kilobytes of HTML) into the log. At small scale it's fine; at 200 brands with hundreds of DNS operations, the log storage fills up with debug noise that nobody reads.
    - **Evidence:**
        ```php
        Log::error('CloudflareDnsService: failed to create CNAME record.', [
            'name' => $name,
            'target' => $target,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
        ```
    - `[DRAFT, confidence: 0.70]`

<!-- ═══ CHUNK: jobs ═══ -->

- [ ] **#LIFE-1** · P1 — ExportFinalizerJob sends email before marking audit completed; retry re-sends duplicate email
    - **Where:** app/Jobs/Exports/ExportFinalizerJob.php (handle method: Mail::send before markCompleted)
    - **Affects:** Any brand professional requesting a commission export. At scale (200 brands × occasional exports), a transient crash during finalization sends duplicate "Your export is ready" emails, which is confusing and erodes trust.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `Mail::send` after `$audit->markCompleted(...)` so a crash between send and mark retries cleanly — the second run sees STATUS_COMPLETED and returns immediately.
        - Or adopt the broadcast-email pattern from `SendStaffBroadcastEmailToSubscriberJob`: insert a receipt row before sending, and gate on the receipt for at-most-once delivery.
    - **Technical:** The STATUS_COMPLETED guard at the top of `handle()` only catches fully-completed runs. A retry that lands between `Mail::send` and `markCompleted` passes the guard (status is still "processing"), re-uploads the file (harmless overwrite), and re-sends the email. The canonical Stripe payout fix (`#STRIPE-2`) established that functions with multiple outcomes need distinct paths — here the email send and the state transition are ordered for at-least-once email, but the guard only catches the terminal state, not the mid-flight crash window.
    - **Plain English:** Imagine a waiter who marks an order "delivered" only after the customer has signed for it. If the waiter trips between handing over the food and marking the delivery, the kitchen sees the order as still pending and sends a second plate. The fix is to mark the order delivered first, then hand over the food — if something goes wrong after marking, the customer can ask for a replacement, which is better than getting two plates and being confused.
    - **Evidence:**
        ```php
        Mail::to($audit->recipient_email)->send(new CommissionExportReadyMail(
            signedUrl: $signedUrl,
            role: $audit->role,
            format: $audit->format,
            filters: $audit->filters ?? [],
            recordCount: $meta['row_count'],
            ttlDays: $ttlDays,
            expiresAt: now()->addDays($ttlDays),
        ));

        $audit->markCompleted(
            filePath: $remoteFinalPath,
            size: $meta['size'],
            sha256: $meta['sha256'],
        );
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#LIFE-2** · P1 — ExportProfessionalDataJob has read-modify-write race on status transition
    - **Where:** app/Jobs/Gdpr/ExportProfessionalDataJob.php (handle method: status check then markProcessing)
    - **Affects:** Any professional requesting a GDPR data export. At pilot scale this is rare, but a race between two dispatches (e.g. Horizon scale-out or retry overlapping original) could double-process the export — uploading two zips, sending two emails, and leaving the audit row in an indeterminate state.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the status check + `markProcessing()` in a `DB::transaction` with `lockForUpdate` on the audit row, matching the canonical `lockForUpdate` pattern from `SendEnquiryNotificationJob` (which already does this correctly).
        - Or use a single atomic `UPDATE ... WHERE status NOT IN ('completed','failed')` query and check `rowCount()` before proceeding.
    - **Technical:** The guard `in_array($audit->status, [COMPLETED, FAILED])` and the subsequent `$audit->markProcessing()` are two separate statements with no lock between them. Two concurrent workers can both read `status = 'queued'`, both pass the guard, and both call `markProcessing()`. The second worker then proceeds through the entire export pipeline — zip creation, R2 upload, email send — duplicating work. The canonical `lockForUpdate + UNIQUE` pattern (`5735525`) requires a row-level lock for this exact read-modify-write shape.
    - **Plain English:** Two receptionists both check the same appointment book at the same time, see the slot is empty, and both book a different client into it. Now two people show up for one slot. The fix is to have only one person hold the pen at a time — the second person has to wait and when they look, they see it's already filled.
    - **Evidence:**
        ```php
        if (in_array($audit->status, [DataExportAudit::STATUS_COMPLETED, DataExportAudit::STATUS_FAILED], true)) {
            return;
        }

        // Professional may have been hard-deleted between dispatch and run ...

        $audit->markProcessing();
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#LIFE-3** · P2 — Gdpr/RedactCustomerJob has read-modify-write race on status transition (same shape as LIFE-2)
    - **Where:** app/Jobs/Shopify/Gdpr/RedactCustomerJob.php (handle method: status check then update)
    - **Affects:** GDPR redaction requests from Shopify. A race between two dispatches could double-anonymise a customer — the second run sees `redacted_at` already set and skips, so impact is limited, but the `gdpr_requests` status and professional-level cleanup (`email_subscriptions` delete, `booking_events` scrub) could race.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same fix as LIFE-2: wrap the status guard + `STATUS_PROCESSING` update in a `lockForUpdate` transaction on the GdprRequest row.
    - **Technical:** Same read-modify-write anti-pattern as LIFE-2. The status check and status update are not atomic. The `whereNull('redacted_at')` guard on the Customer query provides some protection for the Customer row itself, but the sibling cleanup paths (`email_subscriptions` delete, `booking_events` scrub) are executed unconditionally and could be double-run. The canonical `lockForUpdate + UNIQUE` pattern applies.
    - **Plain English:** Same "two receptionists booking the same slot" scenario as the GDPR export job. Here, the second receptionist sees the appointment's already been handled (because the customer's file is stamped "redacted"), but they still go ahead and clean the waiting room and shred documents that the first receptionist already handled. No harm done, but it's wasted work that could collide under heavier load.
    - **Evidence:**
        ```php
        if (in_array($gdpr->status, [GdprRequest::STATUS_COMPLETED, GdprRequest::STATUS_SKIPPED], true)) {
            return;
        }

        $gdpr->update(['status' => GdprRequest::STATUS_PROCESSING]);
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#LIFE-4** · P2 — Gdpr/RedactShopJob has read-modify-write race on status transition (same shape as LIFE-2/LIFE-3)
    - **Where:** app/Jobs/Shopify/Gdpr/RedactShopJob.php (handle method: status check then update)
    - **Affects:** Shopify shop/redact GDPR requests. A race could double-execute the narrow-scope cleanup — access token is already nulled on the first pass, but `AffiliateProductSelection` delete and customer anonymisation could race.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same fix: `lockForUpdate` on the GdprRequest row before the status transition.
    - **Technical:** Identical read-modify-write gap to LIFE-2 and LIFE-3. The access token nullification on first run provides a partial guard (subsequent runs would 401 or skip), but the `AffiliateProductSelection::delete()` and `anonymiseShopifyCustomers()` are idempotent at the data level — the real risk is wasted I/O at scale, not corruption. Still, the canonical pattern should be applied uniformly.
    - **Plain English:** Same pattern — two workers both start the cleanup, one finishes first (disconnecting the power), the second arrives and finds everything already turned off but still walks through all the rooms flipping switches that are already down. Inefficient but not destructive.
    - **Evidence:**
        ```php
        if (in_array($gdpr->status, [GdprRequest::STATUS_COMPLETED, GdprRequest::STATUS_SKIPPED], true)) {
            return;
        }

        $gdpr->update(['status' => GdprRequest::STATUS_PROCESSING]);
        ```
    - `[DRAFT, confidence: 0.70]`

- [ ] **#LIFE-5** · P2 — Gdpr/ExportCustomerDataJob has read-modify-write race on status transition (same shape as LIFE-2)
    - **Where:** app/Jobs/Shopify/Gdpr/ExportCustomerDataJob.php (handle method: status check then update)
    - **Affects:** GDPR customer data export requests from Shopify. Same race shape as LIFE-2 — duplicate email to the merchant, duplicate processing.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same fix: `lockForUpdate` on the GdprRequest row.
    - **Technical:** Same read-modify-write gap. The guard and the `STATUS_PROCESSING` update happen in separate statements without a lock.
    - **Plain English:** Same two-receptionists problem, different appointment type. The GDPR data export desk has the same booking-book double-check issue.
    - **Evidence:**
        ```php
        if (in_array($gdpr->status, [GdprRequest::STATUS_COMPLETED, GdprRequest::STATUS_SKIPPED], true)) {
            return;
        }

        $gdpr->update(['status' => GdprRequest::STATUS_PROCESSING]);
        ```
    - `[DRAFT, confidence: 0.70]`

- [ ] **#LIFE-6** · P2 — PushServiceToFreshaJob swallowed retry exhaustion — Nightwatch never sees permanent failures
    - **Where:** app/Jobs/Fresha/PushServiceToFreshaJob.php (failed method)
    - **Affects:** Professional accounts using Fresha booking integration. At 200 brands with ~5 using Fresha, the blast radius is small, but a permanently-failing push means service updates silently stop syncing — the professional's Fresha catalog drifts from Partna with no alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e);` before `Log::warning(...)` in the `failed()` method, matching the canonical `Log-with-context` pattern used by Stripe jobs (e.g. `ExecuteCommissionPayoutJob::failed()`).
    - **Technical:** `Log::warning` writes a structured breadcrumb to cloud logs, but Nightwatch alerting triggers on exceptions and auto-detected slow jobs/routes, NOT on log queries. Without `report($e)`, the exception never reaches Laravel's exception handler, so Nightwatch never fires an alert for a permanently-exhausted Fresha push job. The canonical Stripe payout fix (`#STRIPE-2`, `35c6f31`) established that every `failed()` must call `report($e)` so retry exhaustion is observable by notification_id/professional_id.
    - **Plain English:** When the Fresha sync completely fails after all retries, it writes a note in a logbook but doesn't turn on the warning light on the operations dashboard. If nobody happens to be reading that specific logbook page, the Fresha integration silently breaks and nobody notices until a professional complains. The fix is to also flip the warning switch.
    - **Evidence:**
        ```php
        public function failed(\Throwable $e): void
        {
            Log::warning('Fresha push service job failed', [
                'service_id' => $this->serviceId,
                'action' => $this->action,
                'message' => $e->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#LIFE-7** · P2 — SyncFreshaCatalogDeltaJob swallowed retry exhaustion — Nightwatch never sees permanent failures
    - **Where:** app/Jobs/Fresha/SyncFreshaCatalogDeltaJob.php (failed method)
    - **Affects:** Same as LIFE-6 — Fresha catalog delta sync failures are invisible to Nightwatch.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e);` before `Log::warning(...)` in `failed()`.
    - **Technical:** Same missing `report($e)` pattern as LIFE-6. The catalog delta sync fetches Fresha's service catalog and syncs it into Partna — a permanent failure means the professional's services are stale indefinitely.
    - **Plain English:** Same silent-warning-light problem but for the catalog sync direction (Fresha → Partna) instead of the push direction (Partna → Fresha).
    - **Evidence:**
        ```php
        public function failed(\Throwable $e): void
        {
            Log::warning('Fresha catalog sync job failed', [
                'business_id' => $this->businessId,
                'begin_time' => $this->beginTime,
                'full_sync' => $this->fullSync,
                'message' => $e->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#LIFE-8** · P2 — PushServiceToSquareJob swallowed retry exhaustion — Nightwatch never sees permanent failures
    - **Where:** app/Jobs/Square/PushServiceToSquareJob.php (failed method)
    - **Affects:** Professional accounts using Square booking integration. Same blast radius and drift risk as LIFE-6 but for Square.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e);` before `Log::warning(...)` in `failed()`.
    - **Technical:** Same missing `report($e)` pattern. Canonical: every `failed()` must call `report($e)` so Nightwatch surfaces retry exhaustion.
    - **Plain English:** Square's version of the silent-warning-light problem from LIFE-6.
    - **Evidence:**
        ```php
        public function failed(\Throwable $e): void
        {
            Log::warning('Square push service job failed', [
                'service_id' => $this->serviceId,
                'action' => $this->action,
                'message' => $e->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#LIFE-9** · P2 — SyncSquareCatalogDeltaJob swallowed retry exhaustion — Nightwatch never sees permanent failures
    - **Where:** app/Jobs/Square/SyncSquareCatalogDeltaJob.php (failed method)
    - **Affects:** Same as LIFE-8, catalog sync direction.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e);` before `Log::warning(...)` in `failed()`.
    - **Technical:** Same missing `report($e)` pattern.
    - **Plain English:** Square's catalog-sync version of the same silent-warning-light problem.
    - **Evidence:**
        ```php
        public function failed(\Throwable $e): void
        {
            Log::warning('Square catalog sync job failed', [
                'merchant_id' => $this->merchantId,
                'begin_time' => $this->beginTime,
                'full_sync' => $this->fullSync,
                'message' => $e->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#LIFE-10** · P2 — CheckStreamingLiveStatusJob swallowed retry exhaustion — Nightwatch never sees permanent failures
    - **Where:** app/Jobs/Streaming/CheckStreamingLiveStatusJob.php (failed method)
    - **Affects:** Streaming live-status polling (Twitch/Kick). At 200 brands with ~50 using streaming blocks, this runs every 2 minutes. A permanently-failed poll means live-status badges on affiliate sitepages go stale with no alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e);` before `Log::error(...)` in `failed()`, matching the canonical `Log-with-context` pattern.
    - **Technical:** Same missing `report($e)` pattern. This job runs on `tries=1`, so `failed()` fires immediately on any exception — making the missing `report($e)` more impactful because a single transient error (e.g. Twitch API 5xx) kills the polling cycle silently.
    - **Plain English:** The job that checks whether streamers are live runs every 2 minutes. If it completely fails, it writes a note in the logbook but doesn't turn on the warning light. The next 2-minute cycle will try again, but if there's a persistent problem (like an API key expiring), nobody finds out until streamers or their viewers complain about stale "offline" badges.
    - **Evidence:**
        ```php
        public function failed(\Throwable $e): void
        {
            Log::error('streaming.job_failed', ['message' => $e->getMessage()]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#LIFE-11** · P3 — ProcessShopifyShopUpdateJob logs warning without report() when integration record is missing
    - **Where:** app/Jobs/Shopify/ProcessShopifyShopUpdateJob.php (handle method, integration-not-found branch)
    - **Affects:** Shopify shop/update webhook processing. A missing integration is unexpected — it means a webhook arrived for a shop we think we're not connected to. The warning is logged but Nightwatch won't alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report(new \RuntimeException(...))` alongside the `Log::warning` so Nightwatch surfaces the anomaly. This is an unexpected state (webhook for a non-existent integration) and should trigger an alert, not just a silent log entry.
    - **Technical:** The missing-integration branch is an anomaly — it means Shopify sent a shop/update webhook for a professional_id that has no matching ProfessionalIntegration row. This could indicate a Shopify app reinstall that bypassed our OAuth flow, or a data integrity issue. `Log::warning` alone won't trigger a Nightwatch alert (Nightwatch alerts on exceptions, not log queries). The canonical `Log-with-context` pattern requires surfacing anomalies as exceptions so they're visible in the operations dashboard.
    - **Plain English:** If Shopify sends us a "shop updated" notification for a store we don't think is connected to us, that's strange — it's like getting a package delivery notification for a house you don't own. Right now, that strangeness gets written in a logbook but nobody gets paged. The fix is to also sound the alarm so the operations team can investigate.
    - **Evidence:**
        ```php
        if (! $integration) {
            Log::warning('Shopify shop/update: no integration record found.', [
                'professional_id' => $this->professionalId,
            ]);

            return;
        }
        ```
    - `[DRAFT, confidence: 0.70]`

- [ ] **#LIFE-12** · P2 — CreateShopifyAffiliateDiscountJob has TOCTOU race between discount-existence check and creation
    - **Where:** app/Jobs/Shopify/CreateShopifyAffiliateDiscountJob.php (handle method: automaticDiscountAlreadyInstalled check then createAutomaticDiscount)
    - **Affects:** Brands connecting Shopify at scale (200 brands). Two concurrent dispatches of the OAuth install chain could both check for existing discount, both see none, and both attempt `discountAutomaticAppCreate`. Shopify likely rejects the duplicate, but the second attempt wastes an API call and logs a confusing error. `ShouldBeUnique` narrows the window to the `uniqueFor` expiry edge case plus any cross-worker race.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the check + create in a single try/catch that handles the Shopify "discount already exists" error gracefully — treat it as success rather than throwing.
        - Or pass an idempotency key derived from the integration ID + function ID to `discountAutomaticAppCreate` so Shopify dedupes the second call server-side.
    - **Technical:** The pattern `if exists → skip; else → create` has a TOCTOU window between the existence query and the create mutation. `ShouldBeUnique` with `uniqueFor=300` prevents same-integration concurrency within a 5-minute window, but if the unique lock expires just as the create fires (edge case), or if Shopify's eventual-consistency index hasn't caught up to a prior create, the second attempt fails. The canonical `idempotency key` pattern requires passing a deterministic key to the vendor so the platform handles dedup, rather than relying on client-side check-then-create.
    - **Plain English:** Imagine two assistants both calling a restaurant to book the same table at the same time. Both call, both ask "is Table 5 free?", both hear "yes", and both try to book it. The restaurant's system catches the double-booking, but one assistant gets an error message and has to clean it up. The fix is to give each booking a unique confirmation number so the restaurant can tell it's the same booking attempt and just say "already confirmed" instead of "error."
    - **Evidence:**
        ```php
        if ($this->automaticDiscountAlreadyInstalled($shopDomain, $accessToken, $apiVersion, $functionId)) {
            $integration->mergeProviderMetadata(['partna_discount_state' => 'registered']);
        } else {
            $this->createAutomaticDiscount($shopDomain, $accessToken, $apiVersion, $functionId);
            $integration->mergeProviderMetadata(['partna_discount_state' => 'registered']);
        }
        ```
    - `[DRAFT, confidence: 0.65]`

- [ ] **#LIFE-13** · P2 — CreateShopifyCollectionsJob has TOCTOU race between collection-existence check and creation (same shape as LIFE-12)
    - **Where:** app/Jobs/Shopify/CreateShopifyCollectionsJob.php (findOrCreateCollection method: COLLECTIONS_QUERY existence check then COLLECTION_CREATE)
    - **Affects:** Brands connecting Shopify. Same TOCTOU window as LIFE-12. Two concurrent dispatches could both query for collection existence, both find none, and both create — producing duplicate collections. `ShouldBeUnique` mitigates but doesn't fully close.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same as LIFE-12: catch the "collection already exists" error from Shopify and treat it as success, or use idempotency keys.
    - **Technical:** Same check-then-create TOCTOU anti-pattern. Shopify's collection title namespace is per-store, so duplicate creations would produce two collections with the same title — confusing for the brand and potentially breaking the collection-handle metafield references that downstream jobs depend on. The canonical `idempotency key` pattern should apply.
    - **Plain English:** Same two-assistants-booking-a-table problem, but for creating collections on a Shopify store. If two helpers both create the same collection, the brand ends up with duplicate folders in their Shopify admin, and the downstream "which collection is the Active Products one?" lookup picks one arbitrarily — possibly the empty duplicate.
    - **Evidence:**
        ```php
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::COLLECTIONS_QUERY, [
            'query' => "title:'{$def['title']}'",
            'first' => 1,
        ]);

        $edges = $response->json('data.collections.edges', []);
        if (! empty($edges)) {
            // ... return existing
        }

        // Create the collection
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::COLLECTION_CREATE, [
            'input' => $input,
        ]);
        ```
    - `[DRAFT, confidence: 0.65]`

- [ ] **#LIFE-14** · P2 — CreateShopifySalesChannelJob has TOCTOU race between publication-existence check and creation (same shape as LIFE-12/LIFE-13)
    - **Where:** app/Jobs/Shopify/CreateShopifySalesChannelJob.php (handle method: findExistingPublicationId then PUBLICATION_CREATE)
    - **Affects:** Brands connecting Shopify. Same TOCTOU pattern. Duplicate publication creation is less harmful (Shopify likely rejects the duplicate name), but the wasted API call and potential error log noise are avoidable.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same approach: treat "publication already exists" as success, or use idempotency keys.
    - **Technical:** Same check-then-create gap. The sales channel publication name is unique per store on Shopify, so a duplicate would be rejected with a userError — the job would throw on the second attempt. This is caught by the outer `try/catch` and triggers a retry, which then finds the first run's publication and succeeds. So the blast radius is one wasted retry cycle, not a permanent failure. Lower severity than LIFE-12/LIFE-13 but same root cause.
    - **Plain English:** Same booking-two-tables problem but for a publication channel. Less harmful because Shopify's system catches the duplicate, but it still wastes an API call and a retry cycle that could delay the brand's setup.
    - **Evidence:**
        ```php
        $existingPublicationId = $this->findExistingPublicationId($shopDomain, $accessToken, $apiVersion);
        if ($existingPublicationId !== null) {
            // ... return early
        }

        // Create publication
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::PUBLICATION_CREATE, [
            'input' => ['autoPublish' => false],
        ]);
        ```
    - `[DRAFT, confidence: 0.60]`

<!-- ═══ CHUNK: ctrl-prof-a ═══ -->

- [ ] **LIFE-1** · P0 — `ShopifyIntegrationController::connect()` creates integration row in `queued` state with no automated reconcile when all job dispatches fail
    - **Where:** app/Http/Controllers/Api/Professional/Brand/ShopifyIntegrationController.php (connect method)
    - **Affects:** Brands connecting Shopify — the integration row is created with `webhook_registration_state = 'queued'`, but if all five jobs fail to dispatch (e.g. Redis queue down), the brand sees `connected: true` with no path to recovery except manual `retrySetup`. At 200 brands, one queue outage during a wave of connects leaves dozens of brands silently broken.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a daily reconcile job (`ReconcileStuckShopifyInstallsJob`) that finds integrations stuck in `queued` or `failed` for > 2h and re-dispatches the missing jobs.
        - Guard the `connect` response: if `webhook_registration_queued` is false after all five dispatches, return a 202 Accepted with a clear `retry_setup_url` rather than 200 with `connected: true`.
    - **Technical:** Pattern match to `daily reconcile job` (`0de1f2f`). Any state that depends on a vendor webhook or async job must have a reconcile job that catches missed deliveries. Here the state machine is `queued → registered/partial/failed` but the only recovery path is manual — a human must notice and click "retry setup." Supabase RLS prevents cross-brand reads, so a single reconcile job iterating `webhook_registration_state IN ('queued','failed') AND updated_at < now() - interval '2 hours'` is safe and cheap.
    - **Plain English:** Imagine a restaurant where the host seats you, marks your table as "waiting for waiter," but the pager system is down so no waiter ever comes. You sit there thinking everything's fine while your order never gets taken. The fix is a manager who walks the floor every hour checking for tables that have been waiting too long. Right now there's no manager — a brand whose Shopify connection jobs failed to queue will sit in limbo until they manually click "retry."
    - **Evidence:**
        ```php
        $webhookRegistrationQueued = true;
        $jobs = [
            RegisterShopifyWebhooksJob::class,
            CreateStorefrontAccessTokenJob::class,
            CreateShopifyMetafieldsJob::class,
            CreateShopifySalesChannelJob::class,
            SyncShopifyBrandDesignJob::class,
        ];

        foreach ($jobs as $jobClass) {
            try {
                $jobClass::dispatch((string) $integration->id);
            } catch (\Throwable $e) {
                $webhookRegistrationQueued = false;
                Log::warning('Failed to dispatch Shopify install job', [...]);
            }
        }

        return $this->success([
            'connected' => true,
            ...
            'webhook_registration_queued' => $webhookRegistrationQueued,
        ]);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **LIFE-2** · P1 — `BrandAffiliateInviteController::claim()` performs three sequential state mutations outside a transaction with no compensating action on partial failure
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateInviteController.php (claim method)
    - **Affects:** Affiliates claiming invites — if `claimInvite` succeeds but `transition` throws `InvalidAccountTypeTransition`, the invite is consumed (status=accepted) but the account type stays unchanged. The affiliate gets a 422, can't retry the token (it's claimed), and is stuck in a broken state.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap claim + transition + site sync in a `DB::transaction()` so partial failure rolls back.
        - If the invite service's `claimInvite` uses a separate connection or external call, add a compensating `releaseInvite` call in the `catch (InvalidAccountTypeTransition)` block so the token is freed for retry.
        - Log distinctly when the transition fails after a successful claim — currently the 422 surface hides the "claimed but broken" state from Nightwatch.
    - **Technical:** Pattern match to `#STRIPE-2` distinct-log and `dcdb3b4` in-flight cancellation. The `claim` method is a mini-aggregate: claim the invite, transition account type, sync site settings, invalidate caches. If step 2 fails, rows from step 1 persist outside a transaction. Two concurrent claim attempts on the same token could also both pass `findByToken` before either's `claimInvite` mutates the row — the invite service's own locking should be verified but isn't visible in this file.
    - **Plain English:** Like a hotel check-in where the front desk marks your room as occupied, but then the key-card machine breaks. You're told "sorry, come back later" but your room is already marked taken — you can't check in again and the room sits empty. The fix is to either do all three steps as one atomic operation, or if the key-card machine breaks, un-mark the room so you can try again.
    - **Evidence:**
        ```php
        try {
            $claimedInvite = $inviteService->claimInvite($invite, $professional);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        // §28.12: flip account_type to partner ...
        try {
            $transitionService->transition(
                $professional->fresh() ?? $professional,
                AccountType::Partner
            );
        } catch (InvalidAccountTypeTransition $e) {
            return $this->error($e->getMessage(), 422);
        }

        $site = Site::query()->where('professional_id', $professional->id)->first();
        if ($site) {
            $this->syncSiteBrandPartnerSettings($site, $brandPartnerLinks, (string) $professional->id);
            app(ProfessionalCacheService::class)->invalidateProfessional($professional);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-3** · P1 — `BrandStoreSettingsController::update()` commits local DB state before Shopify metafield sync — creates drift on vendor failure
    - **Where:** app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php (update method)
    - **Affects:** Brands updating store settings (commission rate, accent color, theme variant, product image ratio, custom photos). Local state persists even when Shopify metafield writes fail — Hydrogen reads from Shopify metafields, so the brand sees stale values on their storefront while the dashboard shows the new values.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Reorder: `updateOrCreate` + site settings write should happen AFTER the Shopify metafield sync succeeds, or within the same logical unit with a compensating rollback.
        - Alternatively, queue the Shopify sync and return 202 Accepted — the dashboard can poll `/shopify/status` for completion.
        - Add a `last_settings_sync_error` column or metadata field to surface drift to support and to self-healing reconcile.
    - **Technical:** Pattern match to `dcdb3b4` in-flight aggregate handling. The method performs: (1) `updateOrCreate` on `brand_store_settings`, (2) Oxygen deployment dispatch (async), (3) `$site->save()` for design settings, (4) Shopify metafield `setShopMetafields` call. Steps 1–3 are committed to Postgres before step 4 runs. If step 4 returns `userErrors`, the method returns 422 — but steps 1–3 are already durable. At 200 brands, a Shopify API partial outage during a configuration push wave creates silent drift between `brand_store_settings` and the Shopify metafields Hydrogen actually reads.
    - **Plain English:** Imagine updating your profile on a job board — you change your name and hit save. The site says "saved!" and shows your new name on your dashboard, but the employer-facing site still shows your old name because the sync to the public database failed silently. You think it worked, but nobody else sees the change. The fix is to either not claim "saved" until the sync completes, or to queue the sync and show a "updating…" status until it confirms.
    - **Evidence:**
        ```php
        // 1. Local DB write
        $settings = BrandStoreSettings::updateOrCreate(
            ['professional_id' => $pro->id],
            $dbFields
        );

        // 2. Oxygen deployment (async)
        if ($hasOxygenToken) {
            $settings->oxygen_deployment_token = ...;
            $settings->save();
            $this->deployment->dispatchDeployment($pro->id);
        }

        // 3. Write visual settings to site.settings.design
        $site->settings = $settings;
        $site->save();

        // 4. Shopify metafield sync — only when a Shopify-backed field is being updated.
        if ($needsShopifySync) {
            // ... metafield writes ...
            if (! $result['success']) {
                return $this->error($msg, 422); // local state already committed!
            }
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **LIFE-4** · P1 — `dispatchImageJob` swallows `Throwable` — creates stuck `processing_state = pending` rows with no recovery path
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandGalleryController.php (dispatchImageJob method) and app/Http/Controllers/Api/Professional/Store/AffiliateProductPhotoController.php (dispatchImageJob method)
    - **Affects:** Brand gallery and affiliate product photo uploads. If the queue connection fails during dispatch, the `SiteMedia` row is created with `processing_state = pending`, the original file is stored on disk, but no processing job runs. The image stays in "pending" forever — no reconcile job, no stale-state alert, no retry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log distinctly: the catch block should emit at `Log::error` with `processing_dispatched: false` so Nightwatch can distinguish "job dispatched" from "dispatch failed."
        - Propagate the failure to the caller: `upload()` should check whether the dispatch succeeded and, if not, either delete the `SiteMedia` row or return a 202 with a clear retry path.
        - Add a daily reconcile job (`ProcessStuckPendingImagesJob`) that finds `SiteMedia` rows with `processing_state = pending` and `created_at < now() - interval '15 minutes'` and re-dispatches or fails them.
    - **Technical:** Pattern match to `#STRIPE-2` distinct-log and `0de1f2f` daily reconcile. The function has two outcomes — dispatched vs. failed — but neither the log nor the return value distinguishes them. The caller (`upload()`) creates the row, stores the file, updates the path, calls `dispatchImageJob`, and returns success — regardless of whether the dispatch worked. At 200 brands uploading images, a queue blip creates orphaned rows that never transition to `ready`.
    - **Plain English:** Like putting a letter in a mailbox that has a broken pickup mechanism. You drop it in, the box confirms it's inside, but the mail truck never comes to collect it. The letter sits there forever. The fix is to either check that the truck actually arrived, or have someone check the box every hour for stuck letters.
    - **Evidence:**
        ```php
        private function dispatchImageJob(string $imageId, string $originalPath, string $basePath): void
        {
            $processInline = in_array(app()->environment(), ['local', 'testing'], true)
                || config('queue.default', 'sync') === 'sync';

            try {
                if ($processInline) {
                    ProcessImageVariantsJob::dispatchSync(...);
                } else {
                    ProcessImageVariantsJob::dispatch(...);
                }
            } catch (Throwable $e) {
                Log::error('Brand gallery: image processing dispatch failed', [
                    'image_id' => $imageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **LIFE-5** · P1 — `ShareCheckoutLinkController::store()` calls Shopify `cartCreate` without an idempotency key — retries create duplicate carts
    - **Where:** app/Http/Controllers/Api/Professional/Store/ShareCheckoutLinkController.php (store method)
    - **Affects:** All shared checkout link requests (~40K daily notifications at scale). Client retries or network replays create duplicate Shopify carts, each with a different `checkoutUrl` — the affiliate's follower gets a dead or wrong link, and the brand's Shopify admin accumulates orphaned carts.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Derive an idempotency key from a deterministic hash of `affiliate_id + product_gids + quantities + timestamp rounded to 30s` and pass it as `idempotencyKey` in the `cartCreate` input per Shopify's Storefront API spec.
        - If Shopify's Storefront API doesn't support idempotency keys on `cartCreate`, add a local dedup: cache `hash(request) → checkoutUrl` for 60s so identical requests within the window return the same cart.
    - **Technical:** Category (1) idempotency on the write path. The `cartCreate` mutation is submitted without any deduplication mechanism. The Shopify Storefront API's `cartCreate` does accept an `idempotencyKey` field in the `CartInput`. At the scale target of ~40K daily notifications driving checkout link traffic, even a 0.1% retry rate produces 40 duplicate carts/day. The canonical `lockForUpdate + UNIQUE` pattern doesn't apply directly (this is an external API), but the `idempotency-key derivation` principle does — the request must be deterministically replayable.
    - **Plain English:** Like sending a text message that says "order me a pizza" — if your phone glitches and sends it twice, the pizzeria makes two pizzas even though you only wanted one. The fix is to add a unique order number to the message so the pizzeria knows "ah, I already made this one" if they see the same number twice.
    - **Evidence:**
        ```php
        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders([
                'X-Shopify-Storefront-Access-Token' => $storefrontToken,
            ])
            ->post($url, [
                'query' => self::CART_CREATE_MUTATION,
                'variables' => [
                    'input' => ['lines' => $lines],
                ],
            ]);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **LIFE-6** · P1 — `BrandGalleryController::upload()` and `AffiliateProductPhotoController::upload()` create `SiteMedia` rows without an idempotency key — client retries create duplicate rows
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandGalleryController.php (upload method) and app/Http/Controllers/Api/Professional/Store/AffiliateProductPhotoController.php (upload method)
    - **Affects:** Image uploads — a network retry after a timeout on the file upload creates two `SiteMedia` rows for the same file. The advisory lock serializes concurrent uploads from the same site, but a retry after the first request completes successfully produces a duplicate row.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `UNIQUE` constraint on `(site_id, pool, COALESCE(product_gid, ''), original_size_bytes, original_mime)` or hash the file content and store a `content_sha256` column with a `UNIQUE(site_id, pool, content_sha256)` constraint.
        - At minimum, add a `UNIQUE(site_id, pool, path)` constraint so two rows can't claim the same storage path — the second `create` would fail with a typed `UniqueConstraintViolationException` catch.
    - **Technical:** Category (1) idempotency. The advisory lock (`pg_advisory_xact_lock`) prevents concurrent uploads within the same transaction, but a client retry after the first request's transaction commits will acquire the lock again, check the count again, and create a second row. The canonical `lockForUpdate + UNIQUE` pattern requires a `UNIQUE` constraint backing the idempotency key — here there is none. At 200 brands uploading gallery images, network hiccups during upload create orphaned duplicate rows.
    - **Plain English:** Like a coat check that uses a turnstile to make sure only one person enters at a time, but has no ticket system. If you go through, come back out, and go through again, you get two hangers for the same coat. The fix is to add ticket numbers that the coat check can use to say "I already have this one."
    - **Evidence:**
        ```php
        $media = DB::transaction(function () use ($site, $maxItems, $request, $file) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["site-images:{$site->id}"]);
            }
            // ...
            return SiteMedia::create([
                'site_id' => $site->id,
                'pool' => self::POOL,
                'path' => '',
                // ... no idempotency key field
            ]);
        });
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-7** · P1 — `ProfessionalAnalyticsController::summary()` swallows all `QueryException` instances, not just "table doesn't exist"
    - **Where:** app/Http/Controllers/Api/Professional/Analytics/ProfessionalAnalyticsController.php (summary method)
    - **Affects:** Dashboard analytics. Multiple query blocks catch `QueryException` with no SQLSTATE check and return empty collections. A syntax error, constraint violation, or schema mismatch in any analytics query would be silently converted to empty data, masking real bugs from Nightwatch until user reports surface them.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Narrow each catch to `catch (QueryException $e)` with a guard: `if (($e->errorInfo[0] ?? null) === '42P01') { return collect(); } throw $e;` — same pattern already used in `AffiliateCommerceAnalyticsController::buildPayoutSummary` for 42703.
        - For the link_clicks and cart_events tables specifically, pre-flight with `Schema::hasTable()` once and skip the query entirely rather than catching on every request.
    - **Technical:** Category (10) observability. The `AffiliateCommerceAnalyticsController` correctly narrows its `QueryException` catch to SQLSTATE 42703 (undefined column). Here, five separate `try { DB::table('analytics.link_clicks')... } catch (QueryException) { return collect(); }` blocks in `summary()` and `shopSummary()` catch ALL QueryExceptions. A mistyped column or broken migration that reaches production would produce zero Nightwatch signal — every dashboard would silently show empty charts instead of surfacing errors.
    - **Plain English:** Like a fire alarm that treats every type of sensor reading — smoke, heat, a dead battery, a spider inside — as "everything's fine, don't alert anyone." A real fire (a broken query) gets the same silent treatment as a known non-issue (a table that hasn't been created yet). The fix is to check WHAT went wrong before deciding to ignore it.
    - **Evidence:**
        ```php
        try {
            $clicksAgg = DB::table('analytics.link_clicks')
                ->where('professional_id', $professional->id)
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw('COUNT(*) as total_clicks')
                // ...
                ->first();
        } catch (QueryException) {
            $clicksAgg = (object) ['total_clicks' => 0, ...];
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **LIFE-8** · P2 — `AffiliateProductController::store()` catches `QueryException` + checks `$e->getCode() === '23505'` instead of using typed `UniqueConstraintViolationException`
    - **Where:** app/Http/Controllers/Api/Professional/Store/AffiliateProductController.php (store method)
    - **Affects:** Affiliate product selection creation. The numeric-code check is fragile across Postgres versions and constraint renames.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `catch (QueryException $e) { if ($e->getCode() === '23505')` with `catch (UniqueConstraintViolationException $e)` per canonical `#STRIPE-3` / `35c6f31`.
    - **Technical:** Category (1) idempotency catch hygiene. Laravel 10+ provides `UniqueConstraintViolationException` as a typed subclass of `QueryException`. Catching by numeric SQLSTATE (`23505`) works but is less readable, relies on the developer knowing the code, and can't be statically analyzed. The canonical pattern is the typed catch — it's version-stable across Postgres releases and constraint renames.
    - **Plain English:** Like identifying someone by their exact height in millimeters instead of using their name. It works if you measure perfectly, but if the measuring tape changes (a new Postgres version uses a different internal code), you'll miss them. Using the typed exception is like calling them by name — stable and clear.
    - **Evidence:**
        ```php
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return $this->error('This product is already selected.', 409);
            }
            throw $e;
        }
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **LIFE-9** · P2 — `ShopifyIntegrationController::resolveShop()` makes synchronous outbound HTTP call with 6s timeout inside a controller — vendor latency propagates to user-facing p99
    - **Where:** app/Http/Controllers/Api/Professional/Brand/ShopifyIntegrationController.php (resolveShop / discoverShopifyHandle methods)
    - **Affects:** Brand onboarding — the "resolve shop domain" step makes a live HTTP request to the prospective Shopify storefront. A slow-responding storefront adds up to 6s to the API response.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Reduce timeout to 3s (the HTML scrape is a best-effort UX convenience, not a correctness requirement).
        - Or: queue the discovery as a job, return a `resolve_job_id`, and have the frontend poll. This also lets the job run with a longer timeout without blocking the user.
    - **Technical:** Category (6) vendor-integration hygiene. The `discoverShopifyHandle` method fetches the storefront homepage with `Http::timeout(6)->connectTimeout(4)`. This is a synchronous call in the request lifecycle — any latency from Shopify's infrastructure or the merchant's storefront directly inflates the p99 of this endpoint. At 200 brands all resolving their domains during onboarding, a Shopify partial outage cascades into 6s timeouts across all concurrent brand setups. The SSRF guards (DNS pinning, redirect disabling) are correct — the concern is purely latency.
    - **Plain English:** Like a receptionist who, when you ask "what's the address for Acme Corp?", drives to Acme Corp's office to read the sign on their door before answering you. You wait in the lobby for up to 6 seconds while they make the round trip. The fix is to either make that trip faster (shorter timeout) or have a separate runner do it and text you the answer when they're back.
    - **Evidence:**
        ```php
        $response = Http::timeout(6)
            ->connectTimeout(4)
            ->withOptions([...])
            ->get($url);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-10** · P2 — `BrandCatalogController::updateMetafields()` can make up to 3 sequential Shopify GraphQL calls in a single request — compounds vendor latency
    - **Where:** app/Http/Controllers/Api/Professional/Store/BrandCatalogController.php (updateMetafields method)
    - **Affects:** Brands managing product catalog — updating metafields on a product with commission_override deletion + new metafield set + variant cascade can make 3 sequential GraphQL calls, each adding 500ms–2s of Shopify latency.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Batch all metafield operations into a single GraphQL call using Shopify's bulk mutation syntax where possible.
        - Move the `clearVariantDisablesForProduct` cascade to an async job — it's best-effort cleanup, not correctness-critical (the brand can manually flip variants).
    - **Technical:** Category (6) vendor-integration hygiene. The method calls `deleteProductMetafield` (for commission_override null), then `setProductMetafields` (for the remaining metafields), then `clearVariantDisablesForProduct` (on activation cascade). Each is a separate HTTP round-trip to Shopify's GraphQL API. At 200 brands actively managing their catalogs, sequential calls compound: if each takes 1s, the endpoint takes 3s to respond. The canonical `vendor API version pinning` pattern doesn't directly apply here, but the principle of minimizing synchronous vendor calls does.
    - **Plain English:** Like placing three separate orders at a restaurant — "I'll have the soup… [waiter leaves, comes back] …now the salad… [waiter leaves, comes back] …now the dessert." Instead of the waiter taking all three at once, the kitchen gets them one at a time and you wait for each. The fix is to hand the waiter the full order in one trip.
    - **Evidence:**
        ```php
        if ($validated['commission_override'] === null) {
            $this->catalogService->deleteProductMetafield($integration, $productGid, 'commission_override');
        } else {
            $metafieldsToSet[] = [...];
        }
        // ... later:
        if (! empty($metafieldsToSet)) {
            $result = $this->catalogService->setProductMetafields($integration, $productGid, $metafieldsToSet);
        }
        // ... later:
        if ($activatingProduct) {
            $this->catalogService->clearVariantDisablesForProduct($integration, $productGid);
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **LIFE-11** · P2 — `ShareCheckoutLinkController::store()` catches `\Throwable` and returns generic 502 — verbatim Shopify error is lost to the caller
    - **Where:** app/Http/Controllers/Api/Professional/Store/ShareCheckoutLinkController.php (store method)
    - **Affects:** Affiliates creating checkout links. When Shopify returns an error (invalid variant, storefront token expired, rate limit), the affiliate sees "Unable to create checkout. Please try again." with no diagnostic information. At 200 brands, support cannot debug checkout-link failures without digging through logs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Store the verbatim Shopify error on a `ShareCheckoutLinkAttempt` model or in a `checkout_error_log` table with the affiliate's professional_id and timestamp.
        - Return a distinct error code per failure class (invalid_variant vs. storefront_unavailable vs. rate_limited) so the frontend can show actionable messaging.
    - **Technical:** Category (6) vendor error hygiene — pattern match to `verbatim vendor error capture` (`bf6e46d`). The catch block logs the full Shopify response to Nightwatch (good) but the user-facing response is generic. At scale, a support ticket "checkout link not working" requires a log dive. Storing the verbatim error on a record attached to the affiliate lets support self-serve.
    - **Plain English:** Like a package delivery service that only tells you "delivery failed" — not whether the address was wrong, the recipient wasn't home, or the truck broke down. You have to call customer support and hope they can look up the scanner logs. The fix is to write down the actual reason on the delivery slip so anyone can see what happened.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::error('Share checkout-link: cart creation exception.', [
                'professional_id' => $pro->id,
                'error' => $e->getMessage(),
            ]);
            return $this->error('Unable to create checkout. Please try again.', 502);
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **LIFE-12** · P2 — Multiple controllers use inline `$request->validate([...])` instead of Form Request classes
    - **Where:** Multiple controllers — `BrandAffiliateController`, `BrandAffiliateInviteController`, `BrandGalleryController`, `AffiliateProductPhotoController`, `AffiliateProductController`, `ShareCheckoutLinkController`
    - **Affects:** Validation consistency and testability. Inline validation rules can't be reused, tested in isolation, or enforced consistently across API versions.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Extract inline validation arrays into dedicated Form Request classes per canonical `a11feb2` pattern.
        - Prioritize controllers that have validation logic beyond simple field lists (e.g., conditional rules, `Rule::in` with config keys).
    - **Technical:** Category (7) authorization & validation hygiene. The canonical `Policy + Form Request` pattern requires Form Request classes for all non-trivial validation. Many controllers in the audit scope have inline `$request->validate([...])` calls. These work correctly but break the single-responsibility pattern: controllers should orchestrate, Form Requests should validate. At 200 brands, validation changes across API versions become risky when rules are scattered across controllers.
    - **Plain English:** Like having every waiter at a restaurant memorize the list of allergies instead of having it printed on a card. When the menu changes, you have to retrain every waiter individually instead of updating one card. The fix is to put the rules in one place (Form Request classes) so they're consistent and easy to update.
    - **Evidence:**
        ```php
        // BrandAffiliateController::disconnect()
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        // BrandAffiliateInviteController::store()
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            // ...
        ]);

        // AffiliateProductController::store()
        $validated = $request->validate([
            'shopify_product_gid' => ['required', 'string', 'max:100', 'regex:/^gid:\/\/shopify\/Product\/\d+$/'],
            // ...
        ]);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **LIFE-13** · P3 — `ProfessionalLinkBlockController::authorizeCustomLinks()` uses inline `abort_unless` with config check instead of a Policy ability
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalLinkBlockController.php (authorizeCustomLinks method)
    - **Affects:** Custom link creation for non-brand accounts. The config-based gate is inline and can't be tested through Policy tests.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `createCustomLink` ability to `BlockPolicy` that checks the same config, and call `$this->authorizeForUser($pro, 'createCustomLink', $site)` instead of `abort_unless`.
    - **Technical:** Category (7) authorization hygiene — pattern match to `Policy over inline role-scoping` (`#STRIPE-1`). The method checks `config("partna.account_type_defaults.{$type}.custom_links_allowed")` inline. Moving this to a Policy ability centralizes authorization logic, makes it testable, and follows the canonical `authorizeForUser` pattern the rest of the codebase uses for resource-level gates.
    - **Plain English:** Like having one security guard at the front door who checks IDs against the guest list, and another guard at the elevator who has the guest list memorized instead of using the same printed copy. If the list changes, you have to update both guards separately. The fix is to give both guards the same printed list (the Policy class).
    - **Evidence:**
        ```php
        private function authorizeCustomLinks(Professional $pro): void
        {
            $type = $pro->account_type?->value ?? mb_strtolower(trim((string) ($pro->professional_type ?? '')));
            abort_unless(
                (bool) config("partna.account_type_defaults.{$type}.custom_links_allowed", false),
                403,
                'Custom links are not available on your account type.'
            );
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-14** · P3 — Several error logs missing `brand_professional_id` / `request_id` context — Nightwatch correlation breaks
    - **Where:** BrandGalleryController (upload), AffiliateProductPhotoController (upload), dispatchImageJob (both controllers)
    - **Affects:** Production debugging at scale. When Nightwatch receives an error without professional context, it can't correlate the error to the affected tenant or the originating request — debugging requires manual log correlation across multiple sources.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `professional_id` (or `brand_professional_id`) to every `Log::error` / `Log::warning` call in controllers and jobs.
        - Add `request_id` from the request lifecycle (available via `request()->header('X-Request-Id')` or equivalent) when called in a controller context.
    - **Technical:** Category (10) observability — pattern match to `Log-with-context` canonical. At the scale target of 200 brands × 50 affiliates generating ~40K daily notifications, log volume makes manual correlation impossible. Nightwatch's auto-grouping requires consistent context keys. The gallery upload error logs have `media_id` and `error` but no `professional_id` — a support ticket "my gallery image is stuck processing" requires a join from media_id → site_id → professional_id before the error is findable.
    - **Plain English:** Like a delivery company's tracking system that logs "package #8472 — problem at sorting facility" without saying which city the sorting facility is in. When a customer calls, you have to cross-reference three different systems just to figure out whose package has a problem. The fix is to always include the customer's ID on every log entry so you can search by customer directly.
    - **Evidence:**
        ```php
        Log::error('Brand gallery: failed to store original', [
            'media_id' => $media->id,
            'error' => $e->getMessage(),
        ]);
        // Missing: professional_id, request_id

        Log::error('Product photo: image processing dispatch failed', [
            'image_id' => $imageId,
            'error' => $e->getMessage(),
        ]);
        // Missing: professional_id, request_id
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-15** · P3 — `BrandAffiliateInviteController` has 6 methods with inline `if (! $professional->isBrand())` checks that duplicate what `brand.only` middleware should handle
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateInviteController.php (index, store, availability, bulk, importCsv, destroy)
    - **Affects:** Consistency of auth gating across the API surface. Middleware + inline checks create defense-in-depth but also make the auth surface harder to audit — a future developer adding a 7th method might forget the inline check, trusting the middleware alone.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Verify `brand.only` middleware is applied on the routes for these methods, remove the inline checks, and add a test that 403s non-brand requests.
        - If the routes DON'T have `brand.only` middleware, add it rather than relying on inline checks.
    - **Technical:** Category (7) authorization hygiene. The canonical pattern is middleware-only for role gating. Other brand controllers (`BrandAffiliateController`, `BrandPayoutsController`) rely on `brand.only` middleware and don't duplicate the check inline. This controller has both — the inline check is either redundant (if middleware is present) or compensating for missing middleware. Either way, inconsistent with the rest of the brand controller surface.
    - **Plain English:** Like having both a bouncer at the door AND a security guard inside who checks your ID again at every room. It's not wrong, but it's confusing — if someone adds a new room (method) and forgets to post a second guard, they'll assume the bouncer at the door was enough. Better to trust the door and test that it works.
    - **Evidence:**
        ```php
        public function index(Request $request): JsonResponse
        {
            $professional = $this->currentProfessional($request);

            if (! $professional->isBrand()) {
                return $this->error('Only brand accounts can view affiliate invites.', 403);
            }
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.8]`

<!-- ═══ CHUNK: ctrl-prof-b-staff ═══ -->

- [ ] **LIFE-1** · P1 — `StaffNotificationController::store` creates notifications without an idempotency key, risking duplicates on retry
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:72-80
    - **Affects:** Staff-created notifications (policy updates, incidents, feature announcements). A double-click or network retry creates two identical notification rows + two email dispatches.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `UNIQUE` constraint on `(professional_id, title, body, created_at::date)` or an explicit `idempotency_key` column with `UNIQUE` + `INSERT … ON CONFLICT DO NOTHING`.
        - Pass a client-generated `idempotency_key` in the request body and include it in the create payload.
    - **Technical:** The canonical replacement is `lockForUpdate + UNIQUE`. Without a unique constraint backing the write, a retried POST creates a duplicate row. The downstream `SendTransactionalNotificationEmailJob` and `SendStaffBroadcastEmailsJob` dispatches also double-fire from the duplicate creation — two emails per retry. At the scale target (~40K daily notifications), even a 1% retry rate means ~400 duplicate notifications/day. The dedup mechanism in `NotificationPublisher` only prevents re-publishing the same dedupe key, not duplicate row creation.
    - **Plain English:** Imagine a staff member clicks "Send Announcement" and the browser hangs. They click again. The system creates two identical announcements and sends two emails to every recipient. The fix is to stamp each announcement with a unique "receipt number" the database can use to recognize and skip duplicates.
    - **Evidence:**
        ```php
        $notification = Notification::query()->create([
            ...$data,
            'category' => $data['category'] ?? null,
        ]);

        $sendEmail = (bool) ($data['send_email'] ?? false);
        $emailListKey = $data['email_list_key'] ?? 'sidest_updates';

        if ($sendEmail) {
            if ($notification->professional_id !== null && $notification->category !== null) {
                SendTransactionalNotificationEmailJob::dispatch(
                    $notification->id,
                    $notification->category,
                    $notification->professional_id,
                );
            } elseif ($notification->professional_id === null) {
                SendStaffBroadcastEmailsJob::dispatch($notification->id, $emailListKey);
            }
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-2** · P1 — `SquareIntegrationController::syncServicesNow` and `pushServiceNow` make synchronous vendor API calls in web request handlers, blocking user-facing p99
    - **Where:** app/Http/Controllers/Api/Professional/SquareIntegration/SquareIntegrationController.php:288-309 and :316-339
    - **Affects:** Brands triggering manual Square sync or service push from the dashboard. At 200 brands × occasional manual sync, Square API latency (200–800ms) propagates directly to the dashboard response time.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Return 202 Accepted immediately and dispatch an async job (`SyncSquareCatalogDeltaJob` or a new `PushServiceToSquareJob`).
        - Let the frontend poll for completion or surface the result via a notification when the job finishes.
    - **Technical:** These endpoints are intentionally synchronous per the docblock ("This endpoint is used by the manual refresh button and must work without queue workers"). However, this design choice means every brand pressing "Sync Now" holds a PHP-FPM worker + Postgres connection for the duration of a Square REST API round-trip. At the scale target with 200 brands, concurrent sync requests during a Square outage or degradation will exhaust the FPM pool. The canonical pattern from the Stripe payout work is to never make synchronous vendor calls in web request handlers — vendor latency must not propagate to user-facing p99.
    - **Plain English:** When a brand clicks "Sync Now," the server personally walks over to Square's servers, waits for an answer, and only then responds to the brand's browser. If Square is slow, the brand's dashboard freezes. If several brands click at once, the whole dashboard slows down. The fix is to hand the task to a background worker and tell the browser "we're on it — check back in a moment."
    - **Evidence:**
        ```php
        // SquareIntegrationController::syncServicesNow
        try {
            $stats = $syncService->syncFromSquare($pro, fullSync: true);
        } catch (SquareApiException $e) {
            // ...
        }

        // SquareIntegrationController::pushServiceNow
        try {
            $syncService->pushServiceToSquare($service, 'upsert');
        } catch (\Throwable $e) {
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **LIFE-3** · P1 — `FreshaIntegrationController::syncServicesNow` and `pushServiceNow` mirror the same synchronous vendor call anti-pattern
    - **Where:** app/Http/Controllers/Api/Professional/FreshaIntegration/FreshaIntegrationController.php:249-266 and :272-297
    - **Affects:** Same as LIFE-2 but for Fresha-connected brands.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Same fix as LIFE-2: return 202 and dispatch an async job.
    - **Technical:** Identical pattern to `SquareIntegrationController` — `syncFromFresha` and `pushServiceToFresha` are called synchronously in the request thread. The Fresha integration is flagged as "scaffolded-and-unverified" in project memory, which means this path is unlikely to be exercised at scale today, but the pattern must be corrected before the integration goes live to avoid the same FPM-pool exhaustion risk.
    - **Plain English:** Same problem as the Square sync — the dashboard waits for Fresha's servers to respond before showing anything to the brand. Fix it the same way: hand off to a background worker.
    - **Evidence:**
        ```php
        // FreshaIntegrationController::syncServicesNow
        try {
            $stats = $syncService->syncFromFresha($pro, fullSync: true);
        } catch (FreshaApiException $e) {
            [$message, $status] = $this->buildFreshaErrorMessage($e);
            return $this->error($message, $status);
        }

        // FreshaIntegrationController::pushServiceNow
        try {
            $syncService->pushServiceToFresha($service, 'upsert');
        } catch (\Throwable $e) {
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **LIFE-4** · P2 — Inline `abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated')` repeated in 7 staff controller methods instead of using middleware
    - **Where:** app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php:23,32,42,52 and app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php:21,37,82
    - **Affects:** All staff-admin endpoints. Every method repeats the same staff-existence check inline. If a new staff controller is added without this check, the endpoint is silently open.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the check into a `staff.auth` middleware or add it to the existing `staff.admin` middleware group.
        - Apply it once in `routes/api/staff.php` via a route group.
    - **Technical:** This is the authorization equivalent of inline validation — the same gate repeated in every method, with no central enforcement. The canonical replacement is `Policy + Form Request` for authorization; here the equivalent is middleware. A missing check on a new controller method means the endpoint accepts requests without a staff actor, leading to NPEs downstream or, worse, silent authorization bypass.
    - **Plain English:** Every staff-only door has its own keypad with the same code. If someone adds a new door and forgets to install the keypad, the door is unlocked. The fix is to put one lock on the hallway entrance instead of one on every door.
    - **Evidence:**
        ```php
        // StaffFeatureFlagController — repeated 4 times:
        abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated');

        // StaffFeatureFlagOverrideController — repeated 3 times:
        abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated');
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-5** · P2 — `StaffShopifyEventReplayController::invoke` dispatches synchronous Shopify API fetch + job processing in a web request handler
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffShopifyEventReplayController.php:127-157
    - **Affects:** Staff replaying Shopify order webhooks. A Shopify REST API call + `ProcessShopifyOrderWebhookJob::dispatchSync` both run on the request thread.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Fetch the order payload inside a queued job rather than the controller, or accept that the staff replay endpoint is low-traffic and the synchronous design is intentional for debuggability (the comment says "dispatchSync so any failure surfaces in the staff response").
        - If keeping synchronous, add explicit timeout handling and a prominent note that this endpoint blocks a worker.
    - **Technical:** The controller calls `$this->shopifyClient->rest(...)` synchronously, then `ProcessShopifyOrderWebhookJob::dispatchSync(...)`. Each of these is a 200–1000ms operation. The `dispatchSync` design is intentional per the comment, but the Shopify REST fetch before it doubles the blocking window. At the scale target, this endpoint is staff-only and low-traffic, so it's unlikely to cause FPM exhaustion — but it's the same anti-pattern shape as LIFE-2/LIFE-3 and should at minimum be documented.
    - **Plain English:** A staff tool that re-fetches an order from Shopify runs both the fetch and the processing while the staff member waits. For an occasional support tool this is fine, but it's the same "wait for Shopify" pattern that causes problems at higher traffic.
    - **Evidence:**
        ```php
        // StaffShopifyEventReplayController::invoke
        $response = $this->shopifyClient->rest(
            method: 'GET',
            shop: $shop,
            accessToken: (string) $integration->access_token,
            path: $path,
        );
        // ... then ...
        ProcessShopifyOrderWebhookJob::dispatchSync(
            brandProfessionalId: (string) $professional->id,
            orderPayload: $orderPayload,
            shopifyEventId: $shopifyEventId,
            source: 'manual',
        );
        ```
    - `[DRAFT, confidence: 0.70]`

- [ ] **LIFE-6** · P2 — `SquareIntegrationController::buildSquareErrorMessage` and `FreshaIntegrationController::buildFreshaErrorMessage` use `str_contains` on vendor error strings to decide reconnect advice — fragile and version-unstable
    - **Where:** app/Http/Controllers/Api/Professional/SquareIntegration/SquareIntegrationController.php:85-98 and app/Http/Controllers/Api/Professional/FreshaIntegration/FreshaIntegrationController.php:83-96
    - **Affects:** Error messages shown to brands when Square/Fresha sync fails. A vendor API change to the error message format silently breaks the reconnect-advice heuristic.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `str_contains` on the error message with typed exception handling: catch `SquareApiException` subtypes (or check HTTP status codes) rather than parsing the message body.
        - If the vendor SDK doesn't expose typed exceptions, at minimum pin the string match to a known set and log a warning when the error message doesn't match any known pattern.
    - **Technical:** This is the same anti-pattern as `catch (QueryException $e) + str_contains($e->getMessage(), 'UNIQUE')` — string-matching on vendor error output is fragile across API version bumps and localization changes. The canonical replacement is `UniqueConstraintViolationException` (typed catch). Here, the equivalent would be checking `$e->status` (HTTP status code) for 401/403 rather than searching the message body for 'unauthorized'.
    - **Plain English:** The code reads Square's error messages like a human scanning for keywords — "does this say 'unauthorized'?" If Square ever rewords their error messages, the reconnect suggestion silently disappears. Better to check the error code number, which is stable.
    - **Evidence:**
        ```php
        // Square:
        $shouldSuggestReconnect =
            str_contains($lower, 'resource not found') ||
            str_contains($lower, 'unauthorized') ||
            str_contains($lower, 'access token') ||
            str_contains($lower, 'merchant');

        // Fresha:
        $shouldSuggestReconnect =
            str_contains($lower, 'resource not found') ||
            str_contains($lower, 'unauthorized') ||
            str_contains($lower, 'access token') ||
            str_contains($lower, 'business');
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-7** · P2 — `AffiliateOrdersController::parseStatusFilter` uses inline `abort_unless()` for request validation instead of a Form Request class
    - **Where:** app/Http/Controllers/Api/Professional/Affiliate/AffiliateOrdersController.php:123-127
    - **Affects:** Affiliate order list endpoint — invalid `?status=` values get a raw 422 without structured validation errors matching the project's API envelope.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the `status` query parameter validation into a dedicated Form Request class (e.g., `ListAffiliateOrdersRequest`) with a `rules()` method.
    - **Technical:** The canonical replacement is `Policy + Form Request`. Inline `abort_unless` for validation bypasses Laravel's validation pipeline — no automatic 422 envelope, no validation error structure consistent with the rest of the API. The project already has ~40 Form Request classes; this is an outlier.
    - **Plain English:** This endpoint hand-validates one of its parameters in the controller method instead of using the project's standard "validation gate" pattern. It works, but it's inconsistent with every other endpoint and harder to test.
    - **Evidence:**
        ```php
        private function parseStatusFilter(Request $request): ?string
        {
            $status = $request->query('status');
            if ($status === null || $status === '') {
                return null;
            }
            abort_unless(in_array($status, ['pending', 'processing', 'paid', 'reversed'], true), 422, 'Invalid status filter.');

            return (string) $status;
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-8** · P2 — `StaffInviteController::assertBrandWithFunding` is an inline role+funding gate repeated before 4 controller methods instead of being a middleware or Policy
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffInviteController.php:228-249 (used by `store`, `bulk`, `importCsv`, `resend`)
    - **Affects:** 4 staff invite write endpoints. If a new write endpoint is added without calling `assertBrandWithFunding`, the brand-funding gate is bypassed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract into a route middleware (e.g., `staff.invite.brand_with_funding`) and apply it to the route group for staff invite writes.
    - **Technical:** Same shape as LIFE-4 — a gate repeated per-method instead of enforced at the route layer. The canonical replacement is middleware for route-level enforcement. The check verifies (a) the route-bound professional is a brand and (b) the brand has a payment method. Both conditions are stateless and belong in middleware.
    - **Plain English:** Four endpoints each independently check "is this a brand with a payment method?" If someone adds a fifth endpoint and forgets to copy the check, staff could send invites for a brand that hasn't added a payment method — circumventing the funding safety net.
    - **Evidence:**
        ```php
        // Called at the top of store(), bulk(), importCsv(), resend():
        if ($error = $this->assertBrandWithFunding($professional)) {
            return $error;
        }

        private function assertBrandWithFunding(Professional $professional): ?JsonResponse
        {
            if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
                return $this->error('This professional is not a brand account.', 422);
            }

            if (! app(StripeConnectService::class)->brandHasPaymentMethod($professional)) {
                return response()->json([...], 402);
            }

            return null;
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-9** · P3 — `ProfessionalUploadController` log context uses `'pro_id'` instead of the canonical `'professional_id'` — breaks Nightwatch correlation
    - **Where:** app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php:81-87
    - **Affects:** Observability — Nightwatch cannot correlate media upload logs with the same professional's Stripe/webhook/notification logs because the key name differs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Rename `'pro_id'` to `'professional_id'` in all log calls within this controller (at least the `upload` method and `dispatchVideoJob` catch block).
        - Sweep the codebase for other `'pro_id'` log keys and canonicalize to `'professional_id'`.
    - **Technical:** The canonical replacement is `Log-with-context`. Nightwatch (and any log aggregator) correlates log entries by field name. Using `pro_id` in some log calls and `professional_id` in others means a professional's activity is fragmented across two disjoint log streams. At the scale target with 10K+ daily job invocations, this makes incident triage materially slower.
    - **Plain English:** Some log entries tag the user as `pro_id` and others as `professional_id`. It's like filing half your receipts under "Office Supplies" and half under "Stationery" — they're the same thing, but searching for one misses the other.
    - **Evidence:**
        ```php
        Log::info('Media upload started', [
            'pro_id' => $pro->id,
            'site_id' => $site->id,
            'pool' => $pool,
            'media_type' => $mediaType,
            'file_size_kb' => $file->getSize() / 1024,
        ]);
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **LIFE-10** · P3 — Multiple staff controllers use inline `$request->validate([...])` instead of Form Request classes
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffBrandAffiliateLinkController.php:36-38, :82-85; StaffCommissionVoidController.php:57-59; StaffInviteController.php:75-81, :112-115, :132-135; StaffStoreSettingsController.php:24-27, :57; StaffProfessionalController.php:109-111, :126-128 (and ~10+ more across the staff controller surface)
    - **Affects:** API contract consistency. Validation errors from these endpoints use Laravel's default 422 format rather than the project's structured error envelope (`$this->error(...)`).
    - **Effort:** L (~1–2d) — systematic refactor across ~15–20 endpoints.
    - **What to do:**
        - Create a Form Request class for each endpoint that currently uses inline `$request->validate(...)`.
        - This is a P3 polish item, not a correctness issue — the endpoints function correctly; the inconsistency is a developer-experience and API-contract concern.
    - **Technical:** The canonical replacement is `Policy + Form Request` (the `a11feb2` refactor pattern). Inline `validate()` bypasses the project's `ApiController::error()` envelope — validation failures render as Laravel's default JSON 422 response instead of the `{ "error": "...", "code": 422, ... }` shape used by every Form-Request-gated endpoint. At the scale target with 200 brands, inconsistent error shapes make frontend error-handling brittle.
    - **Plain English:** Some endpoints use the project's standard "validation checkpoint" (Form Requests) and return errors in a consistent format. Others hand-validate and return errors in Laravel's default format. The frontend team has to handle both shapes. Standardizing on Form Requests makes every endpoint behave the same way.
    - **Evidence:**
        ```php
        // StaffBrandAffiliateLinkController::store
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        // StaffCommissionVoidController::void
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        // StaffStoreSettingsController::update
        $data = $request->validate([
            'default_commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'payout_hold_days' => ['sometimes', 'integer', 'in:0,7,14,28'],
        ]);
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-11** · P3 — `StaffLinkBlockManagementController::update` and `destroy` use inline `abort_unless` for ownership verification instead of a Policy
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffLinkBlockManagementController.php:59-64, :69-75
    - **Affects:** Staff link-block management. Functional today but inconsistent with the project's authorization doctrine.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either add a `LinkBlockPolicy` with `update`/`delete` abilities and call `$this->authorizeForUser($professional, 'delete', $linkBlock)`, or accept that staff controllers operate under a different authorization model (staff-is-God) and document the exception.
    - **Technical:** The canonical replacement is `Policy + Form Request`. The project doctrine states "Authorization through Policies, never inline." The `abort_unless` check here is correct in behavior (blocks cross-professional access) but bypasses the Policy system, making authorization invisible to `Gate::before` hooks, audit tooling, and policy-level testing.
    - **Plain English:** This door has a working lock, but it's a different brand of lock from every other door in the building. It works, but a security audit has to check it separately.
    - **Evidence:**
        ```php
        // StaffLinkBlockManagementController::update
        abort_unless(
            $linkBlock->professional_id === $professional->id &&
            $linkBlock->block_group === 'links' &&
            $linkBlock->block_type === 'link',
            404
        );

        // StaffLinkBlockManagementController::destroy
        abort_unless(
            $linkBlock->professional_id === $professional->id &&
            $linkBlock->block_group === 'links' &&
            $linkBlock->block_type === 'link',
            404
        );
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **LIFE-12** · P3 — `StaffNotificationController::store` passes `$data['severity']` through `Notification::severityForFrontendType()` which can return `null`, then inserts it without a schema-level CHECK constraint on the `severity` column
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:50-51
    - **Affects:** Schema correctness — a null severity slips through to the database, making notification-severity filtering unreliable.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `CHECK` constraint on `notifications.severity` (e.g., `CHECK (severity IN ('info', 'warning', 'critical'))`) per the `64db1f2` pattern.
        - Or coerce `null` to a default (`'info'`) in the controller before create.
    - **Technical:** The canonical replacement is the `64db1f2` pattern for `orders.rate_source` — VARCHAR-backed enums need `CHECK` constraints to prevent invalid values at the database level. The `Notification::severityForFrontendType()` method can return `null` when called with an unrecognized type, and `null` passes through to the insert without rejection.
    - **Plain English:** The notification severity field can end up empty in the database because there's no rule at the database level saying "this must be one of info, warning, or critical." An application bug that sends a weird type results in a silently-empty severity.
    - **Evidence:**
        ```php
        $data['type'] = Notification::normalizeFrontendType($data['type'] ?? null, $data['severity'] ?? null);
        $data['severity'] = Notification::severityForFrontendType($data['type']);

        // ... later:
        $notification = Notification::query()->create([
            ...$data,
            'category' => $data['category'] ?? null,
        ]);
        ```
    - `[DRAFT, confidence: 0.75]`

<!-- ═══ CHUNK: ctrl-public-internal ═══ -->

- [ ] **#LIFE-1** · P0 — GDPR webhook dedup row created before job dispatch; transient queue failure silences retries permanently
    - **Where:** app/Http/Controllers/Api/Webhooks/Shopify/ShopifyGdprWebhookController.php:126-155
    - **Affects:** All Shopify GDPR compliance requests (customers_data_request, customers_redact, shop_redact) — missed data export or deletion in response to a legitimate GDPR subject access request becomes a regulatory non-compliance.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either wrap the job dispatch in a try/catch and delete the `GdprRequest` row on failure (mirroring the `runHandlerWithFailureCleanup` pattern from `ValidatesStripeWebhookPayload`), OR
        - Move the `firstOrCreate` below the successful dispatch so the dedup row is only committed after the job is queued.
    - **Technical:** The `wasRecentlyCreated` guard on the `firstOrCreate` call is consumed immediately. If `ExportCustomerDataJob::dispatch()` throws (e.g., Redis down), the controller returns a 500, Shopify retries, but the `GdprRequest` row already exists with `payload_hash`, so `wasRecentlyCreated` is `false` and the job is never re-dispatched. The identical pattern was fixed in the Stripe webhook trait (`STRP-C` / `35c6f31`) by deleting the dedup row inside a catch so the retry can re-process.
    - **Plain English:** Imagine a compliance officer stamps a receipt “received” the moment a letter arrives, then tries to hand it to the processing team. If the hand-off fails (the team’s door is locked), the receipt is already stamped — when the letter is re-delivered later, the clerk sees the stamp and tosses the letter, so the request is never actually fulfilled. The fix is to stamp the receipt *after* the hand-off succeeds, or tear it up if the hand-off fails.
    - **Evidence:**
        ```php
        $audit = GdprRequest::firstOrCreate(
            ['payload_hash' => $hash],
            [
                'topic' => $topic,
                'shop_domain' => $shopDomain,
                'shopify_shop_id' => is_numeric($payload['shop_id'] ?? null) ? (int) $payload['shop_id'] : null,
                'payload' => $payload,
                'status' => GdprRequest::STATUS_RECEIVED,
                'received_at' => now(),
            ],
        );

        if ($audit->wasRecentlyCreated) {
            match ($topic) {
                GdprRequest::TOPIC_CUSTOMERS_DATA_REQUEST => ExportCustomerDataJob::dispatch($audit->id),
                GdprRequest::TOPIC_CUSTOMERS_REDACT => RedactCustomerJob::dispatch($audit->id),
                GdprRequest::TOPIC_SHOP_REDACT => RedactShopJob::dispatch($audit->id),
            };
            // …
        }

        return $this->success(['received' => true], 202);
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#LIFE-2** · P1 — Square payment idempotency key is a fresh UUID per request; retries cause duplicate charges
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicBookingController.php:273-275
    - **Affects:** Affiliates who take paid bookings via Square — a network hiccup during checkout (client retry, proxy timeout) can result in the customer’s card being charged twice for the same appointment.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Derive the idempotency key from a deterministic value that is stable for the same booking attempt — e.g. `sha1("booking:{$bookingId}:{$version}")` or a UUID generated once when the booking page mounts and passed through the client — instead of `(string) Str::uuid()` called afresh on every checkout request.
    - **Technical:** Square uses the idempotency key to dedup identical create-payment calls. A new random UUID on every request means each retry looks like a brand-new payment to Square, so if the first payment succeeded but the HTTP response was lost, the client’s retry opens a second charge. The canonical pattern is a deterministic idempotency key derived from the business-operation identity, not a per‑request nonce. This is the same shape as the Stripe Transfer idempotency key fix in the payout pipeline.
    - **Plain English:** Every time you press “Pay”, you generate a random receipt number. If the internet drops after the payment goes through but before you see the confirmation, and you press “Pay” again, it looks like a completely new purchase to the bank — you get charged twice. The fix is to give the payment a predictable label (like the appointment ID), so the bank knows “this is the same attempt, ignore the repeat.”
    - **Evidence:**
        ```php
        $paymentResponse = $this->squareApiClient->request($professional, 'POST', '/v2/payments', [], [
            'idempotency_key' => (string) Str::uuid(),
            'source_id' => (string) $validated['sourceId'],
            'amount_money' => [
                'amount' => $priceCents,
                'currency' => $currencyCode !== '' ? $currencyCode : 'AUD',
            ],
            // …
        ]);
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#LIFE-3** · P1 — No reconcile job for missed Shopify orders/paid webhooks; stuck orders will never finalize
    - **Where:** app/Http/Controllers/Api/Webhooks/Shopify/ShopifyOrderWebhookController.php (webhook-only pipeline; no sibling reconcile job found across the codebase)
    - **Affects:** Every commerce order — at 1M orders/year (~3K/day), even a 0.1% webhook delivery gap means ~3 orders/day fail to accrue commission, leaving affiliates underpaid and brands with growing revenue drift.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Write a daily `ReconcileStuckOrdersJob` that polls Shopify for orders that are `paid` but don’t have a corresponding `order_events` row, and processes them through the same job as the webhook handler.
        - Ensure the reconcile path logs every rescued event so operations can measure webhook loss rate over time.
    - **Technical:** Shopify webhooks are at-least-once delivery, but not guaranteed delivery — re-delivery attempts stop after ~48 hours. The Stripe payout pipeline shipped `ReconcileStuckTransferringPayoutsJob` (`0de1f2f`) exactly for this family of dependency. The current orders pipeline depends solely on the webhook; there is no backstop for the gap between a Shopify-side state change and our local copy of it.
    - **Plain English:** You asked the post office to notify you every time a package arrives, but they only promise to *try* — sometimes the notification gets lost. Without a backup plan, some packages sit on your shelf forever. The fix is a daily “check the shelf” worker that asks the post office, “Did anything arrive that you forgot to tell me about?”
    - **Evidence:**
        ```php
        // ShopifyOrderWebhookController relies entirely on the webhook arriving:
        protected function dispatchWebhookJob(
            ProfessionalIntegration $integration,
            array $payload,
            string $eventId,
        ): void {
            ProcessShopifyOrderWebhookJob::dispatch(
                (string) $integration->professional_id,
                $payload,
                $eventId,
            );
        }
        // No complementary ReconcileShopifyOrdersJob exists in the repository.
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#LIFE-4** · P2 — `custom_photos_enabled` cache invalidation misses the `:stale` twin
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php:127-129
    - **Affects:** Hydrogen storefronts that consume the brand-product custom-photos permission — after a brand flips the toggle, the stale window (up to ~50 minutes) still serves the old value, so the affiliate sees stale permission until the stale twin expires naturally.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Cache::forget($key.':stale');` alongside the primary-key forget for `brandProductCustomPhotos`, matching the pattern used elsewhere in the same controller for `embeddedProductSettings`.
    - **Technical:** `CacheLockService::rememberLocked` writes a `:stale` clone with a 10× TTL so that during a brief primary miss the stale copy can be served (SWR). When the write path manually busts the primary but forgets the stale twin, the stale clone lives on and will be returned by the `rememberLocked` method until it expires. The established pattern (`f5450d8`) requires busting both halves on the write path; this controller does it for the settings key but not for `custom_photos_enabled`.
    - **Plain English:** You tell the warehouse “the new inventory sheet is ready — throw away the old copy.” The warehouse throws away yesterday’s sheet, but keeps a backup from two weeks ago, and starts handing that out to anyone who asks. The fix is to throw away the backup at the same time you throw away the main copy.
    - **Evidence:**
        ```php
        if ($field === 'custom_photos_enabled') {
            Cache::forget(CacheKeyGenerator::brandProductCustomPhotos($professionalId, $productGid));
            // Missing: Cache::forget(CacheKeyGenerator::brandProductCustomPhotos($professionalId, $productGid) . ':stale');
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#LIFE-5** · P2 — Concurrent booking webhook/replay can double-write analytics events due to read-then-write on `analytics.booking_events`
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicBookingController.php:512-530
    - **Affects:** Booking analytics dashboards — if the same booking event is re-announced (webhook replay, Square retry) concurrently with the checkout response, duplicate analytics rows can appear, inflating booking counts.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `UNIQUE` constraint on `square_booking_id` (or `(professional_id, square_booking_id)`) so the database serialises the insert, and catch the `UniqueConstraintViolationException` to handle idempotency cleanly.
    - **Technical:** The method reads `existingEventId` from the table without any lock, then either updates or inserts with a freshly minted UUID. If two threads race, both can find no `existingEventId` and both attempt to insert; without a unique index, both succeed and produce duplicate rows. The canonical race-safe idempotency pattern requires a `UNIQUE` constraint on the business key and a typed catch (`UniqueConstraintViolationException`). The Stripe payout pipeline used this for commission movements.
    - **Plain English:** Two cashiers both check “is this customer already in the system?” at the same moment, both see “no,” and both create a new entry. Suddenly the customer appears twice. The fix is to put a “no two customers can have the same passport number” rule in the database, so the second cashier gets an immediate “already exists” nudge.
    - **Evidence:**
        ```php
        $existingEventId = null;
        if ($bookingId !== '') {
            $existingEventId = DB::table('analytics.booking_events')
                ->where('professional_id', $professionalId)
                ->where('square_booking_id', $bookingId)
                ->value('id');
        }
        $eventId = is_string($existingEventId) && trim($existingEventId) !== ''
            ? trim($existingEventId)
            : (string) Str::uuid();
        // …
        if ($existingEventId) {
            DB::table('analytics.booking_events')
                ->where('id', $eventId)
                ->update($attributes);
        } else {
            DB::table('analytics.booking_events')
                ->insert(array_merge($attributes, ['id' => $eventId, 'created_at' => now()]));
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#LIFE-6** · P2 — Supabase email hook lacks idempotency; retries send duplicate auth emails
    - **Where:** app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php:36-44
    - **Affects:** Every user who triggers a Supabase auth email (sign-up confirmation, password reset, magic link, invite). Supabase retries the hook on transient failures, so each retry can deliver a second copy of the same email.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `Cache::add` dedup gate keyed on the hook’s `webhook-id` header (mirroring the Shopify webhook pattern) so that a repeated delivery is acknowledged with 200 but does not re‑send the mail.
    - **Technical:** The controller validates the signature (in middleware), but performs no deduplication before calling `Mail::send()`. Supabase’s hook system retries on non-2xx responses, but also may retry on network‑side timeouts even if the first send succeeded — making it effectively an at‑least‑once delivery. The canonical webhook dedup pattern (used in all Shopify controllers) places an atomic `Cache::add` before any side‑effect so the second identical delivery returns 200 immediately without repeating the side‑effect.
    - **Plain English:** A postman delivers the same letter twice because the first time the doorbell was broken. Without a “letter already received” checklist by the door, the household opens the second envelope too — the recipient gets two copies of the same message. The fix is to stamp the letter as “delivered” the moment it arrives, so the postman sees the stamp next time and moves on.
    - **Evidence:**
        ```php
        // No dedup guard – Mail::send runs on every authenticated request:
        try {
            $mailable = $this->resolveMailable($actionType, $recipientEmail, $displayName, $verifyUrl, $token);
            // …
            Mail::send($mailable);
            return response()->json(['ok' => true, 'handled' => true]);
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#LIFE-7** · P2 — Synchronous Square API calls in the booking checkout path add multi‑second latency to a user‑facing endpoint
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicBookingController.php:checkout() (creates customer, booking, then payment inline)
    - **Affects:** End‑users completing a booking — at peak, multiple synchronous round‑trips to Square’s API (~200–800 ms each) block the HTTP worker, increasing p99 latency and risking request timeouts.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Decouple the non‑reversible writes: create the Square booking synchronously (needed for payment), but offload payment to a queue job so the user’s browser isn’t held open waiting for the `/v2/payments` call to complete. Return a 202 with a status‑poll URL or optimistic UX while the payment processes.
    - **Technical:** The canonical pattern shipped in the Stripe payout pipeline is Master Pattern 16: vendor I/O that can be deferred must run in a queue job. The payment call itself depends on the booking ID, but that ID is available synchronously — the payment can be dispatched after the booking is created, and the client can poll or receive a push notification. This is especially important under load from hundreds of affiliates, where a single slow Square response (1‑2 s) will climb the p99 and begin to time out.
    - **Plain English:** You walk into a store, pick an item, and the cashier says “give me your credit card, I need to go to the bank vault downtown to run it.” While they’re gone, you and everyone behind you waits. The fix is to take your card details, start the transaction, and let you leave — the bank will call you when it’s done.
    - **Evidence:**
        ```php
        // All three Square calls happen synchronously in the request:
        $customerResponse = $this->squareApiClient->request($professional, 'POST', '/v2/customers', [], $customerPayload);
        $bookingResponse  = $this->squareApiClient->request($professional, 'POST', '/v2/bookings', …);
        $paymentResponse  = $this->squareApiClient->request($professional, 'POST', '/v2/payments', …);
        ```
    - `[DRAFT, confidence: 0.9]`
