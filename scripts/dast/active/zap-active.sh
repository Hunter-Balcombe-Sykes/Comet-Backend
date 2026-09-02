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

# READINESS DEADLINE. 90s was tuned on a warm developer laptop — Docker images
# already pulled, so `supabase start` only had to boot containers and apply
# migrations. It is nowhere near enough on a cold machine, and the failure it
# produces is actively misleading.
#
# Measured on GitHub run 31376712687: a cold runner pulls ~12 Supabase images
# before it can start anything, then applies 80+ migrations. It blew past 90s,
# this poll gave up, killed bring-up mid-flight, and the run reported
# "bring-up.sh did not become ready within 90s" — while every migration in the
# log had in fact applied successfully. The stack was fine; the clock was wrong.
#
# Two earlier runs blamed the wrong thing entirely because of it: teardown fired
# on the kill and deleted the scratch dir, so the NEXT statement failed with
# "failed to change workdir ... no such file or directory", which reads like a
# broken workdir rather than a timeout. bring-up.sh's trap split now stops the
# script continuing past its own teardown, but the deadline is what starts it.
#
# 600s default, overridable. Erring long is nearly free — this is an upper bound
# that only matters when something is already wrong, and the poll exits the
# instant bring-up.env appears, so a warm run is unaffected. The liveness check
# below is what catches a genuinely dead bring-up, not this timer.
BRINGUP_TIMEOUT="${DAST_BRINGUP_TIMEOUT:-600}"
log "zap-active: waiting for bring-up.sh readiness (timeout ${BRINGUP_TIMEOUT}s)..."
deadline=$(( $(date +%s) + BRINGUP_TIMEOUT ))
while [[ ! -f "$OUTDIR/bring-up.env" ]]; do
    if ! kill -0 "$BRINGUP_PID" 2>/dev/null; then
        die "bring-up.sh exited before becoming ready — see $OUTDIR/bring-up-stdout.log"
    fi
    [[ $(date +%s) -lt $deadline ]] || die "bring-up.sh did not become ready within ${BRINGUP_TIMEOUT}s — see $OUTDIR/bring-up-stdout.log and $OUTDIR/supabase-start.log. A cold machine pulling Supabase images can legitimately need several minutes; raise DAST_BRINGUP_TIMEOUT before assuming the stack is broken."
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

