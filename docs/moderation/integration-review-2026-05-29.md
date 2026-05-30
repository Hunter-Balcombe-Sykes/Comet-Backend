# Trust & Safety Foundation — Final Cross-Plan Integration Review

**Date:** 2026-05-29
**Branch reviewed:** `development` @ `1cd15864`
**Scope:** Plans A (schema + reporting) + B (staff workflow + outcomes) + C (CSAM pipeline), merged.
**Reviewer:** Claude (6 parallel static review agents + direct verification of all P0/P1 findings).

---

## ✅ Resolution (2026-05-29, same day)

All findings below were subsequently fixed in the working tree (uncommitted) and independently re-reviewed (verdict: safe to commit). Migrations were applied to a fresh local Postgres via psql and the DB-level fixes verified (app_backend now has `moderation` grants; `csam_auto_suspend` + distinct-second-staff CHECKs in place; new indexes present). Full SQLite suite green (1607 passed, 0 failed); the M-FAIL test now passes. **F19 and F20 were intentionally not changed** (documented non-issues). One **pre-existing** follow-up remains: a TOCTOU race in `ModerationDecisionService::decide`/`ModerationCaseService::take` (status checked outside the transaction without `lockForUpdate`) — not introduced by these fixes; harden separately. The verdict below reflects the *pre-fix* state.

---

## ⚖️ Verdict (pre-fix): **NO-GO** for production launch

Two **verified P0** defects make the feature non-functional in production as written, plus a CSAM enforcement gap that means the auto-action does not actually remove illegal content. None are cosmetic; all are fixable. Re-review after the P0s + the CSAM-dispatch P1s are fixed.

**The launch-blocking trio (all verified by reading code, not just agent report):**

1. **P0 — `moderation` schema has no `GRANT`s to `app_backend`.** Every moderation query fails in prod with `permission denied for schema moderation`. The whole feature is dead on arrival in any environment where Laravel connects as `app_backend` (i.e. real dev + prod). Hidden locally because the local stack connects as `postgres` (superuser).
2. **P0 — CSAM auto-action never quarantines the matched media and never hides the site.** `QuarantineMediaJob` is dead code (never dispatched), and `SuspendSiteJob` silently no-ops for CSAM cases. The auto-action suspends the user and files NCMEC, but the actual CSAM image's `processing_state` is never set to `quarantined` and `sites.moderation_state` is never set to `hidden`.
3. **P1 (operational, launch-blocking for CSAM) — on-call staff are never paged on a CSAM match.** `NotifyOnCallStaffJob` is not in the CSAM action set.

---

## Pre-flight results

| Step | Result |
|------|--------|
| All three plans merged | ✅ `ModerationCase` (A), `ModerationDecisionService` (B), `CsamMatchHandlerService` (C) all resolve |
| Fresh-DB migration test (`supabase db reset`) | ❌ **NOT COMPLETED** — blocked by 3 pre-existing (non-T&S) issues; see "Migration / provisioning findings" below |
| Baseline `composer test` (SQLite) | ⚠️ **1600 passed, 1 failed, 47 skipped, 4 todos** — failing test: `tests/Feature/Moderation/PromoteCleanMediaJobTest` (postgres group); see M-FAIL below |
| `php artisan test --group=postgres` | **NOT EXECUTED** — no live Postgres available this session |
| Smoke Journey 1 (human report → resolution) | **NOT EXECUTED** — needs live DB + app stack |
| Smoke Journey 2 (CSAM webhook → auto-action) | **NOT EXECUTED** — needs live DB + app stack |
| Live `EXPLAIN` query plans (Agent 5) | **NOT EXECUTED** — index/query match assessed statically from migration SQL |

> **Why the live-DB steps were skipped:** the local Supabase stack could not be brought up to a clean state (see migration findings). Per user direction the DB-dependent steps were skipped and the static review run instead. The two smoke journeys and live EXPLAIN must be run before final sign-off.

---

## Findings table

`[V]` = verified by directly reading the code during this review. `[A]` = agent-reported, high-confidence (often cross-corroborated) but not independently re-verified here.

