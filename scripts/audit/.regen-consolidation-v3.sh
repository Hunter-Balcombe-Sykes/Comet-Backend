#!/usr/bin/env bash
# .regen-consolidation-v3.sh — regenerate ONLY the Phase 3 consolidation.
# The first run's `--output-format text` dropped the head of a multi-turn
# response. This re-runs the identical prompt but captures EVERY assistant turn
# via stream-json and concatenates them, so the full document survives.
set -euo pipefail
SD="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$SD/../.." && pwd)"; cd "$ROOT"
PHASE="foundation-audit-v3"; OUT_DIR="audits/${PHASE}"; DEPS_DIR="${OUT_DIR}/.deps"
DATE="2026-05-31"
OUT="${OUT_DIR}/audit-${DATE}-CONSOLIDATED.md"
RAW="${OUT_DIR}/.consolidation.stream.jsonl"

# back up the partial run-1 output for reference
[[ -f "$OUT" ]] && cp "$OUT" "${OUT}.run1-partial.bak"

CONS_SYS_PROMPT="$(mktemp)"; CONS_PROMPT="$(mktemp)"
trap 'rm -f "$CONS_SYS_PROMPT" "$CONS_PROMPT"' EXIT

# ── System prompt: verbatim v3 consolidator (same as driver) ──
cat > "$CONS_SYS_PROMPT" <<'SYSEOF'
You are the v3 consolidator for the Partna foundation audit. Your job:

1. Read the 26 lens audits + composer audit + composer outdated outputs (all inlined below).
2. Cross-reference every finding against the inlined lens-audit Evidence blocks. Each lens
   file already includes verbatim source excerpts captured during Phase 1 adjudication, so
   you do NOT have filesystem tools — verify each finding names a real file/symbol/line
   using the inlined evidence. DROP any finding you cannot corroborate from the inlined
   material (precision > recall).
3. Produce ONE consolidated markdown document matching the structure described below,
   section-by-section.

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

# ── User message: identical assembly to the driver's Phase 3 ──
{
    echo "# v3 Consolidation Task"; echo
    echo "**Date:** $DATE"
    echo "**Branch:** $(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"; echo
    echo "## Recent commits (use to validate fixes since v1/v2)"; echo
    echo '```'; git log --oneline -30 2>/dev/null || echo "(git unavailable)"; echo '```'; echo
    echo "## Structural template (compact) — match section ordering / bundle format / conventions exactly"
    echo "(The full v1 CONSOLIDATED is not inlined to conserve context. The two load-bearing"
    echo " pieces from it are below: the Model-selection section to copy VERBATIM, and one"
    echo " example Bundle block showing the required bundle + Session-prompt formatting.)"; echo
    echo "### Copy this Model-selection section verbatim into your output:"; echo
    cat /tmp/v1-model-selection.md 2>/dev/null || echo "(model-selection sample missing)"
    echo; echo "### Example bundle block — replicate this exact shape for every bundle:"; echo
    cat /tmp/v1-sample-bundle.md 2>/dev/null || echo "(bundle sample missing)"
    echo; echo "---"; echo
    PRODUCED=$(ls "$OUT_DIR"/audit-${DATE}-*.md 2>/dev/null | grep -v CONSOLIDATED | wc -l | tr -d ' ')
    echo "## v3 per-lens audits ($PRODUCED files)"; echo
    for f in "$OUT_DIR"/audit-${DATE}-*.md; do
        [[ -f "$f" ]] || continue
        [[ "$f" == *CONSOLIDATED* ]] && continue
        echo "<!-- ═══ LENS FILE: $(basename "$f") ═══ -->"; echo
        cat "$f"; echo; echo "---"; echo
    done
    echo; echo "## composer audit (security CVEs)"; echo
    echo '```'; cat "$DEPS_DIR/composer-audit.txt"; echo '```'; echo
    echo "## composer outdated --direct (dependency drift)"; echo
    echo '```'; cat "$DEPS_DIR/composer-outdated.txt"; echo '```'; echo
    echo "---"; echo
    echo "Now produce the v3 CONSOLIDATED audit per the system prompt format rules."
    echo "Output the markdown only — no preamble, no code-fence wrapping the whole output, start at the first '#'."
} > "$CONS_PROMPT"

echo "→ prompt: $(wc -c < "$CONS_PROMPT") bytes" >&2
echo "→ firing claude (stream-json, all turns captured)…" >&2

# Raise output ceiling to cut down on turn-splitting; capture ALL turns via stream-json.
CLAUDE_CODE_MAX_OUTPUT_TOKENS=64000 claude -p \
    --model sonnet \
    --system-prompt "$(<"$CONS_SYS_PROMPT")" \
    --disallowed-tools "Read Grep Glob LS Bash Edit Write NotebookEdit WebFetch WebSearch Skill Agent TaskCreate TaskUpdate TaskGet TaskList TaskOutput TaskStop" \
    --max-budget-usd 8.00 \
    --output-format stream-json --verbose \
    --no-session-persistence \
    < "$CONS_PROMPT" > "$RAW"

# Concatenate every assistant text block, in stream order (survives multi-turn).
jq -rj 'select(.type=="assistant") | .message.content[]? | select(.type=="text") | .text' "$RAW" > "$OUT"

echo "" >&2
echo "✓ assistant turns: $(jq -r 'select(.type=="assistant") | .message.id' "$RAW" | sort -u | wc -l | tr -d ' ')" >&2
echo "✓ CONSOLIDATED: $(wc -c < "$OUT") bytes, $(wc -l < "$OUT") lines" >&2
echo "✓ first line: $(head -1 "$OUT")" >&2
echo "✓ items: $(grep -c '^- \[ \]' "$OUT") · bundles: $(grep -cE '^### Bundle B[0-9]+' "$OUT")" >&2
