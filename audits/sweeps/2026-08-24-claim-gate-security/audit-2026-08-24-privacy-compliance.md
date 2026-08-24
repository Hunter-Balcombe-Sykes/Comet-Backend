# Privacy & Data-Rights Compliance Audit — 2026-08-24

**Branch:** development
**Lens:** Privacy & data-rights compliance: PII inventory, export/delete completeness, retention enforcement, processor flows
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Models/Core/User/PreAccountBuild.php
- config/partna.php
- routes/console.php (cross-check only, per adjudication mandate)
- app/Services/User/DataExport/DataExportPayloadBuilder.php (cross-check only)
- app/Console/Commands/PurgeRawAnalyticsEvents.php (cross-check only)
- supabase/migrations/20260726000000_baseline_pilot.sql (cross-check only)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#PRIV-1** · P2 — Early-access applicant retention (730d) is enforced but its duration is unexplained relative to the platform's own shorter precedents (Cat 5)
    - **Where:** config/partna.php:1074-1082
    - **Affects:** People who submitted early-access interest but never signed up; their email + signup metadata sit in `early_access_signups` for up to two years.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either shorten `PARTNA_EARLY_ACCESS_RETENTION_DAYS` toward the platform's other free-form-PII precedent (90d, used for feedback and analytics), or add a one-line comment stating the specific operational/legal reason 730 days was chosen for this table specifically (the existing comment explains only why `signed_up` rows are excluded, not why non-converts get 2 years).
        - No enforcement change needed — `early-access:prune-old-signups` already runs weekly and does the deletion; this is a duration/justification gap, not a missing-job gap.
    - **Technical:** `config/partna.php` sets `'retention_days' => (int) env('PARTNA_EARLY_ACCESS_RETENTION_DAYS', 730)` with a comment justifying only the `signed_up` exclusion, not the 730-day figure itself. The same file documents a deliberate, reasoned 90-day window for feedback ("Deliberately SHORTER than the 365-day unsubscribed-subscription precedent… the minimisation argument is stronger") — early-access shows no equivalent reasoning for its far longer window on a comparably low-value, non-converting-visitor dataset. `early-access:prune-old-signups` is genuinely scheduled (`routes/console.php`, weekly Sunday 04:30 UTC) and enforces the value that exists, so this is a minimisation judgment call, not an enforcement gap.
    - **Plain English:** When someone expresses interest in Partna but never actually signs up, we keep their name and email for up to two years before deleting it — much longer than the 90 days we keep other similar throwaway data like feedback forms. The deletion job itself works fine; we just don't have a written reason for why this particular list gets four times longer than everything else like it.
    - **Evidence:**
        ```php
        'early_access' => [
            // PRIV-8: hard-delete non-converting applicant rows older than this window.
            // signed_up rows are excluded — those are governed by account deletion.
            'retention_days' => (int) env('PARTNA_EARLY_ACCESS_RETENTION_DAYS', 730),

            // CFG-1-style batch size for early-access:prune-old-signups — bounds each
            // DELETE's row count so the purge never holds one long-running transaction.
            'prune_batch_size' => (int) env('PARTNA_EARLY_ACCESS_PRUNE_BATCH_SIZE', 1000),
        ],
        ```

- [ ] **#PRIV-2** · P2 — Moderation reporter PII retention only covers resolved cases; a case that never resolves keeps a non-account reporter's name/message forever (Cat 4)
    - **Where:** config/partna.php:2594-2599; routes/console.php:422-432
    - **Affects:** Non-account members of the public who file a moderation report against a Partna sitepage; their name, contact detail, and free-text report text.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a second, longer-window prune pass (or extend `moderation:prune-resolved-signal-pii`) that also ages out non-account reporter PII on `case_signals` whose parent case is still open past a defined ceiling (e.g. 12–24 months stuck-open), separate from the 90-day resolved-case window.
        - Alternatively, document explicitly why open-case reporter PII is exempt from any bound (e.g. active investigation need) — silence is the finding, not the existence of a longer window.
    - **Technical:** `config/partna.php`'s `moderation.signal_pii_retention_days` (default 90) is scoped, by the code's own comment, to "case_signals whose parent case has been resolved." `routes/console.php` confirms: `moderation:prune-resolved-signal-pii` runs weekly and only touches resolved-case rows; the adjacent comment states "Account-reporter PII is handled at deletion time by AccountDeletionService::purgeCaseSignalPii()" — meaning coverage for an open case exists only if the reporter has an account AND that account is later deleted. A non-account reporter's PII attached to a case that stays open indefinitely (`open`/`triaged`/`under_review`) has no purge path at all.
    - **Plain English:** If someone without a Partna account reports a problem with a professional's page, and staff never formally close that report, that reporter's name and message stay in our system forever with no expiry date. Only reports attached to *closed* cases currently get cleaned up after 90 days.
    - **Evidence:**
        ```php
        'moderation' => [
            'enabled' => (bool) env('PARTNA_MODERATION_ENABLED', true),
            // DINT-6 / PRIV: retention window (days) for non-account reporter PII on
            // case_signals whose parent case has been resolved. Pruned weekly by
            // moderation:prune-resolved-signal-pii.
            'signal_pii_retention_days' => (int) env('PARTNA_MODERATION_SIGNAL_PII_RETENTION_DAYS', 90),
        ```
        ```php
        // DINT-6: weekly erasure of non-account reporter PII (reporter_email, reason_details,
        // signal_data) on case_signals whose parent case resolved more than 90 days ago.
        // Account-reporter PII is handled at deletion time by AccountDeletionService::purgeCaseSignalPii().
        Schedule::command('moderation:prune-resolved-signal-pii')
        ```

