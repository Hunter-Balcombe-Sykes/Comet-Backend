#!/usr/bin/env bash
# Supabase project-level checks the code audit can never see:
# RLS actually enabled, server-side security advisors, snapshot freshness.
# Targets the DEV project (the de-facto live DB). Requires SUPABASE_ACCESS_TOKEN.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REF="glncumufgaqcmqhzwrxm" # dev ONLY
TOKEN="${SUPABASE_ACCESS_TOKEN:-}"
[[ -z "$TOKEN" && -f "$DIR/.env" ]] && TOKEN=$(grep '^SUPABASE_ACCESS_TOKEN=' "$DIR/.env" | cut -d= -f2-)
[[ -z "$TOKEN" ]] && { echo "FAIL  SUPABASE_ACCESS_TOKEN missing (scripts/launch-check/.env)"; exit 1; }

FAILS=0
pass() { echo "PASS  $1"; }
fail() { echo "FAIL  $1"; FAILS=$((FAILS + 1)); }
warn() { echo "WARN  $1"; }

# A "000"/empty status means curl never got a response at all (DNS, TLS, timeout, refused).
is_unreachable() { [[ -z "$1" || "$1" == "000" ]]; }
is_success() { [[ "$1" == 2* ]]; }

# --- 1. RLS enabled on every table in the app schemas ---
rls_resp=$(curl -s --max-time 30 -w $'\n%{http_code}' -X POST \
    "https://api.supabase.com/v1/projects/$REF/database/query" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
    -d "$(jq -n --arg q "SELECT schemaname || '.' || tablename AS t FROM pg_tables
        WHERE schemaname IN ('core','site','notifications','analytics','audit','moderation')
        AND rowsecurity = false ORDER BY 1" '{query: $q}')")
rls_curl_exit=$?
rls_code="${rls_resp##*$'\n'}"
rls_body="${rls_resp%$'\n'*}"
if [[ $rls_curl_exit -ne 0 ]] || is_unreachable "$rls_code"; then
    fail "could not verify RLS coverage — API unreachable (curl exit $rls_curl_exit, status ${rls_code:-none})"
elif ! is_success "$rls_code"; then
    fail "could not verify RLS coverage — API returned $rls_code: $(echo "$rls_body" | tr -d '\n' | cut -c1-120)"
elif ! echo "$rls_body" | jq -e 'type == "array"' >/dev/null 2>&1; then
    fail "could not verify RLS coverage — unexpected response shape (status $rls_code): $(echo "$rls_body" | tr -d '\n' | cut -c1-120)"
else
    RLS_OFF=$(echo "$rls_body" | jq -r '.[].t')
    if [[ -z "$RLS_OFF" ]]; then
        pass "RLS enabled on all app-schema tables"
    else
        while IFS= read -r t; do fail "RLS DISABLED: $t"; done <<< "$RLS_OFF"
    fi
fi

# --- 2. Supabase security advisors (server-side lint) ---
# Severity gate is a deliberate, human-approved deviation from the plan's literal code:
# ERROR advisors FAIL the run; WARN advisors are surfaced loudly but do NOT gate exit code
# (dev currently carries 2 legitimate WARNs — gating on them would train operators to ignore
# the probe). An unreachable/unauthenticated/malformed advisors call is a hard FAIL either way
# — "could not verify" must never read as "verified clean".
adv_resp=$(curl -s --max-time 30 -w $'\n%{http_code}' \
    "https://api.supabase.com/v1/projects/$REF/advisors/security" \
    -H "Authorization: Bearer $TOKEN")
adv_curl_exit=$?
adv_code="${adv_resp##*$'\n'}"
adv_body="${adv_resp%$'\n'*}"
if [[ $adv_curl_exit -ne 0 ]] || is_unreachable "$adv_code"; then
    fail "could not verify security advisors — API unreachable (curl exit $adv_curl_exit, status ${adv_code:-none})"
elif ! is_success "$adv_code"; then
    fail "could not verify security advisors — API returned $adv_code: $(echo "$adv_body" | tr -d '\n' | cut -c1-120)"
elif ! echo "$adv_body" | jq -e '.lints' >/dev/null 2>&1; then
    fail "could not verify security advisors — response missing .lints (status $adv_code): $(echo "$adv_body" | tr -d '\n' | cut -c1-120)"
