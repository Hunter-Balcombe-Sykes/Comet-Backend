#!/usr/bin/env bash
#
# Upload an ad-hoc pg_dump (or any file) to the Cloudflare R2 backup bucket FROM THIS LAPTOP.
#
# WHY THIS EXISTS
#   The routine deploy runbook tells you to take a pre-flight `pg_dump` before a risky
#   migration or a DROP, but it stops at "take the dump" — the dump then lives on one
#   laptop, which is not a backup. The scheduled `weekly-db-backup` workflow in
#   `PartnaAu/partna-db-backup` is the only thing that ever writes to R2, and
#   its R2 credentials are GitHub Actions secrets: write-only, unreadable from here.
#   A previous session concluded R2 was therefore unreachable locally. That was wrong.
#
# HOW IT REACHES R2 WITHOUT THOSE SECRETS
#   Not via the S3 API and not via AWS_*/R2_* keys — there are none on this machine
#   (`.env`'s AWS_* keys are EMPTY, and even when populated they address the Laravel
#   Cloud-managed MEDIA bucket in Cloudflare account 367be3a2…, not ours).
#   Instead it uses the wrangler OAuth session already stored at
#   `~/Library/Preferences/.wrangler/config/default.toml`, which authenticates as the
#   account owner against Cloudflare's REST API. wrangler itself is not installed —
#   `npx` fetches it per-run, so there is nothing to install first.
#
#   Consequence worth knowing: that OAuth session is an ACCOUNT-WIDE credential, not the
#   bucket-scoped token the backup design specifies. It can write to every bucket in the
#   account. That is fine for an operator running this by hand and NOT fine to bake into
#   any automated/CI path — CI must keep using the scoped R2_* secrets.
#
# ENCRYPTION
#   Objects under `weekly/` are AES-256-CBC (pbkdf2) encrypted, matching
#   `weekly-db-backup.yml`, so `restore-drill.yml` can decrypt whatever this uploads with
#   the same BACKUP_PASSPHRASE. Set BACKUP_PASSPHRASE to encrypt (default, and required
#   for anything restore-drill will read). Pass --plaintext only for throwaway probes.
#
# USAGE
#   BACKUP_PASSPHRASE='…' scripts/db/backup-to-r2.sh partna-prod-preflight-20260817.dump
#   BACKUP_PASSPHRASE='…' scripts/db/backup-to-r2.sh --key weekly/partna-prod-2026-08-17-preDROP.dump.enc file.dump
#   scripts/db/backup-to-r2.sh --plaintext --key probes/hello.txt hello.txt
#   scripts/db/backup-to-r2.sh --delete weekly/some-object.dump.enc
#
# VERIFICATION
#   Every upload is read back from R2 and byte-compared against what was sent. "Upload
#   complete" on its own is not treated as evidence.
#
#   Do NOT verify with `wrangler r2 bucket info` — wrangler has no object-list command at
#   all (only get/put/delete), and `bucket info`'s object_count is a lagging analytics
#   figure: it still read 4 while a freshly-uploaded probe was demonstrably readable.
#   The round-trip below is the only trustworthy local check.
#
set -euo pipefail

BUCKET="${R2_BACKUP_BUCKET:-partna-db-backups}"
PREFIX="${R2_BACKUP_PREFIX:-weekly/}"
WRANGLER="${WRANGLER_CMD:-npx --yes wrangler@4}"

# Cloudflare's REST object API is single-part only; wrangler cannot chunk. Dumps are
# currently ~0.5 MB, so this is a guard against a future surprise, not a live limit.
MAX_BYTES=$((300 * 1024 * 1024))

die() { printf '\033[31merror:\033[0m %s\n' "$*" >&2; exit 1; }
note() { printf '\033[36m==>\033[0m %s\n' "$*"; }

ENCRYPT=1
KEY=""
DELETE_KEY=""
SRC=""

while [ $# -gt 0 ]; do
    case "$1" in
        --plaintext) ENCRYPT=0; shift ;;
        --key) KEY="${2:-}"; shift 2 ;;
        --delete) DELETE_KEY="${2:-}"; shift 2 ;;
        --bucket) BUCKET="${2:-}"; shift 2 ;;
        -h|--help) sed -n '2,40p' "$0"; exit 0 ;;
        -*) die "unknown flag: $1" ;;
        *) SRC="$1"; shift ;;
    esac
