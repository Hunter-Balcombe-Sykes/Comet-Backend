# Weekly Off-Platform DB Backup — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up a weekly GitHub Actions cron that dumps the live dev Supabase database, verifies + encrypts the dump, and stores it in a dedicated Cloudflare R2 bucket in our own account — closing the account-level-disaster backup gap (TECH-3).

**Architecture:** A standalone private repo `Hunter-Balcombe-Sykes/partna-db-backup` holds one workflow + restore docs. The workflow runs `pg_dump` (Postgres 17 client) against the Supabase Supavisor pooler, fails closed on a bad/short dump, encrypts with AES-256, and `aws s3 cp`s the ciphertext to R2. Retention is an R2 lifecycle rule (90-day auto-prune), not workflow code. The backend repo is untouched except a documentation cross-reference. A separate one-time reminder tracks the Supabase Pro upgrade.

**Tech Stack:** GitHub Actions, `pg_dump`/`pg_restore` (PostgreSQL 17 client via PGDG apt), `openssl enc` (AES-256-CBC), `aws` CLI (S3 API → Cloudflare R2).

## Global Constraints

- **Source DB (read-only):** dev Supabase `glncumufgaqcmqhzwrxm`, region `ap-southeast-2`, Postgres **17.6**. Never restore *into* it.
- **Connection MUST use the Supavisor pooler** (session mode, port 5432), NOT `db.<ref>.supabase.co`. The direct host is IPv6-only; GitHub runners have no IPv6 → a direct connection hangs.
- **PostgreSQL client major version MUST equal the server major (17).** A 16 client refuses to dump a 17 server.
- **Storage:** Cloudflare R2 bucket `partna-db-backups` in *our own Cloudflare account* (the one running the sitepage Worker) — never the Laravel Cloud `sidest-media` bucket.
- **R2 API token scope:** object read/write on `partna-db-backups` only.
- **Schema scope (starting point, reconciled in Task 2):** `core, site, notifications, analytics, audit, public, auth, supabase_migrations`. `auth` is mandatory — it holds `auth.users`.
- **Encryption:** symmetric AES-256-CBC with `-pbkdf2`. Passphrase stored in the GitHub secret **and** the password manager.
- **Retention:** R2 lifecycle rule deletes objects older than **90 days**.
- **Repo:** private. Secrets: `SUPABASE_DB_URL`, `BACKUP_PASSPHRASE`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_ENDPOINT`.

**Note on task style:** this is infra/ops work, not application TDD. There is no unit-test suite; the "test-first" analog is **Task 2 — a full local dry run that proves every credential and the exact dump command before any CI is written.** Each task ends with a concrete, observable verification.

**OWNER steps:** steps marked **[OWNER]** require Josh in a dashboard (Cloudflare / Supabase / GitHub billing) and cannot be done from a code session. Everything else can be done by the implementer (with the secret values Josh provides).

---

### Task 1: Provision R2 bucket, scoped token, and gather the Supabase connection string

**Files:** none (dashboard + local scratch note only).

**Interfaces:**
- Produces: the 5 secret values (`SUPABASE_DB_URL`, `BACKUP_PASSPHRASE`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_ENDPOINT`) — consumed by Tasks 2, 4.

- [ ] **Step 1 [OWNER]: Create the R2 bucket**

In the Cloudflare dashboard (our own account) → R2 → Create bucket. Name: `partna-db-backups`. Location: Automatic (or `APAC` hint). Leave it private (no public access).

- [ ] **Step 2 [OWNER]: Add the 90-day lifecycle rule**

Bucket → Settings → Object lifecycle rules → Add rule: "Delete objects" **90 days** after creation, applied to prefix `weekly/` (or the whole bucket). Save.

- [ ] **Step 3 [OWNER]: Create a scoped R2 API token**

R2 → Manage R2 API Tokens → Create API Token. Permissions: **Object Read & Write**. Scope: **Apply to specific buckets → `partna-db-backups`**. Create, then record **Access Key ID**, **Secret Access Key**, and the **S3 endpoint** (`https://<account_id>.r2.cloudflarestorage.com`). The secret is shown once.

- [ ] **Step 4 [OWNER]: Get the pooler connection string**

Supabase dashboard → project `glncumufgaqcmqhzwrxm` → Connect → **Session pooler** tab. Copy the connection string. It looks like:
`postgresql://postgres.glncumufgaqcmqhzwrxm:[YOUR-PASSWORD]@aws-0-ap-southeast-2.pooler.supabase.com:5432/postgres`
If the password is unknown, Settings → Database → Reset database password (note this rotates it — update anything else using it). Substitute the real password → this is `SUPABASE_DB_URL`. **Do NOT use the "Direct connection" tab.**

