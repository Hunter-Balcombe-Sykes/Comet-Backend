#!/usr/bin/env bash
# Shared: normalize every scanner's raw output to stable {key, severity}
# lines, subtract the committed baseline, emit $OUTDIR/new-findings.txt,
# and decide the exit code. Called once per run.sh invocation (both
# lanes) after the lane's own scripts have produced their artifacts.
#
# Usage: diff-baseline.sh OUTDIR FAIL_ON [--update-baseline]
#
# Stable keys (plan Phase 7 Step 1):
#   ZAP (active + passive): alertRef + url (query string stripped)
#   Nuclei:                 template-id @ matched-at
#   wcvs:                   technique @ url (wcvs has no template-id/
#                            severity field per-finding — every confirmed
#                            finding is treated as "high", matching that
#                            a confirmed cache poisoning/deception is
#                            inherently significant, not a graded scale)
#
# Deliberately bash-3.2-compatible (no `${var,,}`, no `declare -A`) —
# macOS ships bash 3.2 as `/usr/bin/env bash` (GPLv2-licensing holdover);
# both are bash-4+-only and were caught only by actually running this
# script on this machine, not by writing/reading it. lowercase() below
# and a sort|uniq -c tally replace them.
SEVERITY_ORDER="info low medium high critical"

set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DAST_DIR="$HERE"
# shellcheck source=./common.sh
source "$HERE/lib/common.sh"

OUTDIR="$(dast_abspath "${1:?usage: diff-baseline.sh OUTDIR FAIL_ON [--update-baseline]}")"
FAIL_ON="${2:?usage: diff-baseline.sh OUTDIR FAIL_ON [--update-baseline]}"
UPDATE_BASELINE=0
[[ "${3:-}" == "--update-baseline" ]] && UPDATE_BASELINE=1

BASELINE_DIR="$HERE/baseline"
SCANNED="$OUTDIR/.scanned.keys"
: > "$SCANNED"

lowercase() { tr '[:upper:]' '[:lower:]' <<<"$1"; }

# severity_rank <sev> — echoes 0-4 (info..critical), unknowns rank as info.
severity_rank() {
    local sev i=0
    sev="$(lowercase "$1")"
    for s in $SEVERITY_ORDER; do
        [[ "$s" == "$sev" ]] && { echo "$i"; return; }
        i=$((i + 1))
    done
    echo 0
}
FAIL_ON_RANK="$(severity_rank "$FAIL_ON")"

# --- Nuclei: template-id @ matched-at, severity from info.severity.
if [[ -f "$OUTDIR/nuclei.jsonl" && -s "$OUTDIR/nuclei.jsonl" ]]; then
    jq -r '[."template-id", ."matched-at", (.info.severity // "info")] | @tsv' "$OUTDIR/nuclei.jsonl" \
        | while IFS=$'\t' read -r tid matched sev; do
            printf 'nuclei\t%s@%s\t%s\n' "$tid" "$matched" "$sev"
        done >> "$SCANNED"
fi

# --- wcvs: technique @ url, fixed "high" severity (see header comment).
if [[ -f "$OUTDIR/wcvs-report.json" && -s "$OUTDIR/wcvs-report.json" ]]; then
    jq -r '.websites[] | .url as $u | .results[] | select(.isVulnerable == true) | "\(.technique)@\($u)"' \
        "$OUTDIR/wcvs-report.json" 2>/dev/null \
        | while IFS= read -r key; do
            printf 'wcvs\t%s\thigh\n' "$key"
        done >> "$SCANNED"
fi

# --- ZAP (active scan output, edge lane never produces this file).
if [[ -f "$OUTDIR/zap/zap-report.json" && -s "$OUTDIR/zap/zap-report.json" ]]; then
    jq -r '.site[]?.alerts[]? | .alertRef as $ref | (.riskdesc // "Info" | split(" ")[0] | ascii_downcase) as $sev | .instances[]? | "\($ref)\t\(.uri | sub("\\?.*$";""))\t\($sev)"' \
        "$OUTDIR/zap/zap-report.json" 2>/dev/null \
        | while IFS=$'\t' read -r ref url sev; do
            printf 'zap\t%s@%s\t%s\n' "$ref" "$url" "$sev"
        done >> "$SCANNED"
