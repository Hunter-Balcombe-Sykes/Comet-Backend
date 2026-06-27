Good — `report($e)` is only present in `failed()` methods (job-level permanent failure), never inside the per-record per-professional catch blocks. Two more observability gaps confirmed. Now I have the complete picture.

`★ Insight ─────────────────────────────────────`
The codebase shows a deliberate split between job-level failures (where `report($e)` surfaces to Nightwatch via `failed()`) and per-record sweep failures (where the catch only logs a warning to isolate one bad record from killing the sweep). The gap is that per-record failures are invisible to Nightwatch — `failed()` fires only once the job exhausts all retries, so transient per-record DB errors during sweep passes are permanently silent.
`─────────────────────────────────────────────────`

# Lifecycle Audit (Group B: Notifications Fan-out & Dedup) — 2026-05-11

**Branch:** development
**Lens:** Lifecycle correctness: race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline — Group B (Notifications fan-out & dedup)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Notifications/CommerceNotificationService.php
- app/Services/Notifications/NotificationPublisher.php
- app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php
- app/Jobs/Notifications/InviteExpirySweepJob.php
- app/Jobs/Notifications/NudgeStuckOnboardingJob.php
- app/Jobs/Notifications/SendBrandStatusNotificationJob.php
- app/Jobs/Notifications/SendEnquiryNotificationJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php
- app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php
- app/Notifications/Affiliate/AffiliatePayoutGraceWarningNotification.php
- app/Notifications/Brand/BrandPayoutFundingFailedNotification.php
- app/Http/Controllers/Api/Professional/Notifications/NotificationController.php
- app/Http/Controllers/Api/Professional/Notifications/NotificationEmailPreferenceController.php
- app/Http/Controllers/Api/Professional/Notifications/ProfessionalEmailSubscriptionController.php
- app/Models/Core/Notifications/EmailSubscription.php
- app/Models/Core/Notifications/Notification.php
- app/Models/Core/Notifications/NotificationEmailPolicy.php
- app/Models/Core/Notifications/NotificationEmailPreference.php
- app/Models/Core/Notifications/NotificationReceipt.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 6 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#LIFE-1** · P2 — CommerceNotificationService swallows all notification exceptions; booking notification failures invisible to Nightwatch
    - **Where:** app/Services/Notifications/CommerceNotificationService.php — `notifyBookingCompleted()`
    - **Affects:** Affiliates and brand partners who should receive booking-completed and milestone notifications. At ~3K orders/day peak, a transient DB or Redis failure during `notifyBookingCompleted` silently drops all notifications for that checkout with no alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e)` alongside the existing `Log::warning` inside the catch block.
        - This preserves the non-blocking contract (caller `PublicBookingController::recordBookingAnalyticsAndNotify` also swallows) while making the failure visible to Nightwatch.
    - **Technical:** Category (10) — `Log::warning` only. Per the Nightwatch alert model (memory `reference_nightwatch_alerts`), Nightwatch alerts on exceptions and auto-detected anomalies, not on `Log::warning` queries. The entire `notifyBookingCompleted` body — including DB queries for affiliate names, all `publisher->publish()` calls, and the milestone cache read — is wrapped in a single `catch (\Throwable $e)` that swallows silently. Calling `report($e)` forwards the exception to Nightwatch without re-throwing, honouring the non-blocking design. This matches the `Log-with-context` + distinct-failure-log pattern from `#STRIPE-2`. The outer wrapper in `PublicBookingController::recordBookingAnalyticsAndNotify` already swallows for non-blocking correctness; the inner catch in this service should still surface the failure to observability tooling.
    - **Plain English:** When the notification system breaks during a booking, the code quietly whispers "something went wrong" into a log file nobody monitors automatically. The booking itself succeeds, so staff assume everything is fine — but the "new booking" bell never rings for the affiliate or brand. Adding one line (`report($e)`) makes the failure show up on the monitoring dashboard the same way a proper crash would, so the team knows there's a problem without needing to scan logs manually.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::warning('Booking notifications failed', [
                'professional_id' => $context['professional_id'] ?? null,
                'message' => $e->getMessage(),
            ]);
        }
        ```

- [ ] **#LIFE-2** · P2 — NudgeStuckOnboardingJob swallows per-professional notification failures; systematic DB blips silently drop onboarding nudges for all brands
    - **Where:** app/Jobs/Notifications/NudgeStuckOnboardingJob.php — `sweepMilestone()` inner catch block (line ~120)
    - **Affects:** Brand professionals stuck in the onboarding funnel who should receive day-3/day-10/day-30 nudges. A transient DB error during any sweep pass silently skips affected brands with no alert. The job's own `failed()` method only fires after all retry exhaustion — per-professional failures inside `sweepMilestone` are permanent drops invisible to Nightwatch.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e)` alongside the existing `Log::warning` in the per-professional catch block inside `sweepMilestone`.
        - The isolation design (one bad record doesn't abort the sweep) is correct — `report($e)` without re-throwing preserves it.
    - **Technical:** Category (10) — swallowed exception with `Log::warning` only. Identical structural pattern to `#LIFE-1`. The per-milestone catch in `sweepMilestone` logs `['professional_id', 'day', 'message']` but never calls `report($e)`, so Nightwatch receives no signal. At 200 brands / 3 milestones, a systematic failure (Redis lock timeout, Postgres connection drop) silently drops up to 200 nudges per run with no incident alert. The canonical fix is the `Log-with-context` pattern from `#STRIPE-2`: add `report($e)` before the existing log line.
    - **Plain English:** Every day, the system walks through a list of brands that haven't finished setup and sends them a gentle reminder. If the reminder system breaks while walking that list, it quietly skips each affected brand and moves on — no alarm, no record of the failure. The fix is one line that makes the failure loud enough for the monitoring system to catch it automatically.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::warning('NudgeStuckOnboardingJob failed for professional', [
                'professional_id' => $row->id ?? null,
                'day' => $day,
                'message' => $e->getMessage(),
            ]);
        }
        ```

- [ ] **#LIFE-3** · P2 — SendWeeklyAnalyticsNotificationJob swallows per-professional notification failures; at fan-out scale the silent drop rate compounds
    - **Where:** app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php — `handle()` inner catch block
    - **Affects:** All active professionals (affiliates) who should receive weekly commission/sales digests. At 200 brands × 50 affiliates = up to 10K professionals per weekly run, a 1% failure rate during a DB or Redis degradation event silently drops ~100 weekly digest notifications with no Nightwatch alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e)` alongside the existing `Log::warning` in the per-professional catch block inside `handle()`.
        - The job's `failed()` method already has `report($e)` for permanent job-level failures — the per-record catch just needs the same treatment.
    - **Technical:** Category (10). The per-professional catch logs `['professional_id', 'message']` but does not call `report($e)`. `SendWeeklyAnalyticsNotificationJob::failed()` correctly has `report($e)` for permanent job exhaustion — but that fires at most once per job run, not per failed professional. The missing `report($e)` inside the chunk callback means any transient failure affecting specific professionals during a Monday sweep run is permanently invisible to Nightwatch. Same pattern as `#LIFE-1` and `#LIFE-2`. At 40K daily notifications fan-out, Monday spikes are the highest-pressure window; silent drops compound at exactly the time monitoring matters most.
    - **Plain English:** Every Monday, the system tallies each affiliate's week and sends them a summary. If sending fails for a batch of affiliates — say, because the database hiccupped — the code logs a quiet note and skips them. Nobody gets an alert. The affiliate just doesn't get their weekly summary. One extra line (`report($e)`) means the monitoring system would flag this automatically, just like any other system error.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::warning('Weekly analytics notification failed', [
                'professional_id' => $professional->id,
                'message' => $e->getMessage(),
            ]);
        }
        ```

- [ ] **#LIFE-4** · P2 — SendStaffBroadcastEmailToSubscriberJob has no per-recipient send sentinel; parent job retry and individual job retries both produce duplicate broadcast emails
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php — `handle()`
    - **Affects:** All marketing-list subscribers who receive staff broadcast emails. A single parent-job retry re-dispatches fresh `Bus::batch()` chunks for all subscribers, including those already emailed on the first pass. At marketing-list scale (unbounded subscriber count), a partial failure re-sends to every subscriber whose individual job succeeded on the first attempt.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a Supabase migration creating `notifications.broadcast_email_receipts` with `UNIQUE (notification_id, subscription_id)` and an `email_sent_at` timestamp.
        - At the start of `handle()`, after verifying `$sub->status === 'subscribed'`, attempt `DB::table('notifications.broadcast_email_receipts')->insertOrIgnore([...])`. If the returned count is 0, the email was already sent — return early.
        - Alternatively, apply the JSONB dedup pattern from `af90b2e`: store `sent_to_subscription_ids` as a JSONB array on the `notifications.notifications` row and `array_append` atomically, checking for membership before dispatch.
    - **Technical:** Category (1) + (5) — missing `UNIQUE`-backed idempotency on the email side-effect. `SendStaffBroadcastEmailsJob` dispatches via `Bus::batch()` with `allowFailures()` and `$tries = 3`. If the parent retries after partial dispatch, `chunkById` re-queries all subscribers from scratch and dispatches fresh batches — individual jobs whose first-run send completed successfully now receive a second dispatch. The individual job itself has `$tries = 3`, so it too retries on transport acceptance + process-crash. `SendStaffBroadcastEmailsJob::failed()` correctly calls `report($e)`, but per-recipient idempotency is missing at `Mail::to()->send()`. The canonical pattern is the JSONB dedup (`af90b2e`) or a `UNIQUE`-keyed receipt table (`lockForUpdate + UNIQUE`). Compare to `NotificationPublisher::publish`, which uses `insertOrIgnore` on `(professional_id, dedupe_key)` to make the in-app insert idempotent — the email side-effect needs the same treatment.
    - **Plain English:** Imagine sending a company newsletter by handing each subscriber's address to a separate courier. If the coordinator stumbles halfway and starts over, every courier gets redeployed — including the ones who already delivered the letter successfully. Those subscribers get two copies. The fix is a delivery logbook: each courier checks before knocking whether their subscriber is already ticked off, so re-runs only cover people who actually missed the first delivery.
    - **Evidence:**
        ```php
        // Parent job re-dispatches ALL subscribers on retry:
        EmailSubscription::query()
            ->whereNull('professional_id')
            ->where('list_key', $this->listKey)
            ->where('status', 'subscribed')
            ->orderBy('id')
            ->chunkById(500, function ($subs) use ($notification) {
                $jobs = $subs->map(fn ($sub) => new SendStaffBroadcastEmailToSubscriberJob(
                    $notification->id,
                    $sub->id,
                ))->all();
                // ...dispatched fresh on every parent retry...
            });

        // Individual job has no sentinel before send:
        Mail::to($sub->email)->send(
            new StaffBroadcastMail($notification, $unsubscribeUrl)
        );
        ```

- [ ] **#LIFE-5** · P2 — SendEnquiryNotificationJob has no email-send dedup; queue retries produce duplicate contact-form notification emails
    - **Where:** app/Jobs/Notifications/SendEnquiryNotificationJob.php — `handle()`
    - **Affects:** Affiliates who receive contact-form enquiry notifications. With `$tries = 3` and no pre-send guard, a job that succeeds at `Mail::send()` but crashes before returning `ShouldQueue` success retries and sends again. At scale, even a low retry rate means affiliates see duplicate enquiry notifications from their own site.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a nullable `email_sent_at` timestamp column to `site.enquiries` via a Supabase migration.
        - Before `Mail::to()->send()`, check `if ($enquiry->email_sent_at !== null) { return; }`.
        - After successful send, update `$enquiry->email_sent_at = now(); $enquiry->saveQuietly()`.
    - **Technical:** Category (1) — no idempotency key on the email side-effect. `SendEnquiryNotificationJob` has `$tries = 3` with default backoff. The job loads the `Enquiry` model and immediately calls `Mail::to()->send()` with no pre-send existence check. The `Enquiry` model has no `email_sent_at` column and no equivalent dedup guard. The canonical fix is the sentinel-on-source-row pattern applied throughout the payout pipeline: read the flag, skip if set, write the flag after the side-effect. This is the same shape as `#STRIPE-4`'s anchor field separation — the job progress state and the notification state are different concerns and need separate columns.
    - **Plain English:** When a visitor fills out a contact form on an affiliate's site, the system queues an email notification. If the email goes out but the queue worker then stumbles, it retries — and sends the same "you have a new enquiry" email again. The affiliate sees two identical notifications. Adding a simple "already sent" flag to the enquiry record prevents the second send, even if the queue retries.
    - **Evidence:**
        ```php
        public function handle(): void
        {
            $enquiry = Enquiry::query()->find($this->enquiryId);

            if (! $enquiry) {
                Log::warning('SendEnquiryNotificationJob: enquiry not found', [
                    'enquiry_id' => $this->enquiryId,
                ]);

                return;
            }

            Mail::to($this->notificationEmail)->send(new SiteEnquiryNotification($enquiry));
        }
        ```

