# Data Integrity & Privacy Audit — 2026-07-28

**Branch:** development
**Lens:** Data integrity & privacy — FK hygiene, soft-delete coherence, orphan rows, JSONB drift, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- supabase/migrations/20260727130000_ingest_schema.sql
- supabase/migrations/20260727140000_content_schema.sql
- supabase/migrations/20260727150000_sections_and_documents.sql
- supabase/migrations/20260726000000_baseline_pilot.sql
- app/Models/Content/Item.php
- app/Models/Core/Site/SectionItem.php, Section.php, Page.php, SiteMedia.php, IntegrationConnection.php
- app/Services/Content/ItemMerger.php
- app/Content/Identity/DisjointSet.php
- app/Ingest/Landing/Lander.php
- app/Ingest/Projection/ProjectionWriter.php
- app/Services/User/AccountDeletionService.php
- app/Services/User/DataExport/DataExportPayloadBuilder.php
- app/Enums/SitepageId.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 3 of 3 complete
- P2 Medium: 7 of 7 complete
- P3 Low: 0 of 6 complete

---

## P1 — Fix before pilot launch

- [x] **DINT-1** · P1 — `ingest.record_versions` has no FK to `ingest.streams`; the highest-volume table in the system survives every cascade that deletes its parents
    - **Where:** supabase/migrations/20260727130000_ingest_schema.sql:107-117
    - **Affects:** Every user who disconnects an integration or deletes their account — the raw, post-redaction vendor document (which can carry venue names, addresses, reviewer names, etc. per the `content.f_place`/`content.f_review` facets these records eventually feed) is retained forever, unreachable and undeletable, after `ingest.sources` → `ingest.streams` cascade away.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - `ALTER TABLE ingest.record_versions ADD CONSTRAINT record_versions_stream_id_fk FOREIGN KEY (stream_id) REFERENCES ingest.streams(id) ON DELETE CASCADE;` — Postgres 12+ permits a foreign key on a hash-partitioned table's partition key.
        - Confirm the `INSERT ... ON CONFLICT DO NOTHING` write path in `Lander::land()` is unaffected (it already targets `idx_record_versions_content`, unrelated to this FK).
        - Add a regression test that deletes an `ingest.sources` row and asserts zero `ingest.record_versions` rows remain for its streams.
    - **Technical:** `AccountDeletionService::purge()` states explicitly that hard-deleting the professional row is what "triggers every FK cascade" (42 FKs CASCADE) and only lists tables reached by an *explicit* keyed delete in `PURGED_PII_TABLES` when no FK cascade reaches them. `ingest.sources` and `ingest.streams` both cascade correctly from `core.users`/`ingest.sources` respectively (confirmed in the migration), so an account deletion correctly tears those down — but `ingest.record_versions.stream_id` carries no `REFERENCES` clause at all, so those rows are neither cascaded nor covered by any explicit purge step. Every sibling table in the same migration that hangs off `ingest.streams` (`record_state`, `anomalies`) has the FK; `record_versions` — described in its own migration comment as "the highest-volume table in the system" — is the sole exception.
    - **Plain English:** When someone disconnects a platform or deletes their account, the system is supposed to clean up everything it stored about them. Almost every table does get cleaned up automatically, like dominoes falling in sequence. But the biggest table of all — the one holding the raw, unprocessed copy of everything ever fetched from a connected platform — isn't wired into that chain. Those records just sit there forever, invisible to search, but never actually gone. That's a broken promise about deleting someone's data.
    - **Evidence:**
        ```sql
        CREATE TABLE "ingest"."record_versions" (
            "id" bigserial NOT NULL,
            "stream_id" uuid NOT NULL,
            "key" text NOT NULL,                     -- vendor-stable id within the stream
            "doc_hash" text NOT NULL,                -- sha256 over the canonicalised doc
            "doc" jsonb NOT NULL,                    -- verbatim, POST-redaction
            "first_seen_run" uuid,
            "first_seen_at" timestamp with time zone NOT NULL DEFAULT now(),
            "is_current" boolean NOT NULL DEFAULT true,
            PRIMARY KEY ("id", "stream_id")
        ) PARTITION BY HASH ("stream_id");
        ```

