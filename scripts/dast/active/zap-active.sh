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

# --- Clear the stale readiness file BEFORE starting bring-up. OUTDIR is
# per-date-and-lane (lib/common.sh's dast_outdir), so any RE-run of this lane
# on the same date inherits the previous run's bring-up.env — and the poll
# below only tests for that file's EXISTENCE, never its freshness. Without
# this rm, the second run short-circuits the readiness gate instantly, then
# seeds and probes against a stack that is still booting.
#
# Do NOT assume the lane suffix in dast_outdir fixed this: it separates the
# two LANES, it does not separate two runs of the SAME lane on one day, which
# is the exact case that bit (runs 2-5 of 2026-08-07).
#
# That is not a flake, it is a containment failure: .env.dast does not exist
# yet at that point, `--env=dast` silently falls back to the repo's real
# .env, and the "local" seeder points at whatever SUPABASE_URL/DB_HOST the
# developer's real environment holds. Observed 2026-08-07 — run 3 POSTed to
# a REMOTE *.supabase.co admin endpoint and only failed because that host
# was unreachable. seed-identities.php now fail-closes on this too; both
# guards are deliberate (this one prevents it, that one contains it).
rm -f "$OUTDIR/bring-up.env"

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

# --- Tier 2 auth-layer probes: real tokens through real middleware, and a real
# concurrent claim race. Runs BEFORE ZAP so a broken auth layer fails the lane
# fast rather than after a full spider. tier2.sh die()s on any probe failure,
# and set -e here means that aborts the lane — deliberate: a token the auth
# layer accepts when it should not is a finding, not a warning.
bash "$HERE/active/tier2.sh" "$OUTDIR"

EMAIL_A=$(jq -r .A.email "$OUTDIR/identities.json")
PASS_A=$(jq -r .A.password "$OUTDIR/identities.json")
EMAIL_B=$(jq -r .B.email "$OUTDIR/identities.json")
PASS_B=$(jq -r .B.password "$OUTDIR/identities.json")
# Both identities' ids: B's drive the IDOR probes, A's drive the paired
# CONTROLS. A control per probe is not redundancy — see the requestor job.
CUST_A=$(jq -r .A.customer_id "$OUTDIR/identities.json")
ENQUIRY_A=$(jq -r .A.enquiry_id "$OUTDIR/identities.json")
CUST_B=$(jq -r .B.customer_id "$OUTDIR/identities.json")
ENQUIRY_B=$(jq -r .B.enquiry_id "$OUTDIR/identities.json")

TOKEN_A=$(php "$HERE/active/mint-jwt.php" "$EMAIL_A" "$PASS_A")
TOKEN_B=$(php "$HERE/active/mint-jwt.php" "$EMAIL_B" "$PASS_B")
[[ -n "$TOKEN_A" && -n "$TOKEN_B" ]] || die "failed to mint one or both tokens"
log "zap-active: identities seeded, tokens minted"

ZAP_WORK="$OUTDIR/zap"
mkdir -p "$ZAP_WORK"
cp "$OUTDIR/seed-openapi.json" "$ZAP_WORK/seed-openapi.json"

# --- Template zap-context.yaml (contexts + exclusions) with the live target.
# Tokens are NOT substituted here any more — they go into the replacer JOBS
# below. zap-context.yaml's top-level `replacer:` block was dead config (a
# plan has only `env:` and `jobs:`); see that file's header.
sed -e "s|__TARGET_URL__|${ZAP_TARGET_URL}|g" \
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

