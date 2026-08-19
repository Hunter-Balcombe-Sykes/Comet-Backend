#!/usr/bin/env bash
# Timeout-bounded wrapper for `cloud env:logs`.
#
# WHY: `cloud env:logs ... --minutes N --json` is documented as "backlog then
# exit", but it has no guaranteed exit path — it can wedge on a dead connection
# and sleep forever with no parent and no sockets. On 2026-08-19 an 11-minute
# poll loop left 70 such orphans alive for 6 hours, driving load average to 45
# on a 10-core machine and pinning kernel_task at 67% (thermal throttle).
#
# Never call `cloud env:logs` bare from a loop. Call this instead.
#
#   scripts/env/cloud-logs.sh development --minutes 2 --json
#   CLOUD_LOGS_TIMEOUT=120 scripts/env/cloud-logs.sh production --tail 50
#
# Exit 124 = timed out (the wedge this exists to catch), not a log failure.

set -uo pipefail

TIMEOUT="${CLOUD_LOGS_TIMEOUT:-60}"
CLOUD_BIN="${CLOUD_BIN:-$HOME/.composer/vendor/bin/cloud}"

# Homebrew coreutils on macOS; GNU `timeout` elsewhere.
TIMEOUT_BIN="$(command -v gtimeout || command -v timeout || true)"
if [[ -z "$TIMEOUT_BIN" ]]; then
    echo "cloud-logs: no timeout binary found — install coreutils (brew install coreutils)" >&2
    echo "cloud-logs: refusing to run unbounded" >&2
    exit 1
fi

if [[ $# -lt 1 ]]; then
    echo "usage: $0 <environment> [cloud env:logs flags...]" >&2
    exit 2
fi

ENVIRONMENT="$1"; shift

# -k 5s: if TERM is ignored (the wedge state often is), follow with KILL.
"$TIMEOUT_BIN" -k 5s "$TIMEOUT" "$CLOUD_BIN" env:logs partna "$ENVIRONMENT" "$@"
STATUS=$?

if [[ $STATUS -eq 124 || $STATUS -eq 137 ]]; then
    echo "cloud-logs: timed out after ${TIMEOUT}s and was killed (this is the wedge guard working)" >&2
fi

exit $STATUS