- [x] **DINT-2** · P1 — The entire new ingest/content pipeline's PII is invisible to both GDPR data export and account-deletion erasure
    - **Where:** app/Services/User/DataExport/DataExportPayloadBuilder.php:36-47, 145-203; app/Services/User/AccountDeletionService.php:50-57
    - **Affects:** Every user whose sitepage content comes through the new connector/ingest system (Instagram, Spotify, GBP reviews, menu connectors, etc.) — their DSAR export omits this data entirely, and place/venue/reviewer PII embedded in it (`content.f_place.address`, `content.f_review.author_name`) is undocumented for erasure purposes.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Add a `content`/`ingest` section to `DataExportPayloadBuilder::sectionDescriptors()` streaming `content.items` joined to its facets (at minimum `f_place`, `f_text`, `f_review`, `f_authored`) scoped by `user_id`, and add the relevant table names to `COVERED_PII_TABLES`.
        - Confirm `AccountDeletionService::purge()`'s reliance on cascade actually reaches every content/ingest table (per DINT-1, it currently does not for `ingest.record_versions`); once DINT-1 is fixed, add a comment to `PURGED_PII_TABLES` explaining why content/ingest tables are covered by cascade rather than an explicit purge call, so the next engineer doesn't have to re-derive it.
        - Add a `DataExportCoverageTest`-style assertion that every PII-bearing column in `content.*` (address, venue_name, author_name, creator, handle) is reachable from `sectionDescriptors()`.
    - **Technical:** `DataExportPayloadBuilder::COVERED_PII_TABLES` and `sectionDescriptors()` — the single manifest both `build()` and `stream()` derive from — list `core.users`, `site.customers`, `site.enquiries`, `site.workplaces`, etc., but contain no `content.*` or `ingest.*` entry. `AccountDeletionService::PURGED_PII_TABLES` (the explicit-purge list for anything no FK cascade reaches) is similarly silent on both schemas. Both lists predate the 2026-07-27 content/ingest migrations by exactly one day and were never updated. `content.f_place` (venue_name, address, locality, region — Article 4(1) personal data for a home-based sole trader) is the clearest concrete example, but the gap is systemic across the whole pipeline.
    - **Plain English:** Two brand-new database tables went live yesterday to store everything pulled in from connected platforms — Instagram posts, Google Business reviews, menu items, and so on. Some of that data includes real addresses and reviewer names. The feature that lets a user download "everything we know about you," and the feature that's supposed to erase it all when they delete their account, were both built before these new tables existed — and nobody has gone back to teach them about the new data yet. Right now, a user's download would be missing a whole category of their own information, and it's unclear whether deleting their account actually reaches all of it either.
    - **Evidence:**
        ```php
        // DataExportPayloadBuilder.php — no content/ingest entry anywhere in this list
        public const COVERED_PII_TABLES = [
            'core.users', 'core.early_access_signups', 'core.pre_account_builds',
            'core.feedback', 'site.customers', 'site.enquiries', 'site.workplaces',
            'notifications.email_subscriptions', 'audit.data_export_audit', 'audit.user_deletion_audit',
        ];
        ```
        ```php
        // AccountDeletionService.php — same silence
        public const PURGED_PII_TABLES = [
            'core.users', 'core.early_access_signups', 'core.feedback',
            'notifications.email_subscriptions', 'analytics.item_views', 'analytics.action_events',
        ];
        ```
        ```sql
        -- The concrete PII this misses (content_schema.sql:283-295):
        CREATE TABLE "content"."f_place" (
            "item_id" uuid NOT NULL REFERENCES "content"."items" ("id") ON DELETE CASCADE,
            "venue_name" text,
            "address" text,
            "locality" text,
            "region" text,
            ...
        );
        ```