- [ ] **#LIFE-6** · P2 — SendTransactionalNotificationEmailJob has no email-send sentinel; retries duplicate financially-sensitive transactional emails
    - **Where:** app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php — `handle()`
    - **Affects:** Professionals receiving commission-earned, payout-initiated, and invite emails. With `$tries = 3` and `$backoff = [30, 120, 300]`, a mail-transport-accepts-then-process-crash scenario retries and calls `Mail::to()->send()` again. At ~40K daily notifications with even a 0.1% retry rate, ~40 duplicate transactional emails/day — eroding trust most severely for "you've been paid" messages.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a nullable `email_sent_at` timestamp column to `notifications.notifications` via a Supabase migration.
        - At the start of the `Mail::to()->send()` path in `handle()`, reload the notification with a `lockForUpdate` and check `if ($notification->email_sent_at !== null) { return; }`.
        - After successful `Mail::send()`, update `email_sent_at = now()` on the notification row.
        - This matches the `lockForUpdate + UNIQUE` pattern: the notification row is the natural idempotency anchor since `NotificationPublisher::publish` already creates exactly one row per `(professional_id, dedupe_key)`.
    - **Technical:** Category (1). `NotificationPublisher::publish` uses `insertOrIgnore` on `(professional_id, dedupe_key)` and dispatches `SendTransactionalNotificationEmailJob` only when `$inserted > 0` — so the in-app notification is correctly deduped. But the email job itself executes `Mail::to($email)->send($mailable)` with no pre-send guard. The notification row is the natural sentinel: it already exists (dispatch only happens on a new insert), the job carries the `$notificationId`, and adding `email_sent_at` makes the email side-effect idempotent at the source-row level. This is the `lockForUpdate + UNIQUE` canonical pattern applied to side-effect dispatch, matching the reasoning that closed `#STRIPE-3`. For payout and commission notification categories, receiving two "you've been paid" emails is a trust-eroding defect, not merely an annoyance.
    - **Plain English:** The system has a bouncer at the door for in-app notifications — it checks IDs and never lets duplicates in. But when it hands off a copy to the email runner, that runner has no checklist. If the runner trips after delivering the email but before signing off, the system sends them back to deliver again. For emails that say "your commission is ready" or "your payout has been initiated," getting two copies looks like a system error or a double-payment to the recipient. Adding a "delivered" stamp to the original notification record stops the second delivery.
    - **Evidence:**
        ```php
        // NotificationPublisher inserts atomically, dispatches job only once:
        if ($inserted > 0 && config('partna.notifications.email_enabled', false)) {
            SendTransactionalNotificationEmailJob::dispatch(
                $notificationId,
                $category,
                $professionalId,
            )->onQueue('mail');
        }

        // But the job itself re-sends on retry — no guard:
        Mail::to($email)->send($mailable);
        ```

