# Test↔Prod Parity Audit — 2026-07-28

**Branch:** development
**Lens:** Test↔prod schema parity: application writes that pass SQLite CI but violate Postgres constraints
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- supabase/migrations/20260727100000_catalog_schema.sql
- supabase/migrations/20260727120000_routing_schema.sql
- supabase/migrations/20260727130000_ingest_schema.sql
- supabase/migrations/20260727140000_content_schema.sql
- supabase/migrations/20260727150000_sections_and_documents.sql
- supabase/migrations/20260727110000_connections_surface_key.sql
- supabase/migrations/20260728150000_field_bindings.sql
- supabase/migrations/20260726000000_baseline_pilot.sql
- tests/Pest.php (setupSectionsTables, setupIngestTables, setupRoutingTables, setupFieldBindingsTable, setupSitesTable, setupContentTables)
- app/Models/Core/Site/{Section,SectionItem,FieldBinding,IntegrationConnection}.php
- app/Http/Requests/Api/User/Sections/{Store,Update}SectionRequest.php, UpsertSectionItemRequest.php
- app/Http/Controllers/Api/Site/{Section,SectionItem}Controller.php
- app/Routing/{SourceReconciler,RoutingContext,Verdict,Importers/LinkInBioImporter}.php
- app/Ingest/Projection/{ProjectionWriter,*Projector}.php
- app/Services/Profile/FieldBindingSeeder.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 1 of 1 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [x] **#PARITY-1** · P2 — `site.sections`, `site.section_items`, `content.items`, and `routing.source_intents` CHECK constraints are absent from the SQLite test mirror — every current write path happens to be defended elsewhere, but nothing would catch a regression
    <!-- ALSO closed the reason #PARITY-1 was possible. tests/Feature/Architecture/SchemaDriftGuardTest.php could never have caught it: scripts/launch-check/refresh-schema-snapshot.php's SCHEMAS const omitted content/ingest/routing/catalog (so three of the four tables were invisible), the snapshot was stale at latest_migration 20260724150957 (pre-dating the whole content-platform rebuild), helper discovery via get_defined_functions() picked up seven test-file-local setup* helpers making the comparison surface vary by invocation, and ddlCoversCheck() credited a CHECK as covered on a shared VALUE LITERAL (on_empty and stale_display both contain 'hide'). G1/G3/G4 fixed the scope, determinism and matching; G2 adds a snapshot-staleness assertion, left INERT behind SCHEMA_SNAPSHOT_STALENESS_GATE because refreshing needs dev credentials the run did not have. Also found and fixed a SECOND unreported instance: setupContentTables() and setupContentCurationTables() both declared content.sources and content.identity_decisions, and the duplicates had LOST their CHECKs — five test files ran unprotected. -->
    - **Where:** `tests/Pest.php` `setupSectionsTables()` (~L2014, ~L2038), `setupContentTables()`/`setupSectionsTables()` (~L1985), `setupRoutingTables()` (~L2452) — compared against `supabase/migrations/20260727150000_sections_and_documents.sql` (§`site.sections`, §`site.section_items`), `supabase/migrations/20260727140000_content_schema.sql` (§`content.items`), `supabase/migrations/20260727120000_routing_schema.sql` (§`routing.source_intents`)
    - **Affects:** Any future write path to these four tables — a new admin/staff tool, a bulk-fix script, a new projector, or a regression in the existing Form Request rules — would ship green through `composer test` and 500 on Postgres.
    - **Effort:** S (~0.5–1h) — mechanical: add the CHECK clauses to the four SQLite `CREATE TABLE` statements
    - **What to do:**
        - Add `CHECK (kind IN (...))`, `CHECK (slot IN (...))`, `CHECK (mode IN (...))`, `CHECK (group_by IS NULL OR group_by IN (...))`, `CHECK (render IN (...))`, `CHECK (on_empty IN (...))`, `CHECK (stale_display IN (...))` to `setupSectionsTables()`'s `site.sections` statement, matching `20260727150000_sections_and_documents.sql` verbatim.
        - Add `CHECK (state IN ('pinned','excluded'))` to the `site.section_items` statement.
        - Add `CHECK (kind IN ('video','track','release','episode','channel','service','menu_item','product','event','link','media','review','document','article'))` to the `content.items` statement.
        - Add `CHECK (state IN ('proposed','applied','blocked','dismissed','superseded'))`, the `block_reason` CHECK, and `CHECK (origin IN (...))` to the `routing.source_intents` statement.
        - No application code change needed today — see Technical for why the current write paths are already safe.
    - **Technical:** The real DDL declares CHECK constraints on all of these columns (e.g. `"kind" text NOT NULL CHECK ("kind" IN ('collection', 'richtext', 'contact_form', 'newsletter', 'map', 'document', 'policy'))` on `site.sections`), but the SQLite mirrors in `tests/Pest.php` declare the same columns as plain `TEXT NOT NULL` with no CHECK clause at all — unlike most of the rest of the new schemas (`ingest.sources.health`, `ingest.effects.status`, `site.platform_connections.routing_class`/`last_refresh_status`/`apify_status`/`resource_kind`, `site.field_bindings`'s manual-priority CHECK, and `site.sites.moderation_state` all correctly replicate their CHECKs in the SQLite DDL and are enforced there — SQLite natively enforces inline `CHECK` clauses; it does not silently ignore them the way it ignores `PRAGMA foreign_keys`). Verified reachable write paths today: `SectionController::store/update` route `kind`/`slot`/`mode`/`group_by`/`render`/`on_empty`/`stale_display` through `StoreSectionRequest`/`UpdateSectionRequest`, both of which apply `Rule::in()` matching the CHECK sets exactly; `SectionItemController::upsert` routes `state` through `UpsertSectionItemRequest`'s `Rule::in(['pinned','excluded'])`; `content.items.kind` is set from each `Projector::kind()` static method (all 19 current implementations return a value inside the CHECK set); `routing.source_intents.state` comes from the closed `Verdict::intentState()` enum match, and the three insert sites for `origin` (`SourceReconciler`, `LinkInBioImporter` which coerces to a closed `KINDS` list, `StoreBrandSeeder` which defaults to `'paste'`) do not currently pass an out-of-set literal. So there is no live green-CI-prod-500 today — but every one of those guards lives in application code that could regress (a validation rule dropped in a refactor, a new projector with a typo'd `kind()`, a new system seeder bypassing the Form Request) with zero test signal, because the SQLite schema itself has nothing to enforce.
    - **Plain English:** Four database tables have "approved word lists" in production — for example, a page section's `kind` must be one of seven specific words. Right now, every place in the code that can set these words is double-checked by a separate safety net (a validation rule) before the database is ever touched, so nothing is broken today. But the test database's copy of these tables doesn't have the approved word list at all — it accepts anything. That means if someone ever adds a new way to write to these tables (a new import tool, a bulk-fix script, a small mistake in the safety net) and forgets to re-apply that same word-list check, the tests will still pass, and the mistake will only be discovered in production, in front of real users. This is a five-minute fix: teach the test database the same word lists production already has.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260727150000_sections_and_documents.sql:43
        "kind" text NOT NULL CHECK ("kind" IN ('collection', 'richtext', 'contact_form', 'newsletter', 'map', 'document', 'policy')),
        -- supabase/migrations/20260727150000_sections_and_documents.sql:74
        "state" text NOT NULL CHECK ("state" IN ('pinned', 'excluded')),
        -- supabase/migrations/20260727140000_content_schema.sql:43-46
        "kind" text NOT NULL CHECK ("kind" IN (
            'video', 'track', 'release', 'episode', 'channel', 'service', 'menu_item',
            'product', 'event', 'link', 'media', 'review', 'document', 'article'
        )),
        ```
        ```php
        // tests/Pest.php:2014-2036 — site.sections SQLite mirror: no CHECK on kind/slot/mode/render/on_empty/stale_display
        $pg->statement('CREATE TABLE IF NOT EXISTS site.sections (
            id TEXT PRIMARY KEY NOT NULL,
            ...
            slot TEXT NOT NULL DEFAULT \'body\',
            kind TEXT NOT NULL,
            ...
            mode TEXT NOT NULL DEFAULT \'automatic\',
            ...
            render TEXT NOT NULL DEFAULT \'cards\',
            ...
        )');
        // tests/Pest.php:2038-2045 — site.section_items: no CHECK on state
        $pg->statement('CREATE TABLE IF NOT EXISTS site.section_items (
            id TEXT PRIMARY KEY NOT NULL,
            section_id TEXT NOT NULL,
            item_id TEXT NOT NULL,
            state TEXT NOT NULL,
            sort_key REAL NULL,
            created_at TEXT NOT NULL
        )');
        ```

## Suggested Bundled Sessions

- **Bundle 1 — SQLite CHECK-constraint parity for the sections/content/routing mirrors:** #PARITY-1
    - **Why grouped:** single finding, single file (`tests/Pest.php`), mechanical CHECK-clause additions across four `CREATE TABLE` statements.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet). No escalation needed — this is copy-the-DDL-verbatim work.

## Standalone — do NOT bundle

None.