- [ ] **Step 5: Generate the backup passphrase**

Run:
```bash
openssl rand -base64 48
```
This is `BACKUP_PASSPHRASE`. Save it to the password manager immediately — losing it means every backup is unrecoverable.

- [ ] **Step 6: Verify all 5 values are in hand**

Confirm you now have: `SUPABASE_DB_URL` (pooler, real password), `BACKUP_PASSPHRASE`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_ENDPOINT`. Keep them in a scratch location that is NOT committed. Expected: 5 non-empty values.

---

### Task 2: Local end-to-end dry run (de-risk every credential + finalize the dump command)

Proves the whole pipeline by hand before any CI exists. Requires the PostgreSQL 17 client, `openssl`, and `aws` CLI locally.

**Files:** none (scratch dir, deleted at the end).

**Interfaces:**
- Consumes: the 5 values from Task 1.
- Produces: the confirmed `--schema` flag set + a measured dump size → sets `MIN_BYTES` for Task 3.

- [ ] **Step 1: Confirm a matching client is available**

Run:
```bash
pg_dump --version   # or: /usr/lib/postgresql/17/bin/pg_dump --version  (Linux)
```
Expected: `pg_dump (PostgreSQL) 17.x`. If it prints 16.x or lower, install 17 (macOS: `brew install postgresql@17`; then use `$(brew --prefix postgresql@17)/bin/pg_dump`). A version-mismatch dump will fail with "server version mismatch".

- [ ] **Step 2: Enumerate every schema on the source (decide include vs exclude deliberately)**

Run:
```bash
psql "$SUPABASE_DB_URL" -c "select nspname from pg_namespace where nspname not like 'pg_%' and nspname <> 'information_schema' order by 1;"
```
Expected: a list including `core, site, notifications, analytics, audit, public, auth, supabase_migrations` plus Supabase-managed ones (`storage, realtime, vault, extensions, graphql, graphql_public, pgbouncer, _analytics, supabase_functions`, …). Confirm the include list in Global Constraints covers **every app-owned schema present**. If a new app schema exists that isn't in the list, add it. Note in the run scratch *why* each excluded schema is safe to omit (Supabase-managed / recreated by the platform / no app data).

- [ ] **Step 3: Run the dump locally**

Run (use the full path to the 17 binary if `pg_dump` isn't 17):
```bash
pg_dump "$SUPABASE_DB_URL" \
  --format=custom --no-owner --no-privileges --compress=9 \
  --schema=core --schema=site --schema=notifications \
  --schema=analytics --schema=audit --schema=public \
  --schema=auth --schema=supabase_migrations \
  -f partna-dev-test.dump
