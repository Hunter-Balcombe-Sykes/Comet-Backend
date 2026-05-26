#!/usr/bin/env bash
# audit-pilot-resume.sh — resume a partially-completed run of
# audit-pilot-backend.sh. Re-scans ONLY the chunk drafts that are missing or
# empty in an existing --keep-drafts drafts dir, then runs the full per-lens
# adjudication + assembly. Use when a run aborted mid-scan (DeepSeek timeout,
# out-of-balance, etc.) but most drafts already exist.
#
# Usage:
#   scripts/audit/audit-pilot-resume.sh --drafts-dir audits/.drafts/pilot-1779343058
#   scripts/audit/audit-pilot-resume.sh --drafts-dir <dir> --max-parallel 4

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LENS_DIR="$SCRIPT_DIR/lenses"

MAX_PARALLEL=4
ADJ_PARALLEL=4
BUDGET_PER_LENS="3.00"
DRAFTS_DIR=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --drafts-dir)      DRAFTS_DIR="$2"; shift 2 ;;
        --max-parallel)    MAX_PARALLEL="$2"; shift 2 ;;
        --adj-parallel)    ADJ_PARALLEL="$2"; shift 2 ;;
        --budget-per-lens) BUDGET_PER_LENS="$2"; shift 2 ;;
        *) echo "Unknown arg: $1" >&2; exit 2 ;;
    esac
done
[[ -n "$DRAFTS_DIR" && -d "$DRAFTS_DIR" ]] || { echo "Missing/invalid --drafts-dir" >&2; exit 2; }

# Catalog — kept in sync with audit-pilot-backend.sh
CHUNKS=(
  "infra|app/Services/Cache app/Services/FeatureFlags app/Observers app/Listeners app/Events app/Console"
  "svc-prof-stripe|app/Services/Professional app/Services/Stripe"
  "svc-commerce|app/Services/Shopify app/Services/Store app/Services/Media app/Services/Analytics app/Services/Site app/Services/PublicSite"
  "svc-rest-models|app/Services/Square app/Services/Notifications app/Services/Fresha app/Services/Streaming app/Services/Billing app/Services/Accounts app/Services/Cloudflare app/Services/Customers app/Services/Auth app/Services/Exports app/Services/Diagnostics app/Services/Email app/Services/Audit app/Models app/Policies app/Providers app/Enums app/Exceptions app/Mail app/Rules app/Support"
  "jobs|app/Jobs"
  "ctrl-prof-a|app/Http/Controllers/Api/Professional/Brand app/Http/Controllers/Api/Professional/Analytics app/Http/Controllers/Api/Professional/Store app/Http/Controllers/Api/Professional/SiteManagement"
  "ctrl-prof-b-staff|app/Http/Controllers/Api/Professional/Uploads app/Http/Controllers/Api/Professional/Stripe app/Http/Controllers/Api/Professional/Account app/Http/Controllers/Api/Professional/Affiliate app/Http/Controllers/Api/Professional/Notifications app/Http/Controllers/Api/Professional/Booking app/Http/Controllers/Api/Professional/SquareIntegration app/Http/Controllers/Api/Professional/FreshaIntegration app/Http/Controllers/Api/Professional/Customers app/Http/Controllers/Api/Professional/Subscription app/Http/Controllers/Api/Professional/Site app/Http/Controllers/Api/Staff"
  "ctrl-public-internal|app/Http/Controllers/Api/Internal app/Http/Controllers/Api/PublicSite app/Http/Controllers/Api/Webhooks app/Http/Controllers/Api/Shopify app/Http/Controllers/Concerns app/Http/Controllers/Api/ApiController.php app/Http/Controllers/Api/HealthController.php"
  "http-boundary|app/Http/Requests app/Http/Resources app/Http/Middleware"
  "migrations|supabase/migrations"
  "config|config routes .env.example"
  "tc-financial|tests/Feature/Stripe tests/Feature/Commerce tests/Feature/Commission app/Services/Stripe app/Jobs/Stripe"
  "tc-webhook|tests/Feature/Webhooks tests/Feature/Shopify app/Http/Controllers/Api/Webhooks app/Jobs/Shopify"
  "tc-policy|tests/Feature/Policies tests/Feature/Auth app/Policies app/Http/Middleware"
  "tc-migration|tests/Feature/Audit supabase/migrations"
)
LENSES=(
  "security|security.md|infra svc-prof-stripe svc-commerce svc-rest-models jobs ctrl-prof-a ctrl-prof-b-staff ctrl-public-internal http-boundary config"
  "scaling|scaling-antipatterns.md|infra svc-prof-stripe svc-commerce svc-rest-models jobs ctrl-prof-a ctrl-prof-b-staff ctrl-public-internal http-boundary"
  "dbqueue|database-and-queue-scaling.md|infra svc-prof-stripe svc-commerce svc-rest-models jobs ctrl-prof-a ctrl-prof-b-staff ctrl-public-internal http-boundary migrations"
  "lifecycle|lifecycle-correctness.md|infra svc-prof-stripe svc-commerce svc-rest-models jobs ctrl-prof-a ctrl-prof-b-staff ctrl-public-internal"
  "txn|transaction-boundaries.md|infra svc-prof-stripe svc-commerce svc-rest-models jobs ctrl-prof-a ctrl-prof-b-staff ctrl-public-internal"
  "webhook|webhook-idempotency.md|svc-prof-stripe jobs ctrl-public-internal"
  "apicontract|api-contract.md|ctrl-prof-a ctrl-prof-b-staff ctrl-public-internal http-boundary"
  "confighygiene|configuration-hygiene.md|config infra svc-prof-stripe svc-commerce svc-rest-models jobs"
  "migration|migration-safety.md|migrations"
  "schemarls|schema-rls.md|migrations svc-rest-models"
  "testcoverage|test-coverage.md|tc-financial tc-webhook tc-policy tc-migration"
)

