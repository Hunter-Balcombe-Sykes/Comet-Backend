# R2 Media Backup — Setup Runbook

Closes drill finding **F4** (`docs/runbooks/drills/logs/2026-07-26-backup-restore.md`): object
storage has no backup of any kind, so a DB restore returns rows pointing at objects that may be gone.

**Status: NOT YET IMPLEMENTED.** The workflow below is ready to commit, but it cannot run until the
bucket, token, secrets and lifecycle rule exist — all dashboard-only steps. Do those first.

## Scope

Sitepage media lives in **one Cloudflare R2 bucket** behind the `media` disk
(`config/filesystems.php:71` — `MEDIA_DISK_BUCKET` → `AWS_BUCKET` → literal fallback `sidest-media`;
`.env.example:190`). `public_dev` (`config/filesystems.php:95`) is a legacy alias onto the *same*
bucket, not a second one. Five prefixes share it:

| Prefix | Written by | Class |
|---|---|---|
| `images/{userId}/{mediaId}/` | `MediaUploadService.php:179` | originals + derived variants |
| `videos/…` | `MediaUploadService.php:108` | originals + renditions |
| `documents/{userId}/{mediaId}/original.{ext}` | `UserDocumentController.php:158` | **originals only, no derivatives** |
| `platforms/instagram/{ts}/` | `InstagramConnectionSeeder.php:77` | mirrored third-party media |
| `exports/{userId}/{auditId}.zip` | `Jobs/Gdpr/ExportUserDataJob.php:91` | disposable, **deliberately excluded** |

## Why this is worth doing — the numbers, not the vibe

Measured read-only against **dev** (`glncumufgaqcmqhzwrxm`) on 2026-07-30:

- `site.site_media` — 39 live rows / 47 all-time, originals **25.43 MB**
- `site.media_variants` — 91 rows, **13.12 MB**
- By pool: gallery 16 (12.57 MB) · documents 2 (6.55 MB) · content 9 (4.90 MB) · design 12 (1.41 MB)
- Total DB-accounted ≈ **38.5 MB**. Prod is ≈ 0 (`core.users = 0`).

**Originals are 66% of bytes.** The "it's all regenerable thumbnails" argument does not hold, and
`documents/` (17% of bytes) has no derivatives at all.

And the regenerability claim is currently false in practice: `ImageVariantService.php:304` writes
`{basePath}/original_{hash}.{ext}` with `private` visibility, commented as kept "only as a
re-processing source" (`:312`) — but the **only** dispatch sites for `ProcessImageVariantsJob` /
`ProcessVideoVariantsJob` are the upload flow and `ProcessLogoVariantsJob.php:372`. There is no
artisan command and no backfill, so nothing can re-derive variants today. Instagram mirrors are
re-fetchable only via billed Apify scrapes and only while the connection lives; SVG logo variants
come from an external processor (`LogoProcessorClient.php`).

Measure prod before and after:
```sql
select count(*), sum(original_size_bytes) from site.site_media where deleted_at is null;
select count(*), sum(file_size_bytes)     from site.media_variants;
```
Neither covers `platforms/` or `exports/` — for those use `rclone size r2:<bucket>/platforms`.

## The retention constraint — read this before choosing a window

This is not an ordinary backup. The app deletes media on purpose, and a backup that outlives those
deletions defeats them:

- Soft-deleted media hard-purges at **30 days** (`config/partna.php:1026`, `routes/console.php:53`).
- GDPR export ZIPs purge at **30 days** (`routes/console.php:364`).
- **Erasure deletes R2 artifacts synchronously** (`AccountDeletionService.php:733-738`, `:1371`).

So the mirror must **propagate deletes**, and any tombstone must expire at **30 days — not the DB
backup's 90.** A media backup that retains objects after a user has exercised erasure is a worse
compliance problem than the gap it closes.

`exports/**` is excluded outright: those ZIPs are regenerable on demand and contain a full personal-
data dump, so copying them into a second bucket adds risk and protects nothing.

## Step 1 — Cloudflare dashboard (Josh only, do this first)

- [ ] Confirm the **prod** bucket name and which account owns it — Laravel Cloud-managed or your own
      Cloudflare. `docs/deploy/PROMPT-execute-cutover-phase-2-prod-env.md:164` suggests prod may use a
      different bucket than dev. **UNVERIFIED — this determines whether the workflow needs one rclone
      remote or two.** Everything below assumes one account; if it is two, add a second remote block.
- [ ] R2 → Create bucket `partna-media-backup`, same jurisdiction as the source bucket.
- [ ] R2 → Manage API Tokens → create a token with **Object Read** on the source bucket and
      **Object Read & Write** on `partna-media-backup`. Do not reuse the DB-backup token; that one is
      scoped to `partna-db-backups`.
- [ ] `partna-media-backup` → Settings → Object lifecycle rules → delete objects under prefix
      `deleted/` after **30 days**. This is what bounds tombstone retention; without it the
      `--backup-dir` tombstones accumulate forever and the GDPR position above is void.
- [ ] GitHub → `Hunter-Balcombe-Sykes/partna-db-backup` → Settings → Secrets → add
      `R2_MEDIA_ACCESS_KEY_ID`, `R2_MEDIA_SECRET_ACCESS_KEY`, `R2_MEDIA_SOURCE_BUCKET`.
      `R2_ENDPOINT` already exists and is reused.

