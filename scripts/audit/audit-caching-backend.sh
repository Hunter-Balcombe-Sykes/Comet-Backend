#!/usr/bin/env bash
# audit-caching-backend.sh — Whole-backend caching audit, scope-split, parallel.
#
# Runs every caching lens against the whole backend. The caching-relevant
# surface (~3.5 MB of PHP) is partitioned into 8 byte-balanced chunks; each
# lens is scanned against each chunk as a separate DeepSeek call, fired in
# parallel waves, then ONE Claude Sonnet adjudication merges all drafts.
#
# Two lenses run by default:
#   caching-gold-standard.md  (CCH) — existing caches: are they correct?
#   caching-coverage-gaps.md  (CCG) — hot expensive reads with no cache at all
# => 2 lenses x 8 chunks = 16 scans.
#
# Usage:
#   scripts/audit/audit-caching-backend.sh                 # both lenses
#   scripts/audit/audit-caching-backend.sh --lens gold     # adherence only
#   scripts/audit/audit-caching-backend.sh --lens gaps     # coverage gaps only
#   scripts/audit/audit-caching-backend.sh --max-parallel 6 --keep-drafts
#
# Output: audits/audit-YYYY-MM-DD-caching-full-backend.md
# Auth: DEEPSEEK_API_KEY (scripts/audit/.env) + logged-in `claude` CLI.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# --- Args ---
MAX_BUDGET="8.00"
MAX_PARALLEL=8          # concurrent DeepSeek scans per wave (rate-limit guard)
LENS_SELECT="both"      # both | gold | gaps
KEEP_DRAFTS=false
while [[ $# -gt 0 ]]; do
    case "$1" in
        --max-budget)   MAX_BUDGET="$2"; shift 2 ;;
        --max-parallel) MAX_PARALLEL="$2"; shift 2 ;;
        --lens)         LENS_SELECT="$2"; shift 2 ;;
        --keep-drafts)  KEEP_DRAFTS=true; shift ;;
        -h|--help)      sed -n '2,26p' "$0" | sed 's/^# \?//'; exit 0 ;;
        *) echo "Unknown arg: $1" >&2; exit 2 ;;
    esac
done

# --- Lenses: "shortname|prefix|path" ---
GOLD="gold|CCH|$SCRIPT_DIR/lenses/caching-gold-standard.md"
GAPS="gaps|CCG|$SCRIPT_DIR/lenses/caching-coverage-gaps.md"
case "$LENS_SELECT" in
    both) LENSES=("$GOLD" "$GAPS") ;;
    gold) LENSES=("$GOLD") ;;
    gaps) LENSES=("$GAPS") ;;
    *) echo "--lens must be: both | gold | gaps" >&2; exit 2 ;;
esac
for entry in "${LENSES[@]}"; do
    lf="${entry##*|}"
    [[ -f "$lf" ]] || { echo "Lens file missing: $lf" >&2; exit 2; }
done

# --- 8 byte-balanced chunks. Each line: "chunk-name|scope1 scope2 ..." ---
# Sizes (KB) noted for the record; rebalance if the tree grows materially.
CHUNKS=(
  "cache-infra|app/Services/Cache app/Services/FeatureFlags app/Observers app/Console app/Http/Middleware"          # ~376
  "services-prof-stripe|app/Services/Professional app/Services/Stripe"                                              # ~385
  "services-commerce|app/Services/Shopify app/Services/Store app/Services/Media app/Services/Analytics app/Services/Site app/Services/PublicSite"  # ~370
  "services-rest-models|app/Services/Square app/Services/Notifications app/Services/Fresha app/Services/Streaming app/Services/Billing app/Services/Accounts app/Services/Cloudflare app/Services/Customers app/Services/Auth app/Services/Exports app/Services/Diagnostics app/Services/Email app/Services/Audit app/Models"  # ~345
  "jobs|app/Jobs"                                                                                                   # ~370
  "ctrl-prof-a|app/Http/Controllers/Api/Professional/Brand app/Http/Controllers/Api/Professional/Analytics app/Http/Controllers/Api/Professional/Store app/Http/Controllers/Api/Professional/SiteManagement"  # ~356
  "ctrl-prof-b-staff|app/Http/Controllers/Api/Professional/Uploads app/Http/Controllers/Api/Professional/Stripe app/Http/Controllers/Api/Professional/Account app/Http/Controllers/Api/Professional/Affiliate app/Http/Controllers/Api/Professional/Notifications app/Http/Controllers/Api/Professional/Booking app/Http/Controllers/Api/Professional/SquareIntegration app/Http/Controllers/Api/Professional/FreshaIntegration app/Http/Controllers/Api/Professional/Customers app/Http/Controllers/Api/Professional/Subscription app/Http/Controllers/Api/Professional/Site app/Http/Controllers/Api/Staff"  # ~389
  "ctrl-public-internal|app/Http/Controllers/Api/Internal app/Http/Controllers/Api/PublicSite app/Http/Controllers/Api/Webhooks app/Http/Controllers/Api/Shopify app/Http/Controllers/Concerns app/Http/Controllers/Api/ApiController.php app/Http/Controllers/Api/HealthController.php"  # ~411
)

