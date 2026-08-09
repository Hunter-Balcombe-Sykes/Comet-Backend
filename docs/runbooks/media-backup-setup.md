# R2 Media Backup — Setup Runbook

Closes drill finding **F4** (`docs/runbooks/drills/logs/2026-07-26-backup-restore.md`): object
storage has no backup of any kind, so a DB restore returns rows pointing at objects that may be gone.

**Status: PARTIALLY IMPLEMENTED (2026-08-06).** The workflow is committed and live — do not re-paste
it from this doc; edit the file in the backup repo and keep this copy in sync.

| | state |
|---|---|
| `weekly-media-backup.yml` committed to `Hunter-Balcombe-Sykes/partna-db-backup` | ✅ `567d1082`, workflow id `328413731`, active |
| `partna-media-backup` bucket (our Cloudflare account) | ✅ created |
| Lifecycle rule — expire `deleted/` after 30 days | ✅ done 2026-08-06 |
| Destination R2 token → `R2_MEDIA_DST_*` secrets | ✅ set 2026-08-06 |
| Source read-only bucket key → `R2_SRC_*` secrets | ✅ set 2026-08-06 (minted in the dashboard) |
| First run green, both credentials authenticate | ✅ run `31086678346`, 2026-08-06 |
| **Copy / delete / tombstone path exercised** | ❌ **unproven — see below** |
| `R2_SRC_ENDPOINT` / `R2_SRC_BUCKET` secrets | ✅ set 2026-08-06 |
| **Prod env attached to `partna_production`** | ✅ done 2026-08-06 — prod and dev no longer share a bucket or a credential |

**Until the secrets land the scheduled run fails red on auth.** That is the intended fail-closed
behaviour, not a defect: an unconfigured backup should be loud. Disable the schedule only if the
noise is worse than the reminder.

