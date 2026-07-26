#!/usr/bin/env bash
# Active lane: end-to-end orchestration — bring up the isolated stack, seed
# routes + two identities, mint real tokens, drive ZAP's automation
# framework through three passes (identity A, identity B, unauth) plus a
# cross-identity IDOR pass, export results, tear down unconditionally.
#
# NEVER point this at real dev/prod — it fuzzes and mutates ONLY the
# runner's own throwaway local stack (see bring-up.sh).
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DAST_DIR="$HERE"
# shellcheck source=../lib/common.sh
source "$HERE/lib/common.sh"

OUTDIR="$(dast_abspath "${1:?usage: zap-active.sh OUTDIR [TARGET]}")"
require_docker_image zaproxy/zap-stable

BRINGUP_PID=""
teardown() {
    local status=$?
    log "zap-active: tearing down (exit was $status)"
    if [[ -n "$BRINGUP_PID" ]]; then
        kill "$BRINGUP_PID" 2>/dev/null || true
        wait "$BRINGUP_PID" 2>/dev/null || true
    fi
}
trap teardown EXIT INT TERM

# --- Bring up the isolated stack as a background child; teardown() above
# signals it on any exit path (mirrors bring-up.sh's own trap, one layer
# up) — this is the composition bring-up.sh's header documents: start it,
# poll for readiness, use it, kill it.
"$HERE/active/bring-up.sh" "$OUTDIR" >"$OUTDIR/bring-up-stdout.log" 2>&1 &
BRINGUP_PID=$!

log "zap-active: waiting for bring-up.sh readiness..."
deadline=$(( $(date +%s) + 90 ))
while [[ ! -f "$OUTDIR/bring-up.env" ]]; do
    if ! kill -0 "$BRINGUP_PID" 2>/dev/null; then
        die "bring-up.sh exited before becoming ready — see $OUTDIR/bring-up-stdout.log"
    fi
    [[ $(date +%s) -lt $deadline ]] || die "bring-up.sh did not become ready within 90s"
    sleep 1
done

# shellcheck disable=SC1091
source "$OUTDIR/bring-up.env"
TARGET_URL="http://127.0.0.1:${APP_PORT}"
# ZAP runs in its own container — 127.0.0.1 there is the container itself,
# not the host running the served app. host.docker.internal is the correct
# target host on Docker Desktop for Mac (confirmed empirically in Phase 2's
# ZAP import spike and Phase 3's replacer verification — both worked
# cleanly via host.docker.internal; this is the plan's own flagged Phase-4
# spike, resolved early via that incidental evidence).
ZAP_TARGET_URL="http://host.docker.internal:${APP_PORT}"
log "zap-active: target=$TARGET_URL (as seen by ZAP: $ZAP_TARGET_URL)"

# --- Seed routes + two identities, mint real tokens.
"$HERE/active/seed-endpoints.sh" "$OUTDIR" "$ZAP_TARGET_URL"
php "$HERE/active/seed-identities.php" --env=dast "$OUTDIR"

EMAIL_A=$(jq -r .A.email "$OUTDIR/identities.json")
PASS_A=$(jq -r .A.password "$OUTDIR/identities.json")
EMAIL_B=$(jq -r .B.email "$OUTDIR/identities.json")
PASS_B=$(jq -r .B.password "$OUTDIR/identities.json")
CUST_B=$(jq -r .B.customer_id "$OUTDIR/identities.json")
SITE_B=$(jq -r .B.site_id "$OUTDIR/identities.json")
MEDIA_B=$(jq -r .B.media_id "$OUTDIR/identities.json")
ENQUIRY_B=$(jq -r .B.enquiry_id "$OUTDIR/identities.json")

TOKEN_A=$(php "$HERE/active/mint-jwt.php" "$EMAIL_A" "$PASS_A")
TOKEN_B=$(php "$HERE/active/mint-jwt.php" "$EMAIL_B" "$PASS_B")
[[ -n "$TOKEN_A" && -n "$TOKEN_B" ]] || die "failed to mint one or both tokens"
log "zap-active: identities seeded, tokens minted"

