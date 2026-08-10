#!/usr/bin/env bash
# Active lane: isolated, runner-owned Supabase bring-up on a port offset
# (dodges Comet's 54321-54327), migrated fresh, served against a scratch
# .env.dast, health-checked, and torn down via `trap` on EVERY exit path —
# including a mid-boot abort. NEVER point this at real dev/prod; it mutates
# and fuzzes its own throwaway local stack only.
#
# Usage: bring-up.sh OUTDIR
# On success, writes $OUTDIR/bring-up.env (sourceable KEY=VALUE facts:
# API_PORT, DB_PORT, APP_PORT, SUPABASE_URL, JWT_SECRET, ANON_KEY,
# SERVICE_ROLE_KEY, SCRATCH, ENV_DAST) for later phases to read, then
# BLOCKS — holding the stack + served app up — until signaled (SIGTERM/
# SIGINT) or the served app process dies on its own. Run it as a
# background child: start it, poll for bring-up.env to appear (readiness),
# source it, run the dependent phases, then `kill` this process — the
# trap tears everything down on that signal. This is also how Phase 1
# verifies itself standalone (run it, Ctrl-C it, prove the teardown).
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DAST_DIR="$HERE"
REPO_ROOT="$(cd "$HERE/../.." && pwd)"
# shellcheck source=../lib/common.sh
source "$HERE/lib/common.sh"

OUTDIR="$(dast_abspath "${1:?usage: bring-up.sh OUTDIR}")"
OFFSET="${DAST_SUPABASE_PORT_OFFSET:-100}"
API_PORT=$(( 54321 + OFFSET ))
DB_PORT=$(( 54322 + OFFSET ))
# ZAP_TARGET_LOCAL comes from scripts/dast/.env, which lib/common.sh sources only
# IF PRESENT — so on a machine that never ran the one-time `cp .env.example .env`
# it is unset. The fallback on the third line below was always meant to cover
# exactly that; the problem was that `set -u` killed the script one line earlier.
#
# THE VERSION TRAP, and it is the INVERSE of the bash-3.2 one this tool documents
# everywhere else. Measured 2026-08-10:
#
#   bash 3.2 (macOS, what this lane is developed on): `${VAR##*:}` on an UNSET var
#     under `set -u` does NOT error — it yields empty, the regex check below fails,
#     and APP_PORT falls back to 8100. Everything works.
#   bash 4.4+ / 5.x (Linux, GitHub runners): the same expansion IS an unbound-
#     variable error and aborts.
#
# So this was invisible for as long as the lane was manual-only and macOS-only.
# It surfaced within one second of the lane first running on a GitHub runner
# (2026-08-10, run 31375156868): bring-up.sh died with "ZAP_TARGET_LOCAL: unbound
# variable" while zap-active.sh reported only the generic "exited before becoming
# ready" — which is why that job uploads bring-up-stdout.log on failure.
#
# `bash -n` cannot catch this, and neither can running the lane locally. Elsewhere
# in this tool the rule is "no bash-4-isms, macOS ships 3.2"; this is the other
# direction — 3.2 ACCEPTING something modern bash rejects — and now that the lane
# runs in CI, both directions have to hold.
#
# Two steps because bash cannot combine a default and a substring removal in one
# expansion: take the default first, then strip.
APP_PORT="${ZAP_TARGET_LOCAL:-}"
APP_PORT="${APP_PORT##*:}"
[[ "$APP_PORT" =~ ^[0-9]+$ ]] || APP_PORT=8100