- [x] **DINT-16** · P1 — Reverted content never re-projects: `land()` reports `changed: 0` when a doc returns to a previous version, and projection is gated on that counter
    - **Where:** app/Ingest/Landing/Lander.php:59-77 · app/Ingest/Runtime/RunExecutor.php:168
    - **Affects:** Every projected surface on the public sitepage — menu items, events, releases, reviews. When a vendor reverts a record to a value it previously held, the public page keeps serving the superseded version indefinitely, until some unrelated change to the same stream forces a projection.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Treat "the current version changed" as the change signal, not "a row was inserted". Compare the pre-existing `record_state.current_version_id` for the key against the `$versionId` resolved after the write, and increment `$changed` when they differ.
        - Move the demote out from under `if ($inserted > 0)` so it runs whenever the resolved current version differs from what is flagged — otherwise `is_current` stays on the superseded row.
        - Add a regression test landing hash A → hash B → hash A and asserting `changed === 1` on the third landing, and that exactly one `record_versions` row for the key has `is_current = true`.
    - **Technical:** `insertOrIgnore` conflicts on `idx_record_versions_content` (`stream_id, key, doc_hash`), so re-landing a doc whose hash was seen before returns 0 and `$changed` is not incremented. The demote is nested inside `if ($inserted > 0)`, so it is skipped too, leaving `is_current = true` on the *other* version's row and `is_current = false` on the row that is actually current — `idx_record_versions_current` then indexes the wrong row. `record_state.current_version_id` is correct, because the version-id lookup and upsert run unconditionally on every landing, so the two sources of truth disagree with no crash involved. The user-visible consequence comes from `RunExecutor.php:168`, which gates `projectStream()` on `$landed['changed'] > 0 || $landed['tombstoned'] > 0`; a revert satisfies neither, so projection is skipped and the previously projected content item is never rewritten. This is distinct from DINT-9, which covers the crash window between the same four statements — this one needs no crash and fires on the ordinary path. Nothing reads `record_versions.is_current` today (`grep` finds no consumer), which is why the flag desync alone would be P2; the skipped projection is what makes it P1.
    - **Plain English:** The system decides "did anything change?" by checking whether it had to file a brand-new copy of the content. If a shop changes a price from $10 to $12 and then puts it back to $10, the system already has a $10 copy on file, so it files nothing new and concludes nothing changed. It then skips the step that updates the public page, which is still showing $12. The page stays wrong until something else about that shop changes and forces a refresh. Undoing an edit is a completely ordinary thing for people to do, so this isn't a rare corner case.
    - **Evidence:**
        ```php
        // Lander.php:59-77 — insert returns 0 on a hash seen before, so neither
        // $changed nor the demote runs.
        $inserted = DB::table('ingest.record_versions')->insertOrIgnore([
            'stream_id' => $streamId,
            'key' => $record->key,
            'doc_hash' => $hash,
            ...
            'is_current' => true,
        ]);

        if ($inserted > 0) {
            $changed++;
            // Demote the previous current version for this key.
            DB::table('ingest.record_versions')
                ->where('stream_id', $streamId)
                ->where('key', $record->key)
                ->where('doc_hash', '!=', $hash)
                ->update(['is_current' => false]);
        }
        ```
        ```php
        // RunExecutor.php:168 — projection is gated on that same counter.
        if (($landed['changed'] > 0 || $landed['tombstoned'] > 0)
            && ProjectorRegistry::has((string) $source['source_key'], $streamName)) {
            try {
                $this->projections->projectStream($source, $streamId, $streamName);
        ```

## P2 — Should fix

