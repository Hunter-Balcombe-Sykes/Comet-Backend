#!/usr/bin/env bash
# audit.sh — One-command audit: DeepSeek scan(s) → Claude adjudicate → final audit file.
#
# Targeted mode (single lens):
#   scripts/audit/audit.sh \
#     --lens "auth/policy coverage on the new SitePolicy" \
#     --scope app/Policies/SitePolicy.php \
#     --scope app/Http/Controllers/Api/User/Uploads/
#
# Full mode (8 lens-focused scans, then one adjudication):
#   scripts/audit/audit.sh --full --scope app/Services/Stripe
#
#   Alias for --bundle core. Runs eight focused DeepSeek scans against the same
#   scope (security, lifecycle-correctness, scaling-antipatterns,
#   database-and-queue-scaling, schema-rls, caching-gold-standard,
#   webhook-idempotency, transaction-boundaries) then ONE Claude adjudication
#   over the merged drafts. Use this when you want broad correctness coverage
#   and don't have a specific theme.
#
# Bundle mode (named lens groups):
#   scripts/audit/audit.sh --bundle <name> --scope <path>
#
#   Available bundles:
#     core             — the 8 always-relevant correctness lenses (same as --full)
#     concurrency      — caching-gold-standard + webhook-idempotency + transaction-boundaries
#                        (correctness-under-concurrency trio: silent state drift bugs)
#     pre-merge        — migration-safety + api-contract + configuration-hygiene + test-coverage
#                        (run before merging a PR that touches schema or public API)
#     code-quality     — code-quality-slop + semantic-correctness
#                        (AI slop + plausible-but-wrong logic the compiler can't see;
#                         the qualitative companion to `composer analyse`)
#     pre-pilot        — core + data-integrity + job-queue-correctness + observability
#                        + caching-coverage-gaps (12 lenses; full pre-pilot correctness sweep)
#     security         — security + schema-rls + configuration-hygiene + edge-worker
#                        + privacy-compliance (the pure security & tenant-isolation
#                        sweep: auth, IDOR, injection, SSRF, RLS, secrets, PII).
#                        Use with --codebase for a whole-backend security audit.
#     launch-readiness — security + privacy-compliance + edge-worker + configuration-hygiene
#                        + migration-safety + api-contract (the go-live gate)
#     scale-health     — caching trio + database-and-queue-scaling + job-queue-correctness
#                        + observability, graded against a 10k-user target (run post-launch)
#     full-sweep       — ALL 20 lenses (the stage bundles plus test-coverage,
#                        code-quality-slop, semantic-correctness). Nothing left
#                        unturned; use with --codebase for a whole-repo audit.
#
# Codebase mode (whole-repo audit, per-lens scopes — usually with pre-pilot /
# launch-readiness / scale-health / full-sweep):
#   scripts/audit/audit.sh --codebase --bundle pre-pilot [--phase <name>]
#
#   Runs every lens in the bundle against that lens's own built-in scope map
#   (chunked to stay under the scan-recall payload ceiling), then adjudicates
#   PER LENS with repo tools (--no-source), emitting one audit file per lens
#   into audits/<phase>/ (default phase: codebase-<bundle>-YYYY-MM-DD).
#   --scope / --lens / --out are not allowed with --codebase.
#
# Parallelism:
#   DeepSeek scans run in parallel waves (--scan-jobs N, default 4) — they are
#   independent stateless API calls. Claude adjudications run SEQUENTIALLY by
#   design: they share the local claude CLI's plan/rate budget, and serial
#   verification keeps failures isolated and the run resumable. Don't run two
#   audit.sh invocations concurrently for the same reason.
#
# Phase organization (optional):
#   Output always lands in audits/. Pass --phase <name> to organize further
#   under audits/<name>/. Drafts (when --keep-drafts is also set) land in
#   audits/<name>/.drafts/.
#
#     scripts/audit/audit.sh --lens "policy coverage" --scope app/Policies
#     # → audits/audit-YYYY-MM-DD-policy-coverage.md
#
#     scripts/audit/audit.sh --phase phase-1-security \
#       --lens "policy coverage" --scope app/Policies
#     # → audits/phase-1-security/audit-YYYY-MM-DD-policy-coverage.md
#
# Auth:
#   DEEPSEEK_API_KEY  loaded from scripts/audit/.env (gitignored) or shell env
#   Claude            uses the local `claude` CLI's existing OAuth login
#
# Output: audit-YYYY-MM-DD-<slug>.md (or whatever --out is set to)
# Pass --keep-drafts to keep the intermediate DeepSeek drafts.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# --- Load .env if present ---
ENV_FILE="$SCRIPT_DIR/.env"
if [[ -f "$ENV_FILE" ]]; then
    set -a
    # shellcheck disable=SC1090
    source "$ENV_FILE"
    set +a
fi

