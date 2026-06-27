`★ Insight ─────────────────────────────────────`
Three key verification wins from the migration files:
1. **SCHEMA-2 and SCHEMA-3 drop**: The `dedupe_key` partial unique index exists in `20260423010000_add_dedupe_key_to_notifications.sql`, and `broadcast_email_receipts` uses `PRIMARY KEY (notification_id, subscription_id)` — both are solid. DeepSeek hallucinated the missing constraint concern.
2. **SCHEMA-8 drops**: `customers_professional_email_unique ON core.customers (professional_id, lower(email))` is in the v2 baseline. The duplicate-customer race creates a silent failure (not a duplicate row), already covered by LIFE-5.
3. **SCHEMA-1 is confirmed P0**: `notification_email_policies` lives in `notifications` schema per migration, but every raw DB query in the codebase hits `core.notification_email_policies` — one side is querying a nonexistent table.
`─────────────────────────────────────────────────`

# Full-Stack Audit — 2026-05-18

**Branch:** development
**Lens:** Full audit across 11 focused themes: security/policy (SEC-*), lifecycle correctness (LIFE-*), scaling antipatterns / read-side caching (CACHE-*), database/queue scaling (SCALE-*), schema/RLS correctness (SCHEMA-*), migration safety (MIG-*), observability (OBS-*), data integrity & privacy (DINT-*), API contract (API-*), job/queue correctness (JOB-*), and configuration hygiene (CFG-*)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Professional/Notifications/NotificationController.php
- app/Http/Controllers/Api/Professional/Notifications/NotificationEmailPreferenceController.php
- app/Http/Controllers/Api/Professional/Notifications/ProfessionalEmailSubscriptionController.php
- app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php
- app/Http/Controllers/Api/PublicSite/PublicEmailUnsubscribeController.php
- app/Http/Controllers/Api/PublicSite/PublicMarketingPreferenceController.php
- app/Http/Controllers/Api/PublicSite/PublicCustomerLeadController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php
- app/Models/Core/Notifications/EmailSubscription.php
- app/Models/Core/Notifications/Notification.php
- app/Models/Core/Notifications/NotificationEmailPolicy.php
- app/Services/Notifications/CommerceNotificationService.php
- app/Services/Notifications/NotificationListingService.php
- app/Services/Notifications/NotificationPublisher.php
- app/Services/Customers/ContactCaptureService.php
- app/Services/Professional/DataExport/DataExportPayloadBuilder.php
- app/Services/Professional/DataExport/DataExportZipWriter.php
- app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php
- app/Jobs/Notifications/InviteExpirySweepJob.php
- app/Jobs/Notifications/SendEnquiryNotificationJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php
- app/Jobs/Shopify/Gdpr/ExportCustomerDataJob.php
- app/Jobs/Shopify/Gdpr/RedactCustomerJob.php
- app/Jobs/Shopify/Gdpr/RedactShopJob.php
- app/Policies/NotificationPolicy.php
- supabase/migrations/20260403000000_v2_baseline.sql
- supabase/migrations/20260423010000_add_dedupe_key_to_notifications.sql
- supabase/migrations/20260514200000_email_send_sentinels.sql
- supabase/migrations/202605190000002_add_enum_check_constraints.sql

## Progress

- P0 Blockers: 0 of 1 complete
- P1 High: 0 of 7 complete
- P2 Medium: 0 of 22 complete
- P3 Low: 0 of 7 complete

---

## P0 — Must fix before any real user touches the system

- [x] **#SCHEMA-1** · P0 — `notification_email_policies` lives in `notifications` schema but every raw query hits `core` — one side is reading an empty table
    - **Where:** app/Models/Core/Notifications/NotificationEmailPolicy.php:17 (model) vs app/Http/Controllers/Api/Professional/Notifications/NotificationEmailPreferenceController.php:44-46, app/Services/Notifications/NotificationPublisher.php (computeResolvedMap), app/Services/Professional/DataExport/DataExportPayloadBuilder.php (notificationPreferences)
    - **Affects:** Per-professional email preference resolution (every transactional email send), the staff policy management UI, and GDPR data exports. Staff-issued `force_on`/`force_off` policies silently have no effect; mandatory-category resolution is unaffected (it is config-driven), but every other policy override is a no-op.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm the canonical schema is `notifications` (verified in `20260403000000_v2_baseline.sql`: `CREATE TABLE IF NOT EXISTS notifications.notification_email_policies`).
        - Replace every `DB::table('core.notification_email_policies')` reference in `NotificationEmailPreferenceController`, `NotificationPublisher::computeResolvedMap`, and `DataExportPayloadBuilder::notificationPreferences` with `DB::table('notifications.notification_email_policies')`.
        - Add an integration test that writes a policy via the model and reads it back via `NotificationPublisher::computeResolvedMap` to lock the schema choice.
    - **Technical:** The v2 baseline migration creates the table in the `notifications` schema. The `NotificationEmailPolicy` model (`$table = 'notifications.notification_email_policies'`) is correct. All three raw `DB::table()` call sites use `core.notification_email_policies`, which is either absent or an entirely different table. On the query path, `computeResolvedMap` returns an all-default map (`true` for every category) because the policy queries return zero rows. No exception is thrown — Postgres accepts the `core.` qualifier and returns an empty result set if the table doesn't exist under `app_backend`'s search_path for that schema.
    - **Plain English:** Imagine a filing cabinet labelled "Notifications Drawer" with all the email rules inside it. The app's three workers who need to check those rules all walk to the "Core Drawer" instead — which is either empty or a different cabinet entirely. They find nothing, shrug, and apply defaults. Any rule a staff member sets ("never email this person") is written into the right drawer but never read. The fix is pointing all three workers to the correct drawer.
    - **Evidence:**
        ```php
        // Model — CORRECT schema:
        protected $table = 'notifications.notification_email_policies';

        // NotificationEmailPreferenceController — WRONG schema:
        $perProPolicies = DB::table('core.notification_email_policies')
            ->where('professional_id', $pro->id)
            ->get(['category_key', 'mode']);

        // NotificationPublisher::computeResolvedMap — WRONG schema:
        $perProPolicies = DB::table('core.notification_email_policies')
            ->where('professional_id', $professionalId)
            ->pluck('mode', 'category_key')
            ->all();

        // DataExportPayloadBuilder::notificationPreferences — WRONG schema:
        $policies = DB::connection('pgsql')
            ->table('core.notification_email_policies')
            ->where('professional_id', $professionalId)
            ->get()
        ```

---

## P1 — Fix before pilot launch

- [x] **#LIFE-1** · P1 — `SendEnquiryNotificationJob` has a read-modify-write race on `email_sent_at` — duplicate emails on retry
    - **Where:** app/Jobs/Notifications/SendEnquiryNotificationJob.php:38-50
    - **Affects:** Site enquiry email recipients — duplicate notification emails when the job retries or two Horizon workers pick up the same job concurrently.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `email_sent_at` null check + email send + stamp in a `DB::transaction()` with `Enquiry::query()->lockForUpdate()->find($this->enquiryId)`.
        - Follow the pattern already in `SendTransactionalNotificationEmailJob::handle()`, which uses `DB::transaction(fn() => Notification::query()->lockForUpdate()->find(...))`.
    - **Technical:** The job reads `$enquiry->email_sent_at !== null`, sends email if false, then stamps the field. Between the read and the write there is no row lock. Two concurrent instances (retry overlapping with original, or Horizon scaling) both see `null`, both call `Mail::send()`, both stamp — the recipient gets a duplicate. The `20260514200000_email_send_sentinels.sql` migration added `email_sent_at` to `site.enquiries` precisely to enable this dedup pattern, but the job doesn't use `lockForUpdate`.
    - **Plain English:** Two delivery workers both check a "delivered" flag on the same package at the same instant, both see "not delivered," and both drop off a copy. The recipient gets the enquiry email twice. The fix is having the first worker lock the flag while they check it so the second sees "already delivered."
    - **Evidence:**
        ```php
        if ($enquiry->email_sent_at !== null) {
            return; // already sent on a previous attempt
        }

        Mail::to($this->notificationEmail)->send(new SiteEnquiryNotification($enquiry));

        $enquiry->forceFill(['email_sent_at' => now()])->saveQuietly();
        ```

