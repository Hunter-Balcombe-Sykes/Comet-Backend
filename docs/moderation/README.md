# Trust & Safety — operator runbook

> **Scope:** Plan A landed. Public reporting works end-to-end. Staff queue UI + outcome jobs ship in Plan B. CSAM scanning ships in Plan C.

## Current state of moderation

| Capability | Status |
|------------|--------|
| Public `POST /v1/public/report` | ✅ live |
| Cases dedup + merge | ✅ live |
| Evidence snapshotting (Site target) | ✅ live |
| Audit log (`audit.moderation_events`) | ✅ live |
| Staff queue endpoints | ⏳ Plan B |
| Decision pipeline + outcome jobs | ⏳ Plan B |
| CSAM scanning | ⏳ Plan C |

## Quick checks

```bash
# How many open cases?
php artisan tinker --execute='echo App\Models\Moderation\ModerationCase::where("status","open")->count();'

# Show the latest case
php artisan tinker --execute='dump(App\Models\Moderation\ModerationCase::latest()->first()->toArray());'

# Latest 5 audit events
php artisan tinker --execute='dump(App\Models\Moderation\AuditEvent::latest()->limit(5)->get()->toArray());'
```

## Things that can go wrong (and what to look for)

| Symptom | Hypothesis | Where to look |
|---------|-----------|---------------|
| Reports return 429 unexpectedly | Per-target Redis counter not expiring | `redis-cli KEYS 'moderation:report:ip:*'` |
| Dedup blocking legitimate retries | UNIQUE index on `case_signals.dedup_hash` | Verify reporter actually changed reason_code |
| Captcha false-rejecting in dev | `partna.bot_protection.mode` is `enforce`; tests bypass via `mode='off'` | Check `config('partna.bot_protection.mode')` in the test's `beforeEach` |

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
| `CaseStateMachine` | Pure FSM for status transitions — no side effects |
| `DedupHashCalculator` | Computes `dedup_hash` for signal deduplication |
| `NotifyStaffOfCaseUpdateJob` | Threshold-gated staff notification (admin role only) |