```
Expected: exits 0, no errors, `partna-dev-test.dump` created.

- [ ] **Step 4: Verify the dump is structurally readable and record its size**

Run:
```bash
pg_restore --list partna-dev-test.dump | head -30
stat -f%z partna-dev-test.dump   # macOS;  Linux: stat -c%s partna-dev-test.dump
```
Expected: a table-of-contents listing (schemas/tables you expect appear); a byte size. **Record the size** — set `MIN_BYTES` in Task 3 to roughly half of it (a floor that catches truncation without false alarms).

- [ ] **Step 5: Encrypt then decrypt round-trip locally**

Run:
```bash
openssl enc -aes-256-cbc -pbkdf2 -salt -in partna-dev-test.dump -out partna-dev-test.dump.enc -pass pass:"$BACKUP_PASSPHRASE"
openssl enc -d -aes-256-cbc -pbkdf2 -in partna-dev-test.dump.enc -out partna-dev-test.decrypted -pass pass:"$BACKUP_PASSPHRASE"
pg_restore --list partna-dev-test.decrypted | head -5
```
Expected: decrypt succeeds and `pg_restore --list` on the decrypted file prints the same TOC — proves the encryption is reversible with this passphrase.

- [ ] **Step 6: Test the R2 upload with the scoped token**

Run:
```bash
export AWS_ACCESS_KEY_ID="<R2_ACCESS_KEY_ID>"
export AWS_SECRET_ACCESS_KEY="<R2_SECRET_ACCESS_KEY>"
export AWS_DEFAULT_REGION=auto
export AWS_REQUEST_CHECKSUM_CALCULATION=when_required   # R2 rejects aws-cli-v2 default checksum headers
export AWS_RESPONSE_CHECKSUM_VALIDATION=when_required
aws s3 cp partna-dev-test.dump.enc "s3://partna-db-backups/test/partna-dev-test.dump.enc" --endpoint-url "<R2_ENDPOINT>"
aws s3 ls "s3://partna-db-backups/test/" --endpoint-url "<R2_ENDPOINT>"
```
Expected: upload succeeds; `ls` shows the object. If upload 400s with a checksum/`x-amz-content-sha256` error, the two `AWS_*_CHECKSUM_*` env vars above are the fix (already included).

- [ ] **Step 7: Clean up the dry run**

Run:
```bash
aws s3 rm "s3://partna-db-backups/test/partna-dev-test.dump.enc" --endpoint-url "<R2_ENDPOINT>"
rm -P partna-dev-test.dump partna-dev-test.dump.enc partna-dev-test.decrypted
```
Expected: local dump files (containing real user data) securely removed; test object deleted from R2. The `weekly/` prefix stays empty until the real workflow runs.

---

### Task 3: Create the backup repo with the workflow and restore docs

**Files:**
- Create: `.github/workflows/weekly-db-backup.yml`
- Create: `README.md`
- Create: `RESTORE.md`

**Interfaces:**
- Consumes: `MIN_BYTES` from Task 2 Step 4; the confirmed `--schema` set.
- Produces: repo `Hunter-Balcombe-Sykes/partna-db-backup` on default branch `main`.

- [ ] **Step 1: Create the private repo and clone it**

Run:
```bash
gh repo create Hunter-Balcombe-Sykes/partna-db-backup --private \
  --description "Weekly off-platform pg_dump of the Partna dev Supabase DB → Cloudflare R2" \
  --clone
cd partna-db-backup
```
Expected: repo created, empty local clone. (Confirm `Hunter-Balcombe-Sykes` is the right owner org.)

- [ ] **Step 2: Write the workflow file**

Create `.github/workflows/weekly-db-backup.yml` with **`<MIN_BYTES>` replaced by the value from Task 2 Step 4**:

```yaml
name: weekly-db-backup

on:
  schedule:
    - cron: '0 15 * * 0'      # Sunday 15:00 UTC ≈ Monday 01:00 AEST (quiet window)
  workflow_dispatch: {}        # manual on-demand run (e.g. before a risky migration)

concurrency:
  group: db-backup
  cancel-in-progress: false    # never abort an in-flight backup

jobs:
  backup:
    runs-on: ubuntu-latest
    timeout-minutes: 30
    env:
      SUPABASE_DB_URL: ${{ secrets.SUPABASE_DB_URL }}
      BACKUP_PASSPHRASE: ${{ secrets.BACKUP_PASSPHRASE }}
      AWS_ACCESS_KEY_ID: ${{ secrets.R2_ACCESS_KEY_ID }}
      AWS_SECRET_ACCESS_KEY: ${{ secrets.R2_SECRET_ACCESS_KEY }}
      R2_ENDPOINT: ${{ secrets.R2_ENDPOINT }}
      AWS_DEFAULT_REGION: auto
      AWS_REQUEST_CHECKSUM_CALCULATION: when_required
      AWS_RESPONSE_CHECKSUM_VALIDATION: when_required
      MIN_BYTES: '<MIN_BYTES>'
      PGDUMP: /usr/lib/postgresql/17/bin/pg_dump
      PGRESTORE: /usr/lib/postgresql/17/bin/pg_restore
    steps:
      - name: Install PostgreSQL 17 client
        run: |
          sudo sh -c 'echo "deb https://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" > /etc/apt/sources.list.d/pgdg.list'
          curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc | sudo gpg --dearmor -o /etc/apt/trusted.gpg.d/pgdg.gpg
          sudo apt-get update
          sudo apt-get install -y postgresql-client-17

      - name: Dump database
        run: |
          STAMP="$(date +%F)"
          echo "STAMP=$STAMP" >> "$GITHUB_ENV"
          "$PGDUMP" "$SUPABASE_DB_URL" \
            --format=custom --no-owner --no-privileges --compress=9 \
            --schema=core --schema=site --schema=notifications \
            --schema=analytics --schema=audit --schema=public \
            --schema=auth --schema=supabase_migrations \
            -f "partna-dev-$STAMP.dump"

      - name: Verify dump integrity (fail closed)
        run: |
          "$PGRESTORE" --list "partna-dev-$STAMP.dump" > /dev/null
          SIZE="$(stat -c%s "partna-dev-$STAMP.dump")"
          echo "Dump size: $SIZE bytes (floor $MIN_BYTES)"
          if [ "$SIZE" -lt "$MIN_BYTES" ]; then
            echo "::error::Dump $SIZE bytes < floor $MIN_BYTES — refusing to upload a suspect backup."
            exit 1
          fi

      - name: Encrypt (AES-256)
        run: |
          openssl enc -aes-256-cbc -pbkdf2 -salt \
            -in "partna-dev-$STAMP.dump" \
            -out "partna-dev-$STAMP.dump.enc" \
            -pass env:BACKUP_PASSPHRASE

      - name: Upload to R2
        run: |
          aws s3 cp "partna-dev-$STAMP.dump.enc" \
            "s3://partna-db-backups/weekly/partna-dev-$STAMP.dump.enc" \
            --endpoint-url "$R2_ENDPOINT"

      - name: Confirm object landed
        run: |
          aws s3 ls "s3://partna-db-backups/weekly/partna-dev-$STAMP.dump.enc" \
            --endpoint-url "$R2_ENDPOINT"