- [x] **#LIFE-2** · P1 — `ContactCaptureService` catches generic `QueryException` instead of typed `UniqueConstraintViolationException` — misroutes unrelated failures
    - **Where:** app/Services/Customers/ContactCaptureService.php:144-150, 165-177, 226-234
    - **Affects:** Every Shopify order webhook, Square booking, and site lead that races on customer creation or marketing subscription upsert. The generic catch also intercepts deadlocks, serialization failures, and connection drops, silently swallowing them on the `!== '23505'` re-throw path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace all three `catch (QueryException $e)` blocks that branch on `'23505'` with `catch (UniqueConstraintViolationException $e)` (available since Laravel 10; no version flag needed here).
        - The outer `catch (Throwable $e)` in `captureContact` then correctly catches non-unique DB errors.
    - **Technical:** `QueryException` is the parent class for all database exceptions. The `getCode() === '23505'` branch is string-based dispatch — correct for Postgres today, but the generic catch also intercepts deadlocks (`40P01`), serialization failures (`40001`), and connection drops. A serialization failure during a high-write burst would reach the `!== '23505'` branch, be re-thrown from inside the phone-collision try block, then caught by the outer `Throwable` handler and logged as a silent warning with no Nightwatch alert. `UniqueConstraintViolationException` (FQCN: `Illuminate\Database\UniqueConstraintViolationException`) maps directly to SQLSTATE 23505 and is version-stable.
    - **Plain English:** The code catches every type of database error in one net, then reads a number to decide if it's the "two people inserted the same thing" error it wants. A totally different error — like the database being momentarily overloaded — gets caught in the same net and could get routed to the wrong recovery path. A purpose-built net that only catches that one specific error type is safer and clearer.
    - **Evidence:**
        ```php
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
            // Phone collides with another contact on the same affiliate — retry without phone.
        ```
        ```php
        } catch (QueryException $e) {
            // 23505 = another request beat us to the INSERT.
            if ($e->getCode() === '23505') {
        ```
        ```php
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
            $customer->phone = $customer->getOriginal('phone');
        ```

- [x] **#LIFE-4** · P1 — `PublicEmailSubscriptionController` swallows customer-upsert exception without `report()` — Nightwatch is blind to sync drift
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php:104-113
    - **Affects:** Every newsletter signup where the follow-up customer upsert fails. The subscription row succeeds but the `core.customers` record is never created or updated — the lists drift silently with zero observability.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($exception);` before the `Log::warning(...)` call inside the catch block.
        - This forwards the exception to Nightwatch, which alerts; `Log::warning` alone is a breadcrumb that requires manual dashboard queries.
    - **Technical:** The `try/catch` around `upsertMarketingCustomer()` logs a warning but never calls `report($exception)`. Laravel's global exception handler (`report()`) forwards to Nightwatch. Without it, this failure is invisible to alerting. The `Log::warning` context includes `professional_id` and `email`, which is good for breadcrumbing, but Nightwatch groups and alerts on exceptions, not log queries. The same pattern of missing `report()` exists in `ContactCaptureService` (LIFE-5), suggesting this is a copy-paste omission across the notification domain.
    - **Plain English:** When saving a newsletter subscriber's customer profile fails, the code writes a journal note but never sounds the alarm. The journal sits unread. Adding one line triggers the alarm so the team knows customer records are drifting before a support ticket surfaces it days later.
    - **Evidence:**
        ```php
        } catch (\Throwable $exception) {
            // Do not block successful subscription if customer sync fails.
            Log::warning('Public subscribe customer upsert failed', [
                'professional_id' => (string) $site->professional_id,
                'email' => $email,
                'error' => $exception->getMessage(),
            ]);
        }
        ```

- [x] **#OBS-1** · P1 — `NotificationPublisher::publish()` discards notifications silently on three guard branches — no log, no Nightwatch trace
    - **Where:** app/Services/Notifications/NotificationPublisher.php:89-105
    - **Affects:** Every caller of `publish()` — booking completions, brand status changes, invite expiries, weekly analytics, onboarding nudges. An empty `$professionalId`, `$title`, `$body`, or `$dedupeKey` from a caller bug produces a silently dropped notification.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Log::warning('NotificationPublisher: dropped notification — empty required field', ['field' => '...', 'category' => $category])` at each early-return guard.
        - Add `report(new \UnexpectedValueException('NotificationPublisher: empty dedupeKey'))` at the `$dedupeKey === ''` guard specifically — an empty dedupe key is always a caller bug and should trigger a Nightwatch alert.
    - **Technical:** Three silent `return` branches (empty professionalId, empty title/body, empty dedupeKey) run before `insertOrIgnore` executes. No exception, no log, no Nightwatch event. Since `publish()` is the single chokepoint for the entire in-app notification pipeline, a payout or commission notification assembled with a string-interpolation bug vanishes with zero paper trail. The `publishMany()` method has the same pattern (see OBS-2).
    - **Plain English:** The notification delivery system has three silent trapdoors at its front door. If a developer accidentally passes a blank title or an empty event key, the notification gets quietly binned — no entry in any log, no alarm. Adding a one-line warning at each trapdoor means the team knows a notification was dropped and why.
    - **Evidence:**
        ```php
        $professionalId = trim($professionalId);
        if ($professionalId === '') {
            return;
        }

        $title = trim($title);
        $body = trim($body);
        if ($title === '' || $body === '') {
            return;
        }

        $dedupeKey = trim($dedupeKey);
        if ($dedupeKey === '') {
            // Require a non-empty dedupe key — callers should always provide one.
            return;
        }
        ```

- [x] **#OBS-2** · P1 — `NotificationPublisher::publishMany()` silently skips invalid items and silently short-circuits when all items are invalid
    - **Where:** app/Services/Notifications/NotificationPublisher.php:168-175 (foreach skip) and following empty-rows guard
    - **Affects:** Fan-out callers that use `publishMany()` for bulk notification delivery — brand-affiliate invite batches, staff broadcasts. A bug producing all-empty fields results in zero notifications with zero evidence.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log a `Log::warning` (including item index and which field is empty) when an individual item is skipped inside the foreach.
        - Log a `Log::warning` with the input count when `$rows === []` after filtering — the caller sent items but nothing was publishable.
    - **Technical:** The foreach loop silently `continue`s past items with empty required fields. If every item in the batch is invalid, `$rows` stays empty and the method exits with no trace. Callers are orchestrating bulk dispatches and have no per-item feedback loop. A warning log with the count of skipped items closes the gap at negligible cost.
    - **Plain English:** If someone queues 200 brand notifications and a bug makes every single one invalid, the system silently does nothing. The operator who triggered the fan-out sees a success, but zero notifications went out. A single log line saying "200 items provided, 0 valid" would catch this immediately.
    - **Evidence:**
        ```php
        foreach ($items as $item) {
            // ...
            if ($professionalId === '' || $title === '' || $body === '' || $dedupeKey === '') {
                continue;
            }
            // ...
        }

        if ($rows === []) {
            return;
        }
        ```

- [x] **#JOB-1** · P1 — `SendTransactionalNotificationEmailJob` silently discards permanent failures — financial emails vanish without a Horizon failure record
    - **Where:** app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php:51-55, 79-82, 86-89
    - **Affects:** Transactional email delivery for invites, commissions, and payouts — failures on the "no email on record" and "mailable instantiation failed" paths increment no failed-jobs counter and fire no Nightwatch alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `return;` with `$this->fail(new \RuntimeException('no primary_email on record for professional '.$this->professionalId))` in the `! $email` branch.
        - Replace `return;` with `$this->fail(new \RuntimeException('mailable instantiation failed for category '.$this->category))` in the `$mailable === null` branch.
        - The feature-disabled and user-preference-disabled branches are legitimate no-ops — leave those as plain `return`.
    - **Technical:** The job has `$tries = 3` and `$backoff = [30, 120, 300]`. The two non-transient failure conditions — no email on record and mailable class missing — will never resolve on retry. Horizon marks the job succeeded after three no-op retries; no failed-jobs counter increment, no `failed()` hook, no Nightwatch alert. For payout and commission categories this is a trust defect. `$this->fail()` marks the job failed, increments Horizon's failed counter, and fires `failed()`, which already logs correctly.
    - **Plain English:** A mailroom clerk who receives an envelope with no address puts it back in the retry pile three times, then quietly bins it without telling anyone. For commission and payout emails, that is money-related communication going silently missing. Calling `fail()` instead of `return` rings the alarm the moment the envelope arrives addressless.
    - **Evidence:**
        ```php
        if (! $email) {
            Log::warning('Notification email skipped: no email on record', [
                'professional_id' => $this->professionalId,
            ]);
            return;  // ← no $this->fail(); job retries 3× then disappears from Horizon
        }

        $mailable = $this->buildMailable($notification, $class);
        if ($mailable === null) {
            Log::warning('Notification email skipped: mailable instantiation failed', [
                'category' => $this->category,
                'class' => $class,
            ]);
            return;  // ← same silent discard
        }
        ```