| # | Sev | Dimension | Location | Description | Recommended fix | Size |
|---|-----|-----------|----------|-------------|-----------------|------|
| F1 | **P0** `[V]` | Schema | `supabase/migrations/20260528000000_create_moderation_schema.sql` (+ baseline grant block) | No `GRANT USAGE ON SCHEMA moderation` / table grants to `app_backend`. Baseline grants only core/site/notifications/analytics/public; `audit` was added in `20260527010000`; `moderation` was never granted. All moderation DB ops fail as `app_backend`. | New migration: `GRANT USAGE ON SCHEMA moderation TO app_backend;` + `GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA moderation TO app_backend;` + matching `ALTER DEFAULT PRIVILEGES`. Mirror the baseline app_backend block. | S |
| F2 | **P0** `[V]` | Seams / CSAM | `ModerationActionDispatcher.php:25-35,70-82`; `CsamMatchHandlerService.php:134-145`; `QuarantineMediaJob.php` | `QuarantineMediaJob` is never dispatched. No `quarantine_media` action type exists in `ACTIONS_BY_DECISION` and the handler dispatches only `FileCyberTipReportJob` directly. CSAM media's `processing_state` is never set to `quarantined`. | Add `quarantine_media` to the CSAM action set (see fix for F3/F4 — preferably a dedicated `csam_auto_suspend` decision type) and a `dispatchJob` case mapping it to `QuarantineMediaJob`, passing `site_media_id` in `action_target`. | M |
| F3 | **P0** `[V]` | Seams / CSAM | `SuspendSiteJob.php:52-56`; `CsamMatchHandlerService.php:80-81` | `SuspendSiteJob` only acts when `reportable_type === 'Site'`. CSAM cases are `reportable_type='SiteMedia'` with `reportable_id` = media id, so the site is **never** hidden. `SuspendUserJob` only sets `users.status='suspended'`; it does not touch `sites.moderation_state`. | Resolve the owning `site_id` for CSAM (from the SiteMedia row) and hide it — either teach `SuspendSiteJob` to resolve site-from-media when `reportable_type='SiteMedia'`, or add a media-aware site-hide step to the CSAM action set. Confirm end-to-end that a suspended-owner site stops serving at the edge. | M |
| F4 | **P1** `[V]` | Seams / Ops / CSAM | `ModerationActionDispatcher.php:30` | `NotifyOnCallStaffJob` (`notify_oncall_staff`) is only in `override_csam_auto_action` / escalation sets, not in the `suspend_user` set used by the CSAM auto-action. On-call staff are not paged on auto-detected CSAM. | Add `notify_oncall_staff` to the CSAM auto-action's action set. | S |
| F5 | **P1** `[V]` | Ops | `app/Jobs/Moderation/*` | 7 of 10 moderation jobs run on the **default** queue (only `SuspendUserJob`, `NotifyOnCallStaffJob`, `FileCyberTipReportJob` set `moderation_high`). Enforcement jobs `SuspendSiteJob`, `PurgeModerationCacheJob`, `QuarantineMediaJob` can sit behind a default-queue backlog. | Set `$this->queue = 'moderation_high'` on the time-sensitive enforcement jobs (suspend site, purge cache, quarantine media, notify reported user). | S |
| F6 | **P1** `[V]` | Ops / Spec | `config/partna.php:1166,1201`; `CsamMatchHandlerService::handle()` | `PARTNA_MODERATION_AUTO_ACTIONS_ENABLED` kill-switch is not implemented. Only `moderation.enabled` and `csam.enabled` exist. No runtime way to disable auto-actions during an incident without a deploy; `CsamMatchHandlerService` checks no such flag. | Add `moderation.auto_actions_enabled => env('PARTNA_MODERATION_AUTO_ACTIONS_ENABLED', true)`; gate the auto-action dispatch in the CSAM handler / decision service behind it. | S |
| F7 | **P1** `[V]` | Scale | `PromoteCleanMediaJob.php`; `supabase/migrations/20260528020000_alter_site_media_for_scan_states.sql` | No partial index supporting `WHERE processing_state='scanning' AND scanned_at IS NULL`. The job runs every ~60s; at 100k sites this is a recurring seqscan on `site.site_media`. (Job IS correctly bounded at `LIMIT 100`.) | Add `CREATE INDEX CONCURRENTLY site_media_scanning_idx ON site.site_media (created_at) WHERE processing_state='scanning' AND scanned_at IS NULL`. (NB: see migration finding M3 re: CONCURRENTLY + `db reset`.) | S |
| F8 | **P1** `[A]` | Spec / CSAM | `DecideOnCaseRequest.php`; `StaffCaseController::decide` | No guard preventing `decision_type='dismiss'` (or other non-CSAM types) on a `csam_match` case. Spec §9 requires a 422 `INVALID_DECISION_FOR_CASE_TYPE`. | Validate decision_type against case_type in the FormRequest or service; reject invalid combos with 422. | S |
| M-FAIL | **P1** `[V]` | Tests | `tests/Feature/Moderation/PromoteCleanMediaJobTest.php:76` | Baseline test fails: expected `processing_state='ready'` after promote, got `'scanning'`. Ran against a reachable pgsql test connection. May be environmental (DB churn this session) or a real promote-flow bug. | Re-run under a clean migrated Postgres; if it still fails, the clean-media promotion path has a real bug. | S–M |
| F9 | **P2** `[V]` | Seams / Spec | `ModerationActionDispatcher.php:27` | `warn` decision dispatches `notify_reported_user`. Spec §4 locked decision (per Agent 4) says do **not** auto-notify on `warn`. | Remove `notify_reported_user` from the `warn` action set (or reconcile the spec). | S |
| F10 | **P2** `[V]` | Security | `ContentReportService::submit()`; `AccountCapabilitySet` | `can_be_reported` capability is defined, computed, and unit-tested, but never checked at submission. Suspended/banned users can still be reported (queue noise + minor status enumeration). | Check `AccountCapabilities::for($owner)->can_be_reported` in `submit()` before opening/merging; return a synthetic 202 receipt to avoid enumeration. | S |
| F11 | **P2** `[A]` | Security | `StaffCaseController::index()` | `index` does not call `authorizeForUser($staff,'viewAny',ModerationCase::class)`, leaving `CasePolicy::viewAny()` dead. Safe today (staff middleware gates the route) but breaks defence-in-depth. | Add the `authorizeForUser` call. | S |
| F12 | **P2** `[A]` | Schema | `20260529000000_create_csam_quarantine_table.sql` | `csam_quarantine.case_id` FK is `ON DELETE RESTRICT` with no covering index → seqscan on every case delete. | Add index on `csam_quarantine(case_id)`. | S |
| F13 | **P2** `[A]` | Schema / Security | `decisions` CHECK `decisions_csam_override_requires_second_staff` | CHECK requires a second-staff id but does not enforce `second_staff_approval_id <> decided_by_staff_id`. A single staffer could satisfy the two-person rule. | Strengthen CHECK (and/or FormRequest) to require the two ids differ. | S |
| F14 | **P2** `[A]` | Observability | `ContentReportService`, `ModerationDecisionService`, `CsamMatchHandlerService` | Missing Nightwatch breadcrumbs: `moderation.case.opened`, `moderation.decision`, `moderation.auto_action` (handler logs `csam_handler.match_received`, not the spec name). SLA, NCMEC-fallback, quarantine-drift breadcrumbs ARE wired. | Add the missing breadcrumbs at case open, decision write, and auto-action. | S |
| F15 | **P2** `[A]` | Ops / Docs | `docs/moderation/staff-workflow.md` | SLA table wrong: severity-5 documented 2h (config 1h), severity-4 documented 24h (config 4h). Staff under-prioritise. | Correct the runbook to match `config/partna.php`. | S |
| F16 | **P2** `[A]` | Spec | `StaffCaseController` race paths | `ALREADY_TAKEN` / `CASE_ALREADY_RESOLVED` return 422; spec expects 409 Conflict. | Return 409 for these concurrency conflicts. | S |
| F17 | **P3** `[A]` | Security | `VerifyBotToken.php:73,179` | Logs raw `request->ip()` on captcha-error / circuit-breaker-open paths (pre-existing; surfaces on the new report route). | Hash the IP (consistent with `PerTargetReportThrottle`). | S |
| F18 | **P3** `[A]` | Ops / Docs | `.env.example` | `PARTNA_MODERATION_ENABLED` not documented in `.env.example` (exists in config, default true). | Add it to `.env.example`. | S |
| F19 | **P3** `[A]` | Observability | `ncmec_submissions_pending_idx` | Partial index excludes `manual_fallback_required` (the alerting state). | Include `manual_fallback_required` in the index predicate. | S |
| F20 | **P3** `[A]` | Spec | `audit.moderation_events` vs spec `moderation.audit_log`; `moderation.csam.enabled` vs spec `moderation.csam_scan_enabled` | Naming/placement deviations from spec (arguably better designs). | Reconcile spec or code naming. | S |