```

- [ ] **Step 3: Write `README.md`**

Create `README.md`:

```markdown
# partna-db-backup

Weekly off-platform backup of the Partna **dev** Supabase database
(`glncumufgaqcmqhzwrxm`, Postgres 17) — the live DB for both API domains.

## What it does
`.github/workflows/weekly-db-backup.yml` runs every Sunday 15:00 UTC (and on
manual dispatch): `pg_dump` (custom format, via the Supavisor pooler) →
verify (`pg_restore --list` + size floor) → AES-256 encrypt → upload to the
Cloudflare R2 bucket `partna-db-backups` (our own account) under `weekly/`.
An R2 lifecycle rule prunes objects older than 90 days (~13 restore points).

## Why a separate repo
GitHub cron only fires from the default branch, which in the backend repo is a
frozen branch. Isolating here keeps the full-DB connection secret out of the
busy shared repo's blast radius. See the backend spec:
`docs/superpowers/specs/2026-07-17-weekly-db-backup-design.md`.

## Secrets (repo settings → Secrets and variables → Actions)
`SUPABASE_DB_URL` · `BACKUP_PASSPHRASE` · `R2_ACCESS_KEY_ID` ·
`R2_SECRET_ACCESS_KEY` · `R2_ENDPOINT`. The passphrase is also in the password
manager — without it the backups cannot be decrypted.

## Restore
See [RESTORE.md](RESTORE.md). This complements (does not replace) Supabase Pro
managed backups; it exists to survive account-level loss of the Supabase org.
```

- [ ] **Step 4: Write `RESTORE.md`**

Create `RESTORE.md`:

```markdown
# Restoring from a backup

Recovery is a manual, deliberate operation. Never restore into the live source
DB. Restore into a fresh scratch Supabase project (or local Postgres 17).

## 1. Download the object
```bash
export AWS_ACCESS_KEY_ID=<R2_ACCESS_KEY_ID>
export AWS_SECRET_ACCESS_KEY=<R2_SECRET_ACCESS_KEY>
export AWS_DEFAULT_REGION=auto
export AWS_REQUEST_CHECKSUM_CALCULATION=when_required
aws s3 ls s3://partna-db-backups/weekly/ --endpoint-url <R2_ENDPOINT>
aws s3 cp s3://partna-db-backups/weekly/partna-dev-<DATE>.dump.enc . --endpoint-url <R2_ENDPOINT>
```

## 2. Decrypt (passphrase from the password manager)
```bash
openssl enc -d -aes-256-cbc -pbkdf2 -in partna-dev-<DATE>.dump.enc -out partna-dev-<DATE>.dump -pass pass:'<BACKUP_PASSPHRASE>'
pg_restore --list partna-dev-<DATE>.dump | head    # sanity-check the TOC
```

## 3. Restore into a scratch target
```bash
pg_restore --dbname "<SCRATCH_DB_URL>" --no-owner --no-privileges partna-dev-<DATE>.dump
```

## 4. Close the role/connection gap
A logical dump has no cluster roles. In the scratch SQL editor:
```sql
ALTER ROLE app_backend WITH LOGIN PASSWORD '<from secret>';
```
Then verify: `psql "postgresql://app_backend.<ref>:<pw>@<pooler-host>:5432/postgres" -c 'select 1'`.