- [x] **#SCALE-1** · P1 — `SendStaffBroadcastEmailsJob` runs on the `default` queue — its chunkById walk starves other default-queue work
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:28-34 (constructor — no `onQueue` call)
    - **Affects:** Queue health under load — a staff broadcast to tens of thousands of subscribers occupies a `default` worker for up to 120s, back-pressuring every other unclassified job in the system.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->onQueue('notifications');` in the constructor — every other fan-out coordinator job (`FanOutBrandStatusNotificationJob`, `InviteExpirySweepJob`, `NudgeStuckOnboardingJob`, `SendWeeklyAnalyticsNotificationJob`) declares `notifications` in the constructor. This job is the only outlier.
        - The sub-batches it dispatches already go to `mail` via `Bus::batch(...)->onQueue('mail')` — only the coordinator job itself is misplaced.
    - **Technical:** Without `$this->onQueue()`, Horizon puts the job on `default`. The coordinator's work is a `chunkById` walk of `EmailSubscription` scoped to `professional_id IS NULL`, which can span tens of thousands of rows at subscriber scale. The walk itself holds a `default` worker for the job's full 120s timeout window. All other `default`-queue jobs — including time-sensitive webhooks — are delayed until this completes.
    - **Plain English:** Every other team-lead in the dispatch center has a label on their desk saying what lane they manage. This one has no label, so they sit in the "general" lane and block everyone else when they spend two minutes paging through a massive list. Giving them a label (the `notifications` lane) costs nothing and restores the general lane for everyone else.
    - **Evidence:**
        ```php
        public function __construct(
            public string $notificationId,
            public string $listKey = 'sidest_updates'
        ) {}
        // Compare with every other fan-out coordinator:
        // public function __construct(...) { $this->onQueue('notifications'); }
        ```

---

## P2 — Should fix

- [x] **#DINT-1** · P2 — Data export ZIP leaks `unsubscribe_token`, `consent_ip_hash`, and `consent_user_agent` — raw DB query bypasses `EmailSubscription::$hidden`
    - **Where:** app/Services/Professional/DataExport/DataExportPayloadBuilder.php (emailSubscriptions method)
    - **Affects:** Any professional who exports their account data. The ZIP's `data.json` contains every subscriber's unsubscribe token (usable to programmatically unsubscribe anyone) plus IP hash and user-agent (technical fingerprints that belong to the subscriber, not the professional).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an explicit `->select(['id', 'professional_id', 'list_key', 'email', 'full_name', 'status', 'subscribed_at', 'unsubscribed_at', 'consent_source', 'created_at'])` to the query, mirroring the column allow-list the professional already sees via `/api/email-subscribers`.
        - Alternatively, switch to `EmailSubscription::query()->where(...)->get()->map(fn($s) => $s->toArray())` which respects `$hidden`.
    - **Technical:** `EmailSubscription::$hidden = ['unsubscribe_token', 'consent_ip_hash', 'consent_user_agent']` is explicitly defined to prevent these fields from leaving the server. The `DataExportPayloadBuilder::emailSubscriptions()` method uses `DB::table(...)->get()->map(fn ($r) => (array) $r)`, which bypasses Eloquent entirely — no `$hidden`, no casts, no accessors. Every column lands verbatim in the export JSON.
    - **Plain English:** Every newsletter subscriber gets a secret "unsubscribe link" that only they should know. The data export hands the store owner a copy of every subscriber's secret link, plus browser and IP fingerprints. The model already has a "shred these before handing over" rule — the export just doesn't run the shredder. Adding an explicit column list to the query fixes this in one line.
    - **Evidence:**
        ```php
        private function emailSubscriptions(string $professionalId): array
        {
            return DB::connection('pgsql')
                ->table('notifications.email_subscriptions')
                ->where('professional_id', $professionalId)
                ->get()
                ->map(fn ($r) => (array) $r)
                ->all();
        }
        ```

- [x] **#SEC-1** · P2 — `PublicEmailSubscriptionController` logs subscriber email address in plaintext on customer upsert failure
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php:88-93
    - **Affects:** End-user privacy — every customer email that triggers a upsert failure is written to the application log (Nightwatch / log aggregator), creating a persistent PII store outside the database with no equivalent GDPR scrubbing path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'email' => $email` in the log context with a hashed version: `'email_hash' => hash('sha256', $email)`.
        - The `professional_id` and `error` fields are sufficient for incident correlation; the hash allows manual cross-reference if needed without storing raw PII.
    - **Technical:** `Log::warning('Public subscribe customer upsert failed', ['email' => $email, ...])` writes plaintext email to Laravel's log channel, which flows to Nightwatch with retention policies that likely exceed GDPR expectations. The `RedactCustomerJob` and GDPR data export pipeline both explicitly strip or redact PII from database stores, but this log path has no equivalent scrubbing. Supabase/Nightwatch log retention is typically 30–90 days, well beyond what a "temporary operational breadcrumb" justifies for raw email.
    - **Plain English:** When the system hits a hiccup saving a newsletter signup's profile, it writes the customer's email address into the server log diary. That diary lasts much longer than expected and wouldn't be caught by the "delete everything on this person" cleanup. Scrambling the email in the log (using a one-way hash) keeps debugging possible without keeping the raw address.
    - **Evidence:**
        ```php
        } catch (\Throwable $exception) {
            // Do not block successful subscription if customer sync fails.
            Log::warning('Public subscribe customer upsert failed', [
                'professional_id' => (string) $site->professional_id,
                'email' => $email,
                'error' => $exception->getMessage(),
            ]);
        }
        ```

- [x] **#SEC-2** · P2 — Data export includes `ip_hash` and `user_agent` from `lead_submissions` — inconsistent with redaction applied to enquiries
    - **Where:** app/Services/Professional/DataExport/DataExportPayloadBuilder.php (bookings method, lead_submissions query)
    - **Affects:** Professionals receiving a data export get technical fingerprint metadata for lead submissions that is deliberately stripped from the parallel enquiries export.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->select(['id', 'occurred_at', 'outcome', 'form_started_at_ms', 'customer_id', 'created_at'])` to the `lead_submissions` query — mirroring the enquiries approach of excluding `ip_hash` and `user_agent`.
        - The `ExportCustomerDataJob::gatherExportData()` (Shopify GDPR path) has the same gap — apply the same select restriction there too.
    - **Technical:** The `enquiries()` method uses `->select(['id', 'name', 'email', 'phone', 'subject', 'message', 'created_at'])`, deliberately dropping `ip_hash` and `user_agent` — a comment even says "Mirror the redaction in ExportCustomerDataJob." The `bookings()` method fetches `lead_submissions` with `->get()->map(fn ($r) => (array) $r)`, returning every column. The platform's stance (strip technical fingerprints from exports) should apply uniformly.
    - **Plain English:** The contact-form section of the data export thoughtfully hides tracking info (IP fingerprint, browser type). But the lead-capture section includes that same tracking info. It's like redacting a phone number on page one of a report but printing it in full on page three. Apply the same rule everywhere.
    - **Evidence:**
        ```php
        // enquiries — redacted (correct):
        ->select(['id', 'name', 'email', 'phone', 'subject', 'message', 'created_at'])

        // lead_submissions — NOT redacted:
        $leads = DB::connection('pgsql')
            ->table('analytics.lead_submissions')
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
        ```

- [x] **#SEC-3** · P2 — `SendEnquiryNotificationJob::failed()` logs the professional's notification email — PII in the permanent error log
    - **Where:** app/Jobs/Notifications/SendEnquiryNotificationJob.php:56-61
    - **Affects:** The professional's configured notification email is written to the log aggregator whenever the job exhausts all retries — a persistent record outside the database's data-retention and GDPR scrubbing scope.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `'notification_email' => $this->notificationEmail` from the `Log::error` context in `failed()`.
        - `$this->enquiryId` is sufficient for correlation — the email is recoverable from the database via the enquiry's professional if needed for debugging.
    - **Technical:** The professional's own email address (not a customer's, but still PII under GDPR and Australia's Privacy Act 1988) is logged on permanent failure. Log aggregator retention is typically longer than the 30-day soft-delete window. The `RedactCustomerJob` and related pipelines scrub database rows, but log entries are outside their scope. Other job `failed()` hooks in the codebase (e.g. `SendStaffBroadcastEmailToSubscriberJob`) avoid logging raw email addresses in this path.
    - **Plain English:** If the "new enquiry" email notification fails after all retry attempts, the system writes the store owner's email address into the permanent error diary. The diary is kept much longer than the actual data, and a future account deletion wouldn't clear it. Logging the enquiry ID alone is enough to trace the failure.
    - **Evidence:**
        ```php
        public function failed(\Throwable $e): void
        {
            report($e);
            Log::error('SendEnquiryNotificationJob failed permanently', [
                'enquiry_id' => $this->enquiryId,
                'notification_email' => $this->notificationEmail,
                'error' => $e->getMessage(),
            ]);
        }
        ```

- [x] **#LIFE-3** · P2 — `PublicCustomerLeadController` catches generic `QueryException` for unique-constraint detection
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicCustomerLeadController.php:184-188
    - **Affects:** Marketing subscription upserts during lead form submissions. Same fragility as LIFE-2 — a deadlock or connection drop could be silently swallowed as a "duplicate ignore."
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `catch (QueryException $e)` + `$e->getCode() !== '23505'` with `catch (UniqueConstraintViolationException $e)` — identical fix as LIFE-2, same canonical typed-exception pattern.
    - **Technical:** Same root cause as LIFE-2. Lower volume than the Shopify webhook path (dozens/day vs. thousands), but the same risk of intercepting unrelated query failures. The typed exception is a strict superset of the string-code check and carries no performance cost.
    - **Plain English:** Same as LIFE-2 — the net catches all fish when it only wants one species. Lower-traffic path, same fix.
    - **Evidence:**
        ```php
        } catch (QueryException $e) {
            // If a race creates the row first, just ignore.
            if ($e->getCode() !== '23505') {
                throw $e;
            }
        }
        ```

- [x] **#LIFE-5** · P2 — `ContactCaptureService` swallows all exceptions without `report()` — Nightwatch is blind to contact-capture failures on the highest-throughput path
    - **Where:** app/Services/Customers/ContactCaptureService.php:101-108, 164-176
    - **Affects:** Every order webhook, booking, and lead that calls `captureContact()` or `captureMarketingSubscription()`. At peak load (~3K orders/day), a Postgres connectivity blip produces dozens of swallowed exceptions per minute with no Nightwatch visibility.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e);` before each `Log::warning(...)` in both catch blocks — identical fix as LIFE-4.
    - **Technical:** Both `captureContact()` and `captureMarketingSubscription()` catch `Throwable`, log a warning, and return. Neither calls `report($e)`. `Log::warning` is a breadcrumb; Nightwatch alerts on exceptions. Without `report()`, a Postgres outage during peak order processing would accumulate hundreds of silent customer-record failures with no alert firing until a support ticket arrives.
    - **Plain English:** The contact-capture service is designed to never crash the main order flow — but when it fails, it only scribbles in a journal. No alarm goes off. If the database has a bad moment, hundreds of customer records silently fail to save and no one finds out until days later.
    - **Evidence:**
        ```php
        } catch (Throwable $e) {
            Log::warning('Contact capture failed', [
                'professional_id' => $professionalId,
                'source' => $data['source'] ?? null,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
        ```
        ```php
        } catch (Throwable $e) {
            Log::warning('Marketing subscription capture failed', [
                'professional_id' => $professionalId,
                'message' => $e->getMessage(),
            ]);
        }
        ```

