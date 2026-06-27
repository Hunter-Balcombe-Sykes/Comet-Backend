# Strip-Plan Rebuild — Method & Prompt

Rewrites **`docs/superpowers/plans/2026-05-21-standalone-pages-backend-strip.md`**
(currently v6) into a v7 that is driven by the now-finalised, review-corrected
`STRIP-LEDGER.md`. Run once; the output is the plan that gets executed.

## Why this exists

The v1–v6 plans each carried inline "known-gap" file lists because no ledger
existed yet. The ledger now exists, is mechanically complete, and has passed an
adversarial review (`LEDGER-REVIEW-RESULT.md`, verdict *ready with listed
corrections* — all corrections folded in 2026-05-22). So the plan must stop
being a file list and become what it should always have been: **a task graph
with ordering, gates, and ledger references.** Every inline file enumeration in
v6 is now dead weight that can drift out of sync with the ledger — delete it.

## Inputs (read all three in full before writing)

- `audits/standalone-pages/STRIP-LEDGER.md` — **authoritative.** Section 0
  (18 decisions + propagation), Sections 1–5 (PRESERVE / EDIT / DELETE / KEEP /
  DB), Section 6 (the plan corrections this ledger forces). All review
  corrections are already folded in and marked `(review correction 2026-05-22)`.
- `docs/superpowers/plans/2026-05-21-standalone-pages-backend-strip.md` — the v6
  plan being replaced. Keep its task skeleton, ground rules, and verification
  gates where still correct; strip its inline file lists.
- The live repo at `/Users/joshuahunter/Herd/Side Street/backend`.

## Operating rules

1. **The ledger is the file list. The plan is not.** v7 must contain zero
   inline enumerations of files-to-delete or files-to-edit. Where v6 lists
   files, v7 says "per `STRIP-LEDGER.md` Section 3, group 3x" (or 2.x / 4 / 5).
2. **Do not re-derive classifications.** The ledger's DELETE/EDIT/KEEP/DB calls
   are settled. The plan's job is *sequencing and safety*, not re-classifying.
3. **Preserve v6 nuance that is still right** — the ground rules, the
   reference-order law, the three coupling channels, the per-task verification
   gate (`composer test` + `route:list` + `php artisan about` + request-path
   smoke). Don't lose them in the rewrite.
4. **Every Section 6 ledger correction must surface as a concrete plan step.**
   Section 6 is the checklist of what the plan got wrong; v7 fixes each.

## Required structural changes (v6 → v7)

- **Task 1 collapses.** "Build the ledger" is done. Replace the whole
  build-the-ledger task with a one-paragraph gate: "the ledger exists, was
  built mechanically, was adversarially reviewed, corrections folded in — it is
  the authoritative artifact; proceed." Keep it as a gate, not a work item.
- **Task 2 (sever) expands and absorbs the boot path.** Per ledger Section 6
  #1 and #8: `bootstrap/app.php`, all four route files, `routes/console.php`,
  and the `AppServiceProvider` / `EventServiceProvider` severs are explicit
  Task-2 steps that MUST complete before any Task-3 deletion — name them as
  ordered steps (each step = "apply ledger Section 2.1 / 2.7 sever spec for
  <file>"). A DELETE'd class still referenced at parse-time bricks `php artisan`.
- **`AccountType` is a stub, not a deletion** (ledger Section 6 #9 / the
  3-account-type note) — the plan must say "reduce `Enums/AccountType` to the
  `Individual` case" wherever it would otherwise say "delete it".
- **Task 3 (delete) stays grouped by domain** (3a Shopify … 3h worker) and
  points at the ledger's Section 3 groups. No inline lists.
- **Task 7 (DB re-baseline)** must name, per ledger Section 5 + Section 6 #5/#14:
  `20260420200000` as an RLS source; the `sidest_staff`/`comet_staff` →
  `partna_staff` rewrite *including the `data_export_audit` FK*; the
  `trg_recompute_partna_url` / `prevent_staff_escalation` / dual-write-trigger
  rewrites; the `all_site_data` view column drop; the `staff_audit_log` +
  `billing.webhook_events` GRANT ports; the `feature_flag_overrides.created_by`
  FK target; the `BYPASSRLS` decision; the FK drop order.
- **Cloudflare jobs** (Section 6 #6) — the 3 jobs need an explicit `default`
  target queue and a surviving Horizon supervisor; make it a step.
- **GDPR-blocking EDITs** (Section 6 #7) — `AccountDeletionService` /
  `DataExportPayloadBuilder` unguarded raw queries on dropped tables are
  correctness-critical; flag them, don't bury them.
- **Task 5 (rename `professionals`→`users` / `Professional`→`User`)** must be
  atomic — model + factory + `tests/Pest.php` DDL + every tenant helper in one
  pass, per the ledger's 2.9 sequencing note.

## What stays identical

Task 0 (safety net: tag, archive branch, working branch), the reference-order
law, the "migrations append-only until Task 7" rule, and the verification gate
after every task. Re-baselining the DB is still viable (pre-beta, no customers).

## Operating skill

Use `superpowers:writing-plans` to structure the rewrite. The output plan is
itself meant to be executed via `superpowers:subagent-driven-development` or
`superpowers:executing-plans`, so keep checkbox (`- [ ]`) step syntax and one
verification gate per task.

## Deliverable

A rewritten `docs/superpowers/plans/2026-05-21-standalone-pages-backend-strip.md`
(bump the header to v7, note "ledger-driven; inline file lists removed"). It must:

1. Contain no inline file enumerations — every file reference is a pointer into
   `STRIP-LEDGER.md`.
2. Order Task 2 so the boot-path severs precede all Task 3 deletions.
3. Have one concrete step per Section 6 ledger correction.
4. Keep the v6 ground rules and per-task verification gates.

## Stopping rule

One pass. The ledger is settled and reviewed — there is no plan-review
treadmill. After v7 is written, execute it behind the per-task gates.
