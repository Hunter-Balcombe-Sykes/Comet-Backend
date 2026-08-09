#!/usr/bin/env bash
#
# Tier 2 — auth-layer probes. Tier 1 (tests/Authz/) stubs VerifySupabaseJwt and
# runs in-process, so it can only prove "the Policy said no". These prove the
# TOKEN is rejected, and that a real concurrent race has one winner.
#
# Invoked by zap-active.sh against a live bring-up.sh stack. Never CI, never
# cron — same policy as the rest of scripts/dast/active/.
#
# Usage: tier2.sh <outdir>   (the same OUTDIR bring-up.sh was given)
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DAST_DIR="$HERE"
# shellcheck source=../lib/common.sh
source "$HERE/lib/common.sh"

OUTDIR="${1:?usage: tier2.sh <outdir>}"
[[ -f "$OUTDIR/bring-up.env" ]] || die "tier2: $OUTDIR/bring-up.env missing — run bring-up.sh first"
# shellcheck disable=SC1091
source "$OUTDIR/bring-up.env"
IDENTITIES="$OUTDIR/identities.json"
[[ -f "$IDENTITIES" ]] || die "tier2: $IDENTITIES missing — run seed-identities.php first"

# bring-up.env publishes APP_PORT, not APP_URL (see bring-up.sh Step 7) — the
# served app is always on loopback, so compose the base URL here rather than
# adding a redundant key to that contract.
BASE="http://127.0.0.1:${APP_PORT:?APP_PORT not exported by bring-up.env}"

# A no-param staff GET behind require.aal2 — resolved from the real route table
# (routes/api/staff.php:35-41), not guessed. Note the group's middleware order:
# supabase.jwt → require.email_verified → staff → require.aal2, which is why the
# caller must be genuinely staff or the `staff` gate answers 403 first and the
# probe would be asserting the wrong middleware.
STAFF_AAL2_PATH="/api/staff/me"

TIER2_FAILURES=0
TIER2_LOG="$OUTDIR/tier2.md"
: > "$TIER2_LOG"
printf '# Tier 2 — auth-layer probes\n' >> "$TIER2_LOG"

# Deliberately NOT `set -e`: every probe must run so the report is complete,
# and a non-zero curl must not abort the lane mid-suite.
expect_status() {
    local expected="$1" actual="$2" label="$3"
    if [[ "$actual" == "$expected" ]]; then
        log "tier2: PASS  $label ($actual)"
        printf -- '- PASS `%s` — %s\n' "$actual" "$label" >> "$TIER2_LOG"
    else
        log "tier2: FAIL  $label — expected $expected, got $actual"
        printf -- '- **FAIL** expected `%s`, got `%s` — %s\n' "$expected" "$actual" "$label" >> "$TIER2_LOG"
        TIER2_FAILURES=$((TIER2_FAILURES + 1))
    fi
}

mint() {  # mint <email> <password> -> access token on stdout
    php "$HERE/active/mint-jwt.php" "$1" "$2"
}