chunk_scopes() {
    local want="$1" entry
    for entry in "${CHUNKS[@]}"; do
        [[ "${entry%%|*}" == "$want" ]] && { echo "${entry#*|}"; return 0; }
    done
    echo "INTERNAL ERROR: unknown chunk '$want'" >&2; return 1
}

# ─── Find missing/empty drafts ──────────────────────────────────────────────
MISSING=()
for lentry in "${LENSES[@]}"; do
    lname="${lentry%%|*}"
    read -ra chunk_list <<< "$(echo "$lentry" | cut -d'|' -f3)"
    for cname in "${chunk_list[@]}"; do
        f="$DRAFTS_DIR/draft-${lname}__${cname}.md"
        if [[ ! -s "$f" || $(wc -c < "$f" 2>/dev/null || echo 0) -lt 200 ]]; then
            MISSING+=("${lname}|${cname}")
        fi
    done
done

echo "" >&2
echo "════════ Resume — ${#MISSING[@]} missing chunk draft(s) to re-scan ════════" >&2
for m in "${MISSING[@]+"${MISSING[@]}"}"; do echo "  · ${m/|/__}" >&2; done

# ─── Re-scan missing chunks in waves ────────────────────────────────────────
WAVE_PIDS=(); WAVE_LABELS=(); FAILED=()
flush_wave() {
    local i
    [[ ${#WAVE_PIDS[@]} -eq 0 ]] && return 0
    for i in "${!WAVE_PIDS[@]}"; do
        if wait "${WAVE_PIDS[$i]}"; then echo "  ✓ ${WAVE_LABELS[$i]}" >&2
        else echo "  ✗ FAILED: ${WAVE_LABELS[$i]} — $DRAFTS_DIR/log-${WAVE_LABELS[$i]}.txt" >&2; FAILED+=("${WAVE_LABELS[$i]}"); fi
    done
    WAVE_PIDS=(); WAVE_LABELS=()
}
for m in "${MISSING[@]+"${MISSING[@]}"}"; do
    lname="${m%%|*}"; cname="${m#*|}"
    lfile="$LENS_DIR/$(for l in "${LENSES[@]}"; do [[ "${l%%|*}" == "$lname" ]] && echo "$l" | cut -d'|' -f2; done)"
    read -ra scopes <<< "$(chunk_scopes "$cname")"
    scope_args=(); for s in "${scopes[@]}"; do scope_args+=("--scope" "$s"); done
    label="${lname}__${cname}"
    echo "  → re-scanning: $label" >&2
    (
        "$SCRIPT_DIR/audit-scan.sh" --lens-file "$lfile" "${scope_args[@]}" \
            --out "$DRAFTS_DIR/draft-${label}.md" > "$DRAFTS_DIR/log-${label}.txt" 2>&1
    ) &
    WAVE_PIDS+=("$!"); WAVE_LABELS+=("$label")
    [[ ${#WAVE_PIDS[@]} -ge $MAX_PARALLEL ]] && flush_wave
done
flush_wave
[[ ${#FAILED[@]} -eq 0 ]] || { echo "Aborting: ${#FAILED[@]} re-scan(s) failed (${FAILED[*]})." >&2; exit 1; }

# ─── Adjudicate all 11 lenses ───────────────────────────────────────────────
echo "" >&2
echo "════════ Adjudication — ${#LENSES[@]} per-lens passes ════════" >&2
ADJ_PIDS=(); ADJ_LABELS=(); ADJ_FAILED=(); ALL_OUTS=()
flush_adj() {
    local i
    [[ ${#ADJ_PIDS[@]} -eq 0 ]] && return 0
    for i in "${!ADJ_PIDS[@]}"; do
        if wait "${ADJ_PIDS[$i]}"; then echo "  ✓ adjudicated: ${ADJ_LABELS[$i]}" >&2
        else echo "  ✗ adjudication FAILED: ${ADJ_LABELS[$i]} — $DRAFTS_DIR/adj-log-${ADJ_LABELS[$i]}.txt" >&2; ADJ_FAILED+=("${ADJ_LABELS[$i]}"); fi
    done
    ADJ_PIDS=(); ADJ_LABELS=()
}
for lentry in "${LENSES[@]}"; do
    lname="${lentry%%|*}"
    read -ra chunk_list <<< "$(echo "$lentry" | cut -d'|' -f3)"
    merged="$DRAFTS_DIR/merged-${lname}.md"; : > "$merged"
    for cname in "${chunk_list[@]}"; do
        { echo ""; echo "<!-- ═══ CHUNK: $cname ═══ -->"; echo ""; cat "$DRAFTS_DIR/draft-${lname}__${cname}.md"; } >> "$merged"
    done
    meta_lens="Whole-backend PILOT audit — '${lname}' lens. The lens's relevant surface was scope-split into ${#chunk_list[@]} chunk(s), each scanned under the SAME lens; drafts below are concatenated, each prefixed with a <!-- CHUNK: name --> marker. ONE lens — use the lens's own finding-ID prefix and number sequentially across the whole audit. Dedupe across chunks: the same defect can span chunks — merge and cite all files. Verify every finding against the real files via the scope; drop scan-tier false positives and re-tier per the canonical P0-P3 bar."
    out="$DRAFTS_DIR/audit-${lname}.md"; ALL_OUTS+=("$out")
    echo "  → adjudicating: $lname (budget \$$BUDGET_PER_LENS)" >&2
    (
        "$SCRIPT_DIR/audit-adjudicate.sh" --drafts "$merged" --lens "$meta_lens" \
            --scope app --scope supabase/migrations --no-source \
            --max-budget "$BUDGET_PER_LENS" \
            --out "$out" > "$DRAFTS_DIR/adj-log-${lname}.txt" 2>&1
    ) &
    ADJ_PIDS+=("$!"); ADJ_LABELS+=("$lname")
    [[ ${#ADJ_PIDS[@]} -ge $ADJ_PARALLEL ]] && flush_adj
done
flush_adj
[[ ${#ADJ_FAILED[@]} -eq 0 ]] || { echo "Aborting: ${#ADJ_FAILED[@]} adjudication(s) failed (${ADJ_FAILED[*]})." >&2; exit 1; }

# ─── Assemble ───────────────────────────────────────────────────────────────
mkdir -p audits
OUT="audits/audit-$(date +%F)-pilot-full-backend.md"
: > "$OUT"
for i in "${!ALL_OUTS[@]}"; do
    [[ $i -gt 0 ]] && printf '\n\n---\n\n' >> "$OUT"
    cat "${ALL_OUTS[$i]}" >> "$OUT"
done
echo "" >&2
echo "✓ Done — $OUT" >&2
