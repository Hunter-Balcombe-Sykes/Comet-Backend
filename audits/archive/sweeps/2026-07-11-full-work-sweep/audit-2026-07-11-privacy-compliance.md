# Privacy & Data-Rights Compliance Audit — 2026-07-12

**Branch:** HEAD
**Lens:** Privacy & data-rights compliance: PII inventory, export/delete completeness, retention enforcement, processor flows (bundle: rights-machinery + collection-retention-1/2 + schema-pii chunks)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- `app/Services/User/AccountDeletionService.php`
- `app/Services/User/DataExport/DataExportPayloadBuilder.php`
- `app/Models/Core/EarlyAccess/EarlyAccessSignup.php`
- `app/Services/Audit/StaffAuditService.php`
- `app/Services/Moderation/EvidenceSnapshotService.php`
- `app/Services/Analytics/Writers/PostgresEventWriter.php`
- `app/Services/Analytics/AnalyticsEventSanitizer.php`
- `app/Http/Controllers/Concerns/DetectsClientInfo.php`
- `app/Http/Resources/WorkplaceResource.php`
- `app/Models/Core/User/User.php`
- `app/Console/Commands/PruneNotifications.php`
- `app/Console/Commands/PurgeRawAnalyticsEvents.php`
- `config/partna.php`
- `routes/console.php`
- `routes/api/staff.php`
- `supabase/migrations/20260711000300_early_access_signups.sql`
- `supabase/migrations/20260705150000_workplaces_identity_columns.sql`
- `supabase/migrations/20260705150100_users_sector_columns.sql`
- `supabase/migrations/20260704160000_shop_brands_products.sql`
- `supabase/migrations/20260711153000_feedback_type_area_target.sql`
- `supabase/migrations/20260706000000_add_city_to_site_visits.sql`
- `supabase/migrations/20260707020000_site_visits_lat_lon.sql`
- `supabase/migrations/20260526000000_baseline_standalone_user.sql`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 4 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 4 complete

---

## P1 — Fix before pilot launch

- [ ] **PRIV-1** · P1 — Early-access signup PII survives account deletion and is invisible to a data-subject export
    - **Where:** `app/Services/User/AccountDeletionService.php:566-572` (purge) and `app/Services/User/DataExport/DataExportPayloadBuilder.php:127-169` (sectionDescriptors)
    - **Affects:** Every early-access signup (`core.early_access_signups`) who later creates and deletes an account — their pre-account email, workplace/industry, invite metadata, and hashed consent telemetry both persist forever after deletion and never appear in a DSAR.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `purgeEarlyAccessSignup($lookupEmail)` to `AccountDeletionService`, matching the existing `purgeWaitlistSignup()` pattern (delete-by-`email_lc`, no FK to cascade through), and call it from `purge()`.
        - Add a `streamEarlyAccessSignups($lookupEmail)` generator to `DataExportPayloadBuilder`, registered as a new `early_access` entry in `sectionDescriptors()`, mirroring `streamWaitlistSignups()`'s email-join + technical-fingerprint redaction (drop `consent_ip_hash`/`consent_user_agent`/`invite_token_hash`).
    - **Technical:** `core.early_access_signups` (added in the OV-A early-access feature, migration `20260711000300_early_access_signups.sql`, shipped in `b43ecf38 feat(staff): staff accounts core ... early access, invites`) has no `user_id` FK — it's joined only by `email_lc`, exactly like `core.waitlist_signups`. `AccountDeletionService::purge()` already handles every other email-keyed store this way (`purgeWaitlistSignup`, `purgeGlobalEmailSubscriptions`, `purgeCrossTenantSubscriptions`) but has no equivalent call for early access, and `DataExportPayloadBuilder::sectionDescriptors()` has a `waitlist` entry but no `early_access` entry. Both gaps share the same root cause (a new email-keyed pre-account PII table that wasn't wired into either ledger) and should be fixed together.
    - **Plain English:** When someone signs up for early access before creating a real account, we keep a record of their email, what they do for work, and consent details. If they later join and then delete their account, we clean up the old waitlist entry but forget the near-identical early-access entry — it's like shredding one filing folder but leaving an identical one in the next drawer. And if that same person asks "send me everything you have on me," that folder doesn't get included in the package either.
    - **Evidence:**
        ```php
        // AccountDeletionService::purge() — waitlist is purged, early access is not:
        $this->purgeExportZips($professional);           // #P2-08: R2 export ZIPs
        $this->purgeWaitlistSignup($lookupEmail);        // #P2-09: waitlist signup row
        $this->purgeFeedbackRows($professional);         // #P2-10: feedback (FK is SET NULL, not CASCADE)
        $this->purgeCaseSignalPii($professional);        // #P2-11: reporter PII on moderation signals
        $this->purgeReportedUserEvidencePii($professional); // PRIV-4: reported-user PII in evidence payload
        $this->purgeGlobalEmailSubscriptions($lookupEmail);    // #P2-12: global (user_id IS NULL) subscriptions
        $this->purgeCrossTenantSubscriptions($professional, $lookupEmail); // PRIV-7 Gap 1: other-user-owned rows matching this email
        // No purgeEarlyAccessSignup() call.
        ```
        ```php
        // DataExportPayloadBuilder::sectionDescriptors() — waitlist has a section, early access does not:
        ['name' => 'waitlist', 'kind' => 'rows', 'resolve' => fn () => $this->streamWaitlistSignups($lookupEmail), 'csv_columns' => [...]],
        // ... no 'early_access' entry anywhere in the ~24-entry array.
        ```
        ```sql
        -- supabase/migrations/20260711000300_early_access_signups.sql
        CREATE TABLE IF NOT EXISTS core.early_access_signups (
            id                    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            email                 text NOT NULL,
            email_lc              text NOT NULL,
            ...
            consent_ip_hash       text,
            consent_user_agent    text,
            CONSTRAINT early_access_signups_email_lc_unique UNIQUE (email_lc)
        );
        ```

