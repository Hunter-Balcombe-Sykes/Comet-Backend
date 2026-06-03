# Plan C — CSAM Scanning Pipeline: Execution Prompt

**Hand this prompt to a fresh Claude Code session to execute Plan C.**

---

## Your task

Execute implementation Plan C (Cloudflare CSAM scanning pipeline, R2 quarantine, NCMEC CyberTipline outbox, auto-action orchestrator) end-to-end via subagent-driven development, then conduct a comprehensive post-implementation review before declaring complete.

## Inputs you will read

| File | Purpose |
|------|---------|
| `docs/superpowers/specs/2026-05-26-trust-and-safety-foundation-design.md` | Design spec (read sections 4, 6.8-6.10, 7.2, 7.5, 8, 10, 13) |
| `docs/superpowers/plans/2026-05-26-trust-and-safety-foundation-plan-c.md` | The plan you execute. 19 tasks across 11 phases |

## Dependencies

- **Plans A and B must be merged into `development`** before starting. Plan C reuses:
  - `moderation` schema + Plan A models
  - `ModerationDecisionService` (Plan B) — Plan C **extends** it with `decideAsSystem()` in Task 4
  - `ModerationActionDispatcher`, `QuarantineMediaJob`, `SuspendUserJob`, `SuspendSiteJob`, `PurgeModerationCacheJob`, `NotifyOnCallStaffJob` (all from Plan B)
  - `AccountCapabilitySet` already extended (Plan B Task 14)
- If Plans A or B are not on `origin/development`, STOP and escalate.

## Pre-flight (do these before dispatching any subagent)

1. **Pull latest:** `git fetch origin && git checkout development && git pull origin development`
2. **Verify Plans A and B are merged:**
   ```bash
   ls supabase/migrations/ | grep -E '20260528'
   php -r 'require "vendor/autoload.php"; echo method_exists(\App\Services\Moderation\ModerationDecisionService::class, "decide") ? "OK" : "MISSING";'
   php -r 'require "vendor/autoload.php"; $set = new ReflectionClass(\App\Services\Accounts\AccountCapabilitySet::class); echo $set->hasMethod("__construct") && in_array("can_be_reported", array_map(fn($p)=>$p->name, $set->getConstructor()->getParameters())) ? "OK" : "MISSING";'
   ```
3. **Create feature branch:** `git checkout -b feat/ts-foundation-plan-c`
4. **Baseline tests pass:** `composer test` && `php artisan test --group=postgres`

If any pre-flight fails, STOP and escalate.

## Manual ops prerequisites (track but do NOT block code work on these)

These happen outside the codebase. Plan C documents them in `docs/moderation/csam-pipeline.md`; the production launch checklist gates on them. **Code execution proceeds without these being done; production launch does not.**

| Step | Who | Note |
|------|-----|------|
| Create `partna-media-quarantine` R2 bucket (private-only access) | Josh / ops | Required before flipping `PARTNA_CSAM_SCAN_ENABLED=true` in prod |
| Enable Cloudflare CSAM Scanning Tool on the quarantine bucket | Josh / ops | Required |
| Configure webhook → `https://dev-api.partna.au/v1/internal/cloudflare-csam-webhook` | Josh / ops | Capture secret → `CLOUDFLARE_CSAM_WEBHOOK_SECRET` env var |
| Register Partna Pty Ltd as ESP with NCMEC (designate Josh Hunter as primary contact) | Josh | ~2 weeks approval; required for prod launch only |
| Obtain `NCMEC_API_KEY` + `NCMEC_ESP_ID` env vars | Josh | Required for prod launch only |

Add these to the Task 19 launch checklist as you go.

## Execution mode (REQUIRED)

Use the `superpowers:subagent-driven-development` skill. For EACH of the 19 tasks:

1. Extract task text + context from the plan file.
2. Dispatch implementer subagent.
3. Handle status: DONE | DONE_WITH_CONCERNS | NEEDS_CONTEXT | BLOCKED.
4. Dispatch spec-compliance reviewer.
5. Loop until approved.
6. Dispatch code-quality reviewer.
7. Loop until approved.
8. Mark complete in TodoWrite.
9. Move to next task. Execute continuously.

## Architectural decisions that are NON-NEGOTIABLE