# --- Args ---
SCOPE_ARGS=()
LENS_ARG=()
FULL=false
BUNDLE=""
OUT=""
PHASE=""
KEEP_DRAFTS=false
CODEBASE=false
SCAN_JOBS=4

# Print the whole comment header (everything up to `set -euo`) as help text,
# so the header can grow without the range going stale.
usage() { awk '/^set -euo/{exit} NR>1 {sub(/^# ?/,""); print}' "$0"; }

# --- Codebase mode: per-lens scope maps -------------------------------------
# Each line is "chunk-name|space-separated relative paths". Chunks are sized
# so a single scan stays under ~350KB of source (scan recall degrades on
# oversized payloads — see the warning in audit-scan.sh). Paths verified to
# exist as of 2026-06-11; when the tree moves, update here AND in the lens's
# own "Suggested per-domain scope groups" section.
codebase_chunks() {
    case "$1" in
        security) cat <<'EOF'
auth-core|app/Http/Middleware app/Policies app/Providers app/Http/Controllers/Concerns app/Http/Controllers/Controller.php app/Exceptions app/Rules config
user-surface|app/Http/Controllers/Api/User app/Http/Requests
public-staff-surface|app/Http/Controllers/Api/PublicSite app/Http/Controllers/Api/Staff app/Http/Controllers/Api/Internal app/Http/Controllers/Api/Platforms app/Http/Controllers/Api/Webhooks app/Http/Resources
outbound-services|app/Services/SmartLinks app/Services/Platforms app/Services/BotProtection app/Services/Auth app/Services/Streaming app/Services/Media cloudflare-worker/src
EOF
        ;;
        lifecycle-correctness) cat <<'EOF'
account-site|app/Services/Site app/Services/PublicSite app/Services/User app/Services/Accounts app/Jobs/Account app/Jobs/Gdpr app/Http/Middleware/Context
moderation-streaming|app/Services/Moderation app/Services/Streaming app/Services/Notifications app/Notifications app/Jobs/Moderation app/Jobs/Notifications app/Jobs/Streaming app/Jobs/Cloudflare
connectors|app/Services/Platforms app/Services/SmartLinks app/Jobs/Platforms
EOF
        ;;
        scaling-antipatterns) cat <<'EOF'
write-paths|app/Services/Analytics app/Jobs/Analytics app/Services/Notifications app/Jobs/Notifications app/Notifications app/Observers app/Services/Cache app/Http/Resources
EOF
        ;;
        database-and-queue-scaling) cat <<'EOF'
models-config|app/Models app/Http/Resources database/factories app/Console routes/console.php config/horizon.php config/queue.php
jobs-vendors|app/Jobs app/Services/Media app/Services/Streaming app/Services/Cloudflare app/Services/Analytics
EOF
        ;;
        schema-rls) cat <<'EOF'
schema|supabase/migrations app/Models
EOF
        ;;
        caching-gold-standard) cat <<'EOF'
read-paths|app/Services/Cache app/Services/Site app/Services/PublicSite app/Services/Accounts app/Services/FeatureFlags app/Http/Middleware
write-paths|app/Observers app/Jobs/Cache app/Jobs/Cloudflare app/Services/Analytics app/Services/Notifications app/Services/Streaming
EOF
        ;;
        caching-coverage-gaps) cat <<'EOF'
hot-reads|app/Services/Site app/Services/PublicSite app/Services/Accounts app/Http/Middleware app/Http/Controllers/Api/PublicSite app/Services/Streaming app/Services/Platforms
EOF
        ;;
        webhook-idempotency) cat <<'EOF'
callbacks|app/Http/Controllers/Api/Webhooks app/Http/Controllers/Api/Internal app/Services/Webhooks app/Services/Email app/Http/Middleware routes
EOF
        ;;
        transaction-boundaries) cat <<'EOF'
domain-services|app/Services/User app/Services/Site app/Services/Moderation app/Services/Accounts app/Services/Auth app/Services/Feedback app/Observers
vendor-jobs|app/Services/Cloudflare app/Services/Streaming app/Services/SmartLinks app/Services/Platforms app/Jobs app/Listeners
EOF
        ;;
        migration-safety) cat <<'EOF'
migrations|supabase/migrations
EOF
        ;;
        api-contract) cat <<'EOF'
user-api|app/Http/Resources app/Http/Controllers/Api/User app/Http/Controllers/Api/ApiController.php
other-api|app/Http/Resources app/Http/Controllers/Api/PublicSite app/Http/Controllers/Api/Staff app/Http/Controllers/Api/Internal app/Http/Controllers/Api/Platforms
EOF
        ;;
        configuration-hygiene) cat <<'EOF'
config-files|config .env.example routes
consumers|app/Services/Streaming app/Services/Cloudflare app/Services/BotProtection app/Services/Email app/Services/Media app/Services/FeatureFlags app/Services/Diagnostics app/Jobs app/Console app/Http/Middleware
EOF
        ;;
        test-coverage) cat <<'EOF'