- [x] **DINT-3** · P2 — `content.item_merges.kept_item_id` / `discarded_item_id` carry no FK to `content.items`
    <!-- premise holds, but the audit's PRESCRIBED REMEDY was rejected on inspection: ItemMerger.php:58 inserts the item_merges audit row and :76 deletes the discarded content.items row IN THE SAME TRANSACTION, so an ON DELETE SET NULL FK would null the ledger on write — destroying the audit trail the FK was meant to protect. Resolved by 20260729150019_item_merges_no_fk_rationale.sql (COMMENT ON COLUMN recording the reasoning) plus tests/Postgres/ItemMergeAuditSurvivalTest.php. NO FK added, deliberately. -->
    - **Where:** supabase/migrations/20260727140000_content_schema.sql:135-143
    - **Affects:** The merge audit trail — once either item is deleted, the history row silently points at nothing.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE content.item_merges ADD CONSTRAINT item_merges_kept_item_id_fk FOREIGN KEY (kept_item_id) REFERENCES content.items(id) ON DELETE SET NULL;` (same for `discarded_item_id`) — both columns are already nullable, so `SET NULL` requires no further schema change.
    - **Technical:** Every other item-pointer column in this migration (`identity_candidates.left_item_id`/`right_item_id`, `item_anchors.item_id`, `manual_overrides.item_id`, every facet table's `item_id`) carries an explicit FK; `item_merges` is the one exception. `content.items` cascades from `core.users`, so a deleted user's merge history loses referential integrity with nothing enforcing it.
    - **Plain English:** When the system decides two items were really duplicates, it writes a permanent note: "A and B were the same thing, we kept A." If A or B is later deleted, that note still points at IDs that no longer exist — the database has no rule requiring it to notice or clean that up.
    - **Evidence:**
        ```sql
        CREATE TABLE "content"."item_merges" (
            "id" bigserial PRIMARY KEY,
            "user_id" uuid NOT NULL REFERENCES "core"."users" ("id") ON DELETE CASCADE,
            "kept_item_id" uuid,
            "discarded_item_id" uuid,
            "reason" text NOT NULL,
            ...
        );
        ```

- [x] **DINT-4** · P2 — `site.section_items.item_id` has no FK to `content.items`, across a schema boundary the model docblock treats as a hard blocker
    - **Where:** supabase/migrations/20260727150000_sections_and_documents.sql:70-79; app/Models/Core/Site/SectionItem.php:22
    - **Affects:** Any curated pin/exclude whose referenced item is hard-deleted outside `ItemMerger::foldInto()` (which does correctly repoint these rows today) — a future purge job or manual cleanup would silently orphan curation rows.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ALTER TABLE site.section_items ADD CONSTRAINT section_items_item_id_fk FOREIGN KEY (item_id) REFERENCES content.items(id) ON DELETE CASCADE;` — Postgres fully supports cross-schema FKs; the model docblock's "no FK: content lives in another schema" is not a real constraint.
    - **Technical:** `ItemMerger::foldInto()` already moves `site.section_items` rows onto the surviving item on every merge it handles, so the documented merge path is safe today. The residual risk is any *other* hard-delete of `content.items` (e.g. a future GC job per DINT-10) that doesn't route through `ItemMerger` — nothing at the DB layer would stop that from orphaning curation rows.
    - **Plain English:** A user can pin a specific item to a section of their page. That pin is stored as a raw ID pointing at the content system, in a different part of the database, with nothing enforcing that the pinned thing still exists. Today the one place items get deleted already remembers to move the pin along — but there's no safety net if a future code path deletes an item some other way.
    - **Evidence:**
        ```sql
        CREATE TABLE "site"."section_items" (
            "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            "section_id" uuid NOT NULL REFERENCES "site"."sections" ("id") ON DELETE CASCADE,
            "item_id" uuid NOT NULL,
            "state" text NOT NULL CHECK ("state" IN ('pinned', 'excluded')),
            ...
        );
        ```

- [x] **DINT-5** · P2 — `content.source_items.kind` has no CHECK constraint while `content.items.kind` (the very next table) does
    - **Where:** supabase/migrations/20260727140000_content_schema.sql:43-46, 78
    - **Affects:** Content ingestion — a projector bug can write a `source_items.kind` value the rest of the system's 14-value vocabulary doesn't recognize.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add the identical CHECK to `content.source_items.kind`, or the documented superset if `source_items` genuinely needs more values than `items`.
    - **Technical:** `content.items.kind` enumerates 14 closed values via CHECK; `content.source_items.kind text NOT NULL` two tables later has no such guard, despite being the actual ingress point every projector writes through.
    - **Plain English:** The final "items" table has a strict approved list of content types. The raw-record table that feeds it has no such list — a buggy connector could write an unrecognized type and the database would happily store it, only for the dashboard to not know what to do with it.
    - **Evidence:**
        ```sql
        -- content.items: constrained
        "kind" text NOT NULL CHECK ("kind" IN (
            'video', 'track', 'release', 'episode', 'channel', 'service', 'menu_item',
            'product', 'event', 'link', 'media', 'review', 'document', 'article'
        )),
        -- content.source_items: unconstrained
        "kind" text NOT NULL,
        ```

