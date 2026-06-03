#!/usr/bin/env bash
# run-foundation-v3.sh — Run the 26-lens foundation audit (v3).
#
# v3 = the v2 superset, re-weighted with the standalone-pages scaling lenses,
# framed around TWO axes per lens: prepilot correctness/security AND behaviour at
# scale (10,000 site pages, tens of thousands of concurrent public visitors).
# The three historically-oversized themes (Authorization, GDPR, Scale@10k) are
# pre-split so no lens returns truncated.
#
# Phase 1: 26 per-lens DeepSeek scans → Sonnet adjudications (P=4 parallel)
# Phase 2: composer audit + composer outdated --direct
# Phase 3: final Sonnet consolidation pass → audit-YYYY-MM-DD-CONSOLIDATED.md
#
# Resumable: re-running skips any lens whose expected output file already exists.
# All outputs land under audits/foundation-audit-v3/.
#
# Driver doc: audits/foundation-audit-v3/AUDIT-PROMPT.md

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$REPO_ROOT"

PHASE="foundation-audit-v3"
OUT_DIR="audits/${PHASE}"
DEPS_DIR="${OUT_DIR}/.deps"
LOG_DIR="${OUT_DIR}/.logs"
DATE="$(date +%F)"
TODAY_CONSOLIDATED="${OUT_DIR}/audit-${DATE}-CONSOLIDATED.md"

mkdir -p "$OUT_DIR" "$DEPS_DIR" "$LOG_DIR"

# --- Lens table: one record per line, fields separated by '|||'
#     Field 1: lens text (verbatim — used by audit.sh and for slug derivation)
#     Field 2: space-separated --scope arguments
#     Verbatim from AUDIT-PROMPT.md "How to run › Option 1" block (26 lenses).

