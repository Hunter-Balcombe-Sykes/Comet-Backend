#!/usr/bin/env bash
# audit-adjudicate.sh — Sonnet adjudication tier via the local `claude` CLI.
#
# Reads drafts.md (from audit-scan.sh) + the same scope, calls Claude Sonnet
# through Claude Code's OAuth (no separate ANTHROPIC_API_KEY required), and
# emits the final audit markdown ready to ship.
#
# Usage:
#   scripts/audit/audit-adjudicate.sh \
#     --drafts audit-drafts.md \
#     --lens "auth/policy coverage" \
#     --scope app/Policies/SitePolicy.php \
#     --scope app/Http/Controllers/Api/Professional/Uploads/ \
#     [--out audit-2026-05-04-auth-policy-coverage.md]
#
# Required: `claude` CLI on PATH and you must be logged in (run `claude` once if not).
# Cost goes against your Claude plan (Max / Pro / Free) — no additional API key needed.

set -euo pipefail

# --- Args ---
SCOPE_PATHS=()
DRAFTS=""
LENS=""
LENS_FILE=""
OUT=""
OUT_DIR=""
OUT_PROVIDED=false
MODEL="sonnet"
ADJ_PROMPT="$(dirname "$0")/adjudicate-prompt.md"
MAX_BUDGET="2.00"
NO_SOURCE=false   # --no-source: skip inlining scope source; adjudicator uses Read/Grep instead

usage() { sed -n '2,17p' "$0" | sed 's/^# \?//'; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --drafts)        DRAFTS="$2"; shift 2 ;;
        --scope)         SCOPE_PATHS+=("$2"); shift 2 ;;
        --lens)          LENS="$2"; shift 2 ;;
        --lens-file)     LENS_FILE="$2"; shift 2 ;;
        --out)           OUT="$2"; OUT_PROVIDED=true; shift 2 ;;
        --out-dir)       OUT_DIR="$2"; shift 2 ;;
        --model)         MODEL="$2"; shift 2 ;;
        --system-prompt) ADJ_PROMPT="$2"; shift 2 ;;
        --max-budget)    MAX_BUDGET="$2"; shift 2 ;;
        --no-source)     NO_SOURCE=true; shift ;;
        -h|--help)       usage; exit 0 ;;
        *) echo "Unknown arg: $1" >&2; usage >&2; exit 2 ;;
    esac
done