fi

# --- ZAP passive baseline (edge lane only).
if [[ -f "$OUTDIR/zap-baseline-passive.json" && -s "$OUTDIR/zap-baseline-passive.json" ]]; then
    jq -r '.site[]?.alerts[]? | .alertRef as $ref | (.riskdesc // "Info" | split(" ")[0] | ascii_downcase) as $sev | .instances[]? | "\($ref)\t\(.uri | sub("\\?.*$";""))\t\($sev)"' \
        "$OUTDIR/zap-baseline-passive.json" 2>/dev/null \
        | while IFS=$'\t' read -r ref url sev; do
            printf 'zap-passive\t%s@%s\t%s\n' "$ref" "$url" "$sev"
        done >> "$SCANNED"
fi

: > "$OUTDIR/new-findings.txt"

while IFS=$'\t' read -r scanner key sev; do
    [[ -z "$scanner" ]] && continue
    baseline_file=""
    case "$scanner" in
        nuclei|wcvs) baseline_file="$BASELINE_DIR/nuclei-baseline.txt" ;;
        zap) baseline_file="$BASELINE_DIR/zap-baseline.json" ;;
        zap-passive) baseline_file="$BASELINE_DIR/zap-passive-baseline.json" ;;
    esac
    if [[ "$scanner" == "nuclei" || "$scanner" == "wcvs" ]]; then
        # nuclei-baseline.txt is a plain-text, header-commented key list
        # (plan Phase 7 Step 3) — not JSON like the ZAP baselines.
        if [[ -f "$baseline_file" ]] && grep -qxF "$key" "$baseline_file" 2>/dev/null; then
            continue
        fi
    else
        if [[ -f "$baseline_file" ]] && jq -e --arg k "$key" 'index($k) != null' "$baseline_file" >/dev/null 2>&1; then
            continue
        fi
    fi
    printf '%s\t%s\t%s\n' "$scanner" "$key" "$sev" >> "$OUTDIR/new-findings.txt"
done < "$SCANNED"

severity_counts="$(cut -f3 "$OUTDIR/new-findings.txt" | tr '[:upper:]' '[:lower:]' | sort | uniq -c | awk '{printf "%s=%d ", $2, $1}')"
log "diff-baseline: severity counts — ${severity_counts:-<none>}"

if [[ "$UPDATE_BASELINE" -eq 1 ]]; then
    while IFS=$'\t' read -r scanner key sev; do
        [[ -z "$scanner" ]] && continue
        case "$scanner" in
            nuclei|wcvs)
                echo "$key" >> "$BASELINE_DIR/nuclei-baseline.txt"
                ;;
            zap)
                jq --arg k "$key" '. + [$k]' "$BASELINE_DIR/zap-baseline.json" > "$BASELINE_DIR/zap-baseline.json.tmp"
                mv "$BASELINE_DIR/zap-baseline.json.tmp" "$BASELINE_DIR/zap-baseline.json"
                ;;
            zap-passive)
                jq --arg k "$key" '. + [$k]' "$BASELINE_DIR/zap-passive-baseline.json" > "$BASELINE_DIR/zap-passive-baseline.json.tmp"
                mv "$BASELINE_DIR/zap-passive-baseline.json.tmp" "$BASELINE_DIR/zap-passive-baseline.json"
                ;;
        esac
    done < "$OUTDIR/new-findings.txt"
    log "diff-baseline: --update-baseline applied $(wc -l < "$OUTDIR/new-findings.txt" | tr -d ' ') keys"
fi

rm -f "$SCANNED"

new_at_or_above=0
while IFS=$'\t' read -r _scanner _key sev; do
    [[ "$(severity_rank "$sev")" -ge "$FAIL_ON_RANK" ]] && new_at_or_above=$((new_at_or_above + 1))
done < "$OUTDIR/new-findings.txt"

if [[ "$new_at_or_above" -gt 0 ]]; then
    log "diff-baseline: $new_at_or_above NEW finding(s) at/above --fail-on=$FAIL_ON — see $OUTDIR/new-findings.txt"
    exit 2
fi
log "diff-baseline: clean — no new findings at/above --fail-on=$FAIL_ON"
exit 0