- [x] **DINT-6** · P2 — `ingest.sources` unique constraint `(connection_id, source_key)` is bypassable when `connection_id` is NULL
    - **Where:** supabase/migrations/20260727130000_ingest_schema.sql:17-18, 44
    - **Affects:** Ingest scheduling — duplicate source rows for the same logical source cause duplicate fetches and conflicting stream state.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either add `NOT NULL` to `connection_id` if every source must have one, or add a partial unique index scoped to `connection_id IS NULL` covering whatever the true dedup key is in that case (e.g. `(user_id, source_key)`).
    - **Technical:** `connection_id` is a nullable FK; Postgres treats every NULL as distinct under a plain `UNIQUE(connection_id, source_key)`, so any number of NULL-connection rows sharing the same `source_key` can coexist.
    - **Plain English:** The rule "one source per connection" has a loophole: rows with no connection at all don't count as duplicates of each other, no matter how many pile up, because the database treats "nothing" as always different from "nothing else."
    - **Evidence:**
        ```sql
        "connection_id" uuid REFERENCES "site"."platform_connections" ("id") ON DELETE CASCADE,
        ...
        CONSTRAINT "sources_unique_per_connection" UNIQUE ("connection_id", "source_key")
        ```

- [x] **DINT-7** · P2 — `site.sections` stores both `page_id` and `site_id` independently, with nothing enforcing they agree
    - **Where:** supabase/migrations/20260727150000_sections_and_documents.sql:34-37
    - **Affects:** Any query or authorization check that reads `sections.site_id` directly instead of joining through `pages` — a mismatched row would silently produce wrong results for whichever path is taken.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a trigger enforcing `NEW.site_id = (SELECT site_id FROM site.pages WHERE id = NEW.page_id)` on INSERT/UPDATE, or drop the denormalised `site_id` column and always join through `pages`.
    - **Technical:** Both columns are independently `NOT NULL` with their own FKs to different tables; nothing prevents inserting a section whose `page_id` belongs to a different site than its `site_id` claims.
    - **Plain English:** Every section belongs to a page, and every page belongs to a site — but the section also separately records which site it's on, as a shortcut. If those two ever disagree, different parts of the app checking different columns would give different answers about which site owns the section.
    - **Evidence:**
        ```sql
        CREATE TABLE "site"."sections" (
            "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
            "page_id" uuid NOT NULL REFERENCES "site"."pages" ("id") ON DELETE CASCADE,
            "site_id" uuid NOT NULL REFERENCES "site"."sites" ("id") ON DELETE CASCADE,
            ...
        );
        ```

- [x] **DINT-8** · P2 — `site.platform_connections.created_at`/`updated_at` have a DEFAULT but no NOT NULL, unlike every other table in the baseline
    - **Where:** supabase/migrations/20260726000000_baseline_pilot.sql:1853-1854; app/Models/Core/Site/IntegrationConnection.php:43-44
    - **Affects:** Every query sorting or filtering by connection age — a row with an explicit-NULL timestamp (bypassing the DEFAULT) sorts unpredictably and is invisible to range filters; the model's own `scopeStrandedPending` already carries a `whereNotNull('updated_at')` guard specifically because of this.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Backfill any NULL rows, then `ALTER TABLE site.platform_connections ALTER COLUMN created_at SET NOT NULL, ALTER COLUMN updated_at SET NOT NULL;`
    - **Technical:** Confirmed against the baseline: `"created_at" timestamp with time zone DEFAULT "now"()` with no `NOT NULL`, versus e.g. `site.service_categories` in the same file which has `DEFAULT now() NOT NULL`. A DEFAULT-without-NOT-NULL means an explicit `NULL` in an INSERT bypasses the default entirely, so the column's own model docblock has to warn every consumer about it.
    - **Plain English:** Almost every table guarantees every row has a creation date. This one table doesn't — a row can technically be born with no date at all — forcing every piece of code that reads it to first check "does this row even have a date?" before trusting it.
    - **Evidence:**
        ```sql
        "created_at" timestamp with time zone DEFAULT "now"(),
        "updated_at" timestamp with time zone DEFAULT "now"(),
        ```