# --- Validate ---
[[ -n "$DRAFTS" && -f "$DRAFTS" ]]   || { echo "--drafts file required and must exist" >&2; exit 2; }
[[ ${#SCOPE_PATHS[@]} -gt 0 ]]       || { echo "--scope is required (one or more)" >&2; exit 2; }
[[ -n "$LENS" || -n "$LENS_FILE" ]]  || { echo "--lens or --lens-file required" >&2; exit 2; }
[[ -f "$ADJ_PROMPT" ]]               || { echo "adjudicate prompt not found: $ADJ_PROMPT" >&2; exit 2; }
command -v claude >/dev/null         || { echo "claude CLI not found on PATH (install from claude.ai/code)" >&2; exit 2; }

LENS_TEXT="$LENS"
[[ -n "$LENS_FILE" ]] && LENS_TEXT="$(<"$LENS_FILE")"

if [[ -z "$OUT" ]]; then
    SLUG=$(echo "$LENS_TEXT" | tr '[:upper:]' '[:lower:]' | tr ' /' '--' | tr -cd 'a-z0-9-' | head -c 50)
    SLUG="${SLUG%-}"
    OUT="audit-$(date +%F)-${SLUG}.md"
fi

# When --out-dir is given and --out was NOT explicitly set, organize the
# auto-derived filename into the directory. Explicit --out always wins.
if [[ -n "$OUT_DIR" ]] && ! $OUT_PROVIDED; then
    mkdir -p "$OUT_DIR"
    OUT="${OUT_DIR}/${OUT}"
fi

# Refuse to overwrite an auto-derived path. The slug is derived from lens
# text alone, so re-running the same lens on different scopes would silently
# clobber the prior audit. Append -2, -3, ... until a free name is claimed.
# Explicit --out always wins (operator opted into the exact filename).
#
# Concurrency-safe: uses `set -o noclobber` inside a subshell with `: > file`
# to atomically test-and-create. The shell's open(O_CREAT|O_EXCL) syscall is
# the single point where two parallel pipelines compete — the loser falls
# through to the next candidate, so each pipeline ends up with a unique
# placeholder. claude's later `> "$OUT"` redirect overwrites the empty
# placeholder with real output (parent shell does not have noclobber set).
if ! $OUT_PROVIDED; then
    base="${OUT%.md}"
    candidate="$OUT"
    n=1
    while ! (set -o noclobber; : > "$candidate") 2>/dev/null; do
        n=$((n + 1))
        candidate="${base}-${n}.md"
    done
    if [[ "$candidate" != "$OUT" ]]; then
        echo "→ Output file existed; claimed ${candidate} to avoid overwrite" >&2
    fi
    OUT="$candidate"
fi

# --- Build user message ---
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
USER_MSG="$TMP/user.md"

{
    echo "# Adjudication Task"
    echo ""
    echo "**Lens:** $LENS_TEXT"
    echo "**Branch:** $(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
    echo "**Date:** $(date +%F)"
    echo ""
    echo "## Recent commits (use to validate fix recommendations)"
    echo ""
    echo '```'
    git log --oneline -20 2>/dev/null || echo "(git unavailable)"
    echo '```'
    echo ""
    echo "## DeepSeek Drafts to Adjudicate"
    echo ""
    cat "$DRAFTS"
    echo ""
    if $NO_SOURCE; then
        echo "## Source Files (for Evidence verification)"
        echo ""
        echo "Source is NOT inlined here — the scope is too large to fit the prompt."
        echo "Use your \`Read\` / \`Grep\` / \`Glob\` tools to verify every finding's"
        echo "Evidence against the real files. You are running in the project root."
        echo "Scope paths covered by this audit:"
        echo ""
        for path in "${SCOPE_PATHS[@]}"; do echo "- \`$path\`"; done
    else
        echo "## Source Files (for Evidence verification)"
        echo ""
        for path in "${SCOPE_PATHS[@]}"; do
            if [[ -d "$path" ]]; then
                files=$(find "$path" -type f \( -name "*.php" -o -name "*.blade.php" -o -name "*.sql" \) | sort)
            elif [[ -f "$path" ]]; then
                files="$path"
            else
                echo "WARNING: scope path not found: $path" >&2
                continue
            fi
            while IFS= read -r f; do
                [[ -f "$f" ]] || continue
                echo "### $f"
                echo '```php'
                cat "$f"
                echo '```'
                echo ""
            done <<< "$files"
        done
    fi

    echo "---"
    echo ""
    echo "Adjudicate per the system instructions. Emit only the final audit markdown — no preamble, no code-fence wrapping, start at the first \`#\` of the title."
} > "$USER_MSG"

# --- Fire via claude CLI (OAuth-authed, no API key) ---
echo "→ Adjudicating with claude --model $MODEL ..." >&2
START=$(date +%s)

# --system-prompt fully replaces Claude Code's default — no CLAUDE.md, no auto-memory,
# no dynamic sections. Pure adjudication context.
# --disallowed-tools blocks mutation/external tools so the model just writes markdown.
SYSTEM_PROMPT="$(<"$ADJ_PROMPT")"

claude -p \
    --model "$MODEL" \
    --system-prompt "$SYSTEM_PROMPT" \
    --disallowed-tools "Bash Edit Write NotebookEdit WebFetch WebSearch Skill Agent TaskCreate TaskUpdate TaskGet TaskList TaskOutput TaskStop" \
    --max-budget-usd "$MAX_BUDGET" \
    --output-format text \
    --no-session-persistence \
    < "$USER_MSG" > "$OUT"

ELAPSED=$(( $(date +%s) - START ))

ITEMS=$(grep -c '^- \[ \]' "$OUT" 2>/dev/null || echo 0)
echo "" >&2
echo "✓ Wrote $OUT — $ITEMS findings, ${ELAPSED}s elapsed" >&2
echo "Final audit ready: $OUT" >&2