b64url_decode() {
    local d="${1//-/+}"; d="${d//_//}"
    case $(( ${#d} % 4 )) in 2) d="$d==" ;; 3) d="$d=" ;; esac
    printf '%s' "$d" | openssl base64 -d -A 2>/dev/null
}
b64url_encode() { openssl base64 -A | tr '+/' '-_' | tr -d '='; }

probe_status() {  # probe_status <token> <path> -> HTTP code on stdout
    curl -s -o /dev/null -w '%{http_code}' \
        -H "Authorization: Bearer $1" "$BASE$2"
}

# ── Group 1: JWT tampering ───────────────────────────────────────────────────
# Mutations of a REAL token, never a minted one: GoTrue validates session_id
# against auth.sessions, so a from-scratch forgery is rejected for the wrong
# reason (403 session_not_found) and would prove nothing about VerifySupabaseJwt.
# See active/mint-jwt.php's docblock — spiked 2026-07-26.
tier2_jwt_tamper() {
    printf '\n## JWT tampering\n\n' >> "$TIER2_LOG"

    local email password token
    email="$(jq -r '.A.email' "$IDENTITIES")"
    password="$(jq -r '.A.password' "$IDENTITIES")"
    token="$(mint "$email" "$password")"
    [[ -n "$token" ]] || die "tier2: could not mint a token for $email"

    # Control: the untampered token must WORK. Without this the three
    # rejections below could all be caused by a broken fixture or a dead app.
    expect_status 200 "$(probe_status "$token" /api/me)" "control — a real token is accepted"

    local hdr payload sig
    IFS='.' read -r hdr payload sig <<< "$token"

    # 1. Flipped signature — one character changed, everything else valid.
    #
    # The FIRST character, not the last. A 32-byte HMAC signature is 43
    # base64url characters, but 43 × 6 = 258 bits for a 256-bit value: the final
    # character's two low bits are discarded on decode. Mutating the LAST
    # character between values that differ only in those bits (e.g. 'A'→'B')
    # produces byte-identical signature bytes — the token stays VALID and the
    # probe passes 200 while claiming to have tampered with it. Verified against
    # the live stack 2026-07-30. Every bit of the first character is significant.
    local first repl
    first="${sig:0:1}"
    if [[ "$first" == "A" ]]; then repl="B"; else repl="A"; fi
    expect_status 401 "$(probe_status "${hdr}.${payload}.${repl}${sig:1}" /api/me)" \
        "flipped signature is rejected"

    # 2. alg=none downgrade with the signature stripped — the classic
    #    accept-unsigned-tokens bug.
    local hdr_none
    hdr_none="$(b64url_decode "$hdr" | sed 's/"alg":"[^"]*"/"alg":"none"/' | b64url_encode)"
    expect_status 401 "$(probe_status "${hdr_none}.${payload}." /api/me)" \
        "alg=none with no signature is rejected"

    # 3. alg downgraded to HS256 against an RS256/ES256 key — signature confusion.
    local hdr_hs
    hdr_hs="$(b64url_decode "$hdr" | sed 's/"alg":"[^"]*"/"alg":"HS256"/' | b64url_encode)"
    expect_status 401 "$(probe_status "${hdr_hs}.${payload}.${sig}" /api/me)" \
        "alg downgraded to HS256 is rejected"

    # 4. A GENUINELY expired token — real signature, elapsed exp. Requires the
    #    short jwt_expiry bring-up.sh writes into the SCRATCH config; a mutated
    #    exp would break the signature and duplicate probe 1. Read the effective
    #    lifetime off the real token rather than assuming the override landed.
    #    The wait is exp + LEEWAY, not exp: VerifySupabaseJwt sets
    #    JWT::$leeway = config('supabase.jwt_leeway_seconds') (default 60) to
    #    tolerate Supabase clock skew, so an 8s token is legitimately accepted
    #    for 68s. Sleeping only past `exp` returns 200 and looks like a critical
    #    auth bug when it is documented, deliberate behaviour — read the real
    #    value rather than assuming either number.
    local short_token expiry leeway wait_for
    expiry="$(jq -r '.exp - .iat' <<< "$(b64url_decode "$payload")" 2>/dev/null)"
    leeway="$(php "$HERE/../../artisan" --env=dast config:show supabase 2>/dev/null \
        | awk '/jwt_leeway_seconds/ {print $NF}')"
    [[ "$leeway" =~ ^[0-9]+$ ]] || leeway=60
    wait_for=$(( ${expiry:-0} + leeway + 3 ))
    if [[ "$expiry" =~ ^[0-9]+$ ]] && [[ "$wait_for" -le 120 ]]; then
        short_token="$(mint "$email" "$password")"
        expect_status 200 "$(probe_status "$short_token" /api/me)" \
            "control — the short-lived token works before expiry"
        sleep "$wait_for"
        expect_status 401 "$(probe_status "$short_token" /api/me)" \
            "an expired token is rejected after ${wait_for}s (exp ${expiry}s + ${leeway}s leeway)"
    else
        log "tier2: SKIP  expired-exp probe — exp ${expiry}s + ${leeway}s leeway is too long to wait (re-run with DAST_JWT_EXPIRY=8 to exercise it)"
        printf -- '- SKIP expired-`exp` — `jwt_expiry` is %ss and `jwt_leeway_seconds` is %ss, so a real expiry needs a %ss wait. This is EXPECTED on a default run: bring-up.sh defaults `jwt_expiry` long so ZAP tokens survive the full plan. To exercise this probe, re-run with `DAST_JWT_EXPIRY=8` — that run trades authenticated ZAP scanning for it. See scripts/dast/README.md for why an expired token cannot be forged.\n' \
            "$expiry" "$leeway" "$wait_for" >> "$TIER2_LOG"
    fi
}

# ── Group 2: real aal1 token vs require.aal2 ─────────────────────────────────
# A password-grant sign-in is aal1 by construction — AAL2 exists only after a
# TOTP challenge — so no special minting is needed. RequireAal2 answers 401 with
# error='mfa_required' (not 403), so the frontend can trigger a step-up
# (app/Http/Middleware/Auth/RequireAal2.php:25-31).
#
# Tier 1's StaffBoundaryTest already asserts "refuses staff claims at aal1", but
# against a STUBBED JWT — it proves the policy reads the attribute. This proves
# the real middleware rejects a real token.
tier2_aal1_staff() {
    printf '\n## Real aal1 staff token vs require.aal2\n\n' >> "$TIER2_LOG"

    local email password token
    email="$(jq -r '.staff.email' "$IDENTITIES")"
    password="$(jq -r '.staff.password' "$IDENTITIES")"
    token="$(mint "$email" "$password")"
    [[ -n "$token" ]] || die "tier2: could not mint a staff token for $email"

    # Control: the token is valid on a non-AAL2 route. Without this a 401 below
    # could just mean "bad token" rather than "middleware enforced AAL2".
    expect_status 200 "$(probe_status "$token" /api/me)" \
        "control — the staff token is itself valid"

    local code body
    body="$(mktemp)"
    code="$(curl -s -o "$body" -w '%{http_code}' -H "Authorization: Bearer $token" "$BASE$STAFF_AAL2_PATH")"
    expect_status 401 "$code" "aal1 staff token refused by require.aal2 on $STAFF_AAL2_PATH"

    # The CODE matters as much as the status: a generic 401 would mean the token
    # was not recognised at all, and a 403 would mean the `staff` gate stopped it
    # first — both are different (and untested) failures.
    if [[ "$(jq -r '.error // empty' "$body")" == "mfa_required" ]]; then
        log "tier2: PASS  require.aal2 returned error=mfa_required"
        printf -- '- PASS `mfa_required` — the 401 came from require.aal2, not from token rejection\n' >> "$TIER2_LOG"
    else
        log "tier2: FAIL  expected error=mfa_required, got $(jq -c . "$body" 2>/dev/null)"
        printf -- '- **FAIL** expected `error=mfa_required`, got `%s`\n' "$(jq -c . "$body" 2>/dev/null)" >> "$TIER2_LOG"
        TIER2_FAILURES=$((TIER2_FAILURES + 1))
    fi
    rm -f "$body"
}

# ── Group 3: claim race ──────────────────────────────────────────────────────
# N distinct claimants, one build, fired in parallel. Exactly one 2xx; the rest
# 409 ALREADY_CLAIMED. ClaimSiteService::claim() serialises on lockForUpdate()
# over the core.users row (NOT "FOR UPDATE SKIP LOCKED" — that is the prune
# path), and losers then observe auth_user_id != null.
#
# This is the probe PHPUnit cannot express: lockForUpdate() only serialises
# across genuinely separate connections, so it needs OS processes, not
# sequential in-process requests.
#
# DISTINCT claimants, not one token N times: throttle:claim is keyed on
# 'claim:'.$supabase_uid (AppServiceProvider.php:754+), so repeating one
# identity would return 429s and prove nothing. It is also the truer scenario —
# different people, first-come.
tier2_claim_race() {
    printf '\n## Claim race\n\n' >> "$TIER2_LOG"

    local subdomain n tmp i
    subdomain="$(jq -r '.firstComeBuild.subdomain' "$IDENTITIES")"
    n="$(jq -r '.claimants | length' "$IDENTITIES")"
    [[ "$n" -ge 2 ]] || die "tier2: need >= 2 claimants to race, got $n"
    tmp="$(mktemp -d)"

    # Mint every token FIRST. Minting inside the race would serialise the
    # requests behind GoTrue's own latency and the race would not be a race.
    for (( i = 0; i < n; i++ )); do
        local e p
        e="$(jq -r ".claimants[$i].email" "$IDENTITIES")"
        p="$(jq -r ".claimants[$i].password" "$IDENTITIES")"
        mint "$e" "$p" > "$tmp/token-$i" || die "tier2: mint failed for claimant $i"
        [[ -s "$tmp/token-$i" ]] || die "tier2: empty token for claimant $i"
    done

    for (( i = 0; i < n; i++ )); do
        (
            curl -s -o "$tmp/body-$i" -w '%{http_code}' \
                -X POST "$BASE/api/claim" \
                -H "Authorization: Bearer $(cat "$tmp/token-$i")" \
                -H 'Content-Type: application/json' \
                -H 'Accept: application/json' \
                -d "{\"subdomain\":\"$subdomain\"}" > "$tmp/code-$i"
        ) &
    done
    wait

    local winners losers throttled
    winners=0; losers=0; throttled=0
    for (( i = 0; i < n; i++ )); do
        case "$(cat "$tmp/code-$i")" in
            2*)  winners=$((winners + 1)) ;;
            409) losers=$((losers + 1)) ;;
            429) throttled=$((throttled + 1)) ;;
        esac
    done

    expect_status 1 "$winners" "exactly one claimant won the race (of $n)"
    expect_status "$((n - 1))" "$losers" "every other claimant got 409 ALREADY_CLAIMED"
    # A 429 means the limiter keyed on something other than supabase_uid and the
    # race was never actually contended — the assertion above would pass for the
    # wrong reason.
    expect_status 0 "$throttled" "no claimant was rate-limited (race genuinely contended)"

    # ALREADY_CLAIMED is only reachable AFTER lockForUpdate() returns. A losing
    # body of CLAIM_NOT_FOUND or ACCOUNT_EXISTS instead would mean the losers
    # never contended and the one-winner assertion passed vacuously.
    local already
    already="$(grep -l 'ALREADY_CLAIMED' "$tmp"/body-* 2>/dev/null | wc -l | tr -d ' ')"
    if [[ "$already" -ge 1 ]]; then
        log "tier2: PASS  $already loser(s) reported ALREADY_CLAIMED (post-lock branch)"
        printf -- '- PASS `ALREADY_CLAIMED` × %s — losers reached the post-lock branch, so the race was real\n' "$already" >> "$TIER2_LOG"
    else
        log "tier2: FAIL  no loser reported ALREADY_CLAIMED — the race was vacuous"
        printf -- '- **FAIL** no loser reported `ALREADY_CLAIMED` — losers never contended; fix the fixture, do not relax this probe\n' >> "$TIER2_LOG"
        TIER2_FAILURES=$((TIER2_FAILURES + 1))
    fi

    printf -- '\n<details><summary>claim race responses</summary>\n\n```\n' >> "$TIER2_LOG"
    for (( i = 0; i < n; i++ )); do
        printf '%s %s\n' "$(cat "$tmp/code-$i")" "$(jq -c '.code // .message // empty' "$tmp/body-$i" 2>/dev/null)" >> "$TIER2_LOG"
    done
    printf '```\n\n</details>\n' >> "$TIER2_LOG"
    rm -rf "$tmp"
}

main() {
    tier2_jwt_tamper
    tier2_aal1_staff
    tier2_claim_race

    printf '\n**%s**\n' "$([[ "$TIER2_FAILURES" -eq 0 ]] && echo 'Tier 2: all probes passed.' || echo "Tier 2: $TIER2_FAILURES probe(s) FAILED.")" >> "$TIER2_LOG"
    [[ "$TIER2_FAILURES" -eq 0 ]] || die "tier2: $TIER2_FAILURES probe(s) failed — see $TIER2_LOG"
    log "tier2: all probes passed"
}

main "$@"