LENSES=$(cat <<'EOF'
Policy coverage gaps, 403 vs 404 leakage, route middleware gaps, missing Gate::policy registrations, inline-403 aborts bypassing BasePolicy, public-endpoint enumeration via 403, staff vs user route guard drift|||app/Policies app/Http/Controllers app/Http/Middleware routes
IDOR and tenant isolation, cross-user data access, missing user_id scoping, mass-assignment, unvalidated request params, Form Request coverage gaps, handle/subdomain resolution authz at 10k scale|||app/Http/Controllers app/Http/Requests app/Rules app/Services/PublicSite app/Services/Site
JWT verification gaps, AAL2 bypass, MFA enforcement holes, claim trust, token replay, aal/amr attribute handling, fresh-AAL2 policy gaps|||app/Services/Auth app/Http/Middleware/Auth app/Exceptions/Auth
AccountCapabilities bypass, missing capability checks before notification/API/job actions, AccountType enum integrity, moderation/feedback actions gated by capabilities|||app/Services/Accounts app/Services/Moderation app/Services/Feedback app/Jobs/Notifications app/Jobs/Moderation app/Http/Controllers app/Enums app/Models/Core
Missing RLS policies, role grants too permissive, app_backend privileges, audit schema SELECT/INSERT only, schema-level authz gaps, unsafe seed data, search_path correctness, Supabase project config|||supabase/migrations supabase/seed.sql supabase/config.toml config/database.php
Missing transactions, race conditions, soft-delete consistency, FK/unique constraint gaps, N+1 writes, observer side-effects outside transactions, double-dispatch on retried writes|||app/Services app/Models app/Observers app/Jobs supabase/migrations
Migration safety, lock-on-deploy risk, non-CONCURRENTLY index builds, backfill ordering, baseline/incremental drift, CHECK constraints rejecting valid inputs, missing hot-path indexes for 10k-row tables|||supabase/migrations supabase/config.toml
GDPR deletion completeness, cascade gaps, models missing from deletion flow, soft vs hard delete correctness, retention enforcement, orphaned rows/media after account deletion|||app/Jobs/Gdpr app/Jobs/Account app/Exceptions/Gdpr app/Services/User app/Services/Accounts app/Models supabase/migrations
GDPR data export DSAR integrity and completeness, every PII-bearing table represented, export audit trail, email-sent tracking, missing relations, export job idempotency|||app/Jobs/Gdpr app/Mail/Gdpr app/Services/User app/Models app/Http/Controllers
Raw Eloquent model leakage, inconsistent Resource shapes, JSONB column leakage, missing pagination/filtering contracts, breaking-change risk, envelope-key drift|||app/Http/Resources app/Http/Controllers routes
N+1 queries, unbounded result sets, missing eager-loading, missing pagination on hot read paths, queries whose row count grows with sites/customers/enquiries/visits at 10k scale|||app/Http/Controllers app/Services/PublicSite app/Services/Site app/Services/Analytics app/Http/Resources app/Models
Cache invalidation gaps, stampede risk, SWR/single-flight correctness, stale reads, KV/Redis/HTTP cache layering, thundering-herd on cold profile reads when 10k pages expire together|||app/Services/Cache app/Jobs/Cache app/Observers app/Services/PublicSite app/Services/Site
Write amplification and write fan-out, per-write cache busting, per-write KV sync, observer cascades multiplying work, rebuild storms, cost of a single dashboard edit at 10k sites|||app/Observers app/Jobs/Cache app/Jobs/Cloudflare app/Services/Cache app/Services/Cloudflare
Database and queue saturation, Supavisor connection-pool exhaustion, queue-lane saturation, Horizon throughput, synchronous work in request path, analytics-ingest backpressure at high visit volume|||config/database.php config/queue.php config/horizon.php app/Jobs app/Services/Analytics app/Http/Controllers
Non-idempotent jobs, unsafe retries, missing failed() handlers, wrong queue lane, unbounded backoff, missing report() in failure paths, at-least-once duplicate delivery, serialized PII in job payloads|||app/Jobs app/Jobs/Concerns
Scheduler safety, missing withoutOverlapping locks, missing onOneServer, silent scheduled-task failures, missing critical schedules, frequency vs runtime mismatch|||routes/console.php app/Console/Commands app/Jobs
SUBDOMAIN_KV single-writer invariant violations, KV/DB drift, sync job idempotency, alias expirationTtl correctness, 301 alias-vs-canonical correctness at scale|||app/Services/Cloudflare app/Jobs/Cloudflare app/Observers cloudflare-worker/src/index.js routes
Cloudflare worker signature verification, R2 presigned URL leakage, public bucket scope, edge cache caches.default.put correctness, service-binding fallback safety|||app/Services/Cloudflare app/Services/Media cloudflare-worker/src/index.js
Media/video pipeline, presigned URL leakage, storage authz, orphaned media after delete failures, MIME validation before public-bucket write, variant-generation idempotency|||app/Services/Media app/Services/Streaming app/Jobs/Streaming
Rate-limiting coverage on public and auth routes, throttle bypass, CORS misconfig, bot-protection coverage on enquiry/feedback/signup endpoints, abuse surface at 10k-visitor traffic|||routes app/Http/Middleware app/Services/BotProtection app/Providers config/cors.php bootstrap
Security header gaps, HTTPS enforcement, frame/CSP/HSTS posture, header coverage on public site responses|||app/Http/Middleware bootstrap/app.php app/Providers
Webhook signature verification, Supabase email-hook auth, third-party webhook replay risk, internal-API auth weakness, moderation webhook auth|||app/Http/Controllers/Api/Internal app/Http/Middleware/Auth app/Services/Auth routes
Env reads outside the config layer, hardcoded secrets, dangerous config defaults, feature-flag determinism, diagnostic-info leakage, plaintext credentials/PII in logs and exception messages|||config app/Services/FeatureFlags app/Services/Diagnostics app/Services/Auth app/Services/Media app/Services/Streaming app/Console/Commands
Bootstrap and providers, global middleware order bugs, exception render leakage, route-model-binding misuse, Laravel 12 bootstrap drift, dangerous singletons, service-provider boot bugs, mail-send layer correctness, mail XSS, unsigned mail links, PII in emails|||bootstrap app/Exceptions app/Http/Middleware app/Providers app/Mail resources/views/emails app/Services/Email
Deploy script safety, CI workflow secrets handling, action permission scope, dangerous post-deploy hooks, env.example drift vs config, composer-script footguns|||.github/workflows/ci.yml composer.json composer.lock .env.example scripts
Observability and architecture hygiene and test coverage, missing structured log context, PII in logs, silent catch blocks, exception/slow-job coverage gaps, audit-log integrity, service-boundary correctness, fat controllers, dead code post-strip, test coverage of critical paths|||app/Services/Audit app/Services app/Http/Controllers app/Http/Concerns app/Http/Middleware/Logging tests
EOF
)