---

## Cross-plan seam map

| Seam | Status | Evidence |
|------|--------|----------|
| Unified pipeline terminates in one `ModerationActionDispatcher` (human + CSAM) | ✅ intact | `ModerationDecisionService::decide` + `decideAsSystem` both call `dispatchFor()` |
| Single decision-write path via `CaseStateMachine` | ⚠️ one bypass | `ModerationReverseDecisionCommand` writes `Decision::create()` directly (documented stop-gap) — track as P3/P2 |
| `decide` (B) and `decideAsSystem` (C) share `CaseStateMachine` | ✅ intact | Both use injected state machine; `auto_actioned→resolved` transition legal for override |
| Capability gate `receive_moderation_notifications` consulted | ✅ intact | `NotifyReportedUserJob` checks it, fail-closed |
| Capability `can_be_reported` consulted at submit | ❌ broken | Defined/tested but never called in `ContentReportService::submit()` (F10) |
| **CSAM auto-action dispatches full job set** | ❌ **broken** | Missing `QuarantineMediaJob` (F2), `SuspendSiteJob` no-ops (F3), `NotifyOnCallStaffJob` absent (F4). Only SuspendUser + PurgeCache + NotifyReportedUser + FileCyberTip actually fire. |

---

## Spec coverage matrix (summary — Agent 4)

