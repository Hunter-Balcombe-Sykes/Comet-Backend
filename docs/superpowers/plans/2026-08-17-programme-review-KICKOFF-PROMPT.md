# Programme review — kickoff prompt (2026-08-17)

Prompt 7b of `2026-08-14-convergence-session-prompts.md`, expanded into its own
kickoff because the version in that file predates the last three projects.

**What this session is.** The whole-programme verification gate for the Content
Pool Convergence programme. It runs after all implementation, before
`phase-8-review-and-docs`, and the latter is blocked on its verdict.

**Why it exists.** The programme ran one session per slice, with context passing
through checkpoints rather than chat history. That kept each session small enough
to be accurate — but every claim in the parent spec was written by the session
with the most reason to believe it. This is the only step where someone
re-derives those claims cold. Slice 7 proved the need empirically: a coverage
gate read 318/318 on 2026-08-16 and had a 23-row hole by 2026-08-17, concealed by
matching totals.

**What changed after prompt 7b was written (2026-08-16).** Slice 7 shipped at
reduced scope — five tables of nine — and the remaining four were retired by two
follow-on projects with their own specs, plans and gates: the shop re-home and
the services cutover. Both are now complete on dev. The prompt below folds them
in, along with the five holes those projects recorded against themselves.

Paste everything between the fences into a fresh session.

---

