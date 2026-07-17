# Data Integrity & Privacy Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Data integrity & privacy: FK hygiene, soft-delete coherence, orphan rows, JSONB drift, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- supabase/migrations/20260704160000_shop_brands_products.sql
- supabase/migrations/20260705150200_create_content_selection.sql
- supabase/migrations/20260707030000_shop_brand_modes.sql
- supabase/migrations/20260705150000_workplaces_identity_columns.sql
- supabase/migrations/20260708124853_staff_audit_log_ip_hash_and_get_reads.sql
- supabase/migrations/20260701150000_create_workplaces.sql
- supabase/migrations/20260711000100_user_segments.sql
- supabase/migrations/20260526000000_baseline_standalone_user.sql
- app/Models/Core/EarlyAccess/EarlyAccessSignup.php
- app/Services/User/DataExport/DataExportPayloadBuilder.php
- app/Services/User/AccountDeletionService.php
- app/Models/Core/Site/SiteMedia.php
- app/Observers/Core/SiteMediaObserver.php
- app/Http/Controllers/Api/User/Uploads/UserUploadController.php
- app/Models/Core/Site/IntegrationConnection.php
- app/Http/Controllers/Api/Platforms/ShopController.php
- app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php
- app/Services/User/UserBootstrapService.php
- app/Console/Commands/PurgeSoftDeleted.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#DINT-1** · P1 — EarlyAccessSignup PII not wired into GDPR export or account-deletion purge
    - **Where:** app/Services/User/DataExport/DataExportPayloadBuilder.php:123-168 (sectionDescriptors — no `early_access_signups` entry); app/Services/User/AccountDeletionService.php:566-572 (purge() — no early-access step)
    - **Affects:** Any user who signed up for early access (via the OV-A-BE staff/marketing flow, `b43ecf38`) — their email, workplace/industry, platform choices, and consent metadata are never surfaced in a DSAR export and survive account deletion indefinitely.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add an `early_access_signups` section to `DataExportPayloadBuilder::sectionDescriptors()`, joined by the user's resolved email (mirror the existing `waitlist` section's pattern, which is email-keyed the same way).
        - Add a `purgeEarlyAccessSignups($lookupEmail)` step to `AccountDeletionService::purge()`, called alongside the existing `purgeWaitlistSignup($lookupEmail)` call, that hard-deletes `core.early_access_signups` rows matching the pre-pseudonymisation email.
    - **Technical:** `EarlyAccessSignup` has no `user_id` FK — rows are linked only by `email_lc`. `DataExportPayloadBuilder::sectionDescriptors()` is confirmed to be the single manifest of every export section (its docblock says so) and it enumerates `waitlist` but has no `early_access_signups` entry. `AccountDeletionService::purge()` runs `purgeExportZips`, `purgeWaitlistSignup`, `purgeFeedbackRows`, `purgeCaseSignalPii`, `purgeReportedUserEvidencePii`, `purgeGlobalEmailSubscriptions`, and `purgeCrossTenantSubscriptions` — all confirmed present, none touch `core.early_access_signups`. This table was added very recently (`20260711000300_early_access_signups.sql`, same day as this audit, part of `b43ecf38 feat(staff): staff accounts core — segments, availability, early access, invites`) — the GDPR wiring simply hasn't caught up yet. Under GDPR Article 15/17, the email + workplace/industry + platform choices stored here are personal data of the data subject and must be exportable and deletable.
    - **Plain English:** When someone signs up for early access, we collect their email and what industry they're in. If that same person later creates a full account and asks for their data export or account deletion, we hand over everything *except* the early-access record — it stays in the database forever with their personal info. It's like a filing cabinet where every drawer gets emptied when you move out, except the one labelled "waiting list."
    - **Evidence:**
        ```php
        // DataExportPayloadBuilder.php — the single manifest of every export section.
        // waitlist_signups is included; early_access_signups is absent.
        private function sectionDescriptors(User $professional, ?string $lookupEmail, ?string $siteId): array
        {
            return [
                // ...
                ['name' => 'waitlist', 'kind' => 'rows', 'resolve' => fn () => $this->streamWaitlistSignups($lookupEmail), ...],
                // NO 'early_access_signups' entry exists
                // ...
        ```
        ```php
        // AccountDeletionService.php — every purge step in order. No early-access step.
        $this->purgeExportZips($professional);           // #P2-08: R2 export ZIPs
        $this->purgeWaitlistSignup($lookupEmail);        // #P2-09: waitlist signup row
        $this->purgeFeedbackRows($professional);         // #P2-10: feedback (FK is SET NULL, not CASCADE)
        $this->purgeCaseSignalPii($professional);        // #P2-11: reporter PII on moderation signals
        $this->purgeReportedUserEvidencePii($professional); // PRIV-4: reported-user PII in evidence payload
        $this->purgeGlobalEmailSubscriptions($lookupEmail);    // #P2-12: global (user_id IS NULL) subscriptions
        $this->purgeCrossTenantSubscriptions($professional, $lookupEmail); // PRIV-7 Gap 1: other-user-owned rows matching this email
        // NO purgeEarlyAccessSignups($lookupEmail) call
        ```
        ```php
        // EarlyAccessSignup.php — PII columns that are never exported or purged
        protected $fillable = [
            'email',
            'email_lc',
            'type',
            'workplace_or_industry',
            'platforms',
            // ...
        ];
        ```