- [x] **DINT-9** · P2 — `Lander::land()` performs an insert-then-demote-then-select-then-upsert sequence per record with no transaction wrapping it
    - **Where:** app/Ingest/Landing/Lander.php:53-98
    - **Affects:** Every ingest run — a crash mid-loop can leave `is_current` flags demoted with no corresponding `record_state` update, or a `record_state` row pointing at a version that never got demoted correctly.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the per-record write sequence (insert, conditional demote, version-id lookup, `record_state` upsert) in `DB::transaction()`.
    - **Technical:** `land()` issues four independent statements per record with no atomicity between them. A process death between the `insertOrIgnore` and the `record_state` upsert leaves the new version marked `is_current` but `record_state.current_version_id` still pointing at the stale row — the two sources of truth disagree until the next successful run for that key.
    - **Plain English:** Landing one piece of content is really four separate database writes done one after another. If the process crashes partway through — a deploy, an out-of-memory kill — some of those writes happened and others didn't, leaving two parts of the system disagreeing about which version of the content is current.
    - **Evidence:**
        ```php
        $inserted = DB::table('ingest.record_versions')->insertOrIgnore([...]);
        if ($inserted > 0) {
            DB::table('ingest.record_versions')->where(...)->update(['is_current' => false]);
        }
        $versionId = DB::table('ingest.record_versions')->where(...)->value('id');
        DB::table('ingest.record_state')->upsert([[...]], [...], [...]);
        ```

## P3 — Nice to have

- [ ] **DINT-10** · P3 — `content.items` uses `removed_at` instead of `deleted_at`, and no scheduled command purges it
    - **Where:** supabase/migrations/20260727140000_content_schema.sql:52-54, 64-65; app/Models/Content/Item.php (no `SoftDeletes` trait)
    - **Affects:** Long-run storage/index growth — removed items and everything anchored to them (via the not-yet-added FK in DINT-4) never get reclaimed.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Confirmed: `routes/console.php` schedules `partna:purge-soft-deletes` only; no `content:purge`-style command exists anywhere in `app/Console`. Add one, gated on `removed_at < now() - retention window`, using the `idx_content_items_gc` index the migration already provisioned for exactly this purpose.
    - **Technical:** `removed_at` deliberately isn't Laravel `SoftDeletes` (never cleared by reappearance, collapses several dispositions into one column per the migration comment), so the existing `partna:purge-soft-deletes` sweep — keyed on `deleted_at` — cannot see these rows regardless of whether `Item` were added to its model list. The GC index exists; nothing consumes it yet.
    - **Plain English:** Most "deleted" records get automatically cleaned up 30 days later by a nightly janitor. This table's version of "deleted" uses a different name and different rules on purpose — but that also means the janitor doesn't know to look here, so removed items just accumulate indefinitely.
    - **Evidence:**
        ```sql
        "removed_at" timestamp with time zone,
        ...
        CREATE INDEX "idx_content_items_gc" ON "content"."items" ("last_seen_at")
            WHERE ("removed_at" IS NOT NULL);
        ```

