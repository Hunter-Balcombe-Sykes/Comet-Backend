# Wave-2 Implementation-Plan Authoring Prompt (foundational audit)

**Purpose:** one prompt that authors a *single consolidated implementation-plan document* covering
every Wave-2 finding of the foundational audit, then has it independently reviewed against the real
codebase. It produces a finalized, ready-to-execute plan file — it does **NOT** write feature code,
run migrations, or push anything.

**Why this tier exists:** the existing doc
`docs/superpowers/plans/2026-06-30-foundational-wave2-plan.md` is a *design/decision* plan
(premise verdicts + migration SQL sketches + file-level change lists). This prompt turns that into an
*implementation* plan: finalized migration files, complete method/class code, exact TDD tasks, and the
per-PR commit structure — the level an implementer follows verbatim.

**Paste the fenced block below into a fresh session** (default output style — not explanatory — so no
narration leaks into the plan file). Run it once; it emits one plan document + a reviewer verdict.

---

```
Author and independently review a SINGLE CONSOLIDATED IMPLEMENTATION PLAN for ALL Wave-2 findings of
the foundational audit. Do NOT execute it — produce a reviewed, ready-to-run plan document and stop.

## What this is
The Wave-1 refactors already shipped on branch audit-fix/foundational-2026-06-30. Wave 2 is the gated
remainder: DB migrations + auth + the platform HTTP layer. A design/decision plan already exists and
has verified every premise against current code. Your job is to upgrade that design plan into an
implementation plan an engineer can follow step-by-step, for every finding, in ONE document.

## Read first (in this order)
- CLAUDE.md  (house rules: raw SQL in supabase/migrations/ ONLY — a guard rejects Laravel migrations;
  Resource classes; Policies via authorizeForUser; the SQLite-vs-Postgres caveat; supabase push
  semantics → dev ref glncumufgaqcmqhzwrxm serves BOTH api domains; migrate --force is commented out
  so migrations are applied via `supabase db push` / MCP apply_migration, not on deploy)
- scripts/audit/fix-flow.md  (the execute-audit runbook this plan will eventually be run under)
- docs/superpowers/plans/2026-06-30-foundational-wave2-plan.md  ← THE DESIGN PLAN. It contains, per
  finding: the verified premise verdict, migration SQL sketch + rollback, the file+method change list,
  blast radius, test plan, and the recommended PR sequencing. Treat it as the source of intent — but
  re-confirm every cited line/signature against the live tree before writing code into the plan.
- audits/sweeps/2026-06-25-foundational/CONSOLIDATED.md  (the open Wave-2 findings + their evidence)

## Ground in reality — non-negotiable
The audit was generated 2026-06-25 and the tree moved since (Wave-1 refactors + an earlier platform-
registry redesign + SmartLinks removal). The design plan already caught SEVEN stale premises you MUST
honor (do NOT re-introduce the audit's stale version):
1. FOUND-6: child schema is PER-MODE (pickup_price/pickup_url/delivery_price/delivery_url), NOT
   (platform, price, modes, url).
2. FOUND-3: cover_shopify is a DEAD slot; the convention is `cover_` + str_replace('-','_',$key)
   (registry keys are hyphenated, purposes underscored, and IndividualProfilePayloadBuilder splits on
   '_'); introduce a `coverable` flag on PlatformDescriptor (4 platforms: youtube, apple-music,
   apple-podcast, eventbrite).
3. FOUND-18: place_id is a first-class selection identifier (connect contract, Maps deep-link, public
   resource, fetch strategy), NOT hidden state — give it an INDEXED column mirror, keep it in payload;
   only apify_status is fully promoted out of payload.
4. FOUND-16: StaffUpdateSiteRequest is missing charlie_enabled (9 keys, not 10); booking_mode does
   NOT accept 'none' today.
5. FOUND-19: 22 connect requests (not 24); YouTube is a plain `channel` field (not a video-id
   outlier); only GoogleBusiness is irreducible; PlatformDescriptor already carries connect metadata.
6. FOUND-14: a block_type CHECK and a separate block_group CHECK already exist — only the PAIR check
   is missing.
7. FOUND-5: the read-side migration is incomplete without rewiring the JSON WRITE path (a new
   SyncUserAboutService) — the biggest single risk in the wave.
For EVERY finding: open the files the design plan cites (its per-finding "Critical Files" lists) and
confirm class names, method signatures, response shapes, and DDL still match. If anything has drifted
since the design plan, correct the implementation plan and note the drift. Do NOT fabricate.

## The findings to plan (group by the design plan's recommended PRs)
Author one top-level section per PR, in this order, each a complete implementation plan:
  PR1  FOUND-12  — extract Aal2FreshnessGate (AUTH-SENSITIVE; security-review step required)
  PR2  FOUND-2 + FOUND-6  — menu delivery cols + menu_items.platforms → child tables
  PR3  FOUND-4 + FOUND-5  — workplace + credentials/experience JSONB → tables (incl. the write-path rewire)
  PR4  FOUND-14 + FOUND-10 — block_group/type pair-CHECK + config map + SectionVisibility registry
  PR5  FOUND-15  — promote Block.settings live_check_enabled/category/platform → columns (+ both DB views)
  PR6  FOUND-16  — promote 10 site.sites.settings keys → typed columns (+ both DB views)
  PR7  FOUND-3   — SiteMedia cover purposes → registry-derived convention (index collapse)
  PR8  FOUND-18  — IntegrationConnection apify_status (promote) + place_id (indexed mirror)
  PR9  FOUND-19  — connect Form Requests → descriptor-driven
  PR10 FOUND-21  — route registration over the registry (after FOUND-19)

## Decisions (default to the design plan's recommendation unless Josh has since overridden)
Mark each explicitly in the plan as a "DECISION (confirm before implementing)" line so it can be
flipped without rewriting the plan:
- FOUND-6: per-mode child schema (recommended) vs the audit's lossy (price,modes,url).
- FOUND-16: widen booking_mode validation to accept 'none' to match the new CHECK (recommended) vs
  keep 'manual'-only and narrow the CHECK.
- FOUND-4: narrow workplace visibility name-OR-address → name-only (consistency fix; behavior change).
- FOUND-15/16: dual-write-then-strip (recommended — decouples the two-view lockstep) vs strip-in-same-migration.
- FOUND-18: place_id indexed mirror (recommended) vs full payload-purity re-thread (larger, separate task).

## What each finding's implementation plan section MUST contain
Use superpowers:writing-plans for structure. For EACH finding:
- COMPLETE code — every new/changed class and method written out in full (not "similar to" / not a
  sketch). Exact file paths. Real signatures matching the current tree.
- The FINAL migration file(s): exact filename `supabase/migrations/<YYYYMMDDHHMMSS>_<name>.sql` and the
  full raw SQL (CREATE/ALTER + backfill + indexes + CHECK + rollback-as-inline-comment). Backfills are
  no-ops pre-beta but must be correct for prod-shape parity. NEVER a Laravel migration.
- The tests/Pest.php hand-built SQLite schema updates (every promoted column / new table added to the
  matching setup helper) — because the SQLite test schema does NOT auto-apply the SQL migrations.
- A "Postgres-only constraints are invisible to SQLite" note + an explicit DEV-SUPABASE VERIFICATION
  step for every CHECK / partial-unique / FK-cascade the migration adds (apply to glncumufgaqcmqhzwrxm
  via apply_migration, then attempt an invalid insert via execute_sql and confirm rejection).
- For FOUND-15 and FOUND-16: the lockstep edit to BOTH public-read VIEWs (site.all_site_data,
  site.public_site_payload) IN THE SAME migration as the column strip — call this out as the gating
  step (silent NULL data-loss if missed).
- Bite-sized TDD tasks: failing test → run-fail → minimal code → run-pass → `php artisan pint --dirty`
  → commit (with message). No placeholders, no "TODO", no "see Task N".
- Golden-master guard: name the existing tests that must stay green and (where the API contract is
  user-visible — FOUND-19/21, the public-profile payloads) assert byte-identical responses.
- The $backoff-on-every-ShouldQueue-job rule and the authorize-in-controller rule where relevant.
- A per-finding "verify the FULL `composer test` suite is green" gate (a filtered subset is a false
  signal — Wave-1 hit a 9-test regression that only the full suite caught).

## Output shape
- Save ONE document: docs/superpowers/plans/2026-06-30-foundational-wave2-implementation.md
- Top of the doc: the global constraints, the PR ordering table, the 5 decisions, and the 7
  honored premise corrections.
- One `## PRn — FOUND-x[, y]` section per item above, each a self-contained implementation plan.
- Because this is large, you MAY fan out: dispatch one Opus sub-author per PR to draft its section
  (read-only, against the real code), then YOU assemble them into the single document and reconcile
  cross-references (e.g. PR4's SectionVisibility registry vs PR5's settings->category move; PR9 before
  PR10). State in the doc which sections were drafted in parallel.
