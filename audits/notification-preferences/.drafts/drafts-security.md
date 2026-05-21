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