- [ ] **DINT-11** · P3 — `ItemMerger::foldInto()`'s child-table inventory is a hand-maintained list with no test guarding it against schema drift
    - **Where:** app/Services/Content/ItemMerger.php:236-284
    - **Affects:** Future schema changes — a new table referencing `content.items` that isn't added to this list would either lose rows silently (if `ON DELETE CASCADE`) or block every merge (if `RESTRICT`) once `foldInto()`'s trailing hard-delete runs.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a test that introspects `information_schema` for FKs targeting `content.items` and asserts each is either handled in `foldInto()` or on a documented exemption list (facet tables are exempt by design — they're derived, not moved).
    - **Technical:** `foldInto()` explicitly enumerates `source_items`, `item_anchors`, `manual_overrides`, `section_items`, `item_slugs`, `collection_items`, `identity_candidates` before hard-deleting the discarded row. Nothing enforces that this list stays in sync with the schema as new tables are added.
    - **Plain English:** Merging two duplicate items has a manual checklist of every place old data about the loser might live, so it can move that data to the winner before deleting the loser. If a future update adds a new place for that data and forgets to update the checklist, deleting the loser could silently destroy real user data with no warning.
    - **Evidence:**
        ```php
        return [
            'sourceItems' => DB::table('content.source_items')->where('item_id', $discarded->id)->update(['item_id' => $kept->id]),
            'anchors' => DB::table('content.item_anchors')->where('item_id', $discarded->id)->update(['item_id' => $kept->id, 'superseded_by' => $kept->id]),
            'overrides' => $this->moveWithoutClobbering('content.manual_overrides', ...),
            'pins' => $this->moveWithoutClobbering('site.section_items', ...),
            'slugs' => $this->moveSlugs($kept, $discarded),
            'collections' => $this->moveWithoutClobbering('content.collection_items', ...),
            'candidates' => DB::table('content.identity_candidates')->where(...)->update(['dismissed_at' => now()]),
        ];
        ```

- [ ] **DINT-12** · P3 — `ItemMerger::separate()`'s docblock still describes a `DisjointSet` bug that was fixed the same day
    - **Where:** app/Services/Content/ItemMerger.php:93-100; app/Content/Identity/DisjointSet.php:71-78; tests/Feature/Content/IdentityQueueTest.php:221-224
    - **Affects:** Developer trust in this file's comments — a future engineer reading the "KNOWN GAP...fails to split about half the time" note would reasonably believe a live P1 bug still exists.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Update the `separate()` docblock to describe current behavior; the characterisation test's own comment already documents the fix date.
    - **Technical:** `DisjointSet::separate()` was rewritten to re-root whichever argument is *not* the group root (`$detach = $this->find($b) === $b ? $a : $b`), replacing the old always-re-root-`$b` behavior. `tests/Feature/Content/IdentityQueueTest.php` explicitly annotates this as "Old bug (fixed 2026-07-28)" — but `ItemMerger::separate()`'s docblock, in the same codebase, was never updated and still asserts the bug is live.
    - **Plain English:** A code comment claims a specific bug is currently making the "these are different" button fail about half the time. The bug was actually fixed today, but the comment describing it as broken never got updated — so the warning label is now itself the misleading part.
    - **Evidence:**
        ```php
        // ItemMerger.php — stale claim:
        * KNOWN GAP... a `different` ruling currently fails to split about half the time.
        ```
        ```php
        // DisjointSet.php:71-78 — the actual, current fix:
        $detach = $this->find($b) === $b ? $a : $b;
        $this->parent[$detach] = $detach;
        ```

- [ ] **DINT-13** · P3 — `SiteMedia.scanned_at` is excluded from `$casts`, unlike every other timestamp on the model
    - **Where:** app/Models/Core/Site/SiteMedia.php:36, 183-191
    - **Affects:** Any code reading `$media->scanned_at` expecting a `Carbon` instance — it gets a raw driver string instead.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'scanned_at' => 'datetime'` to `$casts`; audit existing call sites for string-comparison assumptions first.
    - **Technical:** `created_at`, `updated_at`, and `deleted_at` are all cast to `datetime`; `scanned_at` is not, per the model's own docblock. Calling a Carbon method on it (`->diffForHumans()`, `->isAfter()`) would error or silently misbehave.
    - **Plain English:** Every date field on this record works like a real calendar date except one, which comes back as plain text. Code that treats it like every other date on the same model will break.
    - **Evidence:**
        ```php
        * @property string|null $scanned_at CSAM-scan completion marker... NOT in $casts, so unlike
        *     every other timestamp column here this returns a raw driver string, not a Carbon instance.
        ```

- [ ] **DINT-14** · P3 — Legacy `PROVISIONAL` section-key mappings feed popularity scores at full signal strength with no downweighting
    - **Where:** app/Enums/SitepageId.php:129, 132, 136, 139
    - **Affects:** Popularity ranking accuracy for Contact, Gallery, Links, and Skool pages.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Downweight or tag `PROVISIONAL` entries in the `analytics:compute-popularity` job so consumers can distinguish confident signal from a best-guess legacy bucket.
    - **Technical:** `SECTION_KEY_TO_PAGE` maps four legacy `section_key` values (`google-business`, `about`, `other`, `community`) to a single best-guess page with an inline `PROVISIONAL` comment, but the scoring job has no mechanism to treat these differently from a confident 1:1 mapping.
    - **Plain English:** For four old, ambiguous data labels, the system makes a best guess about which page they really belong to and marks that guess "provisional" in a code comment — but the actual scoring math doesn't know the difference between a guess and a certainty, so those guesses count exactly as much as solid data.
    - **Evidence:**
        ```php
        'google-business' => 'contact', // PROVISIONAL — GB profile/location bucket
        'about' => 'gallery',           // PROVISIONAL — old About visual/photos role
        'other' => 'links',             // PROVISIONAL — legacy misc grouping
        'community' => 'skool',         // PROVISIONAL — legacy grouped section
        ```

- [ ] **DINT-15** · P3 — The `player-test` section-key omission is silent — nothing logs or counts it if test data ever reaches production
    - **Where:** app/Enums/SitepageId.php:141
    - **Affects:** Analytics observability if a misconfigured environment ever writes `section_key = 'player-test'` into a production table.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log or increment a metric in the scoring job whenever it encounters a `section_key` absent from `SECTION_KEY_TO_PAGE`, rather than silently contributing zero signal.
    - **Technical:** The omission is expressed only as a code comment ("Omitted intentionally: 'player-test'"), not as an observable code path — any unmapped key, test-fixture or otherwise, is currently indistinguishable from a deliberately-ignored one.
    - **Plain English:** There's one known test-only label that's deliberately left off the approved list. If that label ever leaked into the real database by mistake, the system would just quietly ignore that data with no alert — the same as if it had been left off on purpose.
    - **Evidence:**
        ```php
        // Omitted intentionally: 'player-test' (test-fixture noise)
        ```

## Suggested Bundled Sessions

- **Bundle 1 — `Lander::land()` write sequence:** DINT-16, DINT-9
    - **Why grouped:** Both rewrite the same four statements in `Lander::land()` and conflict if done separately — DINT-9 wraps them in a transaction, DINT-16 restructures which of them are conditional. DINT-16 is P1, so this bundle hits the blocker gate: plan first, wait for sign-off before implementing.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Content/ingest app-code hygiene:** DINT-10, DINT-11, DINT-12, DINT-13
    - **Why grouped:** All app-level fixes in the Content/Ingest subsystem with no DB migration required — a new purge command, a test guard, and two stale-artifact cleanups.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Analytics section-key mapping polish:** DINT-14, DINT-15
    - **Why grouped:** Same file (`SitepageId.php`), same root cause — legacy section-key mapping observability.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **DINT-1 — `ingest.record_versions` missing FK to `ingest.streams`** · DB migration + PII retention consequence.
- **DINT-2 — Content/ingest PII missing from GDPR export & deletion wiring** · L-effort, GDPR-compliance-critical, touches two core privacy services.
- **DINT-3 — `content.item_merges` missing FK** · DB migration.
- **DINT-4 — `site.section_items.item_id` missing FK** · DB migration.
- **DINT-5 — `content.source_items.kind` missing CHECK** · DB migration.
- **DINT-6 — `ingest.sources` NULL-bypassable unique constraint** · DB migration.
- **DINT-7 — `site.sections` site_id/page_id drift** · DB migration (trigger).
- **DINT-8 — `IntegrationConnection` nullable timestamps** · DB migration (`ALTER COLUMN ... SET NOT NULL`).
