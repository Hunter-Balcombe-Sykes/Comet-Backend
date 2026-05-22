---
name: partna-handoff-status
description: Use after completing a meaningful unit of work on the standalone-pages plan (PR merged, migration deployed, theme package published, Worker branch deployed, gate G1-G5 reached, blocker hit) - updates the shared ~/Developer/IMPLEMENTATION-STATUS.md so the other developer's Claude session sees the current state on next invocation. Manual trigger; user invokes when they want to log progress.
---

# Implementation Status Handoff

Update `~/Developer/IMPLEMENTATION-STATUS.md` so the other track's Claude session knows what just landed. Two developers work in parallel on Track A (backend, Comet-Backend) and Track B (frontend / themes / Astro / Worker / Cloudflare). The shared status file is the canonical coordination point — neither developer needs to read the other's diff to know what's done.

## When to use

Invoke after:
- Merging a PR for plan-related work
- Deploying a migration to dev or staging
- Publishing a new version of `@partna/themes` to GitHub Packages
- Deploying Worker changes (staging or production)
- Reaching a gate defined in the plan (G0–G5 in §47)
- Hitting a blocker that affects the OTHER track (e.g. waiting on `account_type` column being in dev before frontend can read it)
- Completing any verifiable artifact someone in the other track might want to know about

Do NOT invoke for:
- Work-in-progress thoughts (only completed artifacts)
- Internal track-only progress that doesn't affect the other track

## What to do

1. Read the existing `~/Developer/IMPLEMENTATION-STATUS.md`. If it doesn't exist yet, create it using the template below (Phase 1 kickoff is the natural moment to create).
2. Identify which track you're on (A = backend, B = frontend/themes/astro/worker/cloudflare).
3. Update the corresponding section:
   - **Current phase** — which of Phases 1–5 (G0→G1→...→G5) you're in
   - **Last completed** — most recent verifiable artifact with PR / commit / deploy reference
   - **In progress** — what you're actively working on
   - **Blocked on** — anything you're waiting for from the other track (or external)
   - **Notes for other track** — anything they should know (e.g. "endpoint shape locked at X", "type Y added to ProfessionalDashboardResource")
4. Bump the "Updated:" timestamp.
5. Commit with message `status: <track> completes <unit>` — no Co-Authored-By footer (this is workflow, not code).
6. Push the commit so the other track sees it immediately.

## Template (if file doesn't exist)

```markdown
# Implementation Status — Standalone Individual Sitepages

Updated: <ISO timestamp> by <track>

## Track A (Backend — Comet-Backend)

### Current phase
(e.g. Phase 1, Phase 2)

### Last completed
- (list completed artifacts with PR/commit/deploy refs)

### In progress
- (current work)

### Blocked on
- (or "Nothing currently")

### Notes for Track B
- (anything Track B should know)

## Track B (Frontend / Themes / Astro / Worker / Cloudflare)

### Current phase

### Last completed

### In progress

### Blocked on

### Notes for Track A
```

## Output

Brief confirmation: "Status updated. Track [A/B] now shows: [one-line summary]. Pushed as commit [hash]."

## Reference

Plan at `~/Developer/PARTNA-STANDALONE-PAGES-NEW-DIRECTION.md`. §44 (track ownership), §47 (phases with gates G0–G5), §48 (communication protocol — this file is the primary mechanism, alongside GitHub PR cross-reviews).