| Decision | Why locked |
|----------|------------|
| Two separate R2 buckets: `partna-media-quarantine` (private) and the existing production bucket (public). Uploads route to quarantine when `PARTNA_CSAM_SCAN_ENABLED=true` | Security boundary — public access only after clean scan |
| Cloudflare's CSAM Scanning Tool is the detection mechanism (free, scans against NCMEC PhotoDNA). No alternative scanner is introduced | Already in the stack; multiple scanners = multiple integration surfaces |
| `CsamMatchHandlerService::handle()` creates case + signal + evidence + quarantine + outbox + decision **in one DB transaction**. `decideAsSystem()` triggers `ModerationActionDispatcher` (existing from Plan B) | All-or-nothing atomicity — no orphan quarantine rows without a decision |
| `FileCyberTipReportJob` uses `moderation_high` Horizon queue with `$tries=1` per dispatch — outbox row + `moderation:retry-ncmec-submissions` command drives retry | Standard Horizon retry can't survive worker death between API call + response; outbox is the durable store |
| After 5 failed attempts, `ncmec_submissions.status='manual_fallback_required'` + Nightwatch critical alert. Manual submission via NCMEC web portal | Hash matching has near-zero false positives; persistent submission failure = real infra problem, not retryable forever |
| `EnforceCsamScanGate` middleware (or equivalent read-path filter) blocks `processing_state IN ('scanning','quarantined')` from public media output. Grandfathered media (`scanned_at IS NULL AND created_at < cutoff`) renders normally | Defence-in-depth on top of `processing_state='ready'` filter |
| `moderation:audit-quarantine-bucket` runs daily and fails loudly if the quarantine bucket has any public access | Drift detection — Terraform misapplies happen |
| 90-day binary preservation, then `moderation:expire-csam-quarantine` deletes from R2 but **keeps metadata row** | Legal preservation + audit trail balance |
| Webhook signature verification: HMAC-SHA256("\<ts\>.\<body\>", secret); 5-minute clock skew; Redis-backed 10-minute nonce store for replay protection | Standard webhook security |
| `decideAsSystem()` writes a Decision with `decided_by_system=true`, `auto_actioned=true`, `decided_by_staff_id=null`. DB CHECK `decisions_actor_xor` enforces this from Plan A | Auto-actions and human decisions take the same dispatch path; only attribution differs |

## Forbidden patterns — abort if a subagent introduces any

```bash
# Should return zero matches
git diff --cached | grep -E 'App\\Models\\Core\\Professional\\User|App\\Models\\Site\\Site|App\\Models\\Core\\PartnaStaff[^/]'
git diff --cached | grep -E 'where\\([\x27"]is_on_call|where\\([\x27"]is_active'
git diff --cached | grep -E 'kv->put\\(|SUBDOMAIN_KV.+put'   # only SyncSubdomainToKvJob may write KV
git diff --cached supabase/migrations/ | grep -E 'CREATE SCHEMA IF NOT EXISTS audit'
git diff --cached supabase/migrations/ | grep -E 'core\\.users[^;]+moderation_state'

# CSAM-specific forbidden patterns
git diff --cached | grep -E 'r2_binary_deleted.*false.*WHERE.*expired'   # don't auto-delete unless expiry passed
git diff --cached | grep -E 'tries\\s*=\\s*[2-9]'   # FileCyberTipReportJob must be tries=1 (outbox drives retry)
```

## Post-implementation review stage (REQUIRED — after all 19 tasks land)

### 1. Final code-reviewer subagent

Dispatch with:
- Plan + spec paths
- `git diff origin/development...HEAD`
- All 19 tasks confirmed complete

Reviewer's mandate:
- `CsamMatchHandlerService::handle()` is a single DB transaction
- `FileCyberTipReportJob` uses outbox pattern (writes row first; updates after API response)
- Webhook signature verification rejects: missing header, bad HMAC, replay, stale timestamp
- `PromoteCleanMediaJob` is bounded (max 100 rows per run) and idempotent
- `EnforceCsamScanGate` (or the read-path filter equivalent) is wired into every public media-serve endpoint — grep for unprotected reads
- `decideAsSystem()` passes through the existing `ModerationActionDispatcher` (does not duplicate dispatch logic)
- `csam_quarantine` rows have `ON DELETE RESTRICT` on `case_id` and `site_media_id`

### 2. Full test suite — zero failures

```bash
composer test
php artisan test --group=postgres
php artisan test tests/Feature/Moderation/
php artisan test tests/Feature/Security/WebhookSignatureForgeryTest.php
php artisan test tests/Feature/Commands/Moderation/
php artisan test tests/Unit/Services/Moderation/
```

