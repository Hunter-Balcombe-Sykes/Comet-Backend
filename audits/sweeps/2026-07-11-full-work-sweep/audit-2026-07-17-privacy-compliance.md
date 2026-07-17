# Privacy & Data-Rights Compliance Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Privacy & data-rights compliance — PII inventory completeness, export/delete completeness, retention enforcement, minimisation at collection, processor/third-party flows, and staff access auditing, evaluated against the account-deletion, GDPR-export, and signup-bootstrap machinery.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Resources/PublicSite/IndividualProfileResource.php
- app/Http/Resources/UserDashboardResource.php
- app/Models/Core/Site/Menu.php
- app/Models/Core/Site/MenuItem.php
- app/Models/Core/Site/Site.php
- app/Models/Core/User/User.php
- app/Services/User/AccountDeletionService.php
- app/Services/User/SiteProvisioningService.php
- app/Services/User/UserBootstrapService.php
- app/Console/Commands/CleanupOrphanedLifestyleConnections.php
- app/Mail/Branding/EmailBrandDefaults.php
- app/Services/Analytics/ContentFreshness.php
- supabase/migrations/20260711170000_users_email_unique_case_insensitive.sql
- supabase/migrations/20260712000000_retire_staff_account_type.sql
- supabase/migrations/20260713120000_reconcile_instagram_gallery_unification.sql
- supabase/migrations/20260714200000_architecture_one_to_staple.sql
- supabase/migrations/20260714210000_drop_effect_surface.sql
- supabase/migrations/20260714220000_add_aesthetic_axes.sql
- supabase/migrations/20260714230000_drop_glass_satellites.sql
- supabase/migrations/20260715090000_menu_item_currency_and_dining_modes.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **PRIV-1** · P2 — New-signup marketing subscription is created with no genuine consent signal
    - **Where:** app/Services/User/UserBootstrapService.php:118 (`bootstrap()`), `ensureSidestUpdatesSubscription()` at lines 165-187
    - **Affects:** Every new professional who signs up via `POST` bootstrap — their email is enrolled in the `sidest_updates` marketing list as an automatic side effect of account creation, not a separate opt-in.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add an explicit, validated consent field (e.g. `marketing_opt_in: bool`) to `BootstrapRequest`, sourced from a real checkbox/toggle on the signup form, and pass it into `bootstrap()`.
        - Gate `ensureSidestUpdatesSubscription()` on that flag instead of calling it unconditionally; only write the row when the user affirmatively opted in.
        - Keep `consent_source` accurate post-fix (`'signup_optin'` or similar) so the column continues to double as an audit trail of how consent was obtained.
    - **Technical:** `UserBootstrapService::bootstrap()` calls `$this->ensureSidestUpdatesSubscription($professional->primary_email)` unconditionally inside the signup transaction, for both the create and update branches. The method performs `EmailSubscription::insertOrIgnore(['list_key' => 'sidest_updates', 'status' => 'subscribed', 'consent_source' => 'bootstrap', ...])` with no guard clause checking whether any consent was given — `insertOrIgnore` only prevents duplicate rows, it doesn't gate on opt-in. The `consent_source = 'bootstrap'` value is itself an admission that consent was inferred from account creation, not obtained. Under the Australian Privacy Act (APP 7), direct-marketing enrolment generally requires the individual's consent or a reasonable expectation tied to the primary purpose of collection — "we signed you up because you registered" does not clear that bar. Note this is a genuine right-to-export item, not a silent gap: `DataExportPayloadBuilder::streamEmailSubscriptions()` does surface `notifications.email_subscriptions` rows in the user's own export, and `AccountDeletionService::purgeGlobalEmailSubscriptions()` does delete the row on account purge — so the export/deletion ledgers are intact for this store. The defect is purely at collection time (APP 3/7 minimisation), which is why this stays P2 rather than P1 under the lens's own tiering ("P2 for minimisation and processor-hygiene gaps").
    - **Plain English:** Imagine signing up for a library card and discovering you've also been signed up for the library's weekly newsletter — without ever being asked. Right now, creating a Partna account automatically adds your email to a marketing mailing list in the same database transaction that creates your account; there's no tick-box, no separate step, nothing to decline. Australian privacy law expects people to actively agree before being added to a marketing list, not to have it bundled invisibly into signing up. The fix is a real opt-in checkbox on the signup form that the backend actually checks before subscribing anyone — good news is your existing "view my data" and "delete my account" tools already handle this subscription correctly once it's created, so this is a collection-time fix, not a bigger rebuild.
    - **Evidence:**
        ```php
        // UserBootstrapService::bootstrap() — inside the transaction, unconditional:
        $this->ensureSidestUpdatesSubscription($professional->primary_email);

        // ...

        private function ensureSidestUpdatesSubscription(?string $email): void
        {
            $email = is_string($email) ? strtolower(trim($email)) : '';
            if ($email === '') {
                return;
            }

            $now = now();

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

- [ ] **PRIV-2** · P2 — Staff `admin_notes` freetext survives pseudonymisation for the full 30-day deletion grace period
    - **Where:** app/Services/User/AccountDeletionService.php:299-314 (`pseudonymiseAccountPii()`)
    - **Affects:** Any individual whose account enters `pending_deletion` — freetext support/identity-verification notes staff previously wrote about them remain live and staff-readable in cleartext for up to 30 days after the user pseudonymised their own account.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'admin_notes' => null` to the `forceFill()` array in `pseudonymiseAccountPii()`, alongside the other 10 fields already redacted there.
        - If support staff need historical context during the grace period, snapshot `admin_notes` into `UserDeletionAuditEntry::metadata` (the same pattern already used for the email snapshot) before nulling the live column, rather than leaving the live column readable.
    - **Technical:** `pseudonymiseAccountPii()` explicitly overwrites `phone`, `primary_email`, `first_name`, `last_name`, `public_contact_email`, `public_contact_number`, and all five `location_*` columns the moment a deletion is confirmed — but `admin_notes` (`core.users.admin_notes`, `text`, fillable, DB comment: "Staff-only free-text notes. Exposed via the staff resource only — never through /me") is absent from that list. `UserStaffResource` (`app/Http/Resources/UserStaffResource.php:35`) serialises `admin_notes` verbatim to any staff endpoint using that resource, so during the entire `pending_deletion` window any staff member with normal (non-elevated) access can still read whatever PII was recorded there — commonly identity-verification details, phone numbers read back for support, or dispute notes. This isn't a permanent gap: `core.users` is force-deleted along with everything else at the 30-day hard purge in `AccountDeletionService::purge()`, so `admin_notes` does eventually disappear — the defect is that it's the only fillable, PII-bearing column on the row *not* redacted at the confirm-time pseudonymisation step that every other identity field goes through immediately.
    - **Plain English:** When someone asks to delete their account, we immediately scramble their phone number, email, name, and address so nobody can read them during the 30-day cooling-off period. But there's a staff-only notepad attached to every account — support agents write things like verification details or call notes there — and that notepad is left completely untouched. For up to 30 days after someone requests deletion, any staff member can still open that notepad and read it in plain text. The fix is to blank that notepad at the same moment we scramble everything else, or copy a note into the secure deletion log first if support genuinely needs the history.
    - **Evidence:**
        ```php
        protected function pseudonymiseAccountPii(User $professional): void
        {
            $professional->forceFill([
                'phone' => 'redacted',
                'primary_email' => "deleted+{$professional->id}@partna.au",
                'first_name' => 'Deleted',
                'last_name' => null,
                'public_contact_email' => null,
                'public_contact_number' => null,
                'location_street_address' => null,
                'location_postcode' => null,
                'location_city' => null,
                'location_state' => null,
                'location_country' => null,
            ])->save();
        }
        ```