See the full rehearsal + integrity checks in the backend repo:
`docs/runbooks/drills/04-backup-restore.md`.

## 5. Clean up
`rm -P partna-dev-<DATE>.dump*` — the plaintext dump is every user's data.
```

- [ ] **Step 5: Commit and push**

Run:
```bash
git add .github/workflows/weekly-db-backup.yml README.md RESTORE.md
git commit -m "feat: weekly pg_dump backup workflow + restore docs"
git push -u origin main
```
Expected: files pushed to `main`; GitHub now shows the workflow under Actions (no runs yet).

---

### Task 4: Set the repo secrets

**Files:** none (GitHub secrets).

**Interfaces:**
- Consumes: the 5 values from Task 1.

- [ ] **Step 1: Set all five secrets**

From inside the `partna-db-backup` clone, run (paste real values — or Josh sets them in the GitHub UI if he prefers they never transit a shell):
```bash
gh secret set SUPABASE_DB_URL
gh secret set BACKUP_PASSPHRASE
gh secret set R2_ACCESS_KEY_ID
gh secret set R2_SECRET_ACCESS_KEY
gh secret set R2_ENDPOINT
```
Each prompts for the value (not echoed). Expected: `✓ Set Actions secret ...` five times.

- [ ] **Step 2: Confirm the secret names exist**

Run:
```bash
gh secret list
```
Expected: exactly the five names listed. (Values are never shown — that's fine.)

---

### Task 5: First real run + confirm the object in R2

**Files:** none.

- [ ] **Step 1: Trigger the workflow manually**

Run:
```bash
gh workflow run weekly-db-backup.yml
sleep 5
gh run watch "$(gh run list --workflow weekly-db-backup.yml --limit 1 --json databaseId --jq '.[0].databaseId')"
```
Expected: the run streams; every step goes green; the job succeeds. If a step fails, read its log (`gh run view --log-failed`) — the most common first-run failures are the pooler string (must be Session pooler) and the R2 checksum env (already set).

- [ ] **Step 2: Confirm the first backup exists in R2**

Run:
```bash
aws s3 ls s3://partna-db-backups/weekly/ --endpoint-url "$R2_ENDPOINT"
```
Expected: one object `partna-dev-<today>.dump.enc` with a plausible size (≈ the Task 2 dump size). This is the first real off-platform restore point.

---

### Task 6: Prove recoverability (round-trip restore) + log it against drill 04

This is the step that turns "a backup exists" into "a backup we've restored." Reuses the drill-04 runbook.

**Files:**
- Create (in backend repo): `docs/runbooks/drills/logs/<YYYY-MM-DD>-backup-restore.md` (copied from `logs/TEMPLATE.md`).

- [ ] **Step 1: Download + decrypt the real object**

Run:
```bash
aws s3 cp s3://partna-db-backups/weekly/partna-dev-<DATE>.dump.enc . --endpoint-url "$R2_ENDPOINT"
openssl enc -d -aes-256-cbc -pbkdf2 -in partna-dev-<DATE>.dump.enc -out partna-dev-<DATE>.dump -pass env:BACKUP_PASSPHRASE
pg_restore --list partna-dev-<DATE>.dump | head -20
```
Expected: decrypt succeeds; TOC lists the expected schemas/tables.

- [ ] **Step 2: Restore into a throwaway local Postgres 17 (or scratch Supabase) and integrity-check**

Run (local Postgres 17 example):
```bash
createdb partna_restore_drill
pg_restore --dbname partna_restore_drill --no-owner --no-privileges partna-dev-<DATE>.dump
psql partna_restore_drill -c "select (select count(*) from core.users) as users, (select count(*) from site.sites) as sites, (select count(*) from site.design_kits) as design_kits;"
```
Expected: restore completes; counts are non-zero and match the source (allow for rows written after the dump). Follow drill 04 Phase 4 for the fuller integrity walk if desired.

- [ ] **Step 3: Write the drill-04 log**

Copy `docs/runbooks/drills/logs/TEMPLATE.md` → `docs/runbooks/drills/logs/<YYYY-MM-DD>-backup-restore.md` and fill in: backup path taken (off-platform dump), measured dump size, restore time, integrity counts, and that the round trip succeeded. This is the evidence TECH-3 points at.

- [ ] **Step 4: Clean up local artifacts**

Run:
```bash
dropdb partna_restore_drill
rm -P partna-dev-<DATE>.dump partna-dev-<DATE>.dump.enc
```
Expected: scratch DB dropped; plaintext dump securely deleted.

- [ ] **Step 5: Commit the drill log (backend repo, on a branch)**

Run (in the backend repo):
```bash
git checkout -b chore/backup-drill-log-<date>
git add docs/runbooks/drills/logs/<YYYY-MM-DD>-backup-restore.md
git commit -m "docs(drills): first backup-restore log (off-platform dump verified)"
```
Expected: committed on a feature branch (do not commit straight to `development`).

---

### Task 7: Cross-reference the backup in the backend repo (drill README + checklist)

Makes the off-platform backup discoverable from where someone looks for the backup story.

**Files:**
- Modify: `docs/runbooks/drills/README.md` (staleness-rule list for drill 04)
- Modify: `docs/checklists/launch-readiness-checklist.md` (TECH-3 block, around line 47)

**Interfaces:**
- Consumes: nothing. Same branch as Task 6 Step 5.

- [ ] **Step 1: Add a pointer under drill 04 in the drills README**

In `docs/runbooks/drills/README.md`, under the staleness-rule bullet for `04`, append a sentence:
```markdown
- 04 → never goes stale from code; re-run quarterly because *backups* rot, not code.
  Off-platform weekly dumps run from the `Hunter-Balcombe-Sykes/partna-db-backup`
  repo (GitHub Actions → Cloudflare R2); this drill also restores one of those.
