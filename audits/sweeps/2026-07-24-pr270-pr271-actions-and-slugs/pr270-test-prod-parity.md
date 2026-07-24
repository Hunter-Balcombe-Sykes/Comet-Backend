# PARITY Audit — 2026-07-24

**Branch:** HEAD
**Lens:** Test↔prod schema parity — application writes that pass SQLite CI but violate Postgres constraints (`PARITY`): NOT NULL columns SQLite's seed doesn't mirror, CHECK/enum-domain values SQLite never enforces, FK references SQLite has `PRAGMA foreign_keys = OFF` for, type/precision drift, DB-default divergence, uniqueness/partial-index divergence, and append-only trigger invariants — proven against real `supabase/migrations/` DDL, not speculation.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- `supabase/migrations/20260723090000_create_action_events.sql`
- `supabase/migrations/20260701220000_promote_gb_apify_status_placeid.sql`
- `supabase/migrations/20260709042716_create_content_popularity_scores.sql`
- `supabase/migrations/20260720100300_content_popularity_scores_content_type_check.sql`
- `app/Models/Analytics/ActionEvent.php`
- `tests/Pest.php`
- `app/Jobs/Platforms/GoogleBusinessEnrichJob.php`
- `app/Http/Controllers/Api/User/SiteManagement/UserSiteActionsController.php`
- `app/Services/PublicSite/Actions/ActionVocabulary.php`
- `app/Services/PublicSite/IndividualProfilePayloadBuilder.php`
- `app/Services/PublicSite/SiteActionsService.php`
- `app/Services/User/AccountDeletionService.php`
- `app/Services/User/DataExport/DataExportPayloadBuilder.php`
- `app/Services/Analytics/AnalyticsEvent.php`
- `app/Services/Analytics/RankedActionsComputer.php`
- `app/Services/Analytics/Writers/PostgresEventWriter.php`
- `app/Services/Cache/CacheKeyGenerator.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

No findings survived adjudication. DeepSeek's only draft (`PARITY-1`, "`analytics.action_events.site_id` NOT NULL in prod but nullable in the SQLite seed") was dropped on verification — its own quoted evidence already contradicts its claim. `tests/Pest.php`'s `setupActionEventsTable()` declares `site_id TEXT NOT NULL`, matching the prod column exactly (`supabase/migrations/20260723090000_create_action_events.sql:32`), so there is no seed/prod drift to mask anything. Independently, the only write path that reaches `analytics.action_events` — `App\Services\Analytics\Writers\PostgresEventWriter::appendActionRow()` — inserts a raw array via `ActionEvent::query()->insertOrIgnore($actionRows)`, which bypasses `$fillable`/mass-assignment entirely, and that array always includes `'site_id' => $e->siteId` where `AnalyticsEvent::$siteId` is a required non-nullable constructor parameter (`app/Services/Analytics/AnalyticsEvent.php:54`). There is no reachable code path — `create()`, factory, job, or observer — that can omit `site_id`, so DeepSeek's mass-assignment concern doesn't apply to this table's actual write pattern.

The rest of the scope checked clean on manual re-verification against the real DDL:
- `analytics.action_events.event` CHECK (`'seen'`, `'tap'`) — `PostgresEventWriter::appendActionRow()` derives `event` from the `AnalyticsEvent` type match arm, which can only ever be `'seen'` or `'tap'`.
- `analytics.action_events.action_id` deliberately carries no DB CHECK by design (migration comment, `20260723090000_create_action_events.sql:19-25`) — vocabulary is enforced app-side via `ActionVocabulary::isValidId` behind `ActionSeenRequest`/`ActionTapRequest` Form Requests, so out of scope per the lens's own upstream-constraint exclusion.
- `site.platform_connections.apify_status` CHECK (`'pending'`, `'ok'`, `'unavailable'`) — every write in `GoogleBusinessEnrichJob.php` (`'ok'`, `'unavailable'`) falls within the set (`supabase/migrations/20260701220000_promote_gb_apify_status_placeid.sql:21`).
- `analytics.content_popularity_scores.content_type` CHECK — `'action'` is explicitly enumerated (`20260720100300_content_popularity_scores_content_type_check.sql:43`), matching `RankedActionsComputer::CONTENT_TYPE`.
- `AccountDeletionService::purgeActionEventsPii()` / `DataExportPayloadBuilder::streamAnalyticsActionEvents()` are plain DELETE/SELECT against a non-append-only `analytics.*` table — no trigger-invariant or constraint risk.
- `IndividualProfilePayloadBuilder.php`, `SiteActionsService.php`, `ActionVocabulary.php`, `CacheKeyGenerator.php`, `UserSiteActionsController.php` contain no direct database write calls (`create`/`insert`/`save`/`updateOrCreate`/`firstOrCreate`) in the audited scope.

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

None.