- [x] **#LIFE-6** · P2 — `InviteExpirySweepJob::failed()` has no tenant context — Nightwatch can't correlate a top-level crash to a brand
    - **Where:** app/Jobs/Notifications/InviteExpirySweepJob.php:72-76
    - **Affects:** Operational visibility into the daily invite-expiry sweep. When the job fails before processing any chunk (e.g. DB connection timeout), the error log carries no brand or operation context.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - The per-invite `Log::warning` inside the loop correctly includes `brand_professional_id` (good). The top-level `failed()` method should add at minimum `'job' => 'InviteExpirySweepJob'` and the sweep date so ops can cross-reference the alert with the database. If a brand can be captured at job construction time, include it.
        - Follow the pattern in `FanOutBrandStatusNotificationJob::failed()` which includes `brand_professional_id` and `brand_status`.
    - **Technical:** `failed()` logs only `'message' => $e->getMessage()`. Nightwatch groups exceptions by message + stack trace. Without a tenant discriminator, a single malformed invite row that crashes the sweep produces an undifferentiated alert. The per-invite warning inside the loop does include context, but if the job fails before chunking begins, the `failed()` log is the only record.
    - **Plain English:** When the overnight invite cleanup job crashes, the error log says "sweep failed" but doesn't say whose invite broke it. Adding the sweep date and job name to the crash log is like writing the room number on a fire alarm — the responder knows exactly where to go.
    - **Evidence:**
        ```php
        public function failed(\Throwable $e): void
        {
            Log::error('Invite expiry sweep job failed', [
                'message' => $e->getMessage(),
            ]);
        }
        ```

- [x] **#CACHE-1** · P2 — `NotificationPublisher::loadResolvedMap` has no single-flight lock — stampede risk on cold cache during fan-out
    - **Where:** app/Services/Notifications/NotificationPublisher.php:186-194
    - **Affects:** Email delivery path for all professionals. During fan-out events (brand status changes, weekly analytics) many workers can recompute the same preferences map simultaneously.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the cache-miss / compute / cache-put block in `CacheLockService::rememberLocked` — the same utility already used by `NotificationListingService` and `CommerceNotificationService`. Keep the same TTL; `CacheLockService` adds jitter and SWR semantics automatically.
    - **Technical:** The method uses plain cache-aside: `$cached = Cache::get($key); if (is_array($cached)) return $cached; $map = compute…; Cache::put(…)`. Under cold cache after a deploy or mass eviction, all concurrent calls for the same professional bypass the cache and trigger three DB queries each. A single `FanOutBrandStatusNotificationJob` at 50 affiliates can trigger 50+ parallel `loadResolvedMap` calls in the same second. `CacheLockService::rememberLocked` already exists for this exact pattern.
    - **Plain English:** Fifty delivery workers all check the same shared preferences sheet at once. If the sheet is missing, all fifty run to the back office to fetch a fresh copy — fifty identical database round-trips. With a lock, only one worker makes the trip; the others wait and share that copy.
    - **Evidence:**
        ```php
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $map = self::computeResolvedMap($professionalId);

        try {
            Cache::put($key, $map, self::CACHE_TTL_SECONDS);
        } catch (\Throwable $e) { … }
        ```

- [x] **#CACHE-2** · P2 — `NotificationPublisher` uses `Cache` facade without pinning to the Redis store — file-driver fallback would break cross-worker sharing
    - **Where:** app/Services/Notifications/NotificationPublisher.php:186 (`Cache::get`), :194 (`Cache::put`), :205 (`Cache::forget`), :213-214 (`Cache::add`, `Cache::increment`)
    - **Affects:** Preferences cache for all notification email sends. Under a misconfigured or local-development environment where the default cache store is `file`, each Horizon worker maintains an independent local cache, defeating sharing and causing repeated DB queries.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Append `->store('redis')` to every `Cache` call in this service, or inject `Cache::store('redis')` via a private helper method to stay DRY.
    - **Technical:** The application's caching architecture is Redis-backed, but these calls rely on `config('cache.default')`. In local dev or a misconfigured staging environment, the default could be `array` or `file` — per-worker, non-shared, and non-persistent. The `bumpGlobalVersion` method (`Cache::add` + `Cache::increment`) is particularly sensitive: if the global version key is file-cached, worker A bumps it but worker B never sees the bump, so per-professional cache invalidation silently breaks across workers.
    - **Plain English:** The preferences cache is meant to be a shared whiteboard every worker reads and updates. Pinning to the `file` driver turns it into personal notepads — no worker sees what others wrote. Explicitly naming `redis` keeps it the shared whiteboard even if someone changes the default store in `.env`.
    - **Evidence:**
        ```php
        $cached = Cache::get($key);
        …
        Cache::put($key, $map, self::CACHE_TTL_SECONDS);
        Cache::forget(self::cacheKey($professionalId));
        Cache::add(self::GLOBAL_VERSION_KEY, 1, null);
        Cache::increment(self::GLOBAL_VERSION_KEY);
        ```

- [x] **#CACHE-3** · P2 — `NotificationListingService::bustIndexCache` uses `Cache` facade without pinning to Redis — busting a file-cached key leaves other workers stale
    - **Where:** app/Services/Notifications/NotificationListingService.php:136-139
    - **Affects:** The notification bell unread count and listing cache. After a user marks a notification as read, only the local worker's file-cached copy is deleted; other workers continue to serve the old unread count until TTL expiry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->store('redis')` to each `Cache::forget` call inside `bustIndexCache`.
    - **Technical:** Same root cause as CACHE-2. The `bustIndexCache` iterates the known set of (limit, dismissed) keys and calls `Cache::forget`. With a `file` driver, only the local worker's copy disappears. Other Horizon workers or web servers continue to serve cached (stale) notification lists, making "mark as read" appear broken until the 15s TTL expires naturally.
    - **Plain English:** When you mark a notification as read, the app needs to clear the cached count from the shared whiteboard so all dashboards update. If the cache is on personal notepads instead, only one dashboard updates — the others still show the old count for up to 15 seconds.
    - **Evidence:**
        ```php
        foreach ([50, 100, 200] as $limit) {
            foreach ([false, true] as $includeDismissed) {
                $key = $this->cacheKey($professionalId, $limit, $includeDismissed);
                Cache::forget($key);
                Cache::forget($key.':stale');
            }
        }
        ```