## Step 2 — the workflow

Commit as `.github/workflows/weekly-media-backup.yml` in `Hunter-Balcombe-Sykes/partna-db-backup`
(**not** this repo — that repo holds the backup automation). Shape deliberately mirrors
`weekly-db-backup.yml`: same cron day, same `workflow_dispatch`, same non-cancelling concurrency,
same fail-closed verification.

```yaml
name: weekly-media-backup

on:
  schedule:
    - cron: '30 15 * * 0'   # Sunday 15:30 UTC — 30 min after the DB dump, same quiet window
  workflow_dispatch: {}

concurrency:
  group: media-backup
  cancel-in-progress: false    # never abort an in-flight mirror

jobs:
  mirror:
    runs-on: ubuntu-latest
    timeout-minutes: 30
    env:
      # One remote, two buckets — same account, one scoped token.
      RCLONE_CONFIG_R2_TYPE: s3
      RCLONE_CONFIG_R2_PROVIDER: Cloudflare
      RCLONE_CONFIG_R2_ACCESS_KEY_ID: ${{ secrets.R2_MEDIA_ACCESS_KEY_ID }}
      RCLONE_CONFIG_R2_SECRET_ACCESS_KEY: ${{ secrets.R2_MEDIA_SECRET_ACCESS_KEY }}
      RCLONE_CONFIG_R2_ENDPOINT: ${{ secrets.R2_ENDPOINT }}
      RCLONE_CONFIG_R2_REGION: auto
      # R2 rejects the bucket-creation probe rclone otherwise makes on every run.
      RCLONE_CONFIG_R2_NO_CHECK_BUCKET: 'true'
      SRC_BUCKET: ${{ secrets.R2_MEDIA_SOURCE_BUCKET }}
      DST_BUCKET: partna-media-backup
    steps:
      - name: Install rclone
        run: |
          sudo -v && curl -fsSL https://rclone.org/install.sh | sudo bash
          rclone version

      - name: Stamp
        run: echo "STAMP=$(date +%F)" >> "$GITHUB_ENV"

      - name: Record source size before mirroring
        run: |
          rclone size "r2:$SRC_BUCKET" --exclude 'exports/**' | tee source-size.txt

      # sync (not copy) so deletions propagate — erasure must not be defeated by the
      # backup. --backup-dir diverts the deleted/overwritten object into a dated
      # tombstone prefix instead of destroying it, and the bucket's 30-day lifecycle
      # rule expires that prefix. 30 days matches the app's own media purge window
      # (config/partna.php media retention); do NOT raise it to the DB backup's 90.
      #
      # exports/** is excluded on purpose: GDPR export ZIPs are regenerable on demand
      # and contain a full personal-data dump. Copying them protects nothing and
      # doubles the blast radius of a leak.
      - name: Mirror media
        run: |
          rclone sync "r2:$SRC_BUCKET" "r2:$DST_BUCKET/current" \
            --backup-dir "r2:$DST_BUCKET/deleted/$STAMP" \
            --exclude 'exports/**' \
            --transfers 8 --checkers 16 \
            --stats 30s --stats-one-line \
            --log-level INFO

      - name: Verify the mirror matches (fail closed)
        run: |
          rclone check "r2:$SRC_BUCKET" "r2:$DST_BUCKET/current" \
            --exclude 'exports/**' --one-way --log-level NOTICE

      - name: Report destination size
        run: rclone size "r2:$DST_BUCKET/current"
```

`rclone check --one-way` is the fail-closed gate: it exits non-zero if any source object is missing
or differs at the destination, so a silently-partial mirror fails the run rather than reporting green.

## Step 3 — verify, once

- [ ] Run it manually: `gh workflow run weekly-media-backup.yml --repo Hunter-Balcombe-Sykes/partna-db-backup`
- [ ] Confirm the run is green and that "Verify the mirror matches" actually executed (a skipped
      verification step is the classic false green).
- [ ] Spot-restore one object: `rclone copy r2:partna-media-backup/current/<key> ./` and open it.
      A mirror nobody has ever read back is not a proven backup — that is exactly the mistake F6
      recorded about the DB backup, whose cron sat commented out and had never run once.
- [ ] Re-run drill-04 (`docs/runbooks/drills/04-backup-restore.md`) and update F4 in the log.

## What this does NOT protect against

- **Accidental mass deletion** propagates to `current/` by design. The `deleted/{date}/` tombstones
  are the only recovery, and only for 30 days.
- **Corruption within the window** — a corrupted object mirrors faithfully.
- **Account-level loss.** Both buckets are in one Cloudflare account. Surviving that needs an
  off-provider copy, which is deliberately out of scope at ~40 MB and pre-pilot.
- **Anything in `exports/`**, by design.

## Open decisions

1. **Prod bucket name/account** — determines one remote or two. Blocks Step 1.
2. **Retention: 30 days (GDPR-aligned) or 90 (DB-parity).** 30 is recommended and is what the
   lifecycle rule above assumes.
3. **Whether to build `media:reprocess-variants` in the backend repo.** It does not exist. Building it
   is the only thing that would make the "derivatives are regenerable" claim true, and it would let a
   future backup cover originals only — 34% less to mirror. Not required for this fix.