**The mirror is scoped to prod only.** Prod now reads `AWS_BUCKET=fls-a1bab29a-…`, so this workflow
mirrors the bucket prod actually writes to. It is legitimately empty today (prod holds 0 media rows),
so `MIN_OBJECTS` stays at 0 and the run warns rather than fails. **Raise it to 1 at first pilot
upload** — from then on, an empty source means something is wrong and should turn the run red.
Split detail: `docs/deploy/prod-cutover-change-checklist.md` §C "Media / storage (R2)".

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
Neither covers `platforms/` or `exports/` — for those use `rclone size src:<bucket>/platforms`
(remote names are defined in Step 2's workflow env).

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

## Which account owns what — RESOLVED 2026-08-06

This was the open blocker. Measured against both Laravel Cloud envs:

| | value |
|---|---|
| `AWS_BUCKET` (prod **and** dev, identical) | `fls-a1334790-8631-448b-9b55-dcbd64ec0c65` |
| `AWS_ENDPOINT` (prod **and** dev, identical) | `https://367be3a2035528943240074d0096e0cd.r2.cloudflarestorage.com` |
| `CLOUDFLARE_ACCOUNT_ID` (our own account) | `e1a594b22f137d1723e1d148e4f35ee6` |

Two consequences, both of which change the steps below:

1. **The source bucket is NOT in our Cloudflare account.** The `fls-` name, the `.laravel.cloud`
   public URL and the `367be3a2…` endpoint hash are all Laravel Cloud's managed R2. We cannot open
   it in the Cloudflare dashboard or mint a Cloudflare API token against it.
2. **Therefore: two rclone remotes, not one** — source in Laravel Cloud's account, destination in
   ours. This resolves Open decision 1.

**Credentials for the source come from Laravel Cloud's own key API, not Cloudflare's.** `cloud
bucket-key:create <bucket> --permission read_only` mints exactly the scoped read-only credential this
mirror wants. Do NOT reuse the env-injected `AWS_ACCESS_KEY_ID` pair — those are the bucket's
`default_access_key`, which is `read_write` and is also the credential the running app depends on.

That split is an upgrade, not a compromise: it moves "account-level loss" out of the
*does-not-protect-against* list below, because the two buckets are now genuinely in different accounts.

## Step 1 — dashboard + secrets (Josh only, do this first)

Reminder: do the prod/dev bucket split first (see Status at the top). These steps are unchanged by it
except for the value of `R2_SRC_BUCKET`.

**In our own Cloudflare account (`e1a594b2…`):**

- [ ] R2 → Create bucket `partna-media-backup`, same jurisdiction as `partna-db-backups`.
- [ ] R2 → Manage API Tokens → token with **Object Read & Write** scoped to `partna-media-backup`
      **only**. It cannot cover the source bucket — that lives in another account. Do not reuse the
      DB-backup token; that one is scoped to `partna-db-backups`.
- [ ] `partna-media-backup` → Settings → Object lifecycle rules → delete objects under prefix
      `deleted/` after **30 days**. This is what bounds tombstone retention; without it the
      `--backup-dir` tombstones accumulate forever and the GDPR position above is void.

**Source credential — Laravel Cloud CLI, not a dashboard step.** Mint a dedicated read-only key
against the *prod* media bucket:

**Use the Laravel Cloud dashboard, NOT the CLI.** Bucket → Keys → create a key named
`media-backup-readonly` with **read-only** permission, and copy the secret from the creation dialog.

**The CLI cannot do this** — measured 2026-08-06, not inferred:

| command | `secretAccessKey` |
|---|---|
| `bucket-key:create … --json --show-sensitive` | `null` |
| `bucket-key:create …` (human output, no `--json`) | `null` |
| `bucket-key:list … --json --show-sensitive` | `null` |
| `bucket-key:get … --json --show-sensitive` | `null` |

`bucket-key:create` *does* create the key — correct name and `read_only` permission — it just never
returns the secret, so the CLI produces a credential nobody can use. Every retrieval path returns
`null` afterwards, so a key minted this way is unrecoverable dead weight: **delete it** with
`~/.composer/vendor/bin/cloud bucket-key:delete <bucket> <key> --force` rather than leaving an
unaccounted credential on the bucket.

(The CLI is also not on PATH — `which cloud` fails in interactive and login shells. Elsewhere in these
docs `cloud …` is shorthand; in a copy-paste block use `~/.composer/vendor/bin/cloud`.)

**Do not fall back to the env-injected `AWS_ACCESS_KEY_ID`.** Beyond being `read_write`, it is not
what it appears to be — measured 2026-08-06, the value injected into *both* the prod and dev
environments is **identical to each other and matches none of the keys `bucket-key:list` reports on
either bucket**. Whatever that credential is, it is not the `default_access_key` shown in the
dashboard, and its scope is therefore unverified. Reusing an unidentified credential for the backup
would make the mirror's blast radius unknown — the opposite of the point. **Unexplained; worth
resolving before anyone relies on it.**

**GitHub → `Hunter-Balcombe-Sykes/partna-db-backup` → Settings → Secrets:**

Six new secrets. The repo already holds `BACKUP_PASSPHRASE`, `R2_ACCESS_KEY_ID`, `R2_ENDPOINT`,
`R2_SECRET_ACCESS_KEY`, `SUPABASE_DB_URL` (verified 2026-08-06).

| Secret | Value |
|---|---|
| `R2_SRC_ACCESS_KEY_ID` / `R2_SRC_SECRET_ACCESS_KEY` | the `media-backup-readonly` key from above |
| `R2_SRC_ENDPOINT` | `https://367be3a2035528943240074d0096e0cd.r2.cloudflarestorage.com` |
| `R2_SRC_BUCKET` | `fls-a1bab29a-c5e6-4e62-8a7c-717e1aaa0484` (`partna_production` — **only meaningful after the attach**; until then prod still runs on dev's bucket) |
| `R2_MEDIA_DST_ACCESS_KEY_ID` / `R2_MEDIA_DST_SECRET_ACCESS_KEY` | the new `partna-media-backup` token |

**`R2_ENDPOINT` is reused for the destination** rather than duplicated. `partna-db-backups` and
`partna-media-backup` are both in our own Cloudflare account, so the endpoint is identical, and one
secret cannot drift out of sync with the other. If that ever stops being true the run fails on auth
rather than quietly mirroring into the wrong account. The destination *credentials* are still their
own pair — `R2_ACCESS_KEY_ID` is scoped to `partna-db-backups` and must not be reused.

The source key is genuinely read-only, so a compromised Actions runner cannot write to or delete live
production media. It does **not** rotate on its own; it survives until explicitly deleted with
`cloud bucket-key:delete`. If it ever is deleted, the failure is loud rather than silent — `rclone
check` fails the run instead of mirroring nothing and reporting green.

## Step 2 — the workflow

Commit as `.github/workflows/weekly-media-backup.yml` in `Hunter-Balcombe-Sykes/partna-db-backup`
(**not** this repo — that repo holds the backup automation). Shape deliberately mirrors
`weekly-db-backup.yml`: same cron day, same `workflow_dispatch`, same non-cancelling concurrency,
same fail-closed verification.

```yaml
name: weekly-media-backup

on:
  schedule:
    - cron: '30 15 * * 0'    # Sunday 15:30 UTC — 30 min after the DB dump, same quiet window
  workflow_dispatch: {}        # manual on-demand run, alongside the weekly cron

concurrency:
  group: media-backup
  cancel-in-progress: false    # never abort an in-flight mirror

jobs:
  mirror:
    runs-on: ubuntu-latest
    timeout-minutes: 30
    env:
      # TWO remotes. The source bucket is Laravel Cloud-managed and sits in THEIR
      # Cloudflare account (367be3a2…), not ours (e1a594b2…), so it needs its own
      # endpoint and its own credentials.
      #
      # src — read-only key minted via `cloud bucket-key:create … --permission read_only`.
      # Deliberately NOT the bucket's default_access_key: that one is read_write and is
      # the credential the running app uses.
      RCLONE_CONFIG_SRC_TYPE: s3
      RCLONE_CONFIG_SRC_PROVIDER: Cloudflare
      RCLONE_CONFIG_SRC_ACCESS_KEY_ID: ${{ secrets.R2_SRC_ACCESS_KEY_ID }}
      RCLONE_CONFIG_SRC_SECRET_ACCESS_KEY: ${{ secrets.R2_SRC_SECRET_ACCESS_KEY }}
      RCLONE_CONFIG_SRC_ENDPOINT: ${{ secrets.R2_SRC_ENDPOINT }}
      RCLONE_CONFIG_SRC_REGION: auto
      # R2 rejects the bucket-creation probe rclone otherwise makes on every run.
      RCLONE_CONFIG_SRC_NO_CHECK_BUCKET: 'true'

      # dst — our own account. R2_ENDPOINT is reused from weekly-db-backup: partna-db-backups
      # and partna-media-backup are in the same account, so the endpoint is identical. If that
      # ever stops being true the run fails on auth rather than mirroring somewhere wrong.
      RCLONE_CONFIG_DST_TYPE: s3
      RCLONE_CONFIG_DST_PROVIDER: Cloudflare
      RCLONE_CONFIG_DST_ACCESS_KEY_ID: ${{ secrets.R2_MEDIA_DST_ACCESS_KEY_ID }}
      RCLONE_CONFIG_DST_SECRET_ACCESS_KEY: ${{ secrets.R2_MEDIA_DST_SECRET_ACCESS_KEY }}
      RCLONE_CONFIG_DST_ENDPOINT: ${{ secrets.R2_ENDPOINT }}
      RCLONE_CONFIG_DST_REGION: auto
      RCLONE_CONFIG_DST_NO_CHECK_BUCKET: 'true'

      SRC_BUCKET: ${{ secrets.R2_SRC_BUCKET }}
      DST_BUCKET: partna-media-backup

      # Object-count floor, mirroring weekly-db-backup's MIN_BYTES idea.
      # 0 is correct ONLY pre-pilot: prod currently holds no media at all.
      # RAISE THIS TO 1 the day the first real upload lands — it is the only thing
      # standing between "prod was never attached to this bucket" and a green run
      # that mirrored nothing.
      MIN_OBJECTS: '0'
    steps:
      - name: Install rclone
        run: |
          sudo -v && curl -fsSL https://rclone.org/install.sh | sudo bash
          rclone version

      - name: Stamp
        run: echo "STAMP=$(date +%F)" >> "$GITHUB_ENV"

      - name: Measure source and enforce the object floor
        run: |
          rclone size "src:$SRC_BUCKET" --exclude 'exports/**' --json | tee source-size.json
          COUNT="$(jq -r '.count' source-size.json)"
          BYTES="$(jq -r '.bytes' source-size.json)"
          echo "Source: $COUNT objects, $BYTES bytes (floor $MIN_OBJECTS)"
          {
            echo "### Source bucket \`$SRC_BUCKET\`"
            echo "- objects: **$COUNT**"
            echo "- bytes: **$BYTES**"
          } >> "$GITHUB_STEP_SUMMARY"
          if [ "$COUNT" -lt "$MIN_OBJECTS" ]; then
            echo "::error::Source has $COUNT objects, below floor $MIN_OBJECTS — refusing to mirror."
            exit 1
          fi
          if [ "$COUNT" -eq 0 ]; then
            echo "::warning::Source bucket is EMPTY. Expected pre-pilot. If prod media exists, the \
          prod env is probably still attached to a DIFFERENT bucket — check AWS_BUCKET on the prod \
          environment against R2_SRC_BUCKET before trusting this run."
            echo "> :warning: Source is empty — this run mirrored nothing." >> "$GITHUB_STEP_SUMMARY"
          fi

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
          rclone sync "src:$SRC_BUCKET" "dst:$DST_BUCKET/current" \
            --backup-dir "dst:$DST_BUCKET/deleted/$STAMP" \
            --exclude 'exports/**' \
            --transfers 8 --checkers 16 \
            --stats 30s --stats-one-line \
            --log-level INFO

      - name: Verify the mirror matches (fail closed)
        run: |
          rclone check "src:$SRC_BUCKET" "dst:$DST_BUCKET/current" \
            --exclude 'exports/**' --one-way --log-level NOTICE

      - name: Report destination size
        run: |
          rclone size "dst:$DST_BUCKET/current" --json | tee dest-size.json
          echo "- mirrored: **$(jq -r '.count' dest-size.json)** objects" >> "$GITHUB_STEP_SUMMARY"
```

`rclone check --one-way` is the fail-closed gate: it exits non-zero if any source object is missing
or differs at the destination, so a silently-partial mirror fails the run rather than reporting green.

## The empty-set trap — why run `31086678346` proves less than it looks

That run was green and every step executed, including the fail-closed verify. What it established:
both credentials authenticate, and both buckets are reachable (`Source: 0 objects, 0 bytes` for the
read side; `S3 bucket partna-media-backup path current: 0 differences found` for the write side).

What it did **not** establish: the source had 0 objects, so `rclone sync` copied nothing and
`rclone check --one-way` — "is every source object present at the destination?" — was **vacuously
true**. Every gate passed on a technicality. In particular the destination token's *write*
permission is untested; a token accidentally created read-only would produce an identical green run.

`MIN_OBJECTS` is what keeps this honest rather than silently misleading: the run states `0 objects`
out loud instead of implying it mirrored something.

**DEFERRED by decision (2026-08-06) to prod go-live**, rather than closed with a synthetic probe.
Acceptable because the deferred failure is **loud, not silent**: a destination token lacking write
permission makes `rclone sync` error on the first real object and the run goes red. The only silent
failure mode — an empty source mirroring nothing while reporting green — is what `MIN_OBJECTS`
already guards.

### ⚑ DO THESE TOGETHER, at the first real media in prod

One trigger, two actions. Both are currently *correct* and both become *wrong* the moment prod holds
a single media object.

- [ ] **Raise `MIN_OBJECTS` from `0` to `1`** in `.github/workflows/weekly-media-backup.yml`.
      Until then an empty source is legitimate; after, it means something is broken and must fail.
- [ ] **Verify a real object actually round-trips.** Run the workflow, then confirm the object
      exists at `partna-media-backup/current/<key>` → **write path proven**. Then delete it at
      source, re-run, and confirm it moved to `partna-media-backup/deleted/<date>/` →
      **deletion propagation and the tombstone proven**, which is what the GDPR position rests on.

Until both are done, this workflow is *connected but unexercised* — do not record it as a working
backup. That distinction is the whole content of finding F6.

If you want to close it earlier without waiting for real media, the same test works with a throwaway
file uploaded through the bucket's File explorer (the mirror's own source key is read-only by
design, so uploads cannot come from the pipeline).

## Step 3 — verify, once

- [ ] Run it manually: `gh workflow run weekly-media-backup.yml --repo Hunter-Balcombe-Sykes/partna-db-backup`
- [ ] Confirm the run is green and that "Verify the mirror matches" actually executed (a skipped
      verification step is the classic false green).
- [ ] Spot-restore one object: `rclone copy dst:partna-media-backup/current/<key> ./` and open it.
      A mirror nobody has ever read back is not a proven backup — that is exactly the mistake F6
      recorded about the DB backup, whose cron sat commented out and had never run once.
- [ ] Re-run drill-04 (`docs/runbooks/drills/04-backup-restore.md`) and update F4 in the log.

## What this does NOT protect against

- **Accidental mass deletion** propagates to `current/` by design. The `deleted/{date}/` tombstones
  are the only recovery, and only for 30 days.
- **Corruption within the window** — a corrupted object mirrors faithfully.
- **Anything in `exports/`**, by design.

Cloudflare **account-level loss of the source** is now covered, which it would not have been under the
original single-account plan: the source bucket is Laravel Cloud's account and the mirror is ours, so
losing either account leaves a copy standing. Loss of *both providers* still needs an off-provider
copy, deliberately out of scope at ~40 MB and pre-pilot.

## Open decisions

1. ~~**Prod bucket name/account** — determines one remote or two.~~ **RESOLVED 2026-08-06:** one
   shared Laravel Cloud-managed source bucket in Laravel Cloud's account → **two remotes**. See the
   table above. Superseded by a new blocker: prod and dev share that bucket and must be split first.
2. **Retention: 30 days (GDPR-aligned) or 90 (DB-parity).** 30 is recommended and is what the
   lifecycle rule above assumes.
3. **Whether to build `media:reprocess-variants` in the backend repo.** It does not exist. Building it
   is the only thing that would make the "derivatives are regenerable" claim true, and it would let a
   future backup cover originals only — 34% less to mirror. Not required for this fix.
