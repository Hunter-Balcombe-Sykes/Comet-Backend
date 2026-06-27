# Partna — Stage 2 Pilot Readiness Checklist

**Stage 2: 5 brands, 10–20 affiliates each (~50–100 affiliates, ~500–1,000 links)**

Source: `audit-ledger-2026-05-01.md`. Ordering inside each tier: **least urgent first → most urgent last** (read bottom-up to find what to do next).

Stage 2 stress-tests what Stage 1 left implicit: multi-tenant isolation under cross-tenant load, query performance at realistic row counts, queue/Horizon contention with 5× integrations firing concurrently, cache key correctness across tenants, per-tenant rate limiting, Stripe webhook ordering across multiple subscriptions, Shopify rate-limit handling across multiple stores. Several Stage 1 findings have additional Stage 2 implications and are cross-referenced inline.

## Progress

**✅ Complete — all in-scope items shipped.**

- P0 Blockers: 0 of 0 (no Stage 2-specific P0s; Stage 1 P0s remain prerequisites)
- P1 High: 6 of 6 complete
- P2 Medium: 10 of 10 complete
- P3 Low: 5 of 5 complete

> **Deferred items** (architectural decisions, cross-codebase coordination) were extracted to `pilot-manual-queue.md` for human-driven sessions: `#1-04 / #1-05`, `#2-02 / #2-03`, `#7-06`, `#PR-006`.

---

## P0 — Must fix before any real user touches Stage 2

(none — all Stage 2-specific items are P1 or below; the Stage 1 P0s remain prerequisites for Stage 2.)

---

## P1 — Fix before Stage 2 launch

- [x] **#9-011** · P1 — Staff broadcast email job has no `failed()` handler — permanent failures are silent
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php
    - **Affects:** Staff broadcast email reliability across the now-larger affiliate cohort.
    - **Effort:** S (~1h)
    - **What to do:**
        - Add a `failed(Throwable $e)` method that calls `Log::error` and emits a Nightwatch event keyed by notification_id.
    - **Technical:** Standard Laravel queue idiom. Without `failed()`, the only signal is the failed_jobs table itself.
    - **Plain English:** When a broadcast email permanently fails to send to a user, we never log why. The error just sits in a table waiting to be discovered.
    - **Evidence:**
        ```php
        public int $tries = 3;
        // No failed() method declared.
        ```

- [x] **#9-007** · P1 — Critical money-path jobs lack explicit Nightwatch instrumentation
    - **Where:** app/Jobs/Stripe/ProcessCommissionPayoutsJob.php; app/Jobs/Stripe/ExecuteCommissionPayoutJob.php
    - **Affects:** Payout reliability monitoring at multi-brand scale.
    - **Effort:** S (~1–2h)
    - **What to do:**
        - Add explicit instrumentation in `failed()` callback and around Stripe API calls.
        - Configure a Nightwatch alert on slow `ExecuteCommissionPayoutJob` runs.
    - **Technical:** Auto-instrumentation gives you "jobs ran"; explicit events give you "this specific operation took N ms / succeeded / failed because X". Critical at Stage 2 because payout volume grows 5×.
    - **Plain English:** When a payout job fails, the alert should make it obvious *that's the one that matters*, not lost in a stream of generic "queue job failed" notifications.