- [x] **#SCALE-2** · P2 — `DataExportPayloadBuilder` loads unbounded row sets into memory — OOM risk for mature professionals
    - **Where:** app/Services/Professional/DataExport/DataExportPayloadBuilder.php:101-103, 112-115, 161-168, 172-180
    - **Affects:** GDPR Article 15 right-of-access exports. A large-brand export (5K+ customers, 10K+ orders, 5K+ subscribers) can exhaust PHP memory, causing the export to fail and violating the legal obligation to deliver.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `->get()` with `->lazy()` (Laravel cursor-based lazy collection) for each section, and stream JSON sections to the zip writer incrementally rather than building a monolithic `$payload` array in `build()`.
        - In `DataExportZipWriter::write()`, accept a generator or lazy collection per section and write each row to the CSV/JSON stream incrementally.
    - **Technical:** Six `->get()` calls materialise every matching row into PHP memory simultaneously. At the scale target: 5K customers (×~10 fields ×~2KB) + 5K email_subscriptions + 10K orders + lead submissions + policies = potentially 60–80MB of raw PHP arrays. `json_encode()` in `DataExportZipWriter` then allocates a second copy. Peak memory per export can reach 150MB, exceeding a standard 128MB `memory_limit`. GDPR exports must never fail — a single OOM blocks a legal right.
    - **Plain English:** A librarian asked to compile every book you've borrowed pulls every book off the shelf at once. For 50 books that's fine; for 5,000 books the desk collapses. The fix: photocopy one shelf at a time and hand over pages as they're ready.
    - **Evidence:**
        ```php
        private function customers(string $professionalId): array
        {
            return DB::connection('pgsql')
                ->table('core.customers')
                ->where('professional_id', $professionalId)
                ->get()          // ← unbounded
                ->map(fn ($r) => (array) $r)
                ->all();
        }
        ```

- [x] **#SCHEMA-2** · P2 — `Schema::hasColumn('email_subscriptions', 'email_lc')` checks the wrong schema — may resolve against a different table in the `search_path`
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php:283-299 (emailLcColumnExists method)
    - **Affects:** Public newsletter signup flow — if `email_subscriptions` exists in any earlier `search_path` schema, the check returns true for the wrong table and the controller sets `email_lc` on the wrong Eloquent model.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace both `Schema::hasColumn` calls with a single `Schema::hasColumn('notifications.email_subscriptions', 'email_lc')`.
        - Since the v2 baseline already includes `email_lc` on this table (confirmed via the unique index `email_subscriptions_unique_pro_list_email_lc ON notifications.email_subscriptions (professional_id, list_key, email_lc)`), the column exists in all environments and this guard can be removed entirely — simplifying the controller and eliminating a `Schema` call per process lifetime.
    - **Technical:** Partna's `search_path` includes `public`, `core`, `site`, `brand`, `commerce`, `notifications`, `analytics`, `billing`. An unqualified `Schema::hasColumn('email_subscriptions', ...)` resolves against the first schema in `search_path` containing a table by that name. The fallback `|| Schema::hasColumn('core.email_subscriptions', 'email_lc')` explicitly targets the wrong schema — the model confirms the table is `notifications.email_subscriptions`.
    - **Plain English:** The signup form asks "does this database column exist?" without specifying which of the eight filing cabinets to look in. If a different cabinet ever gains a table with the same name, the form checks the wrong one. Since the column definitely exists in the right cabinet (confirmed in migrations), the simplest fix is to remove the check entirely.
    - **Evidence:**
        ```php
        private function emailLcColumnExists(): bool
        {
            static $cached = null;
            if ($cached !== null) {
                return $cached;
            }

            $cached = Schema::hasColumn('email_subscriptions', 'email_lc')
                || Schema::hasColumn('core.email_subscriptions', 'email_lc');

            return $cached;
        }
        ```

- [x] **#SCHEMA-3** · P2 — `LOWER(email)` WHERE clauses on `core.customers` lack a functional index — full table scan on every contact capture, GDPR redact, and export
    - **Resolution (2026-05-19):** No-op — the v2 baseline already creates two functional indices: `customers_professional_email_unique ON (professional_id, lower(email)) WHERE email IS NOT NULL` (line 434) and `customers_professional_email_search_idx ON (professional_id, lower(email)) WHERE email IS NOT NULL AND deleted_at IS NULL` (line 437). The audit draft was stale.
    - **Where:** app/Services/Customers/ContactCaptureService.php (captureContact), app/Jobs/Shopify/Gdpr/RedactCustomerJob.php (handle), app/Jobs/Shopify/Gdpr/ExportCustomerDataJob.php (gatherExportData)
    - **Affects:** Contact capture latency on all Shopify order webhooks, Square bookings, and site leads, plus GDPR redact throughput. Each call runs a full per-professional table scan if no functional index exists on `LOWER(email)`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Verify: `customers_professional_email_unique ON core.customers (professional_id, lower(email))` is confirmed in the v2 baseline migration — this unique index IS present and already serves the lookup.
        - The `whereRaw('lower(email) = ?', [$email])` queries should already hit this index. Confirm with `EXPLAIN` in the Supabase SQL editor.
        - If `EXPLAIN` shows a seq scan, add `CREATE INDEX CONCURRENTLY ON core.customers (professional_id, lower(email))` — but the unique index should be sufficient.
    - **Technical:** The v2 baseline migration creates `CREATE UNIQUE INDEX customers_professional_email_unique ON core.customers (professional_id, lower(email)) WHERE (email IS NOT NULL)`. The `whereRaw('lower(email) = ?')` pattern in three critical paths should hit this index. The actual performance risk is low (the index exists), but an `EXPLAIN` should confirm Postgres is using it rather than a sequential scan — particularly for `withTrashed()` queries that bypass the `email IS NOT NULL` partial index condition if soft-deleted rows have null emails.
    - **Plain English:** The database already has a fast lookup path for email addresses — this is more of a "please verify" than a "please fix." Run an `EXPLAIN` on the customer lookup query to confirm the index is being used, especially for deleted-customer queries.
    - **Evidence:**
        ```php
        // All three critical paths use this pattern:
        ->whereRaw('lower(email) = ?', [$email])

        // v2 baseline confirms the index exists:
        // CREATE UNIQUE INDEX customers_professional_email_unique
        //     ON core.customers (professional_id, lower(email))
        //     WHERE (email IS NOT NULL);
        ```

- [x] **#SCHEMA-4** · P2 — `notifications.notifications.type` and `severity` columns have no CHECK constraint — arbitrary values accepted at DB level
    - **Resolution (2026-05-19):** Added `notifications_type_check` in `supabase/migrations/202605190000004_add_notifications_check_constraints.sql`. `severity` already had `notifications_severity_check` from the v2 baseline line 964 — only `type` needed adding.
    - **Where:** app/Models/Core/Notifications/Notification.php:23-28 (FRONTEND_TYPES constant) — no corresponding CHECK in `202605190000002_add_enum_check_constraints.sql`
    - **Affects:** Data integrity — a buggy or direct INSERT can store arbitrary type strings, breaking the frontend's type-to-icon mapping and `normalizeFrontendType()` normalization.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add to a new migration using the `NOT VALID` + `VALIDATE CONSTRAINT` two-step pattern (already used in `202605190000002_add_enum_check_constraints.sql`):
            ```sql
            ALTER TABLE notifications.notifications
                ADD CONSTRAINT notifications_type_check
                CHECK (type IN ('Success','Critical','Warning','Invitation','To do','Info'))
                NOT VALID;
            ALTER TABLE notifications.notifications
                ADD CONSTRAINT notifications_severity_check
                CHECK (severity IN ('critical','warning','info'))
                NOT VALID;
            ```
        - Then validate each in a separate transaction.
    - **Technical:** The recent `202605190000002_add_enum_check_constraints.sql` swept eight enum-like columns but missed `notifications.type` and `notifications.severity`. The model defines `FRONTEND_TYPES` and `normalizeFrontendType()` maps input to six canonical values in PHP, but the database column is unconstrained — a raw INSERT or a future code path that bypasses normalization can store anything. This is the exact class of risk the enum-check sweep was designed to close.
    - **Plain English:** The notification system has six official alert types. The app code knows how to normalize anything into one of them. But the database itself doesn't enforce this rule — it'll happily store "banana" as a type if something writes directly to the table. This is the exact check the last migration sweep ran on eight other columns but missed here.
    - **Evidence:**
        ```php
        public const FRONTEND_TYPES = [
            'Success', 'Critical', 'Warning', 'Invitation', 'To do', 'Info',
        ];
        // No CHECK constraint in 202605190000002_add_enum_check_constraints.sql
        // for notifications.notifications.type or .severity
        ```

