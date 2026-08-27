#!/usr/bin/env python3
"""Fetch ALL Laravel Cloud log lines in a time window, despite the API's
100-line-per-request cap.

The `cloud env:logs` CLI accepts --from/--to but every request returns at most
the LAST 100 lines of the range. This pages backwards: each request's earliest
timestamp becomes the next request's --to, until the window is exhausted.
Lines are deduped across the one-second overlap and printed oldest-first as
JSON lines.

Usage:
  scripts/logs/window.py "2026-08-27 03:57:00" "2026-08-27 04:01:00" [app] [env]
  scripts/logs/window.py "2026-08-27 03:57:00" "2026-08-27 04:01:00" | grep youtube

Caveat: if more than 100 lines share ONE second, the excess in that second is
unreachable through this API — a warning is printed to stderr when a page is
full and cannot advance.
"""

import hashlib
import json
import subprocess
import sys


def fetch(app: str, env: str, start: str, end: str) -> list[dict]:
    cmd = [
        "cloud", "env:logs", app, env,
        "--from", start, "--to", end, "--json", "--no-interaction",
    ]
    out = subprocess.run(cmd, capture_output=True, text=True, timeout=120)
    body = out.stdout.strip()
    if not body:
        return []
    try:
        parsed = json.loads(body)
    except json.JSONDecodeError:
        print(f"warn: unparseable response for --to {end}: {body[:200]}", file=sys.stderr)
        return []
    return parsed if isinstance(parsed, list) else []


def key(line: dict) -> str:
    return hashlib.sha1(json.dumps(line, sort_keys=True).encode()).hexdigest()


def main() -> None:
    if len(sys.argv) < 3:
        print(__doc__, file=sys.stderr)
        sys.exit(1)

    start, end = sys.argv[1], sys.argv[2]
    app = sys.argv[3] if len(sys.argv) > 3 else "partna"
    env = sys.argv[4] if len(sys.argv) > 4 else "development"

    seen: set[str] = set()
    collected: list[dict] = []
    to = end

    for _ in range(200):  # hard stop: 200 pages = 20k lines
        batch = fetch(app, env, start, to)
        fresh = [l for l in batch if key(l) not in seen]
        for l in fresh:
            seen.add(key(l))
        collected.extend(fresh)

        if not batch:
            break

        earliest = min(l.get("loggedAt", "") for l in batch)
        if not earliest or earliest <= start:
            break
        # Page boundary: re-request ending AT the earliest second seen (dedupe
        # absorbs the overlap). If that yields nothing new, the window is done.
        next_to = earliest.replace("T", " ").split(".")[0].rstrip("Z")
        if next_to == to:
            if len(batch) == 100:
                print(f"warn: >100 lines in one second at {to}; some lines unreachable", file=sys.stderr)
            break
        if not fresh and next_to >= to:
            break
        to = next_to

    collected.sort(key=lambda l: l.get("loggedAt", ""))
    for line in collected:
        print(json.dumps(line))
    print(f"total: {len(collected)} lines", file=sys.stderr)


if __name__ == "__main__":
    main()
