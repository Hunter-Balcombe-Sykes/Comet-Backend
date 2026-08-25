#!/usr/bin/env bash
# Runs the 2026-08-25 audit-fix tranches back-to-back, unattended.
#
#   scripts/audit/run-overnight-2026-08-25.sh          # all four, in order
#   scripts/audit/run-overnight-2026-08-25.sh 2 3      # only steps 2 and 3
#
# Sequential BY DESIGN: the three hardening parts share one branch and one
# checkout, so two concurrent sessions would switch the branch under each other.
# Deliberately NOT `set -e` — each prompt is self-contained and writes its own
# RESULT file, so a failed step must not cancel the ones after it.

set -uo pipefail

REPO="/Users/joshuahunter/Herd/Side Street/backend"
LOGDIR="$REPO/.audit-work/overnight-2026-08-25"
# Per-step wall-clock ceiling. A wedged session must not eat the whole night.
STEP_TIMEOUT="${STEP_TIMEOUT:-14400}"   # 4h

PROMPTS=(
  "audits/consolidation/2026-08-25-pre-pilot-blockers/EXECUTE.md"
  "audits/consolidation/2026-08-25-pre-launch-hardening/EXECUTE-PART-1.md"
  "audits/consolidation/2026-08-25-pre-launch-hardening/EXECUTE-PART-2.md"
  "audits/consolidation/2026-08-25-pre-launch-hardening/EXECUTE-PART-3.md"
)

cd "$REPO" || { echo "cannot cd to $REPO"; exit 1; }
mkdir -p "$LOGDIR"

# Refuse to start on a dirty tree — an unattended run must not absorb someone
# else's uncommitted work into an audit commit.
if [[ -n "$(git status --porcelain)" ]]; then
  echo "REFUSING TO START: working tree is dirty. Commit or stash first."
  git status --short
  exit 1
fi

steps=("$@")
[[ ${#steps[@]} -eq 0 ]] && steps=(1 2 3 4)

echo "=== overnight run started $(date -u '+%Y-%m-%dT%H:%M:%SZ') (UTC) ==="
echo "steps: ${steps[*]}   timeout/step: ${STEP_TIMEOUT}s   logs: $LOGDIR"

for i in "${steps[@]}"; do
  prompt="${PROMPTS[$((i-1))]}"
  log="$LOGDIR/step-${i}.log"
  echo
  echo "--- step $i  $(date -u '+%H:%M:%SZ')  $prompt"

  timeout --signal=TERM --kill-after=60 "$STEP_TIMEOUT" \
    claude -p "execute audit $prompt" \
      --dangerously-skip-permissions \
      --model opus \
    >"$log" 2>&1
  rc=$?

  case $rc in
    0)   echo "    step $i OK" ;;
    124) echo "    step $i TIMED OUT after ${STEP_TIMEOUT}s — killed, continuing" ;;
    *)   echo "    step $i exited $rc — continuing" ;;
  esac
  echo "    log: $log"
done

echo
echo "=== finished $(date -u '+%Y-%m-%dT%H:%M:%SZ') (UTC) ==="
echo
echo "Reports written by the run:"
find audits/consolidation/2026-08-25-* -name 'RESULT*.md' -o -name 'DEFERRED-*.md' 2>/dev/null | sort | sed 's/^/  /'
echo
echo "Branches:"
git branch --list 'audit-fix/*2026-08-25' | sed 's/^/  /'
echo
echo "Nothing was pushed. Review, then push yourself."
