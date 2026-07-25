# RUN prompt — reconcile dev drift + collapse to a fresh baseline (paste-in)

Run-ready companion to `docs/deploy/PROMPT-execute-reconcile-and-collapse-baseline.md` (the canonical
bootstrap) and its authoritative plan `docs/superpowers/plans/2026-07-22-reconcile-and-collapse-baseline.md`.
This file adds a **verified starting state** and **observed drift** captured on 2026-07-25, so the run
begins with a head-start on Task 2 instead of from zero — but it still forces a fresh gate re-check,
because any work done between now and the run can move the schema.

**How to use:** finish your other work first. Then open a **fresh Claude Code session in this repo on
model Opus**, and paste everything from `=== PROMPT START ===` to the end as your first message.

---

## Reference state captured 2026-07-25 (NOT a substitute for re-verifying)

At 2026-07-25, on `origin/development` @ `91d11ccf`, the entry GATE stands as:
- Pre-pilot RLS slice merged (`6c32b985`) → `20260722020000_force_rls_core_segment_feature_tables` +
  `20260722030000_feedback_type_check` present on dev ledger under exact repo versions. (SCHEMA-7/12/13
  and DINT-3 were no-change — already satisfied.)
- B19 migration-safety guard extensions merged (`c8922a6b`); `php scripts/guard-no-unsafe-migrations.php`
  **re-run 2026-07-25: passes.**
- B8 (`20260722010000_audit_pii_snapshot_retention_prune`) + B20's 11 (`20260721010000, 020000, 030000,
  040000, 040100–040700`) applied to dev under **exact** repo versions.
- 07-11 P0/P1 tier closed (`54929ef2`). 07-11 P2 tier also closed since. **P3 (59 items) remains open and
  is deliberately out of scope for the cutover** — none of it is schema-bearing.
- **No schema-bearing work is in flight.** Every one of the 227 repo migrations that has landed is on the
  dev ledger (verified 2026-07-25); no authored-but-unrun prompt writes a migration.
- ⚠️ **`composer test` NOT re-verified at this capture.** Last known green was **5496 passed** at
  `1a5503e8`; four commits have landed since (`48bae335`, `63808390`, `0fb06997`, `91d11ccf`). Task 0 must
  re-run it. (Use `COMPOSER_PROCESS_TIMEOUT=0` — Composer's default 300s process-timeout kills the suite
  otherwise; that is a wrapper limit, not a test failure.)
- ⚠️ **One in-flight worktree exists** at `.worktrees/fetch-budget-reentrancy-2026-07-25`
  (branch `audit-fix/fetch-budget-reentrancy-2026-07-25`). Code-only, no migrations — but confirm it has
  landed or is parked before collapsing, so the baseline is cut from a settled tree.

### Schema snapshot — refreshed 2026-07-25
`scripts/launch-check/schema-snapshot.json` had gone **7 days / ~11 migrations stale** (generated
2026-07-18, still listing dropped `design_kit_contributions` + `workplaces.previous_website_analysis`,
missing `item_slugs`, `action_events` and the `users_sector*` CHECKs). It has been regenerated against dev
and **proven identical by md5** (`cols 93c9313f…`, `checks 762dcbea…`): **890 columns, 93 CHECK
constraints, latest_migration `20260724150957`**. `scripts/launch-check/schema-drift-baseline.json` was
regenerated on top of it: **266 → 288 entries** (+23 newly-visible, −1 resolved). The added entries are
constraints the gate was previously blind to, now grandfathered — burning them down is the T3 follow-up
(`docs/superpowers/plans/2026-07-25-schema-drift-t3-followup-PROMPT.md`), not this run.

**Drift observed on dev (Task-2 head-start) — the real numbers.** The previous capture listed 5 renumbered
pairs. A full repo-vs-ledger reconciliation on 2026-07-25 found the drift is far wider: **227 repo
migration files vs 220 dev ledger rows.**

- **Pre-July is perfectly aligned.** All 103 repo files dated before `20260701000000` match the 103
  pre-July ledger rows as an identical version set (md5 `2b1b86d7bdf6e0ed7ab80af20e44ebc2`). **All drift is
  July-onward** — scope Task 2/3 accordingly.
- **55 renumbered pairs** (name-identical, different version — Supabase renumbered on `db push`). Full list
  in the appendix below. This is the classic renumbered-dupe class the plan's Task 3 repairs (history-only
  `migration repair`, never a blind push).
- **12 repo files with no ledger row under the same name:**
  `20260705120001_validate_blocks_group_type_check`, `20260705120002_drop_dead_profile_columns_tables`,
  `20260711000100_user_segments`, `20260711000200_feature_availability`,
  `20260711000300_early_access_signups`, `20260711000400_notifications_critical_flag`,
  `20260711153000_feedback_type_area_target`, `20260715090000_menu_item_currency_and_dining_modes`,
  `20260717210000_menu_scan_items`, `20260718000000_manual_menu_content`,
  `20260720110001_add_auth_factor_events_webhook_id_uk`,
  `20260724130000_drop_workplaces_previous_website_analysis`.
- **4 ledger rows with no same-named repo file:** `20260710162608|ov_a_staff_accounts_20260711`,
  `20260710171743|feedback_type_area_target_20260711`, `20260712063906|backfill_staff_account_type`,
  `20260724150957|20260724130000_drop_workplaces_previous_website_analysis`.

> **These last two buckets are name-level classifications, not DDL verdicts.** Several clearly pair up
> across the two lists under a mangled name — `20260711153000_feedback_type_area_target` ↔
> `feedback_type_area_target_20260711`, and `20260724130000_drop_workplaces_previous_website_analysis` ↔
> ledger `20260724150957`, whose `name` column anomalously holds the **whole timestamped filename** rather
> than the bare name (so any name-matching repair logic will miss it — handle that row explicitly).
> Do **not** treat the 12 as "un-applied" and push them. Task 2/3 must decide each on **DDL evidence**
> (compare the file against live schema) before repairing, adopting, or applying.

Re-list `supabase/migrations/` vs the dev ledger at run time; treat the above as expected, and surface
anything NEW beyond this set.

---

```
=== PROMPT START ===