sweep-tests|app/Policies tests/Pest.php tests/Feature/Security tests/Feature/Queue
domain-tests|app/Jobs tests/Feature/Jobs app/Services/PublicSite app/Http/Controllers/Api/PublicSite
EOF
        ;;
        data-integrity) cat <<'EOF'
schema|supabase/migrations app/Enums database/factories
models-gdpr|app/Models app/Observers app/Jobs/Gdpr app/Services/User
EOF
        ;;
        job-queue-correctness) cat <<'EOF'
jobs|app/Jobs app/Console config/horizon.php config/queue.php
EOF
        ;;
        observability) cat <<'EOF'
jobs-hooks|app/Jobs app/Console app/Listeners app/Exceptions app/Services/Webhooks app/Http/Controllers/Api/Webhooks app/Http/Controllers/Api/HealthController.php config/queue.php config/horizon.php
vendor-services|app/Services/Cloudflare app/Services/Streaming app/Services/SmartLinks app/Services/Platforms app/Services/Media app/Services/Moderation app/Services/Audit
EOF
        ;;
        edge-worker) cat <<'EOF'
worker|cloudflare-worker/src cloudflare-worker/wrangler.toml app/Jobs/Cloudflare app/Services/Cloudflare app/Services/Cache/SiteCacheService.php config/partna.php
EOF
        ;;
        privacy-compliance) cat <<'EOF'
rights-machinery|app/Jobs/Gdpr app/Jobs/Account app/Services/User app/Models app/Http/Resources
collection-retention|app/Services/Analytics app/Jobs/Analytics app/Services/Moderation app/Services/Notifications app/Services/Audit app/Console app/Mail app/Http/Middleware/Logging config/partna.php routes/console.php
schema-pii|supabase/migrations
EOF
        ;;
        code-quality-slop) cat <<'EOF'
services|app/Services/User app/Services/Media app/Services/SmartLinks app/Services/Platforms app/Services/Feedback app/Services/Diagnostics app/Mail
http-jobs|app/Http/Controllers/Api/User app/Http/Resources app/Jobs app/Console app/Notifications app/Observers
EOF
        ;;
        semantic-correctness) cat <<'EOF'
core-services|app/Services/User app/Services/Site app/Services/PublicSite app/Services/Cache app/Services/Accounts app/Services/Auth app/Services/FeatureFlags app/Support app/Contracts app/helpers.php
jobs-controllers|app/Jobs app/Http/Controllers/Api/User app/Policies app/DTOs
EOF
        ;;
        *) return 1 ;;
    esac
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --scope)         SCOPE_ARGS+=("--scope" "$2"); shift 2 ;;
        --lens)          LENS_ARG=("--lens" "$2"); shift 2 ;;
        --lens-file)     LENS_ARG=("--lens-file" "$2"); shift 2 ;;
        --full)          FULL=true; shift ;;
        --bundle)        BUNDLE="$2"; shift 2 ;;
        --codebase)      CODEBASE=true; shift ;;
        --scan-jobs)     SCAN_JOBS="$2"; shift 2 ;;
        --out)           OUT="$2"; shift 2 ;;
        --phase)         PHASE="$2"; shift 2 ;;
        --keep-drafts)   KEEP_DRAFTS=true; shift ;;
        -h|--help)       usage; exit 0 ;;
        *) echo "Unknown arg: $1" >&2; usage >&2; exit 2 ;;
    esac
done

# --- Validate ---
# --full is sugar for --bundle core
$FULL && [[ -z "$BUNDLE" ]] && BUNDLE="core"
$FULL && [[ -n "$BUNDLE" && "$BUNDLE" != "core" ]] && { echo "--full and --bundle <other> are mutually exclusive" >&2; exit 2; }

