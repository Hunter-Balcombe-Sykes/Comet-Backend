# KICKOFF PROMPT — Services cutover (the last three legacy tables)

> **SPENT 2026-08-17 — do not re-run.** This prompt's session completed and
> produced `specs/2026-08-17-services-cutover-design.md` and
> `plans/2026-08-17-services-cutover.md` (commit `137c42fdb`). Kept for
> provenance only; the spec supersedes it wherever they disagree.
>
> **Known-wrong statement below, corrected by the spec:** this prompt says the
> armed migration `20260817000000_public_site_payload_services_from_content.sql`
> "exists ONLY on feat/slice-7-teardown — not on development". That branch has
> since merged, so the file **is on `development`** — verified unapplied against
> `schema_migrations`. Spec §1.1 records this; ruling 3 schedules applying it as
> Task 1.

> Paste the fenced block below into a fresh session. Everything outside the fence
> is orientation for whoever is pasting.

**What this session produces:** a spec and a plan. **Not code.** The work is XL
and the last unknown in the convergence programme; planning it is its own task.

**Where it sits:** slice 7's drop phase drops five tables. Four were deferred —
`site.shop_brands` and `site.shop_products` to
`2026-08-17-shop-brands-rehome.md`, and these three to this session.

---

```
Rename this session to services-cutover-plan.

You are planning the retirement of the last three legacy tables in the Content Pool
Convergence programme: site.services, site.service_categories and
site.service_category_assignments. Dev only; production is out of scope for the whole
programme. You write a spec and a plan. You write NO feature code this session.

RULE ZERO — VERIFY, DO NOT TRUST. Every figure, line number and file path below was
derived on 2026-08-17 against the branch feat/slice-7-teardown. They are a snapshot.
Re-derive each one before writing it into your spec. Where what you find disagrees with
what is written here, what you find wins and you say so in the spec. Two documents in
this programme have already been wrong in exactly this way, both caught by re-derivation
and both recorded rather than patched over.

READ FIRST, in this order:
1. docs/superpowers/specs/2026-08-17-shop-brands-rehome-design.md §11 — the scope
   handoff that created this session, including the armed migration below.
2. docs/superpowers/specs/2026-08-12-slice-7-teardown-design.md — Unit K and §8, for
   the ordering rule and what "deferred" has meant so far in this programme.
3. docs/superpowers/plans/2026-08-12-slice-7-teardown.md Task 11 — seven written steps
   for the Fresha payload.selection cutover, with one class name already corrected
   once. LIFT THAT TEXT rather than re-deriving it; it cost a review round to get right.
4. docs/superpowers/specs/2026-08-13-slice-3b-services-fresha-design.md — what 3b
   actually shipped on the content side, which is most of your destination.
5. The parent spec docs/superpowers/specs/2026-08-11-content-pool-convergence-design.md,
   §§ covering slices 3a and 3b.

THE CORE PROBLEM, stated once

The owner-authored half of services moved to content.* in slice 3a. The FRESHA half
never did. So two id spaces are live at once, and the code says so outright — the staff
controller's header documents the rule. A legacy site.services id stays addressable by
design (§C2, slice 3a). Dropping these tables today 42P01s the services list and the
booking surface.

Your job is to end the dual-id-space era: cut the Fresha half over to content.*, repoint
every reader, then drop the three tables.

THE SURFACE — ~30 live query sites, 77 raw references, five files

Re-derive with:
  grep -rn "Service::query()\|Service::where\|Service::find\|ServiceCategory::query()" app/
  grep -rn "site\.services\|service_category_assignments" app/

As of 2026-08-17:
- app/Http/Controllers/Api/User/SiteManagement/UserServiceController.php — 43 refs, by
  far the largest. Live Service::query() at :91, :313, :338, :510, :590, :638, :687,
  :780, :850, :975, :1171, :1187; ServiceCategory::query() at :149, :961, :1093, :1388.
  Most are `->whereNotNull('source')` — that filter IS the Fresha half.
- app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceManagementController.php
  — 18 refs. Its class docblock is the best statement of the dual-id rule in the repo;
  read it before designing anything.
- app/Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceCategoryManagementController.php
  — 9 refs, including destroyLegacy()'s raw detach at :441 and ServiceCategory::query()
  at :302, :383, :479.
- app/Services/Cache/UserCacheService.php — Service::query() at :230. NOTE THE PATH: it
  is app/Services/Cache/, not app/Services/User/. This one is the PUBLIC "active
  services" read (`WHERE source IS NOT NULL`), not the dashboard management list, and
  UserSelfController::show serves from it. Getting these two confused is the shape of
  defect B2 that this method's own docblock records.
- app/Http/Controllers/Api/Platforms/FreshaController.php — 3 refs, Service::query() at :681.

Plus app/Policies/ServicePolicy.php:80, whose docblock encodes the same source-is-null
split, and the Service / ServiceCategory models themselves.

⚠️ ONE MIGRATION IS ALREADY WRITTEN AND ARMED

supabase/migrations/20260817000000_public_site_payload_services_from_content.sql
(19KB, authored 2026-08-17 00:35) recomposes the site.public_site_payload VIEW's
`services` key off content.*. It exists ONLY on feat/slice-7-teardown — not on
development — and is deliberately UNAPPLIED on dev because it matters only for this
drop.

It is a real file in supabase/migrations/, so ANY unrelated `supabase db push` against
the dev ref applies it. Treat it as your step 1 and verify its state before anything
else: is it applied on dev, and does the branch you are working from carry it?

That view is not decorative. app/Models/Views/PublicSitePayload.php reads it and
SyncSubdomainToKvJob is downstream, so it is on the public/KV path. A wrong `services`
key here is a wrong sitepage, not a wrong dashboard.

WHAT IS ALREADY DONE — do not re-plan these

- Slice 3a moved owner-authored services to content.*. ManualServiceWriter,
  ManualServiceItems and ServiceCollections exist and work.
- Slice 3b cut the Fresha READ side: FreshaServiceItems::selectionServices(string $userId)
  already produces the vendor blob shape from content.*, and FreshaServiceProjector no
  longer writes site.services (its :100 docblock says "this writes NOTHING").
- Slice 7 (s7-h, e2c66f476) retired the last site.service_category_assignments WRITES —
  the replace-set in both reorderLayout() verbs — and the legacy category listing in
  StaffServiceCategoryManagementController::index(). What it explicitly did NOT touch:
  the by-id legacy branches (show/update/destroy/restore/reorder) and the seven routes'
  split across the two staff middleware groups. Those are yours.
- Slice 7 Task 11 planned the Fresha payload.selection cutover in seven steps. It may
  or may not have shipped by the time you start — CHECK, and if it shipped, your spec
  records it as done rather than re-planning it.

TRAPS, each already paid for once in this programme

- ManualServiceItems::publicList() is the WRONG target for the Fresha blob, on three
  counts, any one fatal: it returns the seven legacy DASHBOARD keys not the nine VENDOR
  keys; it filters content.sources.kind='manual' while Fresha lands under
  kind='connection'; and routing the write through ManualServiceWriter::write() puts
  Fresha services on the manual source, which ServiceTwoSurfaceTest pins shut in both
  directions. The correct target is FreshaServiceItems::selectionServices().
  markRemoved() IS safe to reuse — it is kind-agnostic. It is write() that leaks.
- Tests run SQLite; production is Postgres. A green suite has twice hidden a Postgres
  rejection in this programme. Anything touching ProjectionWriter or its callers runs
  composer test:pg. Verify constraint-bound writes against supabase/migrations/ DDL.
- A staff route's middleware group is not obvious: staff.php has THREE groups. Check
  which one each of the seven category routes sits in before moving anything.
- The public read and the dashboard read are different lists with different filters.
  Every repoint must say which one it is repointing.
- Cross-file test helper names collide at load time and fatal a --parallel run. Pick a
  prefix for this project's helpers and state it in the plan's Global Constraints.
- Deleting a legacy row is not the same as retiring a lane: check for a live writer
  first. The event slug lane in this same programme had its rows deleted while
  EventSlugSync was still wired, and they came back on the next refresh.

QUESTIONS YOUR SPEC MUST ANSWER — these are the design, not the paperwork

1. What replaces the dual-id space at the API boundary? A legacy site.services uuid is
   currently addressable and users hold those ids. Does the cutover mint a mapping, keep
   the ids by writing them as content.* coords, or break them deliberately with a
   recorded wire change? This is the decision the whole project hangs on.
2. Does anything still WRITE site.services after slice 7 Task 11 lands? Name the writer
   or prove there is none. The DROP is only safe once the answer is none.
3. What happens to soft-deleted legacy rows? deleted_origin='user' suppression and
   deleted_origin='sync' restore-on-return are real behaviours in the legacy lane
   (slice 7 Task 11 Step 4 names them). Where do they live afterwards?
4. Does the DSAR export read these tables? Slice 7 found ManualServiceItems querying
   site.services directly to validate a coord before trusting it in a disclosure
   payload, and a 42P01 there takes the WHOLE export down, not one section. Re-derive
   whether that cross-check still exists and, if so, what replaces it.
5. Is site.public_site_payload the only VIEW over these three tables? Query pg_depend,
   not the table list. The catalog is what tells you; the drop list is not.

PROCESS — this session stops for sign-off

1. Read everything above. Re-derive the figures. Report what you found and what
   disagreed, and STOP for the owner before writing the spec.
2. Write the spec to docs/superpowers/specs/2026-08-17-services-cutover-design.md.
   STOP for sign-off.
3. Write the plan to docs/superpowers/plans/2026-08-17-services-cutover.md using the
   superpowers:writing-plans skill — bite-sized TDD steps, real code in every step, no
   placeholders, and an explicit ordering rule.
4. Do not start implementing. A separate session executes the plan.

NON-NEGOTIABLES

- Every content.* write path lands BEFORE the DROP that removes its legacy twin. This
  rule has held for the whole programme and is the reason nothing has frozen mid-cutover.
- Never create Laravel migration files. Raw SQL in supabase/migrations/, one concern per
  file, CONCURRENTLY at most once per file.
- Never apply anything to the prod ref, and never push development:production.
- The backup gate before any DROP: pg_dump, restore into a scratch schema to prove it
  reads back, assert per-table row counts match live exactly. One table disagreeing
  means nothing is dropped.
- If reality diverges from this prompt, you raise it — you do not quietly edit the
  prompt to match, and you do not proceed on an assumption.
```

---

## After this session

Its plan is the last implementation work in the convergence programme. Then
`phase-8-review-and-docs` — whose A2 legacy-zero sweep now names
`site.shop_brands`, and should name these three too once this project exists.