# --- Derive the same filename slug audit-adjudicate.sh would produce.
#     Matches the regex: lowercase, spaces+slashes → '-', strip non [a-z0-9-],
#     truncate to 50 chars, strip trailing '-'.
slugify() {
    local s
    s=$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | tr ' /' '--' | tr -cd 'a-z0-9-' | head -c 50)
    s="${s%-}"
    printf '%s' "$s"
}

# --- Phase 1: 26 lens scans, P=4 parallel, resumable ---

MAX_PARALLEL=4
declare -a JOB_PIDS=()
LENS_NUM=0
TOTAL_LENSES=$(printf '%s\n' "$LENSES" | grep -c '|||')

echo "" >&2
echo "════════════════════════════════════════════════════════" >&2
echo "  Foundation Audit v3 — $TOTAL_LENSES lenses, P=$MAX_PARALLEL" >&2
echo "  Phase: $PHASE" >&2
echo "  Date:  $DATE" >&2
echo "════════════════════════════════════════════════════════" >&2

run_lens() {
    local n="$1" lens="$2" scopes="$3"
    local slug
    slug=$(slugify "$lens")
    local expected="${OUT_DIR}/audit-${DATE}-${slug}.md"
    local log="${LOG_DIR}/lens-${n}-${slug}.log"

    if [[ -f "$expected" ]]; then
        echo "[${n}/${TOTAL_LENSES}] SKIP (exists): $expected" >&2
        return 0
    fi

    # Also skip if a prior-dated copy from an earlier run exists under the same slug.
    local prior
    prior=$(ls "$OUT_DIR"/audit-*-"${slug}".md 2>/dev/null | head -1 || true)
    if [[ -n "$prior" ]]; then
        echo "[${n}/${TOTAL_LENSES}] SKIP (prior date): $prior" >&2
        return 0
    fi

    echo "[${n}/${TOTAL_LENSES}] START: ${slug}" >&2

    local scope_args=()
    for s in $scopes; do
        scope_args+=("--scope" "$s")
    done

    if "$SCRIPT_DIR/audit.sh" \
        --phase "$PHASE" \
        --lens "$lens" \
        "${scope_args[@]}" \
        > "$log" 2>&1; then
        echo "[${n}/${TOTAL_LENSES}] OK:    ${slug}" >&2
    else
        echo "[${n}/${TOTAL_LENSES}] FAIL:  ${slug} (see $log)" >&2
        return 1
    fi
}