else
    ERROR_COUNT=$(echo "$adv_body" | jq '[.lints[] | select(.level == "ERROR")] | length')
    if [[ "$ERROR_COUNT" == "0" ]]; then
        pass "security advisors: no ERROR-level issues"
    else
        fail "security advisors report $ERROR_COUNT ERROR-level issue(s):"
        echo "$adv_body" | jq -r '.lints[] | select(.level == "ERROR") | "      [\(.level)] \(.title): \(.detail // "" | .[0:160])"'
    fi
    WARN_LINES=$(echo "$adv_body" | jq -r '.lints[] | select(.level == "WARN") | "\(.title): \(.detail // "" | .[0:160])"')
    if [[ -n "$WARN_LINES" ]]; then
        while IFS= read -r line; do warn "advisor: $line"; done <<< "$WARN_LINES"
    fi
fi

# --- 3. Snapshot staleness: latest repo migration vs snapshot's recorded one ---
# $DIR-anchored, like §4 below — a relative `../../` glob resolved against the
# caller's CWD, so running this from anywhere but scripts/launch-check/ silently
# picked a different (or empty) migration set.
LATEST_REPO=$(ls "$DIR/../../supabase/migrations/"2*.sql 2>/dev/null)
LATEST_REPO=$(basename "$(echo "$LATEST_REPO" | sort | tail -1)" | cut -d_ -f1)
SNAP=$(jq -r '.latest_migration' "$DIR/schema-snapshot.json" 2>/dev/null || echo "missing")
if [[ "$SNAP" == "$LATEST_REPO" ]]; then
    pass "schema snapshot current (migration $SNAP)"
else
    warn "schema snapshot at $SNAP but repo has $LATEST_REPO — run refresh-schema-snapshot.php (and 'supabase db push' if the migration isn't applied yet)"
fi

# --- 4. Migration set-diff: repo files vs applied versions (both directions) ---
# Snapshot staleness (§3) only compares the LATEST filename; this catches drift
# anywhere in the set — a repo migration never applied to dev, or a migration
# applied directly to dev that never made it into the repo (see the drift
# reconcile runbook: reference_supabase_migration_drift).
mset_resp=$(curl -s --max-time 30 -w $'\n%{http_code}' -X POST \
    "https://api.supabase.com/v1/projects/$REF/database/query" \
    -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
    -d "$(jq -n --arg q "SELECT version FROM supabase_migrations.schema_migrations ORDER BY 1" '{query: $q}')")
mset_curl_exit=$?
mset_code="${mset_resp##*$'\n'}"
mset_body="${mset_resp%$'\n'*}"
if [[ $mset_curl_exit -ne 0 ]] || is_unreachable "$mset_code"; then
    fail "could not verify migration set-diff — API unreachable (curl exit $mset_curl_exit, status ${mset_code:-none})"
elif ! is_success "$mset_code"; then
    fail "could not verify migration set-diff — API returned $mset_code: $(echo "$mset_body" | tr -d '\n' | cut -c1-120)"
elif ! echo "$mset_body" | jq -e 'type == "array"' >/dev/null 2>&1; then
    fail "could not verify migration set-diff — unexpected response shape (status $mset_code): $(echo "$mset_body" | tr -d '\n' | cut -c1-120)"
else
    REPO_VERSIONS="$(ls "$DIR/../../supabase/migrations/"*.sql 2>/dev/null | xargs -n1 basename 2>/dev/null \
        | grep -oE '^[0-9]+' | sort -u)"
    APPLIED="$(echo "$mset_body" | jq -r '.[].version' 2>/dev/null | sort -u)"
    if [[ -z "$REPO_VERSIONS" ]]; then
        fail "could not verify migration set-diff — no repo migration files found under supabase/migrations/"
    else
        only_repo="$(comm -23 <(echo "$REPO_VERSIONS") <(echo "$APPLIED"))"
        only_db="$(comm -13 <(echo "$REPO_VERSIONS") <(echo "$APPLIED"))"
        if [[ -z "$only_repo" ]]; then
            pass "every repo migration is applied on dev"
        else
            fail "repo migrations NOT applied on dev:"
            echo "$only_repo" | sed 's/^/        /'
        fi
        if [[ -z "$only_db" ]]; then
            pass "no applied-but-unversioned migrations on dev"
        else
            warn "applied on dev but absent from repo (direct-to-Supabase drift): $(echo "$only_db" | tr '\n' ' ')"
        fi
    fi
fi

echo
if [[ $FAILS -gt 0 ]]; then echo "SUPABASE-CHECK: $FAILS failure(s)"; exit 1; fi
echo "SUPABASE-CHECK: passed"