# --- THE IDOR SURFACE TABLE. One row per ownership-CHECK group, which is the
# controller METHOD, not the resource: site/sections/* is served by
# SectionController, SectionGroupController, SectionItemController AND
# SectionTraceController, each hand-rolling its own scoping, so proving one
# proves nothing about the others. Likewise content.items is reachable through
# ItemController, PoolController, ItemLinkController and ManualOverrideController.
# Authorization is per-code-path — group by the code that decides, not by the
# table.
#
# THE COLLAPSE RULE, stated precisely because an imprecise one invites a false
# completeness claim: one row per DISTINCT OWNERSHIP-DECIDING CODE PATH — a
# private lookup helper (findSection/findItem/findIntent/transition) or a policy
# ability. Methods sharing a decider collapse to one row (markRead/markReplied/
# archive/restore all route through transition(); SuggestionsController::accept
# and ::dismiss share findIntent). Methods with their OWN decider get their own
# row even in the same class — which is why markSpam is listed separately from
# enquiry-read below.
#
# Columns: name | METHOD | printf path template | fixture key prefix
#
# VERB CHOICE IS DELIBERATE. Every entry is a GET, a bodyless POST or a DELETE.
# Laravel validates a FormRequest BEFORE the controller method runs, so a
# bodyless PATCH/PUT returns 422 without ever reaching the ownership check and
# tells you nothing — e.g. PATCH /api/site would 422 on UpdateSiteRequest
# before any ownership check. Where a surface offers only a write verb, the
# DELETE is used and the control consumes a dedicated fixture (see
# seed-identities.php's FIXTURE -> CONSUMER map). Never "simplify" one of these
# to a PATCH without sending a valid body.
#
# NOT SURFACES — do not add probes for these. They are enum/string-keyed, so
# there is no other tenant's value to substitute and the probe could not fail:
#   api/design-media/{purpose}, api/sections/{blockType},
#   api/content/pools/{pool} (PoolController::show(Request, string $pool) ->
#   assertPool(); it LOOKS like an id in route:list and is not one).
#
# TWO NEAR-MISSES, deliberately omitted — both carry a second tenant-owned id
# whose effective IDOR target is the PARENT, which is already probed:
#   - api/site/sections/{section}/groups/{groupKey}: {groupKey} is a
#     section-scoped string key (->where('group_key', ...)).
#   - api/site/sections/{section}/items/{item}: SectionItemController::destroy
#     scopes {item} by section_id alone, never by owner — which is sufficient,
#     because a foreign item cannot be pinned in a section you own. The parent
#     {section} is the real target and section-items below probes it.
# SectionItemController::upsert DOES carry a second, genuinely independent
# owner-scoped check on {item} (findItem + authorize 'view'), but it is a PUT
# with a required body, so a bodyless probe would 422 before authorization runs.
# Uncovered, and named here rather than quietly dropped.
IDOR_SURFACES=(
    # UserCustomerController::show -> CustomerPolicy::view -> denyAsNotFound
    "customer|GET|/api/customers/%s|CUSTOMER_ID"
    # UserEnquiryController::transition -> ->where('user_id')->find() then 404.
    # markRead/markReplied/archive/restore all delegate to transition(), so one
    # probe covers the four.
    "enquiry-read|POST|/api/enquiries/%s/read|ENQUIRY_ID"
    # markSpam does NOT delegate to transition() — it duplicates the lookup
    # inline (UserEnquiryController:56-64). A second copy of an ownership check
    # is a second thing that can be wrong, and it is the destructive one (it
    # soft-deletes a Customer and writes a spam-blocklist entry). Probed
    # separately for exactly that reason. Consumes A's enquiry state, which is
    # why it is ordered after enquiry-read.
    "enquiry-spam|POST|/api/enquiries/%s/spam|ENQUIRY_ID"
    # Three SiteMedia controllers, three DISTINCT ownership code paths
    # (gallery-image left with its routes — Wave 6, 2026-09-02). Pools are
    # not interchangeable here: UserDocumentController::destroy pool-checks
    # BEFORE authorizing, so a wrong-pool id 404s for the wrong reason.
    "upload-image|DELETE|/api/images/%s|MEDIA_IMAGE_ID"
    "document|DELETE|/api/documents/%s|MEDIA_DOCUMENT_ID"
    "content-upload|DELETE|/api/content/uploads/%s|MEDIA_CONTENT_ID"
    # ServicePolicy::view -> denyAsNotFound (both controllers)
    "service|GET|/api/services/%s|SERVICE_ID"
    "service-category|GET|/api/service-categories/%s|SERVICE_CATEGORY_ID"
    # Four separate section controllers, one shared fixture. All four bodyless
    # GETs — cheaper and non-destructive versions of the same findSection().
    "section|GET|/api/site/sections/%s|SECTION_ID"
    "section-items|GET|/api/site/sections/%s/items|SECTION_ID"
    "section-groups|GET|/api/site/sections/%s/groups|SECTION_ID"
    "section-trace|GET|/api/site/sections/%s/trace|SECTION_ID"
    # Its OWN page: deleting the section's parent would CASCADE the section away.
    "page|DELETE|/api/site/pages/%s|PAGE_DELETE_ID"
    "feedback|GET|/api/me/feedback/%s|FEEDBACK_ID"
    "restyle-undo|POST|/api/site/restyle/%s/undo|RESTYLE_ID"
    "notification-read|POST|/api/me/notifications/%s/read|NOTIFICATION_ID"
    # Four content.items code paths. content-item CONSUMES its item (removed_at),
    # so the other three use a second one.
    "content-item|DELETE|/api/content/items/%s|ITEM_ID"
    "pool-deselect|DELETE|/api/content/pools/watch/selection/%s|ITEM_POOL_ID"
    "item-link|DELETE|/api/content/items/%s/links/custom|ITEM_POOL_ID"
    # facet/column MUST match the row seed-identities.php inserts (f_text.headline
    # — App\Content\Values\ValueResolver). They are not validated by destroy(), so
    # a mismatch 404s the CONTROL for "no such override" rather than failing loudly
    # about the real cause. Caught exactly that way 2026-08-09 when the fixture
    # moved from 'core' to 'f_text' and this URL did not.
    "item-override|DELETE|/api/content/items/%s/overrides/f_text/headline|ITEM_POOL_ID"
    # Routing. setPrimary changes only is_primary, which satisfies NONE of
    # IntegrationConnectionObserver::saved's gates — no refresh, no IdentitySync,
    # no outbound fetch. dismiss (not accept) shares findIntent's ownership check
    # and stays local; accept would run SuggestionApplier.
    "routing-primary|POST|/api/routing/connections/%s/primary|CONNECTION_ID"
    "routing-suggestion-dismiss|POST|/api/routing/suggestions/%s/dismiss|INTENT_ID"
    # The one surface carrying a SECOND, independently owner-scoped id.
    # SectionItemController::upsert checks {section} via findSection AND {item}
    # via findItem($user->id, ...) + authorize 'view' (:62-67). section-items
    # above covers the first; only this covers the second, so @SECTION_ID@ is
    # pinned to identity A and %s varies — the probe sends A's OWN section with
    # B's item, which is the only way to isolate the {item} check.
    #
    # It needs a BODY: UpsertSectionItemRequest requires state IN
    # (pinned,excluded), and Laravel validates a FormRequest BEFORE the
    # controller, so a bodyless PUT 422s without ever reaching findItem. That is
    # exactly why this decider went unprobed until 2026-08-09. `excluded` is used
    # rather than `pinned` because a pin also calls nextSortKey().
    "section-item-upsert|PUT|/api/site/sections/@SECTION_ID@/items/%s|ITEM_POOL_ID|{\"state\":\"excluded\"}"
    # --- api/platforms/* — AUTHORIZATION probes only, never fuzzing.
    #
    # These paths stay in zap-context.yaml's excludePaths and in the exclusion
    # grep below: the SCAN must never touch them, because their handlers make
    # real, paid third-party calls (Apify, Google Places, OAuth). What that
    # exclusion was also blocking, until 2026-08-10, was AUTHORIZATION probing —
    # a single deliberate request per decider, which costs nothing. 200 routes
    # collapse to five ownership deciders; probing them is not fuzzing them.
    #
    # SAFETY IS PROVEN PER CONTROLLER, not assumed from the verb — the prompt's
    # own worked example of why is OnlineOrderingController::removeEntry, which
    # looks like a local DELETE and dispatches a real Apify menu scrape inline
    # under QUEUE_CONNECTION=sync. See seed-identities.php's platform-fixture
    # block for the three-part proof (owner-scoped lookup, no-op Cloudflare
    # purge, no completeness-gated site touch) and the exclusions it rules out.
    #
    # NOT PROBED, each for a proven reason — do not "close the gap" without
    # re-deriving it:
    #   OnlineOrderingController::removeEntry — its 200 path dispatches
    #     MenuFetchJob, a real MenuApifyScraper run, inline under sync queue.
    #     The 404 probe is harmless; the CONTROL is not, and a probe without a
    #     control is vacuous.
    #   ShopController::* — 'shop' is one of exactly two hasCompletenessPredicate()
    #     platforms (PlatformRegistryServiceProvider:479), so the delete path
    #     calls site->touch() -> SiteObserver:76 -> SyncSubdomainToKvJob: a real
    #     Cloudflare KV write.
    #   MenuContentController::* — every write ends in touchAndRespond() ->
    #     invalidator->touchSite() -> the same SiteObserver KV path, and it is
    #     also the only platform decider whose fixture is not a
    #     site.platform_connections row.
    #   DisplaySettingsController::*, RefreshController::* — {platform} is a
    #     registry surface key, not a tenant-owned id; there is no other
    #     tenant's value to substitute. Refresh is outbound by definition.
    "platform-account|DELETE|/api/platforms/spotify/accounts/%s|PLATFORM_ACCOUNT_ID"
    "platform-events-account|DELETE|/api/platforms/eventbrite/accounts/%s|PLATFORM_EVENTS_ACCOUNT_ID"
    "platform-events-event|DELETE|/api/platforms/eventbrite/events/%s|PLATFORM_EVENT_ID"
)

