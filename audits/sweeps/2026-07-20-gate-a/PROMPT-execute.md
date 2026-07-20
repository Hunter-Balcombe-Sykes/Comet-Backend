# Gate A — execute prompt

Copy-paste prompt for working `audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md` through the
`execute audit` flow (`scripts/audit/fix-flow.md`).

**How to use:**
1. Open a **fresh Claude Code session in this repo**. Recommended model: **Opus** (it orchestrates
   and does the planning; implementation and review are delegated to Sonnet subagents).
2. Paste everything from `=== PROMPT START ===` to the end as your first message.
3. Expect to be asked for sign-off early and often — six of the first seven units trip the blocker
   gate by design. This is not a walk-away run.

---

```
=== PROMPT START ===

execute audit audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md

Follow `scripts/audit/fix-flow.md` exactly. The notes below are gate-specific context that the
runbook cannot know — they override its defaults where they conflict, and everything else in the
runbook still applies unchanged.

## Before you start

Read `audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md` end to end first, including its
`## Cross-run root causes`, `## Requires a schema change` and `## Known limits of this gate`
sections. This file merges 23 separate audit runs; the cross-run table is why several bundles
exist in the shape they do.

Branch: `audit-fix/gate-a-2026-07-20` off `development` (after `git fetch && git pull`).

## The single most important rule for this file

**Every finding line carries only `Where` and `What to do`. That is an index, not the finding.**
The full Technical / Plain English / Evidence for each finding lives in the
`sources/<run>.md` file named at the end of its line.

Before planning ANY unit, open every `sources/*.md` file that unit's findings point at and read
those findings in full. A plan written from `CONSOLIDATED.md` alone is working from a third of
the evidence and will produce a fix that misses the actual defect.

## Unit order — this overrides fix-flow's default P0-first ordering

Work units strictly in this order. The first deviation from "all P0 first" is deliberate and
argued below; do not re-sort by tier.

1. **B2** — migration atomicity (P1) · BLOCKER
2. **S1 + S2 together** — DROP INDEX without CONCURRENTLY (P0) · BLOCKER
3. **S3** — auth-hook fail-open (P1) · BLOCKER
4. **B1** — edge-cache invalidation (P1)
5. **B3** — PII surviving redaction / leaking on export (P1)
6. **B4** — upload content validation (P1) · VERIFY PREMISE FIRST
7. **B5** — policy gate sweep, user API (P2)
8. **B6** — policy gate + PII gating, staff API (P2)
9. **B7** — PII minimisation in logs (P2)
10. **B8** — retention windows that never prune (P2)
11. **B9** — pre-account lifecycle races (P2)
12. **B10** — Nightwatch blind spots (P2)
13. **B11** — $fillable doctrine (P2) · BLOCKER (auth-adjacent + regression trap)
14. **B12** — pre-claim scraping data minimisation (P2)
15. **B13** — Cloudflare Worker hardening (P2) · BLOCKER (Worker/KV)
16. **B14** — public route and ingest hardening (P2)
17. **B15** — outbound HTTP hardening (P2)
18. **B20** — schema: RLS gaps and column defaults (P2) · BLOCKER (RLS + migration)
19. **B21** — test/prod parity (P2)
20. **S4** — eleven-model $fillable sweep (P2, effort L) · BLOCKER
21. **B16** — pin bare DB::transaction() to pgsql (P3)
22. **B17** — cache-layer helper hygiene (P3)
23. **B18** — config extraction sweep (P3)
24. **B19** — migration hygiene, non-blocking (P2/P3) · BLOCKER (migration)

**Why B2 runs before the two P0s.** The P0s are `ACCESS EXCLUSIVE` lock stalls: they need a
populated, serving table to cause harm, and the cutover target is a freshly re-baselined DB with
no traffic and near-empty tables. Their real-world severity for THIS operation is close to inert.
B2's non-atomic backfills do not care about table size — a backfill that commits before its
`DROP COLUMN` fails leaves a state that is neither the old schema nor the new one, where
re-running and rolling back are both unsafe. That is the only failure mode here that turns a
one-shot operation into an unrecoverable one. Fix the unrecoverable class first.

## Hard sequencing constraints inside and between units

- **B1: fix `cache-edge-reconcile/LIFE-1` (the reconcile/timeout gap) FIRST**, before the three
  dispatch-site fixes. Without a reconcile path the dispatch fixes are cleanup that silently
  regresses the next time a purge is dropped.
- **B17 must run after B1.** B1 changes the invalidation call sites B17's helpers would wrap.
- **B19 must run after S1/S2 and B2.** All three edit the same migration files.
- **B11 and S4 must NOT be combined.** Both touch `$fillable`; S4 is the L-effort eleven-model
  sweep and needs its own plan and sign-off.