# --- Step 1: scratch config with a port offset. The committed
# supabase/config.toml (54321-54329, colliding with Comet by design) is
# NEVER edited — materialize a scratch workdir instead.
#
# Deviation from the plan's sed one-liner: sed's replacement text is not
# shell-evaluated, so `s/port = (543NN)/port = $((\1+OFFSET))/` would write
# the LITERAL string "$((54321+100))" into the TOML file — invalid (TOML
# wants an integer, not an unevaluated shell expression). Verified
# 2026-07-26. Using a bash read-loop with BASH_REMATCH + arithmetic instead.
SCRATCH="$(mktemp -d)/dast-supabase"
mkdir -p "$SCRATCH/supabase"
in_captcha_section=0
while IFS= read -r line || [[ -n "$line" ]]; do
    if [[ "$line" =~ ^\[.*\]$ ]]; then
        [[ "$line" == "[auth.captcha]" ]] && in_captcha_section=1 || in_captcha_section=0
    fi
    if [[ "$line" =~ ^port\ =\ (543[0-9]{2})$ ]]; then
        echo "port = $(( ${BASH_REMATCH[1]} + OFFSET ))"
    elif [[ "$line" =~ ^project_id\ = ]]; then
        echo 'project_id = "partna-dast"'
    elif [[ "$line" =~ ^jwt_expiry\ = ]]; then
        # DEFAULT IS LONG, and that is a deliberate trade — read this before
        # "restoring" the old 8s default.
        #
        # `jwt_expiry` is a SERVER-WIDE GoTrue setting: it governs every token
        # the scratch stack issues, not just Tier 2's. It used to default to 8s
        # so Tier 2 could wait out a real expiry (an expired token cannot be
        # forged — changing `exp` invalidates the signature, making it
        # indistinguishable from the flipped-signature probe).
        #
        # But ZAP's tokens are minted ONCE, before a plan that takes ~10min to
        # reach its last job. At 8s + 60s app-side leeway they were dead within
        # ~68s, so every request past that point carried a stale token and got
        # 401. Found 2026-08-07 the only way it could be found: the new IDOR
        # controls failed with "Expected : 200 Received : 401". Before those
        # controls existed this degraded in total silence — the "authenticated"
        # passes were fuzzing 401 responses and reporting clean.
        #
        # The two needs cannot both be met in one stack: Tier 2 needs
        # exp + leeway <= 120s (its own wait cap), ZAP needs exp to outlive the
        # whole plan. So the DEFAULT serves ZAP — authenticated fuzzing of ~490
        # endpoints plus a real cross-tenant IDOR check — and Tier 2's expiry
        # probe SKIPs loudly, which is its own documented fallback.
        #
        # To exercise the expiry probe instead, run the lane with a short value:
        #   DAST_JWT_EXPIRY=8 scripts/dast/run.sh --only active
        # That run's ZAP pass is unauthenticated, so it is an auth-probe run and
        # NOT a substitute for the default one.
        #
        # Same test-only-override pattern as the captcha branch below: the
        # committed config.toml is never touched.
        echo "jwt_expiry = ${DAST_JWT_EXPIRY:-3600}"
    elif [[ "$in_captcha_section" -eq 1 && "$line" =~ ^enabled\ = ]]; then
        # Turnstile captcha needs a real Cloudflare secret to verify against
        # (SUPABASE_AUTH_CAPTCHA_SECRET is unset here) — without it, GoTrue's
        # password grant 500s with "captcha verification process failed"
        # (verified 2026-07-26). This is a headless testing tool with no
        # browser to supply a captcha token; disable it in the scratch copy
        # only — the committed config.toml (real dev/prod local stacks) is
        # never touched.
        echo "enabled = false"
    else
        echo "$line"
    fi
done < "$REPO_ROOT/supabase/config.toml" > "$SCRATCH/supabase/config.toml"
ln -s "$REPO_ROOT/supabase/migrations" "$SCRATCH/supabase/migrations"

# --- Step 2: retried `supabase start` + trap teardown — trap set BEFORE
# start so a mid-boot failure still tears down. Phase-1 spike (verified
# 2026-07-26): `supabase start --workdir "$SCRATCH"` honours the scratch
# config.toml + migrations symlink as-is; no fallback to copy-then-cd
# needed — confirmed with a real bring-up + clean `supabase stop
# --no-backup` teardown (docker ps showed zero partna-dast containers after).
SERVE_PID=""
teardown() {
    local status=$?
    # Re-entrancy guard. The trap split below should make a second call
    # impossible; this is belt-and-braces, because the failure mode when it DOES
    # happen is a second `rm -rf` racing whatever is still running.
    [[ "${teardown_ran:-0}" -eq 1 ]] && return
    teardown_ran=1
    log "bring-up: tearing down (exit was $status)"
    [[ -n "$SERVE_PID" ]] && kill "$SERVE_PID" 2>/dev/null || true
    ( cd "$REPO_ROOT" && supabase stop --workdir "$SCRATCH" --no-backup >/dev/null 2>&1 ) || true
    rm -rf "$SCRATCH"
    rm -f "$REPO_ROOT/.env.dast"   # written at $REPO_ROOT (not $SCRATCH) — see ENV_DAST below
}