- [ ] **PRIV-2** · P1 — 7-year handle-audit retention is declared in config but no job enforces it
    - **Where:** `config/partna.php:56` (`handle.audit_retention_years`) and `routes/console.php` (no matching `Schedule::command()`)
    - **Affects:** Every row in `audit.handle_change_log` — every historical handle a professional has ever held, tied to their identity, retained forever with no expiry mechanism.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `handles:prune-audit-logs` command that hard-deletes `audit.handle_change_log` rows older than `config('partna.handle.audit_retention_years')` years.
        - Register it in `routes/console.php` on a daily cadence with `onOneServer()`, `withoutOverlapping()`, and the shared `$reportScheduledFailure` handler, and have it log the deleted row count (not contents).
    - **Technical:** `config('partna.handle.audit_retention_years')` defaults to 7 with the comment "matches typical fraud-investigation retention," but `routes/console.php` contains no command reading that key or targeting `audit.handle_change_log`. The two handle-related scheduled commands present (`handles:prune-expired-aliases`, `handles:notify-expiry`) both operate on the *alias* lifecycle (the 90-day `redirect_days` window via `->active()` scope), not the audit log. This is a declared retention rule with zero enforcement — the config lies about what actually happens to the data.
    - **Plain English:** The platform promises to keep a permanent log of every handle change for 7 years and then delete it. Nobody wrote the cleanup job. In practice every handle-change record — including handles from years ago — sits in the database forever, with no code anywhere that would ever remove it. If a regulator or a user asks "what old handles do you still hold on me," the honest answer today is "all of them, indefinitely," which contradicts our own stated policy.
    - **Evidence:**
        ```php
        // config/partna.php
        // Years to retain handle_change_log rows. 7y matches typical fraud-investigation retention.
        'audit_retention_years' => (int) env('SIDEST_HANDLE_AUDIT_RETENTION_YEARS', 7),
        ```
        ```php
        // routes/console.php — the only two handle-related schedule entries; neither touches handle_change_log
        Schedule::command('handles:prune-expired-aliases')->dailyAt('03:15')...
        Schedule::command('handles:notify-expiry')->dailyAt('09:00')...
        ```

