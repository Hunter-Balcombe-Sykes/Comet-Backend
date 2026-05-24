# GDPR End-to-End Completeness Audit — 2026-05-24

**Branch:** development
**Lens:** GDPR end-to-end completeness, DSAR coverage, deletion cascade gaps, retention enforcement, data-export integrity, missing models in deletion flow
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Services/Professional/DataExport/DataExportPayloadBuilder.php`
- `app/Services/Professional/AccountDeletionService.php`
- `app/Jobs/Gdpr/ExportProfessionalDataJob.php`
- `app/Http/Controllers/Api/PublicSite/BootstrapController.php`
- `supabase/migrations/20260526000000_baseline_standalone_user.sql`

## Progress

- P0 Blockers: 0 of 1 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 2 complete

---

## P0 — Must fix before any real user touches the system

- [ ] **#GDPR-1** · P0 — Waitlist divert crashes with DB constraint violation on a live public path
    - **Where:** `app/Http/Controllers/Api/PublicSite/BootstrapController.php:57` / `supabase/migrations/20260526000000_baseline_standalone_user.sql:433`
    - **Affects:** Any visitor who hits the site while `individual_waitlist_enabled` is true — the waitlist sign-up upsert throws a PostgreSQL CHECK violation and the request 500s. No waitlist entry is saved; no error is surfaced to the user.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `'applicant_type' => 'individual'` in `BootstrapController` to a value the schema allows: `'professional'` is the closest semantic match for this audience, or add `'individual'` to the `waitlist_signups_type_check` constraint in a new migration.
        - Verify the upsert also supplies values for the `NOT NULL` columns `phone` and `industry` — both are required by the schema but neither is in the current upsert payload (they are likely defaulted or optional in the old form, but the schema is strict).
        - Add a feature-test for the waitlist divert path that exercises a real DB insert to catch constraint violations before deploy.
    - **Technical:** `core.waitlist_signups` has `CONSTRAINT waitlist_signups_type_check CHECK (applicant_type IN ('influencer', 'professional', 'other'))`. The BootstrapController sends `'individual'`, which is not in that set. PostgreSQL rejects the INSERT/upsert with a CHECK constraint violation, producing a 500. This code path executes on every bootstrap call when the waitlist divert flag is on — it is not a narrow edge case.
    - **Plain English:** Think of a velvet-rope list at a restaurant. When someone knocks, the doorperson writes their name in the book under a category. The book has a rule: only "influencer", "professional", or "other" are allowed categories. The code is writing "individual" — the book rejects it and throws the pen across the room. Anyone trying to join the waitlist gets a broken page, and no record is kept that they ever knocked.
    - **Evidence:**
        ```php
        // BootstrapController.php:54-60
        [
            'email' => $emailLc,
            'name' => trim(((string) $request->input('first_name', '')).' '.((string) $request->input('last_name', ''))) ?: null,
            'applicant_type' => 'individual',   // ← not in the CHECK constraint
            'consent_source' => 'individual_waitlist_divert',
            'last_submitted_at' => now(),
        ]
        ```
        ```sql
        -- baseline migration line 433
        CONSTRAINT waitlist_signups_type_check CHECK (applicant_type IN ('influencer', 'professional', 'other')),
        ```

---

## P1 — Fix before pilot launch

- [ ] **#GDPR-2** · P1 — Global `sidest_updates` subscription omitted from DSAR export
    - **Where:** `app/Services/Professional/DataExport/DataExportPayloadBuilder.php` — `streamEmailSubscriptions()`
    - **Affects:** All users who signed up via the public site bootstrap flow. `ensureSidestUpdatesSubscription()` creates a `notifications.email_subscriptions` row with `professional_id = NULL` keyed only by email. That row is personal data (name + email + consent timestamps) but is invisible to the DSAR export because the query filters exclusively on `professional_id`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an OR clause (or a second query) to `streamEmailSubscriptions` that also fetches rows where `professional_id IS NULL AND email = $professional->email`.
        - Confirm the export ZIP writer handles the merged generator correctly (it should — it's already row-based).
        - Add a unit test that seeds one global row and asserts it appears in the export.
    - **Technical:** `streamEmailSubscriptions` is called with only `->where('professional_id', $professionalId)`. Global subscriptions — those created with `professional_id = null` — are architecturally linked to the user by email, not by FK. Under GDPR Article 15, all personal data held about a data subject must be disclosed; the subscription record contains name, email, consent source, and timestamps and clearly belongs to the subject.
    - **Plain English:** When a user joined the mailing list, the system saved their name and email in one place but used a blank for the "who owns this" field. When that user later asks "show me everything you have on me," the export code only looks for records marked with their ID — it completely misses the mailing-list row because it has a blank owner field instead of an ID. The user's consent record is invisible in their own data export.
    - **Evidence:**
        ```php
        private function streamEmailSubscriptions(string $professionalId): Generator
        {
            return $this->lazyRows(
                DB::connection('pgsql')
                    ->table('notifications.email_subscriptions')
                    ->select(['id', 'professional_id', 'list_key', 'email', 'full_name', 'status', 'subscribed_at', 'unsubscribed_at', 'consent_source', 'created_at'])
                    ->where('professional_id', $professionalId)   // ← global rows (professional_id IS NULL) missed
            );
        }
        ```

- [ ] **#GDPR-3** · P1 — Waitlist signup record not exported in DSAR
    - **Where:** `app/Services/Professional/DataExport/DataExportPayloadBuilder.php` — `stream()` method / `app/Jobs/Gdpr/ExportProfessionalDataJob.php`
    - **Affects:** Any user who signed up through the waitlist divert path before their account was created. The `core.waitlist_signups` row holds name, email, phone, industry, consent source, and timestamps — all personal data — but there is no `streamWaitlistSignups` method and no reference to `waitlist_signups` anywhere in the export pipeline.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `streamWaitlistSignups(string $email): Generator` method to `DataExportPayloadBuilder` that queries `core.waitlist_signups WHERE email_lc = lower($professional->email)` (the table has no `professional_id` FK; email is the only join key).
        - Yield the descriptor from `stream()` under the name `'waitlist'`.
        - Add a test that seeds a waitlist row and asserts it appears in the export output.
    - **Technical:** `core.waitlist_signups` has a unique index on `email_lc` and no FK to `core.users` — the link is entirely by email. `stream()` never yields a descriptor for this table, confirmed by Grep (0 matches for `waitlist_signups` in the entire `DataExport/` directory). Under GDPR Article 15, the record is in-scope personal data.
    - **Plain English:** Before a user had a proper account they were on a waiting list. The waiting-list form collected name, email, phone number, and what type of professional they are. That data still sits in a separate list in the database. When the user asks for all their data, the export system doesn't know to look in the waiting list — it only checks the places it was explicitly told to look, and nobody ever told it about this one.
    - **Evidence:**
        ```php
        // stream() in DataExportPayloadBuilder — full yield list, no waitlist entry
        yield ['name' => 'metadata', 'kind' => 'value', 'value' => $this->metadata($professional)];
        yield ['name' => 'profile',  'kind' => 'value', 'value' => $this->profile($professional)];
        yield ['name' => 'site',     'kind' => 'value', 'value' => $this->site($professionalId)];
        // ... media, integrations, email_subscriptions, notification_preferences, audit
        // waitlist_signups: never yielded
        ```
        ```sql
        -- no professional_id column; email_lc is the only user link
        CREATE UNIQUE INDEX waitlist_signups_email_lc_unique ON core.waitlist_signups (email_lc);
        ```

---

## P2 — Should fix

- [ ] **#GDPR-4** · P2 — Handle change log not included in DSAR export
    - **Where:** `app/Services/Professional/DataExport/DataExportPayloadBuilder.php` — `stream()`
    - **Affects:** Users who have renamed their handle. `core.handle_change_log` records every old and new handle with timestamps — it is a history of publicly-used identifiers tied to the user. It survives user hard-delete via `ON DELETE SET NULL`, so it persists after erasure and should be disclosed before that point.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `streamHandleChangeLog(string $professionalId): Generator` querying `core.handle_change_log WHERE professional_id = $professionalId`.
        - Yield it from `stream()` under `'audit.handle_change_log'` (alongside the existing `data_export_audit`).
    - **Technical:** Handle rename events are personally identifying (they link past public identities to the current user) and are stored indefinitely. GDPR Article 15 requires disclosure. The `ON DELETE SET NULL` means these rows outlive the account — making pre-deletion disclosure especially important.
    - **Plain English:** Every time a user changes their public web address (like changing "sarah-hair" to "sarah-styles"), the system keeps a record of the old name. Those old names are personal data — they could be used to link the user's current identity to their past one. The data export doesn't include this history, so a user who asks "what do you have on me?" won't see a list of all their old addresses.
    - **Evidence:**
        ```sql
        -- baseline migration: handle_change_log FK is ON DELETE SET NULL (rows persist after user deletion)
        -- no corresponding stream method or yield exists in DataExportPayloadBuilder::stream()
        ```

- [ ] **#GDPR-5** · P2 — `streamLeadSubmissions` implemented but never yielded — lead analytics silently omitted from export
    - **Where:** `app/Services/Professional/DataExport/DataExportPayloadBuilder.php:300` and `stream()`
    - **Affects:** DSAR completeness. `streamLeadSubmissions` queries contact-form submissions associated with the professional's site (IP hash, referrer, form timing, submission content). The method exists and is fully implemented but is never called from `stream()`, so lead submission data never appears in any export ZIP.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a yield block for `streamLeadSubmissions` in `stream()`, following the same pattern as the other row-based sections.
        - Decide whether `ip_hash` and `user_agent` should be included or redacted (the existing comment says to mirror the redaction in `enquiries()` — follow that guidance).
    - **Technical:** The method is private and fully written but has no call site. It is a dead private method — PHP will never complain, it will silently not run. Any DSAR export produced today is missing this data set despite the implementation existing.
    - **Plain English:** The developer wrote the code to include contact-form submissions in the data export — wrote the whole thing — but forgot to actually hook it up so it runs. It's like writing a chapter of a report, printing it, and then forgetting to put it in the binder. The report goes out incomplete and nobody notices because no error occurs.
    - **Evidence:**
        ```php
        // Exists at line 300 — fully implemented, never called from stream():
        private function streamLeadSubmissions(string $professionalId): Generator
        {
            // Mirror the redaction in enquiries() — drop ip_hash + user_agent
            // (technical fingerprint, not user-visible lead data).
        ```
        ```php
        // stream() yields: metadata, profile, site, media.site_media, integrations,
        //   email_subscriptions, notification_preferences, audit
        // streamLeadSubmissions: zero call sites confirmed by Grep
        ```
