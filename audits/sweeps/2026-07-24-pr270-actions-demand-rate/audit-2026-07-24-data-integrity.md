# Data Integrity & Privacy Audit — 2026-07-24

**Branch:** HEAD
**Lens:** Data integrity & privacy: FK hygiene, soft-delete coherence, orphan rows, JSONB drift, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude`
**Source files audited:**
- supabase/migrations/20260723090000_create_action_events.sql
- app/Models/Analytics/ActionEvent.php
- app/Services/User/AccountDeletionService.php
- app/Services/User/DataExport/DataExportPayloadBuilder.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 1 complete

---

## P3 — Nice to have

- [ ] **DINT-1** · P3 — `analytics.action_events` has no index on `user_id`, forcing a sequential scan on every GDPR purge
    - **Where:** `supabase/migrations/20260723090000_create_action_events.sql:31,52-61`; `app/Services/User/AccountDeletionService.php:1139-1154` (`purgeActionEventsPii`)
    - **Affects:** Account-deletion latency and DB load. `AccountDeletionService::purgeActionEventsPii()` runs `WHERE user_id = ?` on every hard-delete (self-service, staff-initiated, and the daily `PurgeSoftDeleted` sweep). Neither existing index has `user_id` as a leftmost column, so this delete is a sequential scan; on the write-heavy analytics-ingest path (per project scale notes) this table is expected to grow fastest of any in the schema.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a new migration: `CREATE INDEX CONCURRENTLY IF NOT EXISTS action_events_user_id_idx ON analytics.action_events (user_id);` in its own file (per `CONVENTIONS.md` §1 — a `CONCURRENTLY` statement must be alone in its migration file).
        - `analytics.item_views` (`supabase/migrations/20260709042911_create_item_views.sql`) has the identical gap — same denormalized `user_id`, same `purgeItemViewsPii()` query shape, no `user_id` index. Add the equivalent index there in the same session for consistency (same root cause, same fix).
    - **Technical:** `action_events_site_occurred_idx (site_id, occurred_at)` and `action_events_occurred_at_idx (occurred_at)` are the only two indexes on this table (`20260723090000_create_action_events.sql:52-61`). Postgres cannot use either to satisfy a bare `WHERE user_id = ?` (leftmost-prefix rule), so `purgeActionEventsPii()`'s delete (`AccountDeletionService.php:1142-1145`) and the identical `purgeItemViewsPii()` delete both degrade to a full table scan as the table grows. This table was added in `ea046b43` (`feat(analytics): add analytics.action_events raw event table`) explicitly to "mirror item_views' shape" — it faithfully reproduced item_views' pre-existing missing-index gap along with its intentional no-FK design. The FK omission itself is a deliberate, well-documented trade-off (see the table's own migration header and `20260720100500_analytics_site_fks.sql`'s rationale for `item_views`) and is not a finding; the missing index is a separate, unaddressed hygiene gap that specifically slows the erasure path GDPR compliance depends on.
    - **Plain English:** When someone deletes their account, the system needs to find and erase every analytics event tied to them. Right now it does that by flipping through the entire events table page by page looking for matches, instead of using a lookup shortcut. That's not a bug that breaks anything today — it just makes each account deletion a bit slower and more expensive as the table grows, since this data grows fast. Adding a proper index (a lookup shortcut) fixes it cheaply, once.
    - **Evidence:**
        ```sql
        user_id      uuid,             -- site owner (denormalized; nullable = fail-open, mirrors item_views)
        ```
        ```sql
        CREATE INDEX IF NOT EXISTS action_events_site_occurred_idx
            ON analytics.action_events (site_id, occurred_at);

        CREATE INDEX IF NOT EXISTS action_events_occurred_at_idx
            ON analytics.action_events (occurred_at);
        ```
        ```php
        DB::connection('pgsql')
            ->table('analytics.action_events')
            ->where('user_id', $professional->id)
            ->delete();
        ```

## Suggested Bundled Sessions

None.

## Standalone — do NOT bundle

- **#DINT-1 — Missing index on `analytics.action_events.user_id`** · requires a `supabase/migrations/` DDL change (`CREATE INDEX`), so it runs with its own plan + sign-off per the DB-migration rule, even though the fix itself is small.