# --- Drafts dir ---
if $KEEP_DRAFTS; then
    DRAFTS_DIR="audits/.drafts/caching-$(date +%s)"
    mkdir -p "$DRAFTS_DIR"
else
    DRAFTS_DIR="$(mktemp -d)"
    trap 'rm -rf "$DRAFTS_DIR"' EXIT
fi

TOTAL=$(( ${#LENSES[@]} * ${#CHUNKS[@]} ))
echo "" >&2
echo "════════ Whole-backend caching audit — $TOTAL scans (${#LENSES[@]} lens × ${#CHUNKS[@]} chunks), ${MAX_PARALLEL}/wave + 1 adjudication ════════" >&2

# --- Run one scan in the background; appends PID + label to wave arrays ---
WAVE_PIDS=()
WAVE_LABELS=()
FAILED=()

flush_wave() {
    # wait for the current wave, record failures
    local i
    for i in "${!WAVE_PIDS[@]}"; do
        if wait "${WAVE_PIDS[$i]}"; then
            echo "  ✓ ${WAVE_LABELS[$i]}" >&2
        else
            echo "  ✗ FAILED: ${WAVE_LABELS[$i]} — see $DRAFTS_DIR/log-${WAVE_LABELS[$i]}.txt" >&2
            FAILED+=("${WAVE_LABELS[$i]}")
        fi
    done
    WAVE_PIDS=()
    WAVE_LABELS=()
}

# --- Fan out: nested lens × chunk, in waves of MAX_PARALLEL ---
for lentry in "${LENSES[@]}"; do
    lname="${lentry%%|*}"
    lpath="${lentry##*|}"
    for centry in "${CHUNKS[@]}"; do
        cname="${centry%%|*}"
        read -ra scopes <<< "${centry#*|}"
        scope_args=()
        for s in "${scopes[@]}"; do scope_args+=("--scope" "$s"); done

        label="${lname}__${cname}"
        echo "  → launching: $label" >&2
        (
            "$SCRIPT_DIR/audit-scan.sh" \
                --lens-file "$lpath" \
                "${scope_args[@]}" \
                --out "$DRAFTS_DIR/draft-${label}.md" \
                > "$DRAFTS_DIR/log-${label}.txt" 2>&1
        ) &
        WAVE_PIDS+=("$!")
        WAVE_LABELS+=("$label")

        # wave full — drain before launching more
        if [[ ${#WAVE_PIDS[@]} -ge $MAX_PARALLEL ]]; then
            flush_wave
        fi
    done
done
flush_wave  # drain the final partial wave

if [[ ${#FAILED[@]} -gt 0 ]]; then
    echo "Aborting: ${#FAILED[@]} scan(s) failed (${FAILED[*]}). No adjudication run." >&2
    $KEEP_DRAFTS || echo "(re-run with --keep-drafts to inspect partial output)" >&2
    exit 1
fi

# --- Per-lens adjudication, run in parallel ---
# Adjudication is a REDUCE (dedupe + sequential numbering + uniform tiering),
# so it cannot be split by chunk. But CCH and CCG never merge with each other
# and number independently, so one adjudicator PER LENS is safe and concurrent.
echo "" >&2
echo "════════ Final step: ${#LENSES[@]} parallel Claude adjudication(s) ════════" >&2

# Split the budget evenly across the lens adjudicators.
PER_BUDGET=$(awk "BEGIN{printf \"%.2f\", $MAX_BUDGET/${#LENSES[@]}}")

meta_lens_for() {
    case "$1" in
        gold) echo "Whole-backend caching GOLD-STANDARD ADHERENCE audit, scope-split across ${#CHUNKS[@]} byte-balanced chunks, all scanned under the SAME caching-gold-standard lens. Drafts below are concatenated, each prefixed with a <!-- LENS: gold | CHUNK: name --> marker. ONE lens — do not expect different finding categories per chunk. Use the CCH prefix; number sequentially across the whole audit. Dedupe across chunks: the same defect often spans chunks — a cached read in a controller chunk and its push-invalidation in the cache-infra chunk are ONE finding (merge, cite both files). Verify every finding against the real files via the scope before keeping it." ;;
        gaps) echo "Whole-backend caching COVERAGE-GAP audit, scope-split across ${#CHUNKS[@]} byte-balanced chunks, all scanned under the SAME caching-coverage-gaps lens. Drafts below are concatenated, each prefixed with a <!-- LENS: gaps | CHUNK: name --> marker. Use the CCG prefix; number sequentially. HOLD THE PRECISION BAR: a coverage-gap finding must be defensible as hot AND expensive AND multi-caller — drop any that is not. Dedupe across chunks. Verify every finding against the real files via the scope before keeping it." ;;
    esac
}

ADJ_PIDS=()
ADJ_LENSES=()
ADJ_OUTS=()
for lentry in "${LENSES[@]}"; do
    lname="${lentry%%|*}"
    merged="$DRAFTS_DIR/merged-${lname}.md"
    : > "$merged"
    for centry in "${CHUNKS[@]}"; do
        cname="${centry%%|*}"
        {
            echo ""
            echo "<!-- ═══ LENS: $lname | CHUNK: $cname ═══ -->"
            echo ""
            cat "$DRAFTS_DIR/draft-${lname}__${cname}.md"
        } >> "$merged"
    done
    out="$DRAFTS_DIR/audit-${lname}.md"
    echo "  → adjudicating lens '$lname' (budget \$$PER_BUDGET)" >&2
    (
        "$SCRIPT_DIR/audit-adjudicate.sh" \
            --drafts "$merged" \
            --lens "$(meta_lens_for "$lname")" \
            --scope app \
            --max-budget "$PER_BUDGET" \
            --out "$out" \
            > "$DRAFTS_DIR/adj-log-${lname}.txt" 2>&1
    ) &
    ADJ_PIDS+=("$!")
    ADJ_LENSES+=("$lname")
    ADJ_OUTS+=("$out")
done

ADJ_FAILED=()
for i in "${!ADJ_PIDS[@]}"; do
    if wait "${ADJ_PIDS[$i]}"; then
        echo "  ✓ adjudication complete: ${ADJ_LENSES[$i]}" >&2
    else
        echo "  ✗ adjudication FAILED: ${ADJ_LENSES[$i]} — see $DRAFTS_DIR/adj-log-${ADJ_LENSES[$i]}.txt" >&2
        ADJ_FAILED+=("${ADJ_LENSES[$i]}")
    fi
done
[[ ${#ADJ_FAILED[@]} -eq 0 ]] || { echo "Aborting: adjudication failed (${ADJ_FAILED[*]})." >&2; exit 1; }

# --- Assemble final output ---
mkdir -p audits
if [[ ${#LENSES[@]} -eq 1 ]]; then
    OUT="audits/audit-$(date +%F)-caching-${LENSES[0]%%|*}-backend.md"
    cp "${ADJ_OUTS[0]}" "$OUT"
else
    # Two independent audits (distinct CCH / CCG prefixes, no shared numbering)
    # concatenated under a separator — the orchestrator parses item lists, not headers.
    OUT="audits/audit-$(date +%F)-caching-full-backend.md"
    : > "$OUT"
    for i in "${!ADJ_OUTS[@]}"; do
        [[ $i -gt 0 ]] && printf '\n\n---\n\n' >> "$OUT"
        cat "${ADJ_OUTS[$i]}" >> "$OUT"
    done
fi

echo "" >&2
echo "✓ Done — $OUT" >&2
$KEEP_DRAFTS && echo "  drafts + per-lens audits kept in: $DRAFTS_DIR" >&2
