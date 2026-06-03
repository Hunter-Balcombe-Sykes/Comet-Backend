#!/usr/bin/env bash
# .recover-v3.sh — re-run the 2 oversized v3 lenses (6, 26) that overflowed the
# 200k inline-source ceiling, using --no-source adjudication (model Reads/Greps
# on demand instead of inlining the whole scope tree). Writes to canonical paths.
set -uo pipefail
SD="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$SD/../.." && pwd)"; cd "$ROOT"
OUT="audits/foundation-audit-v3"; DATE="2026-05-31"

recover() {
  local slug="$1" lens="$2" scopes="$3"
  local drafts; drafts="$(mktemp)"
  local final="$OUT/audit-${DATE}-${slug}.md"
  local scope_args=(); for s in $scopes; do scope_args+=(--scope "$s"); done
  echo "[$slug] scan…" >&2
  if ! "$SD/audit-scan.sh" --lens "$lens" "${scope_args[@]}" --out "$drafts" >&2; then
    echo "[$slug] SCAN FAILED" >&2; rm -f "$drafts"; return 1
  fi
  echo "[$slug] adjudicate --no-source…" >&2
  if ! "$SD/audit-adjudicate.sh" --drafts "$drafts" --lens "$lens" --no-source --max-budget 3.00 \
        "${scope_args[@]}" --out "$final" >&2; then
    echo "[$slug] ADJUDICATE FAILED" >&2; rm -f "$drafts"; return 1
  fi
  rm -f "$drafts"
  echo "[$slug] DONE → $final ($(wc -c < "$final") bytes)" >&2
}

# Delete the two stubs first
rm -f "$OUT/audit-${DATE}-missing-transactions-race-conditions-soft-delete-c.md" \
      "$OUT/audit-${DATE}-observability-and-architecture-hygiene-and-test-co.md"

recover "missing-transactions-race-conditions-soft-delete-c" \
  "Missing transactions, race conditions, soft-delete consistency, FK/unique constraint gaps, N+1 writes, observer side-effects outside transactions, double-dispatch on retried writes" \
  "app/Services app/Models app/Observers app/Jobs supabase/migrations" &
P1=$!

recover "observability-and-architecture-hygiene-and-test-co" \
  "Observability and architecture hygiene and test coverage, missing structured log context, PII in logs, silent catch blocks, exception/slow-job coverage gaps, audit-log integrity, service-boundary correctness, fat controllers, dead code post-strip, test coverage of critical paths" \
  "app/Services/Audit app/Services app/Http/Controllers app/Http/Concerns app/Http/Middleware/Logging tests" &
P2=$!

wait $P1; R1=$?
wait $P2; R2=$?
echo "════ recovery exit: lens6=$R1 lens26=$R2 ════" >&2
