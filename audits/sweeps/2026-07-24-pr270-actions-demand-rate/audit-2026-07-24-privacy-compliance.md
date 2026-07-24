# Privacy & Data-Rights Compliance Audit — 2026-07-24

**Branch:** HEAD
**Lens:** Privacy & data-rights compliance — PII inventory, export/delete completeness, retention enforcement, processor flows (bundle chunks: rights-machinery, collection-retention, console-mail, schema-pii)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Services/User/DataExport/DataExportPayloadBuilder.php
- app/Services/User/AccountDeletionService.php
- app/Models/Analytics/ActionEvent.php
- app/Services/Analytics/AnalyticsEvent.php
- app/Services/Analytics/Writers/PostgresEventWriter.php
- app/Services/Analytics/AnalyticsEventSanitizer.php
- app/Services/Analytics/RankedActionsComputer.php
- app/Console/Commands/ComputeContentPopularityScores.php
- app/Console/Commands/PurgeRawAnalyticsEvents.php
- app/Console/Commands/PruneCompletedExportsCommand.php
- config/partna.php
- routes/console.php
- routes/api/staff.php
- supabase/migrations/20260723090000_create_action_events.sql
- supabase/migrations/20260707020000_site_visits_lat_lon.sql
- tests/Feature/Security/DataExportCoverageTest.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **PRIV-1** · P2 — Visitor lat/long stored as raw double-precision with no truncation
    - **Where:** app/Services/Analytics/AnalyticsEvent.php:75-76; app/Services/Analytics/Writers/PostgresEventWriter.php:132-133; supabase/migrations/20260707020000_site_visits_lat_lon.sql:12-14
    - **Affects:** Every visitor whose page load resolves an edge-geolocated coordinate pair; the value is retained in `analytics.site_visits` for the full 90-day raw-event retention window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Round `latitude`/`longitude` to ~2 decimal places (roughly city-block resolution) in `PostgresEventWriter::visitRow()` before insert.
        - Document the truncation decision next to the column in the migration/schema comment so future geo columns follow the same convention.
    - **Technical:** Per the migration's own comment, these columns capture Cloudflare's edge-resolved `request.cf.latitude`/`longitude` (forwarded via the `X-Visitor-Lat/Lon` header), not a browser GPS permission prompt — so this is IP-derived, city/postal-centroid-grade geolocation, not street-level precision. That said, the app stores the full untruncated `double precision` value with no code-side rounding, even though the stated purpose ("plot real city pins instead of country centroids," per the migration comment) doesn't require more than 2–3 decimal places of precision. `country_code`/`region_code`/`city` already cover the text-label use case; the raw float pair is the only field carrying more granularity than the product needs. This is the same class of gap the platform already closed for `ip_hash` (hash at the boundary, don't keep more than needed) — lat/long should get the analogous truncate-at-write treatment.
    - **Plain English:** When someone visits a professional's page, Partna's network estimates roughly where they are (from their internet connection, not by asking permission) and can plot that as a pin on the professional's analytics map. Right now we save that estimate at full decimal precision instead of rounding it off first. The map doesn't need that much detail, and holding less precision than necessary is a simple, low-cost way to reduce the personal information we're keeping on file — the same principle we already apply to how we store visitors' IP addresses.
    - **Evidence:**
        ```php
        // AnalyticsEvent.php
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        ```
        ```php
        // PostgresEventWriter.php — visitRow()
        'latitude' => $e->latitude,
        'longitude' => $e->longitude,
        ```
        ```sql
        -- 20260707020000_site_visits_lat_lon.sql
        ALTER TABLE analytics.site_visits
            ADD COLUMN IF NOT EXISTS latitude double precision,
            ADD COLUMN IF NOT EXISTS longitude double precision;
        ```

