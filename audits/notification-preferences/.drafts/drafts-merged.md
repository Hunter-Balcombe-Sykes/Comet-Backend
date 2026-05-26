
<!-- ═══ LENS: security ═══ -->

- [ ] **#SEC-1** · P1 — Data export ZIP leaks unsubscribe tokens and consent metadata via raw DB query that bypasses Eloquent `$hidden`
    - **Where:** app/Services/Professional/DataExport/DataExportPayloadBuilder.php:224-229
    - **Affects:** Any professional whose data export ZIP is requested (self-service or staff-initiated). The ZIP's `data.json` contains every `unsubscribe_token`, `consent_ip_hash`, and `consent_user_agent` for their marketing subscribers — tokens that, in the wrong hands, can programmatically unsubscribe anyone from the professional's marketing list.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Use Eloquent (`EmailSubscription::query()`) instead of `DB::table()` so the model's `$hidden` array strips `unsubscribe_token`, `consent_ip_hash`, and `consent_user_agent` before serialisation.
        - Alternatively, add explicit `->select([...])` excluding those three columns to the raw query.
    - **Technical:** The `EmailSubscription` Eloquent model declares `protected $hidden = ['unsubscribe_token', 'consent_ip_hash', 'consent_user_agent']`, which Laravel strips during `toArray()` / JSON serialisation. But `DataExportPayloadBuilder::emailSubscriptions()` uses `DB::table('notifications.email_subscriptions')->get()->map(fn ($r) => (array) $r)->all()`, a raw query builder call that bypasses Eloquent entirely — no `$hidden`, no casts, no accessors. Every column lands verbatim in the export JSON.
    - **Plain English:** Think of the model as having a "shred these sensitive fields before handing over a copy" rule. But the export builder grabs the data directly from the database drawer without going through the model's shredder, so the sensitive fields — including tokens that act like a one-click "unsubscribe me" key — end up in the ZIP file.
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
    - `[DRAFT, confidence: 0.90]`

- [ ] **#SEC-2** · P0 — StaffEmailSubscriberController has no authorization check — any staff member can view any professional's entire marketing subscriber list
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php (entire controller)
    - **Affects:** Every professional's marketing email subscribers — customer emails and full names — exposed to any authenticated staff member with no restriction on which professional they can inspect.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->authorizeForUser($staffUser, 'viewEmailSubscribers', $professional)` (or equivalent Policy ability) at the top of both `index()` and `export()`.
        - Register a corresponding Policy if one does not already exist for staff-on-behalf-of-professional access.
    - **Technical:** The controller receives a `Professional $professional` via route model binding and immediately queries `EmailSubscription::where('professional_id', $professional->id)` with no call to `authorizeForUser`, no Policy gate, and no inline ownership check. Under the Supabase JWT architecture, `Auth::user()` is always null, so even a theoretical `authorize()` call would silently pass. The correct pattern is `$this->authorizeForUser($resolvedActor, 'ability', $resource)`. This controller does neither — it is a wide-open tenant-boundary bypass for the entire staff surface.
    - **Plain English:** Imagine a support dashboard where every support agent can click into any store's customer list. Right now, there's no lock on the door — any agent who's logged in can pull up any store's full subscriber email list simply by knowing the store's ID. There's no "are you assigned to this store?" check.
    - **Evidence:**
        ```php
        public function index(Request $request, Professional $professional): JsonResponse
        {
            // ... no authorization check ...

            $query = EmailSubscription::query()
                ->where('professional_id', $professional->id)
                ->where('list_key', $listKey)
                ->orderByDesc('subscribed_at')
                ->orderByDesc('created_at');
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEC-3** · P2 — PublicEmailSubscriptionController logs customer email address on upsert failure
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php:88-93
    - **Affects:** End-user privacy — every customer email that triggers a `Customer` upsert failure is written to the application log (Nightwatch / log aggregator), creating a persistent PII store outside the database.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the raw `$email` in the log context with a hashed or truncated version (e.g. `hash('sha256', $email)` or `substr($email, 0, 3) . '***'`).
        - Alternatively, remove the email from the log context entirely — `professional_id` and exception message are sufficient for debugging.
    - **Technical:** `Log::warning('Public subscribe customer upsert failed', ['email' => $email, ...])` writes the plaintext email to Laravel's log channel. In production this flows to Nightwatch or an equivalent aggregator with retention policies that likely exceed the GDPR-era expectation of "log data is not a side-channel PII store." The `enquiries` export and `RedactCustomerJob` both explicitly strip or redact PII from exports and data stores, but this log path has no equivalent scrubbing.
    - **Plain English:** When the system hits a hiccup while saving a newsletter signup, it writes the customer's email address into the server diary. That diary sticks around much longer than intended, and if someone ever asks "delete everything you have on me," this diary entry would likely be missed. Better to write a scrambled version or just skip it.
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
    - `[DRAFT, confidence: 0.90]`

- [ ] **#SEC-4** · P2 — Data export includes `ip_hash` and `user_agent` from lead submissions, inconsistent with redaction applied to enquiries
    - **Where:** app/Services/Professional/DataExport/DataExportPayloadBuilder.php:249-255
    - **Affects:** Professionals receiving a data export — they get technical fingerprint metadata (`ip_hash`, `user_agent`) for lead submissions that serves no business purpose and is stripped from the parallel `enquiries` export.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an explicit `->select([...])` to the `lead_submissions` query mirroring the column allow-list used for `enquiries`, excluding `ip_hash` and `user_agent`.
    - **Technical:** `DataExportPayloadBuilder::bookings()` fetches lead submissions via `->get()->map(fn ($r) => (array) $r)->all()`, returning every column including `ip_hash` and `user_agent`. The same builder's `enquiries()` method explicitly selects only `['id', 'name', 'email', 'phone', 'subject', 'message', 'created_at']`, deliberately dropping those technical columns. This inconsistency means lead submissions leak metadata that enquiries do not.
    - **Plain English:** When a store owner downloads their data export, the contact-form entries come clean (no hidden tracking info), but the lead-capture entries include behind-the-scenes technical markers like a scrambled IP fingerprint and browser version. The store owner doesn't need these and they shouldn't be in the export.
    - **Evidence:**
        ```php
        $leads = DB::connection('pgsql')
            ->table('analytics.lead_submissions')
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SEC-5** · P2 — SendEnquiryNotificationJob logs the professional's notification email on permanent failure
    - **Where:** app/Jobs/Notifications/SendEnquiryNotificationJob.php:56-61
    - **Affects:** The professional's configured notification email address is written to the application log whenever the job exhausts all retries — a persistent PII record in Nightwatch / log aggregator.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log `enquiry_id` only; the notification email is recoverable from the database if needed for debugging.
        - If the email is essential for debugging, mask it: `substr($this->notificationEmail, 0, 3) . '***'`.
    - **Technical:** The `failed()` method passes `$this->notificationEmail` directly into the log context. This is the professional's own email (not a customer's), but it is still PII under GDPR and Australia's Privacy Act, and the log aggregator retention window is likely longer than the application's data-retention policy. The Stripe and Shopify webhook handlers elsewhere in the codebase avoid logging raw emails in failure paths, making this an inconsistency.
    - **Plain English:** If the "new enquiry" email notification fails after all retry attempts, the system writes the store owner's email address into the permanent error diary. That diary is kept much longer than the actual data, and if the store owner later deletes their account, this diary entry would stick around.
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
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SEC-6** · P3 — PublicEmailUnsubscribeController accepts any token string without minimum-length validation (inconsistent with PublicMarketingPreferenceController)
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicEmailUnsubscribeController.php:11-23
    - **Affects:** Negligible user impact — an empty or trivially short token hits the database but never matches a real unsubscribe token (which are 48 random characters). The inconsistency with the marketing-preference controller is the primary concern.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `strlen($token) < 10` guard at the top of `unsubscribe()`, matching the pattern in `PublicMarketingPreferenceController::show()`.
        - Return a 400 error for short tokens to avoid a pointless database round-trip.
    - **Technical:** `PublicEmailUnsubscribeController::unsubscribe()` receives `$token` as a route parameter and passes it directly to `EmailSubscription::where('unsubscribe_token', $token)->first()` with no length gate. The sibling `PublicMarketingPreferenceController` validates `strlen($token) < 10` before querying. Real unsubscribe tokens are `Str::random(48)`, so a short token will never match, but the inconsistency creates a maintenance risk — if token generation ever changes to a shorter format, this controller would accept empty-string queries.
    - **Plain English:** Two different "unsubscribe" doors exist in the app. One checks that the key is at least 10 characters before bothering to walk to the database. The other doesn't — it'll try the database with an empty key, which will never work but wastes a trip. It's a minor housekeeping inconsistency, not a security hole today.
    - **Evidence:**
        ```php
        public function unsubscribe(Request $request, string $token): JsonResponse
        {
            $sub = EmailSubscription::query()
                ->where('unsubscribe_token', $token)
                ->first();
            // no strlen($token) guard before the query
        ```
    - `[DRAFT, confidence: 0.80]`

<!-- ═══ LENS: lifecycle-correctness ═══ -->