# SIGNALS EXIT; THEY DO NOT CLEAN UP IN PLACE. This split matters and was a real
# bug until 2026-08-10 — `trap teardown EXIT INT TERM` looks equivalent and is not.
#
# A signal handler that returns instead of exiting hands control back to the
# script AT THE POINT IT WAS INTERRUPTED — except teardown has just deleted
# $SCRATCH and killed the served app. Everything after that runs against state
# that no longer exists, and it fails with an error about the SYMPTOM rather than
# the cause.
#
# Observed on GitHub run 31376712687, and it cost three runs to unpick:
#   zap-active.sh's readiness poll timed out -> it kill(1)s this process ->
#   TERM trap runs teardown, logs "tearing down (exit was 0)", RETURNS ->
#   the script resumes at `supabase db reset` -> that dies with
#   "failed to change workdir: ... no such file or directory" because teardown
#   removed the workdir a moment earlier -> set -e exits -> the EXIT trap fires
#   teardown a SECOND time, logging "tearing down (exit was 1)".
#
# The two teardown lines and the misleading chdir error were the only evidence,
# and both are downstream of the real cause. Now INT/TERM exit immediately with
# the conventional 128+signal status, the EXIT trap runs teardown exactly once,
# and the first failure reported is the actual one.
teardown_ran=0
trap 'teardown' EXIT
trap 'exit 130' INT    # 128 + SIGINT(2)
trap 'exit 143' TERM   # 128 + SIGTERM(15)

cd "$REPO_ROOT"
started=0
# APPEND, never truncate. `>` here meant each retry destroyed the previous
# attempt's output, so only the LAST attempt's error survived — and when the
# retry path is broken (see below) the last error is a CONSEQUENCE of the retry,
# not the original cause. Found 2026-08-10 on GitHub run 31375473391: the log
# showed only "failed to change workdir ... no such file or directory", which is
# attempt 2/3 tripping over a deleted scratch dir, while attempt 1's real reason
# had already been overwritten and is simply gone.
#
# HISTORICAL — this loop DID mis-retry, and the record is kept because the
# symptom pointed at the wrong culprit for three runs.
#
# What was seen: "tearing down (exit was 1)" firing BETWEEN attempt 1 and attempt
# 2, with attempts 2 and 3 then failing on a missing workdir. That reads as "the
# retry loop deletes its own scratch dir", and an earlier version of this comment
# said exactly that.
#
# The actual cause was OUTSIDE this loop. zap-active.sh's readiness poll timed out
# (90s, against a cold-runner bring-up that needs ~124s) and kill(1)ed this
# process. The old `trap teardown EXIT INT TERM` then ran teardown ON SIGTERM AND
# RETURNED, so the script resumed inside this loop with $SCRATCH already removed.
# The loop was never at fault; it was executing after a cleanup it did not ask for.
#
# Both causes are now fixed — the timeout (DAST_BRINGUP_TIMEOUT, 600s default) and
# the trap split above, where INT/TERM exit rather than clean up in place.
# Verified with a model of this loop: under the OLD traps a mid-attempt SIGTERM
# makes all three attempts run against a deleted dir; under the NEW ones the script
# exits at 143 without attempting again, and when `supabase start` simply fails on
# its own all three attempts are genuine retries with $SCRATCH intact.
#
# Kept as a comment rather than deleted: the failure was diagnosed wrongly twice
# because the loop is where the ERROR surfaced, and the next person to read a
# "workdir missing" message here should know to look at who signalled the process.
for attempt in 1 2 3; do
    if supabase start --workdir "$SCRATCH" >>"$OUTDIR/supabase-start.log" 2>&1; then
        started=1
        break
    fi
    log "bring-up: supabase start failed (attempt $attempt/3) — stopping + retrying"
    supabase stop --workdir "$SCRATCH" --no-backup >/dev/null 2>&1 || true