Execute the cutover-prep task "reconcile dev drift + collapse to a fresh baseline".

The authoritative task list is docs/superpowers/plans/2026-07-22-reconcile-and-collapse-baseline.md —
Read it IN FULL, then execute its Tasks 0-10 in order with the superpowers:executing-plans skill (or
superpowers:subagent-driven-development), ticking checkboxes as you complete steps. For rationale first
read docs/deploy/production-cutover.md ("Drift reconciliation (detailed steps)" + "Migration collapse
(rationale + method)"), and the gate/summary in docs/deploy/PROMPT-execute-reconcile-and-collapse-baseline.md.
The plan supersedes both docs where they differ — it carries the 2026-07-22 adversarial-review
corrections (app_backend BYPASSRLS stitch + rolbypassrls assertion, the `db diff --from local --to
linked` fallback, the grant-matrix parity check, the pg_trgm stitch, the guard-marker Checks-2/5
justification).

RE-VERIFY THE GATE FIRST — do not trust the 2026-07-25 reference state below.
A reference snapshot exists in docs/deploy/RUN-collapse-baseline-paste-prompt.md: on 2026-07-25 the gate
was green EXCEPT `composer test`, which was not re-run at capture time (you must run it). The observed
drift at capture was 55 renumbered pairs + 12 repo-only files + 4 ledger-only rows, all July-onward;
pre-July (103 files) was exactly aligned. The appendix lists every renumbered pair.
Since then, work may have moved the schema. Re-run Task 0 from scratch:
  - confirm the pre-pilot RLS + B8 + B20 migrations are still on the dev ledger under exact repo versions,
  - confirm nothing new schema-bearing is un-applied to dev (list supabase/migrations/ vs the dev ledger
    via the Supabase MCP against DEV ref glncumufgaqcmqhzwrxm),
  - run `php scripts/guard-no-unsafe-migrations.php` (must pass) and
    `COMPOSER_PROCESS_TIMEOUT=0 composer test` (must be green).
If the drift set is LARGER than the 2026-07-25 reference set, that new drift is from the intervening work
— classify it in the Task-2 report and STOP for sign-off before reconciling it. If any GATE box fails,
STOP and name the failing box; do not reconcile a moving schema.

Task map (detail lives in the plan — do NOT improvise from this summary):
- Task 0   Verify the GATE (as above). STOP and name the failing box if any fails.
- Task 1   Worktree ../backend-wt/collapse-baseline on branch chore/collapse-baseline-cutover
           (NOT under .claude/worktrees/ — it poisons the Composer classmap). Own composer install + .env.
- Task 2   Phase A state report: classify the ledger, make drift concrete (see the known renumbered set in
           RUN-collapse-baseline-paste-prompt.md). Expect plain `db diff --linked` to fail 25001
           pre-collapse; fall back to scripts/db/fresh-reset.sh + `supabase db diff --from local --to linked`.
- Task 3   Phase B ledger reconcile: repair renumbered dupes, adopt real remote-only schema, revert
           proven-phantom rows, apply local-only files surgically (VALIDATE data pre-flight; never bulk
           push). Converge to aligned list + empty diff. SIGN-OFF before Phase C.
- Task 4   Dump the verified dev schema (app schemas only, structure only).
- Task 5   Stitch cluster-level scaffolding a dump can't emit: guard disable-file marker with the
           Checks-2/5 justification, CREATE EXTENSION pg_trgm (schema-matched to dev), app_backend created
           NOLOGIN PLUS ALTER ROLE app_backend BYPASSRLS (load-bearing: FORCE-RLS tables without an
           app_backend policy default-deny without it). SIGN-OFF.
- Task 6   Install <ts>_baseline_pilot.sql; git mv EVERY other migration (incl. the 20260526 baseline)
           into supabase/migrations-archive/. SIGN-OFF before archiving.
- Task 7   Prove it: fresh-reset.sh from-zero apply clean; `db diff --from local --to linked` EMPTY; run
           the differ ACL-canary (revoke one grant locally, see if the diff notices, restore).
- Task 8   Posture assertions: rolcanlogin=f AND rolbypassrls=t; grant-matrix + default-ACL diff vs dev
           EMPTY; RLS flags + policy lists diff vs dev EMPTY; pinned search_path spot-checks; both views
           resolve.
- Task 9   Repo gates: guard passes, composer test green, baseline greps (one CREATE ROLE, zero CONCURRENTLY).
- Task 10  Reference updates (CLAUDE.md, AI_CONTEXT.md, production-cutover.md tick, CONVENTIONS §1,
           scripts/audit/adjudicate-prompt.md, the four app docblocks) + commit. UNPUSHED.

## Standing decisions & discipline (non-negotiable; mirrors the plan's Global Constraints)
- DEV-ONLY. Never touch prod in this run. Every link/repair/psql/MCP call targets the DEV project
  glncumufgaqcmqhzwrxm, or a THROWAWAY LOCAL DB. The prod project edplucmvkcnokyygxqsb is NOT contacted.
  If you catch yourself typing the prod ref, STOP.
- A blind `supabase db push` to dev is UNSAFE and FORBIDDEN in this run. Dozens of repo migrations are
  recorded on dev under DIFFERENT version numbers, so a push re-runs DDL that already exists and can
  VALIDATE-fail against live data. Ledger fixes = `supabase migration repair` (history-only); genuinely
  new files applied surgically one at a time; never bulk push.
- Present a written plan and WAIT for Josh's sign-off before: (a) any `migration repair`, (b) authoring
  the collapsed baseline, (c) archiving the incrementals. This is a blocker-gate task end to end.
- NEVER `git stash` / `git checkout <file>` / `git restore` / `git reset`. The stash stack is shared
  across worktrees and other live sessions. Read-only git only; `git show <ref>:<path>` for old content.
  Forbid `git stash` explicitly in any subagent prompt you spawn.
- Pin `model: sonnet` on every implement/verify subagent (Opus fan-out exhausts the budget). Keep the
  top-level reasoning on Opus.
- Commit discipline: verify `git rev-parse --abbrev-ref HEAD` + `git diff --cached --stat` before EVERY
  commit. No `php artisan pint` sweep. Trailers on every commit:
  Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
  Claude-Session: <your session url>
- Do NOT push to development/production and do NOT apply to prod. Josh reviews the branch and owns cutover.

## Stop and ask Josh if
- The Task-2 state report shows drift BEYOND the 2026-07-25 known set (a remote-only REAL object you can't
  confidently adopt, or a drift diff that won't go empty).
- A replay would VALIDATE-fail against live dev data (surface it; mark-applied instead).
- The Task-7 parity diff won't reach empty after two dump/stitch iterations — surface exactly what differs.
- Any pre-pilot-RLS / B8 / B20 migration turns out NOT to be on dev (the GATE is violated — schema isn't final).
- You're unsure whether an incremental should be archived vs kept (e.g. a migration authored AFTER the
  snapshot timestamp).

## When done — report
- Reconciliation: which rows were repair-ed applied/reverted, which files adopted; final migration list
  aligned + drift diff empty (paste the proof).
- Baseline: filename, line count, exactly one CREATE ROLE (app_backend) confirmed NOLOGIN AND BYPASSRLS,
  CREATE EXTENSION pg_trgm present, guard marker present, zero CONCURRENTLY.
- Parity: fresh-reset.sh applied clean; `--from local --to linked` diff EMPTY; ACL-canary outcome; Task-8
  grant-matrix + posture assertions all pass; guard + composer test green.
- References updated (CLAUDE.md, AI_CONTEXT.md, production-cutover.md, CONVENTIONS, adjudicate-prompt,
  app docblocks).
- Branch name + `git log --oneline` of your commits (UNPUSHED). Explicitly: prod was never contacted.
- What remains for cutover day (Phases 1–4 of production-cutover.md): wipe+psql-apply the baseline to the
  prod project, ALTER ROLE app_backend LOGIN (and verify BYPASSRLS), secrets, deploy, verify.

=== PROMPT END ===
```

---

## Appendix — the 55 renumbered pairs observed 2026-07-25

Repo filename → dev ledger version it is actually recorded under. Name-identical, so these are the
low-risk `migration repair` class — but the plan's Task 3 still confirms DDL equivalence before
repairing. Captured from `supabase_migrations.schema_migrations` on ref `glncumufgaqcmqhzwrxm`.

| Repo file | Dev ledger version |
|---|---|
| `20260705150000_workplaces_identity_columns` | `20260705125859` |
| `20260705150100_users_sector_columns` | `20260705125905` |
| `20260705150200_create_content_selection` | `20260705125910` |
| `20260705150300_sites_content_instagram_auto` | `20260705125916` |
| `20260706000000_add_city_to_site_visits` | `20260706131853` |
| `20260707020000_site_visits_lat_lon` | `20260707044955` |
| `20260707030000_rename_skeleton_ids` | `20260707010747` |
| `20260707030001_shop_brand_modes` | `20260707050030` |
| `20260707040000_design_kits_motion_entrance` | `20260707011746` |
| `20260707050000_design_kits_weight_light_scrim_blur` | `20260707012415` |
| `20260707070000_platform_connections_display_settings` | `20260707022536` |
| `20260707080000_skeletons_sheet_thread` | `20260707062209` |
| `20260707120000_rename_skeleton_ids_bento_class` | `20260707123031` |
| `20260707130000_design_kit_identity_axes` | `20260707135755` |
| `20260708000000_add_site_media_palette` | `20260708000529` |
| `20260708120000_sites_shop_global_settings` | `20260707164139` |
| `20260708140000_add_atlas_multipage_skeleton` | `20260707225202` |
| `20260708160000_remove_thread_sheet_skeletons` | `20260707232847` |
| `20260710120000_add_section_views_duration_ms` | `20260710013437` |
| `20260710140000_rls_policies_new_tables` | `20260710025335` |
| `20260710160000_design_kit_theme_surface_rework` | `20260710040150` |
| `20260710170000_skeleton_id_one_only` | `20260710052924` |
| `20260710190000_semantic_text_scale_and_vocab_remap` | `20260710062903` |
| `20260710210000_surfaces_backend` | `20260710094916` |
| `20260710230000_rename_skeleton_id_to_architecture_id` | `20260710123338` |
| `20260711160000_analytics_force_rls_parity` | `20260710204114` |
| `20260711160100_add_analytics_purge_indexes` | `20260710204128` |
| `20260711160200_site_sessions_add_composite_unique` | `20260710204140` |
| `20260711160300_site_sessions_promote_composite_pk` | `20260710204534` |
| `20260713120000_reconcile_instagram_gallery_unification` | `20260713074407` |
| `20260714200000_architecture_one_to_staple` | `20260714041936` |
| `20260714210000_drop_effect_surface` | `20260714041953` |
| `20260714220000_add_aesthetic_axes` | `20260714044454` |
| `20260714230000_drop_glass_satellites` | `20260714051427` |
| `20260717163600_add_typography_weight_axis` | `20260717062901` |
| `20260717170000_menu_items_images` | `20260717090245` |
| `20260718010000_handle_change_log_retention_prune` | `20260718020855` |
| `20260718200000_pre_account_sites` | `20260718131130` |
| `20260719000000_drop_waitlist_signups` | `20260719062803` |
| `20260720100000_sites_shop_link_mode_check` | `20260720052546` |
| `20260720100100_design_kits_aesthetic_axis_checks` | `20260720052607` |
| `20260720100200_shop_brands_mode_checks` | `20260720052612` |
| `20260720100300_content_popularity_scores_content_type_check` | `20260720052622` |
| `20260720100400_item_views_item_type_check` | `20260720052626` |
| `20260720100500_analytics_site_fks` | `20260720052637` |
| `20260720100600_validate_analytics_site_fks` | `20260720052646` |
| `20260720110000_add_webhook_id_to_auth_factor_events` | `20260720054323` |
| `20260721090000_menu_item_multi_category` | `20260721080945` |
| `20260721130000_claim_invite_outreach` | `20260722014429` |
| `20260721150000_services_fresha_projection` | `20260721081007` |
| `20260721150001_services_fresha_projection_indexes` | `20260721081023` |
| `20260721180000_service_multi_category` | `20260721081111` |
| `20260722100000_drop_design_kit_contributions` | `20260722101956` |
| `20260723090000_create_action_events` | `20260724013039` |
| `20260724120000_create_item_slugs` | `20260724072916` |

**Reproduce this classification** (it will drift as work lands):

```sql
-- against ref glncumufgaqcmqhzwrxm
select string_agg(version||'|'||name, chr(10) order by version)
from supabase_migrations.schema_migrations where version >= '20260701000000';
```
then diff against `ls -1 supabase/migrations/*.sql`, matching on the name after the timestamp prefix.
