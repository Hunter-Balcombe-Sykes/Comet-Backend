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