done
[[ "$started" -eq 1 ]] || die "supabase start failed 3x — see $OUTDIR/supabase-start.log (fresh-DB provisioning is documented-flaky; this must die loudly, never silently skip)"

# --- Step 3: force a clean DB regardless of prior volume state. Usually a
# no-op (a fresh SCRATCH + fresh project_id volume already applies every
# migration during `start`), but defends against a prior run's stale
# "partna-dast" volume surviving a crash that skipped teardown.
supabase db reset --workdir "$SCRATCH" >>"$OUTDIR/supabase-start.log" 2>&1

# --- Step 3b: give the restricted runtime role a login, so the SERVED APP can
# connect as `app_backend` instead of `postgres` (Step 4 below points DB_USERNAME
# at it). Until 2026-08-10 this lane ran the app as the `postgres` SUPERUSER, so
# it had never once exercised the role prod actually uses.
#
# BE PRECISE ABOUT WHAT THIS PROVES — the honest claim is narrow, and overstating
# it would be exactly the kind of absence-shaped assurance this tool exists to
# reject. Verified against supabase/migrations/20260726000000_baseline_pilot.sql:
#
#   * the role is created there (`CREATE ROLE app_backend NOLOGIN`, :27), so the
#     scratch stack already has it — this only adds LOGIN + a password;
#   * that same DO block runs `ALTER ROLE app_backend BYPASSRLS` (:33), and of
#     the 27 RLS policies granted to the role, 25 are `USING (true)`.
#
# So RLS is NOT the tenant-isolation mechanism for the app role, and running as
# app_backend does NOT prove "prod RLS". The APPLICATION layer is the isolator,
# and the cross-identity IDOR pass in zap-active.sh is what tests it.
#
# What this DOES catch is missing GRANTs and role misconfiguration — a real
# prod-breakage class in this repo's history (the app_backend NOLOGIN credential
# gap). A route that 500s here having worked under `postgres` is a genuine gap
# between what the app does and what prod's role may do. Treat it as a FINDING:
# write it up and take it through scripts/audit/fix-flow.md's blocker gate. Do
# NOT silently GRANT your way to green — that converts the signal into noise.
#
# Random per run: this credential lives only in the throwaway stack and in the
# gitignored .env.dast, both destroyed by teardown(), so there is nothing to
# rotate, reuse or leak. Same test-only-override discipline as the jwt_expiry and
# captcha branches above — the committed config.toml and migrations are untouched.
#
# Issued through PHP's PDO rather than `psql`: psql is not on the PATH of the
# macOS box this lane is developed on, and shelling into the Supabase container
# would hardcode a CLI-owned container name. pdo_pgsql is already a hard
# dependency of the app being served.
APP_BACKEND_PASSWORD="$(openssl rand -hex 16)"
php -r '
$pdo = new PDO(sprintf("pgsql:host=127.0.0.1;port=%d;dbname=postgres", (int) $argv[1]), "postgres", "postgres", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$exists = $pdo->query("SELECT 1 FROM pg_roles WHERE rolname = '"'"'app_backend'"'"'")->fetchColumn();
if ($exists === false) {
    fwrite(STDERR, "app_backend role does not exist in the scratch stack\n");
    exit(1);
}
$pdo->exec("ALTER ROLE app_backend WITH LOGIN PASSWORD ".$pdo->quote($argv[2]));
' "$DB_PORT" "$APP_BACKEND_PASSWORD" \
    || die "could not grant LOGIN to app_backend in the scratch stack — see $OUTDIR/supabase-start.log"

# --- Step 4: read the scratch stack's real secrets (never the repo's) and
# generate .env.dast — never touches the repo .env.
STATUS_ENV="$(supabase status --workdir "$SCRATCH" -o env 2>/dev/null)"
JWT_SECRET="$(grep '^JWT_SECRET=' <<<"$STATUS_ENV" | cut -d'"' -f2)"
ANON_KEY="$(grep '^ANON_KEY=' <<<"$STATUS_ENV" | cut -d'"' -f2)"
SERVICE_ROLE_KEY="$(grep '^SERVICE_ROLE_KEY=' <<<"$STATUS_ENV" | cut -d'"' -f2)"
[[ -n "$JWT_SECRET" ]] || die "could not read JWT_SECRET from supabase status"

SUPABASE_URL="http://127.0.0.1:${API_PORT}"
ZAP_TARGET_LOCAL_EFFECTIVE="http://127.0.0.1:${APP_PORT}"

# `php artisan serve --env=dast` resolves .env.dast relative to the
# application base path — i.e. $REPO_ROOT/.env.dast, NOT anywhere under
# $SCRATCH. Verified the hard way 2026-07-26: an earlier version of this
# script wrote it under $SCRATCH, Laravel silently found no .env.dast at
# the expected root path and fell back to the real .env — meaning the
# served app was live against whatever DB_HOST the developer's real .env
# points at, not the isolated scratch stack. That .env.dast is gitignored
# (see .gitignore) and removed in teardown() below; a stray leftover from
# a crashed prior run is overwritten fresh by `cp` on every start.
ENV_DAST="$REPO_ROOT/.env.dast"
cp "$REPO_ROOT/.env.example" "$ENV_DAST"
# Overrides — sync/array/array avoid a Redis dependency entirely (also why
# external-side-effect routes need Phase-4 exclusions: sync means every job
# runs inline against a "local" scan).
apply_env() {
    local key="$1" val="$2"
    if grep -q "^${key}=" "$ENV_DAST"; then
        sed -i.bak "s#^${key}=.*#${key}=${val}#" "$ENV_DAST" && rm -f "$ENV_DAST.bak"
    else
        echo "${key}=${val}" >> "$ENV_DAST"
    fi
}
apply_env APP_ENV local
apply_env APP_KEY "base64:16QqwUrILaj5z7qHgtIRdOVAimjt423eOFxQGDCAwwc="
apply_env APP_DEBUG true
apply_env APP_URL "$ZAP_TARGET_LOCAL_EFFECTIVE"
apply_env LOG_CHANNEL single
apply_env DB_CONNECTION pgsql
apply_env DB_HOST 127.0.0.1
apply_env DB_PORT "$DB_PORT"
apply_env DB_DATABASE postgres
# THE RESTRICTED RUNTIME ROLE, not postgres — see Step 3b for what this does and
# does not prove. seed-identities.php reads this same .env.dast, so the seeder
# runs as app_backend too. That is deliberate: splitting the seeder back onto the
# superuser would hide the very grant gaps this change exists to surface, and the
# tables it writes (core.users, core.pre_account_builds, content.*, routing.*) are
# ones the app itself writes in prod. A seeding failure here is a finding about
# the role, not a harness bug to work around.
apply_env DB_USERNAME app_backend
apply_env DB_PASSWORD "$APP_BACKEND_PASSWORD"
apply_env DB_SSLMODE disable
apply_env SUPABASE_URL "$SUPABASE_URL"
apply_env SUPABASE_ANON_KEY "$ANON_KEY"
apply_env SUPABASE_SERVICE_ROLE_KEY "$SERVICE_ROLE_KEY"
apply_env SUPABASE_JWKS_URL "${SUPABASE_URL}/auth/v1/.well-known/jwks.json"
apply_env SUPABASE_JWT_ISSUER "${SUPABASE_URL}/auth/v1"
apply_env SUPABASE_JWT_AUD authenticated
apply_env SUPABASE_JWKS_FAIL_CLOSED false   # Phase-3 spike: local stack is HS256-shared-secret, not JWKS — falls through to the Auth-Server path
apply_env SUPABASE_LOCAL_JWT_SECRET "$JWT_SECRET"   # not a Laravel config key — read by active/mint-jwt.php (Phase 3)
apply_env QUEUE_CONNECTION sync
apply_env CACHE_STORE array
apply_env SESSION_DRIVER array
apply_env BROADCAST_CONNECTION log
apply_env MAIL_MAILER log
apply_env NIGHTWATCH_ENABLED false
# Media stays on the local filesystem. .env.example resolves partna.media_disk
# to the R2 'media' disk with the placeholder endpoint
# https://<account_id>.r2.cloudflarestorage.com — so the IDOR pass's four media
# DELETE controls (gallery/images/documents/content-uploads) would each attempt
# to resolve a non-loopback host. ImageVariantService::deleteVariants and
# UserDocumentController::destroy both catch \Throwable, so it could not 500 —
# but "the active lane only ever touches loopback" is an assertion this script
# makes (seed-identities.php's containment guard 2), not an aspiration, and a
# failed DNS lookup is still an outbound attempt. Local disk keeps it true.
apply_env PARTNA_MEDIA_DISK local
# Two consequences worth knowing, neither a blocker:
#  - the local disk root is storage_path('app/private') IN THE REPO TREE, and
#    teardown() removes only $SCRATCH and .env.dast. Anything the lane writes
#    there survives. In practice ZAP cannot synthesise valid multipart bodies, so
#    essentially nothing is written.
#  - .env.example sets PARTNA_THROTTLE_ENABLED=true and nothing here overrides it,
#    so throttle:authenticated (300/min per supabase_uid) and the route-level
#    throttle:30,1 on documents/content-uploads ARE armed. They never bite only
#    because CACHE_STORE=array above, and `artisan serve`'s php -S child rebuilds
#    the array store per request — every limiter bucket is empty on arrival. So
#    this lane cannot detect a throttling regression, and moving CACHE_STORE to a
#    persistent driver would make identity A's activeScan burn the 300/min budget
#    and start returning 429 on the IDOR controls.

# --- Step 5: serve the app against the local stack.
#
# --no-reload is load-bearing, not an optimization. Laravel's ServeCommand
# spawns a SEPARATE child process (`php -S host:port server.php`) to
# actually handle HTTP requests — verified 2026-07-26 by reading
# ServeCommand::startProcess(): by default (no --no-reload) it filters
# $_ENV down to a small passthrough allowlist before starting that child,
# so the child never receives --env=dast or .env.dast's values and
# bootstraps against the real repo .env instead — meaning the served app
# was silently NOT using the isolated scratch stack (DB_HOST, JWKS_URL,
# etc. all pointed at whatever the real .env has). This is the exact
# failure mode "NEVER point the active lane at real dev/prod" exists to
# prevent — caught via JWKS fetch errors referencing an unrecognized
# Supabase host, not a DB connection failure, so a naive health check
# alone would NOT have caught it. --no-reload makes startProcess() pass
# the FULL $_ENV through unfiltered instead — since THIS process already
# correctly loaded .env.dast via --env=dast, the child inherits it via
# real process environment variables (which Dotenv's already-set check
# then leaves alone). No live-reload-on-.env-change behavior is lost that
# this one-shot bring-up ever needed anyway.
# --- BIND ADDRESS. `artisan serve` defaults to 127.0.0.1, and on Linux that makes
# the lane impossible: ZAP runs in a container and reaches the host via the docker0
# gateway (host.docker.internal -> 172.17.0.1 once zap-active.sh's --add-host maps
# it), so a server listening only on loopback refuses every connection. Docker
# Desktop on macOS proxies loopback for you, which is exactly why this never
# surfaced until the lane ran on a Linux runner — GitHub run 31379520273, where
# the DNS fix turned "Name or service not known" into "Connection refused" and ZAP
# still reached nothing.
#
# Defaulted BY PLATFORM rather than unconditionally, because this is a containment
# trade-off and the tool's whole posture is containment. On Linux 0.0.0.0 is not a
# preference, it is the only value that works. On macOS 127.0.0.1 does work, so it
# stays — binding the scan target, with its seeded identities and deliberately
# fuzzable surface, to every interface on a developer's laptop is a real (if
# small) exposure and there is no reason to accept it where it buys nothing.
#
# DAST_SERVE_HOST overrides either way (e.g. to force 0.0.0.0 when running the
# lane inside a Linux VM on macOS).
case "$(uname -s)" in
    Linux) DEFAULT_SERVE_HOST=0.0.0.0 ;;
    *)     DEFAULT_SERVE_HOST=127.0.0.1 ;;
