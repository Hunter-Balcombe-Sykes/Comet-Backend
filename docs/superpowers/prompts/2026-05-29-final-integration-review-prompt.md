# Trust & Safety Foundation — Final Cross-Plan Integration Review

**Hand this prompt to a fresh Claude Code session ONLY after Plans A, B, and C are all merged into `development`.**

This is not a per-plan review — those happened during each plan's execution. This is the holistic review of the entire Trust & Safety foundation as one integrated system, checking the seams *between* plans and validating the original design goal: **a foundation that scales to 100k sites without rearchitecting.**

---

## Your task

Review the merged Trust & Safety foundation (Plans A + B + C combined) across six dimensions using parallel review subagents, then synthesize the findings into a single go/no-go report for production launch. Produce a written review document at `docs/moderation/integration-review-2026-05-29.md`.

You are **reviewing, not implementing.** Do not write feature code. If you find issues, document them with severity + location + recommended fix; do not fix them inline (that's a follow-up task for an implementer session).

## Inputs

| File | Purpose |
|------|---------|
| `docs/superpowers/specs/2026-05-26-trust-and-safety-foundation-design.md` | The contract. The implemented system is measured against this |
| `docs/superpowers/plans/2026-05-26-trust-and-safety-foundation-plan-a.md` | Plan A (schema + reporting) |
| `docs/superpowers/plans/2026-05-26-trust-and-safety-foundation-plan-b.md` | Plan B (staff workflow + outcomes) |
| `docs/superpowers/plans/2026-05-26-trust-and-safety-foundation-plan-c.md` | Plan C (CSAM pipeline) |
| The merged codebase on `development` | The actual implementation |

## Pre-flight (before dispatching review agents)

1. **Pull latest:** `git fetch origin && git checkout development && git pull origin development`
2. **Confirm all three plans are merged** — these must all be present:
   ```bash
   # Plan A artifacts
   php -r 'require "vendor/autoload.php"; echo class_exists(\App\Models\Moderation\ModerationCase::class) ? "A:OK " : "A:MISSING ";'
   # Plan B artifacts
   php -r 'require "vendor/autoload.php"; echo class_exists(\App\Services\Moderation\ModerationDecisionService::class) ? "B:OK " : "B:MISSING ";'
   # Plan C artifacts
   php -r 'require "vendor/autoload.php"; echo class_exists(\App\Services\Moderation\CsamMatchHandlerService::class) ? "C:OK\n" : "C:MISSING\n";'
   ```
   If any are MISSING, STOP — the relevant plan isn't merged yet. This review is premature.
3. **Fresh-DB migration test:** `supabase db reset` — every migration (A's `20260528*`, C's `20260529*`) must apply cleanly in sequence on an empty database. A failure here is a P0 finding on its own.
4. **Baseline test run:** `composer test && php artisan test --group=postgres` — capture the result; a red baseline is itself a finding.

## Review dimensions (dispatch as PARALLEL subagents)

Use `superpowers:dispatching-parallel-agents`. These six reviews examine different facets of the same merged codebase with no shared state — run them concurrently, each writing findings to a scratch file, then synthesize. Give each agent the spec path, the relevant plan path(s), and a tightly-scoped mandate.

### Agent 1 — Integration seams (the cross-plan call chains)

**Mandate:** Trace every call chain that crosses plan boundaries and confirm the seams are intact.

- The unified pipeline `signal → case → decision → action` must work identically whether the signal is a human report (Plan A) or a CSAM match (Plan C). Both must terminate in the SAME `ModerationActionDispatcher` (Plan B).
- `CsamMatchHandlerService` (C) → `ModerationDecisionService::decideAsSystem()` (C extends B) → `ModerationActionDispatcher::dispatchFor()` (B) → outcome jobs (B) **plus** `FileCyberTipReportJob` (C). Confirm the CSAM auto-action dispatches the full job set: `QuarantineMediaJob`, `SuspendUserJob`, `SuspendSiteJob`, `PurgeModerationCacheJob`, `NotifyOnCallStaffJob`, `FileCyberTipReportJob`.
- Human report (A) → staff `decide` (B) → `ModerationDecisionService::decide()` (B) → dispatcher → outcomes. Confirm no second decision-write path exists.
- `NotifyReportedUserJob` / `NotifyReporterJob` (B) read the extended `AccountCapabilitySet` (B Task 14). Confirm the properties `can_be_reported` + `receive_moderation_notifications` exist and are consulted.
- Confirm `decideAsSystem` (C) and `decide` (B) both transition the case via the SAME `CaseStateMachine` (A) — no bypass.

**Output:** list every cross-plan seam, mark each ✅ intact or ❌ broken with file:line evidence.

### Agent 2 — Schema + migration cohesion

**Mandate:** The whole schema must be internally consistent after all migrations apply.

- All `moderation.*` tables present: `cases`, `case_signals`, `evidence`, `decisions`, `action_log`, `csam_quarantine`, `ncmec_submissions`. Plus `audit.moderation_events`.
- Every FK resolves to an existing table/column. Specifically confirm `reportable_owner_user_id → core.users(id)`, `triaged_by_staff_id → core.partna_staff(id)`, `csam_quarantine.site_media_id → site.site_media(id)`.
- `site.sites.moderation_state` column exists with its CHECK; `core.users` does NOT have a `moderation_state` column (it uses `status`).
- Every partial index from the plans is present (`cases_open_queue_idx`, `cases_target_open_idx`, `case_signals_dedup_uniq`, etc.). Run the `pg_indexes` query.
- The DB-level CHECK constraints are intact: `decisions_actor_xor`, `decisions_csam_override_requires_second_staff`.
- Migration timestamps are monotonic and don't collide with existing migrations.

**Output:** schema-cohesion report; any missing table/FK/index/constraint is a P0 or P1 finding.

### Agent 3 — Security posture (holistic)

**Mandate:** Audit the entire feature's security surface as one.

- **AAL2:** every `/v1/staff/cases*` route is gated by `require.aal2`. Cross-check against `tests/Feature/Security/Aal2RouteCoverageTest.php` (the existing sweep) — does it cover the new routes?
- **Capability gates:** every notification dispatcher to a reported user checks `receive_moderation_notifications` (fail-closed).
- **PII:** no reporter email or raw IP appears in any Resource, log line, or `audit.moderation_events` payload. Grep the codebase for leaks. Confirm `ModerationAuditService` scrubs PII keys.
- **Webhook:** `/v1/internal/cloudflare-csam-webhook` rejects missing-signature / bad-HMAC / replay / stale-timestamp. Confirm the nonce store TTL.
- **Anti-abuse:** public report endpoint has Turnstile (`bot.token`) + IP throttle + per-target throttle + DB dedup. All four layers present.
- **CSAM override:** the two-staff requirement is enforced at BOTH the DB (CHECK) and application (FormRequest) layers.
- **403 vs 404:** public endpoints use 404 for missing/inaccessible resources (no enumeration). Staff endpoints use the policy pattern.
- **Authorization:** `CasePolicy` + `DecisionPolicy` registered; `PolicyCoverageTest` passes including the new models.

**Output:** security findings by severity. Any auth bypass or PII leak is P0.

### Agent 4 — Spec coverage (the contract)

**Mandate:** Every section of the spec is either implemented or explicitly + correctly deferred.

- Walk the spec section by section (§4 locked decisions, §6 data model, §7 API, §8 services, §9 edge cases, §11 testing, §13 launch prereqs).
- For each spec requirement, point to the implementing code or the deferred-item note (§14).
- Confirm the deferred items (appeals, trusted flaggers, DSA SOR enum, EU Transparency DB submitter, ML detection, SiteMedia/Block reportable types) are genuinely deferred — NOT half-built. A half-built deferred feature is a finding.
- Confirm the locked decisions from §4 actually hold in the code (anonymous reports allowed, Site-only reportable target day-one, auto-notify on hide/suspend, separate quarantine bucket, no backfill scan, 90-day retention, auto-suspend on CSAM, CSAM-only scanning, Josh as NCMEC contact, unified cases abstraction, immutable decisions).

**Output:** spec-coverage matrix (requirement → implementing code | deferred). Gaps are findings.

### Agent 5 — Scale + performance (the original goal)

**Mandate:** Validate the design goal that started this whole effort — "won't need rearchitecting at 100k sites."

- The hot-path queue query (`WHERE status IN ('open','triaged','under_review') ORDER BY severity DESC, priority ASC, created_at ASC`) uses `cases_open_queue_idx` (partial index on open states only). Run EXPLAIN; confirm index scan, not seqscan.
- The case-merge lookup (`WHERE reportable_type=? AND reportable_id=? AND status IN (open...)`) uses `cases_target_open_idx`. Run EXPLAIN.
- The staff queue endpoint has no N+1 (eager-loads signals/evidence/decisions for the detail view; the list view doesn't load them).
- `dedup_hash` UNIQUE constraint is the dedup mechanism (DB-level, not application-level).
- The `PromoteCleanMediaJob` is bounded (max 100 rows/run) so a backlog doesn't stall it.
- The partial indexes mean historical (resolved) cases don't bloat the hot path — confirm the `WHERE status IN (...)` predicate on the relevant indexes.

**Output:** for each hot path, the query plan + verdict. Any seqscan on a path that grows with case volume is a P1 finding.

### Agent 6 — Observability + ops readiness

**Mandate:** Confirm the system is operable in production.

- Feature flags wired: `PARTNA_MODERATION_ENABLED`, `PARTNA_CSAM_SCAN_ENABLED`, `PARTNA_MODERATION_AUTO_ACTIONS_ENABLED`. Confirm `PARTNA_CSAM_SCAN_ENABLED` defaults to `false`.
- Nightwatch breadcrumbs: `moderation.case.opened`, `moderation.decision`, `moderation.auto_action`, `moderation.sla.breach_risk`, NCMEC manual-fallback alert, quarantine-bucket-drift alert.
- Scheduled commands registered in `app/Console/Kernel.php`: `sla-scan` (15min), `expire-csam-quarantine` (daily), `audit-quarantine-bucket` (daily), `retry-ncmec-submissions` (5min).
- Horizon `moderation_high` lane exists; time-sensitive jobs use it.
- Operator runbooks complete + accurate: `docs/moderation/README.md`, `docs/moderation/staff-workflow.md`, `docs/moderation/csam-pipeline.md`. Spot-check that the documented commands actually exist and the documented flows match the code.
- Production launch prerequisites checklist is present and accurate (NCMEC ESP registration, R2 quarantine bucket, Cloudflare CSAM tool, webhook secret, on-call channels).

**Output:** ops-readiness checklist; gaps are P1/P2 findings.

## Whole-system integration smoke (you run this directly, not via subagent)

After the parallel agents report, run two end-to-end journeys against a local stack (`composer dev` + `PARTNA_CSAM_SCAN_ENABLED=true`):

### Journey 1 — Human report → resolution (spans A + B)
1. `POST /v1/public/report` (Site target) → 202 + case lands
2. Staff (AAL2) lists queue → sees the case
3. Staff triages → takes → decides `hide_site`
4. Confirm: `sites.moderation_state='hidden'`, `SyncSubdomainToKvJob` fired, reporter got outcome email, reported user got statement-of-reasons email, `audit.moderation_events` has the trail

### Journey 2 — CSAM match → auto-action (spans A + B + C)
1. Sign + POST a Cloudflare-shape CSAM payload to `/v1/internal/cloudflare-csam-webhook` → 200
2. Confirm in ONE transaction: case (`csam_match`, severity 5, `auto_actioned`), `csam_quarantine` row, `ncmec_submissions` row, system decision (`decided_by_system=true`)
3. Confirm dispatched: media quarantined, user `status='suspended'`, site `moderation_state='hidden'`, edge cache purge, on-call staff notified, CyberTip filed (or queued)
4. Staff override path: attempt `override_csam_auto_action` without second staff → rejected; with second staff → accepted + supersedes-linked decision

## Synthesis — the go/no-go report

Write `docs/moderation/integration-review-2026-05-29.md` containing:

1. **Verdict:** GO / GO-WITH-CONDITIONS / NO-GO for production launch
2. **Findings table:** every finding from the six agents + the two smoke journeys, with: severity (P0/P1/P2/P3), dimension, location (file:line), description, recommended fix
3. **Cross-plan seam map:** the signal→case→decision→action chains, each marked intact/broken
4. **Spec coverage matrix:** requirement → implemented | deferred
5. **Scale verdict:** the EXPLAIN results for each hot path + the "100k sites" assessment
6. **Launch prerequisites status:** what's done, what's outstanding (NCMEC registration etc.)
7. **Recommended follow-up tasks:** ordered list of fixes needed before launch (if any), each sized S/M/L

## Severity rubric

- **P0** — blocks launch: auth bypass, PII leak, broken cross-plan seam, migration that doesn't apply, CSAM auto-action that doesn't fire, missing two-staff enforcement on override
- **P1** — fix before launch: missing index on a growth path, missing capability gate, incomplete audit trail, a deferred feature that's half-built
- **P2** — fix soon after launch: missing observability breadcrumb, runbook inaccuracy, non-critical test gap
- **P3** — nice to have: naming, comments, minor cleanup

## Success criteria for THIS review

- [ ] All six review agents completed and reported
- [ ] Fresh-DB migration test passed (or P0 logged)
- [ ] Both integration smoke journeys executed
- [ ] `docs/moderation/integration-review-2026-05-29.md` written with a clear verdict
- [ ] Every P0/P1 finding has a recommended fix + size estimate
- [ ] The "scales to 100k sites" goal is explicitly assessed with query-plan evidence

## What this review does NOT do

- It does not fix findings (those become follow-up implementer tasks)
- It does not flip `PARTNA_CSAM_SCAN_ENABLED=true` in production (that's a deliberate human action gated on the launch prerequisites)
- It does not push or merge anything

---

**Begin with the pre-flight checks. If all three plans are confirmed merged and the fresh-DB migration passes, dispatch the six review agents in parallel, then run the two smoke journeys, then write the synthesis report.**
