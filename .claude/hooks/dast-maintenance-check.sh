#!/usr/bin/env bash
# PreToolUse warn hook for scripts/dast/ maintenance.
# Reads Claude Code hook JSON on stdin, extracts file_path, and prints a warning
# to stderr when a route or migration edit is the kind that scripts/dast/
# does NOT auto-update for (see scripts/dast/README.md). Always exits 0 (warn-only).

set -u

input="$(cat)"
file_path="$(printf '%s' "$input" | /usr/bin/jq -r '.tool_input.file_path // empty' 2>/dev/null)"
[ -z "$file_path" ] && exit 0

# Only inspect files inside this repo.
case "$file_path" in
  */backend/*|*/Side\ Street/backend/*) : ;;
  *) exit 0 ;;
esac

warn() { printf '\n[dast-maintenance] WARN: %s\n  file: %s\n' "$1" "$file_path" >&2; }

# Route surface changes: a new route reaching an external API, or a rename of
# an existing route, can silently invalidate zap-context.yaml's excludePaths
# or one of the 5 custom Nuclei templates (neither is auto-updated).
case "$file_path" in
  */routes/api.php|*/routes/api/*.php)
    warn "Route surface changed. If this adds a route that calls an external API, or renames/moves a route a custom Nuclei template depends on, check scripts/dast/README.md \"What updates automatically vs what you maintain by hand\" — zap-context.yaml excludePaths and scripts/dast/edge/templates/*.yaml are manual."
  ;;
esac

# New migrations touching tables scripts/dast/active/seed-identities.php seeds
# directly (core.users -> site.sites -> site.site_media -> site.customers ->
# site.enquiries) can break that script's schema assumptions silently.
case "$file_path" in
  */supabase/migrations/*.sql)
    new_content="$(printf '%s' "$input" | /usr/bin/jq -r '.tool_input.content // .tool_input.new_string // empty' 2>/dev/null)"
    if printf '%s' "$new_content" | grep -qE 'core\.users|site\.sites|site\.site_media|site\.customers|site\.enquiries'; then
      warn "Migration touches a table scripts/dast/active/seed-identities.php seeds directly. Check its schema assumptions still hold (scripts/dast/README.md)."
    fi
  ;;
esac

exit 0