# The fixture prefixes named by the table, derived once. Used both to validate
# identities.json and to resolve @PREFIX@ tokens.
IDOR_PREFIXES=()
while IFS= read -r p; do
    IDOR_PREFIXES+=("$p")
done < <(printf '%s\n' "${IDOR_SURFACES[@]}" | cut -d'|' -f4 | sort -u)

# Build one URL: %s becomes the varying IDOR target, @PREFIX@ becomes identity
# A's fixture of that name. A parent id in the path MUST stay A's — substituting
# B's would make the probe fail at the parent check and never exercise the child
# one, which is a vacuous pass wearing a green tick.
idor_url() {
    local template="$1" target="$2" out p var
    out="${template//%s/$target}"
    for p in "${IDOR_PREFIXES[@]}"; do
        var="${p}_A"
        out="${out//@${p}@/${!var}}"
    done
    printf '%s' "$out"
}

# Emit the requestor entries: per surface, a CONTROL on A's own row expecting
# 200 immediately followed by the PROBE on B's row expecting 404. Generated
# rather than hand-written so a surface can never acquire a probe without its
# control — the pairing is structural, not a convention someone has to remember.
idor_requests() {
    local surface name method template prefix body a_var b_var
    for surface in "${IDOR_SURFACES[@]}"; do
        IFS='|' read -r name method template prefix body <<<"$surface"
        a_var="${prefix}_A"
        b_var="${prefix}_B"
        idor_emit "control-own-$name" "$method" "$(idor_url "$template" "${!a_var}")" 200 "$body"
        idor_emit "idor-$name"        "$method" "$(idor_url "$template" "${!b_var}")" 404 "$body"
    done
}

