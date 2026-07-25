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

pg_query() {
    curl -s --max-time 30 -X POST "https://api.supabase.com/v1/projects/$REF/database/query" \
        -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
        -d "$(jq -n --arg q "$1" '{query: $q}')"
}

# --- 1. RLS enabled on every table in the app schemas ---
RLS_OFF=$(pg_query "SELECT schemaname || '.' || tablename AS t FROM pg_tables
    WHERE schemaname IN ('core','site','notifications','analytics','audit','moderation')
    AND rowsecurity = false ORDER BY 1" | jq -r '.[].t' 2>/dev/null)
if [[ -z "$RLS_OFF" ]]; then
    pass "RLS enabled on all app-schema tables"
else
    while IFS= read -r t; do fail "RLS DISABLED: $t"; done <<< "$RLS_OFF"
fi

# --- 2. Supabase security advisors (server-side lint) ---
ADVISORS=$(curl -s --max-time 30 "https://api.supabase.com/v1/projects/$REF/advisors/security" \
    -H "Authorization: Bearer $TOKEN")
if echo "$ADVISORS" | jq -e '.lints' >/dev/null 2>&1; then
    COUNT=$(echo "$ADVISORS" | jq '[.lints[] | select(.level == "ERROR" or .level == "WARN")] | length')
    if [[ "$COUNT" == "0" ]]; then
        pass "security advisors clean"
    else
        fail "security advisors report $COUNT issue(s):"
        echo "$ADVISORS" | jq -r '.lints[] | select(.level == "ERROR" or .level == "WARN") | "      [\(.level)] \(.title): \(.detail // "" | .[0:120])"'
    fi
else
    warn "advisors endpoint unavailable (HTTP shape unexpected) — check manually via Supabase MCP get_advisors"
fi

# --- 3. Snapshot staleness: latest repo migration vs snapshot's recorded one ---
LATEST_REPO=$(ls ../../supabase/migrations/2*.sql 2>/dev/null || ls "$DIR/../../supabase/migrations/"2*.sql)
LATEST_REPO=$(basename "$(echo "$LATEST_REPO" | sort | tail -1)" | cut -d_ -f1)
SNAP=$(jq -r '.latest_migration' "$DIR/schema-snapshot.json" 2>/dev/null || echo "missing")
if [[ "$SNAP" == "$LATEST_REPO" ]]; then
    pass "schema snapshot current (migration $SNAP)"
else
    warn "schema snapshot at $SNAP but repo has $LATEST_REPO — run refresh-schema-snapshot.php (and 'supabase db push' if the migration isn't applied yet)"
fi

echo
if [[ $FAILS -gt 0 ]]; then echo "SUPABASE-CHECK: $FAILS failure(s)"; exit 1; fi
echo "SUPABASE-CHECK: passed"
