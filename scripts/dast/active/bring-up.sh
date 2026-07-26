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
APP_PORT="${ZAP_TARGET_LOCAL##*:}"
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
    log "bring-up: tearing down (exit was $status)"
    [[ -n "$SERVE_PID" ]] && kill "$SERVE_PID" 2>/dev/null || true
    ( cd "$REPO_ROOT" && supabase stop --workdir "$SCRATCH" --no-backup >/dev/null 2>&1 ) || true
    rm -rf "$SCRATCH"
    rm -f "$REPO_ROOT/.env.dast"   # written at $REPO_ROOT (not $SCRATCH) — see ENV_DAST below
}
trap teardown EXIT INT TERM

cd "$REPO_ROOT"
started=0
for attempt in 1 2 3; do
    if supabase start --workdir "$SCRATCH" >"$OUTDIR/supabase-start.log" 2>&1; then
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
apply_env DB_USERNAME postgres
apply_env DB_PASSWORD postgres
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
php artisan serve --env=dast --no-reload --port="$APP_PORT" >"$OUTDIR/serve.log" 2>&1 &
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
