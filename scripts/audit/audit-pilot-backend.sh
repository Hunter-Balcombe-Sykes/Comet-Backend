#!/usr/bin/env bash
# audit-pilot-backend.sh — Whole-backend pilot-readiness audit, 11 lenses,
# scope-split, parallel.
#
# Unlike `audit.sh --full` (every lens vs the SAME small scope), this script
# maps each lens to ONLY the surface it audits — broad lenses span all of
# app/, bounded lenses (migration-safety, schema-rls, webhook-idempotency)
# get a handful of chunks. ~65 DeepSeek scans total, fired in parallel waves,
# then ONE Claude Sonnet adjudication PER LENS (run concurrently in waves).
#
# Lenses (11): security, scaling-antipatterns, database-and-queue-scaling,
# lifecycle-correctness, schema-rls, webhook-idempotency, transaction-boundaries,
# api-contract, configuration-hygiene, migration-safety, test-coverage.
# (caching-gold-standard is intentionally excluded — see audit-caching-backend.sh)
#
# Usage:
#   scripts/audit/audit-pilot-backend.sh
#   scripts/audit/audit-pilot-backend.sh --only "security scaling schemarls"
#   scripts/audit/audit-pilot-backend.sh --max-parallel 6 --budget-per-lens 4.00 --keep-drafts
#
# This is a long run (~45-70 min). Background it: append ` &` or run via nohup.
#
# Output: audits/audit-YYYY-MM-DD-pilot-full-backend.md
# Auth: DEEPSEEK_API_KEY (scripts/audit/.env) + logged-in `claude` CLI.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LENS_DIR="$SCRIPT_DIR/lenses"

# --- Args ---
MAX_PARALLEL=8          # concurrent DeepSeek scans per wave
ADJ_PARALLEL=4          # concurrent Claude adjudications per wave (heavier)
BUDGET_PER_LENS="3.00"  # adjudicator budget per lens
ONLY=""                 # optional space-separated lens-shortname filter
KEEP_DRAFTS=false
while [[ $# -gt 0 ]]; do
    case "$1" in
        --max-parallel)   MAX_PARALLEL="$2"; shift 2 ;;
        --adj-parallel)   ADJ_PARALLEL="$2"; shift 2 ;;
        --budget-per-lens) BUDGET_PER_LENS="$2"; shift 2 ;;
        --only)           ONLY="$2"; shift 2 ;;
        --keep-drafts)    KEEP_DRAFTS=true; shift ;;
        -h|--help)        sed -n '2,29p' "$0" | sed 's/^# \?//'; exit 0 ;;
        *) echo "Unknown arg: $1" >&2; exit 2 ;;
    esac
done

# ─── Chunk catalog — "name|scope1 scope2 ..." ───────────────────────────────
# 11 backend chunks (~350-460 KB each) cover the whole backend except tests/;
# 4 tc-* chunks pair tests/ with the app code under test (test-coverage only).
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

# ─── Lens registry — "shortname|lens-file|chunk chunk ..." ──────────────────
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

