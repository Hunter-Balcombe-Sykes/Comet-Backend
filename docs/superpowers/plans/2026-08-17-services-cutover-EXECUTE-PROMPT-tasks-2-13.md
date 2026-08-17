# EXECUTE PROMPT — services cutover, TASKS 2–13 (the rest of the plan)

> Paste the fenced block into a **fresh** session. Task 1 shipped under its own
> prompt (`…-EXECUTE-PROMPT-task-1.md`, now spent); this covers everything after it.
>
> **Use a fresh session, not the Task-1 one.** Tasks 2–13 are the XL bulk of a
> 1,852-line plan. Starting them in a context already spent on Task 1 walks into
> the recall degradation this programme has measured repeatedly. The Task-1
> session's findings are all committed — nothing is lost by starting clean.
>
> **Paste this only once you have finished the post-Task-1 check** you held the
> plan for. Pasting it *is* the clearance.

**Task 1 verified complete, independently, 2026-08-17:**

- `20260817000000` is recorded in `supabase_migrations.schema_migrations`.
- The `pg_depend` query returns **zero rows** for `site.services`,
  `site.service_categories` and `site.service_category_assignments` —
  `site.public_site_payload` no longer reads any of them. That was the
  acceptance criterion, and it is met.

---

```
Rename this session to services-cutover.

You are implementing Tasks 2–13 of the services cutover: ending the dual-id-space era
for services by cutting the Fresha management surface over to content.*, repointing
every remaining reader and writer of the three legacy tables, then dropping
site.services, site.service_categories and site.service_category_assignments.
Dev only; production is out of scope for the whole programme.

YOUR DOCUMENTS — on development
- Plan:  docs/superpowers/plans/2026-08-17-services-cutover.md   ← Tasks 2–13.
         Work them in order, tick as you go. Its Global Constraints bind you.
- Spec:  docs/superpowers/specs/2026-08-17-services-cutover-design.md — every
         design decision, and the six owner rulings. Read §2 before writing code.

Use superpowers:executing-plans (or subagent-driven-development for a fresh agent per
task). The plan's steps are already bite-sized TDD; do not re-plan them, and do not
batch a task into one commit.

TASK 1 IS DONE — DO NOT REDO IT, DO NOT RE-APPLY THE MIGRATION

Verified independently on 2026-08-17: 20260817000000 is in schema_migrations, and the
pg_depend query returns ZERO rows for all three legacy tables — site.public_site_payload
is fully off them. Tick Task 1 if the plan still shows it open, citing that evidence.
Your first real work is Task 2 (FreshaServiceItems — the management read).

Re-confirm cheaply for yourself before starting, then move on:

  select version from supabase_migrations.schema_migrations where version='20260817000000';

SET UP AN ISOLATED WORKSPACE FIRST

Branch feat/services-cutover off origin/development in its OWN git worktree
(superpowers:using-git-worktrees), exactly as the plan's Global Constraints require.
A sibling session is live in this repository and the main checkout's branch has changed
under a session twice today, once with data loss. Never work in the main checkout, never
switch its branch, never `git reset` in it. Worktrees need their own `composer install`
before the suite runs. Verify the branch IN THE SAME COMMAND as any commit, not one
command earlier.

RULE ZERO — VERIFY, DO NOT TRUST. Every line number in the plan was derived on
2026-08-17 and multiple sessions are moving this repo hourly. Re-derive before editing.
Where reality disagrees with the plan, reality wins, you fix the plan in the same commit,
and you say so in the commit body. Two documents in this programme have already been
wrong in exactly this way; both were caught by re-derivation and recorded rather than
patched over.

YOU ARE NOT ALONE — the shop re-home runs in parallel, starting now

Territory was verified disjoint on 2026-08-17, by cross-grepping both plans and by
querying pg_depend. Hold the line:

- YOURS: site.services, site.service_categories, site.service_category_assignments,
  the site.public_site_payload VIEW, and every Service*/Fresha*/UserCacheService/
  ContentFreshness/ServiceObserver/ServiceCategoryObserver/ServiceBackfiller file.
  Migration band 20260818*. Helper prefix svcCut*.
- THEIRS: site.shop_brands, site.shop_products, content.storefronts, and every
  Shop*/StoreBrand*/BrandAssetPipeline file. Migration band 20260819*. Helper
  prefix sbr*. tests/Pest.php is THEIRS — they rewrite its ShopBrand minting.
- pg_depend confirms nothing depends on the shop tables, so their DROP never touches
  your view. Yours is the one on the public/KV path.

If you find yourself editing one of their files, STOP and raise. Do not widen scope.

YOUR MIGRATION BAND IS 20260818* — DO NOT RENUMBER IT

  20260818000100_drop_site_service_category_assignments.sql   (children first)
  20260818000200_drop_site_services.sql
  20260818000300_drop_site_service_categories.sql

Both plans originally claimed 20260818000100 and 20260818000200. schema_migrations keys
on the numeric prefix ALONE — the descriptive suffix is not part of the key — so the
second project to merge would have had its files silently SKIPPED by db push, with no
error: a table surviving a "successful" DROP whose checkpoint records it gone. The SHOP
plan was renumbered to 20260819*, not yours. Keep your three where they are.

Before any db push, dry-run and read the list. It must contain only your own files.
Anything else belongs to another session — STOP and report; never apply a stranger's
migration as a side effect.

THE RULINGS THAT ARE ALREADY MADE — spec §2, do not silently re-open

1. Legacy site.services ids BREAK, deliberately. No mapping is minted. The management
   surface addresses Fresha services by content.items.id after the cutover, exactly as
   it has addressed owner-authored services since 3a. A wire-manifest entry covers it.
2. Deleted-state re-homes per Task 11 Step 4's mapping (§3.3): owner-delete →
   content.items.removed_at (one-way, never resurrected); vendor-removal →
   content.source_items.removed_at (cleared on reappearance — restore-on-return is
   preserved natively); hidden stays on the blob (hiddenServiceIds).
3. The armed view migration applied first — DONE.
4. anseo-studio's unprovisionable book-now/…?pId= URL is DEFERRED past this project.
   That connection publishes nothing until the matcher is widened. Unchanged from today,
   and not yours to fix.
5. The no-selection dashboard prompt stays deferred. Three connections publish nothing
   by 3b's deliberate decision; prompting is dashboard work.
6. LegacyServiceSortOrder and ContentFreshness are RE-HOMED, not deleted-and-lost. The
   functions survive on content.* (§3.4, §3.5); the legacy class retires with the table.

THE TRAPS, each already paid for once in this programme

1. ManualServiceItems::publicList() is the WRONG target for the Fresha blob, on three
   counts, any one fatal: it returns the seven legacy DASHBOARD keys not the nine VENDOR
   keys; it filters content.sources.kind='manual' while Fresha lands under
   kind='connection'; and routing the write through ManualServiceWriter::write() puts
   Fresha services on the manual source, which ServiceTwoSurfaceTest pins shut in BOTH
   directions. The correct target is FreshaServiceItems::selectionServices().
   markRemoved() IS safe to reuse — it is kind-agnostic. It is write() that leaks.
2. Tests run SQLite; production is Postgres. A green suite has twice hidden a Postgres
   rejection in this programme. composer test:pg is mandatory for Tasks 2–9. The
   tests/Postgres/ stand-in DDL is hand-written and drifts silently — update it when
   your schema assumptions change.
3. staff.php has THREE middleware groups. Check which one each of the seven category
   routes sits in before moving anything.
4. The public read and the dashboard read are DIFFERENT lists with different filters.
   UserCacheService (app/Services/Cache/, not app/Services/User/) is the PUBLIC "active
   services" read; the controllers' index() is the dashboard management list. Every
   repoint must say which one it is repointing — confusing them is the shape of defect
   B2, recorded in that method's own docblock.
5. Deleting a legacy row is not the same as retiring a lane: check for a live writer
   first. The event slug lane in this same programme had its rows deleted while
   EventSlugSync was still wired, and they came back on the next refresh.
6. Cross-file test helper names collide at load time and fatal a --parallel run. Your
   prefix is svcCut*.
7. tests/Feature/Content/ServiceTwoSurfaceTest.php must stay green UNMODIFIED through
   every task. It is the invariant, not an obstacle.

Live dev counts, 2026-08-17, for your own sanity checks now and again at Task 12:
site.services 82 (61 Fresha-sourced, 21 superseded owner rows; 28 soft-deleted =
25 deleted_origin='sync' + 3 NULL; 0 rows carry deleted_origin='user' — the code path
is real, the data is empty). site.service_categories 18.
site.service_category_assignments 61.

⚠️ THE BACKUP GATE HAS NO WORKING TOOLING — SOLVE IT BEFORE TASK 12, NOT DURING

The slice-7 drop phase logged this in red in its own checkpoint §4: rclone, aws and
wrangler are all absent locally, and the .env AWS keys address the media bucket, not
partna-db-backup. They took the pg_dump and proved it restorable in a throwaway
postgres:17 container, but the R2 copy was never made — "the dump lives on one laptop".
Your Task 12 requires the same gate. Sort the R2 upload path out EARLY, in parallel with
the early tasks, or you will hit the identical wall with only the DROP left to do. The
shop session may also be restoring dumps on dev today, so name your scratch schema
distinctly (e.g. svc_cutover_restore_check).

CACHE INVALIDATION IS CALLER-OWNED

Every new or changed write path fires all three lanes via
ManualServiceWriter::invalidate([$siteId]) + $site->touch() where the existing method
does. Never re-roll the lanes locally. Assert EXACT revision deltas in tests, never > 0.

PHASE GATE — Task 11, and it is a real stop

Full suite serial, pest --parallel --processes=4, composer test:pg, composer test:schema,
PHPStan at `php -d memory_limit=1G ./vendor/bin/phpstan analyse app tests --no-progress`
(the default OOMs and reports it as "severe errors"), php artisan pint. Then merge to
development, deploy dev, and LIVE-VERIFY before Task 12 touches a DROP.

Live verification is not `composer test`: exercise the management verbs against a real
Fresha connection on dev — list, show, rename/update, hide, delete, restore, reorder,
resync — and assert content.* moved while site.services did not. Paste the counts into
your checkpoint.

Merging to development while the shop session also merges there: rebase, re-run the gate,
never force-push. A conflict in the parent convergence spec or a shared checkpoint doc is
a docs conflict — resolve by keeping BOTH entries.

NON-NEGOTIABLES

- Every content.* write path lands and is live-verified on dev BEFORE the DROP unit.
  The DROPs are ONE unit, LAST. This rule has held for the whole programme and is why
  nothing has frozen mid-cutover.
- Never create Laravel migration files. Raw SQL in supabase/migrations/, one concern per
  file, CONCURRENTLY at most once per file.
- Never link or apply anything against the prod ref edplucmvkcnokyygxqsb. Never
  `git push origin development:production`.
- The backup gate before any DROP: pg_dump all three tables, restore into a scratch
  schema to prove they read back, assert per-table row counts match live EXACTLY. One
  table disagreeing means nothing is dropped.
- site.service_category_assignments drops BEFORE site.services and
  site.service_categories — children before parents.
- Do not touch the shop lane, and do not re-open a settled ruling. If reality diverges,
  you raise it — you do not quietly adapt and proceed.

DEFINITION OF DONE

- All three tables dropped on dev; Service and ServiceCategory reduced to in-memory DTOs;
  the observers, ServiceBackfiller, BackfillOwnerServices and LegacyServiceSortOrder
  deleted; the LegacyServiceQuerySurfaceTest guard green.
- All lanes green: composer test, pest --parallel, test:pg, test:schema, PHPStan, Pint.
- ServiceTwoSurfaceTest green and UNMODIFIED.
- The management verbs exercised live on dev, with counts pasted into the checkpoint.
- Wire manifest docs/wire-changes/2026-08-17-services-cutover.md complete — including
  the ruling-1 id-domain break, alongside the KV entry Task 1 already wrote.
- cloud env:logs partna development --minutes 10 plus a Nightwatch scan, post-deploy.
- Checkpoint into the parent convergence spec: the three tables gone, the dual-id era
  ended, whether the R2 leg of the backup gate was completed or again deferred, and that
  production still carries the legacy schema.
- This is the LAST implementation work in the convergence programme. Say so, and name
  what remains: phase-8-review-and-docs, whose A2 legacy-zero sweep should now name all
  five tables retired by this project and the shop re-home.
```