- [ ] **#PRIV-3** · P2 — Pre-account build IP hash uses unsalted SHA-256, which is reversible for the entire IPv4 address space (Cat 5)
    - **Where:** app/Models/Core/User/PreAccountBuild.php:25,77-80
    - **Affects:** Every visitor who triggers a site-first (pre-account) build — signup and staff-outreach flows both write `created_ip_hash`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Switch the hash function from unsalted `sha256(CF-Connecting-IP)` to an HMAC keyed with a server-side secret (e.g. `hash_hmac('sha256', $ip, config('app.key'))` or a dedicated pepper), so the value can no longer be reversed by brute-forcing the IPv4 space offline.
        - If the field is only ever used for pre-claim dedupe/fraud signals (its index is `WHERE claimed_at IS NULL`), consider whether it needs to persist at all once a build is claimed, rather than only fixing the hash strength.
    - **Technical:** The model docblock states the stored value is `sha256(CF-Connecting-IP)` — a single unsalted, unkeyed hash over a 32-bit input space. IPv4 has only 2^32 possible values, so a full-space precomputed table or live brute-force is trivial on commodity hardware; the "hash" provides no real protection against re-identifying the original IP. Under the Privacy Act APPs an IP address is personal information, and a reversible pseudonym is still personal information at rest — this is the canonical minimisation gap the lens calls out for IP capture.
    - **Plain English:** We store a scrambled version of a visitor's IP address for fraud checks. But the scrambling method we use is like a lock that anyone can pick in seconds by trying every possible combination — because there are only about 4 billion IP addresses, checking all of them is fast for a computer. It looks anonymised, but it isn't really. We should use a stronger, keyed method so the original IP can't be recovered from the stored value.
    - **Evidence:**
        ```php
         * @property string|null $created_ip_hash sha256(CF-Connecting-IP); NULL for staff-built rows (no visitor IP to hash).
        ```
        ```php
        protected $fillable = [
            'source_type', 'source_ref', 'source_ref_lc', 'built_via',
            'created_ip_hash', 'expires_at', 'contact_email', 'auto_invite',
        ];
        ```

## P3 — Nice to have

- [ ] **#PRIV-4** · P3 — `feedback:prune-old-submissions` scheduler comment states the wrong default retention window (Cat 7 doc drift)
    - **Where:** routes/console.php:434-436 vs config/partna.php:2379-2386
    - **Affects:** No runtime behavior — this is a documentation-only drift that could mislead a future reader into thinking feedback PII is kept longer than it actually is.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Fix the `routes/console.php` comment above `feedback:prune-old-submissions` to say "default 90d" (matching the actual `FEEDBACK_RETENTION_DAYS` default), or reference the config key instead of hardcoding a number in the comment so the two can't drift again.
    - **Technical:** `config/partna.php` sets `'retention_days' => (int) env('FEEDBACK_RETENTION_DAYS', 90)` with an explicit rationale comment ("90 days (Josh's call, 2026-07-20)… Deliberately SHORTER than the 365-day unsubscribed-subscription precedent"). The scheduler comment in `routes/console.php` describing the same command says "default 365d" — a stale figure, likely copy-pasted from the neighbouring `notifications:prune-unsubscribed-subscriptions` entry (365d). The actual enforced value is more privacy-protective than documented, so this is not a compliance risk, but it is exactly the kind of "config that lies" pattern this lens is built to catch, and it should not be left to confuse the next person who touches retention config.
    - **Plain English:** A code comment describing how long we keep customer feedback says "365 days," but the actual setting is 90 days. We're actually being more careful with people's data than the comment claims — but a comment that disagrees with the real setting is a paperwork error that should be fixed before someone relies on the wrong number.
    - **Evidence:**
        ```php
        // 90 days (Josh's call, 2026-07-20). Deliberately SHORTER than the
        // 365-day unsubscribed-subscription precedent: that table holds an email
        // and a flag, whereas this one holds free-text a user may have typed
        // anything into, alongside reply_email — so the minimisation argument is
        // stronger and the operational need to keep it is weaker. It also matches
        // analytics_raw_event_retention_days (90), the other free-form user-data
        // window in this file.
        'retention_days' => (int) env('FEEDBACK_RETENTION_DAYS', 90),
        ```
        ```php
        // PRIV-8: weekly hard-delete of core.feedback submissions older than the retention
        // window (default 365d, any triage status) — nothing else ages this table out.
        Schedule::command('feedback:prune-old-submissions')
        ```

## Suggested Bundled Sessions

- **Bundle 1 — PII minimisation & retention accuracy:** #PRIV-1, #PRIV-2, #PRIV-3, #PRIV-4
    - **Why grouped:** All four came out of the same config/partna.php + PreAccountBuild.php audit pass, are independent low-effort fixes (S–M) touching non-overlapping files, and share the "retention/minimisation hygiene" theme — no reason to split across sessions.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

None.