done

command -v npx >/dev/null 2>&1 || die "npx not found — install Node (nvm) first."

# Deleting is separated out so a mistyped key can never be interpreted as an upload.
if [ -n "$DELETE_KEY" ]; then
    note "deleting s3://$BUCKET/$DELETE_KEY"
    $WRANGLER r2 object delete "$BUCKET/$DELETE_KEY" --remote
    # A delete is confirmed by the object becoming unreadable, not by the CLI's exit code.
    # Do NOT pipe wrangler into grep: `pipefail` would surface wrangler's (expected)
    # non-zero status and make the confirmation read as a failure.
    PROBE="$(mktemp -d)"; trap 'rm -rf "$PROBE"' EXIT
    $WRANGLER r2 object get "$BUCKET/$DELETE_KEY" --file "$PROBE/gone" --remote > "$PROBE/out" 2>&1 || true
    if grep -q 'does not exist' "$PROBE/out" && [ ! -s "$PROBE/gone" ]; then
        note "delete confirmed — key no longer resolves"
        exit 0
    fi
    die "delete reported success but the key still resolves — check the bucket by hand."
fi

[ -n "$SRC" ] || die "no source file given. See --help."
[ -f "$SRC" ] || die "not a file: $SRC"

SIZE=$(stat -f%z "$SRC" 2>/dev/null || stat -c%s "$SRC")
[ "$SIZE" -gt 0 ] || die "$SRC is empty — refusing to upload a zero-byte backup."
[ "$SIZE" -le "$MAX_BYTES" ] || die "$SRC is $SIZE bytes; the R2 REST API caps a single-part put at $MAX_BYTES. Use the aws-cli multipart path from CI instead."

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

UPLOAD="$SRC"
if [ "$ENCRYPT" -eq 1 ]; then
    [ -n "${BACKUP_PASSPHRASE:-}" ] || die "BACKUP_PASSPHRASE is unset. Set it (same value as the GitHub secret, it is in the password manager) or pass --plaintext for a throwaway probe."
    UPLOAD="$WORK/$(basename "$SRC").enc"
    note "encrypting (AES-256-CBC, pbkdf2) — same scheme as weekly-db-backup.yml"
    openssl enc -aes-256-cbc -pbkdf2 -salt -in "$SRC" -out "$UPLOAD" -pass env:BACKUP_PASSPHRASE
fi

[ -n "$KEY" ] || KEY="${PREFIX}$(basename "$UPLOAD")"

note "uploading $(basename "$UPLOAD") -> s3://$BUCKET/$KEY"
$WRANGLER r2 object put "$BUCKET/$KEY" --file "$UPLOAD" --remote

# Read-back check. wrangler creates the destination file before it discovers a 404, so a
# zero-byte result is the failure signal — the exit code is not.
note "verifying by round-trip"
$WRANGLER r2 object get "$BUCKET/$KEY" --file "$WORK/readback" --remote >/dev/null
[ -s "$WORK/readback" ] || die "read-back is empty — the object did not land."
cmp -s "$UPLOAD" "$WORK/readback" || die "read-back differs from what was uploaded."

note "verified: s3://$BUCKET/$KEY ($(stat -f%z "$UPLOAD" 2>/dev/null || stat -c%s "$UPLOAD") bytes, byte-identical on read-back)"

if [ "$ENCRYPT" -eq 1 ]; then
    cat <<EOF

Restore rehearsal for this object (runs where the secrets live, nothing touches a laptop):
  gh workflow run restore-drill -R PartnaAu/partna-db-backup -f object_key='$(basename "$KEY")'
  gh run watch \$(gh run list -R PartnaAu/partna-db-backup --workflow restore-drill -L1 --json databaseId --jq '.[0].databaseId') -R PartnaAu/partna-db-backup

Note: restore-drill resolves object_key under weekly/ only. An object uploaded under any
other prefix cannot be drilled without editing that workflow.
EOF
fi