# One requestor entry. `data` and the Content-Type header are emitted only when
# the surface declares a body — verified against ZAP 2.17.0's own schema
# (`zap.sh -cmd -autogenmax`), because an unrecognised field is a WARNING that
# ZAP drops silently, which is how token injection stayed dead for months. The
# plan-integrity grep above turns that into a hard failure, but getting the
# field name right beats relying on the guard.
idor_emit() {
    local name="$1" method="$2" url="$3" code="$4" body="$5"
    # Record the URL for the exclusion check below. idor_requests() runs inside a
    # command substitution, so a shell variable would not survive — a file does.
    printf '%s%s\n' "$ZAP_TARGET_URL" "$url" >> "$ZAP_WORK/idor-urls.txt"
    printf '      - url: "%s%s"\n' "$ZAP_TARGET_URL" "$url"
    printf '        method: %s\n' "$method"
    printf '        name: %s\n' "$name"
    printf '        headers:\n          - "Authorization: Bearer %s"\n' "$TOKEN_A"
    if [[ -n "$body" ]]; then
        printf '          - "Content-Type: application/json"\n'
        printf "        data: '%s'\n" "$body"
    fi
    printf '        responseCode: %s\n' "$code"
}

EMAIL_A=$(jq -r .A.email "$OUTDIR/identities.json")
PASS_A=$(jq -r .A.password "$OUTDIR/identities.json")
EMAIL_B=$(jq -r .B.email "$OUTDIR/identities.json")
PASS_B=$(jq -r .B.password "$OUTDIR/identities.json")
# Both identities' ids: B's drive the IDOR probes, A's drive the paired
# CONTROLS. A control per probe is not redundancy — see the requestor job.
#
# One shell var per fixture per identity, and the key list is DERIVED FROM
# IDOR_SURFACES rather than restated — a hand-maintained second list is exactly
# the drift this whole exercise is about. Adding a surface therefore requires
# nothing here.
#
# The null check is load-bearing: `jq -r` prints the string "null" for a missing
# key rather than failing, so a seeder/probe drift would otherwise reach ZAP as
# the literal URL .../api/services/null — which 404s, satisfying every idor-*
# assertion while testing nothing. (Its paired control would 404 too and fail the
# lane, so it WOULD be caught — but as a confusing control failure 12 minutes in,
# rather than as a named missing fixture before ZAP even starts.)
# `tr`, not ${prefix,,} — macOS ships bash 3.2 and case modification is bash 4+.
# Same constraint lib/diff-baseline.sh's header records; this lane is developed
# and run on macOS, so a bash-4-ism here is a hard break, not a portability nit.
for prefix in "${IDOR_PREFIXES[@]}"; do
    key=$(printf '%s' "$prefix" | tr 'A-Z' 'a-z')
    for who in A B; do
        value=$(jq -r ".${who}.${key} // \"null\"" "$OUTDIR/identities.json")
        [[ "$value" != "null" && -n "$value" ]] \
            || die "identities.json is missing .${who}.${key} — seed-identities.php and the IDOR_SURFACES table have drifted. Add the fixture; do NOT drop the probe."
        printf -v "${prefix}_${who}" '%s' "$value"
    done