# --- Apply --only filter ---
if [[ -n "$ONLY" ]]; then
    filtered=()
    for lentry in "${LENSES[@]}"; do
        sn="${lentry%%|*}"
        for want in $ONLY; do
            [[ "$sn" == "$want" ]] && filtered+=("$lentry")
        done
    done
    [[ ${#filtered[@]} -gt 0 ]] || { echo "--only matched no lenses. Valid: security scaling dbqueue lifecycle txn webhook apicontract confighygiene migration schemarls testcoverage" >&2; exit 2; }
    LENSES=("${filtered[@]}")
fi

# --- Verify lens files exist before any expensive work ---
for lentry in "${LENSES[@]}"; do
    lf="$LENS_DIR/$(echo "$lentry" | cut -d'|' -f2)"
    [[ -f "$lf" ]] || { echo "Lens file missing: $lf" >&2; exit 2; }
done

# --- Look up a chunk's scopes by name ---
chunk_scopes() {
    local want="$1" entry
    for entry in "${CHUNKS[@]}"; do
        if [[ "${entry%%|*}" == "$want" ]]; then
            echo "${entry#*|}"
            return 0
        fi
    done
    echo "INTERNAL ERROR: unknown chunk '$want'" >&2
    return 1
}

# --- Drafts dir ---
if $KEEP_DRAFTS; then
    DRAFTS_DIR="audits/.drafts/pilot-$(date +%s)"
    mkdir -p "$DRAFTS_DIR"
else
    DRAFTS_DIR="$(mktemp -d)"
    trap 'rm -rf "$DRAFTS_DIR"' EXIT
fi

# --- Count total scans ---
TOTAL=0
for lentry in "${LENSES[@]}"; do
    read -ra cl <<< "$(echo "$lentry" | cut -d'|' -f3)"
    TOTAL=$(( TOTAL + ${#cl[@]} ))
done

echo "" >&2
echo "════════ Whole-backend PILOT audit — ${#LENSES[@]} lenses, $TOTAL scans, ${MAX_PARALLEL}/wave ════════" >&2
echo "  (long run — background it if you need the terminal)" >&2

# ─── Scan phase: fan out (lens × chunk) in waves of MAX_PARALLEL ────────────
WAVE_PIDS=(); WAVE_LABELS=(); FAILED=()
flush_scan_wave() {
    local i
    for i in "${!WAVE_PIDS[@]}"; do
        if wait "${WAVE_PIDS[$i]}"; then
            echo "  ✓ ${WAVE_LABELS[$i]}" >&2
        else
            echo "  ✗ FAILED: ${WAVE_LABELS[$i]} — $DRAFTS_DIR/log-${WAVE_LABELS[$i]}.txt" >&2
            FAILED+=("${WAVE_LABELS[$i]}")
        fi
    done
    WAVE_PIDS=(); WAVE_LABELS=()
}

for lentry in "${LENSES[@]}"; do
    lname="${lentry%%|*}"
    lfile="$LENS_DIR/$(echo "$lentry" | cut -d'|' -f2)"
    read -ra chunk_list <<< "$(echo "$lentry" | cut -d'|' -f3)"
    for cname in "${chunk_list[@]}"; do
        read -ra scopes <<< "$(chunk_scopes "$cname")"
        scope_args=()
        for s in "${scopes[@]}"; do scope_args+=("--scope" "$s"); done
        label="${lname}__${cname}"
        echo "  → launching: $label" >&2
        (
            "$SCRIPT_DIR/audit-scan.sh" \
                --lens-file "$lfile" \
                "${scope_args[@]}" \
                --out "$DRAFTS_DIR/draft-${label}.md" \
                > "$DRAFTS_DIR/log-${label}.txt" 2>&1
        ) &
        WAVE_PIDS+=("$!"); WAVE_LABELS+=("$label")
        [[ ${#WAVE_PIDS[@]} -ge $MAX_PARALLEL ]] && flush_scan_wave
    done
done
flush_scan_wave

if [[ ${#FAILED[@]} -gt 0 ]]; then
    echo "Aborting: ${#FAILED[@]} scan(s) failed (${FAILED[*]}). No adjudication run." >&2
    $KEEP_DRAFTS || echo "(re-run with --keep-drafts to inspect partial output)" >&2
    exit 1
fi

# ─── Adjudication phase: one Claude adjudication PER LENS, in waves ─────────
# Adjudication is a reduce (dedupe + numbering + tiering) so it splits at the
# lens boundary and no finer — each lens's chunks all land in one adjudicator.
echo "" >&2
echo "════════ Adjudication — ${#LENSES[@]} per-lens Claude passes, ${ADJ_PARALLEL}/wave ════════" >&2

ADJ_PIDS=(); ADJ_LABELS=(); ADJ_OUTS=(); ADJ_FAILED=()
flush_adj_wave() {
    local i
    for i in "${!ADJ_PIDS[@]}"; do
        if wait "${ADJ_PIDS[$i]}"; then
            echo "  ✓ adjudicated: ${ADJ_LABELS[$i]}" >&2
        else
            echo "  ✗ adjudication FAILED: ${ADJ_LABELS[$i]} — $DRAFTS_DIR/adj-log-${ADJ_LABELS[$i]}.txt" >&2
            ADJ_FAILED+=("${ADJ_LABELS[$i]}")
        fi
    done
    ADJ_PIDS=(); ADJ_LABELS=()
}

ALL_OUTS=()
for lentry in "${LENSES[@]}"; do
    lname="${lentry%%|*}"
    read -ra chunk_list <<< "$(echo "$lentry" | cut -d'|' -f3)"

    # merge this lens's chunk-drafts
    merged="$DRAFTS_DIR/merged-${lname}.md"
    : > "$merged"
    for cname in "${chunk_list[@]}"; do
        {
            echo ""
            echo "<!-- ═══ CHUNK: $cname ═══ -->"
            echo ""
            cat "$DRAFTS_DIR/draft-${lname}__${cname}.md"
        } >> "$merged"
    done

    meta_lens="Whole-backend PILOT audit — '${lname}' lens. The lens's relevant surface was scope-split into ${#chunk_list[@]} chunk(s), each scanned under the SAME lens; drafts below are concatenated, each prefixed with a <!-- CHUNK: name --> marker. ONE lens — use the lens's own finding-ID prefix and number sequentially across the whole audit. Dedupe across chunks: the same defect can span chunks — merge and cite all files. Verify every finding against the real files via the scope; drop scan-tier false positives and re-tier per the canonical P0-P3 bar."

    out="$DRAFTS_DIR/audit-${lname}.md"
    ADJ_OUTS+=("$out"); ALL_OUTS+=("$out")
    echo "  → adjudicating: $lname (budget \$$BUDGET_PER_LENS)" >&2
    (
        "$SCRIPT_DIR/audit-adjudicate.sh" \
            --drafts "$merged" \
            --lens "$meta_lens" \
            --scope app --scope supabase/migrations \
            --max-budget "$BUDGET_PER_LENS" \
            --out "$out" \
            > "$DRAFTS_DIR/adj-log-${lname}.txt" 2>&1
    ) &
    ADJ_PIDS+=("$!"); ADJ_LABELS+=("$lname")
    [[ ${#ADJ_PIDS[@]} -ge $ADJ_PARALLEL ]] && flush_adj_wave
done
flush_adj_wave

[[ ${#ADJ_FAILED[@]} -eq 0 ]] || { echo "Aborting: ${#ADJ_FAILED[@]} adjudication(s) failed (${ADJ_FAILED[*]})." >&2; exit 1; }

# ─── Assemble — concatenate all per-lens audits (distinct prefixes) ─────────
mkdir -p audits
OUT="audits/audit-$(date +%F)-pilot-full-backend.md"
: > "$OUT"
for i in "${!ALL_OUTS[@]}"; do
    [[ $i -gt 0 ]] && printf '\n\n---\n\n' >> "$OUT"
    cat "${ALL_OUTS[$i]}" >> "$OUT"
done

echo "" >&2
echo "✓ Done — $OUT" >&2
$KEEP_DRAFTS && echo "  drafts + per-lens audits kept in: $DRAFTS_DIR" >&2
