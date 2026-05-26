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
