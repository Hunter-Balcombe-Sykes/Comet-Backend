#!/bin/bash
# Run PHP on the dev backend via `cloud tinker` and print the decoded output.
# Usage: tinker.sh '<php code>'   (use \$ for variables when calling from bash)
cd ~/Developer/Comet-Backend || exit 1
cloud tinker development --code "$1" 2>&1 | python3 -c '
import sys, json
raw = sys.stdin.read()
printed = False
for line in raw.splitlines():
    line = line.strip()
    if not line.startswith("{"):
        continue
    try:
        d = json.loads(line)
    except Exception:
        continue
    out = d.get("output")
    if out:
        print(out.rstrip())
        printed = True
if not printed:
    print(raw[-1500:])
'
