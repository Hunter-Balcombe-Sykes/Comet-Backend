# Trust & Safety — operator runbook

> **Scope:** Plan B landed. Staff queue, decision pipeline, and lifecycle commands are live. CSAM scanning ships in Plan C.

## Current state of moderation

| Capability | Status |
|------------|--------|
| Public `POST /v1/public/report` | ✅ live |
| Cases dedup + merge | ✅ live |
| Evidence snapshotting (Site target) | ✅ live |
| Audit log (`audit.moderation_events`) | ✅ live |
| Staff queue endpoints | ✅ live |
| Decision pipeline + outcome jobs | ✅ live |
| AAL2-gated staff workflow | ✅ live |
| Lifecycle commands (SLA scan, redact, show, reverse) | ✅ live |
| CSAM scanning | ⏳ Plan C |

## Staff workflow

See [`docs/moderation/staff-workflow.md`](staff-workflow.md) for the day-to-day staff playbook.

## Quick checks

```bash
# How many open cases?
php artisan tinker --execute='echo App\Models\Moderation\ModerationCase::where("status","open")->count();'

# Show a specific case (JSON)
php artisan moderation:show-case <case_id>

# Latest 5 audit events
php artisan tinker --execute='dump(App\Models\Moderation\AuditEvent::latest()->limit(5)->get()->toArray());'

# SLA scan (normally runs every 15min via scheduler)
php artisan moderation:sla-scan

# Reverse a decision
php artisan moderation:reverse-decision <decision_id> --reason="…"

# GDPR erasure — redact reporter PII from signals
php artisan moderation:redact-reporter-pii <case_id> --reason="user request"
```

## Things that can go wrong (and what to look for)

| Symptom | Hypothesis | Where to look |
|---------|-----------|---------------|
| Reports return 429 unexpectedly | Per-target Redis counter not expiring | `redis-cli KEYS 'moderation:report:ip:*'` |
| Dedup blocking legitimate retries | UNIQUE index on `case_signals.dedup_hash` | Verify reporter actually changed reason_code |
| Captcha false-rejecting in dev | `partna.bot_protection.mode` is `enforce`; tests bypass via `mode='off'` | Check `config('partna.bot_protection.mode')` in the test's `beforeEach` |
| Staff getting 401 on moderation routes | AAL2 not satisfied — browser session may not have TOTP registered | Staff must enroll TOTP; see MFA runbook |
| Outcome jobs stuck in queue | Check `moderation_high` Horizon queue via Laravel Horizon dashboard | `php artisan horizon:list` or cloud env:logs |

## Cloud log queries

```bash
cloud env:logs partna development --minutes 15 | grep moderation
```

## Key schemas

- `moderation` — cases, case_signals, evidence, decisions, action_log
- `audit` — moderation_events (append-only, no UPDATE/DELETE)

## Service classes

| Class | Responsibility |
|-------|---------------|
| `ContentReportService` | Orchestrates submit flow (resolve → dedup → merge/open → snapshot → notify) |
| `EvidenceSnapshotService` | Captures immutable content snapshot at signal time |
| `ModerationAuditService` | Write path for `audit.moderation_events` — strips PII |
| `ModerationCaseService` | Triage, take, release operations |
| `ModerationDecisionService` | **Only** legal write path to `decisions` + dispatches outcome jobs |
| `ModerationActionDispatcher` | Maps decision types to outcome job sets |
| `CaseStateMachine` | Pure FSM for status transitions — no side effects |
| `DedupHashCalculator` | Computes `dedup_hash` for signal deduplication |