---

## P3 — Nice to have

- [ ] **#LIFE-7** · P3 — InviteExpirySweepJob per-invite catch block omits brand_professional_id from log context; Nightwatch correlation requires a second query to trace failures back to tenant
    - **Where:** app/Jobs/Notifications/InviteExpirySweepJob.php — `handle()` inner catch block
    - **Affects:** Operators debugging invite-expiry notification failures. At 200 brands, a per-invite publish failure is logged without the `brand_professional_id` that Nightwatch needs to group failures by tenant.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'brand_professional_id' => $invite->brand_professional_id` to the log context array.
        - The value is already available: the outer query selects `['id', 'brand_professional_id', 'email', 'first_name']`.
    - **Technical:** Category (10) — `Log-with-context` pattern. The per-invite catch emits only `['invite_id', 'message']`. The Stripe audit established that every operational log must include the tenant identifier (`brand_professional_id`) to support per-tenant Nightwatch correlation. Tracing a specific invite failure back to its brand currently requires a second DB query (`SELECT brand_professional_id FROM brand.brand_affiliate_invites WHERE id = ?`). The fix is one additional key in the existing log array — `$invite->brand_professional_id` is already in scope from the chunk callback.
    - **Plain English:** When an invite notification fails, the log says "invite #XYZ failed" but doesn't say which brand it belongs to. Anyone investigating has to look up the invite in the database to find the brand — an unnecessary extra step. Adding the brand ID to the log message makes it a one-stop record, the same way a helpdesk ticket should include the customer's name rather than just a ticket number.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            Log::warning('InviteExpirySweepJob failed for invite', [
                'invite_id' => $invite->id,
                'message' => $e->getMessage(),
            ]);
        }
        ```

