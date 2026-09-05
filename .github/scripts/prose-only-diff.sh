#!/usr/bin/env bash
#
# Decides whether the change under test touches ONLY prose, and writes
# `prose_only=true|false` to $GITHUB_OUTPUT for the jobs in ci.yml to gate on.
#
# WHY THIS EXISTS: a CLAUDE.md typo used to cost ~20 minutes of `test` before it
# could merge, because every required check runs unconditionally. It still runs
# unconditionally — that part is deliberate and must stay (see ci.yml: a
# path-FILTERED required check does not run at all when the filter misses, and a
# never-reported check either blocks the merge forever or renders green while
# guarding nothing). What this script changes is only how much WORK a job does
# once it is already running. Every check still reports its own real conclusion.
#
# THE ALLOWLIST IS DELIBERATELY TINY AND FAILS CLOSED. Prose means:
#   - docs/**            (read by no test — verified 2026-09-05)
#   - *.md in the repo root  (CLAUDE.md, AI_CONTEXT.md, README.md, ...)
# Everything else is code, including things that look like prose:
#   - audits/**          AuditPipelineIntegrityTest reads base_path('audits')
#   - scripts/audit/**/*.md  the same test globs lenses/*.md and greps the prompts
#   - .github/**         a workflow edit must be tested by the workflow it edits
# When in doubt the answer is "code". A wrong `false` costs 20 minutes; a wrong
# `true` merges untested code behind a green check.
#
# NOT `set -e`: every failure path must reach a fail-closed verdict, not abort
# the step with no output and leave the dependent jobs reading an empty string.
set -uo pipefail

verdict() {
    printf 'prose_only=%s\n' "$1" >>"${GITHUB_OUTPUT:-/dev/stdout}"
    printf '::notice::prose_only=%s — %s\n' "$1" "$2"
    exit 0
}

NULL_SHA=0000000000000000000000000000000000000000

case "${GITHUB_EVENT_NAME:-}" in
    pull_request)
        base="origin/${GITHUB_BASE_REF:?}"
        git rev-parse --verify -q "${base}" >/dev/null \
            || verdict false "base ref ${base} is not present in this checkout"
        # Three-dot: what THIS branch changed since the merge base, not every
        # commit that has landed on the base since it forked.
        # --no-renames so a code->docs rename reports BOTH paths; with rename
        # detection only the new (prose-looking) path would appear.
        files=$(git diff --name-only --no-renames "${base}...HEAD") \
            || verdict false "git diff against ${base} failed"
        ;;
    push)
        before="${EVENT_BEFORE:-}"
        [ -n "${before}" ] && [ "${before}" != "${NULL_SHA}" ] \
            || verdict false "no usable before-sha (new branch, or first push)"
        git cat-file -e "${before}^{commit}" 2>/dev/null \
            || verdict false "before-sha ${before} is not in this history (force push?)"
        files=$(git diff --name-only --no-renames "${before}" "${GITHUB_SHA:?}") \
            || verdict false "git diff ${before}..${GITHUB_SHA} failed"
        ;;
    *)
        verdict false "unhandled event '${GITHUB_EVENT_NAME:-}' — not proving anything about it"
        ;;
esac

# An empty list is not proof of prose. It means the diff could not be computed
# (or the push was empty), and "I found no code" must never read as "there is none".
[ -n "${files}" ] || verdict false "empty file list — cannot prove the change is prose"

count=0
while IFS= read -r file; do
    [ -n "${file}" ] || continue
    count=$((count + 1))
    case "${file}" in
        docs/*) ;;          # prose
        */*) verdict false "code path: ${file}" ;;   # any OTHER nested path
        *.md) ;;            # root-level markdown only
        *) verdict false "code path: ${file}" ;;
    esac
done <<<"${files}"

verdict true "${count} file(s), all under docs/ or root-level *.md"
