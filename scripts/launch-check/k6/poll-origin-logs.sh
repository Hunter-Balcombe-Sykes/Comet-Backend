#!/usr/bin/env bash
# Capture the origin access log for the whole of a load run, and read it back.
#
# `cloud env:logs` caps at ~100 entries regardless of --minutes. At the harness's
# ~135 req/min that is about 45 seconds of traffic, so a one-off call rotates a
# rare event out of reach — on 2026-08-09 it missed a stall by two seconds.
# Polling a 5-minute window every 20s gives ~15x overlap; entries are deduped on
# read. Proven on 2026-08-09 run D: 252 unique entries across 6m13s.
#
#   ./poll-origin-logs.sh capture 1260 results/stall-origin.jsonl   # run alongside k6
#   ./poll-origin-logs.sh slow    results/stall-origin.jsonl 500    # entries over 500ms
#   ./poll-origin-logs.sh at      results/stall-origin.jsonl 05:09:32
#
# LIMIT WORTH KNOWING: loggedAt has one-second resolution and carries no request
# id, so dedupe keys on the whole entry. Two identical requests (same path, same
# status, same duration_ms) inside one second collapse into one. That undercounts
# volume; it cannot hide a slow outlier, which is what this exists to catch.

set -euo pipefail

CLOUD="${CLOUD_BIN:-$HOME/.composer/vendor/bin/cloud}"
APP="${CLOUD_APP:-partna}"
ENV_NAME="${CLOUD_ENV:-development}"
INTERVAL="${POLL_INTERVAL:-20}"

mode="${1:-}"

case "$mode" in
  capture)
    seconds="${2:?usage: capture <seconds> <outfile>}"
    out="${3:?usage: capture <seconds> <outfile>}"
    mkdir -p "$(dirname "$out")"
    : >"$out"
    deadline=$(( $(date +%s) + seconds ))
    polls=0
    echo "polling $APP/$ENV_NAME every ${INTERVAL}s for ${seconds}s -> $out"
    while [ "$(date +%s)" -lt "$deadline" ]; do
      # One JSON array per line. Failures are logged, never fatal: losing a poll
      # costs overlap, aborting costs the whole run's coverage.
      if ! "$CLOUD" env:logs "$APP" "$ENV_NAME" --minutes 5 2>/dev/null >>"$out"; then
        echo "warn: poll $polls failed" >&2
      fi
      echo >>"$out"
      polls=$(( polls + 1 ))
      sleep "$INTERVAL"
    done
    echo "done: $polls polls"
    "$0" summary "$out"
    ;;

  summary|slow|at)
    file="${2:?usage: $mode <file> [arg]}"
    arg="${3:-}"
    MODE="$mode" ARG="$arg" python3 - "$file" <<'PY'
import json, os, sys

mode, arg = os.environ['MODE'], os.environ['ARG']
seen, entries = set(), []
for line in open(sys.argv[1]):
    line = line.strip()
    if not line:
        continue
    try:
        batch = json.loads(line)
    except json.JSONDecodeError:
        continue
    for e in batch:
        key = json.dumps(e, sort_keys=True)
        if key in seen:
            continue
        seen.add(key)
        entries.append(e)

entries.sort(key=lambda e: e.get('loggedAt', ''))
access = [e for e in entries if e.get('type') == 'access']

if mode == 'summary':
    print(f'{len(entries)} unique entries ({len(access)} access)')
    if entries:
        print(f'window {entries[0].get("loggedAt")} .. {entries[-1].get("loggedAt")}')
    if access:
        d = sorted(e['data'].get('duration_ms') or 0 for e in access)
        print(f'origin duration_ms: p50 {d[len(d)//2]}  p95 {d[int(len(d)*0.95)]}  max {d[-1]}')
    sys.exit()

if mode == 'slow':
    floor = int(arg or 500)
    rows = [e for e in access if (e['data'].get('duration_ms') or 0) >= floor]
    print(f'{len(rows)} access entries >= {floor}ms')
else:
    rows = [e for e in access if arg in (e.get('loggedAt') or '')]
    print(f'{len(rows)} access entries matching {arg}')

for e in rows:
    d = e['data']
    print(f'{e["loggedAt"]}  {d.get("duration_ms"):>6}ms  {d.get("status")}  '
          f'{d.get("method")} {d.get("path")}  bytes={d.get("bytes_sent")}  ip={d.get("ip")}')
PY
    ;;

  *)
    sed -n '2,20p' "$0"
    exit 1
    ;;
esac
