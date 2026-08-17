# EXECUTE PROMPT — shop re-home (`site.shop_brands` + `site.shop_products`)

> Paste the fenced block into a fresh session. Named EXECUTE, not KICKOFF: the
> spec and plan already exist, so this session implements rather than plans.

**Do not start until the drop phase has merged to `development`.** The plan's
Task 1 checks this, but starting earlier means editing `ShopController` while
another session holds it.

---

```
Rename this session to shop-brands-rehome.

You are implementing the shop re-home: moving the last shop read/write lane off
site.shop_brands and site.shop_products onto content.storefronts + content.collections,
then dropping both tables. Dev only; production is out of scope. Two of the four tables
slice 7 deferred — site.services ×3 is a separate project and is NOT yours.

YOUR DOCUMENTS
- Plan:  docs/superpowers/plans/2026-08-17-shop-brands-rehome.md   ← 15 tasks, work
         through them in order, tick as you go
- Spec:  docs/superpowers/specs/2026-08-17-shop-brands-rehome-design.md

Use the superpowers:executing-plans skill (or subagent-driven-development if you prefer
a fresh agent per task). The plan's steps are already bite-sized TDD; do not re-plan
them, and do not batch a phase into one commit.

RULE ZERO — VERIFY, DO NOT TRUST. Every line number in the plan was derived on
2026-08-17 against feat/slice-7-teardown, and that branch moved 28 commits in one
session. Re-derive before editing. Where reality disagrees with the plan, reality wins,
you fix the plan in the same commit, and you say so in the commit body.

BEFORE YOU WRITE ANY CODE — the three things that decide whether you can start

1. Has the drop phase merged to development? If not, STOP. You would be editing
   ShopController while another session holds it.

2. ⚠️ CORRECTED 2026-08-17 — DO NOT PARK THE SERVICES VIEW MIGRATION.

   supabase/migrations/20260817000000_public_site_payload_services_from_content.sql

   An earlier draft of this prompt told you to move this file out of
   supabase/migrations/ before your first db push. THAT INSTRUCTION IS WITHDRAWN.
   The services cutover spec (2026-08-17-services-cutover-design.md, ruling 3)
   schedules applying it as ITS Task 1, precisely to remove the accidental-push
   hazard rather than live with it. Its ruling 1 records the evidence that made the
   earlier fear moot: the migration's own header verified KV/API id agreement
   element-for-element across all 22 published dev sites.

   YOUR ONLY JOB HERE IS TO VERIFY, NOT TO ACT:

     select version from supabase_migrations.schema_migrations
     where version = '20260817000000';

   - 1 row  → services Task 1 has run. You are clear. Nothing to do.
   - 0 rows → STOP AND ASK THE OWNER. Do not push, do not park, do not
              hand-insert a schema_migrations row. The services session runs
              its Task 1 first; it is an S-sized task and you wait for it.

   Do NOT apply it yourself even if you find it unapplied — it is the services
   project's first unit, with its own KV re-warm and pg_depend verification steps.

3. Task 1's inventory sweep. Slice 7 may have absorbed part of Task 2 — if the
   ShopController fallbacks are already gone, tick Task 2 with their commit sha rather
   than redoing it.

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

PHASE GATES — stop at each, do not run the whole plan in one push

Phases 1, 2 and 3 each end in a gate task: full suite serial, pest --parallel
--processes=4, composer test:pg, PHPStan at `php -d memory_limit=1G ./vendor/bin/phpstan
analyse` (the default OOMs and reports it as "severe errors"), php artisan pint, then
merge to development and deploy dev. Phase 1 is additive — nothing calls stores() yet —
so that deploy changes no behaviour and is safe to take early.

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
- If reality diverges, you raise — you do not quietly widen scope. site.services is not
  yours no matter how tempting it looks while you are in these controllers.

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
- Checkpoint into the parent convergence spec, recording: the two tables gone, the
  services view migration parked and where, and that production still carries the
  legacy schema.
```
