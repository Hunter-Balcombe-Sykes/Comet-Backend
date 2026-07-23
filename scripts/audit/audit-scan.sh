#!/usr/bin/env bash
# audit-scan.sh — DeepSeek scan tier for the Partna dual-worker audit pipeline.
#
# Reads source files, fires deepseek-v4-pro with the Partna audit format spec,
# and writes drafts.md. Drafts must then be adjudicated by Claude Sonnet — the
# scan tier reliably catches real findings but mis-tiers ~30% and occasionally
# proposes the wrong fix when it lacks adjacent context (validated 2026-05-04).
#
# Usage:
#   scripts/audit/audit-scan.sh \
#     --lens "auth/policy coverage on the new SitePolicy and migrated controllers" \
#     --scope app/Policies/SitePolicy.php \
#     --scope app/Http/Controllers/Api/User/Uploads/ \
#     --out audit-drafts.md
#
# Required env: DEEPSEEK_API_KEY

set -euo pipefail

# --- Load .env if present (gitignored, holds DEEPSEEK_API_KEY) ---
ENV_FILE="$(dirname "$0")/.env"
if [[ -f "$ENV_FILE" ]]; then
    set -a
    # shellcheck disable=SC1090
    source "$ENV_FILE"
    set +a
fi

# --- Args ---
SCOPE_PATHS=()
LENS=""
LENS_FILE=""
OUT="audit-drafts.md"
MODEL="deepseek-v4-pro"
INCLUDE_GIT=true
SYSTEM_PROMPT="$(dirname "$0")/system-prompt.md"

usage() {
    sed -n '2,17p' "$0" | sed 's/^# \?//'
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --scope)         SCOPE_PATHS+=("$2"); shift 2 ;;
        --lens)          LENS="$2"; shift 2 ;;
        --lens-file)     LENS_FILE="$2"; shift 2 ;;
        --out)           OUT="$2"; shift 2 ;;
        --model)         MODEL="$2"; shift 2 ;;
        --system-prompt) SYSTEM_PROMPT="$2"; shift 2 ;;
        --no-git)        INCLUDE_GIT=false; shift ;;
        -h|--help)       usage; exit 0 ;;
        *) echo "Unknown arg: $1" >&2; usage >&2; exit 2 ;;
    esac
done

# --- Validate ---
[[ -n "${DEEPSEEK_API_KEY:-}" ]]      || { echo "DEEPSEEK_API_KEY not set" >&2; exit 2; }
[[ ${#SCOPE_PATHS[@]} -gt 0 ]]        || { echo "--scope is required (one or more)" >&2; exit 2; }
[[ -n "$LENS" || -n "$LENS_FILE" ]]   || { echo "--lens or --lens-file required" >&2; exit 2; }
[[ -f "$SYSTEM_PROMPT" ]]             || { echo "system prompt not found: $SYSTEM_PROMPT" >&2; exit 2; }
command -v jq >/dev/null              || { echo "jq required (brew install jq)" >&2; exit 2; }
command -v curl >/dev/null            || { echo "curl required" >&2; exit 2; }

LENS_TEXT="$LENS"
[[ -n "$LENS_FILE" ]] && LENS_TEXT="$(<"$LENS_FILE")"

# --- Build user message ---
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
USER_MSG="$TMP/user.md"
SYS_MSG="$TMP/system.md"

{
    echo "# Audit Scope"
    echo ""
    echo "**Lens:** $LENS_TEXT"
    echo ""
    if $INCLUDE_GIT; then
        echo "**Branch:** $(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
        echo ""
        echo "## Recent commits (last 20)"
        echo ""
        echo '```'
        git log --oneline -20 2>/dev/null || echo "(git unavailable)"
        echo '```'
        echo ""
    fi
    echo "## Files Under Audit"
    echo ""

    for path in "${SCOPE_PATHS[@]}"; do
        if [[ -d "$path" ]]; then
            # .js/.ts/.mjs cover the Cloudflare Worker AND the Next.js frontend;
            # .tsx/.jsx cover React components (the bulk of the frontend surface —
            # a glob without them reads a frontend app/ dir as ~half its files and
            # reports the rest clean, indistinguishable from no findings); .yml/.yaml
            # cover CI workflows (.github/workflows); .sh covers deploy scripts.
            # everything else is PHP/SQL. Excludes: node_modules + .venv (bundled
            # deps), and .next/build/out/.turbo (frontend build output — generated,
            # not source).
            files=$(find "$path" -type f \( -name "*.php" -o -name "*.blade.php" -o -name "*.sql" -o -name "*.js" -o -name "*.mjs" -o -name "*.ts" -o -name "*.tsx" -o -name "*.jsx" -o -name "*.yml" -o -name "*.yaml" -o -name "*.sh" \) ! -path "*/node_modules/*" ! -path "*/.venv/*" ! -path "*/.next/*" ! -path "*/build/*" ! -path "*/out/*" ! -path "*/.turbo/*" | sort)
        elif [[ -f "$path" ]]; then
            files="$path"
        else
            echo "WARNING: scope path not found: $path" >&2
            continue
        fi

        while IFS= read -r f; do
            [[ -f "$f" ]] || continue
            # Fence each file by its REAL language. The old hardcoded ```php told
            # the scanner every .tsx/.ts/.sql file was PHP, degrading how it reads
            # non-PHP source. Extension → fence tag; unknown types fence bare.
            case "$f" in
                *.blade.php|*.php) lang=php ;;
                *.tsx)             lang=tsx ;;
                *.jsx)             lang=jsx ;;
                *.ts)              lang=ts ;;
                *.mjs|*.js)        lang=js ;;
                *.sql)             lang=sql ;;
                *.yml|*.yaml)      lang=yaml ;;
                *.sh)              lang=bash ;;
                *)                 lang= ;;
            esac
            echo "### $f"
            echo ""
            echo "\`\`\`${lang}"
            cat "$f"
            echo '```'
            echo ""
        done <<< "$files"
    done

    echo "---"
    echo ""
    echo "Now perform the audit. Output the findings list only."
} > "$USER_MSG"