esac
SERVE_HOST="${DAST_SERVE_HOST:-$DEFAULT_SERVE_HOST}"
log "bring-up: serving on ${SERVE_HOST}:${APP_PORT} (health-checked via 127.0.0.1)"
php artisan serve --env=dast --no-reload --host="$SERVE_HOST" --port="$APP_PORT" >"$OUTDIR/serve.log" 2>&1 &
SERVE_PID=$!

# --- Step 6: health-check gate — poll the app's /up and the Supabase
# Auth health endpoint until both 200 or a 60s timeout.
deadline=$(( $(date +%s) + 60 ))
app_ok=0 supa_ok=0
while [[ $(date +%s) -lt $deadline ]]; do
    if [[ $app_ok -eq 0 ]] && curl -fsS -o /dev/null "http://127.0.0.1:${APP_PORT}/up" 2>/dev/null; then
        app_ok=1
    fi
    if [[ $supa_ok -eq 0 ]] && curl -fsS -o /dev/null "${SUPABASE_URL}/auth/v1/health" 2>/dev/null; then
        supa_ok=1
    fi
    [[ $app_ok -eq 1 && $supa_ok -eq 1 ]] && break
    sleep 1
done
[[ $app_ok -eq 1 && $supa_ok -eq 1 ]] || die "health check timed out after 60s (app_ok=$app_ok supa_ok=$supa_ok) — see $OUTDIR/serve.log"

