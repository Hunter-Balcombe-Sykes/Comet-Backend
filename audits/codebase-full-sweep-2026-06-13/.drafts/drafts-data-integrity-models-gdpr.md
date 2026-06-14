- [ ] **#DINT-1** · P2 — `LeadSubmission` model has no `created_at` population path; rows land with NULL timestamps
    - **Where:** app/Models/Analytics/LeadSubmission.php:10-29
    - **Affects:** Every `analytics.lead_submissions` row — time-based retention queries, the analytics purge command, and any dashboard that relies on `created_at` for ordering or windowing.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'created_at' => 'datetime'` to `$casts` so the column is cast correctly (mirrors `LinkClick`, `SectionView`, `SiteVisit`).
        - Confirm the migration has a `DEFAULT now()` on `created_at`, or add a `static::creating()` hook that stamps it — otherwise every row lands with NULL and the scheduled `partna:analytics:purge-raw-events` retention window can never match.
    - **Technical:** `LeadSubmission` sets `public $timestamps = false;` (Laravel skips automatic `created_at`/`updated_at` management) but unlike the other three analytics models it does not list `created_at` in `$casts`. It is not in `$fillable` either, so even if application code tries to set it, mass-assignment protection may silently drop the value. The column will be NULL on every insert unless a DB DEFAULT exists — and if the DEFAULT was added in the migration it is invisible to the model layer's type system because no cast is declared.
    - **Plain English:** Imagine a warehouse where every incoming box is supposed to get a date-stamp so you know when it arrived. Three of the four loading docks have a working date-stamper; the fourth doesn't. Boxes pile up there with no arrival date. When it's time to clear inventory older than 90 days, you can't tell how old those boxes are — so they either get deleted too early (data loss) or never get deleted (storage bloat).
    - **Evidence:**
        ```php
        // app/Models/Analytics/LeadSubmission.php

        // analytics tables don't have updated_at
        public $timestamps = false;

        protected $fillable = [
            'occurred_at',
            'subdomain',
            'site_id',
            'user_id',
            'customer_id',
            'ip_hash',
            'user_agent',
            'referrer',
            'outcome',
            'form_started_at_ms',
        ];

        protected $casts = [
            'occurred_at' => 'datetime',
        ];
        ```
        ```php
        // Compare with app/Models/Analytics/LinkClick.php which DOES include it:
        protected $casts = [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#DINT-2** · P2 — Email uniqueness enforced only in application code; no DB-level unique constraint visible
    - **Where:** app/Services/User/UserBootstrapService.php:108-117
    - **Affects:** New user registration — a direct DB write, a migration backfill, or a race between two concurrent bootstrap requests can create two `core.users` rows with the same `primary_email`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a partial unique index: `CREATE UNIQUE INDEX CONCURRENTLY ... ON core.users (LOWER(primary_email)) WHERE deleted_at IS NULL;` so the constraint respects soft-deletes.
        - Keep the application-level guard as a fast-path 409 but treat the index as the safety net.
    - **Technical:** `UserBootstrapService::guardAgainstEmailReuseByDifferentAuthUser()` runs a `SELECT EXISTS(...)` inside a transaction before inserting. Two concurrent requests can both pass the SELECT, both insert, and both commit — the transaction isolation level (Read Committed) does not prevent this phantom. The guard is a best-effort check, not a constraint. The lens explicitly calls out "uniqueness enforced only via `->unique()` in a Form Request" as a finding; a service-layer SELECT is the same class of gap.
    - **Plain English:** It's like checking whether a seat is taken by looking at it from the aisle before walking down the row. If two people look at the same moment from opposite ends of the plane, they both see it empty and both try to sit. A DB unique constraint is the equivalent of an assigned seat on the ticket — the database itself rejects the second person regardless of timing.
    - **Evidence:**
        ```php
        // app/Services/User/UserBootstrapService.php
        private function guardAgainstEmailReuseByDifferentAuthUser(string $email, string $uid): void
        {
            $emailLc = strtolower(trim($email));
            if ($emailLc === '') {
                return;
            }

            $existingByEmail = User::query()
                ->whereRaw('lower(primary_email) = ?', [$emailLc])
                ->where('auth_user_id', '!=', $uid)
                ->exists();

            if ($existingByEmail) {
                throw new RuntimeException('EMAIL_ALREADY_REGISTERED');
            }
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#DINT-3** · P2 — `ConfirmationPreferenceService` uses `updateOrCreate` / `updateOrCreate` without a visible DB unique constraint on `(user_id, action_key)`
    - **Where:** app/Services/User/ConfirmationPreferenceService.php:45-54, 59-67
    - **Affects:** `core.user_confirmation_preferences` — concurrent "don't ask again" toggles or a rapid double-click on delete-media confirmation can create duplicate rows. The service reads via `pluck('skip_confirmation', 'action_key')` which picks the last row for a given key, so duplicate data is silently non-deterministic.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `UNIQUE(user_id, action_key)` on `core.user_confirmation_preferences` (with `WHERE deleted_at IS NULL` if the table uses soft-deletes, which it currently does not — the model has no `SoftDeletes` trait).
        - The transaction in `updateForProfessional` already serialises writes per user; the unique constraint protects against the transaction-less `enableForProfessional` path.
    - **Technical:** `updateOrCreate` in Laravel performs a SELECT-then-INSERT-or-UPDATE cycle. Under Read Committed isolation, two concurrent calls can both SELECT zero rows, both proceed to INSERT, and the second INSERT succeeds alongside the first if no unique constraint blocks it. The natural key `(user_id, action_key)` is exactly the lookup tuple used by `getForProfessional`, so the DB must guard it.
    - **Plain English:** Two browser tabs both check the "don't ask again" box at roughly the same time. Without a database rule that says "only one preference per action per user," both tabs succeed and now there are two rows. The next time the preference is read, one of them wins arbitrarily — the user's actual choice is buried under a coin flip.
    - **Evidence:**
        ```php
        // app/Services/User/ConfirmationPreferenceService.php
        // updateForProfessional() — inside DB::transaction()
        UserConfirmationPreference::query()->updateOrCreate(
            ['user_id' => $userId, 'action_key' => $actionKey],
            ['skip_confirmation' => $skipConfirmation]
        );

        // enableForProfessional() — NO transaction wrapper
        UserConfirmationPreference::query()->updateOrCreate(
            ['user_id' => $userId, 'action_key' => $actionKey],
            ['skip_confirmation' => true]
        );
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#DINT-4** · P2 — `UserBootstrapService::createWelcomeNotification` uses `firstOrCreate` without a DB unique constraint on `(user_id, type, title)`
    - **Where:** app/Services/User/UserBootstrapService.php:139-152
    - **Affects:** New user onboarding — two near-simultaneous bootstrap requests (e.g., app and web client racing after Supabase signup) can create duplicate welcome notifications. The user sees two identical "Welcome to Partna" messages in their dashboard.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `UNIQUE(user_id, type, title)` (or `UNIQUE(user_id, type)` if the intent is one Info notification per user) on `notifications.notifications`.
        - Alternatively, make the notification creation idempotent by checking existence with a lock before insert, matching the `DataExportService` dedup pattern.
    - **Technical:** `firstOrCreate` is a SELECT + INSERT pair. Under concurrency, the same gap as DINT-3 applies. The impact is lower (duplicate welcome messages are cosmetic, not data corruption), but the pattern itself is a constraint-in-code-only anti-pattern that should be fixed consistently across the codebase.
    - **Plain English:** It's the "Welcome to Partna" greeting card. When a user signs up, the system puts one in their inbox. But if the signup flow fires twice (maybe the mobile app and the website both try to finish setup at the same moment), two identical cards land in the inbox. It's not harmful, but it's untidy and erodes the polished first impression the product aims for.
    - **Evidence:**
        ```php
        // app/Services/User/UserBootstrapService.php
        private function createWelcomeNotification(User $professional): void
        {
            Notification::query()->firstOrCreate(
                [
                    'user_id' => $professional->id,
                    'type' => 'Info',
                    'title' => 'Welcome to Partna',
                ],
                [
                    'body' => 'Your account is ready. Complete your profile...',
                    'cta_url' => null,
                    'severity' => 'info',
                    'starts_at' => now(),
                    'ends_at' => null,
                ]
            );
        }
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#DINT-5** · P2 — `EmailSubscription::insertOrIgnore` uses PostgreSQL `ON CONFLICT DO NOTHING` without an explicit conflict target; idempotency depends on any unique index matching by coincidence
    - **Where:** app/Services/User/UserBootstrapService.php:124-137
    - **Affects:** `notifications.email_subscriptions` — if the table's unique constraint is on a column set that doesn't fully overlap with the INSERT columns, or if the constraint is dropped during a migration, duplicate `sidest_updates` subscription rows appear. The user's subscription state becomes ambiguous.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Verify the migration has a `UNIQUE(list_key, email_lc)` (or equivalent) that matches the dedup intent.
        - If constraint exists, add a comment above the `insertOrIgnore` call documenting which constraint provides the idempotency guarantee, so future schema changes don't accidentally remove it.
        - Consider using `updateOrInsert` with an explicit key tuple for self-documenting intent.
    - **Technical:** Laravel's `insertOrIgnore` compiles to `INSERT ... ON CONFLICT DO NOTHING` in PostgreSQL. Without an `ON CONFLICT (col1, col2)` target clause, Postgres relies on ANY unique constraint violation to trigger the ignore. This works but is fragile — if the only matching unique index is on `id` (the UUID PK, which never collides), every call inserts a new row and `insertOrIgnore` silently degrades to a plain INSERT. An explicit `ON CONFLICT (list_key, email_lc)` in a raw statement would be self-documenting.
    - **Plain English:** The system wants to make sure every user gets exactly one "product updates" subscription. It says "add this subscription, but if something already exists that conflicts, just skip it." The problem: it doesn't specify WHAT should conflict. If the database's uniqueness rule ever changes (or doesn't exist yet), the system silently creates duplicates, and the user starts getting two copies of every update email.
    - **Evidence:**
        ```php
        // app/Services/User/UserBootstrapService.php
        private function ensureSidestUpdatesSubscription(?string $email): void
        {
            // ...
            EmailSubscription::insertOrIgnore([
                'id' => (string) Str::uuid(),
                'user_id' => null,
                'list_key' => 'sidest_updates',
                'email' => $email,
                'email_lc' => $email,
                'status' => 'subscribed',
                'subscribed_at' => $now,
                'consent_source' => 'bootstrap',
                'unsubscribe_token' => EmailSubscription::newUnsubscribeToken(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#DINT-6** · P2 — `GdprRequest` model still references `core.gdpr_requests` table; deprecated Shopify GDPR webhook store with potential orphaned PII
    - **Where:** app/Models/Core/Gdpr/GdprRequest.php:11-65
    - **Affects:** Any leftover rows in `core.gdpr_requests` from the pre-standalone era — the `payload` column (`$casts = ['payload' => 'array']`) can contain Shopify customer emails, shop domains, and request IDs. No export or deletion wiring covers this table.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If the table still exists in the DB and has rows: write a one-time purge migration that NULLs the `payload` column (or drops the table entirely) since Shopify GDPR webhooks are removed.
        - If the table is already dropped: delete the `GdprRequest` model class and any remaining references to it — dead code that imports a PII-carrying model is a ticking compliance bomb.
        - Verify no controller or job still queries `GdprRequest`.
    - **Technical:** The architectural notes state "Shopify GDPR webhooks are removed. GDPR work is now first-party." The `DataExportAudit` table was moved to `audit.data_export_audit` in the schema reorganisation migration, but `GdprRequest` was left behind pointing at `core.gdpr_requests`. The `payload` column is cast as `array` and can contain PII from Shopify webhook deliveries (customer email addresses, shop domains). If the table still holds rows, they are outside the data export payload builder AND the account deletion service — meaning a GDPR Article 15 or Article 17 request would miss them entirely.
    - **Plain English:** The platform used to connect to Shopify, and Shopify would send GDPR requests (like "delete this customer's data"). Those requests were stored in a database table along with the customer's actual email address and shop details. Shopify is gone now, but the table — and any old rows in it — might still be sitting there. If a user asks "what data do you have on me?" the system won't look in that table. It's like an old filing cabinet full of customer records that everyone forgot about in a storage closet.
    - **Evidence:**
        ```php
        // app/Models/Core/Gdpr/GdprRequest.php
        // V2: Audit row for Shopify GDPR webhooks. payload_hash unique index provides
        // idempotency against Shopify retries...
        class GdprRequest extends BaseModel
        {
            use HasUuids;

            public const TOPIC_CUSTOMERS_DATA_REQUEST = 'customers/data_request';
            public const TOPIC_CUSTOMERS_REDACT = 'customers/redact';
            public const TOPIC_SHOP_REDACT = 'shop/redact';
            // ...
            protected $table = 'core.gdpr_requests';   // <-- NOT audit.*, not covered by export/deletion

            protected $casts = [
                'payload' => 'array',                   // <-- can contain customer PII
                // ...
            ];
        }
        ```
    - `[DRAFT, confidence: 0.70]`

- [ ] **#DINT-7** · P3 — `FeatureFlagOverride` hand-rolls UUID generation instead of using the `HasUuids` trait; inconsistent with every other UUID-keyed model
    - **Where:** app/Models/Core/FeatureFlagOverride.php:24-28
    - **Affects:** Any code path that inserts a `FeatureFlagOverride` via `insert()` (bypassing Eloquent events) — the `id` column will be empty, causing a constraint violation or a row with an empty-string PK.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the manual `booted()` UUID logic with `use HasUuids;` to match the rest of the codebase.
        - Set `protected $keyType = 'string';` and `public $incrementing = false;` (already present — keep).
    - **Technical:** Every other UUID-keyed model in the codebase (`User`, `Site`, `Block`, `Enquiry`, `Customer`, `Service`, `SmartLink`, `LeadSubmission`, `LinkClick`, `SectionView`, `SiteVisit`, `MediaVariant`, `Notification`, `Feedback`, etc.) uses `HasUuids`. `FeatureFlagOverride` is the sole holdout that manually generates a UUID in a `creating` event. The `HasUuids` trait also hooks into `creating` but additionally sets `$incrementing = false` and `$keyType = 'string'` on boot — the manual approach duplicates this config but without the trait's static analysis guarantees. If a future developer copies the pattern without also setting `$incrementing` and `$keyType`, they get silent auto-increment behaviour on a string column.
    - **Plain English:** Every ID card printer in the building uses the same machine — except one desk where someone writes IDs by hand. It works, but if that person leaves and someone else tries to use the same desk with the standard machine, it jams. Consistency across models means fewer surprises when someone new reads the code.
    - **Evidence:**
        ```php
        // app/Models/Core/FeatureFlagOverride.php
        protected static function booted(): void
        {
            static::creating(function (self $row): void {
                if (empty($row->id)) {
                    $row->id = (string) Str::uuid();
                }
            });
        }
        ```
        ```php
        // canonical pattern used by all other UUID models:
        // app/Models/Analytics/LeadSubmission.php
        use HasUuids;
        // (no manual booted UUID logic needed)
        ```
    - `[DRAFT, confidence: 0.95]`