- **S1 and S2 are ONE unit.** They share the CONCURRENTLY/CLI question below; planning them
  separately will produce two incompatible answers.

## Premise verification — do this before implementing, not after

Three findings may be wrong as written. Generated findings sometimes assume code or columns that
do not exist. Verify each against the current tree BEFORE writing a fix; if the premise fails,
mark the finding `no_change_needed` with your evidence and move on.

1. **B4 / `requests-resources/SEC-1`** — claims document upload has no content validation "at any
   layer". But the `outbound-ssrf` run scoped `app/Services/Media`, where magic-byte sniffing
   would live, and found nothing. Grep the media pipeline for `finfo` / magic-byte checks first.
   If validation already exists downstream, close SEC-1 and keep only SEC-2.
2. **B20 / `user-api/SCHEMA-1`** — claims `updateOrInsert` on `site.design_kits` can race on a
   missing row. The `trg_create_empty_design_kit` trigger auto-inserts an empty row on site
   creation, which should make that unreachable. Confirm the trigger exists and fires before
   authoring a backfill migration.
3. **S1 + S2 / CONCURRENTLY** — CLAUDE.md records a known "CONCURRENTLY/pipeline CLI
   incompatibility" that already breaks `supabase db reset` on a fresh DB. Adding `CONCURRENTLY`
   may trade a lock stall for a migration that will not run at all under `supabase db push`.
   Resolve that interaction as part of the plan; do not ship the keyword change blind.

## House rules that will bite on this specific run

- **Migrations are Supabase SQL only.** Never create a Laravel migration file — a composer guard
  rejects them. All schema work goes in `supabase/migrations/` as raw SQL.
- **Do not apply any migration to a live Supabase project as part of this run.** The fixes here
  edit unapplied migration files. Applying them is a separate, gated cutover decision that is
  Josh's to make.
- **Authorization goes through Policies**, never inline `abort_unless(...403)` — CI fails the
  build on inline 403s in controllers. B5 and B6 are entirely about this; use
  `authorizeForUser($user, ...)`, never `authorize(...)` (under Supabase JWT `Auth::user()` is
  always null, so `authorize()` silently passes).
- **Tests run on SQLite, prod is Postgres, and the schemas have drifted.** For any
  constraint-bound write, verify against the actual DDL in `supabase/migrations/`, not against
  the SQLite schema in `tests/Pest.php`. A green suite does not prove a write is legal on prod.
- **Pin subagent models explicitly** on every spawn (`model: sonnet` for implement and review).
  Child agents inherit the main-loop model otherwise, and an Opus fan-out will exhaust the
  session budget.

## Verification discipline

- **Never run `composer test` while a subagent is also running tests.** They collide. Wait for
  the subagent to return.
- **Never accept an implementer's claim that a failure is "pre-existing".** Stash the change and
  run the same test on the prior commit to prove it, or treat it as caused by this work.
- **Keep commits surgical.** Do not run `php artisan pint` across changed files as part of a fix
  commit — it churns the baseline and buries the real diff. A dedicated style commit is the only
  sanctioned exception.
- **Before every commit, run `git diff --cached --stat` and confirm the file list is exactly what
  you intend.** The index can carry work from a prior session; this repo has a second active
  developer.
- A box goes `[ ]` → `[x]` only after tests pass AND the independent review returns PASS.

## When the file is fully worked

1. `composer test` once for the whole branch — must be green.
2. Run `scripts/audit/archive-done.sh audits/sweeps/2026-07-20-gate-a`. It checks only
   `CONSOLIDATED.md` (the `sources/*.md` boxes are deliberately ignored), so a fully ticked
   consolidated file archives the whole gate as one unit. Do this automatically; never ask.
3. Report: units done, units blocked with reasons, test status, branch name.
4. **Do not push to `development` or `production`.** Josh reviews and merges.

## Stop and ask if

- A blocker unit's plan is ready — present it with blast radius and a recommendation, and wait.
- Two review rounds fail on the same unit — mark it blocked and surface it rather than forcing it.
- A finding's premise turns out to be wrong in a way that suggests the audit misread the
  architecture — say so rather than inventing a fix for a problem that does not exist.

=== PROMPT END ===
```

---

## What this prompt deliberately does not do

- It does **not** restate `fix-flow.md`. The runbook is the spec; this file carries only the
  gate-specific deltas.
- It is **not** compatible with `scripts/audit/PROMPT-consolidated-fix-runner.md`. That driver
  expects per-bundle completion checkboxes and four embedded session-prompt blockquotes per
  bundle, which `CONSOLIDATED.md` does not have. Use `execute audit` / `fix-flow.md`.
- It does **not** attempt a walk-away run. Six of the first seven units trip the blocker gate
  (two P0s, four DB/migration or auth units), so an autonomous driver would stop on nearly all
  of them anyway — with less control and no better throughput.
