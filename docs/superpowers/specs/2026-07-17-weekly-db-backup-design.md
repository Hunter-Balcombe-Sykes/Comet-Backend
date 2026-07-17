# Weekly off-platform database backup — design

**Date:** 2026-07-17
**Status:** Approved, pending implementation plan
**Related:** launch-readiness checklist TECH-3 (P0), `docs/runbooks/drills/04-backup-restore.md`

## Problem

The live database — dev Supabase `glncumufgaqcmqhzwrxm`, which per CLAUDE.md serves
**both** API domains and all production sitepages — has **zero backups**. The Supabase
org (`Partna`, id `ligsuetayyrxzojoxxbt`) is on the **free plan**, which includes no
automatic backups and no PITR. There is no DIY dump job either. The only backup-related
artifact in the repo is the drill runbook (`04-backup-restore.md`), which is a *test* of
backups, not a backup — and it cannot meaningfully run because no restore point exists.

Two remediations were agreed:

1. **This spec:** a weekly off-platform `pg_dump` → encrypt → R2, closing the
   account-level-disaster gap now (independent of Supabase *and* Laravel Cloud).
2. **Follow-up (separate, ~2026-07-24):** upgrade the Supabase org to Pro for automatic
   daily managed backups + the dashboard restore path the drill prefers. Tracked by a
   one-time scheduled reminder + a dated checklist note. Not part of this build.

Managed Pro backups and off-platform dumps are **complementary, not redundant**: Pro
protects against bad migrations / corruption / accidental deletes (failure *inside* the
DB); the off-platform dump additionally protects against Supabase account compromise,
billing lapse, or org deletion (failure *of the platform account*). The dump is also
provider-portable — it restores onto any Postgres 17 server if we ever leave Supabase.

## Non-goals

- Backing up R2 object storage (media). A DB dump never includes object storage; media
  DR is a separate, still-open question flagged in drill 04 Phase 1. Out of scope here.
- PITR / continuous replication. Overkill pre-pilot; the Pro upgrade covers the realistic
  failure modes.
- Backing up the paused prod Supabase project (`edplucmvkcnokyygxqsb`) — it is
  INACTIVE and holds no live data.

## Architecture

A new **private repo `Hunter-Balcombe-Sykes/partna-db-backup`** holding one GitHub Actions
workflow + restore documentation. The backend repo is not modified except for a
cross-reference pointer in drill 04.

```
schedule (weekly) / workflow_dispatch (manual)
  → pg_dump  (PG17 client, via Supavisor pooler, custom format)
  → verify   (pg_restore --list + minimum-size check)
  → encrypt  (openssl AES-256-CBC, pbkdf2)
  → upload   (aws s3 cp → Cloudflare R2, own account)
  → [R2 lifecycle rule auto-prunes objects > 90 days]
  failure at any step → job fails → GitHub emails the owner
```

### Why a separate repo (not the backend repo)

- GitHub cron only fires from the workflow on the **default branch**, which for
  `partna-backend` is the frozen `production` branch (~530 commits behind `development`,
  tied to a stopped Laravel Cloud env). Committing a scheduled workflow there means
  touching the frozen branch and risking its push-to-deploy hook, plus branch-drift risk
  at the eventual re-baseline.
- The job needs the highest-privilege secret in the system — a connection string that can
  read every user's data. Anyone with write access to a repo can modify a workflow to
  exfiltrate that repo's secrets; isolating it in a repo nobody routinely touches is a
  smaller blast radius than the busy shared backend repo.
- Independent lifecycle: cadence/retention/restore-docs don't entangle with backend PRs,
  CI, or the audit pipeline scope maps.
- Trivially reversible: if production unfreezes and in-repo is preferred later, migration
  is copy one YAML + re-create the secrets + delete the tiny repo (~10 min). No lock-in.

### Why Cloudflare R2 in our own account (not the Laravel Cloud bucket)

The existing `sidest-media` bucket is R2 that **Laravel Cloud provisions and manages**.
The backup must not share a failure domain with either the thing it backs up (Supabase)
or the thing that writes it (the app on Laravel Cloud). Therefore:

- **Own Cloudflare account**, the same one already running the sitepage Worker +
  `SUBDOMAIN_KV` — depends on neither Supabase nor Laravel Cloud.
- **Brand-new dedicated bucket `partna-db-backups`** — never co-mingled with `sidest-media`.
  A DB dump is every user's data in one file; it must not sit in the app's constantly-written
  media bucket sharing one credential/misconfig surface.
- **Scoped API token** — object read/write on the one backup bucket only; cannot touch
  `sidest-media` or any other bucket.

## Workflow details

**Triggers:**
- `schedule`: weekly, Sunday 15:00 UTC (≈ Monday 01:00 AEST — quiet window).
- `workflow_dispatch`: manual on-demand run (e.g. immediately before a risky migration).

**Runner:** `ubuntu-latest`. Install `postgresql-client-17` from the PGDG apt repo — the
client major version must match the server (Postgres 17.6). Install `awscli` for the R2
upload; `openssl` is preinstalled.