### 3. Forbidden-pattern sweep

Re-run the full forbidden-pattern grep block. Any match → re-open the relevant task.

### 4. Critical security tests (must pass)

```bash
php artisan test tests/Feature/Security/WebhookSignatureForgeryTest.php
php artisan test tests/Feature/Moderation/CsamAutoActionPipelineTest.php
php artisan test tests/Feature/Moderation/EnforceCsamScanGateTest.php
php artisan test tests/Feature/Commands/Moderation/AuditQuarantineBucketCommandTest.php
```

### 5. End-to-end smoke (Task 19 Step 3)

With `PARTNA_CSAM_SCAN_ENABLED=true`:
- Benign image upload → routes to quarantine bucket
- Wait ~90s for `PromoteCleanMediaJob`
- Confirm `site_media.processing_state='ready'`, `scanned_at` populated, binary moved to production bucket, quarantine bucket empty for that key

### 6. Webhook signature smoke

Manually sign a Cloudflare-shape payload (use Task 9's signing recipe) and POST to `/v1/internal/cloudflare-csam-webhook`. Confirm 200 + case + quarantine + ncmec_submission rows.

### 7. Outbox retry flow smoke

Mark an `ncmec_submissions` row as `failed` with `attempts=2`. Run `php artisan moderation:retry-ncmec-submissions`. Confirm `FileCyberTipReportJob` re-dispatches and the row resolves (or hits `manual_fallback_required` at attempts=5).

### 8. Quarantine bucket access audit

Run `php artisan moderation:audit-quarantine-bucket` against dev. Must pass (return OK).

### 9. Operator runbook updated

Confirm `docs/moderation/csam-pipeline.md` exists and `docs/moderation/README.md` status table marks Plan C complete.

### 10. Production launch prerequisites — track but DO NOT FLIP THE FLAG

Confirm Task 19 Step 2 prerequisites table is filled in for dev. For prod, the flag stays `false` until:
- NCMEC ESP registration complete with prod credentials
- Prod R2 quarantine bucket created
- Cloudflare CSAM scanning enabled on prod bucket
- Webhook configured against prod URL with prod secret

PR should explicitly call out that `PARTNA_CSAM_SCAN_ENABLED=true` requires these to be done first.

### 11. Generate PR

Use Task 19 Step 6's template, including the ⚠ prod-launch-prerequisites callout.

## Success criteria

- [ ] All 19 tasks marked complete in TodoWrite
- [ ] Per-task reviews approved
- [ ] Final post-implementation reviewer approved
- [ ] Full test suite passes
- [ ] Forbidden-pattern sweep returns zero matches
- [ ] All four critical security tests green
- [ ] Benign-upload smoke verified end-to-end on dev
- [ ] Webhook signature smoke verified (200 + rows)
- [ ] Outbox retry smoke verified
- [ ] Quarantine bucket audit passes
- [ ] `docs/moderation/csam-pipeline.md` exists; README updated
- [ ] PR created, prod launch prerequisites flagged in description
- [ ] `PARTNA_CSAM_SCAN_ENABLED` stays `false` in production env (do not flip without user explicit OK)

## Escalation rules

Same as Plans A and B. Stop and ask if:
- BLOCKED implementer unresolvable in one re-dispatch
- Cloudflare R2/CSAM API surface differs from what the plan assumes (likely if the API moved between when the spec was written and execution)
- NCMEC API contract differs from the plan's assumption
- Test failure unresolvable in 3 attempts
- Subagent proposes reverting any locked architectural decision
- A scope ambiguity: e.g., the spec assumes a specific Cloudflare scan-status response shape — if the real API uses a different shape, ask before adapting

## What ships after Plan C completes

- Photo uploads route through private R2 quarantine bucket before going public
- Cloudflare CSAM Scanning Tool scans every upload
- Clean media auto-promotes to production within ~60s
- CSAM matches trigger full auto-action pipeline (quarantine + suspend + cache purge + NCMEC report + on-call notification) in one transaction
- 90-day legal preservation pipeline operational
- Daily quarantine-bucket-public-access audit runs
- NCMEC submissions use durable outbox with 5-retry exponential backoff + manual fallback escalation

**Trust & Safety Foundation: complete** after this plan merges and `PARTNA_CSAM_SCAN_ENABLED=true` is flipped in prod (with all launch prerequisites met).

---

**Begin by reading the plan file completely, creating a TodoWrite list of all 19 tasks, and then dispatching the first implementer subagent for Task 1.**