- Run the superpowers:writing-plans self-review (spec coverage, placeholder scan, type consistency,
  scope) before handing off.

## Then review it independently (dispatch a FRESH Opus reviewer subagent — not an author)
The reviewer reads the assembled plan document against the CURRENT codebase and returns PASS or a
numbered must-fix list:
- GROUNDED IN REALITY: open every file the plan cites and confirm each class/method/resource/route/DDL
  exists with the cited signature. Flag anything fabricated or drifted.
- The 7 premise corrections are honored (no stale-audit schema/shape reintroduced).
- Every migration is raw SQL in supabase/migrations/ (no Laravel migration), has a rollback, updates
  tests/Pest.php, and has a dev-Supabase verification step for each constraint.
- FOUND-15/16 edit BOTH views in the same migration as the strip.
- FOUND-5 includes the write-path rewire (SyncUserAboutService), not just the read side.
- FOUND-12 keeps AAL2 behavior byte-identical and carries a security-review step.
- FOUND-19/21 keep the route count frozen at 52 and the connect contract byte-identical.
- TDD tasks are right-sized with complete code; no placeholders/TODOs; ordering/dependencies correct
  (PR9 before PR10; PR4 before PR5).
If must-fix findings: fix the plan document inline, then re-dispatch the reviewer. Finalize only on PASS.

## Report and STOP
Report the finalized plan file path and the reviewer's PASS verdict. Do NOT implement anything, do NOT
create a branch, do NOT touch supabase. Each PR will be executed later, in order, under fix-flow
(plan already done → Sonnet implement → independent Sonnet/Opus review → full-suite gate → commit),
one PR per session, gated on Josh's sign-off and the prior PR being merged.
```

---

## Notes for Josh (not part of the prompt)

- This authors **one consolidated implementation-plan doc** (your "one document" preference), with a
  `## PRn` section per finding — not 10 separate plan files. If you'd rather have the split-file
  pattern (like the platform-integrations Plans 2–6), say so and I'll switch it to per-PR A/B prompts.
- It defaults the 5 open decisions to the design plan's recommendations and flags each so you can flip
  them without a rewrite — but if you answer the 5 decisions first, I'll bake your answers in so the
  authored plan is unambiguous.
- It does **not** include the **B (execute)** prompt — that's the fix-flow loop I already run per PR.
  I can add matching per-PR B prompts to this file if you want the full author→execute pair.