- [ ] **PRIV-3** · P1 — Platform integration connections entirely absent from GDPR export
    - **Where:** `app/Services/User/DataExport/DataExportPayloadBuilder.php:245-249` (`streamIntegrations`)
    - **Affects:** Any professional with connected platforms (Instagram, YouTube, Spotify, shop, etc.) — their stored platform usernames, profile data, and connection metadata never appear in a DSAR.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the no-op `streamIntegrations()` with a real generator streaming `site.platform_connections` rows scoped to `user_id`.
        - Add an explicit CSV column allow-list in `sectionDescriptors()`, excluding internal-only `payload` keys (`refresh_etag`, `apify_status`, `refresh_last_modified`) that aren't user-facing data.
    - **Technical:** `DataExportPayloadBuilder::streamIntegrations()` yields nothing, with the comment "No integrations for individual-standalone accounts." That premise is false today: `IntegrationConnection` (`site.platform_connections`), `PublicIntegrationConnectionResource`, and ~20 platform controllers are all active individual-account features (per the platform registry work shipped through `2ad3d7cb`/`973303c5`). `payload` stores platform usernames, profile data, follower counts, and business categories — personal data the professional supplied. This is a stale guard from a pre-pivot era that was never updated after the individual-only pivot.
    - **Plain English:** A professional connects their Instagram and Spotify to their Partna page, then asks for a copy of everything we hold on them. We send their profile and photos but silently skip every connected account — because a leftover comment in the code still says "individual accounts don't have integrations," which hasn't been true for a while. The checklist needs to catch up to the product.
    - **Evidence:**
        ```php
        private function streamIntegrations(string $userId): Generator
        {
            // No integrations for individual-standalone accounts; yield nothing.
            yield from [];
        }
        ```

- [ ] **PRIV-4** · P1 — Site analytics (visits, clicks, section views, item views) entirely absent from GDPR export
    - **Where:** `app/Services/User/DataExport/DataExportPayloadBuilder.php:123-169` (`sectionDescriptors` — no analytics entries)
    - **Affects:** Every professional requesting a DSAR — their business traffic analytics (visit counts, referrers, UTM data, geo breakdowns, device splits, link-click destinations) is invisible to the export, despite it being the professional's own business data collected from their sitepage.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `streamSiteVisits()`, `streamLinkClicks()`, `streamSectionViews()`, and `streamItemViews()` generators scoped by `user_id`, registered under an `analytics` group in `sectionDescriptors()`.
        - Redact visitor-identifying columns (`ip_hash`, `visitor_id`, `session_id`) in the export — the professional is entitled to their own aggregate business data, not a bulk transfer of third-party visitor fingerprints.
    - **Technical:** `sectionDescriptors()` enumerates ~24 sections (profile, site, media, customers, enquiries, feedback, notifications, audit logs...) but none for `analytics.site_visits` / `link_clicks` / `section_views` / `item_views`. This is the professional's own generated business data under Article 15 / APP 12, and its complete absence is a clean, verifiable export gap — distinct from the already-well-handled analytics retention/deletion side (see dropped finding note below: the FK `ON DELETE CASCADE` from these tables to `core.users` already handles erasure correctly).
    - **Plain English:** A professional uses their Partna page as their online storefront. When they ask for all their data, we hand over their profile and customer list but leave out their own website analytics — how many visitors, which links got clicked, where visitors came from. That's information about *their* business, generated from *their* page; it belongs in the export, just with individual visitor fingerprints (raw IP hashes, session IDs) stripped out so we're not handing over other people's data along with it.
    - **Evidence:**
        ```php
        private function sectionDescriptors(User $professional, ?string $lookupEmail, ?string $siteId): array
        {
            $userId = $professional->id;
            return [
                ['name' => 'metadata', ...], ['name' => 'profile', ...], ['name' => 'site', ...],
                ['name' => 'waitlist', ...], ['name' => 'media.site_media', ...], ['name' => 'design_kit', ...],
                ['name' => 'integrations', ...], ['name' => 'customers', ...], ['name' => 'services', ...],
                // ... no 'analytics.site_visits', 'analytics.link_clicks', 'analytics.section_views',
                // or 'analytics.item_views' entries anywhere in this array.
            ];
        }
        ```