**Connection — pooler, not direct.** Connect via the Supavisor **pooler in session mode**
(`postgres.<ref>:<pw>@aws-0-<region>.pooler.supabase.com:5432`), NOT the direct
`db.<ref>.supabase.co` host. The direct host is IPv6-only and GitHub-hosted runners have
no IPv6 — a direct connection hangs. Username carries the tenant prefix
`postgres.glncumufgaqcmqhzwrxm`.

**Dump command:**
```bash
pg_dump "$SUPABASE_DB_URL" \
  --format=custom --no-owner --no-privileges --compress=9 \
  --schema=core --schema=site --schema=notifications \
  --schema=analytics --schema=audit --schema=public \
  --schema=auth --schema=supabase_migrations \
  -f partna-dev.dump
```
- Custom format (`-Fc`): compressed, supports selective `pg_restore -t`, parallel restore.
- Schema scope includes **`auth`** deliberately — `auth.users` is where accounts live; a
  dump without it restores sites nobody can log into. Includes `supabase_migrations` so
  restored migration state is comparable to source (drill 04 Phase 3). Supabase-internal
  noise schemas (`realtime`, `vault`, `storage`, `extensions`, `pgbouncer`, etc.) are
  excluded by *not* listing them. The exact include/exclude set is confirmed empirically
  during implementation (dump once, inspect `pg_restore --list`, adjust) — the list above
  is the starting point, not frozen.

**Verify before upload (fail-closed):**
```bash
pg_restore --list partna-dev.dump > /dev/null   # dump is structurally readable
test "$(stat -c%s partna-dev.dump)" -gt "$MIN_BYTES"   # not truncated/empty
```
A dump that fails either check fails the job — a bad dump must never overwrite good
history or masquerade as success. `MIN_BYTES` is a conservative floor set from the first
real dump's size.

**Encrypt:**
```bash
openssl enc -aes-256-cbc -pbkdf2 -salt \
  -in partna-dev.dump -out partna-dev.dump.enc -pass env:BACKUP_PASSPHRASE
```
Symmetric AES-256 chosen deliberately: the encryption's job is to protect the **R2 copy**
(bucket leak/misconfig). It does *not* need to defend against GitHub-secrets compromise —
an attacker with the secrets already holds the DB connection string and can dump the live
DB directly, so asymmetric keys would buy nothing there. Symmetric gives a one-command,
dependency-free restore. The passphrase lives in a GitHub secret **and** the password
manager (losing it = losing the backups).

**Upload:**
```bash
aws s3 cp partna-dev.dump.enc \
  "s3://partna-db-backups/weekly/partna-dev-$(date +%F).dump.enc" \
  --endpoint-url "$R2_ENDPOINT"
```
(Date is stamped by the runner's `date` at execution time — not a hardcoded value.)

**Retention:** an **R2 lifecycle rule deletes objects older than 90 days** → a rolling ~13
weekly restore points, no pruning code in the workflow.

**Failure signal:** a failed scheduled run triggers GitHub's default failure email to the
repo owner. (A future enhancement could post to Slack/Nightwatch; not in scope now.)

## Secrets (5, all scoped to `partna-db-backup` repo)

| Secret | Purpose |
|--------|---------|
| `SUPABASE_DB_URL` | Full pooler connection string (session mode, port 5432). |
| `BACKUP_PASSPHRASE` | AES-256 passphrase. Mirrored in the password manager. |
| `R2_ACCESS_KEY_ID` | R2 token id, scoped to `partna-db-backups` only. |
| `R2_SECRET_ACCESS_KEY` | R2 token secret. |
| `R2_ENDPOINT` | `https://<account_id>.r2.cloudflarestorage.com`. |

## Manual steps (dashboards that cannot be automated from here)

Owner (Josh) performs, or hands values over for `gh secret set`:

1. **Cloudflare:** create bucket `partna-db-backups`; create an R2 API token scoped to that
   bucket (object read/write); add a lifecycle rule deleting objects older than 90 days.
2. **Supabase:** retrieve (or reset) the database password to assemble the pooler
   connection string.
3. **Approve** repo creation. Claude creates the repo, workflow, and docs, and sets the 5
   secrets via `gh` — or Josh pastes them into GitHub directly if he prefers they never
   transit the session.

## Verification

1. First run via `workflow_dispatch` (don't wait a week for the schedule).
2. Prove the round trip end-to-end: download the R2 object → `openssl` decrypt →
   `pg_restore --list` locally. A backup is not "done" until a restore has been demonstrated.
3. This same decrypted dump feeds **drill 04's manual fallback path** — the first measured
   restore rehearsal (RTO/RPO recorded per that runbook).

## Cost

- R2: free tier covers ~10 GB; the DB is far smaller. ~$0.
- GitHub Actions: ~5 min/week on a private repo. Negligible against the free minutes.

## Follow-up: Supabase Pro upgrade (tracked, not built here)

- One-time scheduled reminder for **2026-07-24**: "Upgrade Supabase org `Partna` to Pro —
  TECH-3 backup coverage (daily managed backups + dashboard restore path)."
- Dated note added to the launch-readiness checklist near TECH-3.
- Billing changes require the owner in the Supabase dashboard; the reminder cannot perform
  the upgrade itself.
