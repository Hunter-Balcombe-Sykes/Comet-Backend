#!/bin/bash
# One public-flow build with tier timing. Args: label account_type source_type source_ref [source_name]
API=https://dev-api.partna.au
L=$1; AT=$2; ST=$3; REF=$4; SN=$5
OUT=${PROOF_LOG_DIR:-/tmp}/run-$L.jsonl
now(){ python3 -c 'import time; print(f"{time.time():.2f}")'; }
T0=$(now)
BODY=$(python3 - "$AT" "$ST" "$REF" "$SN" << 'PY'
import json,sys
b={"account_type":sys.argv[1],"source_type":sys.argv[2],"source_ref":sys.argv[3]}
if len(sys.argv)>4 and sys.argv[4]: b["source_name"]=sys.argv[4]
print(json.dumps(b))
PY
)
R=$(curl -s -X POST "$API/api/public/signup/build" -H 'Content-Type: application/json' -d "$BODY")
BID=$(echo "$R" | python3 -c "import json,sys; print(json.load(sys.stdin).get('build_id',''))" 2>/dev/null)
echo "{\"t\":$(now),\"t0\":$T0,\"event\":\"posted\",\"build_id\":\"$BID\",\"raw\":$(echo "$R" | python3 -c 'import json,sys; print(json.dumps(json.load(sys.stdin)))' 2>/dev/null || echo '"unparseable"')}" >> "$OUT"
[ -z "$BID" ] && echo "$L: POST failed: $R" && exit 1
LAST_STATE=""; KV=""; SUB=""
END=$(( $(date +%s) + 900 ))
while [ "$(date +%s)" -lt $END ]; do
  sleep 2
  P=$(curl -s "$API/api/public/signup/builds/$BID")
  STATE=$(echo "$P" | python3 -c "import json,sys; d=json.load(sys.stdin); print(d.get('build_state',''))" 2>/dev/null)
  NSUB=$(echo "$P" | python3 -c "import json,sys; d=json.load(sys.stdin); print(d.get('subdomain') or '')" 2>/dev/null)
  TIERS=$(echo "$P" | python3 -c "import json,sys; d=json.load(sys.stdin); print(json.dumps(d.get('tiers') or {}))" 2>/dev/null)
  if [ "$STATE" != "$LAST_STATE" ]; then
    echo "{\"t\":$(now),\"event\":\"state\",\"state\":\"$STATE\",\"dt\":$(python3 -c "print(round($(now)-$T0,1))")}" >> "$OUT"
    LAST_STATE=$STATE
  fi
  if [ -n "$NSUB" ] && [ -z "$SUB" ]; then SUB=$NSUB; echo "{\"t\":$(now),\"event\":\"subdomain\",\"subdomain\":\"$SUB\",\"dt\":$(python3 -c "print(round($(now)-$T0,1))")}" >> "$OUT"; fi
  if [ -n "$SUB" ] && [ -z "$KV" ] && [ "$LAST_STATE" = "ready" ]; then
    C=$(curl -s -o /dev/null -w "%{http_code}" "https://$SUB.partna.au/" --max-time 5)
    if [ "$C" = "200" ]; then KV=1; echo "{\"t\":$(now),\"event\":\"kv_live\",\"dt\":$(python3 -c "print(round($(now)-$T0,1))")}" >> "$OUT"; fi
  fi
  if [ "$TIERS" != "{}" ] && [ -n "$TIERS" ]; then
    echo "{\"t\":$(now),\"event\":\"tiers\",\"tiers\":$TIERS,\"dt\":$(python3 -c "print(round($(now)-$T0,1))")}" >> "$OUT"
    [ "$STATE" = "ready" ] && echo "$TIERS" | python3 -c "import json,sys; t=json.load(sys.stdin); sys.exit(0 if ('content_filled_at' in t and 'enriched_at' in t) else 1)" && break
  fi
  [ "$STATE" = "failed" ] && break
done
echo "$L done: state=$LAST_STATE sub=$SUB"
