# Staff moderation workflow

## Requirements

- Staff account with `role='admin'` or `role='staff'`
- MFA enrolled (TOTP) — AAL2 is enforced on all staff moderation routes. A 401 response means "step up your MFA", not "you're forbidden".

## Daily flow

1. **Log in with MFA** — all staff case routes require AAL2 (TOTP-backed session)
2. **Check the queue** — `GET /api/staff/cases?status=open` (sorted by severity desc, priority asc)
3. **Triage** — `POST /api/staff/cases/{id}/triage` with `{priority, notes}`. Priority 1 = most urgent, 10 = lowest.
4. **Take** (claim) the case — `POST /api/staff/cases/{id}/take` (moves to `under_review`)
5. **Review** evidence snapshot, signals, related cases via `GET /api/staff/cases/{id}`
6. **Decide** — `POST /api/staff/cases/{id}/decide` (see decision matrix below)
7. **Or escalate** — `POST /api/staff/cases/{id}/escalate` for law enforcement / eSafety Commissioner

If you need to pass a case back to the queue: `POST /api/staff/cases/{id}/release`.

## Decision matrix

| `decision_type` | When to use | Automated effects |
|-----------------|-------------|-------------------|
| `dismiss` | False alarm / out of jurisdiction / not actionable | None — case closed |
| `warn` | Borderline / first offence | Notify reported user |
| `hide_content` | Specific content needs removal (not the whole site) | Cache purge + notify reported user |
| `hide_site` | Site-level violation — content stays but site is hidden | Suspend site; cache purge; notify |
| `suspend_user` | Reversible account suspension | Suspend user + site; cache purge; notify |
| `ban_user` | Permanent account disable | Suspend user + site; cache purge; notify; cannot self-restore |
| `override_csam_auto_action` | Confirmed false positive on CSAM auto-action | **Reverses** the auto-suspend/quarantine; requires two-staff sign-off |
| `escalate_law_enforcement` | Criminal content — tip to law enforcement | Records decision + notifies on-call; no public effects |
| `escalate_esafety` | AU-specific reporting to eSafety Commissioner | Records decision + notifies on-call; no public effects |

### Required fields for `decide`

```json
{
  "decision_type": "hide_site",
  "reason": "Repeated trademark infringement after two warnings (min 10 chars)"
}
```

For `override_csam_auto_action`, also include:

```json
{
  "second_staff_approval_id": "<uuid of a different staff member>"
}
```

Both the deciding and approving staff must have active AAL2 sessions.

## CSAM auto-actions

CSAM matches arrive with `case_type='csam_match'`, `severity=5`, `status='auto_actioned'`.

The system has **already**:
- Suspended the user's account
- Hidden the site
- Quarantined the media (processing_state='quarantined')
- Filed (or queued) the NCMEC CyberTipline report

**Staff role:** review the auto-action. Normally you'll `dismiss` (no further action needed) or `warn`. In rare false-positive cases you may `override_csam_auto_action` with a written justification and a second staff co-signer.

## Escalation

For `escalate_law_enforcement` or `escalate_esafety`:

```json
{
  "escalation_target": "law_enforcement",
  "notes": "Evidence preserved. Hash matched NCMEC DB. Uploading tip to ACORN. (min 20 chars)"
}
```

Notes must be at least 20 characters because external escalations require documented justification.

## Lifecycle commands

```bash
# Show a case as JSON (include signals, evidence, decisions)
php artisan moderation:show-case <case_id>

# Manually reverse a decision (pre-appeals stop-gap)
# Creates a new 'dismiss' decision with supersedes_decision_id set
php artisan moderation:reverse-decision <decision_id> --reason="User appealed; reviewed and upheld"

# GDPR erasure — zero out reporter PII on signals for a case
php artisan moderation:redact-reporter-pii <case_id> --reason="GDPR erasure request #1234"

# SLA scan — runs every 15 min via scheduler; run manually to preview at-risk cases
php artisan moderation:sla-scan
```

## SLA targets (default config)

Configured in `config/partna.php` under `moderation.sla`. Breach warning fires 120 minutes before due.

| Severity | SLA target |
|----------|-----------|
| 5 (CSAM) | 1 hour |
| 4 | 4 hours |
| 3 | 24 hours |
| 2 | 72 hours (3 days) |
| 1 | 168 hours (7 days) |

## Queue lane

Time-sensitive enforcement and paging jobs (`QuarantineMediaJob`, `SuspendUserJob`, `SuspendSiteJob`, `PurgeModerationCacheJob`, `NotifyOnCallStaffJob`, `FileCyberTipReportJob`) run on the `moderation_high` Horizon queue, prioritised above `default` but below any real-time payment jobs. Lower-urgency jobs (reporter/reported-user notifications, staff case-update notifications, clean-media promotion) stay on the `default` queue.

Monitor via the Horizon dashboard or:

```bash
php artisan horizon:list
cloud env:logs partna development --minutes 30 | grep moderation
```