- [ ] **PRIV-2** · P2 — User-Agent sanitizer only length-caps, doesn't reduce to browser family — full fingerprint persists across 5 analytics tables
    - **Where:** app/Services/Analytics/AnalyticsEventSanitizer.php:46-58; app/Services/Analytics/Writers/PostgresEventWriter.php:123, 195, 244, 285, 320; supabase/migrations/20260723090000_create_action_events.sql:39
    - **Affects:** Every visitor to every Partna sitepage — their browser/OS/device fingerprint lands in `site_visits`, `link_clicks`, `section_views`, `item_views`, and `action_events`, all for up to the 90-day raw retention window.
    - **Effort:** S (~1h)
    - **What to do:**
        - Extend `AnalyticsEventSanitizer::userAgent()` to reduce the string to browser family + major version (a small parser or regex extraction) instead of only `Str::limit()`-capping it at 256 chars.
        - Apply uniformly across all 5 write paths — they already share this one sanitizer, so the fix is centralised.
    - **Technical:** `AnalyticsEventSanitizer::userAgent()` is explicitly a length cap, not a minimisation transform — its own docblock says "Cap the User-Agent at 256 chars... device_type is derived separately, so the raw UA adds no dashboard value beyond this," but the implementation only calls `Str::limit()`. A 256-char UA string still typically carries the full browser name, version, OS, and rendering-engine build — a strong cross-site fingerprint when combined with the already-stored `ip_hash`. Since `device_type` (mobile/desktop/tablet) is already derived and stored separately for the dashboard's actual use case, retaining the fuller (if truncated) UA string is collection beyond APP 3 necessity. This is one shared sanitizer feeding all 5 analytics write paths, so the fix is centralised rather than 5 separate patches.
    - **Plain English:** Every time someone visits a professional's page, we save a copy of their browser's technical signature — browser name, version, operating system, and more. We do trim it if it's unusually long, but the trimmed version is still detailed enough to help single out one visitor from a crowd. We already separately note whether they're on a phone, tablet, or desktop, which is what the analytics dashboard actually needs — keeping the fuller signature on top of that is more detail than necessary, the digital equivalent of writing down someone's full ID number when a simple "yes/no" checkbox would do the job.
    - **Evidence:**
        ```php
        // AnalyticsEventSanitizer.php
        public static function userAgent(?string $userAgent): ?string
        {
            if ($userAgent === null || $userAgent === '') {
                return null;
            }

            return Str::limit($userAgent, self::USER_AGENT_MAX_LENGTH, '');
        }
        ```
        ```php
        // PostgresEventWriter.php — repeated at all 5 call sites
        'user_agent' => AnalyticsEventSanitizer::userAgent($e->userAgent),
        ```

- [ ] **PRIV-3** · P2 — Moderation PII erasure (`purgeCaseSignalPii`, `purgeReportedUserEvidencePii`) runs on every account deletion but is untracked by the export/erasure coverage guard
    - **Where:** app/Services/User/AccountDeletionService.php:50-57 (`PURGED_PII_TABLES`), :746-747 (calls in `purge()`), :951-972 (`purgeCaseSignalPii`), :991-1028 (`purgeReportedUserEvidencePii`); tests/Feature/Security/DataExportCoverageTest.php:178-187 (erasure-completeness guard)
    - **Affects:** Reporters and reported users whose PII lives in `moderation.case_signals`/`moderation.evidence` — a future refactor to either purge method would ship with no automated failure.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `moderation.case_signals` to `DataExportPayloadBuilder::COVERED_PII_TABLES` (it is already queried by name in `streamContentReports()`, so it satisfies the "referenced by builder" assertion) and to `AccountDeletionService::PURGED_PII_TABLES`, referencing `purgeCaseSignalPii()`.
        - For `moderation.evidence`: it is not currently exported at all (no section in `sectionDescriptors()`). Either add a redacted `streamEvidence()` section so it can join `COVERED_PII_TABLES` honestly, or explicitly document it as a deliberate export exclusion (mirroring the "signal-level detail withheld to protect reporter identity" precedent already used for case signals) — then add it only to a documented-exclusion list, not silently drop it from tracking.
        - Extend `DataExportCoverageTest.php`'s erasure-path test to assert both tables resolve to a real `purge*()` method reference, the same way `PURGED_PII_TABLES` entries are checked today.
    - **Technical:** `purgeCaseSignalPii()` nulls `reporter_user_id`/`reporter_email`/`reason_details` and resets `signal_data` on `moderation.case_signals`; `purgeReportedUserEvidencePii()` tombstones `handle`/`display_name`/`site_subdomain` inside `moderation.evidence.payload`. Both run unconditionally inside `purge()`. Neither table appears in `PURGED_PII_TABLES`, and `moderation.case_signals` — despite being queried by name in the export builder — is also absent from `COVERED_PII_TABLES`. `DataExportCoverageTest`'s erasure-completeness assertion only diffs `COVERED_PII_TABLES` against `PURGED_PII_TABLES`/`CASCADE_ERASED`/`RETAINED_BY_DESIGN`, so a table missing from `COVERED_PII_TABLES` is invisible to that check regardless of what's in `PURGED_PII_TABLES`. The erasure code is correct and running today; the gap is purely that no regression test would catch its removal.
    - **Plain English:** Two "clean-up crews" already run every time someone deletes their account — one wipes a reporter's contact details off a moderation report, another blanks out a reported person's name and page address from moderation evidence. Both do their job today. But neither crew is on the checklist our automated tests use to make sure clean-up code doesn't quietly get deleted by some unrelated future change. If that happens, nothing will catch it, and personal information that's supposed to be erased on request will keep sitting in the database indefinitely.
    - **Evidence:**
        ```php
        public const PURGED_PII_TABLES = [
            'core.users',                          // forceDelete() — the subject row
            'core.early_access_signups',           // purgeEarlyAccessSignup() — email_lc keyed
            'core.feedback',                       // purgeFeedbackRows() — FK is SET NULL, not CASCADE
            'notifications.email_subscriptions',   // purgeGlobalEmailSubscriptions() + purgeCrossTenantSubscriptions()
            'analytics.item_views',                // purgeItemViewsPii() — user_id is a denormalised column, no FK
            'analytics.action_events',             // purgeActionEventsPii() — same denormalised-column shape as item_views
        ];
        ```
        ```php
        $this->purgeCaseSignalPii($professional);        // #P2-11: reporter PII on moderation signals
        $this->purgeReportedUserEvidencePii($professional); // PRIV-4: reported-user PII in evidence payload
        ```