## P2 — Should fix

- [ ] **PRIV-5** · P2 — Internal brand-analysis data excluded from the dashboard but exported wholesale in the GDPR export
    - **Where:** `app/Services/User/DataExport/DataExportPayloadBuilder.php:222-233` (`site()`) and `app/Http/Resources/WorkplaceResource.php:11-13`
    - **Affects:** Professionals requesting a DSAR — they receive machine-generated `previous_website_analysis` data the platform deliberately never shows them on the dashboard.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `DataExportPayloadBuilder::site()`, `unset()` the `previous_website_analysis` key from `$workplaceRow` before including it, matching `WorkplaceResource`'s exclusion policy — or explicitly document why the export includes it and the dashboard doesn't.
    - **Technical:** `WorkplaceResource` deliberately excludes `previous_website_analysis` ("internal brand-signal detail, not part of the public workplace-card contract" — the column exists per `supabase/migrations/20260701220001_workplace_previous_website_analysis.sql` and is written by `AnalyzePreviousWebsiteJob`/`WebsiteStyleAnalyzer`). `DataExportPayloadBuilder::site()` instead returns `(array) $workplaceRow` — a wholesale cast of every column with no redaction, so the export includes exactly the field the dashboard resource was written to hide. Under Article 15 this data likely *should* be disclosed (derived/profiling data about the subject), but the inconsistency between the two paths is the finding — it should be a deliberate decision either way, not an accident of which code path happens to touch the row.
    - **Plain English:** The platform runs an automated analysis of a professional's previous website to inform their design defaults. We deliberately hide that internal analysis from their dashboard. But if they request a full data export, it slips through anyway, because the export code grabs the whole database row instead of the same curated list the dashboard uses. Either show it in both places with an explanation, or hide it in both.
    - **Evidence:**
        ```php
        // WorkplaceResource — deliberately excludes it (docblock)
        // `previous_website_analysis` ... is deliberately excluded: it is internal
        // brand-signal detail, not part of the public workplace-card contract.
        ```
        ```php
        // DataExportPayloadBuilder::site() — wholesale (array) cast includes it
        $workplaceRow = DB::connection('pgsql')->table('site.workplaces')->where('site_id', $site->id)->first();
        return ['site' => (array) $site, 'blocks' => $blocks, 'workplace' => $workplaceRow ? (array) $workplaceRow : null];
        ```

- [ ] **PRIV-6** · P2 — New `core.feedback` columns (`type`, `area`, `target`) excluded from the GDPR export's explicit column allow-list
    - **Where:** `app/Services/User/DataExport/DataExportPayloadBuilder.php:298-306` (`streamFeedback`) and `supabase/migrations/20260711153000_feedback_type_area_target.sql`
    - **Affects:** Professionals who submit feedback through the OV-D feedback tool — their reaction category, feature-area context, and structured target metadata are silently omitted from their export.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `type`, `area`, and `target` to the explicit `select([...])` allow-list in `streamFeedback()`.
    - **Technical:** The OV-D feedback commit (`4cf90d17 feat(feedback): type/area/target picker + staff triage list`) added three NULLABLE columns to `core.feedback`. `streamFeedback()` uses an explicit column allow-list (not `select('*')`), so new columns are invisible to the export by default unless someone remembers to add them — which didn't happen here. The base table and its deletion path are already correctly in scope (row-level `user_id` delete in `purgeFeedbackRows`); this is a narrow field-completeness gap, not a missing-store gap.
    - **Plain English:** The feedback form just gained three new fields — what kind of feedback it is, which page it's about, and some structured context. The export tool already includes feedback submissions generally, but because it lists column names explicitly rather than grabbing everything, these three new fields don't make it into a download yet.
    - **Evidence:**
        ```php
        private function streamFeedback(string $userId): Generator
        {
            return $this->lazyRows(
                DB::connection('pgsql')->table('core.feedback')
                    ->select(['id', 'user_id', 'reply_email', 'kind', 'severity', 'message', 'page_url', 'viewport', 'app_version', 'request_id', 'status', 'source', 'tags', 'internal_notes', 'created_at', 'updated_at'])
                    ->where('user_id', $userId)
            );
        }
        ```
        ```sql
        ALTER TABLE core.feedback
            ADD COLUMN type text NULL,
            ADD COLUMN area text NULL,
            ADD COLUMN target jsonb NULL;
        ```

