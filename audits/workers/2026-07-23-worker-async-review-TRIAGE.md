# Triaged execute-audit — ALL TIERS — 2026-07-23 worker/async-layer review (4 runs merged)

> **▶ To run this file:** `execute audit audits/workers/2026-07-23-worker-async-review-TRIAGE.md`
> Fires the fix-flow: branch off `development`, then for each work unit (every **bundle** + every **standalone**, in order) **plan (Opus) → implement (Sonnet) → independent Sonnet review → commit** — ticking the box only after tests pass AND review says PASS. **Blocker gate:** P0 · auth · money · DB/migration · L/XL · Standalone → present the plan and WAIT for Josh's sign-off before implementing. Full runbook: `scripts/audit/fix-flow.md`.

## This file

- **Merged from four sequential pipeline runs** (all `scripts/audit/audit.sh`, DeepSeek scan → Claude adjudication, 2026-07-23). Findings are reproduced **verbatim** from the per-run CONSOLIDATED files — nothing here is hand-written.
- **THIS file is the execution source of truth.** Tick boxes here; the per-run CONSOLIDATED files' own checkboxes may go stale (standing repo convention — judge doneness from TRIAGE, not CONSOLIDATED).
- **IDs are run-prefixed** (`R1-`…`R4-`) because the pipeline numbers per-run and two runs both emitted a `#JOB-1` that are *different findings*. Mapping: R1 = jobs-correctness · R2 = scheduler-correctness · R3 = worker-scaling (scale-health bundle) · R4 = media-scraping-lanes.

| Run | Source file | P0 | P1 | P2 | P3 | Total |
|---|---|---|---|---|---|---|
| R1 jobs-correctness | `audits/workers/2026-07-23-jobs-correctness/CONSOLIDATED.md` | 0 | 0 | 1 | 0 | 1 |
| R2 scheduler-correctness | `audits/workers/2026-07-23-scheduler-correctness/CONSOLIDATED.md` | 0 | 0 | 2 | 1 | 3 |
| R3 worker-scaling | `audits/sweeps/2026-07-23-worker-scaling/CONSOLIDATED.md` | 0 | 5 | 9 | 3 | 17 |
| R4 media-scraping-lanes | `audits/workers/2026-07-23-media-scraping-lanes/CONSOLIDATED.md` | 0 | 1 | 1 | 1 | 3 |
| **Total** | | **0** | **6** | **13** | **5** | **24** |

Counts reconcile against the union of IDs (24 ✓). No semantic duplicates across runs. Full context: `docs/reviews/2026-07-23-worker-async-layer-review.md` §4.

## Execution policy

- **Plan:** Opus 4.8 · **Implement:** Sonnet 4.6 · **Review:** separate Sonnet 4.6 (never the implementer).
- **Combine plan+impl:** YES for S/XS · NO for P0/P1 or L/XL. Per-item escalate to Opus for gnarly logic/blast radius.

## Execution grouping — recommended order

**Work units:**

1. **Stamp-before-send mail trio** — `R3-JOB-1`, `R3-JOB-2`, `R3-JOB-3` · 3 sibling notification jobs, identical root cause + fix shape · effort M · 🔒 blocker: P1, Standalone core-mail correctness — plan first
2. **GDPR deletion-mail stamp-before-send** — `R3-JOB-4` · `SendAccountDeletionRequestMailJob` · effort M · 🔒 blocker: auth/security-sensitive bearer-token flow (`ShouldBeEncrypted`) — own reviewed unit, do NOT fold into unit 1
3. **Apify budget double-spend on menu-scrape fallback** — `R4-RES-1` · `MenuApifyScraper` · effort M · 🔒 blocker: money/cost-control (global capped vendor budget) — plan, verify against existing test coverage, wait for sign-off
4. **Job exception-reporting hardening** — `R3-OBS-1` (P1), `R3-OBS-2`, `R3-OBS-3`, `R3-OBS-4`, `R3-OBS-5`, `R3-OBS-6` · mechanical `report()`/`failed()` additions across ~13 files · effort M · autonomous (purely additive observability)
5. **Scheduler TTL & prune hygiene** — `R2-SCHED-1`, `R2-SCHED-2`, `R2-SCHED-3` · one lock-TTL number, two batched-delete extractions, one cursor swap · effort S · autonomous
6. **AI re-billing on retry** — `R4-RES-2` · `WebsiteMenuPdfScanJob` + `WebsiteMenuHtmlScanJob` → `$tries = 1` (established `GoogleMenuPhotoScanJob` pattern) · effort S · autonomous
7. **Bulk-fanout pacing** — `R3-CACHE-1`, `R3-SCALE-2` · takedown purge flood + broadcast provider rate-limit · effort M · autonomous (plan carefully around observer cascade)
8. **Trivial job-property corrections** — `R3-SCALE-3`, `R3-OBS-7`, `R1-JOB-1` · three single-property fixes (video tries, hardcoded queue name, logo timeout margin) · effort S · autonomous
9. **Cache/lock consistency polish (P3)** — `R3-CCH-1`, `R3-JOB-5`, `R4-RES-3` · effort S · autonomous
10. **Horizon analytics-lane capacity under burst** — `R3-SCALE-1` · effort L · 🔒 blocker: L-effort shared queue infrastructure with 2026-07-22 OOM history — capacity-planning decision, NOT a routine fix session. Schedule last / separately.

**Ordering / dependencies:** units 1–2 share the fix pattern — do unit 1 first so unit 2's review can diff against the agreed shape. Unit 8's `R1-JOB-1` and unit 4 both touch `ProcessImageVariantsJob`/`ProcessLogoVariantsJob` — land unit 4 before unit 8 to avoid rebase churn. Unit 10 requires Josh's explicit capacity decision before any implementation.

## Progress

Includes the twelve `RV-*` units folded in from the review roadmap — see **Review-only addendum — pilot tier** at the foot of this file. Pipeline findings: 24. Review-only: 12. Total 36.

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 11 complete (6 pipeline + 5 review-only)
- P2 Medium: 0 of 19 complete (13 pipeline + 6 review-only)
- P3 Low: 0 of 6 complete (5 pipeline + 1 review-only)

---

## P1 — Fix before pilot launch

