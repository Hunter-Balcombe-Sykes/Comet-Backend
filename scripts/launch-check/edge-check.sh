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

# No handle = nothing was probed, which must never read as "the edge works".
# This used to WARN and exit 0; combined with runtime-health.sh WARNing on an
# absent cloud CLI, a group-H run could probe NOTHING and the runner would still
# print "all automated groups passed" (the C1 finding). A check that did not run
# fails closed, exactly like an absent `cloud` CLI in env-check.sh.
if [[ -z "$HANDLE" ]]; then
    echo "FAIL  no handle to probe — this check did not run (nothing checked is not a pass)."
    echo "      Set LAUNCH_CHECK_HANDLE to a published canary handle, or pass --handle <name>."
    exit 1
fi

FAIL=0
URL="https://$HANDLE.$DOMAIN"

# fetch_headers URL — header fetch with a small retry. A single transient blip
# must not hard-FAIL a gate: observed twice in testing, once as a curl-level
# failure and once as a 200 that came back WITHOUT a cf-cache-status header
# (curl exit 0, so a retry keyed only on curl's status would not have covered
# it). Retries while the response is either unreachable or incomplete, then
# returns the LAST attempt regardless — failure semantics are unweakened: a
# persistently bad edge still fails every assertion below, on the final response.
fetch_headers() {
    local url="$1" attempt out rc
    for attempt in 1 2 3; do
        out="$(curl -sS -D - -o /dev/null --max-time 15 "$url" 2>&1)"; rc=$?
        if [[ $rc -eq 0 ]] \
            && printf '%s' "$out" | awk 'NR==1{exit !($2 == "200")}' \
            && printf '%s' "$out" | grep -qi '^cf-cache-status:'; then
            printf '%s' "$out"
            return 0
        fi
        [[ $attempt -lt 3 ]] && sleep 2
    done
    printf '%s' "$out"
    return $rc
}

# 1. Served + HTML + went through the Worker (cf-cache-status present).
resp="$(fetch_headers "$URL")"
curl_exit=$?
if [[ $curl_exit -ne 0 ]]; then
    echo "FAIL  $URL unreachable after 3 attempts (curl exit $curl_exit) — cannot verify edge serving"
    exit 1
fi
code="$(echo "$resp" | awk 'NR==1{print $2}')"
[[ "$code" == "200" ]] && echo "ok    $URL → 200" || { echo "FAIL  $URL → ${code:-000}"; FAIL=1; }
echo "$resp" | grep -qi '^content-type:.*text/html' && echo "ok    content-type html" || { echo "FAIL  not text/html"; FAIL=1; }
echo "$resp" | grep -qi '^cf-cache-status:' && echo "ok    served via Cloudflare edge" || { echo "FAIL  no cf-cache-status — not through the Worker?"; FAIL=1; }

# 1b. WHOSE sitepage is this? The three header assertions above are all satisfied
# by a Worker catch-all that returns 200 text/html through Cloudflare for any
# hostname — including one serving the wrong site, or a generic fallback page.
# Require the handle itself to appear in the rendered body.
body="$(curl -sS -L --max-time 20 "$URL" 2>/dev/null)"
body_curl_exit=$?
if [[ $body_curl_exit -ne 0 || -z "$body" ]]; then
    echo "FAIL  could not read $URL body (curl exit $body_curl_exit) — cannot verify WHOSE sitepage is served"
    FAIL=1
elif printf '%s' "$body" | grep -qiF "$HANDLE"; then
    echo "ok    body identifies handle '$HANDLE'"
else
    echo "FAIL  body never mentions '$HANDLE' — a catch-all or the wrong site may be served"
    FAIL=1
fi

# 2. Edge cache populates (warm fetch should HIT; MISS is a warn, not fatal — cold edge).
warm_resp="$(fetch_headers "$URL")"
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
    # Same retry rationale as fetch_headers — a blip must not hard-FAIL the gate.
    for aattempt in 1 2 3; do
        aresp="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "https://$ALIAS.$DOMAIN" 2>&1)"; acurl_exit=$?
        [[ $acurl_exit -eq 0 ]] && break
        [[ $aattempt -lt 3 ]] && sleep 2
    done
    if [[ $acurl_exit -ne 0 ]]; then
        echo "FAIL  alias $ALIAS unreachable after 3 attempts (curl exit $acurl_exit) — cannot verify redirect"
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