- [x] **#SCHEMA-5** · P2 — `notification_email_policies.mode` has no CHECK constraint — mistyped mode values silently disable policies
    - **Resolution (2026-05-19):** No-op — the v2 baseline (line 1020) already has `CHECK (mode IN ('default', 'force_on', 'force_off'))` inline on the column. The audit draft was stale.
    - **Where:** app/Models/Core/Notifications/NotificationEmailPolicy.php — no CHECK in any verified migration
    - **Affects:** Email delivery correctness — a mistyped mode value (e.g. `'force_on '` with trailing space, or `'force-on'`) silently falls through the `computeResolvedMap` resolution chain and defaults to `true`, effectively ignoring a `force_off` policy.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `CHECK (mode IN ('force_on', 'force_off'))` constraint via `NOT VALID` + `VALIDATE CONSTRAINT` pattern to the `notifications.notification_email_policies` table.
    - **Technical:** `computeResolvedMap` matches `$perPro === 'force_on'` and `'force_off'` exactly. A typo written directly to the DB (or via a future staff tool that doesn't validate) falls through all branches and applies the default (`true`). The `202605190000002_add_enum_check_constraints.sql` sweep covered eight columns but missed `notification_email_policies.mode`. Same pattern as SCHEMA-4.
    - **Plain English:** Staff can set a policy to "always send" or "never send." If someone types "force-on" (with a dash) instead of "force_on" (with an underscore) directly into the database, the policy is treated as if it doesn't exist. A database rule that rejects anything not exactly "force_on" or "force_off" prevents this.
    - **Evidence:**
        ```php
        // Resolution chain — mistyped modes fall through all branches silently:
        if ($perPro === 'force_on') { $map[$category] = true; continue; }
        if ($perPro === 'force_off') { $map[$category] = false; continue; }
        if ($global === 'force_on') { $map[$category] = true; continue; }
        if ($global === 'force_off') { $map[$category] = false; continue; }
        // No CHECK constraint in any migration for this column
        ```

- [x] **#OBS-3** · P2 — `CommerceNotificationService` log context missing `event_key` and `booking_id` on failure — can't trace which booking lost its notification
    - **Where:** app/Services/Notifications/CommerceNotificationService.php:104-108
    - **Affects:** Booking completion flow. When the notification publish or milestone check fails, Nightwatch sees the exception (via `report()`) but the warning log only carries `professional_id` — ops can't tell which specific booking triggered the failure without additional queries.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'event_key' => $eventKey ?? null, 'booking_id' => $bookingId ?? null` to the `Log::warning` context array.
        - These variables are already computed earlier in `notifyBookingCompleted()` — they just need to be captured before the try block or passed into the catch scope.
    - **Technical:** The catch block has access to `$context` (the raw input array) but the derived `$eventKey` and `$bookingId` are computed inside the try block. Moving their computation before the try block (they are pure string operations with no failure mode) makes them available in the catch. Nightwatch shows the exception, but without the booking key, correlating the dropped notification to a specific booking requires manual DB querying.
    - **Plain English:** When a booking notification fails, the error log says "professional X had a problem" but not "booking Y for professional X." Adding the booking ID to the log is like adding the order number to an error receipt — support can find the exact transaction instantly.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Booking notifications failed', [
                'professional_id' => $context['professional_id'] ?? null,
                'message' => $e->getMessage(),
                // missing: 'event_key', 'booking_id'
            ]);
        }
        ```

- [x] **#OBS-4** · P2 — `SendStaffBroadcastEmailsJob` returns silently when the notification is not found — broadcast silently never fans out
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:43-46
    - **Affects:** Staff broadcast email fan-outs. If the notification is deleted between dispatch and execution, the entire broadcast silently does nothing — no log, no Horizon failure, no Nightwatch event.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Log::warning('SendStaffBroadcastEmailsJob: notification not found, broadcast aborted', ['notification_id' => $this->notificationId, 'list_key' => $this->listKey]);` before the `return`.
        - Every other notification job in the codebase (`SendEnquiryNotificationJob`, `FanOutBrandStatusNotificationJob`) logs a warning when its target entity is not found.
    - **Technical:** The job returns silently. A race condition (notification deleted via API while the job is queued) produces a green Horizon dashboard but a broadcast that never went out. Nightwatch has zero record. The `failed()` method only fires on exceptions — a silent return never reaches it.
    - **Plain English:** If a staff member schedules a broadcast email and then deletes the notification before the send starts, the job quietly does nothing. No error, no log, no "this broadcast was never sent" alert. Every other similar job in the system leaves a note when this happens.
    - **Evidence:**
        ```php
        $notification = Notification::query()->find($this->notificationId);
        if (! $notification) {
            return;  // ← no log, no Nightwatch trace
        }
        ```