- [ ] **R3-JOB-1** · P1 — `SendEnquiryConfirmationJob` stamps the idempotency flag before the mail send, masking permanent failures
    - **Where:** app/Jobs/Notifications/SendEnquiryConfirmationJob.php:65-90
    - **Affects:** Visitors who submit a public contact form — a transient mail-provider failure permanently drops their "we got your message" confirmation with no retry and no alert.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `implements ShouldBeUnique` + `uniqueId(): string { return $this->enquiryId; }` (mirrors `DispatchEnquiryNotificationsJob`'s pattern in the same directory) so concurrent-worker protection no longer depends on holding a DB row lock across the mail send.
        - Move `$e->forceFill(['confirmation_sent_at' => now()])->saveQuietly();` to run **after** `Mail::to($recipient)->send(...)` succeeds, matching the at-least-once pattern `SendTransactionalNotificationEmailJob` already uses in the same package.
        - Keep the existing `confirmation_sent_at !== null` check as a post-send idempotency guard, now correctly gating on "did this actually send" rather than "did we start trying."
    - **Technical:** `confirmation_sent_at` is stamped and committed inside the `lockForUpdate` transaction *before* `Mail::send()` runs. If the send throws, the stamp has already survived the commit; on the queued retry the transaction sees `confirmation_sent_at !== null`, returns `false`, and `handle()` returns without throwing — Horizon records this attempt as a clean success. `failed()` is therefore never invoked, even though `failed()` is the job's only path that could surface the drop to Nightwatch. The sibling `SendTransactionalNotificationEmailJob` in the same directory already stamps `email_sent_at` **after** the send for exactly this reason ("At-least-once semantics: stamp happens after send... preferable to silently dropping"); this job (and its two siblings below) picked the opposite, unsafe order. Fixing this only requires reordering + `ShouldBeUnique` for concurrency safety — no schema/migration change needed.
    - **Plain English:** A visitor fills out a "contact me" form and expects a quick confirmation email. If the mail server hiccups the first time, this code has already written down "confirmation sent" before actually sending it — so when the system retries, it sees that note and skips sending, even though the email never went out. The visitor never gets their confirmation and nobody is told it failed. The fix is simple: don't write "sent" until the email has actually gone out.
    - **Evidence:**
        ```php
        if ($e->confirmation_sent_at !== null) {
            return false;
        }

        $e->forceFill(['confirmation_sent_at' => now()])->saveQuietly();

        return $e;
        });

        if ($enquiry === false) {
            return; // already confirmed on a previous attempt
        }
        ```

- [ ] **R3-JOB-2** · P1 — `SendSubscriptionConfirmationJob` same stamp-before-send masking pattern
    - **Where:** app/Jobs/Notifications/SendSubscriptionConfirmationJob.php:59-80
    - **Affects:** Visitors who subscribe to a newsletter list — the double opt-in confirmation email can be silently dropped, with no automatic recovery.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Same fix as R3-JOB-1: add `ShouldBeUnique` + `uniqueId()` keyed on `subscriptionId`, move the `confirmation_sent_at` stamp to after `Mail::send()` succeeds.
    - **Technical:** Identical root cause to R3-JOB-1: `confirmation_sent_at` is stamped inside the `lockForUpdate` transaction before `Mail::send()`, so a send failure leaves the stamp intact and a retry silently no-ops without ever reaching `failed()`.
    - **Plain English:** Same issue as the enquiry confirmation — someone subscribes to updates, the confirmation email fails to send, but the system already marked it "sent" before trying, so it never tries again and nobody notices.
    - **Evidence:**
        ```php
        if ($s->confirmation_sent_at !== null) {
            return false;
        }

        $s->forceFill(['confirmation_sent_at' => now()])->saveQuietly();

        return $s;
        ```

- [ ] **R3-JOB-3** · P1 — `SendEnquiryNotificationJob` same stamp-before-send masking pattern
    - **Where:** app/Jobs/Notifications/SendEnquiryNotificationJob.php:65-99
    - **Affects:** Business owners who rely on enquiry notifications to see new leads — a transient mail failure silently drops the lead email, with the enquiry still sitting unread in the database and no alert raised.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Same fix as R3-JOB-1/R3-JOB-2: add `ShouldBeUnique` keyed on `enquiryId`, move the `email_sent_at` stamp to after `Mail::send()` succeeds.
    - **Technical:** Identical root cause. `email_sent_at` is stamped inside the `lockForUpdate` transaction before `Mail::send()` runs; a send failure leaves the stamp committed, so the retry silently no-ops and `failed()` never fires — despite this job's own comment claiming "permanent failures surface via report() in failed()."
    - **Plain English:** A customer messages a business through their site's contact form. If the notification email to the business owner fails to send even once, this code has already recorded it as delivered — so it never retries, and the business owner never finds out a customer reached out. That's a lost lead with no safety net.
    - **Evidence:**
        ```php
        if ($e->email_sent_at !== null) {
            return false;
        }

        $e->forceFill(['email_sent_at' => now()])->saveQuietly();

        return $e;
        ```

- [ ] **R3-JOB-4** · P1 — `SendAccountDeletionRequestMailJob` stamp-before-send pattern strands a user's GDPR deletion request with no recovery path
    - **Where:** app/Jobs/Account/SendAccountDeletionRequestMailJob.php:61-94
    - **Affects:** End users requesting account deletion under GDPR — a permanent mail failure leaves the deletion token stuck with no automatic recovery, requiring manual staff/DB intervention.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Same fix pattern as R3-JOB-1/2/3: add `ShouldBeUnique` (`uniqueId()` on `userId` + `tokenHash` so a legitimate re-request with a rotated token isn't coalesced away), and move the `deletion_mail_sent_at` stamp to after `Mail::send()` succeeds.
        - Because this touches a security-sensitive bearer-token flow (the job is already `ShouldBeEncrypted` specifically because the confirmation URL carries a raw deletion token), treat this as its own reviewed unit rather than folding it into the R3-JOB-1/2/3 bundle.
    - **Technical:** Same root cause as R3-JOB-1/2/3, but with higher stakes: `deletion_mail_sent_at` is stamped inside the `lockForUpdate` transaction before `Mail::send()`. A send failure leaves the stamp intact; the retry sees it, returns `false`, and the job "succeeds" without ever reaching `failed()` — which is the *only* code path that clears `deletion_token_hash`/`deletion_requested_at` so the user can re-request cleanly. The user is left in a permanent "pending deletion, no email ever arrived" limbo that only a support/DB intervention can clear, with zero Nightwatch signal that it happened.
    - **Plain English:** Imagine you ask a company to delete your account and they say "check your email to confirm." If their mail server hiccups, the email never arrives — but their system already wrote down "confirmation sent," so trying again does nothing. You're stuck in a loop only customer support can escape, and support doesn't even know it happened because nothing alerts them.
    - **Evidence:**
        ```php
        if ($user->deletion_mail_sent_at !== null) {
            return false; // already sent on a previous attempt
        }

        $user->forceFill(['deletion_mail_sent_at' => now()])->saveQuietly();

        return $user;
        });

        if ($professional === false) {
            return; // already sent, or token was rotated — skip silently
        }
        ```

- [ ] **R3-OBS-1** · P1 — `SourceGenerationException` swallowed in `GeneratePreAccountSiteJob.handle()` — build failure invisible to Nightwatch
    - **Where:** app/Jobs/PreAccount/GeneratePreAccountSiteJob.php:128-133
    - **Affects:** Operators monitoring pre-account build health; users whose signup scrape (Instagram/Google Business) fails silently.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e)` before `Log::info(...)` in the `SourceGenerationException` catch block, matching the sibling `Throwable` catch two lines below it, which already calls `report($e)`.
        - Elevate `Log::info` to `Log::warning` so severity matches the consequence (a failed build).
    - **Technical:** `SourceGenerationException` is an expected-but-real failure mode (the Apify/Places scrape returned nothing usable) — the build is marked `STATE_FAILED`, but the job returns normally, so Horizon shows a clean completion and Nightwatch never fires. The sibling `catch (Throwable $e)` block three lines below correctly calls `report($e)`; the domain-specific exception bypasses it entirely. Operators can only discover these failures by actively querying `pre_account_builds` for `build_state = 'failed'`. At 10k-user scale, this is exactly the kind of systemic-vs-isolated distinction Nightwatch exists to surface — a broken Apify credential or a Places quota exhaustion would silently fail every new signup's build with zero alert.
    - **Plain English:** When a new signup's Instagram or Google Business scrape comes back empty, the job correctly marks the build as "failed" in the database but never tells the monitoring system anything went wrong. It's like a delivery driver marking a package "delivered" but leaving it at the depot — the tracking says everything's fine, but the customer got nothing. Adding one line makes the alerting system actually notice.
    - **Evidence:**
        ```php
        } catch (SourceGenerationException $e) {
            $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => $e->failureCode])->save();
            Log::info('pre_account.build_failed', ['build_id' => $build->id, 'failure_code' => $e->failureCode]);

            return;
        } catch (Throwable $e) {
            $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED])->save();
            report($e);

            return;
        }
        ```

- [ ] **R4-RES-1** · P1 — Menu-scrape retry fallback double-spends the shared, capped Apify daily budget
    - **Where:** app/Services/Platforms/MenuApifyScraper.php:52-80 (`fetch()`), :100-165 (`fetchStores()`)
    - **Affects:** every user relying on automatic menu sync (Uber Eats / DoorDash), plus every OTHER feature that shares the Apify daily budget (Instagram connect, Google Business enrichment) — a single bot-blocked store can starve all of them for the rest of the day.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `fetchStores()`'s per-target fallback, stop calling the full `fetch()` (which re-claims an `ApifyBudget` slot and re-runs its own 4-attempt loop). Call `attemptScrape()` directly for a small, explicitly-budgeted number of extra tries (1–2), continuing the attempt counter rather than restarting it.
        - Ensure every real `attemptScrape()` invocation corresponds to exactly one `ApifyBudget::tryClaim()` call, so the budget counter reflects actual Apify spend instead of undercounting by up to 4x on a fallback path.
        - Add a short per-store negative-result cache (e.g. 10–15 min) so a persistently-blocked store doesn't re-run the full retry ladder on every `MenuFetchJob` dispatch (which fires on every online-ordering add/remove/Google-Business-seed, plus the manual "Refresh menu" button).
    - **Technical:** `fetchStores()` claims one `ApifyBudget::tryClaim('menu')` slot per (platform, mode) target (SCALE-2 comment: "retries below are reliability for THIS store, not extra spend") before firing a pooled `Http::pool()` round. When a pooled attempt for a target comes back empty/retryable, the fallback calls the full `fetch()` method for that same target — which itself claims a *second* budget slot and then runs its own `MAX_ATTEMPTS = 4` loop of real, billed `run-sync-get-dataset-items` actor invocations via `attemptScrape()`. The class's own docblock states the scrape "gets bot-blocked on a large fraction of runs," so this isn't a rare edge case — for a store connected on both pickup and delivery across two platforms (4 targets), one `MenuFetchJob` run can trigger up to 1 (pooled) + 4 (fallback loop) = 5 actor invocations per target, i.e. up to 20 real Apify runs, against only 2 accounted budget claims per target. `ApifyBudget::tryClaim()` (app/Services/Cache/ApifyBudget.php) enforces a GLOBAL daily cap keyed only by date — not by user — shared across every Apify-backed feature, so this compounding retry path can exhaust the `menu` actor's cap and/or the global cap, denying scrapes to every other user for the rest of the day.
    - **Plain English:** When a restaurant's online-ordering listing gets temporarily blocked by the delivery app's anti-bot system (which the code says happens often), the menu-scanning code doesn't just try again a reasonable number of times — it can end up paying for the same failed lookup up to twenty times in one refresh, for one restaurant. Worse, the "budget" that limits how many of these paid lookups we can do per day is shared by every feature and every user on the platform — so one stubborn restaurant's blocked listing can use up the day's entire allowance and stop everyone else's automatic menu updates, Instagram connections, and Google Business syncs from working until the next day.
    - **Evidence:**
        ```php
        // app/Services/Platforms/MenuApifyScraper.php:62-77 (fetch())
        if (! app(ApifyBudget::class)->tryClaim('menu')) {
            return null;
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $result = $this->attemptScrape($actor, $token, $storeUrl, $platform, $address, $userId, $attempt);
            if ($result['menu'] !== null) {
                return $result['menu'];
            }
            if (! $result['retryable']) {
                break;
            }
        }
        ```
        ```php
        // app/Services/Platforms/MenuApifyScraper.php:153-161 (fetchStores())
        // Map each response; a concurrent miss falls back to the sequential fetch()
        // (its own retry loop) for that single target.
        $menus = [];
        foreach ($targets as $key => $target) {
            $resp = $responses[$key] ?? null;
            $mapped = $this->mapResponse($resp, $target['platform'], $userId);
            if ($mapped === null && $this->responseRetryable($resp)) {
                $mapped = $this->fetch($target['url'], $target['platform'], $userId, $address);
            }
        ```

## P2 — Should fix

- [ ] **R1-JOB-1** · P2 — ProcessLogoVariantsJob's own `$timeout` (300s) leaves zero grace period before Horizon's hard-kill, so its `failed()` fallback can be preempted
    - **Where:** app/Jobs/ProcessLogoVariantsJob.php:31
    - **Affects:** Business-Partna logos processed through the background-removal pipeline — if a scrape/removal run genuinely takes the full budget, the job can be killed before its own fallback (dispatching the standard `ProcessImageVariantsJob`) ever fires, leaving the media row stuck in `processing_state = processing` until a separate reconciliation sweep notices it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Reduce `ProcessLogoVariantsJob::$timeout` from 300 to ~270–280s, so the job itself always has a margin before Horizon's kill boundary — do **not** raise `config/horizon.php`'s `supervisor-1.timeout`, since this codebase's own `CloudflareCachePurgeJob` docblock documents that `Illuminate\Queue\Worker::timeoutForJob()` prefers the job's own `$timeout` over the supervisor's, so a supervisor-level change would be a no-op for this job and would also affect the other ten job types sharing `supervisor-1`.
        - `GuardsMediaProcessing::acquireProcessingLock()` derives its lock TTL from `$this->timeout + 60`, so lowering `$timeout` automatically shortens the lock TTL too — confirm the new TTL (e.g. 340s at timeout=280) still comfortably outlives typical processing before shipping.
    - **Technical:** `ProcessLogoVariantsJob::$timeout = 300` exactly equals `config/horizon.php`'s `supervisor-1.timeout` (300), but per this repo's own analysis in `CloudflareCachePurgeJob` (`Illuminate\Queue\Worker::timeoutForJob()` prefers `$job->timeout()` over the worker option), the value actually enforced at kill time is the job's own 300s, not the supervisor's — the equality with the supervisor value is coincidental, not the real constraint. The real problem is that the job declares no margin between its own advertised timeout and the point at which the queue worker forcibly terminates it: if `LogoProcessorClient::process()` genuinely runs close to 300s (cold container start + model load + removal + trace, per the job's own "Generous" comment), a hard kill can land before the `finally` block releases the processing lock or before Laravel's timeout-triggered fail path reaches this job's `failed()` — which is the only code path that resets `processing_state` to `pending` and redispatches the standard `ProcessImageVariantsJob` fallback. Compare with the sibling `ProcessImageVariantsJob` (timeout=120 vs. its own no-zero-margin design) — logos are the one media pipeline with equal job/supervisor timeouts.
    - **Plain English:** The logo-background-removal job budgets exactly five minutes for itself, and the shared worker pool that runs it also caps out at five minutes. If a slow run ever eats the whole five minutes, the job gets cut off at the exact same instant its own "give up and use the plain logo instead" safety net was supposed to kick in — so in the worst case the user's logo can get stuck mid-processing with no automatic recovery until a separate cleanup check later notices it. Shaving 20 seconds off the job's own budget guarantees it always has time to hand off to its fallback before getting cut off.
    - **Evidence:**
        ```php
        // ProcessLogoVariantsJob.php
        public int $timeout = 300;
        ```
        ```php
        // config/horizon.php — supervisor-1
        'supervisor-1' => [
            ...
            'timeout' => 300,
            ...
        ],
        ```

- [ ] **R2-SCHED-1** · P2 — `analytics:compute-popularity`'s 14-minute lock TTL is shorter than its 15-minute cadence, contradicting the file's own scheduler convention
    - **Where:** routes/console.php:104-107
    - **Affects:** Content popularity scoring pipeline — if a single run's real runtime lands between 14 and 15 minutes, the lock clears while the run is still active and the next tick starts a second, overlapping instance.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Raise `withoutOverlapping(14)` to `withoutOverlapping(16)` (cadence + 1), matching the `N > cadence` pattern already used for every other sub-hourly task in this file (`horizon:snapshot`, `moderation:sla-scan`, `menu:retry-unavailable`, and the already-fixed `CheckStreamingLiveStatusJob`).
        - If the "let a stuck lock self-clear before the next tick" behavior documented in the inline comment is intentional to keep, promote that reasoning into the top-of-file conventions docblock as a named, explicit exception — right now the blanket "N must... exceed the cadence itself" rule and this line's actual value silently disagree, which reads as an oversight to anyone auditing against the stated convention.
    - **Technical:** The `routes/console.php` header docblock states the lock TTL "must exceed the task's expected runtime AND, for sub-hourly cadences, must also exceed the cadence itself — otherwise a slow run races the next tick on the instant the lock TTL expires." `everyFifteenMinutes()` paired with `withoutOverlapping(14)` violates that rule by 1 minute. Laravel's scheduler mutex is TTL-based, not tied to process lifetime — a normal run releases the lock on completion regardless of TTL, but a run whose *actual* runtime exceeds the TTL will have its lock silently expire in the cache store while the process is still executing, letting the next tick acquire a fresh lock and start concurrently. `ComputeContentPopularityScores::$timeout` is documented at 600s (10min, unenforced — `Illuminate\Console\Command` never reads it), giving nominal headroom under the 14min TTL, but that only bounds the *documented* ceiling, not a slow-Postgres-day outlier. This exact overlap-race pattern was already identified and fixed once in this same file for `CheckStreamingLiveStatusJob` (previously `withoutOverlapping(2)` equal to its `everyTwoMinutes()` cadence, raised to `5`) — the fix here is the same shape.
    - **Plain English:** Imagine a security guard whose shift badge expires at 2:00 PM sharp, while the next guard doesn't arrive until 2:15 PM's cadence. Normally the first guard leaves right on time and there's no gap. But if that guard's shift runs long — say to 2:05 or 2:10 — their badge has already stopped working at 2:00, and if the next scheduled guard shows up early, the front desk ends up staffed by two people at once, which is exactly the kind of "two workers touching the same records simultaneously" scenario the team explicitly designed every other similar task in this file to avoid.
    - **Evidence:**
        ```php
        Schedule::command('analytics:compute-popularity')
            ->everyFifteenMinutes()
            ->onOneServer()
            ->withoutOverlapping(14) // 14min lock (< 15min cadence): releases immediately on a normal run; a stuck run's lock clears before the next tick.
            ->runInBackground()
            ->onFailure($reportScheduledFailure('compute-popularity'));
        ```

- [ ] **R2-SCHED-2** · P2 — Two weekly PII-retention prune commands use a single unbounded `DELETE` instead of the codebase's established batched-delete pattern
    - **Where:** app/Console/Commands/PruneUnsubscribedSubscriptionsCommand.php:63; app/Console/Commands/PruneEarlyAccessSignupsCommand.php:52
    - **Affects:** `notifications:prune-unsubscribed-subscriptions` (weekly, `notifications.email_subscriptions`) and `early-access:prune-old-signups` (weekly, `core.early_access_signups`) — both tables accumulate rows for the lifetime of the platform (every historical unsubscribe / every historical non-converting applicant), unlike operationally-bounded tables.
    - **Effort:** S (~1h)
    - **What to do:**
        - Extract a `purgeBatched(Carbon $cutoff): int` in each command mirroring `PruneOldFeedbackSubmissionsCommand::purgeBatched()` — a `do { $count = DB::table(...)->limit($batchSize)->delete(); $deleted += $count; } while ($count === $batchSize)` loop.
        - Either add a config key per the existing per-domain block (`partna.notifications.prune_batch_size`, `partna.early_access.prune_batch_size` — both `'notifications'` and `'early_access'` config groups already exist in `config/partna.php`), or follow `PruneNotifications`'s alternative precedent of a `--batch-size` CLI option with a literal default, whichever the implementer prefers — both patterns are already established in this codebase.
    - **Technical:** Three sibling retention sweepers (`PruneOldFeedbackSubmissionsCommand`, `PruneNotifications`, `PurgeRawAnalyticsEvents`) all batch their deletes specifically to avoid one long-running transaction holding locks as their target table grows. `PruneUnsubscribedSubscriptionsCommand` and `PruneEarlyAccessSignupsCommand` are the same root-cause pattern — single-statement `$query->delete()` — on two PII-bearing tables governed by the same DINT-1/PRIV-8 retention regime and the same weekly Sunday cadence as the batched sweepers. Both explicitly reference "large cohort" / "growth campaign" scenarios in their own docblocks as the case that would matter. At pre-beta scale this is inert, but it's the same hardening gap DeepSeek found in one file and missed in its structural twin — both should carry the same tier and the same fix.
    - **Plain English:** Picture a janitor who empties a building's trash one floor at a time — quick trips, no blocked hallways. Two of this app's weekly cleanup routines instead try to carry every year's worth of trash out in one single trip. Today the pile is small, so nobody notices. But if the platform runs a big marketing push and a wave of people unsubscribe or apply and never sign up, the pile grows, and one of these two routines could tie up the trash room for a long time during its weekly run. Every other similar cleanup routine in the app already uses the "one floor at a time" approach — these two didn't get updated to match.
    - **Evidence:**
        ```php
        // PruneUnsubscribedSubscriptionsCommand
        $query = DB::connection('pgsql')
            ->table('notifications.email_subscriptions')
            ->where('status', 'unsubscribed')
            ->where('unsubscribed_at', '<', $cutoff->toDateTimeString());
        ...
        $deleted = $query->delete();
        ```
        ```php
        // PruneEarlyAccessSignupsCommand
        $query = DB::connection('pgsql')
            ->table('core.early_access_signups')
            ->where('status', '!=', 'signed_up')
            ->where('created_at', '<', $cutoff->toDateTimeString());
        ...
        $deleted = $query->delete();
        ```
        Compare to the established pattern (`PruneOldFeedbackSubmissionsCommand`):
        ```php
        private function purgeBatched(Carbon $cutoff): int
        {
            $batchSize = (int) config('partna.feedback.prune_batch_size', 1000);
            $deleted = 0;

            do {
                $count = DB::connection('pgsql')
                    ->table('core.feedback')
                    ->where('created_at', '<', $cutoff)
                    ->limit($batchSize)
                    ->delete();

                $deleted += $count;
            } while ($count === $batchSize);

            return $deleted;
        }
        ```

- [ ] **R3-OBS-2** · P2 — `ProcessImageVariantsJob` swallows cache-purge dispatch failures with no `report()`
    - **Where:** app/Jobs/ProcessImageVariantsJob.php:191-196
    - **Affects:** Visitors seeing stale images after a new upload finishes processing.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `report($e)` alongside the existing `Log::warning(...)` in the cache-purge dispatch catch block.
    - **Technical:** After a successful variant generation, the job dispatches `CloudflareCachePurgeJob` so the edge reflects the new image; the dispatch is wrapped in a catch that only logs a warning. Note: **the sibling `ProcessLogoVariantsJob::purgeEdgeCache()` already calls `report($e)` in its equivalent catch block** — this gap is specifically a consistency miss where `ProcessImageVariantsJob` wasn't updated to match its sibling, not a net-new pattern. Real-world severity is bounded: a `CloudflareCachePurgeJob::dispatch()` failure implies the Redis queue backend itself is unreachable, which would already be surfacing via failures across the entire application (every other job dispatch would fail identically) — so this specific `report()` call adds marginal but real signal, and the fix is a one-line parity change with its already-fixed sibling.
    - **Plain English:** When a new photo finishes processing, the system tells the CDN "refresh this page." If that message fails to send, the code shrugs and just logs it quietly. Its sibling job (for logo images) already raises an alarm when this happens — this one was simply missed when that fix was made elsewhere.
    - **Evidence:**
        ```php
        try {
            $subdomain = $siteMedia->site?->subdomain;
            if (is_string($subdomain) && $subdomain !== '') {
                CloudflareCachePurgeJob::dispatch($subdomain);
            }
        } catch (Throwable $e) {
            Log::warning('ProcessImageVariantsJob: cache purge dispatch failed.', [
                'image_id' => $this->imageId,
                'message' => $e->getMessage(),
            ]);
        }
        ```

- [ ] **R3-OBS-3** · P2 — `MenuFetchJob` swallows scan-reapply failures with no `report()` — enrichment loss invisible
    - **Where:** app/Jobs/Platforms/MenuFetchJob.php:181-187
    - **Affects:** Food-business users whose Google-photo/website-scan menu enrichments (longer descriptions, dietary badges) silently fail to reapply after a menu scrape rebuild.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `report($e)` before `Log::warning(...)` in the scan-reapply catch block.
    - **Technical:** After a successful scrape rebuild, `MenuFetchJob` reapplies stored scan enrichments over the fresh rows via `MenuScanApplier`. The core scrape data (prices, dish names) is unaffected if this fails, but the enrichment silently vanishes with only a log line — no Nightwatch signal if a code change to `MenuScanApplier` or the scan-items shape starts breaking this on every run.
    - **Plain English:** After scraping a restaurant's menu, the system tries to layer back on richer details it previously extracted from Google photos. If that layering step breaks, the core menu still works, so the job calls it "good enough" — but all that extra detail silently disappears and nobody gets paged.
    - **Evidence:**
        ```php
        } catch (Throwable $e) {
            Log::warning('menu_fetch.scan_reapply_failed', ['user_id' => $this->userId, 'error' => $e->getMessage()]);
        }
        ```

- [ ] **R3-SCALE-1** · P2 — Analytics ingest shares a single 2-process Horizon lane with 10 other queues, list-ordered behind all of them
    - **Where:** config/horizon.php (`defaults.supervisor-1`, `environments.production`/`development`) + app/Jobs/Analytics/RecordAnalyticsEventJob.php
    - **Affects:** Analytics-dashboard freshness during a viral-traffic spike — the exact scenario this audit is scoped against (single-page virality driving 50–100 events/sec).
    - **Effort:** L (~1–2d) — capacity/memory planning, not a code-only change
    - **What to do:**
        - Model actual analytics throughput at 10k-user scale (50–100 events/sec sustained) against `supervisor-1`'s fixed 2-process budget, given `balance => false` means these are hard-pinned processes, not autoscaled.
        - Consider a dedicated low-memory `analytics` supervisor lane, or reordering the priority list so `analytics` isn't strictly behind `cloudflare`/`cache-warm` during a burst that also triggers those queues.
        - Any change here must be weighed against the 2026-07-22 Horizon OOM incident (documented in this file's own "Worker Lanes" docblock) that specifically consolidated per-queue supervisors down to this shared-lane layout to fix memory pressure — this is not a free change.
    - **Technical:** `RecordAnalyticsEventJob` dispatches onto the `analytics` queue, which sits 7th of 11 in `supervisor-1`'s `queue` list. Because `balance => false`, Horizon drains that list in strict priority order with a fixed process count (`maxProcesses => 2` in both `development` and `production`) — there is no autoscaling response to load, by design (per the file's own comment: "one queue:work drains the comma-joined list in listed (priority) order, and it is the only strategy that respects maxProcesses"). During a viral spike, `moderation_high`/`notifications`/`mail`/`default`/`cloudflare`/`cache-warm` jobs (all higher priority) compete for the same 2 processes before `analytics` gets serviced at all — and `images` sits even lower than `analytics`. At 100 users this is invisible (analytics volume is trivial); at 10k users with 50–100 events/sec during a burst, this becomes a genuine backlog risk for the CLAUDE.md-documented "write-heavy path." This is a **re-tier-for-scale** finding: harmless today, a real capacity constraint once traffic reaches the fleet size this audit is scoped against — flagged now so it's a planned capacity decision rather than a surprise outage.
    - **Plain English:** Right now, all the background tasks — sending emails, purging caches, AND recording page-view analytics — share just two workers, processed in a strict priority order where analytics is near the back of the line. At today's low traffic that's invisible. But if one profile goes viral and generates a flood of page-view events, those events will queue up behind everything else, and the analytics dashboards could lag significantly behind real activity during exactly the moment everyone wants to watch them. This was a deliberate trade-off made recently to avoid running the server out of memory, so fixing it isn't free — it needs a capacity-planning decision, not a quick patch.
    - **Evidence:**
        ```php
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['moderation_high', 'notifications', 'mail', 'default', 'cloudflare', 'cache-warm', 'analytics', 'images', 'streaming', 'platform_refresh', 'platform_connect'],
            'balance' => false,
            'maxProcesses' => 1,
        ```
        ```php
        'development' => [
            'supervisor-1' => ['maxProcesses' => 2],
        ```
        ```php
        $this->onQueue((string) config('partna.analytics_queue.name', 'analytics'));
        ```

- [ ] **R3-OBS-4** · P2 — `SourceGenerationException` swallowed in `ApproveEarlyAccessBuildJob.handle()` — approval scrape failure invisible to Nightwatch
    - **Where:** app/Jobs/PreAccount/ApproveEarlyAccessBuildJob.php:100-105
    - **Affects:** Staff approving early-access signups; operators monitoring the approval pipeline.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `report($e)` before `Log::warning(...)` in the `SourceGenerationException` catch block, matching the sibling `Throwable` catch two lines below.
    - **Technical:** Same pattern as R3-OBS-1, lower severity because this path is staff-initiated (a human is watching for the outcome and can retry). The `SourceGenerationException` catch marks the build `STATE_FAILED` and returns cleanly with no `report()`; the sibling `Throwable` catch does call `report($e)`.
    - **Plain English:** When staff approve an early-access signup and the re-scrape fails, the build is correctly marked broken in the database, but no alert fires — the staff member has to notice the failure themselves rather than being told.
    - **Evidence:**
        ```php
        } catch (SourceGenerationException $e) {
            $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => $e->failureCode])->save();
            Log::warning('early_access.approve.scrape_failed', ['build_id' => $build->id, 'failure_code' => $e->failureCode]);

            return;
        } catch (Throwable $e) {
            $build->forceFill(['build_state' => PreAccountBuild::STATE_FAILED, 'failure_code' => PreAccountBuild::FAILURE_SCRAPE_FAILED])->save();
            report($e);

            return;
        }
        ```

- [ ] **R3-CACHE-1** · P2 — `ReconcilePlatformTakedownJob`'s per-model save loop can flood the `cloudflare` queue and delay unrelated users' cache purges
    - **Where:** app/Jobs/Platforms/ReconcilePlatformTakedownJob.php:53-56
    - **Affects:** Every user whose site gets edited while a platform-wide staff takedown is draining — their legitimate, unrelated cache purges queue up behind the takedown's fan-out.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the per-model `$connection->save()` loop with a query-builder `update(['is_active' => false])` for the bulk state flip, and dispatch the resulting `CloudflareCachePurgeJob`s as an explicit, separately-paced batch rather than relying on the observer cascade.
        - Consider routing takedown-triggered purges through a distinct dispatch path (or staggered `delay()`) so they don't compete 1:1 with real-time, user-triggered purges on the same `cloudflare` queue.
    - **Technical:** The `chunkById(200)` loop calls `$connection->save()` per row specifically so `IntegrationConnectionObserver::saved` fires and busts each site's cache — but each save triggers its own `CloudflareCachePurgeJob` dispatch onto the `cloudflare` queue. Per `config/horizon.php`, `cloudflare` shares `supervisor-1`'s single priority-ordered lane with only 2 total processes, positioned 5th of 11 (after `moderation_high`/`notifications`/`mail`/`default`). `CloudflareCachePurgeJob` itself can take up to ~150s per purge on a degraded Cloudflare API (per its own docblock). A platform affecting thousands of connections at 10k-user scale would flood the `cloudflare` queue with thousands of purge dispatches; because `balance => false` means no elastic scaling, every *other* user's real-time cache purge (from an unrelated site edit happening during the takedown) would queue up behind that backlog for as long as it takes to drain — meaning unrelated users could see stale content at the edge for an extended window. This is a **re-tier-for-scale** call: DeepSeek's P3 "rare staff action" framing undersells the blast radius on OTHER users' unrelated purges once the connection count and purge queue depth are large enough.
    - **Plain English:** When staff disable a platform integration for everyone using it, this code walks through every affected account one at a time, and each one triggers its own "please refresh the cache" request. If thousands of accounts are affected, that's thousands of refresh requests all competing for the same small pool of workers that ALSO handle everyone else's normal cache refreshes. While that backlog drains, someone else who just edited their own site — completely unrelated to the takedown — could see their changes take much longer to appear live.
    - **Evidence:**
        ```php
        $query->chunkById(200, function ($connections): void {
            foreach ($connections as $connection) {
                $connection->is_active = false;
                $connection->save(); // per-model save so the observer busts each site's cache
            }
        });
        ```

- [ ] **R3-OBS-5** · P2 — Lock-contention timeouts in `GoogleBusinessEnrichJob` and `EnrichLinkCardJob` log warnings but never call `report()` — sustained contention invisible
    - **Where:** app/Jobs/Platforms/GoogleBusinessEnrichJob.php:348-354, app/Jobs/Platforms/EnrichLinkCardJob.php:97-103 (also present, uncaptured, in app/Jobs/Platforms/ConnectFetchJob.php's `LockTimeoutException` catch)
    - **Affects:** Operators debugging persistent lock contention on shared platform-connection rows; users with stuck enrichments/connects.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `report($e)` alongside `Log::warning(...)` in each `LockTimeoutException` catch. `GoogleBusinessEnrichJob`'s `persist()` catch currently writes `catch (LockTimeoutException)` without capturing the exception — change to `catch (LockTimeoutException $e)` first.
    - **Technical:** All three jobs use `Cache::lock(...)->block(5, ...)` to serialize writes on a shared per-user/platform key. A `LockTimeoutException` means contention exceeded the 5-second block window; each job handles it gracefully (log-and-skip or terminal-write) but none call `report()`. Sustained contention — e.g. a stuck lock holder — produces a stream of log breadcrumbs Nightwatch never sees, since Nightwatch alerts on exceptions/slow jobs, not on log queries.
    - **Plain English:** These jobs take turns writing to the same account row using a five-second "wait your turn" timer. When one job waits too long, it gives up and writes a note in the log — but doesn't sound any alarm. If a lock ever gets stuck permanently, every job behind it fails the same way, over and over, with nobody notified.
    - **Evidence:**
        ```php
        } catch (LockTimeoutException) {
            Log::warning('platforms.enrich_link_card.lock_timeout', [
                'user_id' => $this->userId,
                'platform' => $this->platform,
                'resource_id' => $this->resourceId,
            ]);
        }
        ```
        ```php
        } catch (LockTimeoutException) {
            Log::warning('google_business.enrich_job.lock_timeout', [
                'user_id' => $this->userId,
                'place_id' => $this->placeId,
                'stage' => $stage,
            ]);
        ```

- [ ] **R3-OBS-6** · P2 — Eight platform/pre-account jobs have no `failed()` callback — terminal failures land silently in `failed_jobs` with no Nightwatch signal
    - **Where:** app/Jobs/Platforms/LinkInBioScanJob.php, WebsiteMenuHtmlScanJob.php, WebsiteMenuPdfScanJob.php, GoogleMenuPhotoScanJob.php, ScanPreviousWebsiteContentJob.php, RefreshConnectionJob.php, EnrichLinkCardJob.php, app/Jobs/PreAccount/ApproveEarlyAccessBuildJob.php
    - **Affects:** Operators relying on Nightwatch for queue-health visibility; users whose content enrichment (menu scans, bio-link sync, platform refresh) silently fails with no trace beyond a generic `failed_jobs` row.
    - **Effort:** M (~2–4h) — mechanical but repetitive across 8 files
    - **What to do:**
        - Add a `failed(Throwable $e)` method to each job that calls `report($e)` and logs structured context (the job's own identifiers — `user_id`/`place_id`/`connection_id`/`signup_id` as applicable).
        - `RefreshConnectionJob` is the highest-priority of the eight: it fans out from the hourly `integrations:refresh` cron, and a silent permanent failure means a connection stops refreshing with zero alert until the next sweep re-creates the job.
    - **Technical:** All eight jobs declare `$tries`/`$backoff` but never define `failed()`. Every OTHER platform job in this codebase that reaches terminal failure (`InstagramConnectJob`, `ConnectFetchJob`, `MenuFetchJob`, `GoogleBusinessEnrichJob`, `DeleteMirroredMediaJob`, all four `Notifications/Send*Job` files) explicitly implements `failed()` calling `report($e)` — this is the established, proven convention for reaching Nightwatch in this codebase. These eight are the exceptions, not a new pattern to invent.
    - **Plain English:** These jobs are like delivery tasks with a "try a few times then give up" rule. Most similar jobs in this codebase have a standard "I've given up, send help" procedure wired up — these eight forgot to install it, so when they exhaust their retries, nobody gets told.
    - **Evidence:**
        ```php
        class RefreshConnectionJob implements ShouldBeUnique, ShouldQueue
        {
            use Dispatchable;
            use InteractsWithQueue;
            use Queueable;

            public int $tries = 0;

            public int $maxExceptions = 3;

            /** Backoff (seconds) between exception-triggered retries (not rate-limit releases). */
            public array $backoff = [30, 120, 300];

            public int $timeout = 120;
        ```

- [ ] **R3-SCALE-2** · P2 — `SendStaffBroadcastEmailToSubscriberJob` has no email-provider rate limiting — a large broadcast can exceed Resend/Postmark per-second caps
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:87-103 + app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php:101-106
    - **Affects:** Staff sending broadcast emails to the marketing list at meaningful scale — subscribers can silently fail to receive the broadcast if the provider rejects overflow sends.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `RateLimited` middleware on `SendStaffBroadcastEmailToSubscriberJob` keyed to a shared `mail-broadcast` limiter, or a short per-job stagger delay.
        - Note: commit `874bcbb4` already fixed the *dispatch-side* write amplification here (batching leaf-job creation via `Bus::batch()` chunks instead of one Redis write per job) — the gap that remains is purely provider-side throughput pacing on the `Mail::send()` calls themselves, not job/DB volume.
    - **Technical:** `SendStaffBroadcastEmailToSubscriberJob::handle()` calls `Mail::to($sub->email)->send(...)` synchronously with zero per-second rate limiting. The `mail` queue is drained by the same fixed 2-process `supervisor-1` lane as everything else; even at that modest concurrency, sequential `Mail::send()` calls easily approach or exceed a provider's stated per-second cap (e.g. Resend's free tier: 10/sec) once a subscriber list grows into the hundreds-to-thousands. The visitor-facing confirmation jobs (`SendEnquiryConfirmationJob`, `SendSubscriptionConfirmationJob`) already carry per-recipient `RateLimiter` gates — the staff-broadcast path is the one sibling missing it.
    - **Plain English:** When staff send a newsletter to the whole mailing list, each recipient gets sent to individually with no pacing. Email providers cap how many messages you can send per second; without a pace-setter, a large-enough broadcast will hit that limit and some subscribers simply won't get the email, silently. The other "send this one email" jobs already have this speed limiter — the broadcast path is the one still missing it.
    - **Evidence:**
        ```php
        $batch = Bus::batch($chunk)
            ->onQueue(config('partna.queues.mail', 'mail'))
            ->name('staff-broadcast:'.$notification->id)
            ->allowFailures()
            ->dispatch();
        ```
        ```php
        Mail::to($sub->email)->send(
            new StaffBroadcastMail($notification, $unsubscribeUrl)
        );
        ```

- [ ] **R3-SCALE-3** · P2 — `ProcessVideoVariantsJob` declares three backoff gaps but `$tries = 2` only ever consumes the first
    - **Where:** app/Jobs/ProcessVideoVariantsJob.php:40-42
    - **Affects:** Video uploaders — a single transient R2 or FFmpeg failure exhausts all retries after one 60s wait, permanently failing an upload that a longer retry window would likely have recovered (the job's own `failed()` handler deletes the partial R2 artifacts, so recovery requires a full re-upload).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Raise `$tries` to 3 or 4 so the declared `[60, 300, 900]` backoff array is actually exercised, or trim the array to `[60]` if a single retry is genuinely the intent (and correct the "exponential backoff" comment either way).
    - **Technical:** Laravel consumes `$backoff` elements 0-indexed per retry gap. With `$tries = 2` (1 initial attempt + 1 retry), only `backoff[0]` (60s) is ever reached — `backoff[1]` (300s) and `backoff[2]` (900s) are dead. `$timeout = 720` and the `redis_video` connection's `retry_after = 3600` both have ample headroom for the longer gaps, so this is purely an under-provisioned retry count, not a resource constraint. This is the single most concrete, verified, user-facing correctness bug in this bundle — every video upload rides this retry path.
    - **Plain English:** This is like giving a delivery driver a full three-step restart plan — wait a minute, then five minutes, then fifteen — but only letting them use step one before giving up for good. A single hiccup in video processing (a brief storage blip, a transient encoder error) permanently fails the upload, and the user has to start over from scratch.
    - **Evidence:**
        ```php
        public int $tries = 2;

        // Exponential backoff (JOB-11): transient R2/transcode failures get progressively longer retry gaps.
        public array $backoff = [60, 300, 900];
        ```

- [ ] **R4-RES-2** · P2 — Website-menu AI scan jobs retry from scratch, re-billing Mistral/DeepSeek on any transient failure
    - **Where:** app/Jobs/Platforms/WebsiteMenuPdfScanJob.php:30-33, :68-75; app/Jobs/Platforms/WebsiteMenuHtmlScanJob.php:32-35, :70-70
    - **Affects:** the shared AI-spend budget (Mistral OCR + DeepSeek structuring) for the automatic previous-website menu scan.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Set `$tries = 1` on both `WebsiteMenuPdfScanJob` and `WebsiteMenuHtmlScanJob` (keep `$backoff` defined, matching `GoogleMenuPhotoScanJob`'s own comment, to satisfy `JobHygienePolicyTest`), so a downstream failure after a successful billed AI call logs and waits for the next scan trigger instead of re-running the paid pipeline.
    - **Technical:** Both jobs use Laravel's default retry behavior (`$tries = 3`, `$backoff = [30, 120]`) around `MenuAiExtractor::ocrDocumentUrl()` / `structure()`, each gated by `AiSpendBudget::tryClaim()` (`mistral_ocr` / `deepseek_structure`). Neither job caches intermediate output, so any exception thrown *after* a successful OCR or structure call — e.g. a `MenuScanApplier::apply()` DB error, a worker restart — causes the next attempt to redo the entire pipeline and re-claim/re-pay for both AI calls. This is the exact pattern the team already closed for the sibling `GoogleMenuPhotoScanJob` (app/Jobs/Platforms/GoogleMenuPhotoScanJob.php:57-63), which sets `$tries = 1` specifically "to avoid... re-billing OCR on a flaky afternoon" and keeps `$backoff` only to satisfy the job-hygiene policy test — the same guard was simply never applied to these two later jobs.
    - **Plain English:** When the menu-scanning job for a website PDF or page hiccups on something unrelated to the AI call itself — like a database blip right after the AI already did its (paid) work — the job tries the whole thing again from scratch, paying the AI provider a second time for identical work. The team already fixed this exact problem for the similar "scan Google Business photos" job; these two newer jobs just never got the same fix.
    - **Evidence:**
        ```php
        // app/Jobs/Platforms/WebsiteMenuPdfScanJob.php:30-33
        public int $tries = 3;

        /** @var list<int> */
        public array $backoff = [30, 120];
        ```
        ```php
        // app/Jobs/Platforms/GoogleMenuPhotoScanJob.php:57-63 (the established fix pattern)
        // AI spend: no automatic retries — a failed scan logs and waits for the
        // next enrichment rather than re-billing OCR on a flaky afternoon.
        // ($backoff is moot at one attempt; declared for the job-hygiene policy.)
        public int $tries = 1;

        /** @var list<int> */
        public array $backoff = [60];
        ```

## P3 — Nice to have

- [ ] **R2-SCHED-3** · P3 — `PruneExpiredPreAccountBuilds` plucks all candidate IDs into memory before its per-candidate loop
    - **Where:** app/Console/Commands/PruneExpiredPreAccountBuilds.php:67-70
    - **Affects:** Daily `builds:prune-expired` sweep — a large accumulation of expired pre-account builds (e.g. after a growth campaign) materializes as one in-memory array of UUIDs before the loop starts.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->pluck('id')` with `->select('id')->cursor()` and iterate the cursor in place of the `Collection`; the loop body already re-queries each build fresh inside its own transaction (`PreAccountBuild::query()->whereKey($buildId)->first()`), so nothing downstream depends on `$candidates` being a materialized Collection except the `$candidates->count()` logging call, which can switch to a separate `count()` query.
    - **Technical:** `PreAccountBuild::live()->where(...)->pluck('id')` loads every candidate UUID into a Collection up front. This is low-risk to fix: the codebase already runs `cursor()->each()` with per-row queries/mutations nested inside the callback in three sibling commands in this same file family (`PruneCompletedExportsCommand`, `SweepPurgedVideoArtifactsCommand`, `SweepStaleExportsCommand`), so the pattern is proven safe against this connection/driver combination — this isn't a novel risk, just an unapplied precedent. At today's scale (hundreds of builds) the UUID list itself is trivially small in memory; this is forward-looking hardening ahead of a viral growth event, not a current problem.
    - **Plain English:** The daily cleanup routine writes down every expired listing's ID on a clipboard before walking around to double-check and remove each one. With today's handful of listings, the clipboard is short and harmless. If the platform ever has a viral spike and thousands of listings expire at once, that clipboard gets long — still not dangerous, but unnecessary. Walking the list one entry at a time without writing it all down first is the tidier approach, and the rest of the codebase already does it that way in similar spots.
    - **Evidence:**
        ```php
        $candidates = PreAccountBuild::live()
            ->where(fn ($q) => $q
                ->where(fn ($qq) => $qq->whereNotNull('expires_at')->where('expires_at', '<', $cutoff))
                ->orWhere(fn ($qq) => $qq->where('build_state', PreAccountBuild::STATE_FAILED)->where('updated_at', '<', $failedCutoff)))
            ->pluck('id');
        ```

- [ ] **R3-CCH-1** · P3 — `GuardsMediaProcessing` uses raw `Redis::set`/`Redis::del` instead of the dedicated `cache_locks` connection
    - **Where:** app/Jobs/Concerns/GuardsMediaProcessing.php:28-39
    - **Affects:** Media processing jobs (`ProcessImageVariantsJob`, `ProcessVideoVariantsJob`, `ProcessLogoVariantsJob`) — architectural consistency of lock placement, not an active production risk.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Route through `Cache::lock($lockKey, $this->timeout + 60)` (as `GoogleBusinessEnrichJob`, `EnrichLinkCardJob`, and `ConnectFetchJob` already do elsewhere in this codebase) so the lock lands on the dedicated `cache_locks` Redis connection instead of the bare default connection.
    - **Technical:** Corrected from the draft's premise — verified against `config/database.php`: the bare `Redis` facade (used here) resolves to the `'default'` connection (`REDIS_DB`, default `0`), which per this codebase's own layout is the **queue/Horizon** database, not the `'cache'` connection (`REDIS_CACHE_DB`, default `1`) that `Cache::flush()` actually targets. So `Cache::flush()` does **not** release these locks as the original draft claimed — they're on a different DB entirely. The real (much lower-severity) issue is that these locks bypass the `cache_locks` connection (`REDIS_CACHE_LOCKS_DB`, default `4`) that `config/database.php` created specifically "so `Cache::flush()` on the data connection never releases locks held by in-flight workers" — meaning this trait is inconsistent with the established `Cache::lock()` convention used everywhere else, and its keys instead live alongside Horizon's own queue bookkeeping in DB 0. No realistic operational trigger (short of a deliberate `FLUSHDB` against the queue database, which would already be catastrophic for Horizon regardless) makes this a live risk today — it's a consistency/hygiene item, re-tiered down from the draft's P2.
    - **Plain English:** This code uses a generic "reserve this slot" signal instead of the dedicated lock system the rest of the codebase uses. The originally-suspected danger — a routine cache-clearing operation accidentally releasing these locks — turns out not to apply here, because these locks live in a different storage bucket than the one that gets cleared. It's still worth tidying up to match the established pattern, just not urgent.
    - **Evidence:**
        ```php
        protected function acquireProcessingLock(string $lockKey): bool
        {
            return (bool) Redis::set($lockKey, '1', 'EX', $this->timeout + 60, 'NX');
        }

        protected function releaseProcessingLock(string $lockKey): void
        {
            Redis::del($lockKey);
        }
        ```
        ```php
        // Dedicated DB for atomic lock keys so Cache::flush() on the data
        // connection never releases locks held by in-flight workers.
        'cache_locks' => [
            'database' => env('REDIS_CACHE_LOCKS_DB', 4),
        ],
        ```

- [ ] **R3-JOB-5** · P3 — `SyncCustomerMarketingOptInJob` missing `ShouldBeUnique` on a concurrency-sensitive cache-column write
    - **Where:** app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php:29
    - **Affects:** `Customer.marketing_opt_in_cached` — a UX/perf shortcut column, not a source of truth.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `implements ShouldBeUnique` + `uniqueId(): string { return $this->subscriptionId; }` + a short `uniqueFor`.
    - **Technical:** Two concurrent syncs for the same subscription could race and leave a stale cached value. The job's own docblock already documents that this column is a fallback shortcut — `isMarketingOptedIn()` falls back to a live `EmailSubscription` lookup when the cache disagrees, and any future subscription change re-dispatches this job — so the staleness this race could introduce is both rare and self-correcting on the very next sync. Worth the one-line hardening, not urgent.
    - **Plain English:** Two workers could both update the same "is this customer subscribed" shortcut at once, and the slower one could overwrite the faster one's more current answer. In practice the system already tolerates a few seconds of staleness here and self-corrects on the next update, so this is low-stakes polish.
    - **Evidence:**
        ```php
        class SyncCustomerMarketingOptInJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;
        ```
        ```php
        $customer->marketing_opt_in_cached = $subscription->status === 'subscribed';
        $customer->saveQuietly();
        ```

- [ ] **R3-OBS-7** · P3 — `SendEnquiryNotificationJob` hardcodes its queue name instead of reading it from config
    - **Where:** app/Jobs/Notifications/SendEnquiryNotificationJob.php:62
    - **Affects:** Operations — if `partna.queues.notifications` is ever repointed, this one job silently stays on the old queue name.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Replace `$this->onQueue('notifications')` with `$this->onQueue(config('partna.queues.notifications', 'notifications'))`, matching every sibling notification job in this same directory.
    - **Technical:** Every other job in `app/Jobs/Notifications/` reads its queue name from `config('partna.queues.notifications', 'notifications')`. This is the one hardcoded exception. Harmless today because the literal matches the config default, but a silent drift risk if the config value is ever changed for routing purposes.
    - **Plain English:** Every similar notification job gets its delivery lane from a shared settings page — except this one, which has the lane name written in permanent marker. Today both point to the same place, so nothing's broken, but it's an inconsistency waiting to bite.
    - **Evidence:**
        ```php
        public function __construct(
            public readonly string $enquiryId,
            public readonly string $blockId,
        ) {
            $this->onQueue('notifications');
        }
        ```

- [ ] **R4-RES-3** · P3 — Orphaned temp file if `rename()` fails in `VideoVariantService::makeTmpFile`
    - **Where:** app/Services/Media/VideoVariantService.php:535-547
    - **Affects:** worker local disk over long uptimes, in the unlikely event of a rename failure.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Check `rename()`'s return value; on `false`, `@unlink($path)` before throwing, so the orphaned `tempnam()` file doesn't linger.
    - **Technical:** `makeTmpFile()` creates a real (empty) file via `tempnam()` at `$path`, then calls `rename($path, $withExt)` to give it the extension FFmpeg needs, discarding the boolean return. If `rename()` fails, `$path` is never cleaned up — only `$withExt` paths are unlinked in `processVariants()`'s `finally` block. Since `$path` and `$withExt` are both in `sys_get_temp_dir()` (same directory, same filesystem), an actual `rename()` failure here would require an unusual condition (permissions change mid-request, ENOSPC, or the directory disappearing) rather than the classic cross-device-rename failure mode — so this is a low-probability, low-blast-radius leak of small (empty) files, not a disk-fill risk.
    - **Plain English:** When the video-processing code creates a temporary placeholder file and then renames it to add the right file extension, it doesn't check whether that rename actually worked. On the rare occasion it fails, the original placeholder file is left behind as small, harmless clutter instead of being cleaned up.
    - **Evidence:**
        ```php
        private function makeTmpFile(string $prefix, string $suffix): string
        {
            $path = tempnam(sys_get_temp_dir(), $prefix);
            if (! $path) {
                throw new \RuntimeException("Failed to create temp file ({$prefix}).");
            }

            // Rename to add the desired extension so FFmpeg knows the container.
            $withExt = $path.$suffix;
            rename($path, $withExt);

            return $withExt;
        }
        ```

## Review-only addendum — pilot tier

> **Source:** `docs/reviews/2026-07-23-worker-async-layer-review.md` §8 roadmap, specified in full in
> `docs/superpowers/plans/2026-07-23-worker-async-pilot-PROMPT.md` §4.
>
> These twelve units have **no pipeline finding** behind them. The review states plainly (§4, closing
> paragraph) that the audit pipeline "caught the code-shaped ones and none of the environment-shaped
> ones" — these are the environment- and config-shaped items it structurally could not surface. They
> are folded in here so that **this file remains the single doneness source of truth** and
> `scripts/audit/archive-done.sh` sees every unit of the run.
>
> **P-tiers on `RV-*` entries are assigned, not inherited.** The pipeline never scored these. Each
> tier below is derived from the review's own roadmap tier plus its severity language; adjust freely.
> Roadmap tier is recorded per entry so the derivation stays auditable.
>
> `RV-1` and `RV-2` are **dashboard/env actions Josh performs himself** — no code, no subagent. They
> carry checkboxes because an untracked unit is an untracked unit; they are not executable by this run.
>
> **Out of scope for this run** (own prompt: `2026-07-23-worker-async-launch-PROMPT.md`): work unit 10
> (`R3-SCALE-1`) and roadmap items 11, 12, 16–20.

- [ ] **RV-1** · P1 — Valkey `maxmemory-policy` on `partna_dev_cache` is unverified; an `allkeys-*` policy evicts queued jobs with zero trace
    - **Where:** Laravel Cloud dashboard → `partna_dev_cache` (and the production cache instance). Not a code path — the app's Redis ACL denies `CONFIG` and `INFO`.
    - **Affects:** Every queued job in the system. This is the only failure mode in the whole review that loses work leaving no evidence at all.
    - **Effort:** S — dashboard read, then a one-field change if wrong. 🙋 **Josh action, not code.**
    - **Roadmap:** #1 (pilot) · **Review:** §9 Q1, §10.1
    - **What to do:**
        - Open the Laravel Cloud dashboard and read `maxmemory-policy` on `partna_dev_cache`.
        - If it is any `allkeys-*` value, set it to `noeviction`. Repeat for the production cache instance.
        - Record the observed value here when done, so the next review does not have to re-ask.
    - **Technical:** Queue payloads (DB 0), cache (DB 1), sessions (DB 2) and lock keys (DB 4) are all co-resident on one 250 MB Valkey instance. Under any `allkeys-*` policy, Redis evicts by its own key-selection heuristic once `maxmemory` is reached — it has no concept that a `queues:*` list holds work in flight. An evicted job throws nothing, writes no `failed_jobs` row and produces no Nightwatch event; the work simply never happens. `noeviction` converts the same memory-pressure condition into a write error at dispatch time, which is loud, attributable and recoverable. The app cannot self-diagnose this: `CONFIG` and `INFO` are both denied by the ACL, so the dashboard is the only source of truth. §9 Q1 escalates this to "the top P0" if the policy turns out to be `allkeys-lru`.
    - **Plain English:** All the background work waiting to be done is stored in the same limited memory bucket as the app's temporary data. If that bucket is set to "when full, throw out whatever looks least used," it can throw away jobs that haven't run yet — and nothing anywhere records that it happened. A customer's confirmation email, a video that never finishes processing, a deletion request that quietly evaporates. Setting it to "when full, refuse new writes" turns an invisible disappearance into a visible error somebody can act on. Nobody can check this from inside the app; it has to be read off the hosting dashboard.

- [ ] **RV-2** · P2 — `HORIZON_NOTIFICATION_EMAIL` unset, so twelve tuned queue-wait thresholds alert nobody
    - **Where:** Laravel Cloud environment variables on `development` and `production`; thresholds live in `config/horizon.php` under `waits`.
    - **Affects:** Operators. Queue backlog is unmonitored end to end — today it is observable only by manually opening the Horizon dashboard and looking.
    - **Effort:** S — one env var per environment. 🙋 **Josh action, not code.**
    - **Roadmap:** #2 (pilot) · **Review:** §9 Q4
    - **What to do:**
        - Set `HORIZON_NOTIFICATION_EMAIL` (or `HORIZON_NOTIFICATION_SLACK_WEBHOOK`) on `development` and on `production`.
        - No code change: `config/horizon.php`'s twelve `waits` entries are already tuned and read these values.
    - **Technical:** Horizon's long-wait notifier is inert unless a notification channel is configured — the twelve per-queue `waits` thresholds already in `config/horizon.php` are evaluated and then dispatched to no channel. **Nightwatch cannot close this gap structurally, not merely by configuration:** it instruments job *execution* — exceptions thrown, jobs running slow — and a job that no worker has consumed never executes, so it never emits a Nightwatch event. A queue growing without bound is therefore the one class of queue failure that neither existing monitor can see. The review's §8 backpressure note calls this "the minimum viable answer"; a depth threshold on the existing `/api/health/*` surface would be the fuller one.
    - **Plain English:** The system already knows how long each kind of background job is allowed to sit waiting before something is wrong — someone tuned twelve of those limits. But there's no address on file to send the warning to, so every one of those alarms rings in an empty room. The other monitoring tool can't cover for it either: that one watches jobs while they run, and the whole problem here is jobs that never get picked up to run at all. Adding an email address is the entire fix.

- [ ] **RV-3** · P1 — `ShouldBeUnique` with no `$uniqueFor` takes a permanent lock that silently black-holes every future dispatch
    - **Where:** app/Jobs/Platforms/LinkInBioScanJob.php:32, app/Jobs/Platforms/ScanPreviousWebsiteContentJob.php:53, tests/Feature/.../HorizonQueueCoverageTest.php:394-400
    - **Affects:** Users whose bio-link scan or previous-website scan is dispatched after a worker was killed mid-job — the scan never runs again for that key, permanently, with no error surfaced anywhere.
    - **Effort:** S · autonomous
    - **Roadmap:** #3 (pilot)
    - **What to do:**
        - Declare an explicit `$uniqueFor` on both jobs. Both carry `$timeout = 60`; pick a value comfortably above it and consistent with the sibling scraping jobs already in that directory.
        - Fix the guard in `HorizonQueueCoverageTest`: it currently does `$uniqueFor = $defaults['uniqueFor'] ?? null; if (! is_int($uniqueFor) ...) { continue; }`. A job that declares **no** `uniqueFor` yields `null`, fails `is_int`, and is skipped — so the `continue` written to tolerate constructor-assigned values also swallows the genuinely dangerous case.
        - Make a missing `uniqueFor` on a `ShouldBeUnique` job **fail** the test. Confirm the new assertion fails against the unfixed jobs before applying the fix.
    - **Technical:** `UniqueLock::acquire()` reads `($job->uniqueFor ?? 0)` and hands it to `RedisLock::acquire()`, which branches on `if ($this->seconds > 0)`. With `0` it falls through to a plain `SETNX` **with no expiry**. If a worker is SIGKILLed — OOM, deploy, timeout — before `UniqueLock::release()` runs, that key survives in Redis **forever**, and every subsequent dispatch for the same `uniqueId` is silently discarded: no exception, no `failed_jobs` row, no Nightwatch event. The lock lives in DB 4, deliberately excluded from `Cache::flush()`, so clearing it requires manual Redis surgery by someone who already knows to look. Note the interaction with `RV-1`/`RV-4`: OOM kill is exactly the event that both strands the lock and produces no `failed()` call.
    - **Plain English:** These two jobs use a "only one of me at a time" flag, implemented as a token taken before the work and handed back after. Because no expiry was set on the token, a worker that dies mid-job — which happens on any deploy or out-of-memory kill — takes the token to the grave. From then on every future attempt to run that job sees the token already taken and quietly does nothing. The user waits for a scan that will never run, and nothing in the logs, the error tracker or the failed-jobs table says so. There is also a test that was supposed to catch this class of mistake, but its guard clause skips exactly the case it was meant to flag.

- [ ] **RV-4** · P1 — Worker memory is over-committed: 1280 MiB permitted on a 1024 MiB box
    - **Where:** `config/horizon.php` supervisor blocks; Laravel Cloud Worker cluster instance size
    - **Affects:** Every job on the Worker box. An OOM kill is a SIGKILL: no `failed()`, no retry scheduling, orphaned processing locks and orphaned temp files.
    - **Effort:** S to apply · 🔒 **blocker: cost decision — Josh's call, not the implementer's**
    - **Roadmap:** #4 (pilot) · **Review:** §3.1, §9 Q3 · **`RV-12` depends on the outcome**
    - **What to do:**
        - Present both options with their cost: raise the Worker instance above `flex-1gb`, or lower `supervisor-videos.memory`.
        - Do not implement either until Josh chooses. `RV-12` (split mail supervisor) is blocked on this decision and must not land first.
    - **Technical:** Permitted worker heap sums to `2 × 256 (supervisor-1) + 256 (supervisor-long) + 512 (supervisor-videos) = 1280 MiB` on a **1024 MiB** `flex-1gb` instance — a 25% over-commit *before* counting the Horizon master process, the three middleman processes, or the scheduler sharing the same instance. Horizon's `memory` key is a **restart-after-exceeded threshold checked between jobs**, not a cap: nothing prevents the sum being reached mid-job, and by the time Horizon would notice, the kernel has already acted. Two compounding factors: an OOM kill is SIGKILL, so `failed()` never runs and the job leaves behind whatever locks and temp files it held; and ffmpeg's resident memory lives outside PHP's allocator entirely, so `memory_get_usage()` — the number Horizon actually checks — cannot see the largest consumer on the box. The 2026-07-22 OOM incident is the precedent.
    - **Plain English:** The worker machine has one gigabyte of memory, and the configuration gives permission to use one and a quarter gigabytes — before counting several supporting processes that also live there. The safety valve that is supposed to prevent this only checks between jobs, so it cannot stop a job that grows past the limit while running. And the video encoder, which is the hungriest thing on the box, allocates memory in a way the safety valve is structurally unable to measure. When the operating system runs out and kills a worker, that worker gets no chance to clean up: no "this failed" record, no retry, and any locks or temporary files it was holding are simply abandoned. The fix is either a bigger machine or a smaller video allowance — a cost trade-off, not an engineering one.

- [ ] **RV-5** · P2 — `integrations:refresh` fans out uncapped and unstaggered onto a near-lowest-priority queue
    - **Where:** app/Console/Commands/RefreshIntegrationConnectionsCommand.php:32-39
    - **Affects:** Platform-connection freshness, plus every other job sharing `supervisor-1` during the burst window.
    - **Effort:** S/M · autonomous
    - **Roadmap:** #5 (pilot)
    - **What to do:**
        - Add a per-run cap and a dispatch stagger (a spread of `->delay()` values) so the burst is bounded.
        - Preserve the intent of the existing "capacity scales with the fleet" comment — the goal is to bound the burst, not to abandon the design.
        - Consider moving the 03:00 slot off the collision minute.
    - **Technical:** The command dispatches one `RefreshConnectionJob` per due connection inside a tight `lazyById()` loop with no cap and no stagger. It fires at 03:00 — the same minute as eleven other scheduled entries, eight of which query Postgres directly from the same 1 GiB container. Its target queue, `platform_refresh`, sits **second-to-last** in `supervisor-1`'s strict-priority list, so the single largest dispatch burst in the system is aimed at the second-lowest-priority lane, behind ten other queues drained by two processes. Refresh latency is therefore the first thing that degrades under growth (review §8: "at 10×… refresh latency degrades first and silently"), and it degrades without alerting because backlog alerting is inert until `RV-2` lands.
    - **Plain English:** Once an hour the system looks up every connected account that is due for a refresh and immediately queues a job for each one, all at once, with no ceiling on how many. It does this at 3 a.m., the same minute that eleven other scheduled tasks start, most of which are hitting the same database on the same small server. And the lane it queues into is second-from-last in priority, so the biggest pile of work in the system is aimed at nearly the slowest-moving line. Spreading the dispatches over a window and capping how many go out per run keeps the design intact while removing the spike.

- [ ] **RV-6** · P1 — Google Places has no spend ceiling in code; it is the only uncapped paid API in the system
    - **Where:** app/Http/Controllers/Api/Platforms/GoogleBusinessController.php:101 → `fetchPlaceDetails`; app/Services/.../GoogleBusinessService
    - **Affects:** Platform spend. Places SKUs bill at **$5–$35 per 1,000 calls** and the primary dashboard path has neither a burst limiter nor a budget gate.
    - **Effort:** M · 🔒 **blocker: money**
    - **Roadmap:** #6 (pilot)
    - **What to do:**
        - Add an `ApifyBudget`-style gate around the Places call path.
        - Study `ApifyBudget` first, and apply `R4-RES-1`'s lesson: **the claim must be taken at every billed call site, not once per logical operation**, or the accounting undercounts in exactly the way Apify's currently does.
        - Prefer a per-user **and** global bound, rather than reproducing `ApifyBudget`'s global-only, date-keyed design — a single user should not be able to exhaust the platform's daily allowance.
    - **Technical:** No spend ceiling exists in code for Places. What exists is burst limiting on one path only — the `preaccount-places` limiter at 30/min plus `pool_concurrency` 5 — and the **primary dashboard path has neither**. `ApifyBudget` guards a different vendor entirely and does nothing here. The vendor side offers no backstop either: Google's own billing documentation states that *"Setting a budget does not automatically cap Google Cloud or Google Maps Platform usage or spending"* — budgets are alerts, not limits. That combination makes this the only place in the system where a loop, a retry storm or an abusive caller converts directly into unbounded billable spend with nothing in the path to stop it.
    - **Plain English:** Every lookup of a business on Google Maps costs real money — between half a cent and three and a half cents each. Everywhere else in the system that spends money on an external service has a daily allowance that gets checked before each call. This one does not. There is a speed limiter on one secondary path, but the main path people actually use from the dashboard has nothing at all. And Google's own "budget" feature does not help: their documentation is explicit that setting a budget sends you an alert but never stops the spending. So a bug that loops, or someone hammering the endpoint, bills without limit. The fix is the allowance-check the rest of the codebase already uses — with one improvement: bound it per user as well as globally, so one account cannot drain everyone's allowance.

- [ ] **RV-7** · P2 — `laravel/horizon` 5.47.0 carries a metric-clearing bug that triggers under exactly this phpredis + scan-prefix configuration
    - **Where:** `composer.json` / `composer.lock` — constraint is already `^5.45`
    - **Affects:** Horizon dashboard metrics accuracy.
    - **Effort:** S · autonomous — **first unit, own commit**
    - **Roadmap:** #7 (pilot)
    - **What to do:**
        - Run `composer update laravel/horizon`. The existing `^5.45` constraint already permits the fix, so no `composer.json` edit is needed.
        - Land it **first and as its own commit**, so the lockfile churn is not tangled with logic changes.
        - Confirm the resulting version is ≥5.47.2 and the suite is green.
    - **Technical:** Horizon 5.47.2 fixes a metric-clearing defect that manifests specifically under **phpredis with a scan prefix configured**. This application runs phpredis 6.3.0 with the prefix `partna_database_` on Horizon **5.47.0** — the bug's trigger conditions, exactly. Related codebase gotcha worth remembering while touching this area: phpredis `SCAN` sees raw *prefixed* keys and its cursor starts at `null` rather than `0`.
    - **Plain English:** The queue dashboard is running a version of its library with a known bug in how it clears out old statistics, and the bug only shows up in the exact Redis setup this app uses. The fix is already released, and the project's version rules already allow it — so this is a one-command dependency update. It goes first and alone so the lockfile change stays easy to read in the history.

- [ ] **RV-8** · P1 — `RefreshController::refresh()` runs vendor scrapes inline in a `foreach`, up to ~108 s × row count in one request
    - **Where:** app/Http/Controllers/Api/Platforms/RefreshController.php:40, :76-82
    - **Affects:** Any user clicking refresh with more than one connected platform — the request can exceed any reasonable HTTP timeout, and holds a PHP-FPM worker for its whole duration.
    - **Effort:** S to implement · 🔒 **blocker: changes a public response contract; frontend is a separate repo**
    - **Roadmap:** #8 (pilot)
    - **What to do:**
        - Dispatch the existing `RefreshConnectionJob` per row instead of calling `PlatformRefresher::refresh()` inline. That job already wraps this exact call for the cron dispatcher, with rate limiting and queueing in place.
        - **Present the new response shape to Josh before implementing.** This changes the endpoint from synchronous result to accepted-for-processing; the frontend lives in a separate repository and must not be broken silently.
    - **Technical:** `refresh()` calls `PlatformRefresher::refresh()` **inline, inside a `foreach` over every connected row**. `SafeUrlFetcher`'s timeouts are **per-hop**, not per-call: 8 s × 6 hops, doubled by the 403 alternate-user-agent retry, is ≈96 s of fetch budget per row, ~108 s worst case end to end — multiplied by row count in a single request. `FetchBudget` (20 s wall-clock) exists and would bound this, but it is opt-in and this path does not opt in. The queued alternative is not speculative: `RefreshConnectionJob` already exists, already wraps this call, and is already the path the hourly cron uses.
    - **Plain English:** When a user hits "refresh my connections," the server tries to re-scrape every connected platform one after another while the browser waits. Each scrape can follow up to six redirects at eight seconds each, and retries once more if it gets blocked — roughly a minute and a half for a single connection, in the worst case, multiplied by however many they have connected. The browser gives up long before that, and meanwhile the server has one of its request handlers tied up doing nothing but waiting. The queued version of this exact work already exists and is already used by the nightly job; this endpoint just needs to hand off to it. The catch is that the endpoint stops returning "here are your results" and starts returning "started, check back" — which the dashboard has to be updated to understand.

- [ ] **RV-9** · P3 — `block_for` is `null` on all four queue connections, so workers poll in userland instead of blocking on `BLPOP`
    - **Where:** `config/queue.php` — the `redis`, `redis_scraping`, `redis_gdpr` and `redis_video` connections
    - **Affects:** Job pickup latency and Redis command volume across every queue.
    - **Effort:** S · autonomous
    - **Roadmap:** #9 (launch tier in the review; pulled into this run)
    - **What to do:**
        - Set `block_for` to ~5 on all four connections.
        - **Do not use `0`.** Verify Horizon still terminates cleanly after the change (a deploy or `horizon:terminate`).
    - **Technical:** `null` means the worker never issues a blocking `BLPOP`; it polls in PHP userland with `--sleep` gaps between attempts, paying both in pickup latency (a job can sit for up to the sleep interval after arriving) and in Redis command volume (every poll is a round trip, whether or not work exists). A positive `block_for` gives near-instant pickup while still returning control periodically so signals can be handled. **`0` is the trap:** it blocks indefinitely and, per the Laravel documentation, *"will also prevent signals such as `SIGTERM` from being handled until the next job has been processed"* — which breaks zero-downtime deploys, since the worker will not acknowledge the shutdown signal until a job happens to arrive.
    - **Plain English:** Right now each worker checks the job list, finds nothing, sleeps a moment, and checks again — over and over. Redis supports a much better arrangement: "wake me the instant something arrives, but give up after five seconds." That gets jobs started faster and cuts a lot of pointless chatter. There is one setting to avoid: "wait forever." That version means the worker stops listening for the shutdown signal during a deploy and hangs until a job happens to show up.

- [ ] **RV-10** · P2 — Moderation `Notify*` jobs write their idempotency marks non-transactionally and re-send on retry
    - **Where:** app/Jobs/Moderation/NotifyOnCallStaffJob.php, NotifyReportedUserJob.php, NotifyReporterJob.php
    - **Affects:** On-call staff receiving duplicate pages, and reporters receiving duplicate emails about the same report. `NotifyReporterJob` is the worst of the three.
    - **Effort:** M · autonomous
    - **Roadmap:** #10 (launch tier in the review; pulled into this run)
    - **What to do:**
        - Follow the correct pattern, which already exists in the same directory: `SuspendUserJob`, `SuspendSiteJob` and `QuarantineMediaJob` wrap all three steps in a single transaction and are consequently safe.
        - For `NotifyReporterJob`, a **per-recipient idempotency key is required regardless** — one transaction wrapped around a loop of sends still re-sends every recipient on retry.
    - **Technical:** All three write `markDispatched` (committed) → send → `markCompleted` (committed) with no transaction spanning the sequence, guarded only by `if ($entry->status === 'completed') return;`. A crash anywhere between the send and the completion mark leaves the row at `dispatched`, which that guard does not catch, so the retry re-sends. `NotifyReporterJob` compounds it: it loops over reporters with **no per-recipient key**, so a crash midway through re-emails everyone already contacted on the next attempt. The safe siblings in the same directory demonstrate the fix shape, so this is applying an established local pattern rather than inventing one.
    - **Plain English:** These jobs mark "started," send the message, then mark "finished" — with each mark saved separately. The guard against sending twice only checks for "finished," so a crash between sending and marking finished leaves the job looking like it never sent, and the retry sends again. The reporter-notification job is worse still: it emails a whole list of people in a loop with no record of which ones it already reached, so a crash halfway through means everyone in the first half gets a second copy. Three of their neighbours in the same folder already do this correctly by saving all the marks together in one atomic step.

- [ ] **RV-11** · P2 — No sweeper exists for orphaned `platforms/` media in R2 — the only uncovered failure class in the review
    - **Where:** New console command + scheduled entry. Existing precedents: `gdpr:sweep-purged-video-artifacts`, `media:gc-orphaned-video-artifacts`
    - **Affects:** R2 storage cost. Scraped Instagram media orphaned by a failed mirror-delete leaks indefinitely with nothing to reclaim it.
    - **Effort:** M · autonomous
    - **Roadmap:** #14 (launch tier in the review; pulled into this run)
    - **What to do:**
        - Add a console command plus a scheduled entry, modelled on the two existing video sweepers.
        - Use `->onOneServer()` and an explicit `withoutOverlapping(N)` where **N exceeds the plausible run time** — see `R2-SCHED-1`: a TTL shorter than the cadence lets the lock expire while the run is still executing.
        - Keep it off the 03:00 collision minute.
        - Include an age guard so the sweeper cannot race an in-flight mirror operation.
    - **Technical:** The review found ten `DeleteMirroredMediaJob` failures at `2026-07-23 03:21:15` — R2 returning 4xx on a `platforms/instagram/...` prefix listing — and that job's `failed()` only reports and logs; it schedules no reclamation. Neither existing sweeper covers the gap: `gdpr:sweep-purged-video-artifacts` reads `EVENT_PURGED` audit rows for **video** paths, and `media:gc-orphaned-video-artifacts` LISTs the **`videos/`** prefix. **Nothing touches `platforms/`.** This is the one failure class in the review with no compensating control anywhere in the system, which is why it is here despite being a launch-tier roadmap item.
    - **Plain English:** When a scraped Instagram image needs deleting from cloud storage and that delete fails, the failure gets logged and then nothing else happens — the file stays there, paid for, forever. There are two existing cleanup routines that go looking for exactly this kind of abandoned file, but both were written for video and only look in the video folder. Nobody has ever swept the platform-media folder. The review found ten of these failures in a single night. The fix is a third cleanup routine on the same pattern as the first two, with two cautions: give its "don't run twice at once" lock a longer life than the gap between runs, and make it ignore recently-created files so it cannot delete something a mirror job is still writing.

- [ ] **RV-12** · P2 — Transactional mail shares a 2-process supervisor with nine other queues; two long jobs stall every email
    - **Where:** config/horizon.php:96-104
    - **Affects:** Every transactional email — enquiry confirmations, notifications, GDPR deletion links — during any period when two long-running jobs occupy `supervisor-1`.
    - **Effort:** M · 🔒 **blocker: strictly after `RV-4`. Do not implement before that decision lands.**
    - **Roadmap:** #15 (launch tier in the review; pulled into this run)
    - **What to do:**
        - **Gate on `RV-4`.** A fourth supervisor adds a middleman plus a worker (~180 MiB) to a box already permitting 1280 MiB on 1024 — splitting first trades a latency problem for the 2026-07-22 OOM. The review states this sequencing explicitly and `config/horizon.php:96-104` already carries a caution comment.
        - **Correct the comment while in the file.** It currently claims `balance => false` is "the only strategy that respects `maxProcesses` — `simple`/`auto` floor at one worker PER QUEUE." That justification is wrong on both halves; the conclusion it supports is nonetheless right. See Technical.
    - **Technical:** `supervisor-1` drains **eleven** queues with **two** processes under `balance => false` (strict listed priority). `mail` and `notifications` sit 2nd and 3rd, which is the correct ordering — but a single long job elsewhere in that supervisor (a 180 s Cloudflare purge, a 300 s logo job) occupies one of only two processes, and two concurrent long jobs stall **every** transactional email until one finishes. On the comment: `Supervisor::scale()` is invoked **only** from `SupervisorCommands\Scale` — that is, manual `horizon:scale` or the dashboard — while the automatic path treats `maxProcesses` as a hard ceiling. **Real behaviour is worse than the comment claims:** with `simple`/`auto` and `maxProcesses` (2) below queue count (11), the first pools to claim workers exhaust the budget and the remaining queues get **zero**, which is starvation rather than a floor of one. Keep `balance => false`; fix the reasoning written beside it.
    - **Plain English:** All the outgoing email shares a two-worker pool with nine other kinds of background work. Email is near the front of the queue, which is right — but "near the front" does not help when both workers are already busy. One slow cache-purge and one slow logo job, running at the same time, means nobody's confirmation email goes out until one of them finishes. The obvious fix is to give email its own dedicated worker, and that is the right fix — but a dedicated worker needs memory the machine does not currently have to spare, which is the previous item's decision. So this waits. There is also a comment in that file explaining why the current setup was chosen; its explanation is factually wrong, though the choice it defends is still correct. Worth fixing so the next person reasoning from it does not reason from a false premise.
