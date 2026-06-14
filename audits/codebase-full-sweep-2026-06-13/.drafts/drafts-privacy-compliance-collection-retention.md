- [ ] **#PRIV-1** · P1 — Moderation evidence snapshots embed reported-user PII with no account-deletion path
    - **Where:** app/Services/Moderation/EvidenceSnapshotService.php:80-96
    - **Affects:** Any professional whose sitepage is reported — their handle, display_name, and bio are captured into an immutable `moderation.evidence` JSONB row that survives their account deletion.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `moderation.evidence` to the account-deletion purge path — either hard-delete rows owned by the deleted user's `reportable_owner_user_id`, or redact the PII keys from the JSONB payload.
        - Document the retention rationale for evidence rows that belong to *other* users' reports (reporter-triggered retention).
    - **Technical:** `EvidenceSnapshotService::snapshotSite()` eagerly loads `$site->user` and writes `handle`, `display_name`, and `bio` into the evidence payload. The evidence table has no `deleted_at` column (no SoftDeletes) and does not appear in `PurgeSoftDeleted::PURGE_HANDLED` or any other retention sweep visible in `routes/console.php`. When a professional deletes their account, the moderation evidence rows that captured their PII remain — an erasure-rights gap. The content report was filed by a third party, but the *subject's* PII is platform-held data and must be accounted for.
    - **Plain English:** If someone reports a professional's page, we take a permanent photo of what the page looked like — including the professional's name, handle, and bio. If that professional later deletes their account, the photo stays in our filing cabinet forever. The professional has a right to ask for that cabinet to be cleaned out too, and we have no way to do it today.
    - **Evidence:**
        ```php
        // EvidenceSnapshotService.php:80-96
        private function snapshotSite(string $siteId): array
        {
            $site = Site::query()->with(['user', 'blocks'])->findOrFail($siteId);

            return [
                'site_id' => $site->id,
                'site_subdomain' => $site->subdomain ?? null,
                'user_id' => $site->user_id,
                'handle' => $site->user?->handle ?? null,
                'display_name' => $site->user?->display_name ?? null,
                'bio' => $site->user?->bio ?? null,
                'block_count' => $site->blocks?->count() ?? 0,
                'block_types' => $site->blocks?->pluck('block_type')->all() ?? [],
            ];
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#PRIV-2** · P1 — Handle-audit retention (7 years) declared in config with no enforcement job scheduled
    - **Where:** config/partna.php (handle.audit_retention_years) + routes/console.php (no corresponding schedule entry)
    - **Affects:** `core.user_handle_aliases` and any handle-change audit log — rows accumulate unboundedly, contradicting the declared 7-year retention policy.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a scheduled command (e.g., `handles:prune-audit-logs`) that hard-deletes handle-audit rows older than 7 years.
        - Register it in `routes/console.php` with the standard `onOneServer()` / `withoutOverlapping()` / `runInBackground()` / `onFailure()` pattern.
        - Log the count of pruned rows (not contents) so compliance is verifiable.
    - **Technical:** `config('partna.handle.audit_retention_years')` declares a 7-year retention policy for handle-change audit logs. `routes/console.php` schedules `handles:prune-expired-aliases` (which cleans up expired redirect aliases) and `handles:notify-expiry` (which emails about upcoming alias expiry), but neither prunes audit-log rows that have passed the 7-year mark. A config value that declares a retention policy without a scheduled enforcer is "config that lies" — the data accumulates forever regardless of what the config says. Under Australian Privacy Act APP 11.2, an entity must take reasonable steps to destroy or de-identify personal information once it is no longer needed — an unbounded audit log with a declared-but-unenforced retention window fails that test.
    - **Plain English:** We tell our lawyers and our privacy policy that we keep handle-change records for 7 years and then delete them. But nobody actually takes out the trash — the records just pile up forever. If we ever need to prove we delete old data on schedule, we can't, because the scheduled cleanup job doesn't exist.
    - **Evidence:**
        ```php
        // config/partna.php
        'handle' => [
            // …
            // Years to retain handle_change_log rows. 7y matches typical fraud-investigation retention.
            'audit_retention_years' => (int) env('SIDEST_HANDLE_AUDIT_RETENTION_YEARS', 7),
        ],
        ```
        ```php
        // routes/console.php — handles:prune-expired-aliases exists…
        Schedule::command('handles:prune-expired-aliases')
            ->dailyAt('03:15')
            // …
        // …but no handles:prune-audit-logs or equivalent appears anywhere in the file.
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#PRIV-3** · P2 — Analytics referrer stored with full query string — PII minimisation gap compared to lead-submission pipeline
    - **Where:** app/Services/Analytics/Writers/PostgresEventWriter.php:140-155 (visitRow) + app/Services/Analytics/AnalyticsEvent.php:59 (referrer property)
    - **Affects:** Every visitor to every public sitepage — their Referer header (potentially containing UTM parameters with email addresses, search terms, or other PII) is stored verbatim in `analytics.site_visits`, `analytics.link_clicks`, and `analytics.section_views`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Strip query-string and fragment from the referrer before writing to analytics tables, matching the existing `LogLeadRateLimits::sanitizeReferrer()` implementation (origin + path only, capped at 512 chars).
        - Apply the sanitisation in `PostgresEventWriter::visitRow()` / `clickRow()` / the section-view row builder, or upstream in the controller that constructs the `AnalyticsEvent`.
    - **Technical:** The `LogLeadRateLimits` middleware already strips query strings from the Referer header before writing to `analytics.lead_submissions`, with an explicit comment that "Query strings from marketing tools routinely embed subscriber emails / UTM PII." But the main analytics pipeline — which handles every pageview, click, and section-view — stores the referrer field from `AnalyticsEvent` verbatim with no sanitisation. `PostgresEventWriter` writes `$e->referrer` directly into Postgres. An Instagram ad click with `?utm_source=instagram&utm_medium=paid&utm_campaign=launch&utm_content=user%40example.com` lands the visitor's email address in the analytics warehouse, retained for 90 days — a clear minimisation gap the platform already knows how to fix (the fix is implemented, just not applied here).
    - **Plain English:** When someone clicks a link to a Partna sitepage, we record where they came from — helpful for the professional's dashboard. But sometimes that "where" includes things like email addresses or search terms accidentally baked into the link by the sender. Our lead-submission pipeline already strips those out before saving. The main analytics pipeline — which handles every single page visit — forgets to do the same thing. It's like having a security camera that blurs faces at the front door but leaves the lobby camera on full resolution.
    - **Evidence:**
        ```php
        // PostgresEventWriter.php — referrer stored verbatim
        private function visitRow(AnalyticsEvent $e): array
        {
            return [
                // …
                'referrer' => $e->referrer,
                // …
            ];
        }
        ```
        ```php
        // LogLeadRateLimits.php — the SAME concern, already handled correctly
        // "Query strings from marketing tools routinely embed subscriber emails /
        // UTM PII — keeping only origin + path retains forensic value without
        // the GDPR retention burden."
        private function sanitizeReferrer(?string $referer): ?string
        {
            // … parse_url, strip query + fragment, cap at 512 chars
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#PRIV-4** · P2 — Moderation evidence table has no retention policy or scheduled purge — unbounded PII accumulation
    - **Where:** app/Services/Moderation/EvidenceSnapshotService.php (writes) + routes/console.php (no scheduled purge) + config/partna.php (no retention value)
    - **Affects:** Every professional whose sitepage is reported — their PII snapshot lives in `moderation.evidence` indefinitely, even after the case is resolved and the retention purpose has expired.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `moderation.evidence_retention_days` config value (e.g., matching the 7-year handle-audit baseline, or shorter if the legal basis is case-resolution only).
        - Add a scheduled command that hard-deletes evidence rows belonging to resolved cases older than the retention window.
        - Register it in `routes/console.php` and log purged counts.
    - **Technical:** The `EvidenceSnapshotService` creates rows in `moderation.evidence` containing JSONB payloads with `handle`, `display_name`, `bio`, and other PII. `ModerationCase` has a `resolved_at` timestamp that could anchor a retention clock, but there is no scheduled job in `routes/console.php` that prunes evidence for long-resolved cases. The `PurgeSoftDeleted` command does not list any moderation models. Without a retention rule and enforcer, evidence payloads accumulate forever — unnecessary retention is itself a Privacy Act APP 11.2 concern ("destroy or de-identify personal information once no longer needed").
    - **Plain English:** When someone reports a page, we take a snapshot of the evidence so staff can review it. After the case is closed, that snapshot has served its purpose. But we never throw it away — it sits in the database forever. Under Australian privacy law, holding onto personal data longer than you need to is its own kind of problem, separate from whether the data is secure. We need a retention policy and an automatic cleanup for old, resolved evidence.
    - **Evidence:**
        ```php
        // EvidenceSnapshotService.php — PII written to evidence payload with no expiry
        return Evidence::forceCreate([
            'id' => (string) Str::uuid(),
            'case_id' => $caseId,
            'signal_id' => $signalId,
            'evidence_type' => 'content_snapshot',
            'payload' => $payload,   // contains handle, display_name, bio
            'content_hash' => $contentHash,
        ]);
        ```
        ```php
        // PurgeSoftDeleted.php — no moderation model listed
        public const PURGE_HANDLED = [
            Customer::class,
            Service::class,
            SiteMedia::class,
            Enquiry::class,
            ServiceCategory::class,
            Block::class,
            Feedback::class,
            SmartLink::class,
            IntegrationConnection::class,
            // Moderation\Evidence is absent
        ];
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#PRIV-5** · P2 — User-agent strings stored verbatim in every analytics table without minimisation
    - **Where:** app/Services/Analytics/Writers/PostgresEventWriter.php (visitRow, clickRow, appendSectionRow, upsertSession) + app/Http/Middleware/Logging/LogLeadRateLimits.php:81
    - **Affects:** Every visitor to every public sitepage — their full User-Agent string is stored in `analytics.site_visits`, `analytics.link_clicks`, `analytics.section_views`, `analytics.site_sessions`, and `analytics.lead_submissions`. Combined with IP hash and visitor ID, this forms a strong browser fingerprint retained for 90 days.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Truncate user-agent to a fixed length (e.g., 256 characters) or extract only the browser family + OS (e.g., "Chrome 125 / Windows") using a server-side parser.
        - Apply the same truncation/extraction consistently across all five analytics tables + the lead-submission path.
    - **Technical:** `AnalyticsEvent` carries `userAgent` as a nullable string with no length constraint. `PostgresEventWriter` writes it verbatim into every analytics row. User-agent strings routinely exceed 500 characters on modern browsers and, combined with IP hash + visitor ID, uniquely re-identify visitors across sessions. The analytics tables already have a 90-day retention window (enforced by `PurgeRawAnalyticsEvents`), so the data is not retained forever, but APP 3.4 requires that collection be limited to what is "reasonably necessary." A device-type enum (`desktop`/`mobile`/`other`) is already derived and stored in `device_type` — the raw user-agent is not needed for analytics and constitutes over-collection. The fix is low-effort: truncate at the ingest boundary.
    - **Plain English:** Every time someone visits a Partna sitepage, we save the full "fingerprint" of their browser — a long string that, combined with other data we collect, can uniquely identify them across different sites and sessions. We only actually use a simple "desktop or mobile" label for the dashboard charts. Keeping the full fingerprint is like taking a photocopy of someone's driver's license when all you needed was "over 18." Australian privacy law says only collect what you reasonably need.
    - **Evidence:**
        ```php
        // PostgresEventWriter.php — user_agent stored verbatim in every table
        private function visitRow(AnalyticsEvent $e): array
        {
            return [
                // …
                'user_agent' => $e->userAgent,   // verbatim, no truncation
                // …
            ];
        }

        private function clickRow(AnalyticsEvent $e): array
        {
            return [
                // …
                'user_agent' => $e->userAgent,   // same
                // …
            ];
        }
        ```
        ```php
        // LogLeadRateLimits.php — also verbatim
        LeadSubmission::query()->create([
            // …
            'user_agent' => $request->userAgent(),   // verbatim
            // …
        ]);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#PRIV-6** · P3 — `EnquirySpamBlocklist` hashed-email entries survive account deletion until natural TTL expiry
    - **Where:** app/Services/Notifications/EnquirySpamBlocklist.php:16-36
    - **Affects:** Professionals who delete their account — their per-user spam-blocklist Redis sorted set (keyed by `user_id`) persists for up to 90 days after the account is gone.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a Redis `DEL` of the `enquiry_spam:{userId}` key to the account-deletion purge path.
        - Alternatively, document the 90-day auto-expiry as a deliberate grace period and accept the residual data.
    - **Technical:** `EnquirySpamBlocklist` stores HMAC-SHA256-hashed email addresses in a Redis sorted set keyed `enquiry_spam:{userId}` with a 90-day TTL. The entries are hashed with the app key, so the raw email is not recoverable from Redis alone, but the data is still user-associated (the key contains the `user_id`). When a professional deletes their account via `AccountDeletionService`, the `PurgeSoftDeleted` command handles DB rows and media but does not clean up Redis keys. The Redis entries auto-expire after 90 days, so this is a bounded leak rather than permanent retention. Low risk due to the HMAC hashing, but worth noting for completeness of the deletion ledger.
    - **Plain English:** When a professional blocks a spammer's email address, we store a scrambled version of that email in a list tied to their account. If they delete their account, that list hangs around in our cache for up to 90 days until it expires naturally. Because the emails are scrambled, nobody can read them — but they're still technically tied to a deleted account. This is a small loose end, not an emergency.
    - **Evidence:**
        ```php
        // EnquirySpamBlocklist.php
        private function key(string $userId): string
        {
            return "enquiry_spam:{$userId}";  // keyed by user_id, TTL 90 days
        }

        public function addWithExpiry(string $userId, string $email, int $expiresAt): void
        {
            $key = $this->key($userId);
            $member = $this->hash($email);    // HMAC-SHA256 with app key
            Redis::zadd($key, $expiresAt, $member);
            // …
            Redis::expire($key, self::TTL_DAYS * 86400);  // 90-day TTL
        }
        ```
    - `[DRAFT, confidence: 0.8]`