```

- [ ] **Step 2: Note the off-platform backup in the checklist TECH-3 block**

In `docs/checklists/launch-readiness-checklist.md`, under the TECH-3 entry (~line 47), add:
```markdown
    - **Off-platform backups:** weekly encrypted `pg_dump` → Cloudflare R2 live from
      `Hunter-Balcombe-Sykes/partna-db-backup` (closes account-level-disaster gap).
      Supabase Pro managed daily backups pending (see below).
```

- [ ] **Step 3: Commit**

Run:
```bash
git add docs/runbooks/drills/README.md docs/checklists/launch-readiness-checklist.md
git commit -m "docs: cross-reference off-platform DB backup from drill 04 + TECH-3"
```
Expected: committed on the same branch as Task 6. Hand the branch to Josh to push/merge per normal flow.

---

### Task 8: Schedule the Supabase Pro upgrade reminder + checklist note

**Files:**
- Modify: `docs/checklists/launch-readiness-checklist.md` (TECH-3 area — dated Pro-upgrade action)

**Interfaces:**
- Consumes: nothing.

- [ ] **Step 1: Add the dated Pro-upgrade action to the checklist**

In `docs/checklists/launch-readiness-checklist.md`, near TECH-3, add:
```markdown
    - **[2026-07-24] Upgrade Supabase org `Partna` to Pro** — enables automatic
      daily managed backups + dashboard "restore to new project". Complements the
      off-platform weekly dump. Billing action, owner-only in the Supabase dashboard.
```

- [ ] **Step 2: Commit (same branch as Task 7)**

Run:
```bash
git add docs/checklists/launch-readiness-checklist.md
git commit -m "docs(checklist): dated Supabase Pro upgrade action (2026-07-24)"
```

- [ ] **Step 3: Create the one-time reminder**

Use the `schedule` skill (or `CronCreate`) to create a **one-time** routine firing on **2026-07-24 (morning, local time)** with a prompt like:
> "Remind Josh: upgrade the Supabase org `Partna` (`ligsuetayyrxzojoxxbt`) to Pro for automatic daily managed backups + dashboard restore (launch-checklist TECH-3). This is a billing action he does in the Supabase dashboard — you can't do it for him. Confirm the off-platform weekly R2 backup is still running as the interim cover."

Expected: the routine appears in the schedule list with the 2026-07-24 fire date. Confirm with Josh which delivery he wants (push notification vs. a claude session).

---

## Verification (whole plan)

- Task 2 proves every credential + the exact dump command **before** CI exists.
- Task 5 produces the first real encrypted object in R2 via a green workflow run.
- Task 6 proves that object actually restores and logs it against drill 04 (TECH-3 evidence).
- Tasks 7–8 make it discoverable and track the Pro follow-up.

**Definition of done:** a manual `workflow_dispatch` run is green, `partna-dev-<date>.dump.enc` is in `s3://partna-db-backups/weekly/`, that object has been decrypted + restored + integrity-checked once, the drill-04 log is written, and the 2026-07-24 Pro-upgrade reminder is scheduled.