ZAP_WORK="$OUTDIR/zap"
mkdir -p "$ZAP_WORK"
cp "$OUTDIR/seed-openapi.json" "$ZAP_WORK/seed-openapi.json"

# --- Template zap-context.yaml (contexts + exclusions + replacer) with the
# live target and real tokens.
sed -e "s|__TARGET_URL__|${ZAP_TARGET_URL}|g" \
    -e "s|__TOKEN_A__|${TOKEN_A}|g" \
    -e "s|__TOKEN_B__|${TOKEN_B}|g" \
    "$HERE/active/zap-context.yaml" > "$ZAP_WORK/zap-context-filled.yaml"

# --- Curated active-scan policy — SQLi, XSS (reflected + persistent),
# Path Traversal, Command Injection, matching the plan's Step 2 list
# verbatim. "Access-control checks" is realized by the cross-identity IDOR
# pass below rather than ZAP's separate Access Control Testing addon
# (which needs its own per-URL access-level definitions) — that pass is
# the higher-value, already-planned mechanism for this app's actual
# authorization model (ownership via Policies), so standing up a second,
# overlapping addon workflow was scoped out. Rule IDs are ZAP's
# long-stable standard set (40018/40012/40014/6/90020); defaultThreshold
# OFF means only these explicitly-enabled rules run — never "run
# everything" (matches the rest of this tool's curation discipline).
#
# "OFF"/"MEDIUM" etc are QUOTED deliberately — verified 2026-07-26 that a
# bare `OFF` is parsed as YAML 1.1's boolean `false` (a classic gotcha:
# off/on/yes/no are boolean literals), which ZAP then rejected ("Invalid
# threshold for job activeScan : false") and silently fell back to
# scanning with its FULL default rule set instead of just these 5 — an
# unbounded scan, exactly what this policy exists to prevent. Caught by
# watching the live run log, not by the plan parsing without error.
cat > "$ZAP_WORK/scan-policy.yaml" <<'POLICY'
  defaultStrength: "MEDIUM"
  defaultThreshold: "OFF"
  rules:
    - id: 40018   # SQL Injection
      threshold: "MEDIUM"
    - id: 40012   # Cross Site Scripting (Reflected)
      threshold: "MEDIUM"
    - id: 40014   # Cross Site Scripting (Persistent)
      threshold: "MEDIUM"
    - id: 6       # Path Traversal
      threshold: "MEDIUM"
    - id: 90020   # Remote OS Command Injection
      threshold: "MEDIUM"
POLICY

