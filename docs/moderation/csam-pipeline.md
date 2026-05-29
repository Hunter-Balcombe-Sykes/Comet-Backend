# CSAM scanning pipeline — operator runbook

## What this pipeline does

Every user-uploaded image:
1. Uploads to **`partna-media-quarantine`** R2 bucket (private, no public access)
2. **Cloudflare CSAM Scanning Tool** scans against NCMEC PhotoDNA database
3. If clean → `PromoteCleanMediaJob` (every 60s) copies to production bucket, marks `processing_state='ready'`
4. If match → Cloudflare posts to `/v1/internal/cloudflare-csam-webhook`:
   - Case + signal + evidence rows created
   - User auto-suspended, site auto-hidden
   - Edge cache purged
   - On-call staff notified
   - NCMEC CyberTipline report filed (outbox pattern with retry)

## Feature flags

| Flag | Effect |
|------|--------|
| `PARTNA_CSAM_SCAN_ENABLED=false` (default) | Skips quarantine routing; uploads go straight to production. **Must flip to `true` before production launch.** |

## NCMEC ESP credentials

| Env var | Purpose |
|---------|---------|
| `NCMEC_API_KEY` | Bearer token for CyberTipline API |
| `NCMEC_ESP_ID` | Registered ESP identifier |
| `NCMEC_CYBERTIP_ENDPOINT` | API URL (default in config) |

Designated legal contact: **Josh Hunter** (founder).

## Common ops tasks

```bash
# Check pending NCMEC submissions
php artisan tinker --execute='echo App\Models\Moderation\NcmecSubmission::where("status","pending")->count();'

# Check submissions stuck at manual_fallback_required (needs human action)
php artisan tinker --execute='dump(App\Models\Moderation\NcmecSubmission::where("status","manual_fallback_required")->get(["id","csam_quarantine_id","last_error"])->toArray());'

# Force a retry sweep
php artisan moderation:retry-ncmec-submissions

# Force-run the quarantine bucket audit
php artisan moderation:audit-quarantine-bucket

# Show a CSAM case in detail
php artisan moderation:show-case <case_id>

# Force-run the 90-day expiry job (normally daily at 03:00)
php artisan moderation:expire-csam-quarantine
```

## What to do when …

### Cloudflare CSAM Scanning Tool is down

Uploads continue (Cloudflare's API stays up). Media stays in `processing_state='scanning'` until the scan-status API returns. If extended outage (>2h), escalate to Cloudflare support.

### NCMEC API is down

`FileCyberTipReportJob` retries via `moderation:retry-ncmec-submissions` (every 5 min). After 5 attempts → `manual_fallback_required` + Log::critical alert. Action: submit the report manually via NCMEC's web portal at https://report.cybertip.org, then update the row:

```bash
php artisan tinker --execute='
  $sub = App\Models\Moderation\NcmecSubmission::find("<id>");
  $sub->update(["status" => "submitted", "ncmec_tip_id" => "MANUAL-<their-tip-id>", "submitted_at" => now()]);
'
```

### A user appeals a CSAM auto-action claiming false positive

Staff path (requires AAL2):
1. Pull the case: `php artisan moderation:show-case <case_id>`
2. Coordinate with a second staff member (different from you)
3. Submit override via `POST /v1/staff/cases/<id>/decide`:
   ```json
   {
     "decision_type": "override_csam_auto_action",
     "reason": "Detailed justification: <why this is false positive>",
     "second_staff_approval_id": "<other-staff-id>"
   }
   ```

### Law enforcement requests preserved evidence

Quarantine binary is retained for 90 days from `csam_quarantine.created_at`. After that, the binary is deleted but metadata (hash, timestamps) stays indefinitely. Subpoenas → consult counsel; the row + audit log + NCMEC tip ID is the full record.

### The quarantine bucket suddenly has public access

`moderation:audit-quarantine-bucket` (daily) logs a critical error + command fails. Immediately:
1. Check Cloudflare R2 dashboard for bucket access policy
2. Revoke any public-read rule
3. Audit R2 access logs for the drift window

## Production launch prerequisites

DO NOT flip `PARTNA_CSAM_SCAN_ENABLED=true` in production until:

- [ ] R2 bucket `partna-media-quarantine` created with private access policy
- [ ] Cloudflare CSAM Scanning Tool enabled on quarantine bucket
- [ ] Webhook configured: `https://api.partna.au/v1/internal/cloudflare-csam-webhook`
- [ ] `PARTNA_CSAM_CLOUDFLARE_WEBHOOK_SECRET` stored in Laravel Cloud env
- [ ] NCMEC ESP registration complete; Josh Hunter as primary contact
- [ ] `NCMEC_API_KEY` + `NCMEC_ESP_ID` stored in prod env
- [ ] `moderation:audit-quarantine-bucket` returns OK against prod bucket

## Useful queries

```sql
-- Open CSAM cases needing staff review
SELECT id, severity, status, signal_count, created_at
FROM moderation.cases
WHERE case_type = 'csam_match' AND status IN ('open', 'auto_actioned')
ORDER BY created_at DESC;

-- NCMEC submissions pending or failing
SELECT id, status, attempts, last_error, created_at
FROM moderation.ncmec_submissions
WHERE status IN ('pending', 'failed', 'manual_fallback_required')
ORDER BY status, created_at;

-- Quarantine rows approaching 90-day expiry
SELECT id, preservation_expires_at, r2_quarantine_key
FROM moderation.csam_quarantine
WHERE r2_binary_deleted = FALSE
  AND preservation_expires_at BETWEEN now() AND now() + interval '7 days';
```