- [ ] **#LIFE-8** · P3 — CommerceNotificationService uses Str::uuid() as fallback dedupe key; callers passing empty IDs produce non-deterministic keys that bypass insertOrIgnore dedup
    - **Where:** app/Services/Notifications/CommerceNotificationService.php — `notifyBookingCompleted()` dedupe key derivation
    - **Affects:** Affiliates and brands receiving booking notifications when a caller omits both `booking_event_id` and `booking_id`. In the current call path (`PublicBookingController`) both IDs are always populated — but the service is a shared injectable, and a future caller passing empty context silently defeats `NotificationPublisher`'s `insertOrIgnore` dedup, producing duplicate in-app notifications.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `Str::uuid()` fallback with a deterministic hash: `hash('sha256', json_encode([$professionalId, $serviceName, $customerName, $amountPaidCents]))`.
        - Or fail loudly: throw an `\InvalidArgumentException` if both `booking_event_id` and `booking_id` are empty, so callers discover the contract violation immediately rather than silently publishing non-idempotent notifications.
    - **Technical:** Category (1) — idempotency-key derivation from a non-deterministic field. The derivation chain `$eventId ?: $bookingId ?: Str::uuid()` means any call with both IDs empty generates a random UUID as the dedup key. Every such call passes `NotificationPublisher::insertOrIgnore`'s `(professional_id, dedupe_key)` constraint, inserting a fresh row. The canonical principle is: idempotency keys must be derived from deterministic inputs (inbound event ID or a deterministic hash of the payload), never from `now()->timestamp` or `Str::uuid()`. The current primary call site is safe because `booking_event_id` is always set — this is a defensive hardening finding against future call-site drift.
    - **Plain English:** The system builds a unique receipt number for each booking notification so it doesn't send duplicates. When it can't find the booking's own ID to use, instead of calculating a receipt number from the booking details (which would always be the same for the same booking), it makes up a random number on the spot. That means if the same booking gets processed twice with missing IDs, the system thinks they're two different bookings and sends two notifications. The fix is to calculate the receipt number from the booking details themselves, like a receipt printer that derives the number from the item list rather than generating it randomly.
    - **Evidence:**
        ```php
        $eventKey = $eventId !== '' ? $eventId : ($bookingId !== '' ? $bookingId : Str::uuid()->toString());
        // ...
        $this->publisher->publish(
            professionalId: $professionalId,
            frontendType: 'Success',
            category: 'analytics_milestones',
            title: 'New booking received',
            body: $body,
            dedupeKey: 'booking:user:'.$eventKey,
            // ...
        );
        ```