done

TOKEN_A=$(php "$HERE/active/mint-jwt.php" "$EMAIL_A" "$PASS_A")
TOKEN_B=$(php "$HERE/active/mint-jwt.php" "$EMAIL_B" "$PASS_B")
[[ -n "$TOKEN_A" && -n "$TOKEN_B" ]] || die "failed to mint one or both tokens"
log "zap-active: identities seeded, tokens minted"

ZAP_WORK="$OUTDIR/zap"
mkdir -p "$ZAP_WORK"
# Cleared before idor_emit appends to it — OUTDIR is per-date-and-lane, so a
# re-run on the same day would otherwise inherit the previous run's list and
# quietly widen the exclusion check's allowlist. Same class of staleness bug as
# the bring-up.env rm at the top of this script.
rm -f "$ZAP_WORK/idor-urls.txt"
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
  # source for EVERY surface in IDOR_SURFACES above. A 200 here means broken
  # tenant isolation.
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
$(idor_requests)

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
# --add-host IS REQUIRED ON LINUX and harmless on Docker Desktop.
# host.docker.internal (the target this plan points at, see ZAP_TARGET_URL) is a
# Docker DESKTOP convenience name — it resolves for free on macOS/Windows and does
# not exist at all on Linux, where the equivalent is an explicit mapping to
# host-gateway. Docker Desktop accepts the same flag and resolves it identically,
# so this is unconditional rather than OS-sniffed.
#
# Without it on a runner, every request dies with
# `ZapUnknownHostException: host.docker.internal: Name or service not known`,
# ZAP exits in ~13 seconds having reached nothing, and the report is EMPTY —
# which a naive severity gate reads as "clean". GitHub run 31379025721 is the
# worked example: openapi, both spiders, all three activeScans and the whole
# requestor job produced nothing, and diff-baseline would happily have called it
# a pass. What actually caught it was the count floor in front of the IDOR gate
# ("expected 58 requestor requests ... the run log shows 0") — an absence check
# added precisely because a green log proves nothing about what was attempted.
docker run --rm \
    --add-host=host.docker.internal:host-gateway \
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
# source: the YAML needs regexes per context, this needs one grep). Pinned both
# ways by tests/Feature/Architecture/DastSelfDestructionExclusionGuardTest.php,
# which asserts each alternation term equals its excludePaths entry with the
# leading/trailing `.*` stripped — so the terms carry their OWN trailing slash
# rather than sharing one after the group.
#
# That shared trailing `/` was a real hole, found 2026-08-09 while writing that
# test: `api/(...|staff/builds|site/custom-domain|me/site/reclaim-handle|...)/`
# required a slash AFTER each term, so it could never match the exact routes
# `POST api/staff/builds`, `GET|PUT|DELETE api/site/custom-domain` or
# `POST api/me/site/reclaim-handle` — three of the very paths it names.
#
# WHAT THIS CHECK CAN AND CANNOT SEE. It greps the run log, and ZAP only logs
# URLs for the requestor job's own requests plus spider SEED/error lines. The
# spider reports discoveries as a bare "Job spider found N URLs" with no
# listing, and activeScan logs nothing per request. So this cannot observe what
# the spider or the active scanner actually requested, and a green result here
# is NOT proof that no excluded path was ever hit — excludePaths correctness
# rests on the YAML. What it does catch is this script itself sending an
# excluded URL (e.g. a new IDOR probe pointing somewhere it should not).
# Verified 2026-08-09 against audits/dast/2026-08-09-active/zap/zap-run.log:
# every api/ line in it is one of the 44 requestor entries. Do not upgrade this
# comment to a proof claim without first raising ZAP's log level or gating on
# the site tree in zap-report.json instead.
#
# The `sessions/|me/deletion/|account/mfa/` entries are the self-destruction
# group added 2026-08-07; `api/sessions/` keeps its trailing slash deliberately
# so the in-scope `GET api/sessions` collection read is NOT matched.
# THE REQUESTOR'S OWN DECLARED URLS ARE FILTERED OUT FIRST, and that carve-out is
# the narrowest one that works. Since 2026-08-10 the IDOR table probes five
# api/platforms/* deciders (authorization, one request each — NOT fuzzing), and
# ZAP logs every requestor URL as "Job requestor requesting URL ...". Without this
# filter the check matches the lane's OWN deliberate probes and kills the run
# ~15 minutes in.
#
# Filtering by EXACT URL, not by dropping requestor lines wholesale: the check's
# stated value is "this script itself never sent an excluded URL — e.g. a new IDOR
# probe pointing somewhere it should not", and dropping every requestor line would
# throw that away entirely. idor-urls.txt holds precisely the URLs IDOR_SURFACES
# declares, generated by idor_emit alongside the plan, so an excluded URL that is
# NOT in the table still fails the lane. Pinned by
# DastSelfDestructionExclusionGuardTest, which asserts the filter is this
# allowlist and not a blanket one.
if grep -vFf "$ZAP_WORK/idor-urls.txt" "$ZAP_WORK/zap-run.log" \
    | grep -qE "api/(platforms/|staff/builds|site/custom-domain|me/site/reclaim-handle|staff/sites/|sessions/|me/deletion/|account/mfa/)"; then
    die "exclusion verification FAILED: an excluded path that is NOT a declared IDOR probe appears in the run log:
$(grep -vFf "$ZAP_WORK/idor-urls.txt" "$ZAP_WORK/zap-run.log" | grep -E "api/(platforms/|staff/builds|site/custom-domain|me/site/reclaim-handle|staff/sites/|sessions/|me/deletion/|account/mfa/)" | sort -u | head -20)
see $ZAP_WORK/zap-run.log"
fi
log "zap-active: exclusion check passed — no excluded path in the run log beyond the ${#IDOR_SURFACES[@]} declared IDOR probes"

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
# COUNT FLOOR FIRST. Everything below keys off the ABSENCE of a mismatch line,
# and absence is exactly the shape of failure this whole lane exists to kill: if
# the requestor job never ran, or emitted fewer requests than the table declares,
# "IDOR assertions passed" prints just the same. The fixture-drift die() up in the
# read loop catches a MISSING key; this catches an empty array, a malformed
# template, and a requestor job ZAP declined to run.
IDOR_SENT=$(grep -c "Job requestor requesting URL" "$ZAP_WORK/zap-run.log" || true)
IDOR_EXPECTED=$(( 2 * ${#IDOR_SURFACES[@]} ))
[[ "$IDOR_SENT" -eq "$IDOR_EXPECTED" ]] || die "IDOR gate is VACUOUS — expected $IDOR_EXPECTED requestor requests (${#IDOR_SURFACES[@]} surfaces x control+probe), the run log shows $IDOR_SENT.
A passing assertion check below would prove nothing. see $ZAP_WORK/zap-run.log"
log "zap-active: IDOR request count check passed — $IDOR_SENT/$IDOR_EXPECTED requests issued"

if grep -q "Difference in response code values" "$ZAP_WORK/zap-run.log"; then
    die "IDOR/control assertion FAILED — a cross-identity probe or its control returned an unexpected status:
$(grep "Difference in response code values" "$ZAP_WORK/zap-run.log" | sort -u)
A 'control-*' line means the pass was vacuous (bad token / dead app / missing route), NOT that authorization is fine.
An 'idor-*' line means a foreign resource was reachable — treat as a P0 authorization finding, never relax the assertion.
see $ZAP_WORK/zap-run.log"
fi
log "zap-active: IDOR assertions passed — foreign resources 404'd and every control returned 200"