# --- Build system prompt (template + lens trailer) ---
{
    cat "$SYSTEM_PROMPT"
    echo ""
    echo "# This Audit's Lens"
    echo ""
    echo "$LENS_TEXT"
} > "$SYS_MSG"

# --- Build payload ---
PAYLOAD="$TMP/payload.json"
jq -n \
    --rawfile sys "$SYS_MSG" \
    --rawfile usr "$USER_MSG" \
    --arg model "$MODEL" \
    '{
        model: $model,
        messages: [
            {role: "system", content: $sys},
            {role: "user",   content: $usr}
        ],
        max_tokens: 393216,
        thinking: {
            type: "enabled",
            reasoning_effort: "max"
        }
    }' > "$PAYLOAD"
# Note: temperature/top_p/penalty params are silently ignored by DeepSeek when
# thinking mode is enabled, so we omit them. reasoning_effort: "max" enables
# Think Max (deepest CoT, same per-token price as Non-think / Think High).

PAYLOAD_BYTES=$(wc -c < "$PAYLOAD")
echo "→ Firing $MODEL (thinking: max) — payload ${PAYLOAD_BYTES} bytes (~$((PAYLOAD_BYTES / 4)) tokens)" >&2
# Soft guard: oversized scopes degrade scan recall (truncated attention) before they hard-fail.
if (( PAYLOAD_BYTES > 400000 )); then
    echo "WARNING: payload exceeds ~100K tokens — scan recall degrades on oversized scopes." >&2
    echo "         Split --scope into per-domain groups (see the lens's scope groups), or use" >&2
    echo "         --codebase mode, which chunks each lens's scope map automatically." >&2
fi

# --- Fire (with retry) ---
# DeepSeek's gateway intermittently resets long "thinking: max" connections
# (curl 56 "Recv failure") — NOT payload-size-driven (a 118K-token chunk can
# succeed while a 50K one resets in the same run). A single-shot curl silently
# drops a whole chunk from a multi-chunk codebase sweep. Retry transport errors
# and 429/5xx with linear backoff; connect/max timeouts bound a hung socket.
RESPONSE="$TMP/response.json"
MAX_ATTEMPTS=4
ATTEMPT=1
while :; do
    START=$(date +%s)
    CURL_RC=0
    HTTP_CODE=$(curl -sS -o "$RESPONSE" -w "%{http_code}" \
        --connect-timeout 30 --max-time 1200 \
        -X POST \
        -H "Authorization: Bearer ${DEEPSEEK_API_KEY}" \
        -H "Content-Type: application/json" \
        -d @"$PAYLOAD" \
        https://api.deepseek.com/v1/chat/completions) || CURL_RC=$?
    ELAPSED=$(( $(date +%s) - START ))

    [[ "$CURL_RC" -eq 0 && "$HTTP_CODE" == "200" ]] && break

    # Retryable: any curl transport failure, or HTTP 429/5xx. A 4xx other than
    # 429 is a real client error (bad key, bad payload) — fail fast, no retry.
    RETRYABLE=false
    if [[ "$CURL_RC" -ne 0 || "$HTTP_CODE" == "429" || "$HTTP_CODE" == 5* ]]; then
        RETRYABLE=true
    fi

    if ! $RETRYABLE || (( ATTEMPT >= MAX_ATTEMPTS )); then
        if [[ "$CURL_RC" -ne 0 ]]; then
            echo "DeepSeek scan curl failed (exit $CURL_RC) after ${ATTEMPT} attempt(s)" >&2
        else
            echo "DeepSeek API returned HTTP $HTTP_CODE after ${ATTEMPT} attempt(s)" >&2
            jq '.' "$RESPONSE" >&2 2>/dev/null || cat "$RESPONSE" >&2
        fi
        exit 1
    fi

    BACKOFF=$(( ATTEMPT * 15 ))
    echo "  ⚠ transient scan failure (curl ${CURL_RC} / HTTP ${HTTP_CODE:-000}) — retry $((ATTEMPT + 1))/${MAX_ATTEMPTS} in ${BACKOFF}s" >&2
    sleep "$BACKOFF"
    ATTEMPT=$(( ATTEMPT + 1 ))
done

if ! jq -e '.choices[0].message.content' "$RESPONSE" >/dev/null 2>&1; then
    echo "Malformed response — no .choices[0].message.content:" >&2
    jq '.' "$RESPONSE" >&2
    exit 1
fi

# --- Extract drafts + summary ---
jq -r '.choices[0].message.content' "$RESPONSE" > "$OUT"
ITEMS=$(grep -c '^- \[ \]' "$OUT" 2>/dev/null || echo 0)

echo "" >&2
echo "✓ Wrote $OUT — $ITEMS findings, ${ELAPSED}s elapsed" >&2
echo "" >&2
echo "Token usage:" >&2
jq -r '.usage | "  prompt: \(.prompt_tokens)  output: \(.completion_tokens)  reasoning: \(.completion_tokens_details.reasoning_tokens // 0)  cache_hit: \(.prompt_cache_hit_tokens // 0)/\(.prompt_tokens)"' "$RESPONSE" >&2
echo "" >&2
echo "→ Next: adjudicate with Claude Sonnet. Feed $OUT + the same scope to Sonnet" >&2
echo "  asking it to verify findings, refine tiers, fix wrong fix recommendations," >&2
echo "  and emit audit-\$(date +%F)-<lens>.md in the canonical format." >&2
