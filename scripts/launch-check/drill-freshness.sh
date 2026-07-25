#!/usr/bin/env bash
# Drill-log freshness — READ-ONLY. Verifies each failure-mode drill has a result
# log (docs/runbooks/drills/logs/<YYYY-MM-DD>-<slug>.md) that postdates the last
# commit touching the drilled code path. It never RUNS a drill — drills are
# destructive, human-run sessions (docs/runbooks/drills/README.md); this only
# checks their EVIDENCE is current, so a launch-check run still covers them.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LOGS="$ROOT/docs/runbooks/drills/logs"
FAIL=0

latest_log_date() { # $1 slug → newest YYYY-MM-DD from matching log filenames, or empty
    ls "$LOGS" 2>/dev/null | grep -E "^[0-9]{4}-[0-9]{2}-[0-9]{2}-$1\.md$" | sort | tail -1 | cut -c1-10
}

# Code-anchored drill: stale when the drilled path changed after the last log.
# Same-day log+change counts as fresh (dates compare lexicographically).
check_code_drill() { # $1 slug, $2 runbook file, $3.. watched paths
    local slug="$1" runbook="$2"; shift 2
    local log_date last_change
    log_date="$(latest_log_date "$slug")"
    if [[ -z "$log_date" ]]; then
        echo "FAIL  $slug — never drilled. Run docs/runbooks/drills/$runbook"; FAIL=1; return
    fi
    last_change="$(cd "$ROOT" && git log -1 --format=%cs -- "$@")"
    # An empty result means NO commit touches any watched path — the path was
    # renamed or deleted, so this drill's blast radius no longer points at real
    # code and it would otherwise report `ok` forever, immune to every future
    # change. "Cannot determine staleness" is a FAIL, never a pass.
    if [[ -z "$last_change" ]]; then
        echo "FAIL  $slug — watched path not found in git — update the drill's blast radius (docs/runbooks/drills/README.md): $*"; FAIL=1
    elif [[ "$last_change" > "$log_date" ]]; then
        echo "FAIL  $slug — drilled $log_date but $last_change changed: $*"; FAIL=1
    else
        echo "ok    $slug — log $log_date current for: $*"
    fi
}

# Calendar-anchored drill: backups rot on a calendar, not with code.
check_calendar_drill() { # $1 slug, $2 runbook file, $3 max age (days)
    local slug="$1" runbook="$2" max_days="$3"
    local log_date cutoff
    log_date="$(latest_log_date "$slug")"
    # macOS (BSD date) first, GNU date fallback — runs on dev laptops AND CI.
    cutoff="$(date -v-"${max_days}"d +%F 2>/dev/null || date -d "${max_days} days ago" +%F)"
    if [[ -z "$log_date" ]]; then
        echo "FAIL  $slug — never drilled. Run docs/runbooks/drills/$runbook"; FAIL=1
    elif [[ "$log_date" < "$cutoff" ]]; then
        echo "FAIL  $slug — last drilled $log_date (>${max_days}d ago). Re-run docs/runbooks/drills/$runbook"; FAIL=1
    else
        echo "ok    $slug — log $log_date within ${max_days}d"
    fi
}

# Watched paths mirror the staleness rules in docs/runbooks/drills/README.md —
# update BOTH when a drill's blast radius changes.
check_code_drill worker-kill   01-worker-kill.md   app/Jobs/Cloudflare app/Jobs/Concerns config/horizon.php config/queue.php
check_code_drill vendor-outage 02-vendor-outage.md app/Jobs/Platforms app/Services/Platforms
check_code_drill redis-down    03-redis-down.md    app/Services/Analytics config/cache.php config/database.php
check_calendar_drill backup-restore 04-backup-restore.md 92   # quarterly + grace

exit $FAIL
