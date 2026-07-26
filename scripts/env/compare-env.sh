#!/usr/bin/env bash
# Compare env-var KEY presence across local .env, Laravel Cloud dev + prod, and
# the prod-cutover checklist. Prints a presence matrix and gap summaries so you
# don't hand-diff the three environments against the doc.
#
# KEYS ONLY — this never reads or prints values, so no secrets are exposed
# (values are *meant* to differ per the SAME/SPLIT/NEW tags in the checklist;
#  presence is what you actually need to reconcile).
#
# Usage:  scripts/env/compare-env.sh
# Env:    CLOUD=/path/to/cloud   override the Cloud CLI binary
#         DEV_ENV / PROD_ENV     override Cloud environment names
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLOUD="${CLOUD:-$HOME/.composer/vendor/bin/cloud}"
[[ -x "$CLOUD" ]] || CLOUD="cloud"
DEV_ENV="${DEV_ENV:-development}"
PROD_ENV="${PROD_ENV:-production}"
CHECKLIST="$ROOT/docs/deploy/prod-cutover-change-checklist.md"
ENV_FILE="$ROOT/.env"

command -v jq >/dev/null || { echo "jq is required (brew install jq)" >&2; exit 1; }

# KEY list for a Cloud environment. Tolerates a stopped/erroring env (→ empty).
cloud_keys() {
    "$CLOUD" environment:get "$1" --json --fields=environmentVariables 2>/dev/null \
        | jq -r '.environmentVariables[]?.key' 2>/dev/null | sort -u || true
}

local_keys() {
    [[ -f "$ENV_FILE" ]] || return 0
    grep -E '^[A-Za-z_][A-Za-z0-9_]*=' "$ENV_FILE" | sed 's/=.*//' | sort -u
}

# UPPER_SNAKE tokens wrapped in backticks. The underscore requirement drops the
# SAME/SPLIT/NEW tags and prose words; wildcards (`AWS_*`) are excluded by the
# anchored class, so the column is a heuristic "is it named in the doc", not exact.
checklist_keys() {
    [[ -f "$CHECKLIST" ]] || return 0
    grep -oE '`[A-Z][A-Z0-9_]{2,}`' "$CHECKLIST" | tr -d '`' | grep -E '_' | grep -vE '_$' | sort -u
}

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
local_keys           > "$tmp/local"
cloud_keys "$DEV_ENV"  > "$tmp/dev"
cloud_keys "$PROD_ENV" > "$tmp/prod"
checklist_keys       > "$tmp/checklist"
sort -u "$tmp/local" "$tmp/dev" "$tmp/prod"      > "$tmp/env_union"
sort -u "$tmp/env_union" "$tmp/checklist"        > "$tmp/all_union"

[[ -s "$tmp/dev"  ]] || echo "⚠  dev env returned no keys — is the Cloud CLI authed?" >&2
[[ -s "$tmp/prod" ]] || echo "⚠  prod env returned no keys — stopped or not authed?"   >&2

count() { wc -l < "$1" | tr -d ' '; }
echo "env-var key presence — local(.env)=$(count "$tmp/local")  dev=$(count "$tmp/dev")  prod=$(count "$tmp/prod")  checklist=$(count "$tmp/checklist")"
echo "legend: Y=present  ·=absent   columns: LOCAL DEV PROD CHECKLIST"
echo

printf '%-42s %-6s %-4s %-5s %s\n' "KEY" "LOCAL" "DEV" "PROD" "CHKLST"
printf '%-42s %-6s %-4s %-5s %s\n' "$(printf '%.0s-' {1..42})" "-----" "---" "----" "------"
awk '
    FILENAME ~ /\/local$/     { L[$0]=1; next }
    FILENAME ~ /\/dev$/       { D[$0]=1; next }
    FILENAME ~ /\/prod$/      { P[$0]=1; next }
    FILENAME ~ /\/checklist$/ { C[$0]=1; next }
    FILENAME ~ /\/all_union$/ {
        printf "%-42s %-6s %-4s %-5s %s\n", $0, \
            ($0 in L?"Y":"·"), ($0 in D?"Y":"·"), ($0 in P?"Y":"·"), ($0 in C?"Y":"·")
    }
' "$tmp/local" "$tmp/dev" "$tmp/prod" "$tmp/checklist" "$tmp/all_union"

# Materialise the diffs to real files first — process substitution races the
# EXIT-trap cleanup and can see $tmp deleted before comm reads it.
comm -23 "$tmp/dev" "$tmp/prod"            > "$tmp/dev_not_prod"
comm -13 "$tmp/dev" "$tmp/prod"            > "$tmp/prod_not_dev"
comm -23 "$tmp/env_union" "$tmp/checklist" > "$tmp/env_not_chk"
comm -13 "$tmp/env_union" "$tmp/checklist" > "$tmp/chk_not_env"

section() { # $1 title  $2 file
    [[ -s "$2" ]] || return 0
    echo; echo "── $1 ($(count "$2")) ──"; sed 's/^/  /' "$2"
}
section "in DEV but NOT PROD — prod may be missing these"                 "$tmp/dev_not_prod"
section "in PROD but NOT DEV — verify these are intended"                 "$tmp/prod_not_dev"
section "set in an env but NOT named in the checklist — undocumented"     "$tmp/env_not_chk"
section "named in checklist but set in NO cloud env — provision/doc-only" "$tmp/chk_not_env"

# Checklist coverage — for every checklist-named key, where is it actually set?
echo; echo "══ CHECKLIST COVERAGE — each checklist-named key, bucketed by where it is set ══"
awk '
    FILENAME ~ /\/local$/     { L[$0]=1; next }
    FILENAME ~ /\/dev$/       { D[$0]=1; next }
    FILENAME ~ /\/prod$/      { P[$0]=1; next }
    FILENAME ~ /\/checklist$/ {
        k=$0; l=(k in L); d=(k in D); p=(k in P)
        if      ( d &&  p) b="1  set in dev + prod"
        else if (!d &&  p) b="2  prod only (not dev)"
        else if ( d && !p) b="3  dev only — MISSING FROM PROD"
        else if ( l && !d && !p) b="4  local only — not in dev/prod"
        else                     b="5  set NOWHERE (local/dev/prod all absent)"
        printf "%s\t%-42s L:%s D:%s P:%s\n", b, k, (l?"Y":"·"),(d?"Y":"·"),(p?"Y":"·")
    }
' "$tmp/local" "$tmp/dev" "$tmp/prod" "$tmp/checklist" \
    | sort | awk -F'\t' '{ if ($1!=last){printf "\n  ── bucket %s ──\n", $1; last=$1} print "    " $2 }'

# Platform-injected vars never appear in the env-var list, so they land in
# bucket 5 even when live. Assert those via config(), not this key diff.
echo
echo "  note: NIGHTWATCH_TOKEN is injected by the Cloud↔Nightwatch integration and is invisible"
echo "        to this key diff — bucket 5 is expected. Verify with:"
echo "        cloud tinker <env> --code='echo strlen((string) config(\"nightwatch.token\"));'"