- **89** requirements covered; **3** covered with documented deviations; **8** genuinely deferred (appeals, trusted flaggers, DSA SOR enum, EU Transparency DB submitter, ML detection, SiteMedia/Block reportable types) — **all clean, no half-built scaffolding**.
- Locked decisions (§4) mostly hold. Deviations: `warn` auto-notifies (F9), audit-log schema placement (F20), CSAM config nesting (F20).
- Gaps that are also findings above: missing schema grant (F1), CSAM dispatch set (F2/F3/F4), CSAM dismiss guard (F8), auto-actions flag (F6).

Full per-requirement matrix in scratch: `/tmp/ts-review/agent-4-spec.md`.

---

## Scale verdict (static — EXPLAIN NOT EXECUTED)

Goal: "won't need rearchitecting at 100k sites." **Largely met, with one index gap (F7).**

| Hot path | Index | Static verdict |
|----------|-------|----------------|
| Staff queue list (`status IN (open,triaged,under_review) ORDER BY severity DESC, priority ASC, created_at ASC`) | `cases_open_queue_idx` (partial) | ✅ column order + partial predicate match. ⚠️ if `index()` is called with no status filter the predicate isn't satisfied → consider a default status filter (Agent 5 F-1, P2). |
| Case-merge lookup (`reportable_type=? AND reportable_id=? AND status IN (...)`) | `cases_target_open_idx` (partial) | ✅ matches; `lockForUpdate()` prevents merge race. |
| Dedup | `case_signals_dedup_uniq` UNIQUE | ✅ DB-level, not app-race-prone. |
| N+1 | — | ✅ list uses `CaseResource` (no eager loads); detail uses `->with([signals,evidence,decisions])` + `whenLoaded`. |
| `PromoteCleanMediaJob` | (none) | ❌ no partial index for the `scanning` query → recurring seqscan (F7, P1). Job IS bounded at LIMIT 100. |
| Partial indexes keep resolved cases off hot path | `WHERE status IN (...)` predicates | ✅ confirmed. |

Resolved-case history does not bloat the hot path. The case/signal/decision model scales; the one real scale risk is F7. **Live EXPLAIN must still be run before sign-off.**

---

## Migration / provisioning findings (pre-flight; pre-existing, NOT caused by T&S)

These surfaced running the fresh-DB test and block a from-zero `supabase db reset`/fresh-prod provision.

