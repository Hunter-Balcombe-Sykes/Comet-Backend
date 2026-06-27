#!/usr/bin/env bash
# archive-done.sh — move completed audits into audits/archive/ automatically.
#
# An audit is "done" when its tracked markdown has at least one checkbox and
# ZERO unchecked `- [ ]` boxes left (every finding is `- [x]`). Done folders
# are moved to audits/archive/<same relative path>, preserving git history.
#
# Usage:
#   scripts/audit/archive-done.sh                 # scan all of audits/, archive every done run
#   scripts/audit/archive-done.sh <path>          # archive just this folder/file if it's done
#   scripts/audit/archive-done.sh --dry-run [path]# report what WOULD move, change nothing
#
# The `execute audit` flow calls this at the end of every run, so finished
# audits archive themselves — no need to ask "are all the boxes ticked?".

set -euo pipefail
cd "$(git rev-parse --show-toplevel 2>/dev/null || echo .)"

AUDITS="audits"
ARCHIVE="$AUDITS/archive"
DRY_RUN=false
TARGET=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run) DRY_RUN=true; shift ;;
        -h|--help) sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) TARGET="$1"; shift ;;
    esac
done

[[ -d "$AUDITS" ]] || { echo "no audits/ directory here" >&2; exit 1; }

# unchecked = count of "- [ ]" finding lines; checked = "- [x]"/"- [X]".
# A file is done iff checked>0 AND unchecked==0.
file_is_done() {
    local f="$1" unchecked checked
    unchecked=$(grep -cE '^[[:space:]]*- \[ \]' "$f" 2>/dev/null || true)
    checked=$(grep -cE '^[[:space:]]*- \[[xX]\]' "$f" 2>/dev/null || true)
    [[ "$checked" -gt 0 && "$unchecked" -eq 0 ]]
}

# A run folder is done iff its CONSOLIDATED.md is done (the canonical tracked
# file). If there's no CONSOLIDATED.md, fall back to: every *.md must be done.
folder_is_done() {
    local d="$1"
    if [[ -f "$d/CONSOLIDATED.md" ]]; then
        file_is_done "$d/CONSOLIDATED.md"
        return
    fi
    local any=false md
    while IFS= read -r md; do
        any=true
        file_is_done "$md" || return 1
    done < <(find "$d" -maxdepth 1 -name '*.md' -type f)
    $any  # done only if there was at least one md and all passed
}

move_to_archive() {
    local src="$1" dest
    # Preserve the path under audits/ inside archive/ (e.g.
    # audits/security/2026-06-23-x → audits/archive/security/2026-06-23-x).
    local rel="${src#"$AUDITS"/}"
    dest="$ARCHIVE/$rel"
    if $DRY_RUN; then
        echo "  [dry-run] would archive: $src → $dest"
        return
    fi
    mkdir -p "$(dirname "$dest")"
    if git ls-files --error-unmatch "$src" >/dev/null 2>&1 || git ls-files "$src" 2>/dev/null | grep -q .; then
        git mv "$src" "$dest"
    else
        mv "$src" "$dest"
    fi
    echo "  ✓ archived: $rel"
}

archive_one() {
    local p="$1"
    case "$p" in "$ARCHIVE"/*|"$ARCHIVE") return 0 ;; esac   # never re-archive
    if [[ -d "$p" ]]; then
        folder_is_done "$p" && move_to_archive "$p" || true
    elif [[ -f "$p" ]]; then
        file_is_done "$p" && move_to_archive "$p" || true
    fi
}

MOVED_BEFORE=$(find "$ARCHIVE" -maxdepth 3 -type d 2>/dev/null | wc -l | tr -d ' ')

if [[ -n "$TARGET" ]]; then
    # Explicit target: if a CONSOLIDATED.md/file was named, act on its folder.
    [[ -f "$TARGET" && "$(basename "$TARGET")" == "CONSOLIDATED.md" ]] && TARGET="$(dirname "$TARGET")"
    if [[ -d "$TARGET" ]] && ! folder_is_done "$TARGET"; then
        echo "Not archived — open checkboxes remain in: $TARGET" >&2
        exit 0
    fi
    archive_one "$TARGET"
else
    # Scan every run folder: audits/<category>/<date>-<name> and audits/sweeps/<...>.
    while IFS= read -r d; do archive_one "$d"; done < <(
        find "$AUDITS" -mindepth 2 -maxdepth 2 -type d ! -path "$ARCHIVE/*"
    )
    # Loose legacy files directly under audits/.
    while IFS= read -r f; do archive_one "$f"; done < <(
        find "$AUDITS" -maxdepth 1 -name '*.md' -type f
    )
fi

MOVED_AFTER=$(find "$ARCHIVE" -maxdepth 3 -type d 2>/dev/null | wc -l | tr -d ' ')
if [[ "$MOVED_AFTER" == "$MOVED_BEFORE" ]] && ! $DRY_RUN && [[ -z "$TARGET" ]]; then
    echo "Nothing to archive — no fully-completed audits found."
fi