```
Rename this session to programme-review.

You are the whole-programme verification gate for the Content Pool Convergence
programme (parent spec: docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md).
You run AFTER all implementation, BEFORE phase-8-review-and-docs, which is queued in
another session and blocked on your verdict. Dev only; production is out of scope
programme-wide. You write no feature code: you verify, audit and file findings. Fixes
follow scripts/audit/fix-flow.md in their own sessions — the only in-place fixes
permitted here are CLAUDE.md's opportunistic-P3 rule.

SETUP. Start from origin/development at 93d323cb0 (or later — fetch first). Work in your
OWN git worktree: `git worktree add`, then `cp -a <main>/vendor ./vendor` (a SYMLINKED
vendor makes Pest's ->in('Feature') binding fail and produces ~1100 fake failures) and
symlink .env. The main checkout is on feat/platform-brand-routes doing unrelated
platform-uniformity work — do not switch its branch, and do not `git reset` in a shared
index; a peer has already silently unstaged another session's work that way.

SCOPE BOUNDING. `development` carries non-programme work (7df4214a5 link-card-media,
f3bf8614e, and the platforms-uniformity branch queued behind it). The programme is the
convergence slices AND the three projects that finished it: slice 7 phase 6, the shop
re-home, the services cutover. Bound the audit and code-review diffs by path and topic,
not by "everything since slice 0", or the sweep drowns in unrelated changes.

WHAT CHANGED SINCE THIS PROMPT WAS WRITTEN (2026-08-16) — verify, do not trust:
  * Slice 7 shipped at REDUCED scope — five tables of nine (menu_item_categories,
    menu_item_platforms, menu_items, menu_categories, content_selection), merged
    cef89ec5f. Its checkpoint is in docs/superpowers/plans/2026-08-17-slice-7-phase-6-checkpoint.md,
    NOT in the parent spec. Verify it as a checkpoint anyway; that it is misfiled is
    phase 8's to fix, not yours.
  * It accepted a 23-row data loss on `ollies` (10 dishes, 2 categories, 11 memberships)
    by owner ruling. Confirm the loss is exactly what was recorded and no larger.
  * The remaining four tables were retired by two follow-on PROJECTS, each with its own
    spec, plan and gates: the shop re-home (shop_brands, shop_products —
    specs/2026-08-17-shop-brands-rehome-design.md, plans/2026-08-17-shop-brands-rehome.md)
    and the services cutover (services, service_categories, service_category_assignments —
    specs/2026-08-17-services-cutover-design.md, plans/2026-08-17-services-cutover.md).
    Both plans are fully ticked. Re-verify their gate evidence yourself — backup-gate
    counts, live reads, deploy verification — exactly as you would a slice checkpoint.
  * BOTH wrote a checkpoint numbered "## 27" into the parent spec. There are two §27s.
    That is a real defect — record it, leave it for phase 8.
  * ServiceBackfiller and BackfillOwnerServices were retired in bb9f103eb, so the parent
    spec's §28.3 residual 4 ("survive as the project's single code residual") is already
    stale. Expect more of this: prefer to_regclass, live SQL and grep over any list.

1. CHECKPOINT RE-VERIFICATION (rule zero — trust nothing, cite nothing). For every
   checkpoint in the parent spec — §12–§19, slice 6's inline §7, and everything §20+
   including slices 4/4b, custom-links, phases 4/6, both §27s — plus the misfiled slice 7
   phase 6 checkpoint, re-run the checkpoint's live SQL on dev (glncumufgaqcmqhzwrxm) and
   confirm each claim still holds. An assertion that no longer holds REOPENS its owning
   slice: stop and raise. Do not patch it here and do not tick anything.

   Method, learned the hard way by slice 7: never gate on totals. A count that matches
   can still conceal a hole — 2026-08-16 read 318/318 and 2026-08-17 read 283/293 with
   rows uncovered while the net total FELL. Derive per row (by coord), not by COUNT(*).
   And a coverage gate on a live environment is valid only until the next scrape; say
   when each reading was taken.

1b. THE FIVE KNOWN HOLES the last three projects recorded against themselves. A generic
   checkpoint re-read will pass straight over these; test each one directly:
   a. The services cutover's AUTHENTICATED verbs were never exercised live — no owner JWT
      was available, so edit / resync / hide / delete / restore / reorder are covered by
      test and DB reads only, and the DROPs went in knowingly on that basis. This is the
      programme's largest unproven surface. Exercise them, or state plainly that they
      remain unproven and why.
   b. `site.section_items` held 0 Fresha rows at drop time because only a live reorder
      writes them — so the first real reorder has a one-time tail-of-list effect. Confirm
      the current row count and whether the effect is still pending.
   c. The unexplained services count drift (§28.3 residual 3: 82/18/61 on one reading,
      79/16/61 at drop time, no migration between). Something outside this programme
      writes dev. Identify the writer or record it as an unresolved unknown.
   d. `content.storefronts.user_id` NOT NULL is a cross-lane hazard: MenuFetchJob writes
      order-platform store cards there and was not setting user_id (shop re-home §29.5).
      The fix needs a LIVE proof — a NEW order-platform storefront created by a menu
      scrape, not a passing test.
   e. `site.shop_brands` carried three CHECK constraints the DROP would have taken
      silently. Confirm each is re-expressed on content.*.

2. LEGACY-ZERO SWEEP. Grep app/, routes/, config/, tests/ for any surviving reader of
   every retired store and lane: site.menu_items and the three other menu tables,
   site.services + service_categories + service_category_assignments, site.shop_brands,
   site.shop_products, site.content_selection, site.themes, settings.design.*,
   profile_fields, the four retired review wire keys, designMedia/gallery/siteImages, the
   demoted connectors (twitch/skool/strava/gumroad/substack), the article and channel
   kinds. Confirm the drops with to_regclass rather than by reading any list. A green
   suite is not evidence — read the grep hits. Note that a grep is BLIND to Eloquent
   model access, so check the models and their relations explicitly, not just table names.

   Then the inverse (invariant #2): every kind in KindRegistry must have a live writer, a
   pool (or a recorded exemption, like document), and a wire read path.

3. ALL TEST LANES, LOCALLY — CI is not your test runner, and composer test alone proves
   little here: composer test (serial, on purpose); ./vendor/bin/pest --parallel
   --processes=4 (paratest takes at most ONE path argument); composer test:pg (tests/Postgres/,
   throwaway postgres:16 container — ProjectionWriter changed repeatedly this programme);
   composer test:schema (applied-schema lane, pins architecture constraints composer test
   never sees — and a PROVEN blind spot: 55e1db8e7 shows it still asserted dropped
   constraints after the drops merged, so read its output, do not just check the exit
   code); and the authz lane WITH Postgres up ("31 skipped" reads green but tests
   nothing). Also php -d memory_limit=1G ./vendor/bin/phpstan analyse app --no-progress
   (the default invocation OOMs and misreports it), and Pint.

4. AUDIT PIPELINE — never hand-write findings: scripts/audit/audit.sh --bundle pre-merge
   --changed-since <the commit immediately before slice 0 merged; derive it from the parent
   spec §13 baseline>, bounded per SCOPE BOUNDING above. If that delta exceeds ~100K
   tokens, split into targeted runs per scripts/audit/campaigns.md (pools/resolver,
   ingest/projection, migration commands, wire resources, the 301 slug lane) — narrow runs
   find MORE than sweeps. Never run two audit.sh at once.

5. CODE + SECURITY REVIEW: /code-review high over the programme's diff for correctness;
   /security-review over the new public surface (pools wire, custom_links, source_stats,
   content.item_slugs 301 lane, the storefront/order-platform cards). Verify the
   paid-connector guardrails specifically: every CostClass::Actor source is
   auto_sync=false, and nothing re-enables scheduling on a paid surface.

6. LIVE SURFACE: cloud env:logs partna development --minutes 30; Nightwatch scan for
   anything first-seen since the programme's first merge; spot-check the public wire for
   2–3 real handles (ra33rty, ollies, anseo-studio) — pools present and populated, legacy
   keys absent, migrated menu slugs 301-ing, pools.reviews.stats serving. Note anseo-studio
   was last recorded 410 Gone; confirm rather than assume.

7. WIRE MANIFESTS vs REALITY: verify all 14 manifests in docs/wire-changes/ against actual
   wire output — including the two newest, 2026-08-17-services-cutover.md (which records
   the ruling-1 legacy-id break: verbs that now 404 or 422) and
   2026-08-17-shop-brands-rehome.md. These are the frontend rebuild's input — a manifest
   that misdescribes the wire is a P1 finding, not a docs nit.

8. KNOWN OPEN ITEMS — confirm each is still recorded, not silently lost: 4b/Phase 5's
   menu-actor proof if still F30-blocked (Apify payment — re-probe before escalating, the
   402 was transient platform middleware, not a dead card); LEGAL-2; the RLS
   accepted-posture revisit; Google aggregates cadence; prod reconciliation deferred (prod
   still carries EVERY table this programme dropped — derive that list with to_regclass
   against both refs rather than counting from a doc); the two slice-4 product questions;
   the R2 leg of the services-cutover backup gate, deferred with the dump on one laptop;
   anseo-studio's unprovisionable book-now URL and the no-selection dashboard prompt.

DELIVERABLE: the audit output folder(s) with CONSOLIDATED.md, plus a review record in the
parent spec ending in ONE of: PASS — phase-8-review-and-docs may run; or BLOCKED — a named
list of findings, each assigned to its owning slice/session/project. Do not archive, do not
tick other slices' boxes, do not fix what you find (file it). When done, SendMessage the
phase-8-review-and-docs session with the verdict and the merge SHA, so it is not waiting on
a gate that has already resolved.

Autonomy: verification, audits and filed findings without sign-off. Any reopened
checkpoint, any auth/money finding, any P0: STOP for the owner.
```
