# EXECUTE PROMPT — shop re-home (`site.shop_brands` + `site.shop_products`)

> Paste the fenced block into a fresh session. Named EXECUTE, not KICKOFF: the
> spec and plan already exist, so this session implements rather than plans.

**Status 2026-08-17 — CLEARED TO LAUNCH. All gates discharged.**

- ✅ The drop phase has merged. `origin/development` carried it at `1866354dc`;
  its checkpoint (`2026-08-17-slice-7-phase-6-checkpoint.md`) reads *"Shipped,
  at reduced scope: five tables of nine"* and explicitly hands `site.shop_products`
  back to this project. `ShopController` is free.
- ✅ The migration-version collision with the services cutover is resolved.
  This project owns the `20260819*` band.
- ✅ **Services cutover Task 1 has landed** — the gate that used to block this
  project. Verified independently 2026-08-17: `20260817000000` is recorded in
  `supabase_migrations.schema_migrations`, and `pg_depend` returns zero rows for
  all three legacy service tables. The block below still re-checks it, because
  verifying is cheap and this line is a snapshot.

The services cutover runs **in parallel** with this project, not before it —
only its Task 1 is a dependency. Territory was verified disjoint, not assumed.

---

```
Rename this session to shop-brands-rehome.

You are implementing the shop re-home: moving the last shop read/write lane off
site.shop_brands and site.shop_products onto content.storefronts + content.collections,
then dropping both tables. Dev only; production is out of scope. Two of the four tables
slice 7 deferred — site.services ×3 is a SIBLING PROJECT RUNNING RIGHT NOW and is NOT
yours.

YOUR DOCUMENTS
- Plan:  docs/superpowers/plans/2026-08-17-shop-brands-rehome.md   ← 15 tasks, work
         through them in order, tick as you go
- Spec:  docs/superpowers/specs/2026-08-17-shop-brands-rehome-design.md

Use the superpowers:executing-plans skill (or subagent-driven-development if you prefer
a fresh agent per task). The plan's steps are already bite-sized TDD; do not re-plan
them, and do not batch a phase into one commit.

SET UP AN ISOLATED WORKSPACE FIRST. Branch feat/shop-brands-rehome off origin/development
in its OWN git worktree (superpowers:using-git-worktrees). A sibling session is live in
this repo; do not work in the main checkout, and do not switch its branch. Worktrees need
their own `composer install` before the suite runs.

RULE ZERO — VERIFY, DO NOT TRUST. Every line number in the plan was derived on
2026-08-17 against feat/slice-7-teardown, and that branch moved 28 commits in one
session. Re-derive before editing. Where reality disagrees with the plan, reality wins,
you fix the plan in the same commit, and you say so in the commit body.

BEFORE YOU WRITE ANY CODE

1. THE GATE — services Task 1. It HAD landed as of 2026-08-17 (verified: the row
   below exists, and pg_depend returns zero rows for the three legacy service
   tables). Re-check anyway; it costs one query and this prompt is a snapshot.

   select version from supabase_migrations.schema_migrations
   where version = '20260817000000';

   - 1 row  → clear, proceed. This is the expected result.
   - 0 rows → STOP AND ASK THE OWNER. Do not push, do not park the file, do not
              hand-insert a schema_migrations row, and do NOT apply it yourself.
              It is the services project's first unit and carries its own KV
              re-warm and pg_depend verification. An earlier draft of this prompt
              told you to move that file out of supabase/migrations/. THAT
              INSTRUCTION IS WITHDRAWN — services ruling 3 schedules applying it
              deliberately, on evidence (its header verified KV/API id agreement
              element-for-element across all 22 published dev sites).

   Until that row exists, `supabase db push` from your branch would apply it as a
   side effect. That is the whole reason this gate exists.

2. The drop phase HAS merged — confirmed at origin/development 1866354dc, whose
   checkpoint docs/superpowers/plans/2026-08-17-slice-7-phase-6-checkpoint.md reads
   "Shipped, at reduced scope: five tables of nine". Re-confirm cheaply rather than
   trusting this line, then move on:

     git log --oneline -1 origin/development
     git grep -c "ShopBrand" origin/development -- app/Http/Controllers/Api/Platforms/ShopController.php

   As of 2026-08-17 that grep returned 61 — the fallbacks are still there, so Task 2
   is real work, not already done.

3. Task 1's inventory sweep still runs in full. Slice 7 may have absorbed part of
   Task 2 — if a fallback is already gone, tick it with their commit sha rather than
   redoing it.

YOU ARE NOT ALONE IN THIS REPO — the services cutover runs in parallel

Territory was verified disjoint on 2026-08-17, by cross-grepping both plans and by
querying pg_depend. Hold the line:

- THEIRS: site.services, site.service_categories, site.service_category_assignments,
  the site.public_site_payload VIEW, and every Service*/Fresha*/UserCacheService/
  ContentFreshness/ServiceObserver file. Migration band 20260818*. Helper prefix svcCut*.
- YOURS: site.shop_brands, site.shop_products, content.storefronts, and every
  Shop*/StoreBrand*/BrandAssetPipeline file. Migration band 20260819*. Helper prefix sbr*.
- tests/Pest.php is YOURS alone — their plan does not touch it.
- pg_depend confirms nothing depends on site.shop_brands or site.shop_products, so your
  DROP never touches the view on the KV path. Theirs does. Stay out of it.

If you find yourself editing one of their files, STOP and raise. Do not widen scope,
no matter how tempting it looks while you are inside these controllers.

YOUR MIGRATION BAND IS 20260819* — DO NOT RENUMBER IT

  20260819000100_content_storefronts_user_id.sql
  20260819000110_content_storefronts_identity_unique.sql   (own file: CONCURRENTLY)
  20260819000200_drop_site_shop_products.sql
  20260819000210_drop_site_shop_brands.sql

Both plans originally claimed 20260818000100 and 20260818000200. schema_migrations keys
on the numeric prefix ALONE — the descriptive suffix is not part of the key — so the
second project to merge would have had both files silently SKIPPED by db push, with no
error: shop_products dropped while content.storefronts.user_id never landed. If a
20260818* filename appears in your plan text it is prose explaining this, not a file.

WHAT ALREADY EXISTS — this is a repoint, not a design job

- StoreRecord (app/Services/Shop/StoreRecord.php), shipped by slice 7 Task 24. Readonly
  DTO, identity (provider, externalRef). Its fromStorefrontRow() is already written and
  its docblock calls it "the post-DROP read direction" — that IS your read path.
- ShopContentWriter is already off the model: upsertStore(StoreRecord $store, string
  $ownerId): string, retireStore(string $userId, string $collectionId): void. Note the
  argument orders; they are not (User, …).
- ShopContentReader::brandMap(User $user, ?array $productRanks = null): array already
  rebuilds the exact toBrandArray() shape from content.*, ordered c.position then
  s.external_ref. Your ShopConnections::stores() must use the SAME ordering or the two
  reads will disagree about which store is position 0.
- content.storefronts already carries every column that matters. style_analysis,
  selection_mode and link_mode are dead — nothing in app/ reads them, they need no home.

Live dev counts, 2026-08-17, for your own sanity check at Task 1 and again at Task 13:
site.shop_brands 10, site.shop_products 51, content.storefronts 15.

THE FOUR TRAPS IN THIS PROJECT

1. tests/Pest.php mints ShopBrand rows. It is a SHARED helper, and cross-file test
   helper names collide at load time and fatal a --parallel run. Change it ALONE, run
   pest --parallel --processes=4 immediately, commit it on its own — before the other
   25 test files. Helper prefix for this project is sbr*.
2. The R2 logo prefix moves from shop-brands/{legacy-uuid} to shop-brands/{collectionId}.
   Do NOT migrate existing objects: their URLs are stored absolute and keep resolving,
   and a re-process rewrites them under the new prefix for free. Spec §5.1 — leave the
   comment it asks for, so a later bucket audit does not read the stranded prefix as loss.
3. Property names change with the type. is_individual → isIndividual,
   products_curated_at → productsCuratedAt. And Collection::max('position') returns null
   for an empty set where the query Builder returned 0 — Task 6 Step 4 coalesces for
   exactly this reason.
4. Tests run SQLite, production is Postgres. This programme has been bitten twice.
   Task 11 adds a unique index; its test belongs in tests/Postgres/ and must pin the
   REFUSAL REASON (the constraint name), not merely that a QueryException was thrown.

⚠️ THE BACKUP GATE HAS NO WORKING TOOLING — SOLVE THIS BEFORE TASK 13, NOT DURING

The slice-7 drop phase logged this in red in its own checkpoint §4: rclone, aws and
wrangler are all absent locally, and the .env AWS keys address the media bucket, not
partna-db-backup. They took the pg_dump and proved it restorable, but the R2 copy was
never made — "the dump lives on one laptop". Your Task 13 requires the same gate. Sort
the R2 upload path out EARLY, in parallel with Phase 1, or you will hit the identical
wall with the DROP as the only thing left to do. Two sessions may restore dumps on dev
today, so name your scratch schema distinctly (e.g. shop_rehome_restore_check).

PHASE GATES — stop at each, do not run the whole plan in one push

Phases 1, 2 and 3 each end in a gate task: full suite serial, pest --parallel
--processes=4, composer test:pg, PHPStan at `php -d memory_limit=1G ./vendor/bin/phpstan
analyse` (the default OOMs and reports it as "severe errors"), php artisan pint, then
merge to development and deploy dev. Phase 1 is additive — nothing calls stores() yet —
so that deploy changes no behaviour and is safe to take early.

Merging to development while a sibling session also merges there: rebase, re-run the
gate, and never force-push. If you hit a conflict in the parent convergence spec or a
shared checkpoint doc, resolve by keeping BOTH entries — it is a docs conflict, not a
semantic one.

Live verification is not optional and is not `composer test`: on dev, exercise all seven
shop verbs against a real store (connect, poll status, rename, curate products, remove a
product, remove the store, re-add it) and assert content.storefronts moved while
site.shop_brands did not.

NON-NEGOTIABLES

- Every content.* write path lands BEFORE the DROP that removes its legacy twin.
- Never create Laravel migration files. Raw SQL in supabase/migrations/, one concern per
  file, CONCURRENTLY at most once per file.
- Never push to the prod ref. Never `git push origin development:production`.
- The backup gate before any DROP (Task 13): pg_dump both tables, restore into a scratch
  schema to prove they read back, assert per-table row counts match live exactly. One
  table disagreeing means nothing is dropped.
- site.shop_products drops BEFORE site.shop_brands — children before parents, and
  shop_products.brand_id references it.
- Phase 5 is VERIFY ONLY. The event slug lane belongs to the drop phase. If your checks
  fail, you raise; you do not fix IntegrationConnectionObserver.
- If reality diverges, you raise — you do not quietly widen scope.

DEFINITION OF DONE

- Both tables dropped on dev; ShopBrand and ShopProduct deleted; a grep for
  ShopBrand|shop_brands|ShopProduct|shop_products across app/ routes/ config/ tests/
  returns nothing.
- All lanes green: composer test, pest --parallel, test:pg, test:schema, PHPStan, Pint.
- The seven verbs exercised live on dev, with the counts pasted into the checkpoint.
- Wire manifest written to docs/wire-changes/2026-08-17-shop-brands-rehome.md. The
  public wire is UNCHANGED (slice 5b already moved it) — say so explicitly rather than
  omitting the file.
- cloud env:logs partna development --minutes 10 plus a Nightwatch scan, post-deploy.
- Checkpoint into the parent convergence spec, recording: the two tables gone, that your
  migrations took the 20260819* band and why, that services Task 1 was verified applied
  before your first db push, whether the R2 leg of the backup gate was completed or
  again deferred, and that production still carries the legacy schema.
```