# --- Assemble the full plan: context/replacer (filled) + import + per-
# context spider/activeScan + cross-identity IDOR requests + report.
{
    cat "$ZAP_WORK/zap-context-filled.yaml"
    cat <<EOF
jobs:
  - type: openapi
    parameters:
      apiFile: "/zap/wrk/seed-openapi.json"
      targetUrl: "${ZAP_TARGET_URL}"
      context: "identity-a"

  - type: spider
    parameters:
      context: "identity-a"
      url: "${ZAP_TARGET_URL}"
  - type: activeScan
    parameters:
      context: "identity-a"
    policyDefinition:
$(sed 's/^/      /' "$ZAP_WORK/scan-policy.yaml")

  - type: replacer
    parameters: {}
    rules:
      - description: "auth-identity-a"
        enabled: false
        matchType: req_header
        matchString: "Authorization"
        replacement: "Bearer ${TOKEN_A}"
      - description: "auth-identity-b"
        enabled: true
        matchType: req_header
        matchString: "Authorization"
        replacement: "Bearer ${TOKEN_B}"
  - type: spider
    parameters:
      context: "identity-b"
      url: "${ZAP_TARGET_URL}"
  - type: activeScan
    parameters:
      context: "identity-b"
    policyDefinition:
$(sed 's/^/      /' "$ZAP_WORK/scan-policy.yaml")

  # Cross-identity IDOR pass: re-enable A's replacer (still points at A's
  # token from the first block) and hit B's seeded resource ids directly —
  # a 200 where 404 is expected is the finding.
  - type: replacer
    parameters: {}
    rules:
      - description: "auth-identity-a"
        enabled: true
        matchType: req_header
        matchString: "Authorization"
        replacement: "Bearer ${TOKEN_A}"
      - description: "auth-identity-b"
        enabled: false
        matchType: req_header
        matchString: "Authorization"
        replacement: "Bearer ${TOKEN_B}"
  - type: requestor
    parameters: {}
    requests:
      - url: "${ZAP_TARGET_URL}/api/customers/${CUST_B}"
        method: GET
        name: idor-customer-b
      - url: "${ZAP_TARGET_URL}/api/sites/${SITE_B}"
        method: GET
        name: idor-site-b
      - url: "${ZAP_TARGET_URL}/api/media/${MEDIA_B}"
        method: GET
        name: idor-media-b
      - url: "${ZAP_TARGET_URL}/api/enquiries/${ENQUIRY_B}"
        method: GET
        name: idor-enquiry-b

  # Unauth pass — no Authorization header at all.
  - type: replacer
    parameters: {}
    rules:
      - description: "auth-identity-a"
        enabled: false
        matchType: req_header
        matchString: "Authorization"
        replacement: "Bearer ${TOKEN_A}"
  - type: spider
    parameters:
      context: "unauth"
      url: "${ZAP_TARGET_URL}"
  - type: activeScan
    parameters:
      context: "unauth"
    policyDefinition:
$(sed 's/^/      /' "$ZAP_WORK/scan-policy.yaml")

  - type: report
    parameters:
      template: "traditional-json"
      reportDir: "/zap/out"
      reportFile: "zap-report"
  - type: report
    parameters:
      template: "traditional-html"
      reportDir: "/zap/out"
      reportFile: "zap-report"
EOF
} > "$ZAP_WORK/zap-plan.yaml"

log "zap-active: running ZAP automation plan (this can take a while — active scanning is slow by nature)"
# ZAP's own exit code is informational only, same as wcvs.sh/zap-baseline.sh
# — diff-baseline.sh (Phase 7) owns gating uniformly from the JSON report,
# by severity, not by whatever a given scanner's CLI treats as "failing."
# Load-bearing here: verified 2026-07-26 that ZAP's automation framework
# runs its PASSIVE scan rules continuously on all traffic (spidering +
# active scanning) independent of the curated activeScan rule policy —
# even a fully clean SQLi/XSS/etc pass can pick up passive findings (e.g.
# a missing X-Content-Type-Options header) and exit non-zero. Without
# `set +e` here, that non-zero exit would abort this script immediately
# (set -e is active throughout) BEFORE the exclusion check below ever ran.
set +e
docker run --rm \
    -v "$ZAP_WORK:/zap/wrk/:rw" \
    -v "$ZAP_WORK:/zap/out:rw" \
    zaproxy/zap-stable zap.sh -cmd -autorun /zap/wrk/zap-plan.yaml \
    > "$ZAP_WORK/zap-run.log" 2>&1
ZAP_EXIT=$?
set -e
log "zap-active: ZAP automation run exited $ZAP_EXIT (informational only) — see $ZAP_WORK/zap-run.log, $ZAP_WORK/zap-report.json"

# Exclusion verification (Phase 4 done-when) — the excluded prefixes must
# never appear anywhere in the run log. NOT scoped to "requesting URL"
# lines: verified 2026-07-26 that -cmd -autorun only logs that phrase for
# the spider/requestor jobs, not per-payload for activeScan (hundreds of
# fuzzed requests per URL, none individually logged) — a narrower grep
# would silently pass even if the active scanner itself hit an excluded
# path, since the site tree it draws from is what excludePaths governs.
if grep -qE "api/(platforms|staff/builds|site/custom-domain|me/site/reclaim-handle|staff/sites)/" "$ZAP_WORK/zap-run.log"; then
    die "exclusion verification FAILED: an excluded path appears in the run log — see $ZAP_WORK/zap-run.log"
fi
log "zap-active: exclusion check passed — zero references to excluded paths in the run log"