## P3 — Nice to have

- [ ] **PRIV-3** · P3 — Internal cleanup command writes user handles to log/console output unnecessarily
    - **Where:** app/Console/Commands/CleanupOrphanedLifestyleConnections.php:51-57
    - **Affects:** Users whose account has an orphaned lifestyle-integration connection cleaned up by this one-shot remediation command — their handle (an indirect identifier) is written to console output and, depending on the deployment's stdout capture, to Laravel Cloud log storage.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$user->handle ?? '(no handle)'` with `$user->id` in the `$this->line()` call — the UUID alone is sufficient for operator traceability.
    - **Technical:** The command's per-user progress line interpolates `$user->handle` directly (`'%s %s (%s): %d connection(s)'` with handle as the second `%s`), in addition to already including `$user->id`. The handle adds no diagnostic value beyond the UUID and unnecessarily widens the PII surface of console/log output for what the class docblock itself describes as a "one-shot data remediation" tool. This is a minor, low-traffic finding — the command is not scheduled in `routes/console.php` (confirmed: no reference found there), so it only runs on manual invocation, and `$user->handle` is already a public-facing identifier (it's literally the subdomain), not a sensitive one. The fix is a one-line swap.
    - **Plain English:** When this cleanup tool runs, it prints out affected users' public usernames into its output, which can end up in system logs. Anyone who later pulls those logs — for a security review or an audit — gets a needlessly detailed list tying usernames to a specific internal operation. The user's ID number alone tells the operator the same story without adding an extra personal-identifier trail to log storage.
    - **Evidence:**
        ```php
        $this->line(sprintf(
            '%s %s (%s): %d connection(s)',
            $dryRun ? 'would remove' : 'removed',
            $user->handle ?? '(no handle)',
            $user->id,
            $count,
        ));
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Account-lifecycle PII hygiene:** PRIV-1, PRIV-2
    - **Why grouped:** both live in `App\Services\User` and touch the same account lifecycle (signup / deletion) that the professional's PII flows through; small, independent fixes that don't interact with each other.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Ops-tooling log hygiene:** PRIV-3
    - **Why grouped:** standalone one-line fix in a console command, unrelated subsystem to Bundle 1 — kept separate so it doesn't block on the signup-consent design discussion.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet (combine plan+implement given trivial size).

## Standalone — do NOT bundle

None — no finding in this audit is P0, touches auth/authorization or money, involves a DB migration/schema change, or is L/XL effort.
