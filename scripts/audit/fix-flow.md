# Audit Fix Flow — the `execute audit` runbook

This is the **single** canonical spec for working an audit file's findings. It replaces the old
orchestrator + paste-in runner prompts (deleted 2026-06-23). When Josh says **`execute audit <file>`**
(or "work the audit <file>"), follow this exactly. Do not skip steps. Do not invent a different flow.

The `<file>` is normally a `CONSOLIDATED.md` produced by `scripts/audit/audit.sh`.

---

## 0. Read the file and set up

1. Read the audit file end to end. **The file's `## Execution policy` header is the source of truth for
   which Claude model plans, implements, and reviews — read those three values from THIS file and use
   exactly them.** Do not hardcode models from this runbook; the values below are only the defaults the
   file ships with, and Josh may have edited a specific audit's policy (e.g. bumped implement to Opus).
   Also read the **`## Suggested Bundled Sessions`** and **`## Standalone — do NOT bundle`** sections —
   they define the work units and the order.
2. Confirm you're on a clean, up-to-date base: `git fetch && git pull`, then branch
   `audit-fix/<slug>-<date>` off `development` (slug = the audit's name). All commits land here; never
   commit straight to `development` or `production`.
3. Build the work list: each **bundle** is one unit; each **standalone** item is its own unit. Order:
   all P0 first, then P1, P2, P3. Standalone items interleave at their tier.

## 1. Per unit (bundle or standalone item) — plan → implement → review

Run units **sequentially** (a later fix may depend on an earlier one; parallel edits to the same files
collide). For each unit:

### a) Blocker gate — pause for sign-off if ANY of these is true
- the unit contains a **P0**, or
- it touches **auth / authorization**, **money**, or a **DB migration / schema change**, or
- it's an **L or XL** effort item, or
- it appears under **`## Standalone — do NOT bundle`**.

→ Do NOT implement yet. Produce the plan (step b), present it to Josh with the blast radius and your
recommendation, and **wait for explicit go-ahead**. Everything else proceeds without asking.

> Models for all three steps come from the file's `## Execution policy` (defaults: Plan = Opus,
> Implement = Sonnet, Review = Sonnet). Follow whatever that header says, plus its per-item override
> guidance (e.g. "escalate implement → Opus for gnarly logic"). The combine-plan+impl and blocker
> rules below are flow structure and always apply regardless of the models chosen.

### b) Plan — use the file's **Plan** model
Spawn a planning instance (a subagent) scoped to the unit's findings. It reads the cited code and
returns a concrete fix plan: files to change, the exact change per finding, tests to add/run, and risks.
- **Combine plan+impl** for **S/XS** units (or whatever the file's policy says): skip the separate
  planning instance and let the implementer plan-and-do in one pass.
- Keep plan and implement **separate** for P0/P1 and L/XL units.

### c) Implement — use the file's **Implement** model
Spawn an implementation instance that applies the plan. It writes code + tests, follows CLAUDE.md house
rules (Policies not inline 403s, Resource classes, Supabase migrations not Laravel, `$backoff` on jobs,
etc.), and runs the relevant tests (`composer test` or a targeted subset) until green. Apply the file's
per-item escalation guidance (e.g. bump to Opus for a gnarly unit).

### d) Review — use the file's **Review** model, as a SEPARATE independent instance (never the implementer)
Spawn a fresh review instance that did NOT write the code. Give it the finding + the diff. It must
verify: the change actually fixes the finding, no regression or new bug, tests genuinely pass, house
rules honoured. It returns PASS or specific defects.
- PASS → go to step e.
- FAIL → hand the defects back to a new implementation instance (step c) and re-review. After 2 failed
  review rounds, mark the unit **blocked** and surface it to Josh rather than forcing it.

### e) Record + commit
- Tick the checkbox(es) for every finding in the unit: `- [ ]` → `- [x]` in the audit file, and bump
  its `## Progress` counts.
- Commit the code + the ticked audit file together: `fix(audit): <unit> — <ids>`.

## 2. When the file is fully worked

1. Run the suite once more for the whole branch: `composer test`. Must be green.
2. **Auto-archive:** run `scripts/audit/archive-done.sh <path-to-the-audit-folder>`. If every box is
   ticked it moves the whole run folder into `audits/archive/` (history preserved). This is automatic —
   never ask Josh "should I archive this?"; just run it. If boxes remain (blocked units), it stays put
   and reports why.
3. Report: units done, units blocked (with reason), test status, the branch name. Josh reviews and
   merges — you don't push to `development`/`production` without his say-so.

## Non-negotiables
- **The audit file's `## Execution policy` decides the models** (plan / implement / review) — read them
  from the file, obey its per-item overrides; never substitute your own. This runbook's defaults are only
  what the file ships with.
- Never skip plan → implement → **independent** review for a unit. The reviewer is always a separate instance.
- Blockers (P0 / auth / money / DB / L-XL / standalone) ALWAYS pause for sign-off before implementation.
- Never auto-merge or push to a shared branch; work stays on `audit-fix/<slug>-<date>`.
- Completed audit folders auto-archive via `archive-done.sh` — no manual "is it done?" check.
- Verify before ticking: a box goes to `[x]` only after tests pass AND the independent review says PASS.