- [ ] **PRIV-7** · P2 — Staff audit log duplicates staff/user email and handle into the append-only audit schema
    - **Where:** `app/Services/Audit/StaffAuditService.php:33-38`
    - **Affects:** Every staff member whose action is logged, every impersonation event, and every professional whose data staff access — their emails/handle become permanently undeletable once written into `audit.staff_audit_log`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Stop writing `staff_email_snapshot` / `impersonator_email_snapshot` / `professional_handle_snapshot`; rely on the `staff_id` / `impersonator_staff_id` / `user_id` FKs already stored on the same row.
        - Add a lookup on the audit-reader side (staff UI / export) that resolves email/handle from the FK at read time, gated by the reader's own access controls.
    - **Technical:** `StaffAuditService::record()` writes `staff_email_snapshot`, `impersonator_email_snapshot`, and `professional_handle_snapshot` directly into every row of the append-only `audit.staff_audit_log` table, duplicating PII that's already reachable via the `staff_id`/`impersonator_staff_id`/`user_id` FK columns on the same row. Because the audit schema is append-only by design, this PII can never be corrected or redacted without dropping the whole table — unlike `ip_hash`, which correctly uses one-way HMAC-SHA256 (`hashIp()`), the email/handle columns get no such protection.
    - **Plain English:** Every time a staff member looks at or edits a user's account, we permanently log it — that's good practice. But the log entry also copies in the staff member's email address, any impersonator's email, and the user's handle, baked forever into a record we can never edit or delete. It's like a security camera that also stamps everyone's name and email onto the footage — the footage alone already proves who was there; the extra personal detail just creates a second permanent copy of PII with no way to ever remove it.
    - **Evidence:**
        ```php
        return StaffAuditEntry::query()->create([
            'staff_id' => $staff?->id,
            'staff_email_snapshot' => $staff?->primary_email,
            'impersonator_staff_id' => $impersonator?->id,
            'impersonator_email_snapshot' => $impersonator?->primary_email,
            'user_id' => $professional?->id,
            'professional_handle_snapshot' => $professional?->handle,
            ...
            'ip_hash' => $this->hashIp($ip),
        ]);
        ```

- [ ] **PRIV-8** · P2 — `core.feedback` has no declared retention rule and no scheduled purge
    - **Where:** `config/partna.php:1552-1577` (`feedback` section — no `retention_days` key) and `routes/console.php` (no feedback prune command)
    - **Affects:** Every feedback submission ever filed — free-text messages routinely embed the submitter's name/email/context and accumulate with no expiry, unlike the structurally similar `moderation.case_signals` (which has `signal_pii_retention_days` + a weekly prune job).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `retention_days` to `config('partna.feedback')`.
        - Add a scheduled command (`feedback:prune-old-submissions`) registered in `routes/console.php`, logging purge counts (not contents).
    - **Technical:** The `feedback` config section covers rate limits, IP-hash pepper, duplicate detection, and message-length caps, but has no `retention_days` — and no command in `routes/console.php` targets `core.feedback` for age-based deletion (only `AccountDeletionService::purgeFeedbackRows()` removes it, and only on account deletion). Compare `moderation.signal_pii_retention_days` (90d + `moderation:prune-resolved-signal-pii` weekly), which is the correct pattern for exactly this kind of user-generated free-text PII store.
    - **Plain English:** When someone submits feedback — including their name and email in the message — that submission sits in the database forever unless they later delete their whole account. There's no automatic cleanup schedule the way there is for other similar records. Under Australian privacy law, personal information should only be kept as long as there's a real reason to keep it, and "we never built a cleanup job" isn't one.
    - **Evidence:**
        ```php
        'feedback' => [
            'notify_emails' => ...,
            'rate_limit_per_hour' => (int) env('FEEDBACK_RATE_LIMIT_HOUR', 10),
            'rate_limit_per_day' => (int) env('FEEDBACK_RATE_LIMIT_DAY', 30),
            'duplicate_window_seconds' => (int) env('FEEDBACK_DUPLICATE_WINDOW', 60),
            'ip_hash_pepper' => env('FEEDBACK_IP_HASH_PEPPER'),
            'max_message_length' => 5000,
        ],
        // no retention_days key; routes/console.php has no feedback-related Schedule::command entry
        ```

