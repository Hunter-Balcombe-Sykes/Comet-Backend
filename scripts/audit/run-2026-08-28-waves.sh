#!/usr/bin/env bash
# Cost-controlled sweep, staged 2026-08-28. Sequential by design — audit.sh's
# adjudications share the Claude plan budget; never run two at once.
#
# TRIMMED from the naive 204-scan plan to ~106 (-48%) on measured yield-per-scan
# from the 2026-08-27 run. What was cut and why:
#   test-coverage(31) code-quality-slop(22) test-prod-parity(15) — the P3-tail
#     generators; repo policy (BACKLOG-TRIAGE.md) is absorb-opportunistically,
#     never campaign. 68 scans.
#   webhook-idempotency(2) — 0 findings last run AND no webhook code changed.
#   transaction-boundaries(12) + semantic-correctness(22) — NOT dropped, moved
#     to targeted mode (runs C/D): measured, a narrow scope detects BETTER than
#     a sweep chunk, at ~1/6 the scans.
#
# Adjudication model: sonnet is the default and stays it. Haiku only for run A2,
# whose two lenses verify by grep (does this config key exist / is this TTL
# jittered) rather than by judgement.
set -euo pipefail
cd "$(dirname "$0")/../.."

BOTH=1151b0b6e     # both waves — the 10 lenses that never scanned owe this span
NEW=9567e3057      # new wave only — the 11 lenses already adjudicated on wave 1

echo "════════ A1 — high-value unscanned lenses, both waves (14 scans, sonnet) ════════"
mkdir -p audits/sweeps/2026-08-27-overnight-run
scripts/audit/audit.sh --codebase --bundle full-sweep \
    --changed-since "$BOTH" --name overnight-run --run-date 2026-08-27 \
    --only-lenses migration-safety,api-contract,privacy-compliance \
    --keep-drafts

echo "════════ A2 — grep-shaped lenses, both waves (21 scans, HAIKU) ════════"
scripts/audit/audit.sh --codebase --bundle full-sweep \
    --changed-since "$BOTH" --name overnight-run --run-date 2026-08-27 \
    --only-lenses configuration-hygiene,edge-worker,caching-coverage-gaps \
    --adj-model haiku --keep-drafts

echo "════════ B — correctness lenses, NEW wave only (65 scans, sonnet) ════════"
mkdir -p audits/sweeps/2026-08-28-wave-2
scripts/audit/audit.sh --codebase --bundle full-sweep \
    --changed-since "$NEW" --name wave-2 \
    --only-lenses security,lifecycle-correctness,database-and-queue-scaling,caching-gold-standard,observability,data-integrity,schema-rls,job-queue-correctness,scaling-antipatterns \
    --keep-drafts