- [x] **#OBS-5** · P2 — `SendStaffBroadcastEmailToSubscriberJob` returns silently when notification or subscription is not found
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php:53-60
    - **Affects:** Individual subscriber deliveries within a staff broadcast batch. In a batch with `allowFailures()`, a silent return is indistinguishable from a successful send — the batch shows "completed" with zero failures even if emails were not sent.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Log::warning('SendStaffBroadcastEmailToSubscriberJob: entity not found', ['notification_id' => $this->notificationId, 'subscription_id' => $this->subscriptionId, 'missing' => $notification ? 'subscription' : 'notification']);` at the two early-return sites.
    - **Technical:** The job runs inside `Bus::batch()->allowFailures()`. Because a silent `return` is not an exception, the batch's `totalJobs`/`failedJobs` counters are unaffected — the batch shows fully successful. Ops has no way to distinguish "subscriber unsubscribed between dispatch and run" (acceptable no-op) from "subscription row went missing" (data anomaly).
    - **Plain English:** Each subscriber in a broadcast gets their own mini-job. If that job can't find the subscriber's record, it just stops with no note. The batch dashboard looks perfect — all succeeded. But some emails were never sent, and there's no way to know which ones or why.
    - **Evidence:**
        ```php
        $notification = Notification::query()->find($this->notificationId);
        if (! $notification) {
            return;  // ← silent
        }

        $sub = EmailSubscription::query()->find($this->subscriptionId);
        if (! $sub) {
            return;  // ← silent
        }
        ```

- [x] **#JOB-2** · P2 — `FanOutBrandStatusNotificationJob` missing `ShouldBeUnique` — concurrent runs dispatch double batches
    - **Where:** app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php:33 (class declaration)
    - **Affects:** Queue resource — every connected affiliate gets double `SendBrandStatusNotificationJob` instances when the fan-out runs twice concurrently. The leaf job's dedupe key prevents duplicate notification rows, but double the queue work still runs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `implements ShouldBeUnique` to the class declaration.
        - Add `public function uniqueId(): string { return 'fanout-brand-status:'.$this->brandProfessionalId.':'.$this->brandStatus; }`.
    - **Technical:** If two instances run concurrently (brand status flips twice quickly, or Horizon restarts during processing), both walk the same `brand_partner_links` table and dispatch duplicate batches. The leaf job's dedupe key prevents the duplicate notification DB row, but the `mail` queue still processes double the work for every subscriber. `ShouldBeUnique` with `uniqueId()` keyed on `(brandProfessionalId, brandStatus)` serialises concurrent runs for the same status transition without affecting per-attempt retry semantics.
    - **Plain English:** Two delivery crews grab the same mailing list at the same time. The final delivery is blocked (the dedupe key), but both crews stuff envelopes for every recipient — double the wasted effort. A "one crew at a time for this list" rule stops the waste without affecting retries.
    - **Evidence:**
        ```php
        class FanOutBrandStatusNotificationJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;
            public int $backoff = 30;
            // No ShouldBeUnique, no uniqueId()
        ```

- [x] **#JOB-3** · P2 — `SendStaffBroadcastEmailsJob` missing `ShouldBeUnique` — concurrent runs double the per-subscriber batch fan-out
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:31 (class declaration)
    - **Affects:** Queue throughput — subscribers get two `SendStaffBroadcastEmailToSubscriberJob` instances each in the `mail` queue. The leaf job's `insertOrIgnore` on `broadcast_email_receipts` prevents duplicate emails (backed by `PRIMARY KEY`), but processing cost doubles.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `implements ShouldBeUnique` to the class declaration.
        - Add `public function uniqueId(): string { return 'staff-broadcast:'.$this->notificationId; }`.
    - **Technical:** Same concurrency pattern as JOB-2. If a second instance is dispatched (e.g. staff triggers twice, or Horizon restarts mid-walk), both chunk through the subscriber table and dispatch two leaf jobs per subscriber. The leaf job's `PRIMARY KEY (notification_id, subscription_id)` receipt blocks the duplicate send, but the `mail` queue still does double the work.
    - **Plain English:** Same "two crews, one mailing list" problem. No subscriber gets duplicate emails (the receipt table blocks it), but the post office sorts envelopes twice for everyone. One crew at a time is cheaper.
    - **Evidence:**
        ```php
        class SendStaffBroadcastEmailsJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;
            public array $backoff = [10, 30, 60];
            // No ShouldBeUnique, no uniqueId()
        ```

- [x] **#DINT-2** · P2 — No scheduled job enforces the 30-day soft-delete retention policy — trashed rows accumulate indefinitely _(already implemented: `app/Console/Commands/PurgeSoftDeleted.php` sweeps Customer, Service, SiteMedia, Enquiry, ServiceCategory, and pending-deletion Professionals; scheduled daily at 03:20 UTC in `routes/console.php:63-68`. FeatureFlag is the only other `SoftDeletes` model and has its own `feature-flags:prune-expired` scheduler at 03:30. Auditor false-positive — missed the Artisan command in `app/Console/Commands/`.)_
    - **Where:** app/Jobs/ and app/Console/ (absence)
    - **Affects:** All models using `SoftDeletes` (professionals, customers, sites, etc.). Soft-deleted rows accumulate indefinitely, violating the documented retention policy and slowly bloating storage and index scans.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Implement a scheduled Artisan command (e.g. `gdpr:purge-soft-deletes`) that calls `Model::onlyTrashed()->where('deleted_at', '<', now()->subDays((int) config('partna.soft_delete_retention_days', 30)))->chunkById(500, fn($rows) => $rows->each->forceDelete())` for each SoftDeletes model.
        - Schedule it daily in `routes/console.php` alongside `InviteExpirySweepJob` and `NudgeStuckOnboardingJob`.
        - Log record counts per model after each run so storage reduction is observable.
    - **Technical:** `CLAUDE.md` documents a 30-day soft-delete retention policy configurable via `SOFT_DELETE_RETENTION_DAYS`. The codebase's sweeper jobs (`InviteExpirySweepJob`, `NudgeStuckOnboardingJob`, `SendWeeklyAnalyticsNotificationJob`) handle specific business events, not generic model lifecycle garbage collection. Without a purge job, rows accumulate without bound.
    - **Plain English:** The platform promises that deleted items are kept for 30 days then permanently removed. There's no janitor scheduled to take out the trash. Deleted accounts, customers, and sites pile up forever, taking up storage and eventually slowing queries.
    - **Evidence:**
        No purge/prune job found for soft-deleted records across `app/Jobs/` (only `PurgeAffiliateProductSelectionsJob` for Shopify product selections — unrelated). `CLAUDE.md` documents the 30-day policy; no enforcement mechanism exists.

- [x] **#API-1** · P2 — Notification listing returns raw `stdClass` rows directly in the API response, bypassing the Resource layer
    - **Where:** app/Services/Notifications/NotificationListingService.php:103-126 (buildIndexPayload)
    - **Affects:** All callers of `GET /me/notifications` — both the Professional dashboard bell and the Staff-on-behalf-of endpoint receive raw DB column names directly in JSON.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a `NotificationResource` class in `app/Http/Resources/` that explicitly lists allowed fields.
        - Map each row through `NotificationResource::make($row)` before returning. Since the query uses `DB::table()` rather than Eloquent, wrap each `stdClass` in an `Arrayable` or convert to array first.
        - The explicit `->get([column list])` in `buildIndexPayload` already limits which columns are returned, which reduces (but does not eliminate) the risk — a future developer adding to the select list bypasses the Resource gate.
    - **Technical:** The `buildIndexPayload` method selects via `DB::table(...)->get([...])` and returns `$rows->values()->all()` — raw `stdClass` objects serialised as JSON. The Partna architecture mandates Resource classes for all API responses. Without one, any column added to the select list immediately appears in the API with no review gate. The `Notification` Eloquent model exists and could anchor a Resource class.
    - **Plain English:** Most of the API routes dishes through a kitchen pass (Resource classes) where every plate gets checked before it reaches the customer. The notification list bypasses the pass entirely. Adding the pass here closes the gap and makes future schema additions safe by default.
    - **Evidence:**
        ```php
        $rows = $listQuery
            ->orderByDesc('n.created_at')
            ->limit($limit + 1)
            ->get(['n.id', 'n.type', 'n.title', /* ... */ 'r.read_at', 'r.dismissed_at']);
        // …
        return [
            'unread_count' => $unreadCount,
            'has_more' => $hasMore,
            'notifications' => $rows->values()->all(),  // raw stdClass objects
        ];
        ```

- [x] **#CFG-1** · P2 — `config('partna.public_domain')` has no fallback default — missing env breaks every public-site route
    - **Where:** routes/api/publicSite.php:15
    - **Affects:** All public-site routes (site rendering, bookings, leads, enquiries, subscribe/unsubscribe). An empty `public_domain` config produces an invalid domain group pattern that matches nothing, silently breaking the entire public surface.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change to `config('partna.public_domain', 'partna.au')`.
        - Add an assertion in `AppServiceProvider::boot()` that fails loudly in production if the key returns an empty string: `throw_if(app()->isProduction() && empty(config('partna.public_domain')), \RuntimeException::class, 'partna.public_domain must be configured')`.
    - **Technical:** `Route::group(['domain' => '{subdomain}.'.$publicDomain])` with an empty `$publicDomain` resolves to `{subdomain}.`, which in most Symfony router implementations either matches nothing or matches literally against `.`. A deploy with a typo'd or missing `PARTNA_PUBLIC_DOMAIN` env var silently breaks every public-facing URL.
    - **Plain English:** The file that sets up public website routes reads the domain name from config. If that setting is missing, the routes point at an empty address and every public page silently breaks. A fallback to a known-good domain name means the site stays reachable even if someone forgets the env var.
    - **Evidence:**
        ```php
        $publicDomain = config('partna.public_domain');

        Route::group([
            'domain' => '{subdomain}.'.$publicDomain,
            'where' => ['subdomain' => '[A-Za-z0-9-]+'],
            'prefix' => 'public',
        ], function () {
        ```

- [x] **#CFG-2** · P2 — `config('partna.gdpr.queue')` has no fallback in three GDPR job constructors — missing config silently routes compliance jobs to `default`
    - **Where:** app/Jobs/Shopify/Gdpr/ExportCustomerDataJob.php:37, RedactCustomerJob.php:33, RedactShopJob.php:37
    - **Affects:** Shopify GDPR compliance jobs. Missing config silently merges these jobs into the `default` queue instead of the isolated GDPR queue, defeating the isolation and retry-policy separation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change to `config('partna.gdpr.queue', 'gdpr')` in all three constructors.
        - Ensure `.env.example` lists the corresponding key (`GDPR_QUEUE=gdpr`) so new environments configure it correctly.
    - **Technical:** The GDPR queue exists to isolate compliance jobs with independent retry/backoff policies and a dedicated Horizon pool. `onQueue(null)` (returned by `config()` when the key is absent) silently routes to `default`. `RedactShopJob` compounds this with `onConnection('redis_gdpr')` — the connection override fires but the queue is null, creating a partially-isolated job that lands on `default` queue of the `redis_gdpr` connection.
    - **Plain English:** GDPR jobs — like deleting customer data when required by law — are meant to run in a dedicated lane so they can't be delayed by newsletter sends. If the config key for that lane is missing, these legal-obligation jobs quietly merge into general traffic. A fallback queue name ensures the lane always exists.
    - **Evidence:**
        ```php
        // ExportCustomerDataJob.php:37
        $this->onQueue(config('partna.gdpr.queue'));

        // RedactCustomerJob.php:33
        $this->onQueue(config('partna.gdpr.queue'));

        // RedactShopJob.php:37
        $this->onConnection('redis_gdpr')->onQueue(config('partna.gdpr.queue'));
        ```

---

## P3 — Nice to have

- [x] **#SEC-4** · P3 — `PublicEmailUnsubscribeController` accepts any token string without minimum-length validation — inconsistent with `PublicMarketingPreferenceController`
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicEmailUnsubscribeController.php:11-23
    - **Affects:** Negligible — an empty or trivially short token hits the database but never matches a real 48-character unsubscribe token. The inconsistency is the primary concern.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `if (strlen($token) < 10) { return $this->error('Invalid or expired unsubscribe link.', 404); }` at the top of `unsubscribe()`, matching `PublicMarketingPreferenceController::show()`.
    - **Technical:** `PublicMarketingPreferenceController` validates `strlen($token) < 10` before querying. `PublicEmailUnsubscribeController::unsubscribe()` passes `$token` directly to `EmailSubscription::where('unsubscribe_token', $token)->first()` with no length gate. Real tokens are `Str::random(48)`, so a short token never matches — but the wasted DB round-trip and the inconsistency create a maintenance risk if token generation ever changes.
    - **Evidence:**
        ```php
        public function unsubscribe(Request $request, string $token): JsonResponse
        {
            $sub = EmailSubscription::query()
                ->where('unsubscribe_token', $token)
                ->first();
            // no strlen($token) guard — contrast with PublicMarketingPreferenceController
        ```

- [x] **#JOB-4** · P3 — All retryable jobs lack `$maxExceptions` — deterministic failures exhaust the full retry window before surfacing
    - **Where:** app/Jobs/Notifications/* and app/Jobs/Shopify/Gdpr/* (every job with `$tries >= 2`)
    - **Affects:** Incident response time — a job that throws on every attempt takes the full `$backoff` window to surface as failed in Horizon.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public int $maxExceptions = 2;` to every job that has `$tries >= 3`.
        - For jobs with `$tries = 2`, add `public int $maxExceptions = 1;`.
    - **Technical:** Without `$maxExceptions`, a job that throws on every attempt exhausts all `$tries` over the full `$backoff` window. For `SendTransactionalNotificationEmailJob` (`$tries=3`, `$backoff=[30, 120, 300]`), that is up to 7.5 minutes before Horizon marks it failed and an alert fires. `$maxExceptions = 2` fails the job after the second consecutive throw regardless of remaining tries — much faster for deterministic failures, while preserving retry semantics for transient failures.
    - **Evidence:**
        ```php
        // SendTransactionalNotificationEmailJob — representative of all audited jobs
        public int $tries = 3;
        public array $backoff = [30, 120, 300];
        // public int $maxExceptions — absent, defaults to $tries (3)
        ```

- [x] **#JOB-5** · P3 — `EmailSubscription::saved` hook dispatches `SyncCustomerMarketingOptInJob` outside transaction safety
    - **Where:** app/Models/Core/Notifications/EmailSubscription.php:105-112
    - **Affects:** `SyncCustomerMarketingOptInJob` may run against a rolled-back `EmailSubscription` row, wasting a queue slot. The job degrades gracefully (returns early if no customer found), so no data corruption occurs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the dispatch in `DB::afterCommit(fn() => SyncCustomerMarketingOptInJob::dispatch(...))` so the job only enters the queue after the surrounding transaction commits.
    - **Technical:** The `saved` Eloquent event fires immediately after `save()`, which may still be inside an open transaction. If the transaction rolls back, the `EmailSubscription` row never exists but the job is already queued. The job's `handle()` calls `Customer::where(...)->first()` and returns early if no customer is found — so no corruption. The wasted queue slot and confusing no-op log are the only effects.
    - **Evidence:**
        ```php
        protected static function booted(): void
        {
            static::saved(function (self $subscription) {
                if ($subscription->list_key === 'marketing' && $subscription->professional_id && $subscription->email) {
                    \App\Jobs\Notifications\SyncCustomerMarketingOptInJob::dispatch(
                        (string) $subscription->professional_id,
                        (string) $subscription->email,
                        $subscription->status === 'subscribed',
                    );
                }
            });
        }
        ```

- [x] **#API-2** · P3 — Notification listing uses `limit`+`has_more` pagination while every other list endpoint uses page-based `paginate()`
    - **Where:** app/Services/Notifications/NotificationListingService.php:89-102, app/Http/Controllers/Api/Professional/Notifications/NotificationController.php:27-31
    - **Affects:** Frontend developers — different pagination logic is required for notifications vs. every other list endpoint.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Either migrate to `paginate()` style for consistency, or document the divergence as intentional (e.g. real-time polling where total-count metadata adds no value) and add a `next_cursor` token so clients can resume deterministically after new notifications arrive between pages.
    - **Technical:** `NotificationController::index()` accepts `?limit=` (default 50) and returns `{unread_count, has_more, notifications: [...]}`. Every other list controller uses `->paginate($perPage)` and returns `meta.current_page`, `meta.last_page`, `links.next`. Without a cursor token, clients must maintain a local offset that becomes inconsistent if new notifications arrive between requests.
    - **Evidence:**
        ```php
        // NotificationController — limit-based:
        $limit = (int) $request->query('limit', 50);
        return $this->success($this->listing->index($pro->id, $limit, $includeDismissed));

        // ProfessionalEmailSubscriptionController — page-based:
        $page = $query->paginate($perPage)->appends($request->query());
        return $this->success($this->paginatedResponse($page, 'subscriptions', [...]));
        ```

- [x] **#API-3** · P3 — No audience-specific Resource class for `EmailSubscription` — Professional and Staff endpoints share raw model serialisation
    - **Where:** app/Http/Controllers/Api/Professional/Notifications/ProfessionalEmailSubscriptionController.php:51-52, app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php:46-47
    - **Affects:** Future development — adding a Staff-only field (e.g. `admin_notes`) has no safe place to land; it either leaks to Professionals or hides from Staff.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `ProfessionalEmailSubscriptionResource` and `StaffEmailSubscriptionResource` in `app/Http/Resources/`.
        - Both currently expose the same fields, so the content is identical — the value is the architectural separation that makes future Staff-only additions safe by default.
    - **Technical:** Both controllers call `paginate()` and pass the result through `paginatedResponse()`, which serialises Eloquent models via `toArray()` (respecting `$hidden`). Currently `$hidden` correctly strips `unsubscribe_token`, `consent_ip_hash`, and `consent_user_agent` from both. But the all-or-nothing `$hidden` gate means the next developer who adds a Staff-internal field has no obvious place to express "this is Staff-only."
    - **Evidence:**
        ```php
        // ProfessionalEmailSubscriptionController.php:51-52
        $page = $query->paginate($perPage)->appends($request->query());
        return $this->success($this->paginatedResponse($page, 'subscriptions', [...]));

        // StaffEmailSubscriberController.php:46-47 — identical pattern, no Resource
        $page = $query->paginate($perPage)->appends($request->query());
        return $this->success($this->paginatedResponse($page, 'subscriptions', [...]));
        ```

- [x] **#CFG-3** · P3 — `BATCH_CHUNK_SIZE` constant duplicated across two fan-out jobs with sync-warning comments
    - **Where:** app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php:37, app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:37
    - **Affects:** Redis pipeline write load — if only one constant is changed, the two fan-out paths diverge in batch size silently.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract to `config('sidest.notifications.batch_chunk_size', 200)`.
        - Replace both `private const BATCH_CHUNK_SIZE` declarations with config reads.
        - Remove the "keep in sync" comments — the config file becomes the single source of truth.
    - **Evidence:**
        ```php
        // FanOutBrandStatusNotificationJob.php:37
        // Shared with SendStaffBroadcastEmailsJob — keep in sync if changed.
        private const BATCH_CHUNK_SIZE = 200;

        // SendStaffBroadcastEmailsJob.php:37
        // Shared with FanOutBrandStatusNotificationJob — keep in sync if changed.
        private const BATCH_CHUNK_SIZE = 200;
        ```

- [x] **#CFG-4** · P3 — Notification listing cache TTL hardcoded to `15` in `NotificationListingService`
    - **Where:** app/Services/Notifications/NotificationListingService.php:54
    - **Affects:** In-app notification bell polling frequency — tuning requires a code change and redeploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `15` with `config('sidest.notifications.listing_cache_ttl_seconds', 15)`.
        - Consider extracting the other hardcoded TTLs in the notifications domain (`NotificationPublisher::CACHE_TTL_SECONDS = 3600`, `CommerceNotificationService::MILESTONE_TOTALS_TTL_SECONDS = 60`) for consistency.
    - **Evidence:**
        ```php
        return $this->cache->rememberLocked(
            $this->cacheKey($professionalId, $limit, $includeDismissed),
            15,  // ← hardcoded; no config key
            fn () => $this->buildIndexPayload($professionalId, $limit, $includeDismissed),
        );
        ```
