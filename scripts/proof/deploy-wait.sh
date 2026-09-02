#!/bin/bash
# Wait for the latest Laravel Cloud deployment of an env to finish. Usage: deploy-wait.sh [development] [max_seconds]
# One call replaces a poll loop of `cloud deploy:list`; prints one line per state change and the final status.
cd ~/Developer/Comet-Backend || exit 1
ENV=${1:-development}; MAX=${2:-900}; END=$(( $(date +%s) + MAX )); LAST=""
while [ "$(date +%s)" -lt "$END" ]; do
  LINE=$(cloud deploy:list "$ENV" 2>/dev/null | grep -m1 -iE "succeeded|failed|running|pending|building|queued|deploying")
  STATE=$(echo "$LINE" | grep -oiE "succeeded|failed|running|pending|building|queued|deploying" | head -1 | tr '[:upper:]' '[:lower:]')
  if [ "$STATE" != "$LAST" ]; then echo "$(date +%H:%M:%S) $STATE  $LINE" | cut -c1-160; LAST=$STATE; fi
  case "$STATE" in succeeded|failed) exit 0;; esac
  sleep 20
done
echo "timeout after ${MAX}s (last: $LAST)"; exit 1
