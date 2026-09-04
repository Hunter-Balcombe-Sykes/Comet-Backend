#!/usr/bin/env bash
#
# npm audit, retried ONLY when npm's advisory endpoint fails to answer.
#
# Background: npm retired the legacy quick-audit endpoint on 2026-09-03/04.
# Pinning npm@11 moved CI onto the supported bulk advisory endpoint (see the
# comment above the pin in ci.yml), but that endpoint then began intermittently
# answering `503 Service Unavailable` — five CI runs went red on commits that
# touched no JavaScript at all, and one hung five minutes before the 503.
#
# THIS DOES NOT RELAX THE GATE. The two failure modes are deliberately kept
# apart:
#
#   - npm answered, and there are advisories at or above --audit-level
#     -> exits with npm's own code IMMEDIATELY. No retry, no second chance.
#   - npm did not answer at all (503/502/504, reset, timeout, "audit endpoint
#     returned an error")
#     -> retried with backoff, and if it never answers the job still FAILS.
#       A silent pass on an unreachable advisory DB would be worse than the
#       flake it is trying to fix: it would report "no known vulnerabilities"
#       having asked nobody.
#
# Usage: npm-audit-retry.sh [any npm audit flags]
set -uo pipefail

attempts=4
delay=15

for i in $(seq 1 "$attempts"); do
    out="$(npm audit "$@" 2>&1)"
    code=$?
    printf '%s\n' "$out"

    if [ "$code" -eq 0 ]; then
        exit 0
    fi

    if printf '%s' "$out" | grep -qiE 'audit endpoint returned an error|Service Unavailable|Bad Gateway|Gateway Time-?out|ECONNRESET|ETIMEDOUT|EAI_AGAIN|socket hang up|network timeout'; then
        if [ "$i" -lt "$attempts" ]; then
            echo "npm's advisory endpoint did not answer (attempt ${i}/${attempts}). Retrying in ${delay}s — the gate is not relaxed; if it never answers this job fails."
            sleep "$delay"
            delay=$(( delay * 2 ))
            continue
        fi
        echo "::error::npm's advisory endpoint never answered after ${attempts} attempts. Failing closed — this is NOT a pass, and the dependencies were never audited."
        exit 1
    fi

    # npm answered and is reporting advisories. That is the gate doing its job.
    exit "$code"
done