- [ ] **PRIV-9** · P2 — Analytics visitor coordinates stored as raw, untruncated `double precision` with no minimisation
    - **Where:** `supabase/migrations/20260707020000_site_visits_lat_lon.sql:12-14`, `app/Services/Analytics/Writers/PostgresEventWriter.php:126-127`, `app/Http/Controllers/Concerns/DetectsClientInfo.php:165-187`
    - **Affects:** Every visitor to any Partna sitepage — edge-resolved lat/lon is persisted at full floating-point precision alongside the already-sufficient `city`/`region_code`/`country_code`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Truncate latitude/longitude to ~4 decimal places (≈11m precision — plenty for a metro-level demographics map pin) in `DetectsClientInfo::parseCoordinate()`, at the point of ingest.
        - Document the chosen precision and rationale in the detector's docblock.
    - **Technical:** `PostgresEventWriter::visitRow()` writes `$e->latitude`/`$e->longitude` straight through with no rounding; `DetectsClientInfo::parseCoordinate()` only range-validates (±90/±180), it never truncates. The same row already carries `city`, `region_code`, and `country_code` (added specifically for "demographics map" per the `city` column's own migration comment: "Best-effort demographics only"), so the raw coordinates add identifiability precision the stated use case doesn't need. This mirrors the referrer/UA sanitisation already applied elsewhere in the same writer (`AnalyticsEventSanitizer` — see dropped-finding note below), which shows the minimisation pattern is already established in this codebase; lat/lon is the one field that pattern didn't reach.
    - **Plain English:** Every visit to a Partna page records the visitor's city and country — reasonable for an analytics dashboard. It also records their exact latitude/longitude to many decimal places, when the only feature that uses it is a "which metro area are visitors from" map pin. That's like a delivery company keeping your precise GPS coordinates on file when all they needed was your suburb. Rounding the numbers down when they're collected keeps the map feature working while dropping the unnecessary precision.
    - **Evidence:**
        ```sql
        -- 20260707020000_site_visits_lat_lon.sql
        ALTER TABLE analytics.site_visits
            ADD COLUMN IF NOT EXISTS latitude double precision,
            ADD COLUMN IF NOT EXISTS longitude double precision;
        ```
        ```php
        // PostgresEventWriter::visitRow() — no truncation applied
        'latitude' => $e->latitude,
        'longitude' => $e->longitude,
        ```
        ```php
        // DetectsClientInfo — only bounds-checks, never rounds
        protected function detectLatitude(Request $request): ?float
        {
            return $this->parseCoordinate($request->header('X-Visitor-Lat'), 90.0);
        }
        ```

## P3 — Nice to have

- [ ] **PRIV-10** · P3 — Stale Shopify-era docblock and dead `RedactShopJob` reference in the GDPR config section
    - **Where:** `config/partna.php:1468-1479` (`gdpr` section docblock)
    - **Affects:** No live data — documentation-only drift that could mislead a future privacy audit about what the `gdpr` config section actually governs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Rewrite the docblock to describe what `gdpr.*` actually governs today (the Partna professional data-export pipeline: `export_retention_days`, `signed_url_ttl_days`, `dedup_window_minutes`), removing the Shopify/`RedactShopJob` references.
    - **Technical:** The docblock reads "Config for Shopify GDPR webhook handlers... `RedactShopJob` can take several minutes" — `RedactShopJob` does not exist anywhere in `app/` (confirmed via repo-wide search; only archived migrations and historical audit docs reference it). Commerce/Shopify was removed 2026-05-22 per the standalone strip-down. The `export_retention_days`/`signed_url_ttl_days`/`dedup_window_minutes` keys underneath are live and correctly serve the Partna export pipeline (enforced by the `gdpr:prune-completed-exports` scheduled command) — only the prose above them is stale.
    - **Plain English:** The instruction manual for this config section still describes a Shopify feature that was removed months ago, including a cleanup job that no longer exists in the code. The actual settings below the comment are fine and in active use — just the explanation above them is out of date, which could waste a future auditor's time chasing a dead code path.
    - **Evidence:**
        ```php
        /*
        | Config for Shopify GDPR webhook handlers. Jobs dispatch onto a dedicated
        | queue so they don't contend with the default worker on a mature shop
        | (RedactShopJob can take several minutes). The placeholder domain is used
        | when anonymising customer email addresses...
        */
        'gdpr' => [
            'queue' => env('PARTNA_GDPR_QUEUE', env('GDPR_QUEUE', 'gdpr')),
            'redact_placeholder_domain' => env('GDPR_REDACT_PLACEHOLDER_DOMAIN', 'gdpr.partna.au'),
            'export_retention_days' => (int) env('GDPR_EXPORT_RETENTION_DAYS', 30),
            ...
        ],
        ```

- [ ] **PRIV-11** · P3 — Default seeded contact card uses a real, platform-uncontrolled domain (`charlie@ai.com`)
    - **Where:** `config/partna.php:852-858` (`account_type_defaults.individual.default_contact`)
    - **Affects:** New individual accounts before the professional customises their public contact card — any code path that acts on the default before it's overwritten sends mail to a real stranger's inbox rather than nowhere.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Replace `charlie@ai.com` with `example@example.com` (RFC 2606 reserved) or a `partna.au` address the platform controls.
        - Replace `1234 567 890` with a reserved fictional-use number.
    - **Technical:** `ai.com` is a real, resolvable domain with an MX record, owned by a third party. The seed value is meant to be overwritten by the professional, but nothing in the codebase guarantees that happens before any code path (e.g. a render of the public contact block, or a future welcome-email touch) could act on it. RFC 2606 reserves `example.com`/`example.org`/`example.net` for exactly this scenario.
    - **Plain English:** Every new account starts with placeholder contact info — a made-up name, "charlie@ai.com," and a fake phone number. The problem is `ai.com` is a domain someone else actually owns. If anything ever emails that placeholder before the professional replaces it, a stranger receives it. Using an address the internet has officially reserved for "this will never go anywhere" removes that risk entirely.
    - **Evidence:**
        ```php
        'default_contact' => [
            'full_name' => 'Charlie',
            'email' => 'charlie@ai.com',
            'phone' => '1234 567 890',
            'source' => 'system_default',
            'subscribed' => true,
        ],
        ```

- [ ] **PRIV-12** · P3 — Two-year waitlist retention for non-converting applicants may exceed what's proportionate
    - **Where:** `config/partna.php:816` (`waitlist.retention_days`)
    - **Affects:** Every waitlist signup who never converts to a full account — name, email, and industry retained 730 days past their last activity.
    - **Effort:** S (~0.5h, config-only)
    - **What to do:**
        - Reduce `retention_days` to a period proportionate to the waitlist's actual purpose (e.g. 365 days), or document the business justification for keeping 730.
    - **Technical:** The enforcement mechanism here is already correct — `waitlist:prune-old-signups` runs weekly and reads this config value, unlike the handle-audit and feedback gaps above. This is purely a proportionality judgment under APP 11.2: once the platform launches or an applicant is passed over, the original evaluation purpose is largely fulfilled, and two additional years starts to look like "just in case" retention rather than purpose-bound retention.
    - **Plain English:** If someone signs up for the waitlist but never becomes a user, we keep their name, email, and profession for two full years after they lose interest. The cleanup job that eventually deletes it works correctly — the question is just whether two years is longer than we actually need, versus a shorter, easier-to-justify window.
    - **Evidence:**
        ```php
        'waitlist' => [
            'enabled' => (bool) env('PARTNA_WAITLIST_ENABLED', env('SIDEST_WAITLIST_ENABLED', false)),
            // PRIV-8: hard-delete non-converting applicant rows older than this window.
            'retention_days' => (int) env('PARTNA_WAITLIST_RETENTION_DAYS', 730),
            ...
        ],
        ```

- [ ] **PRIV-13** · P3 — Evidence snapshot's captured handle/display_name absent from the GDPR export (deletion side already covered)
    - **Where:** `app/Services/Moderation/EvidenceSnapshotService.php:59-67` and `app/Services/User/DataExport/DataExportPayloadBuilder.php` (no `moderation.evidence` export section)
    - **Affects:** Any user whose site was the subject of a moderation report — their handle and display name are frozen into an immutable evidence row that never surfaces in their own DSAR.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `moderation.evidence` (or content-report-adjacent) export entry that surfaces the redacted-safe fields of a user's own evidence snapshots, or explicitly document the exclusion rationale in `sectionDescriptors()`.
    - **Technical:** `EvidenceSnapshotService::snapshotSite()` captures `handle` and `display_name` into `moderation.evidence.payload` at report time. The deletion side of this is already handled correctly — `AccountDeletionService::purgeReportedUserEvidencePii()` tombstones `handle`/`display_name`/`site_subdomain` to `'[redacted]'` on account purge, so this is narrower than the original draft suggested: only the export-completeness half of the ledger is actually missing, not the erasure half.
    - **Plain English:** When someone's page gets reported, we take a permanent snapshot that includes their handle and display name at that moment. If they ever delete their account, that snapshot already gets properly scrubbed — that part works. But if they ask "what do you have on me" *before* deleting, that snapshot isn't part of what we send them, and it probably should be.
    - **Evidence:**
        ```php
        private function snapshotSite(string $siteId): array
        {
            $site = Site::query()->with(['user', 'blocks'])->findOrFail($siteId);
            return [
                'site_id' => $site->id,
                'site_subdomain' => $site->subdomain ?? null,
                'user_id' => $site->user_id,
                'handle' => $site->user?->handle ?? null,
                'display_name' => $site->user?->display_name ?? null,
                ...
            ];
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Export ledger completeness:** PRIV-3, PRIV-4, PRIV-5, PRIV-6, PRIV-13
    - **Why grouped:** All are additive entries/fields to the same file (`DataExportPayloadBuilder::sectionDescriptors()` / `site()` / `streamFeedback()`) — one coherent pass over the export manifest.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Retention enforcement gaps:** PRIV-2, PRIV-8
    - **Why grouped:** Identical root-cause pattern (a declared `config/partna.php` retention value with no matching `routes/console.php` command) — same fix shape, same files.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Config & minimisation hygiene:** PRIV-9, PRIV-10, PRIV-11, PRIV-12
    - **Why grouped:** All are small, low-risk config/ingest-layer cleanups (precision truncation, stale docs, placeholder values, retention-window tuning) with no cross-file coordination needed.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **PRIV-1 — Early-access signup purge + export gap** · spans the account-deletion erasure path (`AccountDeletionService::purge()`) — the lens's own "highest-stakes category." Isolate so a mistake here doesn't get masked by unrelated bundle changes; get a plan reviewed before touching the deletion flow.
- **PRIV-7 — Staff audit log snapshot removal** · writes into the append-only `audit.staff_audit_log` compliance trail; isolate from other bundles so a regression here doesn't silently corrupt the audit record staff/legal rely on.
