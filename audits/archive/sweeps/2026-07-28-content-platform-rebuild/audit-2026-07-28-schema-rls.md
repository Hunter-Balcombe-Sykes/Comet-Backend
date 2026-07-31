# Schema / RLS / search_path Audit — 2026-07-28

**Branch:** development
**Lens:** Schema / RLS / search_path: database-side correctness, constraint coverage, migration safety
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- supabase/migrations/20260727100000_catalog_schema.sql
- supabase/migrations/20260727110000_connections_surface_key.sql
- supabase/migrations/20260727120000_routing_schema.sql
- supabase/migrations/20260727130000_ingest_schema.sql
- supabase/migrations/20260727140000_content_schema.sql
- supabase/migrations/20260727150000_sections_and_documents.sql
- supabase/migrations/20260728130000_brand_asset_refs.sql
- supabase/migrations/20260728150000_field_bindings.sql
- supabase/migrations/20260726000000_baseline_pilot.sql (verification only)
- app/Models/Content/Item.php, IdentityCandidate.php, ManualOverride.php
- app/Models/Core/Site/Section.php, Page.php, SectionItem.php, SectionGroup.php, SiteMedia.php, FieldBinding.php, DesignKitRestyle.php, IntegrationConnection.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 3 of 3 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [x] **SCHEMA-1** · P2 — `content.source_items.kind` has no CHECK constraint while sibling column `content.items.kind` does
    - **Where:** supabase/migrations/20260727140000_content_schema.sql:78 (vs. `content.items.kind` at line 43)
    - **Affects:** The identity-resolution and item-projection pipeline — a projector bug writing a kind value outside the 14-value domain lands in `source_items` unconstrained, then propagates into `content.items` when the resolver copies it across, at which point the (constrained) `items.kind` CHECK would only catch it at the *second* write, not the first.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CHECK ("kind" IN (...))` to `content.source_items.kind` mirroring the 14-value domain already enforced on `content.items.kind`.
        - Ship as `NOT VALID` + `VALIDATE CONSTRAINT` if the table has live rows by the time this lands (dev-scale cold table today, per the migration's own precedent in `20260727110000`).
    - **Technical:** `content.items.kind` carries `CHECK ("kind" IN ('video', 'track', 'release', 'episode', 'channel', 'service', 'menu_item', 'product', 'event', 'link', 'media', 'review', 'document', 'article'))`. `content.source_items.kind` (the per-external-record unit that `ProjectionWriter::resolveItems()` groups by, and whose value flows into `createItem()`'s `'kind' => $kind` on new-item creation) is declared `text NOT NULL` with no constraint. Kind values here come from each platform Projector's typed `kind()` method rather than raw user input, so the realistic trigger is a projector-authoring bug, not attacker input — but the DB is currently the last line of defense on `items.kind` only, one hop downstream of where the value is first persisted.
    - **Plain English:** Two tables in the pipeline both store "what type of thing is this" (a video, a review, a menu item, etc.), and there are only 14 valid types. One of the two tables has a bouncer checking IDs at the door; the other just waves everyone through. If a bug ever writes an invalid type into the second table, the database won't catch it until it tries to copy that value into the first table — one step later than it should.
    - **Evidence:**
        ```sql
        -- source_items: NO CHECK
        "kind" text NOT NULL,

        -- items: HAS CHECK
        "kind" text NOT NULL CHECK ("kind" IN (
            'video', 'track', 'release', 'episode', 'channel', 'service', 'menu_item',
            'product', 'event', 'link', 'media', 'review', 'document', 'article'
        )),
        ```

- [x] **SCHEMA-2** · P2 — `ingest.effects.kind` documents a closed 4-value set in a comment but has no CHECK constraint
    - **Where:** supabase/migrations/20260727130000_ingest_schema.sql:162
    - **Affects:** The charge-once billing ledger (`ingest.effects`) — this table is the sole guard against double-billing a paid effect (Apify actor run, external API call) on job retry; an unconstrained `kind` weakens auditability of the one table this subsystem depends on for cost correctness (recent commit `694906b7` was specifically fixing billed-effect replay correctness in this area).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CHECK ("kind" IN ('http', 'actor', 'api', 'ai'))` matching the comment's documented domain.
        - Use `NOT VALID` + `VALIDATE` if applied after rows exist.
    - **Technical:** The column comment (`-- http | actor | api | ai`) states a closed 4-value domain, but no `CHECK` backs it — unlike every other enum-shaped column in this same file (`sources.health`, `sources.scope`, `streams.health`, `runs.trigger`, `runs.outcome`, `effects.status`, `anomalies.severity` all carry `CHECK` constraints). This is the one enum column in the file that has a documented domain but no enforcement, in a table whose entire purpose is being the auditable charge-once record for billed effects.
    - **Plain English:** This table is the receipt book for anything Partna pays money for externally (scraping, API calls). A note next to one column says "only these four values are allowed," but nothing actually stops a fifth value from being written. In a receipt book that's meant to prevent being charged twice, every field should be as strict as the ones next to it — right now this one isn't.
    - **Evidence:**
        ```sql
        "kind" text NOT NULL,                    -- http | actor | api | ai
        ```

- [x] **SCHEMA-3** · P2 — `ingest.anomalies.kind` documents a closed 5-value set in a comment but has no CHECK constraint
    - **Where:** supabase/migrations/20260727130000_ingest_schema.sql:184
    - **Affects:** The human-triage queue for ingest anomalies (delete-guard trips, schema drift, stranded runs) — an invalid `kind` would silently produce a triage-queue row that staff tooling filtering/grouping by `kind` doesn't recognise, potentially hiding a real anomaly from the queue view.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CHECK ("kind" IN ('delete_guard', 'shape', 'drift', 'stranded', 'schema'))` matching the documented domain.
        - Use `NOT VALID` + `VALIDATE` if applied after rows exist.
    - **Technical:** Same pattern as SCHEMA-2, same file: `-- delete_guard | shape | drift | stranded | schema` documents a closed set with no `CHECK`, while the adjacent `severity` column on the same table (`CHECK ("severity" IN ('info', 'warning', 'critical'))`) is properly constrained. This is the delete-guard's human-escalation path (`ingest.anomalies` is "things a human must look at" per the file's own header comment) — a bug that writes an unrecognised `kind` degrades exactly the safety net meant to catch destructive-delete false positives.
    - **Plain English:** When the ingest pipeline detects something that needs a human to look at it — like "this looks like we're about to delete way too much data" — it writes a row into this table with a category label. Only five categories are supposed to exist, but the database doesn't check that. If a bug ever writes a sixth, made-up category, the staff dashboard that watches for these categories might silently miss it.
    - **Evidence:**
        ```sql
        "kind" text NOT NULL,                    -- delete_guard | shape | drift | stranded | schema
        ```

## Suggested Bundled Sessions

None — every finding in this audit is a schema/constraint change (`ALTER TABLE ... ADD CONSTRAINT`), which is a DB migration and therefore standalone per policy regardless of size or shared root cause.

## Standalone — do NOT bundle

- **SCHEMA-1 — `content.source_items.kind` missing CHECK** · DB migration/schema change (new constraint on a live-ish table).
- **SCHEMA-2 — `ingest.effects.kind` missing CHECK** · DB migration/schema change; also touches the billing/effect-ledger table.
- **SCHEMA-3 — `ingest.anomalies.kind` missing CHECK** · DB migration/schema change.
