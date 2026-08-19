# Migration Safety Audit — 2026-08-18

**Branch:** HEAD
**Lens:** Migration safety: lock-on-deploy risk, backfill ordering, online DDL hygiene, reversibility
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- supabase/migrations/20260819001000_link_observations_allow_commerce_probe.sql
- supabase/migrations/20260819001100_item_media_role_video.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **#MIG-1** · P2 — `content.item_media_role_check` widened without the mandatory `NOT VALID` split
    - **Where:** supabase/migrations/20260819001100_item_media_role_video.sql:14-19
    - **Affects:** `content.item_media` writes/reads during deploy of this migration (InstagramMediaProjector, `PoolResolver::frames()`, sitepage media reads). More immediately: this file cannot merge as written — see Technical.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Split into two files following the repo's established `_not_valid.sql` / `_validate.sql` pairing (see e.g. `20260729150000_source_items_kind_check_not_valid.sql` + `...150001_..._validate.sql`, or the sibling exemplar cited under MIG-2):
          - File 1: `ADD CONSTRAINT item_media_role_check CHECK (role IN (...)) NOT VALID;`
          - File 2 (separate `BEGIN`/`COMMIT`): `VALIDATE CONSTRAINT item_media_role_check;`
        - No `SET LOCAL lock_timeout` addition is needed — `content.item_media` is not in the CI guard's `HOT_TABLES` list (`site.design_kits`, `site.sites`, `site.blocks`, `core.users`), so that check doesn't apply here.
    - **Technical:** Category 2. `scripts/guard-no-unsafe-migrations.php` **Check 3** ("`ADD CONSTRAINT CHECK without NOT VALID` detected") is a mechanical, CI-blocking gate that this file trips as written — it cannot currently be merged. The sibling migration `20260814100000_source_intents_allow_commerce_probe.sql`, widening the identical CHECK-vocabulary pattern on `routing.source_intents` five days earlier, does the two-window `NOT VALID` + separate-transaction `VALIDATE CONSTRAINT` split even though its own comment notes the widen is provably safe ("the guard is mechanical by design, and 'this particular ALTER is provably safe' is exactly the argument that stops being true when someone copies the file"). This file should follow the same convention. Note also: `content` is one of the schemas absent from production entirely (`content.items` does not exist there — see CLAUDE.md's Content Pool Convergence section) — so the near-term blast radius is the `development` environment where this table now holds real ingest data from the active media-pool work, not a live prod incident. That keeps this at the lens's own canonical P2 anchor for "missing NOT VALID + VALIDATE split," not P0/P1.
    - **Plain English:** When you widen a rule about what values are allowed in a column, the safe way is a two-step process: tell the database "this rule applies to new entries starting now" (fast), then separately go back and check the old entries fit the rule too (a bit slower, but doesn't block anyone). This migration does both steps at once, which briefly locks the whole table while every existing row gets checked. There's an automated gatekeeper in this project that already refuses to let this kind of change through un-split — so this file needs the two-step fix before it can even be merged.
    - **Evidence:**
        ```sql
        ALTER TABLE content.item_media
            DROP CONSTRAINT IF EXISTS item_media_role_check;

        ALTER TABLE content.item_media
            ADD CONSTRAINT item_media_role_check
            CHECK (role IN ('cover', 'gallery', 'poster', 'avatar', 'logo', 'video'));
        ```

- [ ] **#MIG-2** · P2 — `routing.link_observations_source_check` widened without the mandatory `NOT VALID` split, across every monthly partition
    - **Where:** supabase/migrations/20260819001000_link_observations_allow_commerce_probe.sql:16-22
    - **Affects:** `routing.link_observations` writes/reads during deploy (`CommerceProbeJob` observation inserts, routing reconciler reads). More immediately: this file cannot merge as written — see Technical.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Split into a `NOT VALID` file + a separate `VALIDATE CONSTRAINT` file, exactly matching the pattern already used for the sibling widen on `routing.source_intents` (`20260814100000_source_intents_allow_commerce_probe.sql`) — that file is the direct precedent to copy, down to the rollback-comment structure.
        - Because `link_observations` is `PARTITION BY RANGE (observed_at)`, the parent-level `ADD CONSTRAINT ... NOT VALID` propagates `NOT VALID` to each existing partition (`link_observations_2026_07/08/09`); the follow-up `VALIDATE CONSTRAINT` on the parent then validates each partition individually under `SHARE UPDATE EXCLUSIVE`, one at a time, instead of taking `ACCESS EXCLUSIVE` on all partitions simultaneously as the current unsplit version does.
    - **Technical:** Category 2 (with category-1 lock-multiplication flavor from partitioning). Same CI guard as MIG-1 — `guard-no-unsafe-migrations.php` Check 3 — flags this file's `ADD CONSTRAINT ... CHECK (...)` (no `NOT VALID`) and blocks merge. `routing` is one of the schemas absent from production entirely (per CLAUDE.md, prod lacks `content`/`ingest`/`routing`/`catalog` outright), so there is no live prod table for this migration to lock today; the near-term exposure is `development`, where `routing.link_observations` is actively written by `CommerceProbeJob` per this file's own comment (the bug being fixed is that those writes are currently failing the CHECK and being silently dropped). The partitioned structure means the unsplit version's `ACCESS EXCLUSIVE` exposure spans three relations (parent + 2026-07/08/09 partitions) at once rather than one table, which is a real aggravating factor versus MIG-1's non-partitioned `content.item_media` — but at present row counts (schema is 3 weeks old, pre-beta) this doesn't cross into P1/P0 territory per the lens's own calibration ("load conditions we don't hit today"). Tiered the same as MIG-2 per "same root cause, same tier."
    - **Plain English:** Same issue as the item-media finding, but on a busier, subdivided table (the routing history is split into one section per month). Locking the whole thing at once briefly stalls three sections of the table simultaneously instead of one. The fix is the same two-step approach already used correctly five days earlier for the near-identical change to a neighboring table — this file should copy that pattern instead of skipping it.
    - **Evidence:**
        ```sql
        ALTER TABLE routing.link_observations
            DROP CONSTRAINT IF EXISTS link_observations_source_check;

        ALTER TABLE routing.link_observations
            ADD CONSTRAINT link_observations_source_check
            CHECK (source = ANY (ARRAY['paste', 'website_import', 'link_in_bio',
                'bio_harvest', 'google_business', 'staff', 'reproject', 'commerce_probe']));
        ```

## Suggested Bundled Sessions

- **Bundle 1 — CHECK-widen NOT VALID split:** #MIG-1, #MIG-2
    - **Why grouped:** Identical root cause (CHECK constraint widened via `DROP`/`ADD` without `NOT VALID`), identical mechanical fix, identical CI guard (`guard-no-unsafe-migrations` Check 3), authored in the same overnight session — trivially fixed together by mirroring the `20260814100000_source_intents_allow_commerce_probe.sql` exemplar for each.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

None.
