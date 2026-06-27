# Partna Phase 2 Lifecycle Correctness — Consolidated Remediation Plan

> **FROZEN as of 2026-05-12.** This plan is no longer the source of truth for status, regressions, or post-baseline annotations. Live status — including all changes from PR #12 onwards and any new findings introduced after this date — lives in `audits/MASTER-REMEDIATION-PLAN.md`. This file is preserved as provenance for `Original ID: Phase 2 Pattern X` references in the master plan. Do not edit; if a status change is needed, update the master instead.

**Date:** 2026-05-11
**Branch:** development
**Source:** 6 audits across `audits/phase-2-lifecycle/`, adjudicated by `claude-sonnet-4-6` over `deepseek-v4-pro` drafts

## Summary

- **37 unique findings** across 6 audits (no cross-audit duplicates — see "Related findings" below for fixes that touch the same file)
- **Tier breakdown:** 0 P0 · 4 P1 · 27 P2 · 6 P3
- **Five foundational patterns close 24 of 37 findings** (2 P1 · 19 P2 · 3 P3)
- **13 standalone fixes** for the rest (2 P1 · 8 P2 · 3 P3)
- **Estimated total:** ~2–3 weeks of focused work to close all 37 findings

## Related findings (land together in one PR)

| Findings | Why bundle |
|----------|------------|
| LIFE-B#2 (KV dispatch jitter in `cascadeAffiliateKvSync`) + LIFE-D#6 (KV retry backoff in `SyncSubdomainToKvJob`) | Two facets of one fan-out path — jitter at dispatch site vs. retry backoff inside job. Distinct fixes but reviewers benefit from seeing both touches on the KV pipeline together. |
| LIFE-D#7 (Twitch error/empty conflation) + LIFE-D#8 (Kick error/empty conflation) | Structurally identical fixes against the same `LiveStatusPoller` consumer — bundling avoids two PRs against the same poller file. |
| Pattern A coverage (LIFE-B#3, LIFE-C#3, LIFE-D#2, LIFE-F#4, LIFE-E#7) | Same canonical pattern (`35c6f31`); land as one sweep. |
| Pattern B coverage (LIFE-C#1, LIFE-C#2, LIFE-C#4, LIFE-B#4, LIFE-D#12, LIFE-E#1, LIFE-E#2, LIFE-E#3) | Same `report($e)` rule; land as one sweep. |
| Pattern C coverage (LIFE-D#3, LIFE-D#4, LIFE-D#5, LIFE-D#6) | Same `$backoff` property addition; land as one trait extraction. |

## Source audit files

- `audit-2026-05-11--lifecycle-schema-correctness.md` (**LIFE-A**: 2 P2)
- `audit-2026-05-11--lifecycle-cache-invalidation-and-write-path.md` (**LIFE-B**: 1 P1, 2 P2, 1 P3)
- `audit-2026-05-11--lifecycle-financial-auth-gating.md` (**LIFE-C**: 1 P1, 2 P2, 1 P3)
- `audit-2026-05-11--lifecycle-edge-vendors-and-worker.md` (**LIFE-D**: 1 P1, 11 P2, 2 P3)
- `audit-2026-05-11--lifecycle-notifications-fanout-and-dedup.md` (**LIFE-E**: 6 P2, 2 P3)
- `audit-2026-05-11--lifecycle-shopify-webhook-and-integration.md` (**LIFE-F**: 1 P1, 4 P2)

---

## Post-baseline annotations (2026-05-12)

The commits on `origin/development` (`60f231c..feeab29`, 17 commits across PR #12–#25) landed between audit generation (2026-05-11) and this plan. Two regressions and one partial-touch need updated tier/sequencing:

**Findings re-classified after the May 11-12 window:**

- `#LIFE-D#9` (P2 → P1 candidate) — **REGRESSED.** PR #12 (`a118f62`) expanded the single KV delete into a `foreach` over `ProfessionalHandleAlias` entries. The original swallow-and-log bug now hides one Cloudflare failure per alias instead of one per professional. See updated entry under "KV fan-out hardening".
- `#LIFE-D#1` (P1) — **SYMPTOM NOW VISIBLE.** PR #13 (`9fedbcb`) made `BrandDesignMediaService::listDesignMedia` always return ready-state placeholders, including those whose URL resolution fails. The race condition itself is unchanged; the orphan output is now user-observable as a 6th placeholder slot with no thumbnail. See updated Pattern D Step 2.
- `#LIFE-B#3` (P2) — **Partially touched.** PR #12 removed one of ten observer log sites (the `RetireSubdomainFromKvJob` dispatch in `ProfessionalObserver`). The remaining nine sites are unchanged.

**New audit-worthy concerns introduced by these commits** — captured in the appendix at the bottom of this file.

---

# Part 1 — Foundational fixes

These five patterns are sequenced by fix-leverage (findings closed per day of work). Land them in this order: Pattern C first (smallest, fastest), then B, then A, then E, then D. The order is *not* the tier order — it is the order that closes the most findings in the shortest time without contaminating later patterns.

## Pattern A — `Log-with-context` sweep across observers, services, middleware, and jobs

**Closes 5 unique findings (4 P2 · 1 P3):** LIFE-B#3, LIFE-C#3, LIFE-D#2, LIFE-F#4, LIFE-E#7

**Effort:** ~1 day

### Root cause

The canonical `Log-with-context` pattern established in commit `35c6f31` (Stripe `#STRIPE-2`) requires every operational log line to carry at minimum:

| Field | Source | Purpose |
|-------|--------|---------|
| `request_id` | `request()->header('X-Request-Id', '')` | Nightwatch trace correlation |
| `operation` or `__METHOD__` | Logger call site | Discriminate failure mode |
| Tenant ID (`brand_professional_id` / `professional_id` / `site_id`) | Whichever is in scope | Per-tenant aggregation in Nightwatch |
| Vendor correlation key (`shopify_event_id`, `payout_id`, etc.) | Job context | End-to-end vendor trace |

The audit pipeline found this canonical shape applied unevenly: success-path logs and per-record sweep catches frequently omit one or more required fields. Nightwatch groups events by structured context keys, so a log line that says "JWT verification failed" with only `reason` and `ip` is unjoinable to the request that triggered it.

### What to do

- [x] **Step 1 — Add `request_id` and tenant ID to all 10 observer `Log::warning` / `Log::error` calls** in `app/Observers/Core/` and `app/Observers/Professional/ProfessionalObserver.php`. Closes LIFE-B#3.
    - `BlockObserver.php`, `BrandAffiliateInviteObserver.php`, `CommissionMovementObserver.php`, `CommissionPayoutObserver.php`, `BrandProfileObserver.php`, `ProfessionalIntegrationObserver.php`, `SiteMediaObserver.php`, `SiteObserver.php`, `BrandPartnerLinkObserver.php`, `ProfessionalObserver.php`
    - Pattern: `'request_id' => request()->header('X-Request-Id', '')` added to every existing context array. Tenant ID is already present in most observers — only add where missing.
- [x] **Step 2 — Extract `App\Observers\Concerns\LogsWithRequestContext` trait.** Single source of truth for the field set; `protected function logContext(array $extra = []): array` returns `request_id`, operation, and merges callsite-specific fields. Prevents future observer skeletons from regressing.
- [x] **Step 3 — Fix `VerifySupabaseJwt` middleware logs.** `app/Http/Middleware/Auth/VerifySupabaseJwt.php` — both `Log::warning` call sites (JWKS fallback + final auth-failure path) gain `'request_id' => $request->header('X-Request-Id', str()->uuid())` and `'operation' => 'VerifySupabaseJwt'`. The middleware runs before `LoadCurrentProfessional`, so `brand_professional_id` is unavailable here — `request_id` + `operation` + IP is the floor. Closes LIFE-C#3.
- [x] **Step 4 — Thread `brand_professional_id` and `site_id` through media pipeline logs.** `app/Services/Media/BrandDesignMediaService.php`, `app/Services/Media/ImageVariantService.php`, `app/Services/Media/VideoVariantService.php`. `BrandDesignMediaService` already has the `Site` in scope; `ImageVariantService` / `VideoVariantService` receive `image_id`/`media_id` only — change the callers (in `BrandDesignMediaService` and the queue jobs) to thread `site_id` in. Closes LIFE-D#2.
- [x] **Step 5 — Add `shopify_event_id` to webhook-job success-path logs.** `app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php` (`process()` terminal `Log::info`) and `app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php` (all `Log::info`/`Log::warning` in `handleUpdated`, `handleEdited`, `handleCancelled`, `handleRefund`). The `failed()` handlers already carry it — replicate to success paths. Closes LIFE-F#4.
- [x] **Step 6 — Add `brand_professional_id` to `InviteExpirySweepJob` per-invite catch.** The value (`$invite->brand_professional_id`) is already in scope from the chunk callback `select` list. Closes LIFE-E#7.

### Plain English

Right now operational logs are like sticky notes that say "something went wrong" but don't include the customer name, the request number, or which operation was running. During an incident, support has to manually correlate timestamps and IP addresses across hundreds of unrelated lines to find the thread for one customer. The fix is to staple four required fields onto every log line — request ID, operation name, tenant ID, vendor event ID — so monitoring tools can automatically group and filter by customer, request chain, or vendor delivery.

### Why this is the highest-leverage pattern

This is the most cross-cutting pattern in Phase 2. Logging hygiene is invisible until an incident — then it's the difference between a five-minute triage and a five-hour scavenger hunt. The fix is mechanical (no logic changes), low risk, and pays back the first time an oncall engineer needs to trace a webhook delivery to a specific tenant. Extracting the trait in Step 2 means new observers and middleware inherit the discipline without anyone remembering it.

---

## Pattern B — `report($e)` on every swallowed catch block

**Closes 8 unique findings (1 P1 · 5 P2 · 2 P3):** LIFE-C#1, LIFE-C#2, LIFE-C#4, LIFE-B#4, LIFE-D#12, LIFE-E#1, LIFE-E#2, LIFE-E#3

**Effort:** ~0.5–1 day

### Root cause

Per `reference_nightwatch_alerts` (memory), Nightwatch alerts on **exceptions** and auto-detected anomalies — *not* on `Log::warning` queries. A `catch (\Throwable $e)` that logs a warning and returns is permanently invisible to Nightwatch's incident pipeline.

The audits found this pattern across six different code surfaces (Stripe controllers, Store controllers, Form Requests, AnalyticsController, CommerceNotificationService, multiple notification jobs, media-service sync fallback). All have the same shape:

```php
try {
    $this->doVendorThing();
} catch (\Throwable $e) {
    Log::warning('thing failed', [...]);
    return; // or return $this->error(...)
}
```

This is structurally correct (the catch isolates one bad record from killing the sweep / one Stripe outage from breaking the user flow) — but it converts every vendor failure into a permanent silence at the monitoring layer.

### What to do

A shared rule: **every non-rethrowing catch must call `report($e)` before returning or responding.**

- [ ] **Step 1 — Stripe controllers (LIFE-C#1, P1).** `app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php` — `syncPaymentMethodSession()` and `confirmTopUpCheckoutSession()` catch blocks. Add `report($e)` and `Log::error(...)` with `brand_professional_id`, `session_id`, and `$e->getMessage()` verbatim, before the 422 response. **Narrow the catch from `\RuntimeException` to `\Stripe\Exception\ApiErrorException`** — any non-Stripe `RuntimeException` should be allowed to bubble. Apply to both endpoints; they are structurally identical.
- [ ] **Step 2 — Store controllers (LIFE-C#2, P2, 13+ endpoints).** `app/Http/Controllers/Api/Professional/Store/BrandCatalogController.php` + `BrandCollectionController`, `BrandStoreSettingsController`, `AffiliateProductController`, `AffiliateProductPhotoController`. Add `Log::warning('Shopify catalog fetch failed', ['brand_professional_id' => ..., 'error' => $e->getMessage(), 'operation' => __METHOD__]) ` and `report($e)` inside every `\RuntimeException` and `\Throwable` catch block. Use `ShareCheckoutLinkController` (which is already correct) as the model. **Preserve the verbatim vendor error message** for operators — the user-facing paraphrase stays in the response body but the log must keep the raw Shopify error.
- [ ] **Step 3 — Form Request alias-uniqueness checks (LIFE-C#4, P3).** `app/Http/Requests/Api/BootstrapRequest.php` (inline `handle_lc` validator) and `app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php` (inline `subdomain` validator). Add `Log::warning('Handle alias check skipped — alias table unavailable', [...])` to each empty catch. **Narrow the catch from `\Exception` to `\Illuminate\Database\QueryException`** so non-DB exceptions (validator coding errors) still bubble.
- [ ] **Step 4 — AnalyticsController cache invalidation (LIFE-B#4, P3).** `app/Http/Controllers/Api/PublicSite/AnalyticsController.php` — `pageview()`, `click()`, `cartEvent()` each have an identical `try { invalidateAnalytics(...); } catch (Throwable) {}` block. Replace empty body with `Log::warning('analytics cache invalidation failed', ['professional_id' => ..., 'site_id' => ..., 'error' => $e->getMessage()])` — keep the silent-success semantics (no rethrow), but make Redis degradation visible.
- [ ] **Step 5 — Notification-service swallowed exceptions (LIFE-E#1/E#2/E#3, P2 × 3).** Three structurally identical fixes:
    - `app/Services/Notifications/CommerceNotificationService.php` — `notifyBookingCompleted()` catch
    - `app/Jobs/Notifications/NudgeStuckOnboardingJob.php` — `sweepMilestone()` inner catch
    - `app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php` — `handle()` inner catch
    - Add `report($e)` alongside the existing `Log::warning`. The job-level `failed()` handlers already call `report($e)` for permanent exhaustion — this gap is the *per-record* sweep failures that never reach `failed()`.
- [ ] **Step 6 — `dispatchVariantJob` sync-path swallow (LIFE-D#12, P2).** `app/Services/Media/BrandDesignMediaService.php` — both inline and production-fallback `\Throwable` catches around `ProcessImageVariantsJob::dispatchSync()`. Add `report($e)`, **and** mark the SiteMedia row as failed before returning: `$siteMedia->forceFill(['processing_state' => SiteMedia::PROCESSING_STATE_FAILED, 'processing_error' => $e->getMessage()])->save();` — the queue's `failed()` callback does not fire in sync mode, so `markFailed()` inside the job never runs. Without this fix the row stays in `processing` forever.

### Plain English

The current code is full of "quiet failure" catches — places where a vendor call fails, the user sees a generic error, and the system logs a note nobody monitors automatically. Three different audits flagged this in three different layers. The canonical fix is one extra line per catch: `report($e)`. It tells the monitoring system "this exception happened" without changing the behavior the user sees. For most catches, that's the whole fix. For two cases (Stripe controllers and Store controllers), there's also a "narrow the catch type" step so unexpected exceptions still bubble as crashes.

### Why this is high leverage despite mostly being one-liners

Eight findings, mostly trivial diffs, but they all defeat the *same* failure-detection contract: Nightwatch is the team's primary incident surface. Right now a Stripe API degradation at the 200-brand scale produces 200 silent 422 responses with zero alert. Adding `report($e)` to one controller file closes that. The marginal value per character of code changed is among the highest in this plan.

---

## Pattern C — Cloudflare job retry backoff

**Closes 4 unique findings (4 P2):** LIFE-D#3, LIFE-D#4, LIFE-D#5, LIFE-D#6

**Effort:** ~30 minutes – 1 hour

### Root cause

All four Cloudflare-touching jobs declare `public int $tries = 3` with no `$backoff` property. Laravel's default backoff is **zero seconds**, producing three near-instantaneous retries during a Cloudflare outage. The canonical pattern from commit `9a9b107` requires explicit `$tries` AND `$backoff`.

At the scale target (200 brands × 50 affiliates), a Cloudflare incident during a batch of brand-onboarding completions produces up to 600 rapid-fire API calls in milliseconds — compounding an already-failing vendor API.

### What to do

- [ ] **Step 1 — Add `public array $backoff = [10, 30, 60];` to all four jobs:**
    - `app/Jobs/Cloudflare/ProvisionBrandDnsJob.php` (LIFE-D#3)
    - `app/Jobs/Cloudflare/RetireBrandDnsJob.php` (LIFE-D#4)
    - `app/Jobs/Cloudflare/RetireSubdomainFromKvJob.php` (LIFE-D#5)
    - `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php` (LIFE-D#6)
- [ ] **Step 2 — Consider extracting `App\Jobs\Concerns\HasCloudflareRetryPolicy` trait** that declares `$tries = 3` and `$backoff = [10, 30, 60]`. Four files use the same constants; centralising them prevents drift if the policy changes (e.g., Cloudflare doubles their rate-limit window).

### Plain English

When a Cloudflare API call fails, all four of these background jobs retry immediately — three failed calls in a fraction of a second, like slamming a stuck door instead of waiting between tries. During an outage this makes things worse, not better. A 10/30/60 second backoff gives Cloudflare time to recover and reduces the call-rate during their incident, which is the kind of thing they ask integrators to do.

### Why this is fastest

This is a single property addition × 4 files. Five minutes per file. Closes 4 P2 findings.

---

## Pattern D — Race-safety hardening: `lockForUpdate + UNIQUE`

**Closes 4 unique findings (1 P1 · 3 P2):** LIFE-D#1, LIFE-D#10, LIFE-D#11, LIFE-A#1

**Effort:** ~2–3 days

### Root cause

The canonical concurrency pattern from commit `5735525` requires **both** application-level row locking (`lockForUpdate` within a transaction) **and** database-level uniqueness (`UNIQUE` constraint or partial unique index). The audits found four distinct race conditions where one or both layers is missing. Each closes a real bug at the scale target; they share toolkit but not file.

### What to do

- [ ] **Step 1 — `commission_payouts` partial UNIQUE index (LIFE-A#1, P2).** Add a Supabase migration `<timestamp>_commission_payouts_natural_key_unique.sql`:
    ```sql
    CREATE UNIQUE INDEX commission_payouts_natural_key_uq
        ON commerce.commission_payouts (brand_professional_id, affiliate_professional_id, eligible_after)
        WHERE status NOT IN ('cancelled', 'failed', 'reversed');
    ```
    In `CommissionPayoutService::createPayoutBatch`, wrap the `forceCreate` call in `try/catch (\Illuminate\Database\UniqueConstraintViolationException $e)` — log a warning and return `null` (treat as "created by a concurrent path"). This is the `UniqueConstraintViolationException` typed-catch pattern from `#STRIPE-3`.
    Note: post-`20260419000002_nullable_commission_fks.sql`, both FK columns are nullable; Postgres NULL-valued rows are excluded from unique index conflicts, so soft-deleted professional rows are safely excluded.
- [ ] **Step 2 — `addPlaceholder` count-then-insert race (LIFE-D#1, P1 — SYMPTOM NOW VISIBLE).** `app/Services/Media/BrandDesignMediaService.php` — `addPlaceholder` method. Add `->lockForUpdate()` to the `$activeCount` query inside the existing transaction:
    ```php
    $activeCount = SiteMedia::query()
        ->where('site_id', $site->id)
        ->where('pool', SiteMedia::POOL_DESIGN)
        ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
        ->whereNull('deleted_at')
        ->where('is_active', true)
        ->whereNotIn('processing_state', [SiteMedia::PROCESSING_STATE_FAILED])
        ->lockForUpdate() // ← add this
        ->count();
    ```
    `deletePlaceholder` on the same class already uses this pattern — match it.

    **Post-baseline note (2026-05-12):** PR #13 (`9fedbcb`) modified `listDesignMedia` to always return ready-state placeholders, including those whose `MediaVariant::getUrlAttribute()` returns `''` due to an unreachable disk. The race condition itself is unchanged — but any race-bypass orphan is now visible to brands as a 6th placeholder card with no thumbnail (where the bug previously silently hid the orphan). Land this `lockForUpdate` fix promptly to stop new visible orphans. Existing orphans (if any) need a separate one-off cleanup task — they are now logged via the `9fedbcb` Nightwatch warning on unresolvable URLs, so the cleanup script can be informed by Nightwatch query.

    **Safe-sequencing note:** `lockForUpdate` on a count query is a pure safety addition — zero behaviour change under single-user load, just serializes concurrent inserts. No risk of deadlock — `site_media` insert is a single-row operation, no outer transaction holding other rows.
- [ ] **Step 3 — `ProcessVideoVariantsJob` in-flight lock (LIFE-D#10, P2).** `app/Jobs/ProcessVideoVariantsJob.php` — `handle()`. The existing terminal-state guard covers `READY` and `FAILED` but not `PROCESSING`. Add a Redis `SET NX` lock at the start of `handle()`, keyed on `media_id` (NOT job ID, so crash-then-retry can still acquire it via TTL expiry):
    ```php
    $lockKey = "video:processing-lock:{$this->mediaId}";
    $lock = Redis::set($lockKey, 1, 'EX', $this->encodingTimeout + 60, 'NX');
    if (!$lock) {
        Log::info('ProcessVideoVariantsJob: another worker is processing this media', ['media_id' => $this->mediaId]);
        return;
    }
    try {
        // ...existing handle() body...
    } finally {
        Redis::del($lockKey);
    }
    ```
    Apply the same fix to `ProcessImageVariantsJob` (LIFE-D mentions it has the same gap but CPU cost is small; tag as optional follow-on, not required for the close).
- [ ] **Step 4 — Logo upload row-then-file ordering (LIFE-D#11, P2).** `app/Services/Media/BrandDesignMediaService.php` — `upsertLogoFromUploadedFile`, `upsertLogoFromBytes`, `createDesignRow`. Reverse the ordering: **store the original file to R2 first**, using a deterministic path derived from a content hash; then call `createDesignRow` and singleton-replace within the transaction. The row commits with `path` already populated — eliminating the soft-delete-during-flight race where Row A's path-update silently no-ops because Row B's createDesignRow already soft-deleted it.

    Pseudocode:
    ```php
    // current (broken): row first, file second
    $media = $this->createDesignRow(...);
    $path = $this->images->storeOriginal($file, "images/{$proId}/{$media->id}");
    $media->update(['path' => $path]); // ← silently no-ops if soft-deleted concurrently

    // fixed: hash → file → row
    $contentHash = hash_file('sha256', $file->getPathname());
    $basePath = "images/{$proId}/by-hash/{$contentHash}";
    $path = $this->images->storeOriginal($file, $basePath);
    $media = $this->createDesignRow($site, $purpose, $file->getMimeType(), $file->getSize(), 0, $path);
    ```
    `createDesignRow` signature gains a `?string $path = null` parameter that is included in the insert payload. Row commits with `path` set; no second update needed; soft-delete race is eliminated.

### Plain English

Four separate race conditions, same toolkit:
- **LIFE-A#1:** the payouts table can accept two duplicate "Alice owes Bob $200 this month" rows if any future code path bypasses the application's lock. The schema fix is adding a database-level uniqueness rule that enforces itself regardless of who's writing.
- **LIFE-D#1:** the 5-placeholder limit can be bypassed by two simultaneous uploads — both check "do we have room?", both see 4, both insert. Adding `lockForUpdate()` to the count makes the check-then-insert atomic.
- **LIFE-D#10:** a video can be encoded twice in parallel if the queue redelivers while encoding is mid-flight. A Redis lock keyed on the media item (not the job) prevents the duplicate work.
- **LIFE-D#11:** two simultaneous logo uploads can leave one image stored in cloud storage with no database row pointing to it, and the dashboard stuck on "processing…" forever. Reversing the order — store the file first, then claim the database slot — eliminates the race.

### Why this is later in the order

These are real concurrency bugs but require the most care: the placeholder/logo races involve transactions spanning external I/O; the video lock needs to be tested with a real Redis instance to confirm crash-then-retry behavior; the partial unique index needs a backfill check on production data before adding (a duplicate that snuck through pre-fix would cause migration failure). Doing this last means the easier `report($e)` and `Log-with-context` patterns have already landed and reduced the noise floor — making it easier to spot a regression introduced by the race-safety changes.

---

## Pattern E — Email-send idempotency sentinel

**Closes 3 unique findings (3 P2):** LIFE-E#4, LIFE-E#5, LIFE-E#6

**Effort:** ~2 days (one Supabase migration + three job updates)

### Root cause

`NotificationPublisher::publish` correctly uses `insertOrIgnore` on `(professional_id, dedupe_key)` to make the in-app notification idempotent. But the email-send side-effects dispatched from there are **not** idempotent — `Mail::to($email)->send($mailable)` runs unconditionally on every job invocation, and three of those jobs have `$tries = 3`.

The mail-transport-accepts-then-process-crashes scenario is the canonical retry path: SMTP accepts the message, queue worker crashes before marking the job complete, queue retries, second copy sends. At ~40K daily notifications with even a 0.1% retry rate, that's ~40 duplicate transactional emails per day — and the failure mode hits hardest on "your commission is ready" and "your payout has been initiated" messages, which look like double-payments to the recipient.

### What to do

A shared rule: **every email-send job must read a sentinel before sending and write the sentinel after sending; the sentinel lives on the source row that triggered the job.**

- [ ] **Step 1 — Migration: add `email_sent_at` to three tables.** Single Supabase migration `<timestamp>_email_send_sentinels.sql`:
    ```sql
    ALTER TABLE notifications.notifications     ADD COLUMN email_sent_at timestamptz;
    ALTER TABLE site.enquiries                  ADD COLUMN email_sent_at timestamptz;
    CREATE TABLE notifications.broadcast_email_receipts (
        notification_id uuid NOT NULL REFERENCES notifications.notifications(id) ON DELETE CASCADE,
        subscription_id uuid NOT NULL,
        email_sent_at   timestamptz NOT NULL DEFAULT now(),
        PRIMARY KEY (notification_id, subscription_id)
    );
    ```
    Three tables because the three jobs trigger off three different source-row types. `broadcast_email_receipts` is a separate table because the EmailSubscription rows are recipients, not notifications — they can't carry per-notification state.

- [ ] **Step 2 — `SendTransactionalNotificationEmailJob` (LIFE-E#6).** Inside `handle()`, before the `Mail::to()->send()` call:
    ```php
    $notification = DB::transaction(function () {
        $n = Notification::query()->lockForUpdate()->find($this->notificationId);
        if ($n === null || $n->email_sent_at !== null) {
            return null;
        }
        return $n;
    });
    if ($notification === null) {
        return; // already sent or deleted
    }

    Mail::to($email)->send($mailable);

    $notification->forceFill(['email_sent_at' => now()])->saveQuietly();
    ```
    The `lockForUpdate` window is short (one row read + flag check), but eliminates the worker-vs-worker race.

- [ ] **Step 3 — `SendEnquiryNotificationJob` (LIFE-E#5).** Mirror Step 2 against `site.enquiries`:
    ```php
    $enquiry = Enquiry::query()->find($this->enquiryId);
    if (! $enquiry || $enquiry->email_sent_at !== null) {
        return;
    }
    Mail::to($this->notificationEmail)->send(new SiteEnquiryNotification($enquiry));
    $enquiry->forceFill(['email_sent_at' => now()])->saveQuietly();
    ```

- [ ] **Step 4 — `SendStaffBroadcastEmailToSubscriberJob` (LIFE-E#4).** Use `broadcast_email_receipts.insertOrIgnore`:
    ```php
    $inserted = DB::table('notifications.broadcast_email_receipts')->insertOrIgnore([
        'notification_id' => $this->notificationId,
        'subscription_id' => $this->subscriptionId,
    ]);
    if ($inserted === 0) {
        return; // already sent
    }
    Mail::to($sub->email)->send(new StaffBroadcastMail($notification, $unsubscribeUrl));
    ```
    `insertOrIgnore` returns the count of newly inserted rows; 0 means the PK constraint caught the duplicate. The send happens only after the insert succeeds — so a crash before send leaves an "already sent" receipt for a never-sent email. That is the **correct tradeoff**: at-most-once delivery for financially-sensitive emails is the right safety bias. If the team wants at-least-once with dedup, swap the order to send-then-insert and accept duplicate-send risk on crash.

### Plain English

Right now the in-app side of the notification system has a bouncer that checks IDs — no duplicates. But when it hands off the email side to the post office, that runner has no checklist. If the post office accepts the letter but the runner trips before signing off, the system thinks the letter wasn't sent and sends it again. For "your commission is ready" emails, getting two copies looks like a double-payment to the recipient. The fix is to give each notification a "delivered" stamp; the email job stamps the source notification before mailing and skips the mail step if the stamp is already there.

### Why this needs its own pattern

Three findings, one migration, three identical job structures. Bundling them keeps the migration to one PR and makes the regression-test surface coherent — all three jobs ship together with consistent sentinel semantics.

---

# Part 2 — Standalone fixes

These 13 findings don't cluster into the foundational patterns. Each is a discrete, localized fix.

## Cluster: streaming-vendor false-negatives (one shape, two clients)

A shared rule: **API client error paths must be distinguishable from "no results" paths in their return type.** Today `getLiveHandles` returns `[]` for both, and the poller writes `is_live = '0'` to Redis for every handle in the batch — making every "LIVE NOW" badge across all brand sites disappear during a vendor outage.

- [ ] **#LIFE-D#7 · P2** — `TwitchApiClient::getLiveHandles` conflates error and empty
    - **Effort:** S (~0.5–1h)
    - **Where:** `app/Services/Streaming/TwitchApiClient.php` — `getLiveHandles` catch block and non-2xx branch
    - **What to do:** Throw a typed `TwitchApiException` on non-2xx and `\Throwable` catches. In `LiveStatusPoller::pollTwitch`, catch the new exception and skip `writeStatus` for the affected batch — do not write false-negatives.
- [ ] **#LIFE-D#8 · P2** — `KickApiClient::getLiveHandles` conflates error and empty
    - **Effort:** S (~0.5–1h)
    - **Where:** `app/Services/Streaming/KickApiClient.php` — generic `\Throwable` catch (after the `KickRateLimitException` re-throw)
    - **What to do:** Same shape as LIFE-D#7 — throw `KickApiException` on error, update `pollKick` to skip writes.

Both should land in one PR — the poller changes touch the same file.

## Cluster: webhook idempotency + reconcile-scope correctness

- [ ] **#LIFE-F#1 · P1** — `handleRefund` increments `refund_cents` before idempotency check
    - **Effort:** M (~2–4h)
    - **Where:** `app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php` — `handleRefund()` method
    - **What to do:** Gate the `refund_cents` UPDATE inside an idempotency check. Two options:
        - **Option A (minimal):** Call `insertEventIfNew(...)` first; only execute the UPDATE if it returned without catching `UniqueConstraintViolationException`. This is the `#STRIPE-3` canonical ordering — idempotency guard before side-effect.
        - **Option B (absolute-sum derivation):** Rewrite as `UPDATE commerce.orders SET refund_cents = (SELECT COALESCE(SUM(refund_subtotal_cents), 0) FROM commerce.order_events WHERE order_id = ? AND event_type IN ('refunded','partially_refunded'))`. This makes the update idempotent on its face — running it twice produces the same value. Requires `refund_subtotal_cents` to be stored on `order_events` (verify via `list_tables`).
    - **Plain English:** The store's return counter currently hands back the money first and writes the return in the ledger second. If the same return slip arrives twice, two refunds happen before the ledger catches the duplicate. Move the ledger stamp to step 1.

- [ ] **#LIFE-F#2 · P2** — `ReconcileShopifyOrders` lacks `financial_status=paid` filter
    - **Effort:** S (~0.5–1h)
    - **Where:** `app/Console/Commands/ReconcileShopifyOrders.php` — `reconcileIntegration()` Shopify REST query
    - **What to do:** Add `'financial_status' => 'paid'` to the query params. This restricts the reconciler to its intended purpose (backstopping missed `orders/paid` events) and excludes `refunded`/`partially_refunded`/`voided`/`pending`/`authorized` orders from reprocessing — without which the hardcoded `status = 'approved'` in `upsertOrder()`'s `DO UPDATE` clause would reset a refunded order's status.
    - **Plain English:** The daily catch-up sweep was built to find paid orders the system missed hearing about. It's currently looking at ALL orders, including returns — and when it finds a returned order it re-processes it as fully paid again. One filter keeps the sweep focused on what it was built for.

## Cluster: `:stale` twin bust + SWR cache discipline

- [ ] **#LIFE-B#1 · P1** — `invalidateAnalytics` doesn't bust `:stale` twins for visit/click keys
    - **Effort:** S (~0.5–1h)
    - **Where:** `app/Services/Cache/AnalyticsCacheService.php` — the 90-day `$keys` loop and `Cache::deleteMultiple` call
    - **What to do:** Append each key's `:stale` twin to `$keys` before the `deleteMultiple` call:
        ```php
        for ($i = 0; $i < 90; $i++) {
            $date = Carbon::now()->subDays($i)->format('Ymd');
            $visitKey  = CacheKeyGenerator::analyticsVisits($professionalId, $date, $date);
            $clickKey  = CacheKeyGenerator::analyticsClicks($professionalId, $date, $date);
            $keys[] = $visitKey;
            $keys[] = $visitKey . ':stale';
            $keys[] = $clickKey;
            $keys[] = $clickKey . ':stale';
        }
        ```
        The `affiliateProjections` loop in the same method already does this correctly — use it as the reference. Add a test asserting both primary and `:stale` keys are absent after invalidation.
    - **Plain English:** Each cached analytics number has two stored copies: a fast-expiring "current" copy and a 10×-longer "safety net" copy. Invalidation today flushes the current copy but forgets the safety net, so dashboards keep showing stale numbers for up to ten times the normal cache window after every visit. The canonical fix (commit `f5450d8`) is one line per key.

## Cluster: KV fan-out hardening (one observer change + one re-throw)

- [ ] **#LIFE-B#2 · P2** — `SiteObserver::cascadeAffiliateKvSync` dispatches 1→N Cloudflare KV jobs without jitter
    - **Effort:** S (~0.5–1h)
    - **Where:** `app/Observers/Core/SiteObserver.php` — `cascadeAffiliateKvSync` private method
    - **What to do:** Add `->delay(now()->addSeconds(random_int(0, 30)))` to each `SyncSubdomainToKvJob::dispatch($affiliateId)` call. `SiteCacheService::invalidateSite` already uses this pattern (commit `38ff4fb`) for `InvalidateConnectedAffiliateCachesJob` — mirror it.
    - **Plain English:** When a brand renames their website, the system updates routing for every connected affiliate. One part of the system staggers those updates across 30 seconds; the part that updates Cloudflare's routing table fires all 50 at the same instant. Add the same stagger.

- [ ] **#LIFE-D#9 · P2 → P1 candidate** — `SyncSubdomainToKvJob` swallows KV delete failure across ALL historical aliases
    - **Effort:** S (~0.5–1h)
    - **Where:** `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php` — the `! $siteUrl` branch (lines 64–74 post-PR #12)
    - **Post-baseline note (2026-05-12):** PR #12 (`a118f62`) expanded the single delete into a `foreach` loop over `ProfessionalHandleAlias` entries. The swallow now hides one Cloudflare failure per alias. At a brand with N historical handle renames, a single KV outage during a disconnect leaves N stale entries with no retry. The fix is unchanged but the urgency increased.
    - **What to do:** Re-throw the caught `\Throwable` (after the `foreach` completes, so all aliases get an attempt before the job fails) so the queue retries the whole job. `RetireSubdomainFromKvJob` already does this correctly — copy the pattern.
    - **Safe-sequencing note (behavior change ahead):** the rethrow changes the job from "always succeeds" to "may fail and retry." Bundle this PR with Pattern C (LIFE-D#6, `$backoff` on `SyncSubdomainToKvJob`) — without backoff, the rethrow will retry-loop three times in milliseconds during transient Cloudflare blips. Do not merge the rethrow alone. Verify Horizon's failed-jobs alert routing is configured so a sustained Cloudflare incident produces one alert, not 200.
    - **Plain English:** When an affiliate removes a brand partnership, the system tries to delete the old routing entry for the current handle and every historical alias. If any of those deletes fail (Cloudflare outage), the job currently declares success and moves on — every stale entry persists indefinitely. One-line fix: re-throw so the queue retries the job — but only after Pattern C's retry-backoff has landed.

## Cluster: Shopify integration controller refactor

- [ ] **#LIFE-F#3 · P2** — `ShopifyIntegrationController` uses inline `Validator::make` across all 7 actions
    - **Effort:** M (~2–4h)
    - **Where:** `app/Http/Controllers/Api/Professional/ShopifyIntegration/ShopifyIntegrationController.php` — `status()`, `connect()`, `disconnect()`, `token()`, `registerWebhooks()`, `retrySetup()`, `resolveShop()`
    - **What to do:** Extract each action's rules into a dedicated Form Request class under `app/Http/Requests/Api/Professional/ShopifyIntegration/` — `StatusShopifyIntegrationRequest`, `ConnectShopifyIntegrationRequest`, etc. Each request's `authorize()` calls through to `resolveTargetBrandProfessionalId`. This mirrors commit `a11feb2` (Stripe controller refactor).
    - **Why it matters:** Form Requests run `authorize()` before `rules()` — inline `Validator::make` runs validation first, leaking field-level error details to callers without access. Glob of `app/Http/Requests/` confirms no ShopifyIntegration form requests exist yet.

## Cluster: dropped-roadmap webhook attack surface

- [ ] **#LIFE-F#5 · P2** — Fresha and Square webhook HMAC validation are unverified placeholder stubs
    - **Effort:** M (~2–4h) — OR S (~30min) if the controllers are deleted entirely
    - **Where:** `app/Http/Controllers/Api/Webhooks/FreshaCatalogWebhookController.php`, `app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php` — `isValidSignature()` in both
    - **What to do:** Per `project_booking_dropped.md` (2026-05-11 — Fresha and Square are dropped from the roadmap), the safest fix is to **delete both controllers, their routes, their feature flags (`partna.features.fresha_sync`, `partna.features.square_sync`), and any catalog-sync jobs they wire up.** If they're kept for any reason, the Fresha implementation is currently a Square-shaped guess (the comment explicitly says so), and Square's six-URL-variant try-loop indicates the correct notification URL is also unknown. Both flags default to `false`, so present risk is bounded — but the codepath exists.
    - **Plain English:** The side entrance has a keypad lock, but nobody knows the correct code — so someone guessed and posted a sticky note saying "try the front door code." Until we look up the real code from the building manual (or — since we decided not to use this entrance — just brick it up), anyone who knows enough to guess might get in. Given the booking-roadmap decision, bricking it up is the right call.

## Cluster: grace-warning atomic dedup

- [ ] **#LIFE-A#2 · P2** — `fireGraceWarnings()` dedup check is non-atomic with the notification send
    - **Effort:** S (~0.5–1h)
    - **Where:** `app/Jobs/Stripe/VoidExpiredPayoutsJob.php:89,96-98`
    - **What to do:** Two acceptable approaches:
        - **Tight-window:** Wrap the per-payout block in `DB::transaction(function () use ($payout, $tag) { $row = CommissionPayout::query()->lockForUpdate()->find($payout->id); if (in_array($tag, $row->grace_notifications_sent ?? [], true)) return; $row->forceFill(['grace_notifications_sent' => [...$existing, $tag]])->save(); $payout->affiliateProfessional?->notify(new AffiliatePayoutGraceWarningNotification($row, $daysOut)); });` — commit the dedup tag **before** the notify call. Tradeoff: a crash between `save` and `notify` causes a missed warning (correct safety bias for financial messaging — at-most-once).
        - **Atomic JSONB update:** Use a Postgres `UPDATE ... WHERE NOT (grace_notifications_sent @> '["T-X"]') RETURNING id` — only notify if the UPDATE returned a row. Same at-most-once semantics, no transaction.
    - **Plain English:** The current code calls Alice, then writes "called Alice" on the list. If the pen runs out, Alice gets called twice. The fix is to cross her off the list first; if the pen-runs-out crash happens, Alice misses one call — which is the right tradeoff for financial messaging.

## Cluster: minor hygiene (P3s)

- [ ] **#LIFE-D#13 · P3** — `upsertCname` always PATCHes `proxied` even when content matches
    - **Effort:** S (~0.5–1h)
    - **Where:** `app/Services/Cloudflare/CloudflareDnsService.php` — `upsertCname` and `findRecord`
    - **What to do:** Extend `findRecord`'s return shape to include `proxied` (the Cloudflare API already returns it; the function currently drops it). Skip the PATCH when both `content` and `proxied` already match the desired state.

- [ ] **#LIFE-D#14 · P3** — `VideoVariantService::processVariants` clears `processing_error` on success, losing transient-failure history
    - **Effort:** S (~0.5–1h)
    - **Where:** `app/Services/Media/VideoVariantService.php` — final `SiteMedia::update` call
    - **What to do:** Either don't include `processing_error => null` in the success-path update (only clear it if previously non-null) OR add a `processing_error_history` JSONB column and append the prior error before clearing the active field. Helps spot near-OOM capacity trends before they become outages.

- [ ] **#LIFE-E#8 · P3** — `CommerceNotificationService` uses `Str::uuid()` as fallback dedupe key
    - **Effort:** S (~0.5–1h)
    - **Where:** `app/Services/Notifications/CommerceNotificationService.php` — `notifyBookingCompleted()` dedupe key derivation
    - **What to do:** Replace `$eventId ?: $bookingId ?: Str::uuid()->toString()` with a deterministic hash: `$eventId ?: $bookingId ?: hash('sha256', json_encode([$professionalId, $serviceName, $customerName, $amountPaidCents]))`. Or fail loudly: throw `\InvalidArgumentException` if both IDs are empty.
    - **Why P3:** Current primary call site always populates `booking_event_id` — this is defensive against future call-site drift, not a live bug.

---

# Suggested merge order

| Day | Work | Findings closed |
|-----|------|-----------------|
| 0.5 | **Pattern C** — Cloudflare backoff (one-line × 4 jobs + optional trait) | 4 P2 |
| 1   | **Pattern B** — `report($e)` sweep across 6 surfaces (includes 1 P1) | 1 P1 · 5 P2 · 2 P3 |
| 2   | **Pattern A** — `Log-with-context` sweep across observers/middleware/services/jobs | 4 P2 · 1 P3 |
| 3   | **Standalone P1s** — LIFE-B#1 (`:stale` twin) + LIFE-F#1 (refund double-count) | 2 P1 |
| 4–5 | **Pattern E** — Email-send idempotency (migration + 3 jobs) | 3 P2 |
| 6–8 | **Pattern D** — Race-safety hardening (UNIQUE constraint + 3 races) | 1 P1 · 3 P2 |
| 9   | **Standalone P2 cluster:** streaming false-negatives (LIFE-D#7, D#8), KV jitter + re-throw (LIFE-B#2, D#9), reconcile filter (LIFE-F#2), grace dedup (LIFE-A#2) | 6 P2 |
| 10  | **Standalone P2 + roadmap cleanup:** Shopify form requests (LIFE-F#3), Fresha/Square decision (LIFE-F#5) | 2 P2 |
| 11  | **P3 cleanup:** LIFE-D#13, LIFE-D#14, LIFE-E#8 | 3 P3 |

**Why this order:**

- **Pattern C first** because it's 30 minutes and closes 4 P2 findings — highest tier impact per hour.
- **Pattern B next** because it's mechanical (one extra line per catch), closes a P1 immediately, and improves the noise floor for the rest of the work (you can see real failures in Nightwatch while debugging the harder fixes).
- **Pattern A** rides B because both touch logging surfaces — bundling them avoids file-touch churn on observers and services.
- **Standalone P1s on day 3** because by then the observability backbone is in place; an unexpected regression from the refund-count or `:stale` fix will surface quickly via Nightwatch instead of silently producing bad data.
- **Pattern E** before Pattern D because the email migration is mechanical (column adds + receipt table) and lower-risk than the race-safety changes.
- **Pattern D last among the heavy patterns** because the placeholder/logo races involve transactions spanning external I/O and need careful regression testing — best done after the observability work makes regressions visible.
- **P3s last** — pure hygiene, no incident risk.

# Appendix — New audit-worthy concerns surfaced 2026-05-12

These items emerged from cross-referencing PR #12–#25 against this plan. They are not yet in the audit ledger; folding them into the next Phase 2 sweep is recommended.

## Smart-collection metafield namespace churn (one-time operational watch item)

Within ~5 hours on May 11, the smart-collection `metafield_ref` strings flipped `partna → sidest → partna` (`d5cc1a3` → `8327d1f` → `1c03040`) while bulk metafield writes also moved `sidest → partna` via `MigrateMetafieldNamespaceCommand`. Brands that connected during the rename window may have inconsistent state — smart collections referencing a namespace not yet provisioned on that brand's store.

**What to do (runbook):**
- Confirm `ReconcileSmartCollectionRulesCommand` has been run for every brand connected between commits `d5cc1a3` and `1c03040`.
- Add a dev-brand smoke test verifying `partna.*` metafield definitions exist AND smart-collection rules reference them.
- Once all live brands are reconciled, run `partna:migrate-metafield-namespace --delete-old` to remove the legacy `sidest.*` definitions.

One-time concern that resolves when the migration window closes. No live finding bug; flagging for runbook completeness.

## `SyncSubdomainToKvJob` alias fan-out compounds existing Pattern C (P2)

PR #12 (`a118f62`) expanded the job to write one KV entry per current handle **plus every historical alias** (`app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:39–46`). Each alias is a separate `$kv->put()` call — no batching, no debounce. Combined with the new weekly cron from `fedcb66` (`partna:backfill-subdomain-kv --all --queue` Sundays 04:00 UTC), steady-state KV write rate scales with `professionals × avg_aliases_per_pro`.

**What to do:** no new fix item here — Pattern C (`#LIFE-D#6` backoff) and `#LIFE-B#2` (KV dispatch jitter) already cover the surface, but their priority increases. The weekly cron's per-job random-second-spread (already correct via the queue) means the immediate alias fan-out is now the dominant burst, not the brand-handle-rename cascade. Keep Pattern C ahead of any catalog/cache work in the merge order — it's the load-bearing protection for the broader KV path.

## `MediaVariant::getUrlAttribute` new exception swallow (Pattern B candidate)

PR #13 (`9fedbcb`) added a `try/catch (\Throwable)` around `Storage::disk($this->disk)->url($this->path)` returning `''` on failure with `Log::warning('SiteMedia variant URL resolution failed', [...])`. Intentional for the placeholder list path (a stale disk reference no longer 500s `GET /brand/design`), but it's a new category-10 swallow.

**What to do:** add `report($e)` alongside the existing `Log::warning` per Pattern B canonical. **One-line addition, zero logic change** — the swallow stays swallowed; `report($e)` only makes it visible to Nightwatch. Bundle into Pattern B's PR.

## `EmbeddedSetupController::provisionShopifyIntegration` new outbound HTTP call (Pattern B candidate)

PR #23 (`1c03040`) added `validateShopifyAccessToken()` — a new outbound Shopify HTTP call inside the provision endpoint with `Http::timeout(8)`. The validation-failure path logs `'shop_domain' => $shopDomain, 'error' => $e->getMessage()` but does not call `report($e)`. No backoff, no retry on transient 5xx.

**What to do:**
- Add `report($e)` to the failure path so vendor-side network failures surface in Nightwatch.
- Sanitise the logged error (cURL exceptions can include the resolved URL/IP) — log `class_basename($e)` instead of the full message. Same fix is also tracked in Phase 1 Pattern A Step 6.
- Once Phase 1 SEC-C#4 lands fully, the safe-sequencing recommendation (fail-open on 5xx, refuse on 401/domain-mismatch) replaces the current "non-401 is success" behaviour.

## `commerce.orders.status = 'approved'` hardcode in `upsertOrder()` is more leveraged now (LIFE-F#2 watch)

Not a new concern, but the recent Shopify integration churn (PR #16, #17, #18, #20, #23, #24, #25) means the reconciler is more likely to be triggered during dev re-syncs. `#LIFE-F#2`'s fix (adding `financial_status=paid` to the reconcile query) becomes more leveraged as dev iteration accelerates. Promote in the merge order if dev resync activity continues.

---

# What this plan does NOT cover

- **Phase 3–6 audits** (Scaling antipatterns, DB/queue scaling, Test coverage, Data integrity, Security review of new code) — these run after Phase 2 closure per the staged checklist.
- **External audits** (`composer audit`, `npm audit`, Supabase RLS review, backup drill) — separately tracked.
- **Pentests** — deferred to STAGE 3.
- **Things the audits explicitly verified clean** — including: `Bus::batch()->allowFailures()` on `FanOutBrandStatusNotificationJob` (already correct); `EmailSubscription::newUnsubscribeToken()` entropy (already 285-bit); `analyticsSummary` cache versioning (the version token IS appended at the call site, contrary to DeepSeek's draft); `ReconcileShopifyOrders` daily scheduling (present at `routes/console.php:163`); `failed()` handlers across notification jobs (correctly call `report($e)` for permanent job-level failures — the gap is per-record sweep catches, addressed by Pattern B); `commerce.orders` LWW guards (`shopify_updated_at` comparison is correct, the bug in LIFE-F#1 is ordering-of-side-effect-vs-guard, not the guard itself); HMAC verification on the seven Shopify webhook controllers (covered by Phase 1 Pattern C).
