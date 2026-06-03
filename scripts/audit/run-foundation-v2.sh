#!/usr/bin/env bash
# run-foundation-v2.sh — Re-run the 25-lens foundation audit (v2).
#
# Phase 1: 25 per-lens DeepSeek scans → Sonnet adjudications (P=4 parallel)
# Phase 2: composer audit + composer outdated --direct
# Phase 3: final Sonnet consolidation pass → audit-YYYY-MM-DD-CONSOLIDATED.md
#
# Resumable: re-running skips any lens whose expected output file already exists.
# All outputs land under audits/foundation-audit-v2/.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
cd "$REPO_ROOT"

PHASE="foundation-audit-v2"
OUT_DIR="audits/${PHASE}"
DEPS_DIR="${OUT_DIR}/.deps"
LOG_DIR="${OUT_DIR}/.logs"
DATE="$(date +%F)"
TODAY_CONSOLIDATED="${OUT_DIR}/audit-${DATE}-CONSOLIDATED.md"

mkdir -p "$OUT_DIR" "$DEPS_DIR" "$LOG_DIR"

# --- Lens table: one record per line, fields separated by '|||'
#     Field 1: lens text (verbatim — used by audit.sh and for slug derivation)
#     Field 2: space-separated --scope arguments

LENSES=$(cat <<'EOF'
AccountCapabilities bypass, missing capability checks before notification/API/job actions, AccountType enum integrity|||app/Services/Accounts app/Jobs/Notifications app/Http/Controllers app/Enums app/Models/Core/Professional
Authorization gaps, policy coverage, 403/404 leakage, input validation, JWT/AAL handling, IDOR, route middleware gaps, internal-API auth weakness|||app/Http/Controllers app/Policies app/Http/Middleware app/Http/Requests routes
bootstrap middleware stack gaps, global middleware order bugs, exception render leakage, route model binding misuse, Laravel 12 bootstrap config drift|||bootstrap app/Exceptions app/Http/Middleware
cache invalidation gaps, stampede risk, stale reads, KV/Redis/HTTP cache layering correctness|||app/Services/Cache app/Jobs/Cache app/Observers app/Services/PublicSite app/Services/Site
Cloudflare worker signature verification, R2 presigned URL leakage, public bucket scope|||app/Services/Cloudflare app/Services/Media cloudflare-worker/src/index.js
Architecture hygiene, service boundary correctness, dead code post-standalone-strip|||app/Http/Controllers app/Services app/Http/Concerns
Controllers with business logic, leaky service boundaries, fat-controller anti-patterns post-strip|||app/Http/Controllers app/Services
dangerous config defaults, missing artisan command authz, listener cascade bugs, feature-flag determinism, diagnostic info leakage|||config app/Console/Commands app/Listeners app/Observers app/Services/FeatureFlags
deploy script safety, CI workflow secrets handling, action permission scope, dangerous post-deploy hooks, env.example drift vs config, composer-script footguns|||.github/workflows/ci.yml composer.json composer.lock .env.example scripts
env reads outside config layer, hardcoded secrets, plaintext credentials/PII leaking into logs and exception messages|||app/Services/Auth app/Services/Professional app/Services/Media app/Services/Streaming
GDPR end-to-end completeness, DSAR coverage, deletion cascade gaps, retention enforcement, data-export integrity, missing models in deletion flow|||app/Jobs/Gdpr app/Mail/Gdpr app/Models app/Exceptions/Gdpr app/Services/Professional/DataExport app/Http/Controllers supabase/migrations
JWT verification gaps, AAL2 bypass, MFA enforcement holes, claim trust, token replay|||app/Services/Auth app/Http/Middleware/Auth app/Exceptions/Auth
missing RLS policies, role grants too permissive, app_backend privileges, schema-level authz gaps, unsafe seed data, Supabase project config|||supabase/migrations supabase/seed.sql supabase/config.toml config/database.php
missing structured context, PII in logs, silent catch blocks, gaps in exception/slow-job coverage, audit-log integrity|||app/Services app/Jobs app/Http/Middleware/Logging
missing transactions, race conditions, soft-delete consistency, FK/unique constraint gaps, N+1 writes|||app/Services app/Models app/Jobs supabase/migrations
N+1 queries, unbounded result sets, synchronous work belonging in jobs, hot-path inefficiencies|||app/Http/Controllers app/Services app/Http/Resources
non-idempotent jobs, unsafe retries, missing failure handlers, wrong queue lane, unbounded backoff|||app/Jobs
presigned URL leakage, storage authz, video pipeline integrity, orphaned media, MIME validation|||app/Services/Media app/Services/Streaming app/Jobs/Media app/Jobs/Streaming
rate limiting coverage on public + auth routes, throttle bypass, CORS misconfig|||routes app/Http/Middleware app/Providers config/cors.php bootstrap
raw model leakage, inconsistent Resource shapes, breaking-change risk, missing pagination/filtering contracts|||app/Http/Resources app/Http/Controllers routes
scheduler safety, missing withoutOverlapping locks, missing onOneServer, silent scheduled-task failures, missing critical schedules, frequency vs runtime mismatch|||routes/console.php app/Console/Commands app/Jobs
security header gaps, HTTPS enforcement, frame/CSP/HSTS posture|||app/Http/Middleware/SecureHeaders.php bootstrap/app.php app/Http/Middleware app/Providers
service provider boot bugs, dangerous singletons, mail XSS, unsigned mail links, PII in emails, global helper footguns, email-send layer correctness|||app/Providers app/Mail resources/views/emails app/Helpers
SUBDOMAIN_KV write paths, single-writer invariant violations, KV/DB drift, sync job idempotency|||app/Services/Cloudflare app/Jobs/Cloudflare app/Observers cloudflare-worker/src/index.js routes
webhook signature verification, Supabase email hook auth, third-party webhook replay risk|||app/Http/Controllers/Api/Internal app/Http/Middleware/Auth app/Services/Auth routes
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

# --- Phase 1: 25 lens scans, P=4 parallel, resumable ---

MAX_PARALLEL=4
declare -a JOB_PIDS=()
LENS_NUM=0
TOTAL_LENSES=$(printf '%s\n' "$LENSES" | grep -c '|||')

echo "" >&2
echo "════════════════════════════════════════════════════════" >&2
echo "  Foundation Audit v2 — $TOTAL_LENSES lenses, P=$MAX_PARALLEL" >&2
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

    # Also skip if a v1-dated copy from a prior run exists under the same slug
    # (e.g. a partial run yesterday). Look for any matching slug under this phase.
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

# System prompt — short, focused on role + format invariants
cat > "$CONS_SYS_PROMPT" <<'SYSEOF'
You are the **v2 consolidator** for the Partna foundation audit. Your job:

1. Read 25 lens audits + composer audit + composer outdated outputs (in the user message).
2. Cross-reference findings against the current codebase via Read/Grep/Glob (you have those tools).
3. Produce ONE consolidated markdown document in the EXACT structure shown by the v1 CONSOLIDATED template the user will inline.

# Format invariants (non-negotiable)

The audit orchestrator parses these files. Match v1's structure section-by-section:

- `# Consolidated Foundation Audit — <DATE>` (today's date)
- Branch / source count / raw count / final count / bundle count one-liner under the heading
- Optional Run notes paragraph
- "Read before fixing" pointer paragraph explaining #P0-/#P1-/#P2-/#P3- + lens backref scheme
- `## Model selection — read once` — copy the v1 text VERBATIM (it is a standing convention, not regeneratable)
- `## Dependency advisories` with `### Security CVEs` and `### Direct dependency drift` subsections
- `## Cross-lens high-confidence findings` — themes that surface under 2+ lenses
- `## P0`, `## P1`, `## P2`, `## P3` — each tier; P2 + P3 sub-grouped by theme (e.g. `### Authorization & policy doctrine`, `### Data integrity & lifecycle`, etc.)
- `## Suggested bundled fix sessions` with `### Bundle BN: <title> (N items) — Effort: S/M/L/XL` blocks each containing rationale, suggested approach, dependencies, AND the two Session prompts (Implementation + Review) per v1
- `## Standalone — do NOT bundle`
- `## Deduplication notes`
- `## Coverage report` with `### Coverage by lens` table and `### Coverage gaps`
- Final line: `*Generated <date> by ...*`

Per-finding format (every P0/P1/P2/P3 entry):
```
- [ ] **#PN-NN** <one-line title> — Lens: `<lens-shortname>`
    - Where: <file>:<line> (· optional more files)
    - What: <2–4 sentence context>
    - Fix: <actionable description>
    - Models: impl=<x> · review=<y>
```

Bundle entries:
```
### Bundle BN: <title> (N items — #P*-XX..) — Effort: S/M/L/XL
- [ ] Bundle status checkbox
- Items: `#P*-XX`, `#P*-XX`, ...
- Models: impl=<x> · review=<y>
- Rationale: <1–2 sentences>
- Suggested approach: <concrete plan>
- Dependencies: <other bundles or "none">

**Session prompts** (paste with the bundle into a fresh session):

*Implementation:*
> ...

*Review:*
> ...
```

# Model selection rules (use to fill `Models:` lines)

- haiku — trivial mechanical changes (delete a line, add a default, single-line `report($e)`, drop a log key)
- sonnet — default for most implementation work (refactors, Resources, observers, queue swaps, migrations, new services)
- opus — load-bearing invariants (auth gates, RLS policies, transaction boundaries, single-writer KV contract, GDPR/PII flows, schema migrations)
- Review with opus on auth/RLS/KV/transactions/GDPR/PII/schema/mail; sonnet elsewhere. Never review with haiku.

# Bundling rules

- 2–6 findings per bundle, grouped by root cause + atomic-PR size
- Items that touch single-writer invariants (SUBDOMAIN_KV), schema-wide RLS, or need human design decisions go in `## Standalone — do NOT bundle`
- Use v1 bundle names (B1=Symfony CVEs, B2=Bootstrap, B3=PII/log hygiene, etc.) when the same bundle would re-form; introduce new BNN identifiers for new bundles. Re-numbering is fine — pick what reads cleanest.

# Diff-vs-v1 awareness

Many v1 findings have been fixed (see commit log in the user message). When a lens audit doesn't surface an old item, that's expected — don't re-add it from memory. When new findings emerge that v1 didn't catch, mark them as new in the Run notes paragraph.

# Output rules

- **No preamble.** Start at `# Consolidated Foundation Audit — <DATE>`.
- **No closing chatter.** End at the generation footer line.
- **No code-fence wrapping the whole output.** Emit raw markdown.
- Use Read/Grep to verify any finding that names a file/symbol BEFORE including it. If you can't verify, drop the finding (precision > recall).
SYSEOF

# User message — inline 25 lens audits + composer outputs + v1 template + current state
{
    echo "# v2 Consolidation Task"
    echo ""
    echo "**Date:** $DATE"
    echo "**Branch:** $(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
    echo ""
    echo "## Recent commits (use to validate fixes since v1)"
    echo ""
    echo '```'
    git log --oneline -30 2>/dev/null || echo "(git unavailable)"
    echo '```'
    echo ""
    echo "## v1 CONSOLIDATED — structural template (match section ordering / bundle format / conventions exactly)"
    echo ""
    if [[ -f "audits/foundation-audit-v1/audit-2026-05-24-CONSOLIDATED.md" ]]; then
        cat "audits/foundation-audit-v1/audit-2026-05-24-CONSOLIDATED.md"
    else
        echo "(v1 template missing — proceed from the format rules in the system prompt alone)"
    fi
    echo ""
    echo "---"
    echo ""
    echo "## v2 per-lens audits ($PRODUCED files)"
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
    echo "Now produce the v2 CONSOLIDATED audit per the system prompt format rules."
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