# --- Assemble the full plan: contexts (filled) + per-pass replacer + import
# + per-context spider/activeScan + cross-identity IDOR requests + report.
#
# AUTH INJECTION — three mechanics, each verified empirically 2026-08-06
# against a header-echoing HTTP server (NOT inferred from a clean run; the
# previous implementation looked clean while injecting nothing for months):
#
#  1. The field is `replacementString`, NOT `replacement`. ZAP 2.17.0's own
#     schema (`zap.sh -cmd -autogenmax`) lists description/url/matchType/
#     matchString/matchRegex/replacementString/tokenProcessing/initiators.
#     A wrong key is logged as "Unrecognised parameter for job replacer"
#     — a WARNING, so the plan still runs and still reports success, with
#     every request going out unauthenticated. Grep-guarded at the end of
#     this script so that can never silently recur.
#  2. There is NO per-rule `enabled` field. The old design defined both
#     identities' rules once and flipped `enabled: true/false` per pass;
#     that concept does not exist in the automation framework. Identity
#     switching is instead one replacer JOB per pass carrying ONLY that
#     pass's rule, with `deleteAllRules: true` to evict the previous
#     pass's rule. Without deleteAllRules, rules accumulate and two rules
#     race to set the same header.
#  3. `matchType: req_header` ADDS the header when absent (it is not a
#     replace-only-if-present match), which is what makes injection work
#     against a scanner that sends no Authorization header of its own.
#     `deleteAllRules: true` with NO rules list is how the unauth pass gets
#     a genuinely bare request.
#
# Ordering is load-bearing: the replacer job must precede the jobs it is
# meant to authenticate. The old plan's first replacer sat AFTER identity-a's
# openapi/spider/activeScan, so that pass had no auth applied even in intent.
{
    cat "$ZAP_WORK/zap-context-filled.yaml"
    cat <<EOF
jobs:
  - type: replacer
    parameters:
      deleteAllRules: true
    rules:
      - description: "auth-identity-a"
        matchType: req_header
        matchString: "Authorization"
        replacementString: "Bearer ${TOKEN_A}"
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

  # Swap to identity B: deleteAllRules evicts A's rule, so exactly one
  # Authorization rule is ever live.
  - type: replacer
    parameters:
      deleteAllRules: true
    rules:
      - description: "auth-identity-b"
        matchType: req_header
        matchString: "Authorization"
        replacementString: "Bearer ${TOKEN_B}"
  - type: spider
    parameters:
      context: "identity-b"
      url: "${ZAP_TARGET_URL}"
  - type: activeScan
    parameters:
      context: "identity-b"
    policyDefinition:
$(sed 's/^/      /' "$ZAP_WORK/scan-policy.yaml")

  # --- Cross-identity IDOR pass. Clear every replacer rule first, then carry
  # A's token as an EXPLICIT per-request header. Two reasons this is per-
  # request rather than another global replacer: it removes the ordering
  # dependency that broke the old plan, and it leaves no rule installed for
  # the unauth pass below to inherit.
  #
  # Each probe asserts \`responseCode\`, which the old plan omitted entirely —
  # it sent four requests and checked nothing, so a successful cross-tenant
  # read would have gone unreported. 404 (not 403) is the app's deliberate
  # convention for a resource that exists but is not yours, confirmed in
  # source: CustomerPolicy::view -> BasePolicy::denyAsNotFound(), and
  # UserEnquiryController::transition's own \`->where('user_id', ...)->find()\`
  # then 404. A 200 here means broken tenant isolation.
  #
  # EVERY probe is paired with a CONTROL on identity A's OWN equivalent
  # resource expecting 200. Without it the probes are vacuous three ways
  # over: a dead app 404s everything, a rejected token 401s everything
  # (which is NOT 200, so a naive "not 200" check would pass), and — the
  # trap that actually bit here — a route that does not exist 404s too. The
  # old plan probed /api/sites/{id}, /api/media/{id} and /api/enquiries/{id};
  # \`route:list\` has NONE of those three, so they returned 404 for "no such
  # route" and would have passed a 404 assertion while testing nothing. Only
  # surfaces with a real by-id route AND a seeded fixture are probed here;
  # sites and site-media have no by-id user route at all, so they carry no
  # IDOR surface to test. Adding one means seeding a fixture for it and
  # confirming its route exists FIRST — not assuming, which is how three
  # phantom probes survived this long.
  - type: replacer
    parameters:
      deleteAllRules: true
  - type: requestor
    parameters: {}
    requests:
      - url: "${ZAP_TARGET_URL}/api/customers/${CUST_A}"
        method: GET
        name: control-own-customer-a
        headers:
          - "Authorization: Bearer ${TOKEN_A}"
        responseCode: 200
      - url: "${ZAP_TARGET_URL}/api/customers/${CUST_B}"
        method: GET
        name: idor-customer-b
        headers:
          - "Authorization: Bearer ${TOKEN_A}"
        responseCode: 404
      - url: "${ZAP_TARGET_URL}/api/enquiries/${ENQUIRY_A}/read"
        method: POST
        name: control-own-enquiry-a
        headers:
          - "Authorization: Bearer ${TOKEN_A}"
        responseCode: 200
      - url: "${ZAP_TARGET_URL}/api/enquiries/${ENQUIRY_B}/read"
        method: POST
        name: idor-enquiry-b
        headers:
          - "Authorization: Bearer ${TOKEN_A}"
        responseCode: 404

  # Unauth pass — the replacer was cleared above and the IDOR tokens were
  # per-request, so nothing is installed and these requests are genuinely
  # bare. Verified: a \`deleteAllRules: true\` job with no rules list yields a
  # null Authorization header at the server.
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
# Keep this alternation in sync with zap-context.yaml's excludePaths — the two
# lists are the same contract stated twice (there is intentionally no shared
# source: the YAML needs regexes per context, this needs one grep). The
# `sessions|me/deletion|account/mfa` entries are the self-destruction group
# added 2026-08-07; `api/sessions/` with the trailing slash deliberately does
# NOT match the in-scope `GET api/sessions` collection read.
if grep -qE "api/(platforms|staff/builds|site/custom-domain|me/site/reclaim-handle|staff/sites|sessions|me/deletion|account/mfa)/" "$ZAP_WORK/zap-run.log"; then
    die "exclusion verification FAILED: an excluded path appears in the run log — see $ZAP_WORK/zap-run.log"
fi
log "zap-active: exclusion check passed — zero references to excluded paths in the run log"

# --- Plan-integrity guard. ZAP treats an unknown job/rule field as a WARNING,
# not an error: it logs "Unrecognised parameter for job X : y", drops the
# field, runs the rest of the plan and still writes a clean report. That is
# exactly how the replacer's `replacement`/`enabled` fields silently disabled
# ALL token injection for months while this lane reported PASS — every
# "authenticated" scan was anonymous and nothing anywhere said so. Any
# unrecognised parameter now fails the lane: in a plan this tool generates
# itself, an ignored field is always a bug, never an intentional spare.
if grep -q "Unrecognised parameter" "$ZAP_WORK/zap-run.log"; then
    die "plan integrity FAILED: ZAP ignored a parameter it did not recognise — the plan is not doing what it says. Offending lines:
$(grep "Unrecognised parameter" "$ZAP_WORK/zap-run.log" | sort -u)
see $ZAP_WORK/zap-run.log"
fi
log "zap-active: plan integrity check passed — ZAP recognised every parameter in the plan"

# --- IDOR assertion gate. requestor `responseCode` mismatches surface ONLY as
# "Difference in response code values for message ..." warnings in the run log
# — they do NOT become report alerts, and diff-baseline.sh gates purely on the
# JSON report's alerts, so without this check a failed cross-tenant assertion
# would leave the lane green. Message format confirmed empirically against ZAP
# 2.17.0 (2026-08-06). Fires for BOTH directions, and that symmetry is the
# point: an `idor-*` mismatch means a foreign resource was readable (broken
# tenant isolation); a `control-*` mismatch means the probes proved nothing
# because the token, the app or the route was not working. Both invalidate the
# run, so both must fail it.
if grep -q "Difference in response code values" "$ZAP_WORK/zap-run.log"; then
    die "IDOR/control assertion FAILED — a cross-identity probe or its control returned an unexpected status:
$(grep "Difference in response code values" "$ZAP_WORK/zap-run.log" | sort -u)
A 'control-*' line means the pass was vacuous (bad token / dead app / missing route), NOT that authorization is fine.
An 'idor-*' line means a foreign resource was reachable — treat as a P0 authorization finding, never relax the assertion.
see $ZAP_WORK/zap-run.log"
fi
log "zap-active: IDOR assertions passed — foreign resources 404'd and every control returned 200"
