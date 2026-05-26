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
