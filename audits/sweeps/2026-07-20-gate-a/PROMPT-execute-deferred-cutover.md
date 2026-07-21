# Gate A — execute prompt: DEFERRED, cutover-gated schema (B20 + B8)

Continues `audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md`. These items are **deliberately deferred to
the prod-cutover window** (Josh) — they carry Supabase migrations that `db push` would apply to the LIVE
dev DB, and the cutover collapses migration history into a fresh baseline, so they must be part of the
*final dev schema before the collapse snapshot*. Tracked in `docs/deploy/production-cutover.md` Phase 0.

Two parts. **Part 1 can be done any time** (author files, leave UNPUSHED). **Part 2 is cutover-prep
only** — do NOT run it until the cutover runbook's Phase 0 (it applies schema to live dev right before the
snapshot).

**How to use:** open a fresh Claude Code session on **Opus**, then paste from `=== PROMPT START ===`.

---

```
=== PROMPT START ===

Continue audit audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md — the DEFERRED cutover-gated schema
(B20 migrations + B8 models-data/PRIV-2 & PRIV-3). Read docs/deploy/production-cutover.md first; this
prompt is the detail behind its Phase-0 "Land the deferred Gate-A P2 schema items" checkbox.

## Orient
- `git fetch && git checkout audit-fix/gate-a-2026-07-20 && git rev-parse --abbrev-ref HEAD` — CONFIRM the
  branch name. Read CONSOLIDATED's B20 entries (`pii-schema/SCHEMA-1..3`, `user-api/SCHEMA-1`,
  `staff-api/SCHEMA-1`) and B8's `models-data/PRIV-2` + `PRIV-3` (both marked ⏸).
- **Discipline:** verify branch NAME before any commit; NEVER `git stash`/`checkout <file>`/`restore`/
  `reset` (use `git show`); do NOT push to development/production without explicit sign-off. A `supabase
  db push` against the LIVE dev DB (glncumufgaqcmqhzwrxm) is real — Part 2 only, gated, dry-run + confirm.

## PART 1 — author the B8 prune migrations (do now; leave UNPUSHED)
B20's 11 migrations already exist (`supabase/migrations/20260721010000…040700`), authored + reviewed —
nothing to write there. B8 is NOT yet authored:
- **PRIV-2** — `audit.user_deletion_audit.professional_email_snapshot` has no retention bound. The table
  is append-only for `app_backend` (SELECT/INSERT only; `20260527010000` revoked UPDATE/DELETE
  schema-wide), so pruning needs a **NEW SECURITY DEFINER prune function** — mirror
  `audit.prune_handle_change_log()` in `supabase/migrations/20260718010000_*.sql` EXACTLY (same
  SECURITY DEFINER + pinned `search_path` + owner + the scheduler wiring pattern). Choose the retention
  window with Josh (the audit notes a ~7yr default; zero rows today, pre-pilot).
- **PRIV-3** — `audit.data_export_audit.professional_email_snapshot` / `recipient_email`. This table DOES
  have a `GRANT UPDATE` (`20260624…`), so it *could* anonymise via a migration-free scheduled UPDATE — but
  the audit deliberately keeps it on the SAME prune mechanism as PRIV-2 (one consistent SECURITY DEFINER
  path for the sibling audit tables). Author it the same way unless Josh prefers the split.
- Wire both into the scheduler alongside the existing handle-change prune. Author the migration file(s) +
  any `app/Console` schedule entry; add tests where meaningful. **Leave UNPUSHED** (like B20). Commit on
  audit-fix: `fix(audit): B8 PRIV-2/PRIV-3 audit-table retention prune (authored, unpushed — cutover)`.
  Tick the two ⏸ boxes to `[x]` with a note that they are authored + gated. `composer test` green
  (SQLite won't exercise the DDL, but the schedule wiring + any PHP must not regress).

## PART 2 — apply at cutover-prep ONLY (do NOT run outside the cutover window)
This runs as part of `docs/deploy/production-cutover.md` Phase 0, AFTER the drift reconciliation there:
1. Reconcile dev migration drift FIRST (cutover doc "Drift reconciliation") — note the known snag: the
   sibling menu/services migrations are applied on dev under versions `20260721080945/081007/081023/081111`
   but the repo files are `090000/150000/150001/180000`; a naive `db push` will try to re-apply them.
   Use `supabase migration repair --status applied <version>` / adopt-file to align BEFORE pushing, so the
   push only applies genuinely-un-applied files (B20's 11 + B8's).
2. `supabase link --project-ref glncumufgaqcmqhzwrxm && supabase db push --dry-run` — REVIEW every
   statement. Confirm with Josh. Then `supabase db push`.
3. Verify on dev: RLS is ENABLED+FORCED on `site.workplaces` + `site.content_selection` with the
   owner/staff/service_role policies; `menu_platform_links`/`menu_item_platforms.id` have a
   `gen_random_uuid()` default; `design_kits` has one row per site; the `pg_trgm` GIN indexes exist; the
   B8 prune functions exist with the right grants + are scheduled.
4. Only THEN proceed to the migration-collapse snapshot (these are now part of the final dev schema).

## Stop and ask Josh
- Before ANY `supabase db push` to dev (Part 2) — dry-run + confirm, always.
- On the B8 retention window / split-vs-shared prune decision (Part 1).
- If the drift reconciliation surfaces more than the known menu/services version mismatch.

=== PROMPT END ===
```