log "bring-up: healthy — app=http://127.0.0.1:${APP_PORT} supabase=${SUPABASE_URL}"

# --- Step 6b: POSITIVE proof of the connecting role. A health check that passes
# says the app reached SOME database; it does not say which role it reached it
# as, and "the .env says app_backend" is a claim about config, not about a live
# connection. Ask Postgres. This boots Laravel with --env=dast and resolves the
# pgsql connection through the app's OWN config, so it fails for the same reasons
# the served child would.
#
# Absence-shaped assurance is what this whole tool exists to eliminate, so this
# asserts the value rather than merely not-erroring: a fallback to `postgres`
# would otherwise sail through silently and the lane would report having
# exercised a restricted role it never touched.
DB_ROLE="$(php artisan --env=dast tinker --execute \
    "echo DB::connection('pgsql')->selectOne('select current_user as u')->u;" 2>/dev/null | tr -d '[:space:]')"
[[ "$DB_ROLE" == "app_backend" ]] \
    || die "the served app is connecting as '${DB_ROLE:-<unknown>}', not app_backend — the restricted-role lane is not actually restricted. see $OUTDIR/serve.log"
log "bring-up: connected role verified — current_user = $DB_ROLE (restricted runtime role, not postgres)"

# --- Step 7: publish facts for later phases, then hold the stack up.
# Written LAST (after the health check passes) so a poll loop watching for
# this file's existence never observes a half-healthy stack. Values are
# double-quoted — this repo's own path contains a space ("Side Street"),
# and an unquoted `source` of this file word-splits it, breaking the
# sourcing script entirely (verified 2026-07-26).
cat > "$OUTDIR/bring-up.env" <<EOF
API_PORT="${API_PORT}"
DB_PORT="${DB_PORT}"
APP_PORT="${APP_PORT}"
SUPABASE_URL="${SUPABASE_URL}"
JWT_SECRET="${JWT_SECRET}"
ANON_KEY="${ANON_KEY}"
SERVICE_ROLE_KEY="${SERVICE_ROLE_KEY}"
SCRATCH="${SCRATCH}"
ENV_DAST="${ENV_DAST}"
EOF

wait "$SERVE_PID"
