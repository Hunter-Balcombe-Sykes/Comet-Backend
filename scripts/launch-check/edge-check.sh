#!/usr/bin/env bash
# Edge / sitepage probe — the PRODUCT surface. Verifies <handle>.partna.au is
# served through the Cloudflare Worker + SUBDOMAIN_KV + Cache API, that the edge
# cache populates, that aliases 301, and that TLS isn't about to expire. External
# (no auth, no cloud CLI) — this is what a visitor actually hits.
set -uo pipefail

HANDLE="${LAUNCH_CHECK_HANDLE:-}"
DOMAIN="partna.au"
ALIAS=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --handle) HANDLE="$2"; shift 2 ;;
        --domain) DOMAIN="$2"; shift 2 ;;
        --alias)  ALIAS="$2";  shift 2 ;;
        *) echo "unknown arg: $1"; exit 2 ;;
    esac
done

if [[ -z "$HANDLE" ]]; then
    echo "WARN  no handle to probe — set LAUNCH_CHECK_HANDLE or pass --handle <name>"
    exit 0   # WARN: nothing to test ≠ edge broken
fi

FAIL=0
URL="https://$HANDLE.$DOMAIN"

# 1. Served + HTML + went through the Worker (cf-cache-status present).
resp="$(curl -sS -D - -o /dev/null --max-time 15 "$URL" 2>&1)"
curl_exit=$?
if [[ $curl_exit -ne 0 ]]; then
    echo "FAIL  $URL unreachable (curl exit $curl_exit) — cannot verify edge serving"
    exit 1
fi
code="$(echo "$resp" | awk 'NR==1{print $2}')"
[[ "$code" == "200" ]] && echo "ok    $URL → 200" || { echo "FAIL  $URL → ${code:-000}"; FAIL=1; }
echo "$resp" | grep -qi '^content-type:.*text/html' && echo "ok    content-type html" || { echo "FAIL  not text/html"; FAIL=1; }
echo "$resp" | grep -qi '^cf-cache-status:' && echo "ok    served via Cloudflare edge" || { echo "FAIL  no cf-cache-status — not through the Worker?"; FAIL=1; }

# 2. Edge cache populates (warm fetch should HIT; MISS is a warn, not fatal — cold edge).
warm_resp="$(curl -sS -D - -o /dev/null --max-time 15 "$URL" 2>&1)"
warm_curl_exit=$?
if [[ $warm_curl_exit -ne 0 ]]; then
    echo "WARN  warm fetch unreachable (curl exit $warm_curl_exit) — could not check cache-status"
else
    warm="$(echo "$warm_resp" | grep -i '^cf-cache-status:' | tr -d '\r')"
    if [[ -z "$warm" ]]; then
        echo "WARN  warm fetch had no cf-cache-status header — could not check cache-status"
    else
        echo "$warm" | grep -qi 'HIT' && echo "ok    warm fetch HIT" || echo "WARN  warm fetch not HIT ($warm) — cache not populating?"
    fi
fi

# 3. Alias 301s to canonical (only if given).
if [[ -n "$ALIAS" ]]; then
    aresp="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "https://$ALIAS.$DOMAIN" 2>&1)"
    acurl_exit=$?
    if [[ $acurl_exit -ne 0 ]]; then
        echo "FAIL  alias $ALIAS unreachable (curl exit $acurl_exit) — cannot verify redirect"
        FAIL=1
    else
        acode="$aresp"
        [[ "$acode" == "301" ]] && echo "ok    alias $ALIAS → 301" || { echo "FAIL  alias $ALIAS → ${acode:-000} (want 301)"; FAIL=1; }
    fi
fi

# 4. TLS cert not about to expire (warn under 21 days).
exp="$(echo | openssl s_client -servername "$HANDLE.$DOMAIN" -connect "$HANDLE.$DOMAIN:443" 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)"
if [[ -n "$exp" ]]; then
    exp_epoch="$(date -j -f '%b %d %T %Y %Z' "$exp" +%s 2>/dev/null || date -d "$exp" +%s 2>/dev/null)"
    if [[ -z "$exp_epoch" ]]; then
        echo "WARN  could not parse TLS cert expiry date ($exp)"
    else
        now_epoch="$(date +%s)"
        days=$(( (exp_epoch - now_epoch) / 86400 ))
        [[ "$days" -ge 21 ]] && echo "ok    TLS cert ${days}d to expiry" || echo "WARN  TLS cert only ${days}d to expiry"
    fi
else
    echo "WARN  could not read TLS cert expiry"
fi

echo
if [[ $FAIL -ne 0 ]]; then echo "EDGE-CHECK: failure(s)"; else echo "EDGE-CHECK: passed"; fi
exit $FAIL