## P2 — Should fix

- [ ] **#DINT-2** · P2 — `core.users.primary_email` unique index is case-sensitive; the app's dedup guard is case-insensitive
    - **Where:** supabase/migrations/20260526000000_baseline_standalone_user.sql:363,367; app/Services/User/UserBootstrapService.php:122-137
    - **Affects:** Concurrent signups with the same email in different casing (e.g. `Bob@Example.com` vs `bob@example.com`) can create two `core.users` rows that the application logic treats as duplicates but the database does not.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `users_email_unique` with a case-insensitive partial unique index: `CREATE UNIQUE INDEX CONCURRENTLY users_email_lower_unique ON core.users (lower(primary_email)) WHERE (deleted_at IS NULL);` then drop the old `users_email_unique`.
        - Keep `guardAgainstEmailReuseByDifferentAuthUser()` as the user-friendly 409 path — the DB constraint becomes the final arbiter for the race window, matching the existing `users_auth_user_id_unique` / `core_users_handle_lc_unique` convention (both are already case/soft-delete-aware partial unique indexes on this same table).
    - **Technical:** The baseline already has `CREATE UNIQUE INDEX users_email_unique ON core.users (primary_email) WHERE (deleted_at IS NULL)` — so the general "no DB constraint at all" race DeepSeek described does not exist; a same-case concurrent duplicate INSERT is already rejected by Postgres. However, the index is on the raw `primary_email` column, not `lower(primary_email)` (only a non-unique search index, `users_email_search_idx`, uses `lower()`). `guardAgainstEmailReuseByDifferentAuthUser()` runs its pre-check with `whereRaw('lower(primary_email) = ?', ...)` inside the bootstrap transaction — so the app's notion of "duplicate" is case-insensitive while the DB's enforcement is case-sensitive. Two concurrent bootstrap calls for the same address in different casing (different Supabase `auth_user_id`s, e.g. a re-signup with a retyped email) can both pass the pre-check and both INSERT successfully, producing two `core.users` rows the app considers the same identity but the DB does not dedupe.
    - **Plain English:** We check the guest list at the door to make sure nobody with the same email (ignoring capital letters) is already inside. But the database's own lock-the-door mechanism only catches an *exact* letter-for-letter repeat — "Bob@Example.com" and "bob@example.com" look like two different names to it. Two signups arriving at the same instant with slightly different capitalization can both get through and create two separate accounts for what should be one person.
    - **Evidence:**
        ```sql
        CREATE UNIQUE INDEX users_email_unique ON core.users (primary_email) WHERE (deleted_at IS NULL);
        CREATE INDEX users_email_search_idx ON core.users (lower(primary_email));
        ```
        ```php
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

## P3 — Nice to have

- [ ] **#DINT-3** · P3 — `site.shop_brands` TEXT enum columns (`provider`, `selection_mode`, `link_mode`) have no DB CHECK constraint
    - **Where:** supabase/migrations/20260704160000_shop_brands_products.sql:13,21; supabase/migrations/20260707030000_shop_brand_modes.sql:19-22
    - **Affects:** Shop brand data — a direct DB write, buggy job, or migration error can insert an invalid `provider`, `selection_mode`, or `link_mode` value that the app's `toBrandArray()` coalesce logic wasn't written to expect.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `CHECK (selection_mode IN ('manual', 'latest'))` and `CHECK (link_mode IN ('product', 'checkout'))` to `site.shop_brands` — both are documented as closed, two-value vocabularies in the migration's own header comment.
        - Leave `provider` unconstrained if the provider list is expected to keep growing (adding a scraper = adding a provider), or add a CHECK plus a follow-up migration each time a provider is added — a call worth making explicitly rather than by omission.
    - **Technical:** Both migrations state "no CHECK constraints, matching the SQLite-test-mirror convention" as the deliberate rationale. That's a real, repeated architectural choice (also used for `site.sites.shop_link_mode`), not an oversight — but it stands in contrast to the sibling table `site.content_selection`, created the following day, which enumerates its own closed vocabulary with a DB `CHECK (entry_type IN (...))`. `selection_mode` and `link_mode` are both closed two-value sets per the migration's own comment (`'manual' or 'latest'`; `'product' or 'checkout'`), so a CHECK costs nothing at the SQLite-parity level test writers would need to special-case (a CHECK constraint that Postgres enforces and SQLite silently accepts is not a test-breaking difference — CHECK is standard SQLite syntax) and closes the same class of gap this codebase has already been bitten by (per project CLAUDE.md: prod-only CHECK/NOT NULL violations passing CI green on SQLite and then 500ing on Postgres, "bit the async Instagram connect twice").
    - **Plain English:** The database has text fields that are supposed to contain specific words like "manual" or "checkout." The database itself doesn't enforce this — it's like a form field with no validation on the server side. The app's front door checks your input, but if someone uses the side door (a migration, a background job, a direct query), they can write gibberish and the database will happily accept it. A sibling table built the very next week for a similar purpose *does* enforce this at the database level, so this is an inconsistency worth closing rather than a hard architectural constraint.
    - **Evidence:**
        ```sql
        -- provider — no CHECK:
        provider       text NOT NULL,
        ```
        ```sql
        -- selection_mode, link_mode — no CHECK, despite a closed 2-value vocabulary:
        ADD COLUMN IF NOT EXISTS selection_mode text NOT NULL DEFAULT 'manual',
        ADD COLUMN IF NOT EXISTS link_mode text NOT NULL DEFAULT 'product',
        ```
        ```text
        -- Migration comment confirms deliberate omission:
        "Values validated in the request layer (UpdateShopBrandRequest) — no CHECK
        constraints, matching the SQLite-test-mirror convention."
        ```

## Suggested Bundled Sessions

- **Bundle 1 — GDPR coverage for the new early-access table:** #DINT-1
    - **Why grouped:** single-file pair (export builder + deletion service), same root cause (new table shipped same-day without GDPR wiring).
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).
- **Bundle 2 — `core.users` email uniqueness hardening:** #DINT-2
    - **Why grouped:** single migration + single guard method; standalone anyway per the DB-migration rule below, listed here only for theming reference.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

- **#DINT-1 — EarlyAccessSignup PII not wired into GDPR export/deletion** · touches the account-deletion/export pipeline (PII handling) — run alone with its own plan + sign-off.
- **#DINT-2 — primary_email case-insensitive unique index** · DB migration (index replacement on a live table) — run alone with its own plan + sign-off.
- **#DINT-3 — shop_brands CHECK constraints** · DB migration (adds constraints to a live table) — run alone with its own plan + sign-off.