- [ ] **PRIV-4** · P2 — `analytics.content_popularity_scores` has no time-bound retention — only a score-threshold fade-out that can retain rows indefinitely
    - **Where:** app/Console/Commands/PurgeRawAnalyticsEvents.php:19-28 (`TABLES` list omits this table); app/Console/Commands/ComputeContentPopularityScores.php:549-555 (fade-out-only deletion)
    - **Affects:** Every site whose `content_popularity_scores` rows maintain even faint recurring engagement — derived behavioural data tied to a `site_id` accumulates with no maximum age.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `analytics.content_popularity_scores` to `PurgeRawAnalyticsEvents::TABLES` keyed on `computed_at`, or add a dedicated age-bound purge alongside the existing fade-out logic.
        - Declare the retention window explicitly in `config/partna.php` (e.g. 180–365 days from last `computed_at`) rather than leaving it as an emergent property of the scoring decay.
    - **Technical:** `PurgeRawAnalyticsEvents` enumerates seven raw-event tables for hard-deletion past the 90-day retention window, but `analytics.content_popularity_scores` — written by `ComputeContentPopularityScores` via upsert — is absent from that list. The only deletion path is the fade-out in `scoreAndRank()`, which drops a row only when its blended score falls below `SCORE_FLOOR` (0.05) **and** no live aggregate signal exists. A content key with even trivial recurring traffic (one click every few months) resets that decay and keeps its row alive indefinitely. This is functional cleanup tied to engagement, not time-bound retention — a stale site with a faint trickle of activity accumulates rows with no ceiling, and the table holds `site_id`, an identifier linkable to a professional under APP 11.2.
    - **Plain English:** Think of a filing cabinet where old reports only get thrown out once their "relevance score" naturally fades to near-zero. A report that gets even one tiny update per year never quite reaches zero, so it stays in the cabinet forever. That's what's happening with this analytics table — old content-ranking data can live on indefinitely as long as something, somewhere, occasionally interacts with it. The fix is to add a "destroy after X months regardless" rule, the same way our raw visitor data already has one.
    - **Evidence:**
        ```php
        // PurgeRawAnalyticsEvents.php — content_popularity_scores is NOT listed:
        private const TABLES = [
            'analytics.link_clicks' => 'occurred_at',
            'analytics.site_visits' => 'occurred_at',
            'analytics.lead_submissions' => 'occurred_at',
            'analytics.section_views' => 'occurred_at',
            'analytics.item_views' => 'occurred_at',
            'analytics.action_events' => 'occurred_at',
            'analytics.site_sessions' => 'last_seen_at',
        ];
        ```
        ```php
        // ComputeContentPopularityScores.php — only score-floor deletion, no age gate:
        foreach ($blended as $key => $score) {
            if (! isset($agg[$key]) && $score < self::SCORE_FLOOR) {
                $deletes[] = (string) $key;
                unset($blended[$key]);
            }
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Analytics ingest minimisation:** PRIV-1, PRIV-2
    - **Why grouped:** Both touch the same write path (`PostgresEventWriter.php` + `AnalyticsEventSanitizer.php`) and the same root cause — geo/UA fields captured at more precision than the analytics dashboard needs.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — PII lifecycle guard hardening:** PRIV-3, PRIV-4
    - **Why grouped:** Same root-cause pattern — PII-handling code that already runs correctly (moderation purge methods, score fade-out) but isn't backed by an automated regression guard or a declared time-bound retention rule. Both are test/console additions, not behavioural changes to the erasure logic itself.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.