# Iterate lenses, fan out at MAX_PARALLEL
while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    LENS_NUM=$((LENS_NUM + 1))

    lens="${line%%|||*}"
    scopes="${line##*|||}"

    run_lens "$LENS_NUM" "$lens" "$scopes" &
    JOB_PIDS+=($!)

    # Throttle to MAX_PARALLEL concurrent
    if [[ ${#JOB_PIDS[@]} -ge $MAX_PARALLEL ]]; then
        # Wait for the oldest job (works on bash 3.2 — no `wait -n`)
        wait "${JOB_PIDS[0]}" || true
        JOB_PIDS=("${JOB_PIDS[@]:1}")
    fi
done <<< "$LENSES"

# Wait for the final batch
wait

# Count actual successful outputs
PRODUCED=$(ls "$OUT_DIR"/audit-*-*.md 2>/dev/null | grep -v CONSOLIDATED | wc -l | tr -d ' ')
echo "" >&2
echo "Phase 1 done. Per-lens audits on disk: $PRODUCED / $TOTAL_LENSES" >&2

if [[ "$PRODUCED" != "$TOTAL_LENSES" ]]; then
    echo "WARNING: missing lens audits — re-run this script to retry failed ones." >&2
fi

# --- Phase 2: dependency advisories ---

echo "" >&2
echo "════════ Phase 2: composer audit + outdated ════════" >&2

composer audit --no-interaction > "$DEPS_DIR/composer-audit.txt" 2>&1 || true
composer outdated --direct --no-interaction > "$DEPS_DIR/composer-outdated.txt" 2>&1 || true

echo "✓ wrote $DEPS_DIR/composer-audit.txt" >&2
echo "✓ wrote $DEPS_DIR/composer-outdated.txt" >&2

# --- Phase 3: final Sonnet consolidation ---

echo "" >&2
echo "════════ Phase 3: Sonnet consolidation pass ════════" >&2

CONS_PROMPT="$(mktemp)"
CONS_SYS_PROMPT="$(mktemp)"
trap 'rm -f "$CONS_PROMPT" "$CONS_SYS_PROMPT"' EXIT

# System prompt — carried verbatim from AUDIT-PROMPT.md "Consolidator system prompt".
cat > "$CONS_SYS_PROMPT" <<'SYSEOF'
You are the v3 consolidator for the Partna foundation audit. Your job:

1. Read the 26 lens audits + composer audit + composer outdated outputs.
2. Cross-reference every finding against the current codebase via Read/Grep/Glob BEFORE
   including it. If you cannot verify a finding names a real file/symbol/line, DROP it
   (precision > recall).
3. Produce ONE consolidated markdown document matching the v1 CONSOLIDATED template's
   structure section-by-section.

Dual-axis tagging: this audit targets BOTH prepilot correctness AND scale to 10k site
pages. Tag every scale-driven finding with `[@10k]` in its title so the reader can triage
"breaks now" vs "breaks at scale". Severity reflects pilot impact: a 10k-only degradation
is usually P2/P3 unless it also corrupts data or breaks correctness today.

Format invariants (the audit orchestrator parses these files — match v1 exactly):

- `# Consolidated Foundation Audit v3 — 2026-05-31`
- One-liner: Branch / source count / raw count / final count / bundle count.
- "Read before fixing" pointer paragraph (the #P0-/#P1- + lens-backref scheme).
- `## Model selection — read once` — copy v1's text verbatim (standing convention).
- `## Dependency advisories` with `### Security CVEs` and `### Direct dependency drift`.
- `## Cross-lens high-confidence findings` — themes that surface under 2+ lenses.
- `## P0`, `## P1`, `## P2`, `## P3` — P2/P3 sub-grouped by theme.
- `## Suggested bundled fix sessions` — `### Bundle BN: <title> (N items) — Effort: S/M/L/XL`
  blocks, each with rationale, approach, dependencies, AND the two Session prompts
  (Implementation + Review).
- `## Standalone — do NOT bundle`.
- `## Deduplication notes`.
- `## Coverage report` with `### Coverage by lens` table and `### Coverage gaps`.
- Final line: `*Generated 2026-05-31 by ...*`.

Per-finding format (every P0/P1/P2/P3 entry):
- [ ] **#PN-NN** <one-line title, with [@10k] if scale-driven> — Lens: `<lens-shortname>`
    - Where: <file>:<line> (· optional more files)
    - What: <2–4 sentence context>
    - Fix: <actionable description>
    - Models: impl=<x> · review=<y>

Bundle entries:
### Bundle BN: <title> (N items — #P*-XX..) — Effort: S/M/L/XL
- [ ] bundle status checkbox
- Items: `#P*-XX`, ...
- Models: impl=<x> · review=<y>
- Rationale / Suggested approach / Dependencies
**Session prompts** (Implementation + Review blockquotes).

Model selection rules (fill the Models: lines):
- haiku — trivial mechanical changes (delete a line, add a default, single-line report($e)).
- sonnet — default for most implementation (refactors, Resources, observers, queue swaps, migrations, new services).
- opus — load-bearing invariants (auth gates, RLS policies, transaction boundaries, single-writer KV contract, GDPR/PII flows, schema migrations).
- Review with opus on auth/RLS/KV/transactions/GDPR/PII/schema/mail; sonnet elsewhere. Never review with haiku.

Bundling rules:
- 2–6 findings per bundle, grouped by root cause + atomic-PR size.
- Items touching single-writer KV, schema-wide RLS, or needing human design decisions go in `## Standalone — do NOT bundle`.

Diff-vs-prior awareness:
- Many v1/v2 findings are already fixed — check the recent commit log. If a lens doesn't
  surface an old item, that's expected; don't re-add it from memory. Mark genuinely new
  findings as new in a Run-notes paragraph.

Output rules:
- No preamble. Start at the first `#`. No closing chatter. No code-fence wrapping the whole output. Raw markdown.
SYSEOF

# User message — inline 26 lens audits + composer outputs + v1 template + current state
{
    echo "# v3 Consolidation Task"
    echo ""
    echo "**Date:** $DATE"
    echo "**Branch:** $(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
    echo ""
    echo "## Recent commits (use to validate fixes since v1/v2)"
    echo ""
    echo '```'
    git log --oneline -30 2>/dev/null || echo "(git unavailable)"
    echo '```'
    echo ""
    echo "## v1 CONSOLIDATED — structural template (match section ordering / bundle format / conventions exactly)"
    echo "## Do NOT use the v2 CONSOLIDATED — it is a session-limit stub."
    echo ""
    if [[ -f "audits/foundation-audit-v1/audit-2026-05-24-CONSOLIDATED.md" ]]; then
        cat "audits/foundation-audit-v1/audit-2026-05-24-CONSOLIDATED.md"
    else
        echo "(v1 template missing — proceed from the format rules in the system prompt alone)"
    fi
    echo ""
    echo "---"
    echo ""
    echo "## v3 per-lens audits ($PRODUCED files)"
    echo ""
    for f in "$OUT_DIR"/audit-${DATE}-*.md; do
        [[ -f "$f" ]] || continue
        [[ "$f" == *CONSOLIDATED* ]] && continue
        echo "<!-- ═══ LENS FILE: $(basename "$f") ═══ -->"
        echo ""
        cat "$f"
        echo ""
        echo "---"
        echo ""
    done
    echo ""
    echo "## composer audit (security CVEs)"
    echo ""
    echo '```'
    cat "$DEPS_DIR/composer-audit.txt"
    echo '```'
    echo ""
    echo "## composer outdated --direct (dependency drift)"
    echo ""
    echo '```'
    cat "$DEPS_DIR/composer-outdated.txt"
    echo '```'
    echo ""
    echo "---"
    echo ""
    echo "Now produce the v3 CONSOLIDATED audit per the system prompt format rules."
    echo "Output the markdown only — no preamble, no code-fence wrapping the whole output, start at the first '#'."
} > "$CONS_PROMPT"

PROMPT_BYTES=$(wc -c < "$CONS_PROMPT")
echo "→ consolidation prompt: ${PROMPT_BYTES} bytes (~$((PROMPT_BYTES / 4)) tokens)" >&2

claude -p \
    --model sonnet \
    --system-prompt "$(<"$CONS_SYS_PROMPT")" \
    --disallowed-tools "Bash Edit Write NotebookEdit WebFetch WebSearch Skill Agent TaskCreate TaskUpdate TaskGet TaskList TaskOutput TaskStop" \
    --max-budget-usd 6.00 \
    --output-format text \
    --no-session-persistence \
    < "$CONS_PROMPT" > "$TODAY_CONSOLIDATED"

ITEMS=$(grep -c '^- \[ \]' "$TODAY_CONSOLIDATED" 2>/dev/null || echo 0)
BUNDLES=$(grep -cE '^### Bundle B[0-9]+' "$TODAY_CONSOLIDATED" 2>/dev/null || echo 0)

echo "" >&2
echo "════════════════════════════════════════════════════════" >&2
echo "  DONE" >&2
echo "════════════════════════════════════════════════════════" >&2
echo "  Per-lens audits: $PRODUCED / $TOTAL_LENSES" >&2
echo "  Consolidated:    $TODAY_CONSOLIDATED" >&2
echo "  Item count:      $ITEMS open checkboxes" >&2
echo "  Bundles:         $BUNDLES" >&2
echo "" >&2
