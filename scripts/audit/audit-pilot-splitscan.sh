#!/usr/bin/env bash
# audit-pilot-splitscan.sh — one-off recovery: re-scan the 8 oversized pilot
# chunks that DeepSeek can't schedule (900s start-timeout) by splitting each
# into smaller sub-chunk payloads, then concatenating the sub-drafts back into
# the canonical draft-<lens>__<chunk>.md file the resume script expects.
#
# Usage:
#   scripts/audit/audit-pilot-splitscan.sh --drafts-dir audits/.drafts/pilot-1779343058

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LENS_DIR="$SCRIPT_DIR/lenses"

MAX_PARALLEL=4
DRAFTS_DIR=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --drafts-dir)   DRAFTS_DIR="$2"; shift 2 ;;
        --max-parallel) MAX_PARALLEL="$2"; shift 2 ;;
        *) echo "Unknown arg: $1" >&2; exit 2 ;;
    esac
done
[[ -n "$DRAFTS_DIR" && -d "$DRAFTS_DIR" ]] || { echo "Missing/invalid --drafts-dir" >&2; exit 2; }

# Sub-chunk catalog — "lens|chunk|subname|lensfile|scope scope ..."
SUBCHUNKS=(
  "security|http-boundary|s1|security.md|app/Http/Requests"
  "security|http-boundary|s2|security.md|app/Http/Resources app/Http/Middleware"
  "security|config|s1|security.md|config"
  "security|config|s2|security.md|routes .env.example"
  "scaling|infra|s1|scaling-antipatterns.md|app/Services/Cache app/Services/FeatureFlags app/Listeners app/Events"
  "scaling|infra|s2|scaling-antipatterns.md|app/Observers app/Console"
  "scaling|svc-prof-stripe|s1|scaling-antipatterns.md|app/Services/Professional"
  "scaling|svc-prof-stripe|s2|scaling-antipatterns.md|app/Services/Stripe"
  "scaling|svc-commerce|s1|scaling-antipatterns.md|app/Services/Shopify app/Services/Store"
  "scaling|svc-commerce|s2|scaling-antipatterns.md|app/Services/Media app/Services/Analytics app/Services/Site app/Services/PublicSite"
  "scaling|svc-rest-models|s1|scaling-antipatterns.md|app/Services/Square app/Services/Notifications app/Services/Fresha app/Services/Streaming app/Services/Billing app/Services/Accounts app/Services/Cloudflare app/Services/Customers app/Services/Auth app/Services/Exports app/Services/Diagnostics app/Services/Email app/Services/Audit"
  "scaling|svc-rest-models|s2|scaling-antipatterns.md|app/Models"
  "scaling|svc-rest-models|s3|scaling-antipatterns.md|app/Policies app/Providers app/Enums app/Exceptions app/Mail app/Rules app/Support"
  "scaling|jobs|s1|scaling-antipatterns.md|app/Jobs/Shopify"
  "scaling|jobs|s2|scaling-antipatterns.md|app/Jobs/Cache app/Jobs/Cloudflare app/Jobs/Concerns app/Jobs/Exports app/Jobs/Fresha app/Jobs/Gdpr app/Jobs/Notifications app/Jobs/Square app/Jobs/Store app/Jobs/Streaming app/Jobs/Stripe app/Jobs/DeleteMediaArtifactsJob.php app/Jobs/ProcessImageVariantsJob.php app/Jobs/ProcessVideoVariantsJob.php"
  "scaling|ctrl-prof-a|s1|scaling-antipatterns.md|app/Http/Controllers/Api/Professional/Brand app/Http/Controllers/Api/Professional/SiteManagement"
  "scaling|ctrl-prof-a|s2|scaling-antipatterns.md|app/Http/Controllers/Api/Professional/Analytics app/Http/Controllers/Api/Professional/Store"
)

echo "" >&2
echo "════════ Split-scan — ${#SUBCHUNKS[@]} sub-chunks, ${MAX_PARALLEL}/wave ════════" >&2

PIDS=(); LABELS=(); FAILED=()
flush() {
    local i
    for i in "${!PIDS[@]}"; do
        if wait "${PIDS[$i]}"; then echo "  ✓ ${LABELS[$i]}" >&2
        else echo "  ✗ FAILED: ${LABELS[$i]} — $DRAFTS_DIR/log-sub-${LABELS[$i]}.txt" >&2; FAILED+=("${LABELS[$i]}"); fi
    done
    PIDS=(); LABELS=()
}
for entry in "${SUBCHUNKS[@]}"; do
    IFS='|' read -r lname cname sname lfile scopes <<< "$entry"
    sub_draft="$DRAFTS_DIR/draft-${lname}__${cname}__${sname}.md"
    # skip if already done
    if [[ -s "$sub_draft" && $(wc -c < "$sub_draft") -ge 200 ]]; then
        echo "  · skip (done): ${lname}__${cname}__${sname}" >&2
        continue
    fi
    scope_args=(); for s in $scopes; do scope_args+=("--scope" "$s"); done
    label="${lname}__${cname}__${sname}"
    echo "  → scanning: $label" >&2
    (
        "$SCRIPT_DIR/audit-scan.sh" --lens-file "$LENS_DIR/$lfile" "${scope_args[@]}" \
            --out "$sub_draft" > "$DRAFTS_DIR/log-sub-${label}.txt" 2>&1
    ) &
    PIDS+=("$!"); LABELS+=("$label")
    [[ ${#PIDS[@]} -ge $MAX_PARALLEL ]] && flush
done
flush
[[ ${#FAILED[@]} -eq 0 ]] || { echo "Aborting: ${#FAILED[@]} sub-scan(s) failed (${FAILED[*]}). Re-run to retry." >&2; exit 1; }

# ─── Concatenate sub-drafts into canonical draft-<lens>__<chunk>.md ──────────
echo "" >&2
echo "════════ Stitching sub-drafts into canonical chunk drafts ════════" >&2
seen=""
for entry in "${SUBCHUNKS[@]}"; do
    IFS='|' read -r lname cname sname lfile scopes <<< "$entry"
    key="${lname}__${cname}"
    case " $seen " in *" $key "*) continue ;; esac
    seen="$seen $key"
    canonical="$DRAFTS_DIR/draft-${key}.md"
    : > "$canonical"
    for e2 in "${SUBCHUNKS[@]}"; do
        IFS='|' read -r l2 c2 s2 lf2 sc2 <<< "$e2"
        [[ "${l2}__${c2}" == "$key" ]] || continue
        { echo ""; echo "<!-- ═══ SUB-CHUNK: $s2 ($sc2) ═══ -->"; echo ""; cat "$DRAFTS_DIR/draft-${key}__${s2}.md"; } >> "$canonical"
    done
    echo "  ✓ stitched: $canonical" >&2
done
echo "" >&2
echo "✓ All 8 chunk drafts rebuilt. Now run:" >&2
echo "  scripts/audit/audit-pilot-resume.sh --drafts-dir $DRAFTS_DIR" >&2
