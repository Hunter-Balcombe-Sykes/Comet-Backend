#!/usr/bin/env bash
# audit.sh — One-command audit: DeepSeek scan(s) → Claude adjudicate → final audit file.
#
# Targeted mode (single lens):
#   scripts/audit/audit.sh \
#     --lens "auth/policy coverage on the new SitePolicy" \
#     --scope app/Policies/SitePolicy.php \
#     --scope app/Http/Controllers/Api/Professional/Uploads/
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
#     core         — the 8 always-relevant correctness lenses (same as --full)
#     concurrency  — caching-gold-standard + webhook-idempotency + transaction-boundaries
#                    (correctness-under-concurrency trio: silent state drift bugs)
#     pre-merge    — migration-safety + api-contract + configuration-hygiene + test-coverage
#                    (run before merging a PR that touches schema or public API)
#     code-quality — code-quality-slop + semantic-correctness
#                    (AI slop + plausible-but-wrong logic the compiler can't see;
#                     the qualitative companion to `composer analyse`)
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

usage() { sed -n '2,47p' "$0" | sed 's/^# \?//'; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --scope)         SCOPE_ARGS+=("--scope" "$2"); shift 2 ;;
        --lens)          LENS_ARG=("--lens" "$2"); shift 2 ;;
        --lens-file)     LENS_ARG=("--lens-file" "$2"); shift 2 ;;
        --full)          FULL=true; shift ;;
        --bundle)        BUNDLE="$2"; shift 2 ;;
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
    [[ ${#LENS_ARG[@]} -gt 0 ]] || { echo "--lens, --bundle, or --full is required" >&2; exit 2; }
fi
[[ ${#SCOPE_ARGS[@]} -gt 0 ]]     || { echo "--scope is required (one or more)" >&2; exit 2; }
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
        *)
            echo "Unknown bundle: $BUNDLE (expected: core, concurrency, pre-merge, code-quality)" >&2
            exit 2
            ;;
    esac
    LENS_COUNT=${#LENS_FILES[@]}

    # Verify all lens files exist BEFORE starting expensive scans
    for lf in "${LENS_FILES[@]}"; do
        [[ -f "$lf" ]] || { echo "Lens file missing: $lf" >&2; exit 2; }
    done

    echo "" >&2
    echo "════════ Bundle '$BUNDLE' — $LENS_COUNT lens-focused scans + 1 adjudication ════════" >&2

    : > "$DRAFTS"  # truncate / create

    LENS_NUM=0
    for lf in "${LENS_FILES[@]}"; do
        LENS_NUM=$((LENS_NUM + 1))
        LENS_NAME=$(basename "$lf" .md)
        echo "" >&2
        echo "──── Scan ${LENS_NUM}/${LENS_COUNT}: $LENS_NAME ────" >&2
        LENS_DRAFTS="$DRAFTS_DIR/drafts-${LENS_NAME}.md"
        "$SCRIPT_DIR/audit-scan.sh" --lens-file "$lf" "${SCOPE_ARGS[@]}" --out "$LENS_DRAFTS"
        {
            echo ""
            echo "<!-- ═══ LENS: $LENS_NAME ═══ -->"
            echo ""
            cat "$LENS_DRAFTS"
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