- **M1 (P0, FIXED — uncommitted):** `20260525104153_correct_boolean_defaults.sql` sorted before the baseline it patches → `schema "site" does not exist`. Renamed to `20260526000001_correct_boolean_defaults.sql` (byte-identical content).
- **M2 (P0, FIXED — uncommitted):** baseline created `app_backend` (~L2255) after referencing it in policies (~L1706). Hoisted a guarded `CREATE ROLE app_backend` to a new "section 1b" after schema creation.
- **M3 (OPEN — tooling, owner: Josh):** `supabase db reset`/`start` (CLI v2.98.2 **and** v2.101.0) batches each migration file into a libpq pipeline; `CREATE INDEX CONCURRENTLY` cannot run in a pipeline. Many index files (incl. `20260528000001_create_moderation_indexes.sql`, 18 statements) hit this. Migrations are convention-correct (`CONVENTIONS.md` §1). **Open risk:** `db push` shares this applier — a fresh-prod provision may hit the same wall. Candidate fix: one CONCURRENTLY statement per file. **Relevant to F7's fix** (any new CONCURRENTLY index inherits this constraint).

---

## Launch prerequisites status

| Prerequisite | Status |
|--------------|--------|
| `PARTNA_CSAM_SCAN_ENABLED` defaults to `false` | ✅ verified (`config/partna.php:1201`, `.env.example:300`) |
| Scheduled commands registered (sla-scan 15m, expire-csam-quarantine daily, audit-quarantine-bucket daily, retry-ncmec 5m) | ✅ in `routes/console.php` (L12 scheduler) with overlap guards |
| Horizon `moderation_high` lane defined | ✅ in `config/horizon.php` (defaults + production) — but most jobs don't use it (F5) |
| `app_backend` can access `moderation` schema | ❌ **F1 — must fix before any env** |
| CSAM auto-action actually isolates content | ❌ **F2/F3 — must fix** |
| On-call paging on CSAM | ❌ **F4** |
| Auto-actions runtime kill-switch | ❌ **F6** |
| NCMEC ESP registration / R2 quarantine bucket / Cloudflare CSAM tool / webhook secret / on-call channels | ⏳ external/human — confirm with Josh; on-call channel setup missing from CSAM runbook checklist (F-ops P3) |
| Operator runbooks accurate | ⚠️ SLA values wrong (F15); otherwise present |
| Fresh-DB / fresh-prod provisioning works | ❌ blocked by M1/M2 (fixed, uncommitted) + M3 (open) |

---

## Recommended follow-up tasks (ordered; do before re-review)

**Must-fix before launch (P0/P1):**
1. **(S)** F1 — add `moderation` schema grants migration for `app_backend`. *Without this nothing works.*
2. **(M)** F2 + F3 + F4 — fix the CSAM auto-action set: dispatch `QuarantineMediaJob`, hide the owning site (resolve site-from-media), page on-call. Strongly consider a dedicated `csam_auto_suspend` decision type instead of overloading `suspend_user`, so the action set is explicit and can't drift. Add a test asserting the full CSAM job set fires.
3. **(S)** F6 — implement the `PARTNA_MODERATION_AUTO_ACTIONS_ENABLED` kill-switch and gate the CSAM handler on it.
4. **(S)** F5 — move enforcement jobs to `moderation_high`.
5. **(S)** F8 — reject invalid decision_type for `csam_match` cases (422).
6. **(S)** F7 — add the `site_media_scanning_idx` partial index (mind M3).
7. **(S–M)** M-FAIL — re-run `PromoteCleanMediaJobTest` on a clean Postgres; fix if it's a real promote bug.
8. Commit M1/M2 migration fixes; decide on M3 (CONCURRENTLY restructure) and verify fresh-prod `db push`.

**Fix soon after launch (P2):** F9, F10, F11, F12, F13, F14, F15, F16.
**Nice-to-have (P3):** F17, F18, F19, F20; the `ModerationReverseDecisionCommand` decision-write bypass.

**Before final sign-off (could not run this session):**
- Run both smoke journeys (human report → resolution; CSAM webhook → auto-action + override) against a live stack with `PARTNA_CSAM_SCAN_ENABLED=true`.
- Run `php artisan test --group=postgres` against a clean migrated Postgres.
- Run live `EXPLAIN` on the two hot-path queries to confirm index scans.

---

## Verification notes

- All **P0** findings (F1, F2, F3) and the structural **P1s** (F4, F5, F6, F7) were verified by directly reading the implementing code this session, not merely from agent summaries.
- P2/P3 items marked `[A]` are agent-reported (several cross-corroborated across agents) but not all independently re-verified — confirm before acting.
- Per-agent raw findings: `/tmp/ts-review/agent-{1-seams,2-schema,3-security,4-spec,5-scale,6-ops}.md` and `/tmp/ts-review/preflight-findings.md`.
