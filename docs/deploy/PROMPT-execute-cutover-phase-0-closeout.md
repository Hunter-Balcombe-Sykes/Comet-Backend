# Cutover Phase 0 closeout — pre-cutover-day readiness (paste-in execute prompt)

Closes the remaining **Phase 0** pre-cutover items in `docs/deploy/production-cutover.md` so cutover day
is only Phases 1→5 (each of which has its own prompt). This is the unhurried, mostly-read-only prep that
makes cutover day a sequence of confirmations, not discovery. It does **not** execute any of Phases 1–5.

**How to use:** open a fresh Claude Code session in this repo on **model Opus**, then paste everything
from `=== PROMPT START ===` to the end as your first message.

---

## GATE — do not start until every box is true

- [ ] The collapse baseline (`supabase/migrations/20260726000000_baseline_pilot.sql`) is **merged to
      `development`** (done 2026-07-26 @ `034a4aed`) — the schema is frozen, so the env-var/config prep
      below is being built against a settled state.

STOP only if the schema isn't final; everything else here is what this prompt establishes.

---

```
=== PROMPT START ===

Execute the remaining Phase-0 pre-cutover-day prep for the production cutover. Read
docs/deploy/production-cutover.md Phase 0 IN FULL first, plus docs/deploy/prod-cutover-change-checklist.md
§C/§D/§E. The goal of this run is a go/no-go READINESS REPORT that closes every still-open Phase-0
checkbox — NOT to execute the cutover. Phases 1–5 have their own prompts
(PROMPT-execute-cutover-phase-1-prod-db.md … phase-5-post-cutover.md, launch-check-3, and the seed
prompt) — do not do their work here.

## Cutover context (read first)
- Prod Supabase ref `edplucmvkcnokyygxqsb`; dev ref `glncumufgaqcmqhzwrxm` — the OPPOSITE. Say which ref
  every command targets.
- This is unhurried before-the-day prep: mostly READ-ONLY audits + verification, plus two provisioning
  actions Josh takes in dashboards (Supabase Pro, DNS). It mutates NOTHING in the prod database or the
  prod Laravel env — it produces the artifacts and confirmations Phases 1–3 will consume.
- Josh drives every dashboard/provisioning action; you prepare, verify read-only, and confirm.

## Tasks

### Task 1 — The hard gate: all P0/P1 audit findings resolved
This is the cutover's top-level gate (`production-cutover.md` Phase 0, first checkbox + the header Gate).
Verify it, don't assume it. Judge P0/P1 done from the TRIAGE-*.md files (the source of truth — per-lens
`[ ]` marks go stale), not CONSOLIDATED. Confirm nothing schema-bearing since the last close is un-applied
to dev (the schema is already snapshotted, so a late schema fix would mean re-baselining). Report any open
P0/P1 finding by ID; a single open P0/P1 is a cutover blocker. If clean, tick the checkbox with a dated note.

### Task 2 — Env-var parity audit → build the complete prod secret set
This is the open Phase-0 "Env-var parity audit" checkbox and the artifact Phase 2 consumes. READ-ONLY:
- `cloud environment:get development --json` and `cloud environment:get production --json` — capture both
  key sets (prod may be near-empty/stale from the 2026-05-21 deploy; expected).
- Diff against `.env.example` and against `prod-cutover-change-checklist.md` §C.
- Run `scripts/env/compare-env.sh` as a cross-check (heuristic keyword scan — not a replacement for the
  explicit diff).
Produce the **key-by-key prod secret checklist** — every §C key tagged SAME / SPLIT / NEW, with the value
or the exact derivation procedure (secrets stay redacted; note "Josh supplies at Phase 2"). Do NOT set
anything — this is the reviewed artifact Phase 2 pastes from. Attach it to the report.

### Task 3 — Supabase Pro on the prod project (before go-live)
`production-cutover.md` Phase 2 requires Pro on `edplucmvkcnokyygxqsb` BEFORE go-live so managed daily
backups + paid-tier limits cover the riskiest first days. This is a Josh dashboard action. Confirm current
tier (read-only via MCP/dashboard); if still Free, flag it as a required pre-go-live provisioning step and
get Josh's go to upgrade. Report status.

### Task 4 — Email deliverability DNS is prod-ready
Phase 4 depends on this (`production-cutover.md` Phase 4 "Deliverability DNS"). Confirm, or run via
`docs/superpowers/plans/2026-07-21-email-deliverability-hardening-PROMPT.md`: `partna.au` has an MX + a
reachable `hello@partna.au` inbox; DMARC `rua` points at a report parser someone actually reads; the
Resend bounce/complaint webhook is registered against the PROD URL with the prod secret. These are DNS /
dashboard actions Josh takes — verify state read-only and report what's outstanding.

### Task 5 — Note the staff-signup seed prerequisite (scheduling dependency, not doable here)
The Phase-1 seed needs Josh + Tobias to have signed up on **prod** and enrolled TOTP (their prod
`auth.users` UUIDs are the seed's only parameters). That requires prod Auth to be live (Phase-1 hooks +
TOTP-enabled), so it can't happen in this Phase-0 run — it slots between Phase-1 auth-config and the seed.
Do NOT attempt it here; just flag it prominently in the report as a "days-earlier-if-possible" dependency
so it isn't discovered on cutover day.

## PROD-SAFETY RULES (non-negotiable)
- Prod ref `edplucmvkcnokyygxqsb` vs dev `glncumufgaqcmqhzwrxm` — verify before every command.
- READ-ONLY everywhere except the two provisioning actions (Supabase Pro, DNS/webhook), which are Josh's
  dashboard actions on confirmation. Set zero prod env vars, run zero prod DB writes, run zero migrations.
- Never push git without explicit confirmation; read-only git otherwise; NEVER `git stash` /
  `git checkout <file>` / `git restore` / `git reset`. Pin `model: sonnet` on any subagent.

## Stop and ask Josh if
- Any P0/P1 finding is still open (cutover blocker).
- The env-parity diff surfaces a dev key with no resolvable prod value.
- Supabase Pro or the deliverability DNS can't be confirmed and Josh hasn't actioned it.

## When done — report
- Task 1: P0/P1 gate — clean or the open IDs (go/no-go).
- Task 2: the full prod secret-set checklist (SAME/SPLIT/NEW), ready for Phase 2 — flag any unresolved key.
- Task 3: Supabase Pro status on prod.
- Task 4: deliverability DNS status (MX / inbox / DMARC rua / Resend webhook) — each done or outstanding.
- Task 5: staff-signup dependency flagged.
- A single **READY / NOT-READY for cutover day** verdict, with the outstanding items listed if not ready.

=== PROMPT END ===
```
