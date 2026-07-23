#!/usr/bin/env bash
# contract-inventory.sh — deterministic backend↔frontend contract pre-pass (NO LLM).
#
# Emits audits/cross-repo/CONTRACT-INVENTORY.md, the first --scope the cross-repo
# lenses read. It does the mechanical, non-judgemental half of the cross-repo diff:
#   - enumerates the backend's real route surface (php artisan route:list --json),
#     feature flags, capability keys, and staff integration.*/feature.* rules;
#   - enumerates the frontend's app/api BFF routes, literal /api/... call sites,
#     proxy.ts RESERVED_PATHS, and app/**/page.tsx route surface;
#   - normalizes BOTH sides ({param} and ${…}/[dynamic] → {}) and diffs into
#     MATCHED / FRONTEND-ONLY / UNRESOLVED buckets.
#
# It deliberately NEVER writes the BACKEND-ONLY bucket. A backend route with no
# STATIC frontend caller may still be reached via a path the frontend builds
# dynamically, so "dead backend route" is a claim only the LLM adjudicator may
# make — after grepping BOTH repos. The pre-pass hands it candidates in UNRESOLVED.
# That asymmetry is the campaign's whole false-positive defense.
#
# The frontend is read STRICTLY read-only. This script never fetches, pulls, or
# checks out the frontend — it inspects only the working tree it is given.
#
# Usage:
#   scripts/audit/contract-inventory.sh [--frontend PATH] [--allow-dirty] [--out FILE]
#
# Preconditions:
#   - Frontend on `main`, clean tree (else refuses). --allow-dirty overrides and
#     stamps a loud staleness caveat into the header (for running against a live,
#     actively-edited frontend checkout).
#   - PARTNA_FRONTEND_PATH set, or ../partna-frontend resolves.

set -euo pipefail

BACKEND_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
FRONTEND_PATH="${PARTNA_FRONTEND_PATH:-$BACKEND_ROOT/../partna-frontend}"
ALLOW_DIRTY=false
OUT="$BACKEND_ROOT/audits/cross-repo/CONTRACT-INVENTORY.md"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --frontend)    FRONTEND_PATH="$2"; shift 2 ;;
        --allow-dirty) ALLOW_DIRTY=true; shift ;;
        --out)         OUT="$2"; shift 2 ;;
        -h|--help)     sed -n '2,30p' "$0" | sed 's/^# \?//'; exit 0 ;;
        *) echo "Unknown arg: $1" >&2; exit 2 ;;
    esac
done

command -v jq  >/dev/null || { echo "jq required (brew install jq)" >&2; exit 2; }
command -v php >/dev/null || { echo "php required" >&2; exit 2; }
[[ -d "$FRONTEND_PATH" ]] || { echo "Frontend path not found: $FRONTEND_PATH (set PARTNA_FRONTEND_PATH)" >&2; exit 2; }
FRONTEND_PATH="$(cd "$FRONTEND_PATH" && pwd)"

# --- Frontend git state (read-only; never fetch/pull/checkout) ---------------
FE_BRANCH="$(git -C "$FRONTEND_PATH" rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
FE_SHA="$(git -C "$FRONTEND_PATH" rev-parse --short HEAD 2>/dev/null || echo unknown)"
FE_DIRTY=false
[[ -z "$(git -C "$FRONTEND_PATH" status --porcelain 2>/dev/null || true)" ]] || FE_DIRTY=true

DIRTY_CAVEAT=""
if [[ "$FE_BRANCH" != "main" || "$FE_DIRTY" == true ]]; then
    if ! $ALLOW_DIRTY; then
        {
          echo "REFUSING: contract inventory needs the frontend on 'main' with a clean tree."
          echo "  branch: $FE_BRANCH   dirty: $FE_DIRTY   ($FRONTEND_PATH)"
          echo "  This session must NOT check out the frontend. Ask the human to park it on a"
          echo "  clean main, OR re-run with --allow-dirty to inventory the live tree anyway"
          echo "  (a staleness caveat is stamped into the output)."
        } >&2
        exit 3
    fi
    _uncommitted=""
    [[ "$FE_DIRTY" == true ]] && _uncommitted=" **with UNCOMMITTED changes**"
    DIRTY_CAVEAT="⚠ STALE / NON-CANONICAL FRONTEND — generated against \`$FE_BRANCH\` @ \`$FE_SHA\`${_uncommitted}, not a clean \`main\`. Every absence claim below is provisional; the adjudicator MUST re-grep both repos before promoting anything to a finding."