- [x] **#7-04** · P1 — Video processing has no codec allowlist, no resolution cap, no FFmpeg timeout
    - **Where:** app/Services/Media/VideoVariantService.php (probe / encodeMp4 / extractPoster paths; `setTimeout(null)`)
    - **Affects:** Video upload pipeline (currently feature-flagged off but the code is the same that runs when the flag flips).
    - **Effort:** M (~4–6h)
    - **What to do:**
        - Probe first; validate codec ∈ {h264, h265, vp9}, resolution ≤ 4K.
        - Set process timeout to 2× duration + 60s.
        - Switch to `new Process([...args...])` with array form (defense against shell-string composition mistakes — pre-empts #7-09).
    - **Technical:** Standard hardening for FFmpeg pipelines. Probe + array-form Process gives both a resource ceiling and a smaller blast radius. At Stage 2 with 5× brands enabling video, a single crafted upload could wedge the redis_video queue.
    - **Plain English:** Videos go through FFmpeg with no time limit and no rules about what kinds of videos are allowed. A maliciously-crafted video could hang or kill the worker that handles them.
    - **Evidence:**
        ```php
        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout(null); // unbounded
        ```

- [x] **#7-01** · P1 — Image MIME validation accepts client-declared Content-Type without magic-byte sniffing
    - **Where:** app/Http/Requests/Api/Professional/Uploads/UploadImageRequest.php (and brand logo / placeholder request classes)
    - **Affects:** All image upload endpoints across all brands.
    - **Effort:** S (~2h)
    - **What to do:**
        - After validation, sniff with `finfo_file($path, FILEINFO_MIME_TYPE)` and reject mismatches.
        - Reject SVG everywhere (per #7-02 it's already not in validators, but BrandDesignMediaService accepts the MIME if it ever shows up).
    - **Technical:** One helper method, called from each upload FormRequest's `withValidator` or each upload action. Reject if `finfo` MIME ∉ whitelist.
    - **Plain English:** We trust the file extension and the type header the user's browser sends. We should look at the actual file bytes too, in case someone disguises a non-image as an image.
    - **Evidence:**
        ```php
        'image' => ['sometimes', 'nullable', 'file', 'image', 'mimes:jpeg,png,webp', "max:{$imageMaxKb}"],
        ```

- [x] **#6-03** · P1 — Click-recording endpoint has no bot/fraud detection beyond IP throttle
    - **Where:** app/Http/Controllers/Api/PublicSite/AnalyticsController.php:84-179
    - **Affects:** All analytics dashboards (LinkClick aggregation), top_links/top_sections, click-rate calculations across competing brands.
    - **Effort:** M (~4–8h)
    - **What to do:**
        - Add a User-Agent denylist (known bots) at minimum.
        - Add a referrer-domain check tied to the resolved site's brand storefront and registered social handles.
        - Add per-link rate cap, not just per-IP.
        - Consider session_id-based dedup for clicks within N seconds (related to #6-04 in Stage 1).
    - **Technical:** A defense-in-depth stack: signal (UA), origin (referrer), pacing (per-link rate), behavioral dedup (session). Each layer is small. Keeping it simple beats integrating a third-party WAF for pilot.
    - **Plain English:** Right now anyone can hit the click-tracking URL as fast as IP throttling allows and it counts as real clicks. We should at least filter obvious bots and check that the click looks like it came from a real visit.

- [x] **#3-03** · P1 — Stripe webhook event idempotency uses `insertOrIgnore` without distinguishing race-loss from duplicate
    - **Where:** app/Http/Controllers/Api/Webhooks/StripeWebhookController.php:52-62; same pattern in StripeConnectWebhookController
    - **Affects:** All Stripe and Stripe Connect webhook handlers — risk grows linearly with subscription count.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace with `firstOrCreate(['stripe_event_id' => $event->id], [...])` and check `wasRecentlyCreated`.
        - Or wrap in a transaction with `lockForUpdate()` on the event row.
    - **Technical:** `insertOrIgnore` is a fast path but lossy — returns 0 for both "already exists" and "concurrent insert lost the race". `firstOrCreate` returns a model with `wasRecentlyCreated` that distinguishes the two cases. Stripe retries within seconds during transient failures; with 5 brands this races constantly.
    - **Plain English:** Two webhook deliveries arriving milliseconds apart can both think they're "the first one" and both run the handler. Switch to a Laravel helper that knows whether it just inserted or just found an existing row.
    - **Evidence:**
        ```php
        $alreadyProcessed = ! DB::table('billing.webhook_events')->insertOrIgnore([...]);
        if ($alreadyProcessed) { return response()->json(['received' => true]); }
        ```

---

## P2 — Fix during Stage 2 if seen

- [x] **#3-01** · P2 — Stripe API error messages logged with full string (potential PII)
    - **Where:** app/Services/Stripe/CommissionPayoutService.php:489-494 (and similar)
    - **Affects:** Payout error logs across all brands.
    - **Effort:** S (~1h)
    - **What to do:**
        - Log the Stripe error code (e.g., `$e->getError()->code`) instead of the message string.
    - **Technical:** Replace `getMessage` with code/type extraction.
    - **Plain English:** Error messages from Stripe sometimes include customer info. Logging just the error code is safer.
    - **Evidence:**
        ```php
        Log::error('Auto-refund after transfer failure failed — manual action required', [
            'transfer_error' => $e->getMessage(),
            'refund_error' => $refundEx->getMessage(),
        ]);
        ```

- [x] **#3-05** · P2 — Wallet debit and PaymentIntent create are not in a single transaction
    - **Where:** app/Services/Stripe/CommissionPayoutService.php:314-398
    - **Affects:** Wallet integrity for all brands; risk multiplies with payout volume at Stage 2.
    - **Effort:** M (~3h)
    - **What to do:**
        - Either extend the transaction; or store a "pending_debit" sentinel and commit the debit only when PaymentIntent succeeds.
    - **Technical:** Ledger-style debit-then-confirm pattern.
    - **Plain English:** A rare crash at exactly the wrong moment could leave a brand's wallet debited without a payment going through.

- [x] **#3-06** · P2 — Stripe rate-limit errors retry without explicit backoff
    - **Where:** app/Jobs/Stripe/ProcessCommissionPayoutsJob.php
    - **Affects:** Stripe API health under multi-brand payout load.
    - **Effort:** S (~2h)
    - **What to do:**
        - Catch `RateLimitException`, sleep / re-queue with longer delay before re-throwing.
    - **Technical:** Exponential backoff specifically for 429s. Job-level backoff array exists, but `RateLimitException` re-thrown immediately on each attempt with no spacing — at 5× brands this could exacerbate Stripe degradation.
    - **Plain English:** When Stripe says "slow down," we keep hammering. Wait between retries.

- [x] **#3-08** · P2 — Plan upgrade local state may diverge from Stripe past_due
    - **Where:** app/Services/Stripe/StripeBillingService.php:106-126; app/Actions/Subscription/ChangeProfessionalPlanAction.php
    - **Affects:** Brand entitlement correctness on plan upgrades.
    - **Effort:** M (~3h)
    - **What to do:**
        - Don't commit local plan change until the webhook reconciles status.
        - Or check returned subscription status and revert plan_id if not active.
    - **Technical:** Drift between local and Stripe state on payment failure.
    - **Plain English:** When a brand upgrades plans and the upgrade payment fails, we still grant the higher-tier features locally.

- [x] **#4-05** · P2 — shop/update webhook overwrites brand-customized fields
    - **Where:** app/Jobs/Shopify/ProcessShopifyShopUpdateJob.php (calls ShopProfileAutoFillService)
    - **Affects:** Brand profile data integrity for any brand that customizes locally.
    - **Effort:** M (~4h)
    - **What to do:**
        - Add an opt-in `auto_sync_shopify_fields` flag on brand_profiles.
        - Or track local edits with a "manually edited" timestamp and skip resync of touched fields.
    - **Technical:** Single-source-of-truth question — make the choice explicit and document it.
    - **Plain English:** When a brand changes their store name in Shopify, our copy gets overwritten — even if the brand had customized it locally.

- [x] **#4-09** · P2 — orders/updated refund reversal idempotency window is only the 24h cache
    - **Where:** app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php (handlePartialRefund ~95-227)
    - **Affects:** Commission ledger correctness on long-delayed Shopify replays.
    - **Effort:** M (~3h)
    - **What to do:**
        - Add a unique constraint or indexed dedup table on `(order_id, refund_id, line_item_id)`.
    - **Technical:** Replace cache-window idempotency with a durable table. Multi-brand traffic and Shopify outage windows make 24h cache-only dedup unsafe.
    - **Plain English:** The "we already processed this refund" check expires after a day. If Shopify resends the same refund a day later, we'd process it twice.

- [x] **#5-02** · P2 — Square/Fresha disconnect doesn't revoke tokens at provider
    - **Where:** app/Http/Controllers/Api/Professional/SquareIntegration/SquareIntegrationController.php:185-187 (and Fresha equivalent)
    - **Affects:** OAuth hygiene across all brands disconnecting integrations.
    - **Effort:** S (~2–3h)
    - **What to do:**
        - Call Square's `oauth2/revoke` and Fresha's equivalent before deleting the local row. Log failures, don't block.
    - **Technical:** Add a revoke step in the disconnect handler.
    - **Plain English:** When users disconnect, the OAuth token is still valid at Square / Fresha until it naturally expires. We should ask the provider to invalidate it.

- [x] **#9-008** · P2 — BrandAffiliateInviteObserver fetches names per event — N+1 on bulk invite
    - **Where:** app/Observers/Core/BrandAffiliateInviteObserver.php:89-111
    - **Affects:** Bulk-invite latency and DB load when a brand uploads a CSV at Stage 2.
    - **Effort:** S (~1–2h)
    - **What to do:**
        - Eager-resolve name once per batch.
        - Or move the notification publish to a queued job that fetches in bulk.
    - **Technical:** Standard N+1 fix.
    - **Plain English:** When a brand uploads 100 invites at once, we hit the database 100 times to get the brand's name.

- [x] **#9-009** · P2 — InviteExpirySweepJob loads all expired invites into memory
    - **Where:** app/Jobs/Notifications/InviteExpirySweepJob.php:19-42
    - **Affects:** Daily expiry sweep job — risk grows with invite volume across 5 brands.
    - **Effort:** S (~1h)
    - **What to do:**
        - Switch to `chunkById(500)`.
        - Bump timeout to 300, tries to 3.
    - **Technical:** Standard chunked iteration.
    - **Plain English:** A daily cleanup job loads everything into memory at once. If the queue has thousands of expired invites, it'll crash.

- [x] **#9-014** · P2 — Horizon analytics worker memory limit may be tight for aggregations
    - **Where:** config/horizon.php:105-117 (supervisor-analytics, memory: 256)
    - **Affects:** Daily analytics rebuilds at multi-brand row counts.
    - **Effort:** S (~1h)
    - **What to do:**
        - Profile peak memory in Stage 2; bump to 512 if needed.
    - **Technical:** Tuning task — 256 MB may be insufficient for `RebuildCommerceDailyAggregatesJob` etc. at Stage 2 volumes.
    - **Plain English:** The worker that runs analytics jobs has a memory cap that might be too low at scale.

---

## P3 — Nice to have

- [x] **#PR-008** · P3 — Stripe transaction blocks don't set isolation level explicitly
    - **Where:** app/Services/Stripe/CommissionPayoutService.php (transaction blocks)
    - **Effort:** S (~1h)
    - **What to do:** Document expected isolation level for these blocks; or set explicitly with `DB::transaction(..., isolationLevel: ...)`.

- [x] **#7-10** · P3 — VideoVariantService.extractPoster writes a 0/1-byte placeholder file on poster-extract failure
    - **Where:** app/Services/Media/VideoVariantService.php (extractPoster)
    - **Effort:** S (~1h)
    - **What to do:** Use a real placeholder JPEG or fail the upload outright.

- [x] **#7-09** · P3 — VideoVariantService uses `Process::fromShellCommandline` (currently safe, server-config inputs only)
    - **Where:** app/Services/Media/VideoVariantService.php
    - **Effort:** S (~2h)
    - **What to do:** Refactor to array form `new Process([...])`. Pairs with the #7-04 hardening.

- [x] **#3-04** · P3 — No JSON-schema validation of webhook payload before storage
    - **Where:** StripeWebhookController + StripeConnectWebhookController payload storage
    - **Effort:** S (~2h)
    - **What to do:** Optional schema check (e.g., Opis JSON Schema) before persisting payload row.

- [x] **#2-07** · P3 — Cache keys don't include site_id (one-site-per-pro assumption)
    - **Where:** app/Services/Cache/CacheKeyGenerator.php
    - **Effort:** S (~1h)
    - **What to do:** Document the assumption inline; or namespace cache keys by site if multi-site support arrives.