if [[ -n "$BUNDLE" ]]; then
    [[ ${#LENS_ARG[@]} -eq 0 ]] || { echo "--bundle/--full and --lens/--lens-file are mutually exclusive" >&2; exit 2; }
    FULL=true  # downstream logic gates on $FULL for the multi-lens path
else
    $CODEBASE && { echo "--codebase requires --bundle <name> (or --full)" >&2; exit 2; }
    [[ ${#LENS_ARG[@]} -gt 0 ]] || { echo "--lens, --bundle, or --full is required" >&2; exit 2; }
fi
if $CODEBASE; then
    [[ -n "$BUNDLE" ]]              || { echo "--codebase requires --bundle <name> (or --full)" >&2; exit 2; }
    [[ ${#SCOPE_ARGS[@]} -eq 0 ]]   || { echo "--codebase uses built-in per-lens scopes; --scope is not allowed" >&2; exit 2; }
    [[ -z "$OUT" ]]                 || { echo "--codebase emits one audit file per lens; --out is not allowed" >&2; exit 2; }
    [[ -n "$PHASE" ]]               || PHASE="codebase-${BUNDLE}-$(date +%F)"
else
    [[ ${#SCOPE_ARGS[@]} -gt 0 ]]   || { echo "--scope is required (one or more)" >&2; exit 2; }
fi
[[ "$SCAN_JOBS" =~ ^[0-9]+$ && "$SCAN_JOBS" -ge 1 ]] || { echo "--scan-jobs must be a positive integer" >&2; exit 2; }
[[ -n "${DEEPSEEK_API_KEY:-}" ]]  || { echo "DEEPSEEK_API_KEY not found (set in scripts/audit/.env or export)" >&2; exit 2; }
command -v claude >/dev/null      || { echo "claude CLI not on PATH — install from claude.ai/code" >&2; exit 2; }

# --- Output folder: always audits/, optionally with a phase subfolder ---
BASE_DIR="audits"
if [[ -n "$PHASE" ]]; then
    BASE_DIR="audits/${PHASE}"
fi
mkdir -p "$BASE_DIR"

# --- Drafts location ---
if $KEEP_DRAFTS; then
    if [[ -n "$BASE_DIR" ]]; then
        DRAFTS_DIR="${BASE_DIR}/.drafts"
        mkdir -p "$DRAFTS_DIR"
    else
        DRAFTS_DIR="."
    fi
    DRAFTS="$DRAFTS_DIR/drafts-$(date +%s).md"
else
    TMP="$(mktemp -d)"
    trap 'rm -rf "$TMP"' EXIT
    DRAFTS_DIR="$TMP"
    DRAFTS="$TMP/drafts.md"
fi

# --- Adjudicator budget (higher for --full because of merged drafts + tool use) ---
ADJ_BUDGET="2.00"
$FULL && ADJ_BUDGET="5.00"

if $FULL; then
    # --- Resolve bundle name → (lens files, meta-lens prefix list) ---
    case "$BUNDLE" in
        core)
            LENS_FILES=(
                "$SCRIPT_DIR/lenses/security.md"
                "$SCRIPT_DIR/lenses/lifecycle-correctness.md"
                "$SCRIPT_DIR/lenses/scaling-antipatterns.md"
                "$SCRIPT_DIR/lenses/database-and-queue-scaling.md"
                "$SCRIPT_DIR/lenses/schema-rls.md"
                "$SCRIPT_DIR/lenses/caching-gold-standard.md"
                "$SCRIPT_DIR/lenses/webhook-idempotency.md"
                "$SCRIPT_DIR/lenses/transaction-boundaries.md"
            )
            META_PREFIXES="security/policy (SEC-*), lifecycle correctness (LIFE-*), scaling antipatterns (CACHE-*), database/queue scaling — N+1/throughput (SCALE-*), schema/RLS correctness (SCHEMA-*), caching gold-standard adherence (CCH-*), webhook idempotency & delivery (WHK-*), and transaction-boundary correctness (TXN-*)"
            ;;
        concurrency)
            LENS_FILES=(
                "$SCRIPT_DIR/lenses/caching-gold-standard.md"
                "$SCRIPT_DIR/lenses/webhook-idempotency.md"
                "$SCRIPT_DIR/lenses/transaction-boundaries.md"
            )
            META_PREFIXES="caching gold-standard adherence (CCH-*), webhook idempotency & delivery (WHK-*), and transaction-boundary correctness (TXN-*) — the correctness-under-concurrency trio (silent state drift on rollback, replay, or stampede)"
            ;;
        pre-merge)
            LENS_FILES=(
                "$SCRIPT_DIR/lenses/migration-safety.md"
                "$SCRIPT_DIR/lenses/api-contract.md"
                "$SCRIPT_DIR/lenses/configuration-hygiene.md"
                "$SCRIPT_DIR/lenses/test-coverage.md"
            )
            META_PREFIXES="migration safety (MIG-*), API contract (API-*), configuration hygiene (CFG-*), and test coverage gaps (TEST-*) — pre-merge sweep for PRs touching schema, public API, or config"
            ;;
        code-quality)
            LENS_FILES=(
                "$SCRIPT_DIR/lenses/code-quality-slop.md"
                "$SCRIPT_DIR/lenses/semantic-correctness.md"
            )
            META_PREFIXES="AI slop & low-value code (SLOP-*) and semantic correctness — type-valid-but-wrong behaviour (SEM-*). Complementary lenses: SLOP is a taste/maintainability pass graded against CLAUDE.md house style; SEM hunts plausible-but-wrong logic that compiles AND passes Larastan. Larastan already enforces symbol existence (undefined methods/properties/classes/config), so do NOT re-report missing-symbol findings here. When a single line is both slop and a semantic bug, keep the SEM finding (higher signal) and drop the SLOP duplicate. Apply both lenses' anti-hallucination directive: confirm every finding against the actual repo via Read/Grep before keeping it — never on a prior about how a library 'should' behave"
            ;;
        pre-pilot)
            LENS_FILES=(
                "$SCRIPT_DIR/lenses/security.md"
                "$SCRIPT_DIR/lenses/lifecycle-correctness.md"
                "$SCRIPT_DIR/lenses/scaling-antipatterns.md"
                "$SCRIPT_DIR/lenses/database-and-queue-scaling.md"
                "$SCRIPT_DIR/lenses/schema-rls.md"
                "$SCRIPT_DIR/lenses/caching-gold-standard.md"
                "$SCRIPT_DIR/lenses/webhook-idempotency.md"
                "$SCRIPT_DIR/lenses/transaction-boundaries.md"
                "$SCRIPT_DIR/lenses/data-integrity.md"
                "$SCRIPT_DIR/lenses/job-queue-correctness.md"
                "$SCRIPT_DIR/lenses/observability.md"
                "$SCRIPT_DIR/lenses/caching-coverage-gaps.md"
            )
            META_PREFIXES="security/policy (SEC-*), lifecycle correctness (LIFE-*), scaling antipatterns (CACHE-*), database/queue scaling (SCALE-*), schema/RLS (SCHEMA-*), caching gold-standard (CCH-*), inbound callbacks & idempotency (WHK-*), transaction boundaries (TXN-*), data integrity (DINT-*), job/queue correctness under failure (JOB-*), observability/silent failures (OBS-*), and caching coverage gaps (CCG-*) — the full pre-pilot correctness sweep"
            ADJ_BUDGET="8.00"
            ;;
        security)
            LENS_FILES=(
                "$SCRIPT_DIR/lenses/security.md"
                "$SCRIPT_DIR/lenses/schema-rls.md"
                "$SCRIPT_DIR/lenses/configuration-hygiene.md"
                "$SCRIPT_DIR/lenses/edge-worker.md"
                "$SCRIPT_DIR/lenses/privacy-compliance.md"
            )
            META_PREFIXES="security/auth/tenant-isolation/IDOR/injection/SSRF/PII/MFA (SEC-*), schema & RLS / search_path DB-layer tenant isolation (SCHEMA-*), configuration hygiene & secret leakage / CORS (CFG-*), edge worker origin-trust & KV poisoning (EDGE-*), and privacy & data-rights compliance / PII inventory (PRIV-*) — the full security & tenant-isolation sweep across the whole backend"
            ADJ_BUDGET="5.00"
            ;;
        launch-readiness)
            LENS_FILES=(
                "$SCRIPT_DIR/lenses/security.md"
                "$SCRIPT_DIR/lenses/privacy-compliance.md"
                "$SCRIPT_DIR/lenses/edge-worker.md"
                "$SCRIPT_DIR/lenses/configuration-hygiene.md"
                "$SCRIPT_DIR/lenses/migration-safety.md"
                "$SCRIPT_DIR/lenses/api-contract.md"
            )
            META_PREFIXES="security (SEC-*), privacy & data-rights compliance (PRIV-*), edge worker routing/cache (EDGE-*), configuration hygiene (CFG-*), migration operational safety (MIG-*), and API contract (API-*) — the go-live gate: what must be true before public launch"
            ADJ_BUDGET="5.00"
            ;;
        scale-health)
            LENS_FILES=(
                "$SCRIPT_DIR/lenses/caching-gold-standard.md"
                "$SCRIPT_DIR/lenses/caching-coverage-gaps.md"
                "$SCRIPT_DIR/lenses/scaling-antipatterns.md"
                "$SCRIPT_DIR/lenses/database-and-queue-scaling.md"
                "$SCRIPT_DIR/lenses/job-queue-correctness.md"
                "$SCRIPT_DIR/lenses/observability.md"
            )
            META_PREFIXES="caching gold-standard adherence (CCH-*), caching coverage gaps (CCG-*), scaling antipatterns (CACHE-*), database/queue scaling (SCALE-*), job/queue correctness (JOB-*), and observability (OBS-*). GRADE EVERY FINDING AGAINST A 10,000-USER FLEET: ~10k KV-routed sitepages, aggregate 100k–1M page views/day with single-page viral spikes, analytics ingest bursts of 50–100 events/sec, per-user cache and notification fan-out across a 10k keyspace, and Horizon queue depth under burst. A finding harmless at 100 users but breaking at 10k is in scope — re-tier it for 10k and say so in the Technical section"
            ADJ_BUDGET="5.00"
            ;;
        full-sweep)
            LENS_FILES=(
                "$SCRIPT_DIR/lenses/security.md"
                "$SCRIPT_DIR/lenses/lifecycle-correctness.md"
                "$SCRIPT_DIR/lenses/scaling-antipatterns.md"
                "$SCRIPT_DIR/lenses/database-and-queue-scaling.md"
                "$SCRIPT_DIR/lenses/schema-rls.md"
                "$SCRIPT_DIR/lenses/caching-gold-standard.md"
                "$SCRIPT_DIR/lenses/webhook-idempotency.md"
                "$SCRIPT_DIR/lenses/transaction-boundaries.md"
                "$SCRIPT_DIR/lenses/data-integrity.md"
                "$SCRIPT_DIR/lenses/job-queue-correctness.md"
                "$SCRIPT_DIR/lenses/observability.md"
                "$SCRIPT_DIR/lenses/caching-coverage-gaps.md"
                "$SCRIPT_DIR/lenses/privacy-compliance.md"
                "$SCRIPT_DIR/lenses/edge-worker.md"
                "$SCRIPT_DIR/lenses/configuration-hygiene.md"
                "$SCRIPT_DIR/lenses/migration-safety.md"
                "$SCRIPT_DIR/lenses/api-contract.md"
                "$SCRIPT_DIR/lenses/test-coverage.md"
                "$SCRIPT_DIR/lenses/code-quality-slop.md"
                "$SCRIPT_DIR/lenses/semantic-correctness.md"
            )
            META_PREFIXES="every audit theme the pipeline knows: SEC, LIFE, CACHE, SCALE, SCHEMA, CCH, WHK, TXN, DINT, JOB, OBS, CCG, PRIV, EDGE, CFG, MIG, API, TEST, SLOP, and SEM — the exhaustive nothing-left-unturned sweep"
            ADJ_BUDGET="12.00"
            ;;
        *)
            echo "Unknown bundle: $BUNDLE (expected: core, concurrency, pre-merge, code-quality, pre-pilot, security, launch-readiness, scale-health, full-sweep)" >&2
            exit 2
            ;;
    esac
    LENS_COUNT=${#LENS_FILES[@]}

    # Verify all lens files exist BEFORE starting expensive scans
    for lf in "${LENS_FILES[@]}"; do
        [[ -f "$lf" ]] || { echo "Lens file missing: $lf" >&2; exit 2; }
    done

    # ════════ Codebase mode: per-lens scopes, per-lens adjudication ════════
    # Each lens scans its own scope map (chunked), then gets its own
    # adjudication with --no-source (the adjudicator verifies via Read/Grep),
    # emitting one audit file per lens. A failed lens doesn't abort the run.
    if $CODEBASE; then
        RUN_DATE=$(date +%F)
        FAILED_LENSES=()
        WRITTEN=()

        # Build the global scan task list up front: "lensfile|lens|chunk|paths".
        # Scans are independent stateless DeepSeek calls, so they parallelize
        # freely; adjudications stay sequential (they share the local claude
        # CLI's plan/rate budget and are the careful verification tier).
        TASKS=()
        for lf in "${LENS_FILES[@]}"; do
            LENS_NAME=$(basename "$lf" .md)
            CHUNK_LINES="$(codebase_chunks "$LENS_NAME" || true)"
            if [[ -z "$CHUNK_LINES" ]]; then
                echo "WARNING: no codebase scope map for lens '$LENS_NAME' — skipping" >&2
                FAILED_LENSES+=("$LENS_NAME (no scope map)")
                continue
            fi
            while IFS='|' read -r CHUNK_NAME CHUNK_PATHS; do
                [[ -n "$CHUNK_NAME" ]] || continue
                TASKS+=("$lf|$LENS_NAME|$CHUNK_NAME|$CHUNK_PATHS")
            done <<< "$CHUNK_LINES"
        done
        TASK_COUNT=${#TASKS[@]}

        echo "" >&2
        echo "════════ Codebase audit: bundle '$BUNDLE' — $LENS_COUNT lenses, $TASK_COUNT chunk scans (${SCAN_JOBS}-wide) + $LENS_COUNT sequential adjudications ════════" >&2
        echo "Output: $BASE_DIR/audit-${RUN_DATE}-<lens>.md (one file per lens)" >&2

        # --- Phase 1: wave-parallel DeepSeek scans -------------------------
        # Waves of $SCAN_JOBS background scans; per-scan output goes to a log
        # file so interleaved stderr stays readable. Portable to bash 3.2
        # (no `wait -n`): launch a wave, then harvest each pid's status.
        ti=0
        SCANNED=0
        while [[ $ti -lt $TASK_COUNT ]]; do
            WAVE_PIDS=()
            WAVE_META=()
            wj=0
            while [[ $wj -lt $SCAN_JOBS && $ti -lt $TASK_COUNT ]]; do
                IFS='|' read -r lf LENS_NAME CHUNK_NAME CHUNK_PATHS <<< "${TASKS[$ti]}"
                read -ra PATHS <<< "$CHUNK_PATHS"
                SCOPES=()
                for p in "${PATHS[@]}"; do SCOPES+=(--scope "$p"); done
                CHUNK_OUT="$DRAFTS_DIR/drafts-${LENS_NAME}-${CHUNK_NAME}.md"
                SCAN_LOG="$DRAFTS_DIR/scan-${LENS_NAME}-${CHUNK_NAME}.log"
                "$SCRIPT_DIR/audit-scan.sh" --lens-file "$lf" "${SCOPES[@]}" --out "$CHUNK_OUT" >"$SCAN_LOG" 2>&1 &
                WAVE_PIDS+=($!)
                WAVE_META+=("${LENS_NAME}|${CHUNK_NAME}")
                ti=$((ti + 1)); wj=$((wj + 1))
            done
            wk=0
            for pid in "${WAVE_PIDS[@]}"; do
                IFS='|' read -r m_lens m_chunk <<< "${WAVE_META[$wk]}"
                if wait "$pid"; then
                    SCANNED=$((SCANNED + 1))
                    echo "  ✓ scanned [$SCANNED/$TASK_COUNT] ${m_lens} [${m_chunk}]" >&2
                else
                    echo "  ✗ scan FAILED: ${m_lens} [${m_chunk}] — tail of log:" >&2
                    tail -5 "$DRAFTS_DIR/scan-${m_lens}-${m_chunk}.log" 2>/dev/null | sed 's/^/      /' >&2
                    rm -f "$DRAFTS_DIR/drafts-${m_lens}-${m_chunk}.md"
                fi
                wk=$((wk + 1))
            done
        done

        # --- Phase 2: per-lens draft assembly + sequential adjudication ----
        LENS_NUM=0
        for lf in "${LENS_FILES[@]}"; do
            LENS_NUM=$((LENS_NUM + 1))
            LENS_NAME=$(basename "$lf" .md)
            CHUNK_LINES="$(codebase_chunks "$LENS_NAME" || true)"
            [[ -n "$CHUNK_LINES" ]] || continue  # no scope map — already recorded

            LENS_DRAFTS="$DRAFTS_DIR/drafts-${LENS_NAME}.md"
            : > "$LENS_DRAFTS"
            ALL_PATHS=()
            while IFS='|' read -r CHUNK_NAME CHUNK_PATHS; do
                [[ -n "$CHUNK_NAME" ]] || continue
                read -ra PATHS <<< "$CHUNK_PATHS"
                ALL_PATHS+=("${PATHS[@]}")
                CHUNK_OUT="$DRAFTS_DIR/drafts-${LENS_NAME}-${CHUNK_NAME}.md"
                [[ -f "$CHUNK_OUT" ]] || continue  # chunk scan failed — skip it
                {
                    echo ""
                    echo "<!-- ═══ LENS: $LENS_NAME | CHUNK: $CHUNK_NAME ═══ -->"
                    echo ""
                    cat "$CHUNK_OUT"
                } >> "$LENS_DRAFTS"
            done <<< "$CHUNK_LINES"

            if [[ ! -s "$LENS_DRAFTS" ]]; then
                FAILED_LENSES+=("$LENS_NAME (all chunk scans failed)")
                continue
            fi

            echo "" >&2
            echo "──── Adjudicate ${LENS_NUM}/${LENS_COUNT}: $LENS_NAME ────" >&2
            ADJ_SCOPES=()
            for p in "${ALL_PATHS[@]}"; do ADJ_SCOPES+=(--scope "$p"); done
            OUT_FILE="$BASE_DIR/audit-${RUN_DATE}-${LENS_NAME}.md"

            if "$SCRIPT_DIR/audit-adjudicate.sh" \
                --drafts "$LENS_DRAFTS" \
                --lens-file "$lf" \
                "${ADJ_SCOPES[@]}" \
                --no-source \
                --max-budget "3.00" \
                --out "$OUT_FILE"; then
                WRITTEN+=("$OUT_FILE")
            else
                echo "WARNING: adjudication failed for $LENS_NAME" >&2
                FAILED_LENSES+=("$LENS_NAME (adjudication failed)")
            fi
        done

        echo "" >&2
        echo "════════ Codebase audit complete ════════" >&2
        for f in ${WRITTEN[@]+"${WRITTEN[@]}"}; do echo "  ✓ $f" >&2; done
        if [[ ${#FAILED_LENSES[@]} -gt 0 ]]; then
            echo "  Incomplete lenses (re-run individually with --bundle/--lens):" >&2
            for fl in "${FAILED_LENSES[@]}"; do echo "    ✗ $fl" >&2; done
        fi
        $KEEP_DRAFTS && echo "Per-lens drafts kept in: $DRAFTS_DIR" >&2
        exit 0
    fi

    echo "" >&2
    echo "════════ Bundle '$BUNDLE' — $LENS_COUNT lens-focused scans + 1 adjudication ════════" >&2

    : > "$DRAFTS"  # truncate / create

    # Wave-parallel scans (independent DeepSeek calls, $SCAN_JOBS at a time);
    # per-scan output goes to a log file so interleaved stderr stays readable.
    # Drafts are concatenated AFTER all scans, in bundle order, so the merged
    # file is deterministic. Bundle mode needs every lens — any failure aborts.
    li=0
    SCANNED=0
    while [[ $li -lt $LENS_COUNT ]]; do
        WAVE_PIDS=()
        WAVE_NAMES=()
        wj=0
        while [[ $wj -lt $SCAN_JOBS && $li -lt $LENS_COUNT ]]; do
            lf="${LENS_FILES[$li]}"
            LENS_NAME=$(basename "$lf" .md)
            echo "──── Scan $((li + 1))/${LENS_COUNT}: $LENS_NAME ────" >&2
            "$SCRIPT_DIR/audit-scan.sh" --lens-file "$lf" "${SCOPE_ARGS[@]}" \
                --out "$DRAFTS_DIR/drafts-${LENS_NAME}.md" \
                >"$DRAFTS_DIR/scan-${LENS_NAME}.log" 2>&1 &
            WAVE_PIDS+=($!)
            WAVE_NAMES+=("$LENS_NAME")
            li=$((li + 1)); wj=$((wj + 1))
        done
        wk=0
        ANY_FAIL=false
        for pid in "${WAVE_PIDS[@]}"; do
            if wait "$pid"; then
                SCANNED=$((SCANNED + 1))
                echo "  ✓ scanned [$SCANNED/$LENS_COUNT] ${WAVE_NAMES[$wk]}" >&2
            else
                echo "  ✗ scan FAILED: ${WAVE_NAMES[$wk]} — tail of log:" >&2
                tail -5 "$DRAFTS_DIR/scan-${WAVE_NAMES[$wk]}.log" 2>/dev/null | sed 's/^/      /' >&2
                ANY_FAIL=true
            fi
            wk=$((wk + 1))
        done
        $ANY_FAIL && { echo "Aborting: bundle mode requires every lens scan to succeed." >&2; exit 1; }
    done

    for lf in "${LENS_FILES[@]}"; do
        LENS_NAME=$(basename "$lf" .md)
        {
            echo ""
            echo "<!-- ═══ LENS: $LENS_NAME ═══ -->"
            echo ""
            cat "$DRAFTS_DIR/drafts-${LENS_NAME}.md"
        } >> "$DRAFTS"
    done

    # Meta-lens describing the bundle for the adjudicator
    META_LENS="Bundle '$BUNDLE' audit across $LENS_COUNT focused themes: ${META_PREFIXES}. Drafts below are concatenated from $LENS_COUNT lens-focused scans, each prefixed with a <!-- LENS: name --> marker. Dedupe across lenses where the same finding appears under multiple prefixes."
    LENS_PASS_ARGS=(--lens "$META_LENS")

    # Default output name for bundle mode (apply phase prefix if set)
    if [[ -z "$OUT" ]]; then
        OUT="audit-$(date +%F)-${BUNDLE}.md"
        [[ -n "$BASE_DIR" ]] && OUT="${BASE_DIR}/${OUT}"
    fi
else
    # --- Targeted mode — single scan ---
    echo "" >&2
    echo "════════ Step 1/2: DeepSeek scan ════════" >&2
    "$SCRIPT_DIR/audit-scan.sh" "${LENS_ARG[@]}" "${SCOPE_ARGS[@]}" --out "$DRAFTS"
    LENS_PASS_ARGS=("${LENS_ARG[@]}")
fi

# --- Adjudicate ---
echo "" >&2
if $FULL; then
    echo "════════ Final step: Claude adjudication across all ${LENS_COUNT} lenses ════════" >&2
else
    echo "════════ Step 2/2: Claude adjudication ════════" >&2
fi

OUT_FLAG=()
[[ -n "$OUT" ]] && OUT_FLAG=(--out "$OUT")

# Pass --out-dir so the adjudicator's auto-derived filename (targeted mode w/o --out)
# lands inside the phase folder. Adjudicator ignores --out-dir when --out is given.
ADJ_OUT_DIR=()
[[ -n "$BASE_DIR" ]] && ADJ_OUT_DIR=(--out-dir "$BASE_DIR")

"$SCRIPT_DIR/audit-adjudicate.sh" \
    --drafts "$DRAFTS" \
    --max-budget "$ADJ_BUDGET" \
    ${ADJ_OUT_DIR[@]+"${ADJ_OUT_DIR[@]}"} \
    "${LENS_PASS_ARGS[@]}" \
    "${SCOPE_ARGS[@]}" \
    ${OUT_FLAG[@]+"${OUT_FLAG[@]}"}

if $KEEP_DRAFTS; then
    echo "" >&2
    if $FULL; then
        echo "Per-lens drafts kept in: $DRAFTS_DIR" >&2
        echo "Merged drafts: $DRAFTS" >&2
    else
        echo "Drafts kept at: $DRAFTS" >&2
    fi
fi