fi

mkdir -p "$(dirname "$OUT")"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

# ============================ BACKEND ======================================
# Normalized backend API routes -> METHOD \t api/normalized/uri \t name \t middleware
( cd "$BACKEND_ROOT" && php artisan route:list --json 2>/dev/null ) > "$TMP/routes.json" || echo '[]' > "$TMP/routes.json"
jq -e 'type=="array"' "$TMP/routes.json" >/dev/null 2>&1 || echo '[]' > "$TMP/routes.json"

jq -r '.[] | [(.method // ""), (.uri // ""), (.name // ""),
              ((.middleware // "") | if type=="array" then join(",") else tostring end)] | @tsv' "$TMP/routes.json" \
  | awk -F'\t' '$2 ~ /^api\// { u=$2; gsub(/\{[^}]*\}/,"{}",u); sub(/\/+$/,"",u); print $1"\t"u"\t"$3"\t"$4 }' \
  | sort -u > "$TMP/be_routes.tsv"
cut -f2 "$TMP/be_routes.tsv" | sort -u > "$TMP/be_uris.txt"

# Feature flags (config/partna.php) — best-effort: env()-backed + 'enabled' keys.
( grep -nE "=> *\(?\(bool\)? *env\(|'enabled' *=>|_ENABLED" "$BACKEND_ROOT/config/partna.php" 2>/dev/null || true ) > "$TMP/be_flags.txt"

# Capability keys (AccountCapabilities + AccountCapabilitySet) — best-effort.
( grep -rhoE "(can|is|has|may)_[a-z0-9_]+" "$BACKEND_ROOT/app/Services/Accounts/" 2>/dev/null || true ) | sort -u > "$TMP/be_caps.txt"

# Staff availability rules: integration.* / feature.* keys anywhere in app/ + config/.
( grep -rhoE "'(integration|feature)\.[a-z0-9_.*-]+'" "$BACKEND_ROOT/app" "$BACKEND_ROOT/config" 2>/dev/null || true ) | tr -d "'" | sort -u > "$TMP/be_avail.txt"

# ============================ FRONTEND =====================================
FE_EXCL=(--exclude-dir=node_modules --exclude-dir=.next --exclude-dir=out --exclude-dir=build --exclude-dir=.turbo)

# app/api/**/route.ts  ->  BFF route path (app/api/foo/[id]/route.ts -> api/foo/{})
: > "$TMP/fe_bff.tsv"
if [[ -d "$FRONTEND_PATH/app/api" ]]; then
    while IFS= read -r rf; do
        rel="${rf#$FRONTEND_PATH/}"
        p="${rel#app/}"; p="${p%/route.ts}"
        p="$(printf '%s' "$p" | sed -E 's#\([^/]*\)/?##g; s#\[[^]]*\]#{}#g; s#/+#/#g; s#/+$##')"
        printf '%s\t%s\n' "$p" "$rel" >> "$TMP/fe_bff.tsv"
    done < <(find "$FRONTEND_PATH/app/api" -type f -name 'route.ts' ! -path '*/node_modules/*' 2>/dev/null)
    sort -u -o "$TMP/fe_bff.tsv" "$TMP/fe_bff.tsv"
fi

# Literal /api/<surface>/... call sites. Stop the match at a quote, backtick,
# space, paren, angle bracket (JSX/HTML like `...</code>`), or list punctuation
# so a template literal `/api/x/${id}/y` is captured whole but prose tails are not.
Q="\"'\`"
CALL_RE="/api/(professional|public|staff|account|internal)/[^${Q} (),<>;]*"
( grep -rhoE --include='*.ts' --include='*.tsx' "${FE_EXCL[@]}" "$CALL_RE" "$FRONTEND_PATH" 2>/dev/null || true ) \
  | sed -E 's/[?#].*$//; s/\$\{[^}]*\}/{}/g; s/\[[^]]*\]/{}/g; s/[.,;:]+$//; s#/+$##; s#^/##' \
  | grep -E '^api/' | sort -u > "$TMP/fe_calls.txt"

# proxy.ts RESERVED_PATHS array contents.
: > "$TMP/fe_reserved.txt"
if [[ -f "$FRONTEND_PATH/proxy.ts" ]]; then
    ( awk '/RESERVED_PATHS/{f=1} f{print} f&&/\]/{c++; if(c>=1 && /\]/) exit}' "$FRONTEND_PATH/proxy.ts" 2>/dev/null || true ) \
      | grep -oE "'[^']+'|\"[^\"]+\"" 2>/dev/null | tr -d "\"'" | sort -u > "$TMP/fe_reserved.txt" || true
fi

# app/**/page.tsx route surface.
: > "$TMP/fe_pages.txt"
if [[ -d "$FRONTEND_PATH/app" ]]; then
    ( find "$FRONTEND_PATH/app" -type f -name 'page.tsx' ! -path '*/node_modules/*' 2>/dev/null || true ) \
      | sed "s#^$FRONTEND_PATH/##" \
      | sed -E 's#/page\.tsx$##; s#\([^/]*\)/?##g; s#\[[^]]*\]#{}#g; s#^app##; s#//+#/#g; s#^/##' \
      | sort -u > "$TMP/fe_pages.txt"
fi

# ======================= RECONCILE =========================================
comm -12 "$TMP/fe_calls.txt" "$TMP/be_uris.txt" > "$TMP/matched.txt"    # exact both ways
comm -23 "$TMP/fe_calls.txt" "$TMP/be_uris.txt" > "$TMP/fe_only.txt"    # fe call, no backend route
comm -13 "$TMP/fe_calls.txt" "$TMP/be_uris.txt" > "$TMP/be_unref.txt"   # backend route, no fe call

# Annotate FRONTEND-ONLY: a shared first-3-segment prefix on the backend side
# usually means normalization misaligned a param segment, NOT a real 404 — flag
# those for the adjudicator instead of asserting a live 404.
awk -F/ 'NR==FNR{ be[$1"/"$2"/"$3]=1; next }
         { k=$1"/"$2"/"$3;
           if (k in be) print $0"\t⚠ backend prefix exists — likely a normalization miss, verify";
           else         print $0"\tno backend prefix — strong 404 candidate" }' \
    "$TMP/be_uris.txt" "$TMP/fe_only.txt" > "$TMP/fe_only_annot.tsv"

N_BE=$(wc -l < "$TMP/be_uris.txt" | tr -d ' ')
N_FE=$(wc -l < "$TMP/fe_calls.txt" | tr -d ' ')
N_MATCH=$(wc -l < "$TMP/matched.txt" | tr -d ' ')
N_FEONLY=$(wc -l < "$TMP/fe_only.txt" | tr -d ' ')
N_UNREF=$(wc -l < "$TMP/be_unref.txt" | tr -d ' ')
N_BFF=$(wc -l < "$TMP/fe_bff.tsv" | tr -d ' ')
BE_BRANCH="$(git -C "$BACKEND_ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
BE_SHA="$(git -C "$BACKEND_ROOT" rev-parse --short HEAD 2>/dev/null || echo unknown)"

# ======================= EMIT ==============================================
{
  echo "# Cross-repo contract inventory"
  echo ""
  if [[ -n "$DIRTY_CAVEAT" ]]; then
      echo "> $DIRTY_CAVEAT"
      echo ""
  fi
  echo "## Provenance"
  echo ""
  echo "- **Backend:** \`$BE_BRANCH\` @ \`$BE_SHA\` — \`$BACKEND_ROOT\`"
  echo "- **Frontend:** \`$FE_BRANCH\` @ \`$FE_SHA\` — \`$FRONTEND_PATH\`"
  echo "- **Generated by:** \`scripts/audit/contract-inventory.sh\` (deterministic, no LLM)"
  echo "- **Backend API routes:** $N_BE normalized · **Frontend /api call sites:** $N_FE normalized · **BFF route files:** $N_BFF"
  echo ""
  echo "## Reconciliation summary"
  echo ""
  echo "| Bucket | Count | Meaning |"
  echo "|--------|-------|---------|"
  echo "| MATCHED | $N_MATCH | frontend call ↔ backend route, exact after normalization |"
  echo "| FRONTEND-ONLY | $N_FEONLY | frontend calls it, no exact backend route — **candidate live 404 (P1)** |"
  echo "| UNRESOLVED (backend-unreferenced) | $N_UNREF | backend route, no static frontend call — candidate BACKEND-ONLY |"
  echo "| BACKEND-ONLY | 0 | **adjudicator-filled only**, after grepping BOTH repos |"
  echo ""
  echo "> **Normalization:** backend \`{param}\` and frontend \`\${…}\`/\`[dynamic]\` both fold to \`{}\` before diffing."
  echo "> The pre-pass NEVER asserts BACKEND-ONLY: a route with no *static* caller may be built dynamically."
  echo "> Treat every FRONTEND-ONLY and UNRESOLVED row as **unproven** — a finding needs a source read in both repos."
  echo ""

  echo "## FRONTEND-ONLY — candidate live 404s (verify each; XREPO category 2, P1)"
  echo ""
  if [[ -s "$TMP/fe_only_annot.tsv" ]]; then
      echo '```'
      sed 's/\t/    /' "$TMP/fe_only_annot.tsv"
      echo '```'
  else
      echo "_(none — every normalized frontend /api call matched a backend route)_"
  fi
  echo ""

  echo "## UNRESOLVED — backend routes with no static frontend caller (candidate BACKEND-ONLY)"
  echo ""
  echo "The adjudicator must grep the FRONTEND for a dynamic caller before moving any of these to"
  echo "BACKEND-ONLY. Internal/webhook/health routes are expected here and are usually not consumer-facing."
  echo ""
  if [[ -s "$TMP/be_unref.txt" ]]; then
      echo '```'
      cat "$TMP/be_unref.txt"
      echo '```'
  else
      echo "_(none)_"
  fi
  echo ""

  echo "## MATCHED (reference)"
  echo ""
  if [[ -s "$TMP/matched.txt" ]]; then
      echo '```'
      cat "$TMP/matched.txt"
      echo '```'
  else
      echo "_(none)_"
  fi
  echo ""

  echo "## Backend route surface (full, normalized)"
  echo ""
  echo '```'
  echo -e "METHOD\tURI\tNAME\tMIDDLEWARE"
  cat "$TMP/be_routes.tsv"
  echo '```'
  echo ""

  echo "## Backend feature flags (config/partna.php — best-effort)"
  echo ""
  echo '```'
  cat "$TMP/be_flags.txt"
  echo '```'
  echo ""

  echo "## Backend capability keys (app/Services/Accounts — best-effort)"
  echo ""
  echo '```'
  cat "$TMP/be_caps.txt"
  echo '```'
  echo ""

  echo "## Backend staff availability rules (integration.* / feature.*)"
  echo ""
  echo '```'
  cat "$TMP/be_avail.txt"
  echo '```'
  echo ""

  echo "## Frontend BFF routes (app/api/**/route.ts → route path : file)"
  echo ""
  if [[ -s "$TMP/fe_bff.tsv" ]]; then
      echo '```'
      sed 's/\t/    :  /' "$TMP/fe_bff.tsv"
      echo '```'
  else
      echo "_(none found)_"
  fi
  echo ""

  echo "## Frontend proxy.ts RESERVED_PATHS"
  echo ""
  if [[ -s "$TMP/fe_reserved.txt" ]]; then
      echo '```'
      cat "$TMP/fe_reserved.txt"
      echo '```'
  else
      echo "_(none found — check proxy.ts exists / array name)_"
  fi
  echo ""

  echo "## Frontend page.tsx route surface"
  echo ""
  if [[ -s "$TMP/fe_pages.txt" ]]; then
      echo '```'
      cat "$TMP/fe_pages.txt"
      echo '```'
  else
      echo "_(none found)_"
  fi
  echo ""
} > "$OUT"

echo "✓ Wrote $OUT" >&2
echo "  backend routes: $N_BE · fe calls: $N_FE · MATCHED: $N_MATCH · FRONTEND-ONLY: $N_FEONLY · UNRESOLVED: $N_UNREF" >&2
[[ -n "$DIRTY_CAVEAT" ]] && echo "  ⚠ stamped STALE (frontend $FE_BRANCH@$FE_SHA, dirty=$FE_DIRTY)" >&2
exit 0