- [ ] **LIFE-1** · P1 — SendEnquiryNotificationJob has a read-modify-write race on `email_sent_at`
    - **Where:** app/Jobs/Notifications/SendEnquiryNotificationJob.php:38-50
    - **Affects:** Site enquiry email recipients — duplicate enquiry notification emails when the job retries or two workers pick up the same job concurrently.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `email_sent_at` null check + email send + stamp in a `DB::transaction()` with `Enquiry::query()->lockForUpdate()->find($this->enquiryId)`.
        - Follow the exact pattern used by `SendTransactionalNotificationEmailJob` (same file group), which already does `lockForUpdate` inside a transaction.
    - **Technical:** The job reads `$enquiry->email_sent_at !== null`, sends email if false, then stamps the field. Between the read and the write there is no row lock. Two concurrent job instances (retry overlapping with original, or Horizon scaling) both see `null`, both call `Mail::send()`, both stamp — the recipient gets a duplicate. The `SendTransactionalNotificationEmailJob` in the same directory already uses the correct `lockForUpdate` pattern and should be the reference implementation.
    - **Plain English:** Picture two postal workers both checking a mailbox flag that says "mail already delivered." If neither locks the flag while they check it, both see "not delivered," both drop off the same letter, and the recipient gets two copies. The fix is having the first worker flip the flag while holding it so the second worker sees "already delivered" and walks away.
    - **Evidence:**
        ```php
        if ($enquiry->email_sent_at !== null) {
            return; // already sent on a previous attempt
        }

        Mail::to($this->notificationEmail)->send(new SiteEnquiryNotification($enquiry));

        $enquiry->forceFill(['email_sent_at' => now()])->saveQuietly();
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **LIFE-2** · P1 — ContactCaptureService catches generic `QueryException` instead of typed `UniqueConstraintViolationException`
    - **Where:** app/Services/Customers/ContactCaptureService.php:144-150, 165-177, 226-234
    - **Affects:** Every Shopify order webhook, Square booking, and site lead that races on customer creation or marketing subscription upsert. At ~3K orders/day peak, race windows produce ~30 unique-violation exceptions/day that are caught imprecisely.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace all three `catch (QueryException $e)` blocks that match on `23505` with `catch (UniqueConstraintViolationException $e)` (Laravel 10+).
        - Follow the canonical `#STRIPE-3` / `35c6f31` pattern — the typed catch is version-stable across Postgres releases and constraint renames.
    - **Technical:** The current code uses `catch (QueryException $e)` + `$e->getCode() === '23505'` to detect unique-constraint violations. This string-based dispatch is fragile — Postgres error codes are stable but the generic `QueryException` catch also intercepts unrelated query failures (deadlocks, serialization failures, connection drops), silently swallowing them on the `!== '23505'` re-throw path or misrouting them into the race-reconciliation logic. The typed `UniqueConstraintViolationException` is a first-class Laravel 10+ exception that maps directly to SQLSTATE 23505.
    - **Plain English:** The code catches every kind of database error in one net, then checks a numeric code to decide if it's the specific "two people tried to insert the same thing" error. If Postgres changes how it reports errors, or if a completely different error happens to share a code in a future version, the wrong recovery path runs. The fix is using a purpose-built net that only catches that one specific error type — like a filter that only lets through the exact fish you're looking for.
    - **Evidence:**
        ```php
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }
            // Phone collides with another contact
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
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-3** · P2 — PublicCustomerLeadController catches generic `QueryException` for unique-constraint detection
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicCustomerLeadController.php:184-188
    - **Affects:** Marketing subscription upserts during lead form submissions. Lower throughput than the Shopify path (~dozens/day vs ~3K orders/day), but same fragility.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `catch (QueryException $e)` with `catch (UniqueConstraintViolationException $e)`.
        - Same canonical pattern: `#STRIPE-3` / `35c6f31`.
    - **Technical:** Identical pattern to LIFE-2 — `QueryException` catch + `$e->getCode() === '23505'` branch. Same risk of intercepting unrelated query failures. Typed exception is the correct defense.
    - **Plain English:** Same issue as LIFE-2 but in the public lead-capture flow. The net is catching all fish when it only wants one specific species.
    - **Evidence:**
        ```php
        } catch (QueryException $e) {
            // If a race creates the row first, just ignore.
            if ($e->getCode() !== '23505') {
                throw $e;
            }
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-4** · P1 — PublicEmailSubscriptionController swallows customer-upsert exception without reporting to Nightwatch
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php:104-113
    - **Affects:** Every newsletter signup where the follow-up customer upsert fails. The subscription succeeds silently but the customer record is never created or updated — data drifts with zero observability. At ~40K daily notifications fan-out peak and growing subscriber lists, this silent failure accumulates.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($exception);` before the `Log::warning(...)` call inside the catch block.
        - Follow the canonical Log-with-context pattern — Nightwatch alerts trigger on exceptions, not on log queries.
    - **Technical:** The `try/catch` around `upsertMarketingCustomer()` logs a warning but never calls `report($exception)`. Laravel's exception handler (`report()`) forwards to Nightwatch/Datadog — without it, the failure is invisible to alerting. The `Log::warning` call is a breadcrumb, not an alert trigger. Nightwatch groups and alerts on exceptions; a log query requires manual dashboard creation. The `Log::warning` context already includes `professional_id` and `email`, which is good — but the missing `report()` means the ops team never knows the customer record drifted.
    - **Plain English:** When someone signs up for a newsletter, the system also tries to create or update their customer profile. If that second step fails, the code writes a note in a journal but doesn't sound the fire alarm. The journal sits unread until someone manually checks it. The fix is adding one line that triggers the fire alarm so the team knows something needs attention.
    - **Evidence:**
        ```php
        try {
            $this->upsertMarketingCustomer(
                (string) $site->professional_id,
                $email,
                $resolvedName,
                $overwriteName,
            );
        } catch (\Throwable $exception) {
            // Do not block successful subscription if customer sync fails.
            Log::warning('Public subscribe customer upsert failed', [
                'professional_id' => (string) $site->professional_id,
                'email' => $email,
                'error' => $exception->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-5** · P2 — ContactCaptureService swallows all exceptions without reporting
    - **Where:** app/Services/Customers/ContactCaptureService.php:101-108, 164-176
    - **Affects:** Every order webhook, booking, and lead that calls `captureContact()` or `captureMarketingSubscription()`. Failures are logged as warnings but never surface to Nightwatch. At ~3K orders/day peak, this is the highest-throughput silent-failure path in the provided scope.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e);` before each `Log::warning(...)` in both catch blocks.
        - Follow the canonical Log-with-context pattern.
    - **Technical:** Both `captureContact()` and `captureMarketingSubscription()` catch `Throwable`, log a warning, and return null / continue. Neither calls `report($e)`. This is the same pattern as LIFE-4 but on a higher-throughput path — a Postgres connectivity blip during the 3K/day order peak would produce ~30 swallowed exceptions per peak-hour minute with no Nightwatch visibility.
    - **Plain English:** The contact-capture service is designed to never crash the main flow — if saving a customer fails, the order still processes. But when it fails, it only writes a journal entry. No alarm goes off. If the database has a hiccup, hundreds of customer records silently fail to save and no one knows until a support ticket comes in.
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
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-6** · P2 — StaffEmailSubscriberController has no Policy authorization gate
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php:30-65, 71-102
    - **Affects:** GDPR Article 15/20 right-of-access requests routed through the platform inbox. Any authenticated staff member can list and export any professional's email subscribers without a Policy check.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a Policy ability (e.g., `viewSubscribers`) to `SubscriptionPolicy` or a new `EmailSubscriptionPolicy` that gates staff access to a professional's subscriber list.
        - Call `$this->authorizeForUser($staffUser, 'viewSubscribers', $professional)` in both `index()` and `export()` methods before the query.
        - Follow the canonical `#STRIPE-1` / `Policy + Form Request` pattern — authorization through Policies, never implicit.
    - **Technical:** The Partna Authorization Doctrine mandates that authorization goes through Policies, never inline. The controller accepts a `Professional` via route model binding and queries `EmailSubscription` scoped to that professional without any `authorizeForUser` call. The staff `auth` middleware gates authentication, not authorization — it confirms the actor IS staff but not WHICH professional's data they may access. At 200 brands, the staff team may need role-based access (support tier 1 vs. compliance officer); embedding that logic in a Policy now keeps the controller clean as staff roles evolve.
    - **Plain English:** The controller is like a filing cabinet that any employee with a staff badge can open. Right now that's fine because the staff team is small, but at 200 brands with differentiated staff roles (support agent vs. compliance officer), you'll want a lock that checks the employee's role before letting them pull a specific brand's subscriber list. Adding a Policy now means the lock is already installed when you need it.
    - **Evidence:**
        ```php
        // No authorizeForUser call anywhere in the controller
        public function index(Request $request, Professional $professional): JsonResponse
        {
            // ... directly queries EmailSubscription for $professional->id
            $query = EmailSubscription::query()
                ->where('professional_id', $professional->id)
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **LIFE-7** · P2 — InviteExpirySweepJob log context missing `brand_professional_id` for Nightwatch correlation
    - **Where:** app/Jobs/Notifications/InviteExpirySweepJob.php:72-76
    - **Affects:** Operational visibility into the daily invite-expiry sweep. When the job fails at the top level, Nightwatch cannot correlate the failure to a specific brand.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - The per-invite `Log::warning` inside the loop already includes `brand_professional_id` (good), but the top-level `failed()` method's `Log::error` does not — add `brand_professional_id` context by capturing it from the last-processed chunk or by making the job brand-scoped.
        - Follow the canonical Log-with-context pattern — every log emit should carry `brand_professional_id`, operation name, and `request_id` where available.
    - **Technical:** The `failed()` method logs only `'message' => $e->getMessage()` with no tenant or operation context. Nightwatch groups exceptions by message string + stack trace; without a tenant discriminator, a single bad invite row that crashes the sweep produces an alert with no way to identify which brand's invite caused it. The per-invite warning inside the loop does include context, but if the job fails before processing any chunk (e.g., DB connection timeout), the `failed()` log is the only record.
    - **Plain English:** When the overnight invite cleanup job crashes, the error log says "sweep failed" but doesn't say whose invite broke it. Support has to manually scan the database to find the bad row. Adding the brand ID to the crash log is like putting the room number on a fire alarm — the responder knows exactly where to go.
    - **Evidence:**
        ```php
        public function failed(\Throwable $e): void
        {
            Log::error('Invite expiry sweep job failed', [
                'message' => $e->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **LIFE-8** · P3 — CommerceNotificationService catches `\Throwable` at the top level without `report()`
    - **Where:** app/Services/Notifications/CommerceNotificationService.php:80-87
    - **Affects:** Booking-completion notification fan-out. When the entire `notifyBookingCompleted()` call fails, the exception is reported but the inner catch also logs a warning — the `report($e)` is present (good), but this `catch` block duplicates the logging that would happen anyway from the exception bubbling up. Harmless but unnecessary.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `Log::warning` call — `report($e)` already forwards to Nightwatch, and the warning is duplicate signal.
        - Alternatively, if the warning adds context the exception doesn't carry, keep it but ensure it doesn't fire on the same exception that `report()` already sent — use `report($e)` only and let the exception's own message carry the context.
    - **Technical:** The method wraps the entire body in `try { ... } catch (\Throwable $e) { report($e); Log::warning(...); }`. `report($e)` already logs the exception with full stack trace to Nightwatch. The additional `Log::warning` produces a second, less-structured log entry for the same failure. At ~40K daily notifications, this doubles the log volume for booking-notification failures without adding signal.
    - **Plain English:** When the booking notification engine fails, it writes two separate journal entries for the same problem — a detailed one (the automatic crash report) and a shorter handwritten one. The shorter one adds no new information and clutters the journal. Pick one.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Booking notifications failed', [
                'professional_id' => $context['professional_id'] ?? null,
                'message' => $e->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.70]`

<!-- ═══ LENS: scaling-antipatterns ═══ -->

- [ ] **#CACHE-1** · P2 — NotificationPublisher::loadResolvedMap lacks single‑flight lock; stampede risk on cold cache  
    - **Where:** app/Services/Notifications/NotificationPublisher.php:186‑194  
    - **Affects:** Email‑delivery path for all professionals. During fan‑out events (brand status change, etc.) many workers can recompute the same preferences map simultaneously.  
    - **Effort:** S (~0.5–1 h)  
    - **What to do:**  
        - Wrap the cache‑miss / compute / cache‑put block in `CacheLockService::rememberLocked` (or an equivalent Redis lock) so only one worker computes the map while others wait.  
        - Keep the same TTL; the `CacheLockService` already adds jitter and SWR semantics.  
    - **Technical:** The method uses a plain cache‑aside pattern:  
        `$cached = Cache::get($key); if (is_array($cached)) return $cached; $map = compute…; Cache::put(…)`.  
        Under cold cache after a deploy or a mass eviction, all concurrent calls to `resolveEmailEnabled` for the same professional will bypass the cache, causing N identical three‑table scans. At scale (30 brands × 50 affiliates) a single `FanOutBrandStatusNotificationJob` may trigger 50+ parallel lookups, amplifying load. The canonical replacement is `CacheLockService::rememberLocked`, already used elsewhere in the codebase.  
    - **Plain English:** A group of waiters all check the reservation book at once. If the book is empty, each one runs to the office to fetch a fresh copy, all returning with the same list. That is a stampede. With a lock, only one waiter goes to the office; the others wait and use his copy. Fixing this prevents unnecessary database trips when many people need the same information at the same time.  
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
    - `[DRAFT, confidence: 0.9]`  

- [ ] **#CACHE-2** · P2 — Cache facade used in NotificationPublisher without explicit Redis store; file‑driver fallback would break performance and cross‑worker sharing  
    - **Where:** app/Services/Notifications/NotificationPublisher.php:186 (`Cache::get`), 194 (`Cache::put`), 205 (`Cache::forget`), 213‑214 (`Cache::add`, `Cache::increment`)  
    - **Affects:** Preferences cache for all notification email sends. If the default store ever flips to `file`, every Horizon worker maintains an independent local cache, defeating sharing and causing repeated DB queries.  
    - **Effort:** S (~0.5 h)  
    - **What to do:**  
        - Append `->store('redis')` to every `Cache` call in this service.  
        - Consider a helper method that always returns the Redis store to keep the code DRY.  
    - **Technical:** The application’s caching architecture is designed for Redis, but these calls rely on the default store. In local development or a mis‑configured production environment the default could be `file`, which is per‑worker and not shared. This would cause the `loadResolvedMap` cache to be local, leading to both duplicate computation and stale reads across workers. Explicitly pinning to `redis` makes the intent clear and resilient to configuration changes.  
    - **Plain English:** The cache is meant to be a shared whiteboard that every team member can read and update. If someone accidentally replaces it with personal notepads, nobody sees what others wrote, and everyone starts re‑doing the same work. Pinning the cache to Redis ensures it stays the shared whiteboard.  
    - **Evidence:**  
        ```php
        // loadResolvedMap
        $cached = Cache::get($key);
        …
        Cache::put($key, $map, self::CACHE_TTL_SECONDS);

        // forget()
        Cache::forget(self::cacheKey($professionalId));

        // bumpGlobalVersion()
        Cache::add(self::GLOBAL_VERSION_KEY, 1, null);
        Cache::increment(self::GLOBAL_VERSION_KEY);
        ```  
    - `[DRAFT, confidence: 0.8]`  

- [ ] **#CACHE-3** · P2 — Cache facade used in NotificationListingService without explicit Redis store; busting a local file cache leaves other workers stale  
    - **Where:** app/Services/Notifications/NotificationListingService.php:136‑139  
    - **Affects:** The notification‑index cache for the dashboard bell. After a user marks a notification as read, the associated cache keys are deleted — but if the store is `file`, only the local worker’s copy disappears; other workers still serve the old unread count.  
    - **Effort:** S (~0.5 h)  
    - **What to do:**  
        - Add `->store('redis')` to each `Cache::forget` call inside `bustIndexCache`.  
        - Optionally wire `CacheLockService`’s Redis store so the same pinning applies across the whole service.  
    - **Technical:** `bustIndexCache` iterates the small, known set of (limit, dismissed) keys and calls `Cache::forget($key)`. Without a Redis‑specific store, a `file` driver would only remove the local filesystem copy; other Horizon workers or web servers would continue to serve cached (and now stale) notification lists. This can make the “mark as read” action appear ineffective until the natural TTL expires.  
    - **Plain English:** When you mark a notification as read, the app needs to update the cache so the dashboard shows the new count. If the cache is kept on individual notepads instead of the shared whiteboard, other parts of the app still see the old number. Pinning to Redis ensures the dashboards all stay in sync.  
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
    - `[DRAFT, confidence: 0.8]`

<!-- ═══ LENS: database-and-queue-scaling ═══ -->

- [ ] **#SCALE-1** · P1 — SendStaffBroadcastEmailsJob lands on default queue instead of a domain queue
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:41-44
    - **Affects:** Queue health under load — a staff broadcast to a large subscriber list competes with all default-queue work. At 40K daily notifications, one broadcast can starve other default-queue jobs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->onQueue('mail');` in the constructor matching the sub-batch dispatch queue.
        - Verify the dispatch site isn't overriding with `->onQueue()` (defense-in-depth — the job should declare its own home).
    - **Technical:** Every other notification/broadcast job in this codebase declares its queue in the constructor (`FanOutBrandStatusNotificationJob`, `InviteExpirySweepJob`, `NudgeStuckOnboardingJob`, `SendWeeklyAnalyticsNotificationJob`, `SendTransactionalNotificationEmailJob`). `SendStaffBroadcastEmailsJob` is the outlier. Without `$this->onQueue()`, the Horizon supervisor for `default` processes this fan-out job alongside unrelated work. The sub-batches route to `mail` via `Bus::batch(...)->onQueue('mail')`, but the parent job itself sits on `default` for its entire chunked walk of the subscriber table. At a subscriber count in the tens of thousands, that walk holds a `default` worker for up to 120s, back-pressuring every other unclassified job in the system.
    - **Plain English:** Think of this as a food-delivery dispatch center. Every other delivery driver knows which zone they serve — notifications, email, analytics — and reports to that zone's parking lot. This one driver shows up at the "general" parking lot, even though they're only delivering email. During a big broadcast, they tie up a general-purpose slot that could be handling time-sensitive tasks from other departments. The fix is to send them to the email parking lot, where they belong.
    - **Evidence:**
        ```php
        public function __construct(
            public string $notificationId,
            public string $listKey = 'sidest_updates'
        ) {}
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SCALE-2** · P2 — DataExportPayloadBuilder loads unbounded row sets into memory across 6+ tables, risking OOM for mature professionals
    - **Where:** app/Services/Professional/DataExport/DataExportPayloadBuilder.php:101-103, 112-115, 161-168, 172-180
    - **Affects:** GDPR data exports (Article 15 right of access). A single large-brand export at the scale target (5K+ customers, 10K+ orders, 5K+ subscribers) can exhaust PHP memory and fail silently.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `->get()` with `->chunk(500)` or `LazyCollection` and stream sections to the zip writer one at a time instead of building a monolithic `$payload` array.
        - In `DataExportZipWriter`, stream JSON sections incrementally (e.g. write `data/` folder with one JSON file per section) rather than `json_encode`-ing the whole payload at once.
    - **Technical:** The `build()` method calls `->get()` on `core.customers`, `notifications.email_subscriptions`, `analytics.booking_events`, `analytics.lead_submissions`, `commerce.commission_movements`, `commerce.commission_payouts`, and `core.data_export_audit` — all scoped to one `professional_id`. Each `->get()` materialises every matching row into a PHP array. For a professional with 5K customers, each row as an array with 10+ fields is ~2KB; 5K rows = ~10MB. Add bookings (2K rows × ~1KB = 2MB), commission movements (5K rows × ~1KB = 5MB), payouts (1K rows), lead submissions (500 rows), and email subscriptions (5K rows). Total: ~25MB of raw array data. `json_encode()` in `DataExportZipWriter` then allocates a second copy as a string (~30-40MB). Peak memory for one export can hit 70MB, which exceeds a conservative 128MB `memory_limit`. GDPR exports must never fail — a single OOM blocks the user's legal right.
    - **Plain English:** Imagine you ask a librarian to compile every book you've ever checked out. A good librarian flips through the catalog page by page, photocopying as they go. This librarian instead pulls every single book off the shelf at once and stacks them on one tiny desk. For someone who checked out 50 books, the desk holds fine. For someone with 5,000 books, the desk collapses and nothing gets delivered. The fix: photocopy one shelf at a time.
    - **Evidence:**
        ```php
        // 6+ unbounded get() calls in one build() invocation
        private function customers(string $professionalId): array
        {
            return DB::connection('pgsql')
                ->table('core.customers')
                ->where('professional_id', $professionalId)
                ->get()
                ->map(fn ($r) => (array) $r)
                ->all();
        }
        ```
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
        ```php
        private function bookings(string $professionalId): array
        {
            $events = DB::connection('pgsql')
                ->table('analytics.booking_events')
                ->select([...])
                ->where('professional_id', $professionalId)
                ->get()
                ->map(fn ($r) => (array) $r)
                ->all();
            $leads = DB::connection('pgsql')
                ->table('analytics.lead_submissions')
                ->where('professional_id', $professionalId)
                ->get()
                ->map(fn ($r) => (array) $r)
                ->all();
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.85]`

<!-- ═══ LENS: schema-rls ═══ -->

- [ ] **#SCHEMA-1** · P0 — `notification_email_policies` table lives in two different schemas across the codebase — model vs raw queries disagree
    - **Where:** app/Models/Core/Notifications/NotificationEmailPolicy.php:17 vs app/Http/Controllers/Api/Professional/Notifications/NotificationEmailPreferenceController.php:44-46
    - **Affects:** Staff policy management UI, per-professional email preference resolution, data export GDPR payload. One of the two schemas silently returns zero rows.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Determine which schema is authoritative (`core` or `notifications`) by inspecting the actual migration that created the table.
        - Align the model `$table` property with the raw queries — change one side to match the other.
        - Add an integration test that writes a policy via the model and reads it back via `NotificationPublisher::computeResolvedMap` to lock the schema choice.
    - **Technical:** The `NotificationEmailPolicy` model sets `$table = 'notifications.notification_email_policies'`. Three separate files — `NotificationEmailPreferenceController`, `NotificationPublisher::computeResolvedMap`, and `DataExportPayloadBuilder::notificationPreferences` — all query `core.notification_email_policies` via `DB::table()`. Laravel's `DB::table('core.notification_email_policies')` resolves correctly because Postgres treats the dot as schema qualification, but Eloquent queries through the model use `notifications.notification_email_policies`. One side is querying an empty or non-existent table. Category (2) — `search_path` / multi-schema correctness.
    - **Plain English:** Imagine a filing cabinet where the label on the drawer says "notifications" but everyone who actually pulls files goes to the "core" drawer instead. The drawer labeled "notifications" is either empty or doesn't exist — and anyone who trusts the label (the model) sees nothing. The fix is to agree on one drawer and use it everywhere.
    - **Evidence:**
        ```php
        // Model says:
        protected $table = 'notifications.notification_email_policies';
        ```
        ```php
        // But three raw queries all say:
        $perProPolicies = DB::table('core.notification_email_policies')
            ->where('professional_id', $pro->id)
            ->get(['category_key', 'mode']);
        ```
        ```php
        $perProPolicies = DB::table('core.notification_email_policies')
            ->where('professional_id', $professionalId)
            ->pluck('mode', 'category_key')
            ->all();
        ```
        ```php
        $policies = DB::connection('pgsql')
            ->table('core.notification_email_policies')
            ->where('professional_id', $professionalId)
            ->get()
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SCHEMA-2** · P0 — `NotificationPublisher::publish()` dedup depends on a `UNIQUE (professional_id, dedupe_key)` constraint — if missing, every call inserts a duplicate notification
    - **Where:** app/Services/Notifications/NotificationPublisher.php (publish method, insertOrIgnore call)
    - **Affects:** Every notification sent through the system — booking completions, brand status changes, onboarding nudges, invite expiries, weekly analytics. All would fire repeatedly on retry/replay.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inspect the `notifications.notifications` table DDL in supabase/migrations to confirm a `UNIQUE (professional_id, dedupe_key)` constraint exists.
        - If missing, add it via migration using `CREATE UNIQUE INDEX CONCURRENTLY` (table is hot at scale — notifications fire on every booking).
    - **Technical:** The code comment says "Atomic upsert: ON CONFLICT on (professional_id, dedupe_key) DO NOTHING" but the implementation uses Laravel's `insertOrIgnore()`, which generates `INSERT ... ON CONFLICT DO NOTHING` without specifying a conflict target. Postgres then uses *all* unique constraints to detect conflicts. Since `id` is a fresh UUID every call, the only constraint that can trigger the DO NOTHING is `UNIQUE (professional_id, dedupe_key)`. If that constraint doesn't exist, every INSERT succeeds and the dedup is silently bypassed. The same pattern applies to `publishMany()` which uses the same `insertOrIgnore`. Category (3) — constraint coverage; this is the schema-side counterpart of the idempotency-key pattern from `lifecycle-correctness.md`.
    - **Plain English:** The notification system has a "do not send the same alert twice" safety catch. But the catch only works if a specific database rule exists — a rule that says "one alert per person per event." Nobody has verified whether that rule was actually installed. If it's missing, every time a booking event gets replayed (webhook retry, queue restart), the professional gets duplicate "New booking!" notifications.
    - **Evidence:**
        ```php
        // Atomic upsert: ON CONFLICT on (professional_id, dedupe_key) DO NOTHING.
        // If a notification with this dedupe_key already exists for this pro,
        // this is a no-op — no duplicate row, no race window.
        $inserted = DB::table('notifications.notifications')->insertOrIgnore([
            'id' => $notificationId,
            'professional_id' => $professionalId,
            // ...
            'dedupe_key' => $dedupeKey,
            // ...
        ]);
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#SCHEMA-3** · P1 — `broadcast_email_receipts` table may lack a `UNIQUE (notification_id, subscription_id)` constraint — at-most-once delivery promise is unenforced at DB level
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php:60-63
    - **Affects:** Staff broadcast email recipients — a subscriber could receive duplicate broadcast emails if the job retries after a crash between the receipt insert and the mail send.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Verify that `notifications.broadcast_email_receipts` has a `UNIQUE (notification_id, subscription_id)` constraint.
        - If missing, add it via `CREATE UNIQUE INDEX CONCURRENTLY`.
    - **Technical:** The job calls `DB::table('notifications.broadcast_email_receipts')->insertOrIgnore([...])` to claim a send slot before dispatching the email. `insertOrIgnore` generates `ON CONFLICT DO NOTHING` without specifying conflict columns, relying on any unique constraint to detect duplicates. Without a composite unique on the pair, every retry of the job inserts a new receipt row and sends another email — breaking the at-most-once delivery guarantee the code comment describes. Category (3) — constraint coverage; same `insertOrIgnore`-without-conflict-target anti-pattern as SCHEMA-2.
    - **Plain English:** The broadcast email system has a "claim ticket" system to make sure each subscriber gets exactly one copy. The job takes a ticket before sending. But if the ticket machine doesn't actually prevent two people from grabbing the same ticket, a retry after a crash would grab a second ticket and send a second email. The subscriber sees the same broadcast twice.
    - **Evidence:**
        ```php
        // Claim the send slot before touching the mailer — at-most-once delivery.
        $inserted = DB::table('notifications.broadcast_email_receipts')->insertOrIgnore([
            'notification_id' => $this->notificationId,
            'subscription_id' => $this->subscriptionId,
        ]);

        if ($inserted === 0) {
            return; // already delivered on a previous attempt
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SCHEMA-4** · P1 — Unqualified `Schema::hasColumn('email_subscriptions', ...)` can false-positive against the wrong schema on Partna's multi-schema `search_path`
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php (emailLcColumnExists method)
    - **Affects:** Public newsletter signup flow — the `email_lc` column existence check may return true from a different schema's table, causing the controller to write to a column that doesn't exist in `notifications.email_subscriptions`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Schema::hasColumn('email_subscriptions', 'email_lc')` with `Schema::hasColumn('notifications.email_subscriptions', 'email_lc')`.
        - Remove the `|| Schema::hasColumn('core.email_subscriptions', 'email_lc')` fallback — the model confirms the table is in `notifications`.
    - **Technical:** Partna's `search_path` includes `public`, `core`, `site`, `brand`, `commerce`, `notifications`, `analytics`, `billing`. `Schema::hasColumn('email_subscriptions', ...)` resolves against the first schema in the `search_path` that has a table named `email_subscriptions`. If a table with that name exists in `public` or `core` (unlikely but possible via future migration), the check returns true for the wrong table. The model `EmailSubscription` declares `$table = 'notifications.email_subscriptions'` — the column check must target the same schema. Category (2) — `search_path` correctness.
    - **Plain English:** The signup form asks "does this database table have an email_lc column?" but it asks the question without specifying which filing cabinet to look in. The building has eight filing cabinets on the search path, and if a different cabinet has a table with the same name, the answer is "yes" for the wrong one. The form then tries to write to a column that doesn't exist in the right cabinet.
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
    - `[DRAFT, confidence: 0.90]`

- [ ] **#SCHEMA-5** · P2 — `LOWER(email)` WHERE clauses on `core.customers` lack a functional index — full table scan on every contact capture, GDPR redact, and export
    - **Where:** app/Services/Customers/ContactCaptureService.php (captureContact method), app/Jobs/Shopify/Gdpr/RedactCustomerJob.php (handle method), app/Jobs/Shopify/Gdpr/ExportCustomerDataJob.php (gatherExportData method)
    - **Affects:** Contact capture latency (Shopify order webhooks, Square bookings, site leads), GDPR redact throughput, customer data export speed. Every one of these code paths runs a `LOWER(email)` scan.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a functional index `CREATE INDEX CONCURRENTLY ON core.customers (professional_id, LOWER(email))` — or adopt the `email_lc` denormalized-column pattern already used on `notifications.email_subscriptions`.
        - Replace `whereRaw('lower(email) = ?', [$email])` with a lookup on the indexed expression or the denormalized column.
    - **Technical:** Multiple critical paths query `core.customers` with `WHERE professional_id = ? AND LOWER(email) = ?`. Without a functional index on `(professional_id, LOWER(email))`, Postgres performs a sequential scan over all customers for that professional. A mature shop with tens of thousands of customers experiences growing latency on every webhook. The `notifications.email_subscriptions` table already solves this with a denormalized `email_lc` column and a `UNIQUE (professional_id, list_key, email_lc)` constraint — `core.customers` should follow the same pattern. Category (4) — index hygiene.
    - **Plain English:** Every time a customer books, buys, or submits a lead form, the system checks "do we already know this email address?" by scanning the entire customer list for that business alphabetically. For a business with 50,000 customers, that scan runs on every single booking. The mailing-list table already solved this by keeping a pre-computed lowercase copy of the email — the customer table should do the same.
    - **Evidence:**
        ```php
        // ContactCaptureService — runs on every Shopify/Square/lead webhook
        $existing = Customer::query()
            ->withTrashed()
            ->where('professional_id', $professionalId)
            ->whereRaw('lower(email) = ?', [$email])
            ->first();
        ```
        ```php
        // RedactCustomerJob — GDPR redact path
        $customer = Customer::query()
            ->withTrashed()
            ->where('professional_id', $professionalId)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->whereNull('redacted_at')
            ->first();
        ```
        ```php
        // ExportCustomerDataJob — Shopify data request path
        $customers = Customer::query()
            ->withTrashed()
            ->where('professional_id', $professionalId)
            ->whereRaw('LOWER(email) = ?', [$emailLc])
            ->get()
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SCHEMA-6** · P2 — `notifications.notifications.type` column has no CHECK constraint despite app code defining a fixed set of valid types
    - **Where:** app/Models/Core/Notifications/Notification.php:23-28 (FRONTEND_TYPES constant) vs the underlying `notifications.notifications` table
    - **Affects:** Data integrity — a buggy or direct INSERT can store arbitrary type strings, breaking the frontend's type-to-icon mapping and the `normalizeFrontendType()` normalization.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `CHECK` constraint on `notifications.notifications.type`: `CHECK (type IN ('Success', 'Critical', 'Warning', 'Invitation', 'To do', 'Info'))`.
        - Use `ALTER TABLE ... ADD CONSTRAINT ... NOT VALID` followed by `VALIDATE CONSTRAINT` to avoid a full-table scan blocking writes.
    - **Technical:** The `Notification` model defines `FRONTEND_TYPES` and `normalizeFrontendType()` maps arbitrary input to one of six canonical values. However, the database column is unconstrained — a raw INSERT or a future code path that bypasses normalization can store any string. The canonical Partna pattern is the CHECK constraint added in `202605190000002_add_enum_check_constraints.sql` for `orders.rate_source`; `Notification.type` and `Notification.severity` should follow the same pattern. Category (3) — constraint coverage.
    - **Plain English:** The notification system has six official alert types — Success, Critical, Warning, Invitation, To do, and Info. The app's code knows how to normalize anything into one of these six. But the database itself doesn't enforce this rule — it'll happily store "banana" as a type if something writes directly to the table. A CHECK constraint is like a bouncer at the database door that rejects anything not on the guest list.
    - **Evidence:**
        ```php
        public const FRONTEND_TYPES = [
            'Success',
            'Critical',
            'Warning',
            'Invitation',
            'To do',
            'Info',
        ];
        ```
        (No corresponding CHECK constraint quoted — the absence is the finding.)
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SCHEMA-7** · P2 — `notification_email_policies.mode` column has no CHECK constraint — values like `'force_on'` and `'force_off'` are enforced only in app logic
    - **Where:** app/Models/Core/Notifications/NotificationEmailPolicy.php vs the underlying table (in whichever schema it actually lives — see SCHEMA-1)
    - **Affects:** Email delivery correctness — a mistyped mode value ('force_on' vs 'force_on') silently falls through to the default branch in the resolution chain, potentially disabling email for a category that should be forced on.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CHECK (mode IN ('force_on', 'force_off'))` to the `notification_email_policies` table.
        - Use `NOT VALID` + `VALIDATE CONSTRAINT` pattern for the migration.
    - **Technical:** The `NotificationEmailPreferenceController` and `NotificationPublisher` both resolve policies by matching `$perProMode === 'force_on'` and `'force_off'`. A typo in a direct DB insert (or future staff UI that sends a misspelled value) would fall through all match branches and treat the policy as if it doesn't exist — defaulting to `true`. A CHECK constraint catches this at write time. Category (3) — constraint coverage.
    - **Plain English:** Staff policies have two modes: "force on" and "force off." If someone types "force_on" (missing the underscore) directly into the database, it's treated as if the policy doesn't exist at all — which means the email defaults to "on." A database rule that rejects anything that isn't exactly "force_on" or "force_off" prevents typos from silently changing behavior.
    - **Evidence:**
        ```php
        // Resolution chain — mistyped modes fall through all branches
        if ($perProMode === 'force_on') {
            $effective = true;
        } elseif ($perProMode === 'force_off') {
            $effective = false;
        } elseif ($globalMode === 'force_on') {
            $effective = true;
        } elseif ($globalMode === 'force_off') {
            $effective = false;
        }
        ```
        (No corresponding CHECK constraint quoted — the absence is the finding.)
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SCHEMA-8** · P2 — `core.customers` may lack a `UNIQUE (professional_id, LOWER(email))` constraint — duplicate customers possible under concurrent contact capture
    - **Where:** app/Services/Customers/ContactCaptureService.php (captureContact method) — SELECT-then-INSERT pattern without 23505 catch on the INSERT path
    - **Affects:** Affiliate customer lists — two concurrent Shopify webhooks for the same new customer email can create duplicate `core.customers` rows with different UUIDs, downstream breakage in booking attribution, marketing list dedup, and GDPR redact completeness.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Verify whether `core.customers` has a `UNIQUE (professional_id, LOWER(email))` constraint.
        - If not, add one — adopt the `email_lc` denormalized pattern (`CREATE UNIQUE INDEX CONCURRENTLY ON core.customers (professional_id, email_lc)`) with a backfill migration, or create a functional unique index `ON core.customers (professional_id, LOWER(email))`.
        - Add 23505 handling in `captureContact`'s INSERT path (application-side race safety — sibling to `lifecycle-correctness.md`).
    - **Technical:** The `ContactCaptureService::captureContact()` method does SELECT → (UPDATE existing OR INSERT new). Two concurrent calls for the same (professional_id, email) both see `$existing = null` and both INSERT. Without a DB-side unique constraint, both succeed and the affiliate gets duplicate customer rows. The `email_subscriptions` equivalent (`captureMarketingSubscription`) explicitly handles 23505 with `reconcileRacedSubscription()` — `captureContact` has no equivalent catch. Category (3) — constraint coverage; sibling to `lifecycle-correctness.md` (1).
    - **Plain English:** When two Shopify orders for the same new customer arrive at the exact same moment, both webhooks check "does this customer exist?" and both get "no." Both then create a new customer record. Without a database rule that says "one customer per email address," you end up with two copies of the same person — and their booking history and marketing preferences get split across both copies.
    - **Evidence:**
        ```php
        $existing = Customer::query()
            ->withTrashed()
            ->where('professional_id', $professionalId)
            ->whereRaw('lower(email) = ?', [$email])
            ->first();

        if ($existing) {
            // update...
            return $existing;
        }

        // No 23505 catch here — if a race partner already inserted,
        // this throws an unhandled exception (caught by outer Throwable handler)
        return $this->createCustomerRow($professionalId, $fullName, $email, ...);
        ```
    - `[DRAFT, confidence: 0.80]`

<!-- ═══ LENS: migration-safety ═══ -->

I cannot perform a meaningful migration-safety audit on `supabase/migrations/*.sql` files because **no migration SQL files were provided**. The "Files Under Audit" section contains only PHP application code (controllers, models, services, jobs, policies, routes). The lens requires quoting verbatim DDL/DML from migration files — category (1) `CREATE INDEX`, category (2) `ADD CONSTRAINT`, category (3) backfill `UPDATE` statements, etc. None of that exists in the provided PHP files.

That said, the PHP code does surface **one pattern that reveals a migration-deployment gap** worth flagging:

- [ ] **MIG-1** · P2 — Runtime `Schema::hasColumn()` check suggests `email_lc` migration was never hardened for guaranteed apply
    - **Where:** `app/Http/Controllers/Api/PublicSite/PublicEmailSubscriptionController.php:283-299`
    - **Affects:** Every public subscribe request (`POST /public/subscribe`) — the `Schema::hasColumn` call runs on every request until the static cache warms per process.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Locate the migration that added `email_lc` to `notifications.email_subscriptions` (likely `202605190000002_add_enum_check_constraints.sql` or similar).
        - Remove the `emailLcColumnExists()` guard and the conditional `$subscription->email_lc = $email` assignment — assume the column is present in all environments.
        - If the column truly doesn't exist in production, backfill it in a dedicated migration with proper online-DDL hygiene before removing the guard.
    - **Technical:** The guard pattern (`static $cached = null; Schema::hasColumn(...)`) exists because the codebase doesn't trust that `email_lc` is present everywhere. This usually means the migration was applied out-of-order on some environment, or the column was added without a `supabase db push` verification step. In either case the guard is a silently-deployed partial migration — the exact class of risk this lens hunts. The guard itself isn't lock-risky, but it signals that the underlying migration (which I cannot quote because the file wasn't provided) may have issues like missing `IF NOT EXISTS`, a volatile default on a hot table, or a cross-schema `ADD COLUMN` without schema qualification.
    - **Plain English:** There's a piece of code that checks "does this database column exist?" every time someone subscribes to a newsletter, and only writes data to it if the answer is yes. It's like having a mailbox that you only use if you remember to check whether it's been installed. This means some environments have the column and some don't — the migration that was supposed to add it everywhere probably got applied inconsistently, and the guard is the band-aid. Remove the band-aid by making sure the column exists everywhere, then delete the check.
    - **Evidence:**
        ```php
        // PublicEmailSubscriptionController.php:283-299
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
        ```php
        // Called at PublicEmailSubscriptionController.php:145
        if ($this->emailLcColumnExists()) {
            $subscription->email_lc = $email;
        }
        ```
    - `[DRAFT, confidence: 0.7]`

---

**To complete this audit**, re-scope with the actual migration files. The canonical command:

```
--scope supabase/migrations
```

I need to see every `.sql` file in that directory to hunt for: `CREATE INDEX` without `CONCURRENTLY`, `ADD CONSTRAINT` without `NOT VALID`, backfill `UPDATE` statements without `WHERE` guards, `DROP COLUMN` without rollback comments, `ALTER COLUMN TYPE`, and `SET NOT NULL` on populated columns. The PHP code alone cannot answer any category (1)–(8) question.

<!-- ═══ LENS: observability ═══ -->

- [ ] **#OBS-1** · P1 — `NotificationPublisher::publish()` drops notifications silently when required fields are empty
    - **Where:** app/Services/Notifications/NotificationPublisher.php:89-105
    - **Affects:** Every caller of `publish()` — booking notifications, brand status changes, invite expiries, weekly analytics, onboarding nudges. If a bug in calling code produces an empty `$professionalId`, `$title`, `$body`, or `$dedupeKey`, the notification vanishes with zero paper trail.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Log::warning(...)` calls at each early-return site with the method arguments as context so the dropped notification is searchable.
        - Consider adding a `report()` call at the `$dedupeKey === ''` guard — an empty dedupe key is always a caller bug and should trigger an alert.
    - **Technical:** Three silent `return` branches (empty professionalId, empty title/body, empty dedupeKey) run before `insertOrIgnore` ever executes. No exception, no log, no Nightwatch event, no Horizon failure. If a payout notification is assembled by a future service and the dedupe key string interpolates to empty, the professional never learns about their money and ops has no signal. The `publish()` method is the single chokepoint for the entire in-app notification pipeline — guarding it with at-least-a-log is a high-leverage change.
    - **Plain English:** The system's notification delivery has three silent trapdoors at the front door. If a developer accidentally passes an empty customer name or blank title, the notification is thrown away with no record anywhere — not in logs, not in error trackers, not in the database. It's like a postal worker who quietly bins any letter without a return address instead of stamping it "return to sender."
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
    - `[DRAFT, confidence: 0.9]`

- [ ] **#OBS-2** · P1 — `NotificationPublisher::publishMany()` drops bulk notifications silently when all items are invalid
    - **Where:** app/Services/Notifications/NotificationPublisher.php:168-175 (foreach skip) and :186-188 (empty rows return)
    - **Affects:** Fan-out callers that use `publishMany()` for bulk notification delivery (brand-affiliate invite batches, staff broadcasts). A bug that produces all-empty fields results in zero notifications with zero evidence.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log a warning when an individual item is skipped inside the foreach loop (include the item index and reason).
        - Log a warning when `$rows === []` after filtering — the caller sent `$items` but nothing was publishable.
    - **Technical:** The foreach loop silently `continue`s past items with empty professionalId, title, body, or dedupeKey. If every item in the batch is invalid, `$rows` stays empty and the method returns at the `if ($rows === [])` guard. No log, no Nightwatch trace. Contrast with `publish()` which at least has the single-caller visibility — `publishMany()` callers are orchestrating bulk dispatches and have no per-item feedback loop. A warning log with the count of skipped items would close the observability gap.
    - **Plain English:** Same trapdoor as the single-publish method, but multiplied. If someone queues up 200 brand notifications and a bug makes every single one invalid, the system silently does nothing. The operator who clicked "send" sees a success message, but zero emails went out. A simple log saying "200 items provided, 0 published" would catch this immediately.
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
    - `[DRAFT, confidence: 0.85]`

- [ ] **#OBS-3** · P2 — `CommerceNotificationService::notifyBookingCompleted()` swallows all exceptions, hiding notification failures from callers
    - **Where:** app/Services/Notifications/CommerceNotificationService.php:104-108
    - **Affects:** Booking completion flow — if the notification publish or milestone check fails, the booking webhook/controller that called this method continues as if everything worked. Nightwatch sees the exception (via `report()`), but the business operation succeeds without the notification being delivered.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Evaluate whether booking notifications are critical enough to let the exception propagate (causing a 500 to the webhook caller, which triggers a retry).
        - If the current "best-effort" semantics are correct, add structured context to the `Log::warning` call — include the `$eventKey`, `$bookingId`, and `$serviceName` so ops can correlate the dropped notification to a specific booking.
    - **Technical:** The catch-all `catch (\Throwable $e)` calls `report($e)` (visible in Nightwatch) and logs a warning, but the method returns void and the caller proceeds normally. The booking event itself is already persisted — only the notification is lost. Nightwatch shows the exception, but there's no automated way to know "a booking happened but the professional wasn't told." The current `Log::warning` context only has `professional_id` and `message` — adding `event_key` and `booking_id` would let ops trace which specific booking lost its notification.
    - **Plain English:** When a booking notification fails, Nightwatch gets a copy of the error, but the rest of the system acts like everything's fine. The professional never sees the "new booking" notification. It's like a cash register that still prints the receipt but silently fails to ring the customer-facing bell — the sale went through, but the customer doesn't know.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Booking notifications failed', [
                'professional_id' => $context['professional_id'] ?? null,
                'message' => $e->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#OBS-4** · P2 — `SendStaffBroadcastEmailsJob::handle()` returns silently when the notification is not found
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:43-46
    - **Affects:** Staff broadcast email fan-outs. If the notification is deleted between when the job is dispatched and when it runs, the job silently does nothing — no log, no Horizon failure, no Nightwatch event. The staff member who sent the broadcast never knows it didn't fan out.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `Log::warning(...)` call mirroring the pattern used in `SendEnquiryNotificationJob` and `FanOutBrandStatusNotificationJob` — include `notification_id` and `list_key` as context.
    - **Technical:** Every other notification job in the codebase (`SendEnquiryNotificationJob`, `FanOutBrandStatusNotificationJob`, `ExportCustomerDataJob`, `RedactCustomerJob`) logs a warning when its target entity is not found. `SendStaffBroadcastEmailsJob` is the outlier — it returns silently. A race condition (notification deleted via API while the job is queued) would produce a green Horizon dashboard but a broadcast that never went out to any subscriber. Nightwatch would have zero record.
    - **Plain English:** If a staff member schedules a broadcast email and then deletes the notification before the send starts, the job quietly does nothing. No error, no log, no "this broadcast was never sent" alert. Every other similar job in the system leaves a note when this happens — this one doesn't.
    - **Evidence:**
        ```php
        $notification = Notification::query()->find($this->notificationId);
        if (! $notification) {
            return;
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#OBS-5** · P2 — `SendStaffBroadcastEmailToSubscriberJob::handle()` returns silently when notification or subscription is not found
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php:53-60
    - **Affects:** Individual subscriber deliveries within a staff broadcast batch. If either the notification or the subscription row is deleted between batch dispatch and job execution, the job silently returns — no log, no trace. A batch of 200 jobs could have several silently no-op without the batch owner knowing.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Log::warning(...)` at both early-return sites, including `notification_id` and `subscription_id`.
    - **Technical:** Same pattern as OBS-4, but in the leaf job. Because this job runs inside a `Bus::batch()` with `allowFailures()`, a silent return is indistinguishable from a successful send — the batch shows "completed" with zero failures. A warning log would let ops search for `subscription_id` and distinguish "subscriber unsubscribed between dispatch and run" from "batch dispatched but subscription row went missing." The `failed()` method on this job only fires on exceptions — a silent return never reaches it.
    - **Plain English:** Each subscriber in a broadcast gets their own mini-job. If that job can't find the subscriber's record, it just stops with no note. From the dashboard, the batch looks perfect — all succeeded. But some emails were never sent, and there's no way to know which ones.
    - **Evidence:**
        ```php
        $notification = Notification::query()->find($this->notificationId);
        if (! $notification) {
            return;
        }

        $sub = EmailSubscription::query()->find($this->subscriptionId);
        if (! $sub) {
            return;
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#OBS-6** · P3 — `SendStaffBroadcastEmailsJob` does not declare a queue, running on `default` instead of a named queue
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:28-34 (constructor)
    - **Affects:** Queue priority and starvation risk — the job's chunkById loop over potentially thousands of subscribers runs on the `default` queue, competing with every other unassigned job. The batches it dispatches correctly go to `mail`, but the coordinator job itself is undifferentiated.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->onQueue('notifications');` in the constructor to match the pattern used by `FanOutBrandStatusNotificationJob`, `InviteExpirySweepJob`, and `NudgeStuckOnboardingJob` — all fan-out coordinator jobs in the codebase.
    - **Technical:** Every other fan-out coordinator job in the provided files explicitly assigns itself to the `notifications` queue in its constructor. `SendStaffBroadcastEmailsJob` is the outlier. On the `default` queue, it can be starved by lower-priority work or, conversely, its chunkById loop can delay time-sensitive default-queue jobs. In practice the risk is low (the coordinator only does lightweight chunking), but the inconsistency with the rest of the codebase means a future operator tuning queue weights might miss this job.
    - **Plain English:** All the other "fan-out" coordinator jobs have a label telling the queue system what kind of work they are. This one doesn't — it gets lumped into the "everything else" bucket. It'll probably work fine, but if the system gets busy and someone needs to prioritize notification sends, this job won't be grouped with its siblings.
    - **Evidence:**
        ```php
        public function __construct(
            public string $notificationId,
            public string $listKey = 'sidest_updates'
        ) {}
        // Compare with FanOutBrandStatusNotificationJob:
        // public function __construct(...) {
        //     $this->onQueue('notifications');
        // }
        ```
    - `[DRAFT, confidence: 0.85]`

<!-- ═══ LENS: data-integrity ═══ -->

- [ ] **DINT-1** · P1 — Professional data export leaks unsubscription tokens and subscriber technical metadata
    - **Where:** app/Services/Professional/DataExport/DataExportPayloadBuilder.php (emailSubscriptions method, around line 150)
    - **Affects:** Any professional who exports their account data via self-service; every subscriber on their marketing lists (token compromise, IP/browser fingerprint exposure).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Restrict the `notifications.email_subscriptions` query in `DataExportPayloadBuilder::emailSubscriptions` to only select columns the professional already sees via the normal API: `id`, `professional_id`, `list_key`, `email`, `full_name`, `status`, `subscribed_at`, `unsubscribed_at`.
        - Alternatively, replace the raw DB query with a model call that respects `EmailSubscription::$hidden`, and ensure `unsubscribe_token`, `consent_ip_hash`, and `consent_user_agent` are excluded.
    - **Technical:** The `DataExportPayloadBuilder` uses `DB::table(...)->get()->map(fn ($r) => (array) $r)` which bypasses Laravel model serialization and includes every column. `EmailSubscription::$hidden` lists `unsubscribe_token`, `consent_ip_hash`, and `consent_user_agent` as hidden precisely because they should never leave the server. Exporting them gives the professional the ability to unsubscribe any subscriber (by replaying the token) and exposes the subscriber's IP hash and User-Agent, which are technical fingerprints unrelated to the professional's legitimate business need.
    - **Plain English:** Imagine every customer who signs up for a newsletter gets a secret "unsubscribe link" that only they should know. If the store owner downloads a data backup, they shouldn't find a master list of everyone's secret links — that would let the owner unsubscribe people without permission, and also see behind‑the‑scenes details like which IP address each person used. The export should match what the owner already sees on their dashboard, not include hidden system secrets.
    - **Evidence:**
        ```php
        // app/Services/Professional/DataExport/DataExportPayloadBuilder.php
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
    - `[DRAFT, confidence: 1.0]`

- [ ] **DINT-2** · P1 — Global notification deduplication silently broken for professional_id NULL rows
    - **Where:** app/Services/Notifications/NotificationPublisher.php (publish method, insertOrIgnore call)
    - **Affects:** Any global (broadcast) notification fired concurrently or retried; duplicate in‑app alerts and emails can reach every professional.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the `(professional_id, dedupe_key)` unique constraint on `notifications.notifications` with a partial unique index that handles NULLs, e.g. `CREATE UNIQUE INDEX ... ON notifications.notifications (COALESCE(professional_id, '00000000-0000-0000-0000-000000000000'::uuid), dedupe_key)`.
        - Or enforce deduplication at the application level for global notifications by using a separate table or a dedicated locking mechanism.
    - **Technical:** PostgreSQL treats each NULL as a distinct value in a unique constraint, so `ON CONFLICT (professional_id, dedupe_key) DO NOTHING` will never detect a conflict when `professional_id` is NULL. Two concurrent publishes of the same global notification (`professional_id = null`) with the same `dedupe_key` will each insert a row, resulting in duplicate notifications. The `insertOrIgnore` method relies entirely on the unique constraint for deduplication; there is no application‑level guard.
    - **Plain English:** The system has a “don’t send the same message twice” safety net that works perfectly for messages aimed at one person. But for all‑user broadcasts (like “system maintenance on Tuesday”), the safety net is blind — two staff members hitting send at the same time, or a server retry, will create duplicate alerts. Everyone would get the same warning twice.
    - **Evidence:**
        ```php
        // app/Services/Notifications/NotificationPublisher.php
        $inserted = DB::table('notifications.notifications')->insertOrIgnore([
            'id' => $notificationId,
            'professional_id' => $professionalId, // can be null for broadcasts
            // …
            'dedupe_key' => $dedupeKey,
            // …
        ]);
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **DINT-3** · P2 — Notification `type` and `severity` columns lack DB CHECK constraints
    - **Where:** app/Models/Core/Notifications/Notification.php (FRONTEND_TYPES constant)
    - **Affects:** Any direct DB write (migration, console command, external integration) could insert values not handled by the frontend, causing rendering bugs or silent failures.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `CHECK` constraint to the `notifications.notifications` table for `type IN ('Success','Critical','Warning','Invitation','To do','Info')`.
        - Add a `CHECK` constraint for `severity IN ('critical','warning','info')`.
    - **Technical:** The model defines allowed values only in PHP constants and uses `Notification::normalizeFrontendType` to coerce any string, but the database schema accepts arbitrary text. This allows data to be inserted that the application cannot meaningfully display, and violates the defense‑in‑depth principle already applied to `email_subscriptions.status` (which has a CHECK). Without a constraint, a single bug in a background job can silently write garbage that persists until noticed.
    - **Plain English:** The notification system has a predefined set of message types, like different envelope colours. The database doesn’t check that the colour written is actually one of the allowed ones — it will happily accept a typo or a random string. Later, when the app tries to show that message, it could crash or display a blank icon, and nobody would know why.
    - **Evidence:**
        ```php
        // app/Models/Core/Notifications/Notification.php
        public const FRONTEND_TYPES = [
            'Success',
            'Critical',
            'Warning',
            'Invitation',
            'To do',
            'Info',
        ];
        ```
        No `@see` comment referencing a CHECK migration, unlike `EmailSubscription` which points to `202605190000002_add_enum_check_constraints.sql`.
    - `[DRAFT, confidence: 0.9]`

- [ ] **DINT-4** · P2 — `notification_email_policies.mode` lacks DB CHECK constraint
    - **Where:** app/Models/Core/Notifications/NotificationEmailPolicy.php
    - **Affects:** Staff‑issued email policy rows could contain invalid mode values, silently breaking the email‑preference resolution ladder without alerting anyone.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `CHECK` constraint to the `core.notification_email_policies` (or `notifications.notification_email_policies`) table for `mode IN ('force_on','force_off', ...)` with the full set of supported modes.
    - **Technical:** The model has no enumeration of allowed modes; values like `'force_on'` and `'force_off'` are used throughout the `NotificationPublisher` and controller logic. A stray insert of `'forc_on'` would be treated as an unrecognized mode and fall through the preference cascade, effectively being ignored — leading to a policy that appears set but has no effect. A DB‑level CHECK prevents this class of silent misconfiguration.
    - **Plain English:** Staff can set a policy like “always send emails of this type” or “never send.” The database doesn’t verify the policy value, so a typo like “forc_on” looks like it worked from the admin screen but actually does nothing. An explicit allowed‑value list in the database would catch this mistake immediately.
    - **Evidence:**
        ```php
        // app/Models/Core/Notifications/NotificationEmailPolicy.php
        protected $fillable = [
            'professional_id',
            'category_key',
            'mode',   // no constraint or enumeration documented
        ];
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **DINT-5** · P2 — No scheduled job found to purge soft‑deleted records past the 30‑day retention window
    - **Where:** app/Services, app/Jobs (absence of a soft‑delete purge job)
    - **Affects:** All tables using `SoftDeletes` (e.g., `core.professionals`, `core.customers`, `site.sites`); stale trashed rows accumulate indefinitely, violating the documented 30‑day retention policy and bloating storage / query performance.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Implement a Laravel command or scheduled `ShouldQueue` job that deletes rows where `deleted_at < now() - interval '30 days'` (configurable via `SOFT_DELETE_RETENTION_DAYS`).
        - Use `chunkById` with `forceDelete` to keep memory bounded.
        - Add audit logging (e.g., `data_export_audit` style) or at least a log entry recording how many rows were purged per model.
        - Schedule the command daily via `routes/console.php`.
    - **Technical:** The CLAUDE.md defines a 30‑day soft‑delete retention policy, but the provided job set includes only notification‑specific sweepers (`InviteExpirySweepJob`, `NudgeStuckOnboardingJob`, etc.). No generic garbage collector exists. Without it, soft‑deleted rows accumulate forever; accounts that delete and recreate resources (e.g., sites, customers) may eventually hit unique‑constraint issues if `deleted_at` is not included in the unique index, and storage costs grow unnecessarily.
    - **Plain English:** We promise that when you delete something, it’s kept for 30 days just in case you need it back, then permanently removed. But we don’t have a janitor scheduled to actually take out the trash. Deleted items would pile up forever, taking up storage and eventually slowing the system down, like a recycling bin that’s never emptied.
    - **Evidence:**
        No evidence of a scheduled purge job in the provided service and job files; the only “sweep” jobs handle invitations and onboarding nudges.
    - `[DRAFT, confidence: 0.85]`

<!-- ═══ LENS: api-contract ═══ -->

- [ ] **#API-1** · P2 — Notification listing returns raw stdClass rows, bypassing API Resource layer entirely
    - **Where:** app/Services/Notifications/NotificationListingService.php:103-126 (buildIndexPayload)
    - **Affects:** All callers of `GET /me/notifications` (Professional dashboard bell) and the Staff-on-behalf-of endpoint — both receive raw database column names directly in JSON responses.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a `NotificationResource` class in `app/Http/Resources/` that explicitly lists allowed fields.
        - Wrap every row in `buildIndexPayload` through `NotificationResource::make($row)` before returning.
        - If Staff and Professional surfaces should show different fields, create `StaffNotificationResource` vs `ProfessionalNotificationResource` and route which one the service uses.
    - **Technical:** The `buildIndexPayload` method selects columns via `DB::table('notifications.notifications as n')->leftJoin(...)->get([...])` and returns the raw collection via `$rows->values()->all()`. No Eloquent model serialisation, no `$hidden`/`$appends` protection, no Resource class gate. If a developer adds a column like `admin_notes` to the select list, it immediately appears in the Professional API. The Partna architecture mandates that all API responses go through Resource classes — this service bypasses that contract entirely. The Notification model IS an Eloquent model (`app/Models/Core/Notifications/Notification.php`), so there's a model to anchor a Resource class to; the service just doesn't use it.
    - **Plain English:** Think of it like a restaurant kitchen that sends dishes out through the pass (Resource classes) — every plate gets checked before it reaches the customer. The notification list bypasses the pass entirely, handing raw ingredients straight to the table. If a chef later adds a new ingredient to the prep list (a new database column), it lands on the customer's plate with no one checking whether it belongs there. The fix is to route this through the same pass every other dish uses.
    - **Evidence:**
        ```php
        // NotificationListingService.php: buildIndexPayload()
        $rows = $listQuery
            ->orderByDesc('n.created_at')
            ->limit($limit + 1)
            ->get([
                'n.id',
                'n.professional_id',
                'n.type',
                'n.title',
                'n.body',
                'n.cta_url',
                'n.primary_action_label',
                'n.secondary_action_label',
                'n.secondary_action_url',
                'n.severity',
                'n.starts_at',
                'n.ends_at',
                'n.created_at',
                'r.read_at',
                'r.dismissed_at',
            ]);

        // ... map + slice ...

        return [
            'unread_count' => $unreadCount,
            'has_more' => $hasMore,
            'notifications' => $rows->values()->all(),  // raw stdClass objects
        ];
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#API-2** · P3 — Notification listing pagination shape differs from all other list endpoints
    - **Where:** app/Services/Notifications/NotificationListingService.php:89-102 and app/Http/Controllers/Api/Professional/Notifications/NotificationController.php:27-31
    - **Affects:** Frontend developers building notification UIs — they need different pagination logic for notifications (`limit` + `has_more` boolean) vs every other list endpoint (`page` + `per_page` with `meta.current_page`/`meta.last_page`/`links.next`).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Decide on one pagination contract — either migrate notifications to `paginate()` style, or document the divergence as intentional with a clear reason (e.g. real-time polling doesn't benefit from total-page metadata).
        - If `limit`+`has_more` is kept, add a `next_cursor` or `next_page` token so clients can request subsequent pages deterministically.
        - Ensure the response envelope includes enough metadata for clients to know there are more results without counting returned items.
    - **Technical:** `NotificationController::index()` accepts `?limit=` (default 50) and the service returns `{unread_count, has_more, notifications: [...]}`. Contrast with `ProfessionalEmailSubscriptionController::index()` which uses `$query->paginate($perPage)` and returns the standard paginated shape with `meta` and `links`. Clients must maintain two code paths — one that reads `has_more` and increments a local offset, and another that reads `meta.current_page` and follows `links.next`. The notification approach is closer to cursor-based pagination but omits the cursor token, so there's no way to resume from where the last page left off if new notifications arrive between requests.
    - **Plain English:** Most of the API works like a book with numbered pages — you ask for page 3, you get page 3. The notification list works like a scroll — you get 50 items and a flag saying "there's more." Both are valid, but the client has to build two different reading mechanisms. It's like having a library where half the books use page numbers and half use "keep scrolling." The fix is to either number all the pages or give the scroll a bookmark so the client knows where to resume.
    - **Evidence:**
        ```php
        // NotificationController.php:27-31 — limit-based, no page parameter
        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min($limit, 200));
        $includeDismissed = filter_var($request->query('include_dismissed', false), FILTER_VALIDATE_BOOLEAN);
        return $this->success($this->listing->index($pro->id, $limit, $includeDismissed));

        // vs ProfessionalEmailSubscriptionController.php — page-based paginate()
        $page = $query->paginate($perPage)->appends($request->query());
        return $this->success($this->paginatedResponse($page, 'subscriptions', [...]));
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#API-3** · P3 — DataExportPayloadBuilder leaks ip_hash and user_agent on lead_submissions but redacts them on enquiries
    - **Where:** app/Services/Professional/DataExport/DataExportPayloadBuilder.php:113-117 (enquiries, redacted) vs :158-164 (bookings→lead_submissions, not redacted)
    - **Affects:** Professionals who request a GDPR data export — their export zip contains IP hash and user-agent strings for lead submissions but not for enquiries, creating an inconsistency in what technical metadata is disclosed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Redact `ip_hash` and `user_agent` from lead_submissions in the export payload, matching the enquiries approach.
        - OR explicitly document why leads carry technical metadata but enquiries don't (e.g. spam analysis), and apply the same rule consistently.
        - Also remove `ip_hash` and `user_agent` from the lead_submissions query if the `ExportCustomerDataJob` pattern is the intended one (that job already strips them).
    - **Technical:** The `enquiries()` method in `DataExportPayloadBuilder` uses `->select(['id', 'name', 'email', 'phone', 'subject', 'message', 'created_at'])` — deliberately omitting `ip_hash` and `user_agent`. The `bookings()` method, which includes lead_submissions, uses `->get()->map(fn ($r) => (array) $r)` — selecting all columns including `ip_hash` and `user_agent`. The `ExportCustomerDataJob::gatherExportData()` (Shopify GDPR path) maps lead_submissions to an array but also selects all columns, creating the same inconsistency. The platform's stance on enquiries (strip technical fingerprints) should apply uniformly to lead submissions — both are user-submitted forms tracked for abuse prevention, not data the professional needs in their export.
    - **Plain English:** When a business owner downloads their data, the "contact form messages" section thoughtfully hides technical tracking info (IP address hash, browser type). But the "lead form submissions" section includes that same tracking info. It's like redacting a phone number on page one of a report but printing it in full on page three. The fix is to apply the same redaction rule everywhere, so the export is consistent regardless of which form the customer filled out.
    - **Evidence:**
        ```php
        // DataExportPayloadBuilder.php:113-117 — enquiries ARE redacted
        return DB::connection('pgsql')
            ->table('site.enquiries')
            ->select(['id', 'name', 'email', 'phone', 'subject', 'message', 'created_at'])
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        // DataExportPayloadBuilder.php:161-165 — lead_submissions are NOT redacted
        $leads = DB::connection('pgsql')
            ->table('analytics.lead_submissions')
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#API-4** · P3 — No audience-specific Resource class exists for EmailSubscription; Professional and Staff endpoints share raw model serialisation
    - **Where:** app/Http/Controllers/Api/Professional/Notifications/ProfessionalEmailSubscriptionController.php:51-52 and app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php:46-47
    - **Affects:** Future development — if a field is added to `EmailSubscription` that should be Staff-only (e.g. `admin_notes`, `flagged_reason`), the `$hidden` attribute on the Eloquent model would either hide it from both audiences or show it to both. There's no per-audience filter.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `ProfessionalEmailSubscriptionResource` and `StaffEmailSubscriptionResource` classes.
        - Have the Professional Resource expose only the brand-owner-relevant fields (`email`, `full_name`, `status`, `subscribed_at`, `list_key`).
        - Have the Staff Resource expose the same fields — they're currently identical in scope, but the separation makes future Staff-only additions safe by default.
        - Register both in `AppServiceProvider` and route the appropriate Resource in each controller.
    - **Technical:** Both controllers call `EmailSubscription::query()->paginate()` and pass the paginator through `$this->paginatedResponse()`. The items in the paginator are Eloquent models serialised via `toArray()`, which respects `$hidden` and `$casts`. Currently `$hidden = ['unsubscribe_token', 'consent_ip_hash', 'consent_user_agent']` — these are correctly hidden from both audiences. But this is an all-or-nothing gate. The Partna architecture expects per-audience Resource classes for any model served to more than one API surface. Without them, the next developer who adds a Staff-internal field to the model has no obvious place to say "this is Staff-only" and will likely either add it to `$hidden` (breaking the Staff endpoint) or leave it exposed (leaking it to Professionals).
    - **Plain English:** Right now, the subscriber list looks the same whether the brand owner views it or a Partna staff member views it. That's fine today because there's nothing sensitive that differs between them. But the architecture is designed for each audience to have its own "lens." Without that lens in place, the next person who adds a staff-only note field has nowhere to put it — they'll either hide it from everyone (breaking the staff view) or show it to everyone (leaking internal notes to brand owners). Setting up the lenses now, even if they show the same fields, prevents that future mistake.
    - **Evidence:**
        ```php
        // ProfessionalEmailSubscriptionController.php:51-52
        $page = $query->paginate($perPage)->appends($request->query());
        return $this->success($this->paginatedResponse($page, 'subscriptions', [...]));

        // StaffEmailSubscriberController.php:46-47 — identical pattern, no Resource
        $page = $query->paginate($perPage)->appends($request->query());
        return $this->success($this->paginatedResponse($page, 'subscriptions', [...]));
        ```
    - `[DRAFT, confidence: 0.75]`

<!-- ═══ LENS: job-queue-correctness ═══ -->

- [ ] **#JOB-1** · P1 — `SendTransactionalNotificationEmailJob` silently discards permanent failures, retries wasted
    - **Where:** app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php:51-55, 79-82, 86-89
    - **Affects:** Transactional email delivery for invites, commissions, and payouts — failures are invisible to operators.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->fail($e ?? new \RuntimeException('Permanent failure: no email on record'))` in the `! $email` branch.
        - Add `$this->fail(new \RuntimeException('Mailable instantiation failed for ' . $class))` in the `$mailable === null` branch.
        - Optionally: keep the feature-disabled and preference-disabled branches as no-fail early-returns (those are legitimate no-ops).
    - **Technical:** The job has `$tries = 3` and `$backoff = [30, 120, 300]`. Three early-return paths in `handle()` log and exit without calling `$this->fail()`: no email on record, mailable class doesn't exist, and mailable instantiation returns null. These are non-transient conditions — if the professional has no email at T=0, they won't have one at T=120 or T=300. Horizon marks the job succeeded after three no-op retries; no failed-jobs counter increment, no Nightwatch alert. For financially-sensitive categories (commission, payout), a silently dropped email is a trust defect. Laravel's `$this->fail()` method marks the job failed and fires the `failed()` hook, which already logs properly.
    - **Plain English:** Imagine a mailroom clerk who, when handed an envelope with no address, puts it back in the "retry later" pile three times before quietly throwing it away. The sender never knows it wasn't delivered. For commission and payout emails, that's money-related communication going silently missing.
    - **Evidence:**
        ```php
        if (! $email) {
            Log::warning('Notification email skipped: no email on record', [
                'professional_id' => $this->professionalId,
            ]);

            return;  // <-- no $this->fail(), job retries 3x then disappears
        }

        $mailable = $this->buildMailable($notification, $class);
        if ($mailable === null) {
            Log::warning('Notification email skipped: mailable instantiation failed', [
                'category' => $this->category,
                'class' => $class,
            ]);

            return;  // <-- no $this->fail(), same silent discard
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#JOB-2** · P2 — `FanOutBrandStatusNotificationJob` missing `ShouldBeUnique` — concurrent runs dispatch double batches
    - **Where:** app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php:33 (class declaration)
    - **Affects:** Queue resource waste; every connected affiliate gets duplicate `SendBrandStatusNotificationJob` instances when the fan-out runs twice concurrently.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `implements ShouldBeUnique` to the class declaration.
        - Add `public function uniqueId(): string { return 'fanout-brand-status:' . $this->brandProfessionalId . ':' . $this->brandStatus; }`.
    - **Technical:** This job iterates `brand_partner_links` via `chunkById` and dispatches one `SendBrandStatusNotificationJob` per affiliate. If two instances run concurrently (brand status flips twice quickly, or Horizon restarts during processing), both walk the same partner list and dispatch duplicate batches. Although the leaf job's dedupe key prevents duplicate notification rows, the queue still processes double the work. `ShouldBeUnique` with a `uniqueId()` keyed on `(brandProfessionalId, brandStatus)` serialises concurrent runs for the same status transition. The `$tries = 3` property is preserved — uniqueness only gates concurrency, not retries.
    - **Plain English:** If two mailroom workers grab the same mailing list at the same time, they both stuff envelopes for every name on the list. The duplicates get caught at the mailbox (the dedupe key), but twice the envelopes were stuffed and carried. A simple "only one worker on this list at a time" rule prevents the waste.
    - **Evidence:**
        ```php
        class FanOutBrandStatusNotificationJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;
            public int $backoff = 30;
            // ... no ShouldBeUnique interface, no uniqueId() method
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#JOB-3** · P2 — `SendStaffBroadcastEmailsJob` missing `ShouldBeUnique` — concurrent runs double the batch fan-out
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:31 (class declaration)
    - **Affects:** Queue throughput; subscribers may see redundant processing (though actual duplicate emails are blocked by the leaf job's `insertOrIgnore` guard).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `implements ShouldBeUnique` to the class declaration.
        - Add `public function uniqueId(): string { return 'staff-broadcast:' . $this->notificationId; }`.
    - **Technical:** Same concurrency pattern as JOB-2. The job chunks through `EmailSubscription` rows and dispatches `SendStaffBroadcastEmailToSubscriberJob` batches. The leaf job has an `insertOrIgnore` on `broadcast_email_receipts` so actual double-sends are prevented. But if the fan-out runs twice concurrently, every subscriber gets two leaf jobs dispatched into the `mail` queue, doubling processing cost. `ShouldBeUnique` keyed on `notificationId` ensures only one fan-out instance processes a given broadcast.
    - **Plain English:** Same "two workers on the same mailing list" problem, but for platform-wide staff broadcasts. The leaf worker checks "have I already sent to this person?" before hitting send, so nobody gets duplicate emails — but the system still does double the preparation work for every recipient.
    - **Evidence:**
        ```php
        class SendStaffBroadcastEmailsJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;
            public array $backoff = [10, 30, 60];
            // ... no ShouldBeUnique interface, no uniqueId() method
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#JOB-4** · P3 — All retryable jobs lack `$maxExceptions` — failures exhaust full retry window before surfacing
    - **Where:** app/Jobs/Notifications/* and app/Jobs/Shopify/Gdpr/* (every job with `$tries >= 2`)
    - **Affects:** Incident response time — deterministically-failing jobs take minutes to surface instead of seconds.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public int $maxExceptions = 2;` to every job that has `$tries >= 3`.
        - For jobs with `$tries = 2`, add `public int $maxExceptions = 1;`.
    - **Technical:** Without `$maxExceptions`, a job that throws on every attempt will exhaust all `$tries` over the full `$backoff` window. For `SendTransactionalNotificationEmailJob` (`$tries=3`, `$backoff=[30,120,300]`), that's up to 7.5 minutes before Horizon marks it failed and alerts fire. With `$maxExceptions=2`, the job fails after the second consecutive throw — much faster visibility. This pairs with JOB-1: once those permanent-failure paths call `$this->fail()`, `$maxExceptions` ensures the job doesn't waste retry slots on the same deterministic error.
    - **Plain English:** Right now, when a job hits an unrecoverable error on every attempt, it keeps retrying until it exhausts its full allowance — like a smoke detector that waits 7 minutes to go off after detecting smoke. Setting a "max consecutive exceptions" limit is like telling the detector to trigger after the second puff, not after the whole room is filled.
    - **Evidence:**
        ```php
        // SendTransactionalNotificationEmailJob — representative of all audited jobs
        public int $tries = 3;
        public array $backoff = [30, 120, 300];
        // public int $maxExceptions is absent — defaults to $tries (3)
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#JOB-5** · P3 — `EmailSubscription` `saved` hook dispatches job outside transaction safety
    - **Where:** app/Models/Core/Notifications/EmailSubscription.php:105-112
    - **Affects:** `SyncCustomerMarketingOptInJob` may run against a rolled-back `EmailSubscription` row, wasting a queue slot.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `static::saved()` hook dispatch with a `dispatchAfterResponse()` call, or
        - Move the dispatch to a service-layer call after the transaction commits (e.g., in `ContactCaptureService`), or
        - Add the `AfterCommit` trait equivalent by wrapping in `DB::afterCommit(...)`.
    - **Technical:** The `saved` Eloquent event fires immediately after `save()`, which may still be inside an open database transaction. `SyncCustomerMarketingOptInJob::dispatch()` pushes the job to Redis immediately. If the transaction rolls back, the `EmailSubscription` row never exists, but the job is already queued and will process. The job's `handle()` degrades gracefully — it calls `Customer::where(...)->first()` and returns early if no customer is found. So no data corruption, but wasted queue work and a confusing no-op in logs. Laravel's `dispatchAfterResponse()` or `DB::afterCommit()` would defer the dispatch until after the transaction commits (or the response is sent).
    - **Plain English:** Imagine a clerk stamps "SEND" on a task card and drops it in the outbox while the manager is still reviewing the paperwork. If the manager rejects the paperwork, the task card is already in the mailroom. The mailroom worker looks for the related file, can't find it, and shrugs — wasted a trip. The fix is to put the task card in the outbox only after the manager signs off.
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
    - `[DRAFT, confidence: 0.75]`

<!-- ═══ LENS: configuration-hygiene ═══ -->

- [ ] **#CFG-1** · P2 — `config('partna.public_domain')` called without fallback default in public site route file
    - **Where:** routes/api/publicSite.php:15
    - **Affects:** All public-site routes (site rendering, bookings, leads, enquiries, subscribe/unsubscribe). If the key is missing, the domain group pattern resolves to `{subdomain}.` (empty string), breaking every public-site endpoint.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a sensible fallback: `config('partna.public_domain', 'partna.au')` so the route file degrades gracefully.
        - Add a deployment-time assertion in a service provider's `boot()` that fails loudly in production if the key returns an empty string.
    - **Technical:** Laravel's `Route::group(['domain' => ...])` with an empty domain segment matches against an empty host portion, which is never the intended behaviour. The public-site route file is the only place this config key is consumed, and there is no defensive default — one missing env-to-config mapping silently breaks the entire public surface. Category 5 (config file correctness / missing default).
    - **Plain English:** The public-site route file reads the domain name that visitors use to see a brand's site. If that setting ever goes missing from config, every public page becomes unreachable instead of falling back to a known-good domain. Think of it as a signpost with a missing arrow — nobody knows where to go.
    - **Evidence:**
        ```php
        $publicDomain = config('partna.public_domain');

        Route::group([
            'domain' => '{subdomain}.'.$publicDomain,
            'where' => ['subdomain' => '[A-Za-z0-9-]+'],
            'prefix' => 'public',
        ], function () {
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CFG-2** · P2 — `config('partna.gdpr.queue')` called without fallback in three GDPR job constructors
    - **Where:** app/Jobs/Shopify/Gdpr/ExportCustomerDataJob.php:37, app/Jobs/Shopify/Gdpr/RedactCustomerJob.php:33, app/Jobs/Shopify/Gdpr/RedactShopJob.php:37
    - **Affects:** All Shopify GDPR compliance jobs (customer data export, customer redaction, shop redaction). Missing config silently routes these jobs to the default queue instead of the isolated GDPR queue.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a fallback: `config('partna.gdpr.queue', 'gdpr')` in all three constructors.
        - Ensure `.env.example` lists the corresponding env var (e.g., `GDPR_QUEUE=gdpr`) so new environments route these jobs correctly.
    - **Technical:** The GDPR queue exists to isolate compliance jobs from the main queue so they can have independent retry/backoff policies and a dedicated Horizon pool. `onQueue(null)` (which is what `config()` returns when the key doesn't exist) silently puts these jobs on the `default` queue, defeating the isolation. The `RedactShopJob` goes further and also sets `onConnection('redis_gdpr')` — the queue name inconsistency between connection override and missing queue default compounds the risk. Category 5 (config file correctness).
    - **Plain English:** GDPR jobs — like deleting a customer's data when Shopify tells us — are meant to run in a separate lane so they don't get stuck behind newsletters and booking notifications. If the config key for that lane goes missing, these jobs quietly merge into the main traffic lane with no one noticing. The fix is a safety net so the lane name defaults to something sensible even if someone forgets to set it.
    - **Evidence:**
        ```php
        // ExportCustomerDataJob.php:37
        $this->onQueue(config('partna.gdpr.queue'));

        // RedactCustomerJob.php:33
        $this->onQueue(config('partna.gdpr.queue'));

        // RedactShopJob.php:37
        $this->onConnection('redis_gdpr')->onQueue(config('partna.gdpr.queue'));
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#CFG-3** · P3 — Duplicated `BATCH_CHUNK_SIZE` constant with explicit sync-warning comments in two fan-out jobs
    - **Where:** app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php:37-39, app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:37-39
    - **Affects:** Brand status fan-out and staff broadcast email dispatch. If only one constant is changed and the other is forgotten, the two fan-out paths diverge in batch size, causing uneven Redis pipeline pressure.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract to a single config key: `config('sidest.notifications.batch_chunk_size', 200)`.
        - Replace both `private const BATCH_CHUNK_SIZE` declarations with config reads.
        - Remove the "keep in sync" comments — the config file becomes the single source of truth.
    - **Technical:** Both classes define `private const BATCH_CHUNK_SIZE = 200` with near-identical docblocks warning "Shared with [the other class] — keep in sync if changed." This is a textbook signal that the value belongs in shared config. The constant controls how many jobs are packed into one `Bus::batch()` call, which determines Redis pipeline write load. Category 4 (hardcoded values that should be config-driven).
    - **Plain English:** Two different parts of the system split a big list of recipients into chunks of 200 before handing them to the queue. Both parts have a note saying "if you change this, also change it in the other file." That's like two cooks in a kitchen each having their own measuring cup with a sticky note saying "use the same size as the other cook." Put the measurement on the recipe card (config) and both cooks read from the same place.
    - **Evidence:**
        ```php
        // FanOutBrandStatusNotificationJob.php:37
        // Bound batch size so any one Redis pipeline write stays predictable.
        // Shared with SendStaffBroadcastEmailsJob — keep in sync if changed.
        private const BATCH_CHUNK_SIZE = 200;

        // SendStaffBroadcastEmailsJob.php:37
        // Bound batch size so any one Redis pipeline write stays predictable.
        // Shared with FanOutBrandStatusNotificationJob — keep in sync if changed.
        private const BATCH_CHUNK_SIZE = 200;
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#CFG-4** · P3 — Hardcoded notification listing cache TTL in NotificationListingService
    - **Where:** app/Services/Notifications/NotificationListingService.php:54
    - **Affects:** In-app notification bell polling — the 15-second TTL governs how quickly a newly-published notification surfaces in the dashboard without refreshing. Tuning requires a code change and deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the hardcoded `15` with `config('sidest.notifications.listing_cache_ttl_seconds', 15)`.
        - Consider extracting other cache TTLs in the notifications domain (`NotificationPublisher::CACHE_TTL_SECONDS = 3600`, `CommerceNotificationService::MILESTONE_TOTALS_TTL_SECONDS = 60`) for consistency.
    - **Technical:** The `rememberLocked()` call in `NotificationListingService::index()` bakes `15` as the cache lifetime. A 15-second TTL is a deliberate UX tradeoff (notifications appear within one poll cycle vs. cache hit rate), and tuning it via config lets operators adjust without a deployment. Category 4 (hardcoded values).
    - **Plain English:** The notification bell on the dashboard refreshes every 15 seconds because of a number baked into the code. If we ever want to make it faster (5 seconds) or slower (30 seconds, to reduce server load), someone has to edit the code and deploy. Moving this number to a settings file means a quick config change does the job.
    - **Evidence:**
        ```php
        return $this->cache->rememberLocked(
            $this->cacheKey($professionalId, $limit, $includeDismissed),
            15,
            fn () => $this->buildIndexPayload($professionalId, $limit, $includeDismissed),
        );
        ```
    - `[DRAFT, confidence: 0.70]`
