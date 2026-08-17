# Production schema reconciliation — the follow-up slice 7 owes

Written 2026-08-17 as slice 7 Phase 6's closing deliverable (kickoff step 9).
Nothing here has been executed. **Production has not been touched by any part of
the content-pool convergence programme.**

## 1. Why this exists

Slice 7 dropped five tables on **dev only**, by owner decision on 2026-08-12 and
restated at every gate since. The reasons that decision was right are exactly the
reasons this document has to exist before anyone repeats the teardown on prod:

- Prod is **hundreds of commits behind** `development`.
- Prod's schema **diverged from the 2026-07-26 baseline**: dev has taken many
  migrations since that were never applied to prod. Parity must be *verified
  against the ref*, never assumed.
- **Prod DB access was unconfirmed** on 2026-08-12 and has not been confirmed
  since. The `app_backend` role is created `NOLOGIN` by the v2 baseline, and the
  real password's location is not documented anywhere — every reference in
  `docs/deploy/` is a placeholder.

> An irreversible teardown must not be the operation that discovers a migration
> gap on a database nobody could read.

## 2. The state to reconcile

**Dev**, as of 2026-08-17 after Phase 6:

- Migration ledger ends at `20260817001100_delete_legacy_event_item_slugs`.
- Dropped: `site.menu_item_categories`, `site.menu_item_platforms`,
  `site.menu_items`, `site.menu_categories`, `site.content_selection`.
- `site.item_slugs` retains 293 `menu_item` rows, 0 `event` rows, and has **no
  writer at all** — a write-free orphan.
- Still present and still legacy: `site.services`, `site.service_categories`,
  `site.service_category_assignments`, `site.shop_brands`, `site.shop_products`.
- One migration is **written, committed and deliberately unapplied**:
  `20260817000000_public_site_payload_services_from_content.sql`. See §4 — it is
  a live footgun, not an oversight.

**Prod**: unknown in detail, and that is the finding. `core.users` = 0 (no
customer data), which is the one fact that makes this tractable.

## 3. Order of work

1. **Establish access first, and prove it with a read.** `ALTER ROLE app_backend
   WITH LOGIN PASSWORD …` then `SELECT count(*) FROM core.users;`. The LOGIN
   grant and the schema grants are separate things; only the second proves the
   app can work. If the secret cannot be found, **stop here** — everything below
   is unreachable and the rest of this plan is theory.
2. **Diff the ledgers**, not the schemas: `supabase_migrations.schema_migrations`
   on both refs. The gap is the work.
3. **Diff the actual catalogs too.** The ledger records intent; `pg_class` /
   `information_schema` record reality, and the 2026-08-17 MCP drift (§4) is
   proof the two can disagree. Reconcile on the catalog, not the ledger.
4. **Decide the strategy, and it is probably not "replay the gap".** Prod carries
   no customer data. A from-zero apply of `supabase/migrations/*.sql` into a
   fresh prod ref is very likely cheaper and far more verifiable than replaying
   hundreds of migrations against a diverged schema. `scripts/db/fresh-reset.sh`
   proves a from-zero apply locally first — that rehearsal is not optional.
5. **Only then** consider the teardown on prod, and only after the deferred
   tables (§5) are re-homed, so prod never has to run the two-step dev did.

## 4. The unapplied migration — read this before any `db push` against any ref

`supabase/migrations/20260817000000_public_site_payload_services_from_content.sql`
rewrites the `site.public_site_payload` VIEW so its `services` key reads
`content.*` instead of `site.services` / `service_category_assignments` /
`service_categories`.

It is **correct and proven** — diffed old-vs-new across all 22 published sites
with zero differences, ordering included — and it is **applied nowhere**. Phase 6
deliberately left it pending, because the three service tables it decouples the
view from were themselves deferred, so applying it would have been a live change
to the KV render payload (`services[].id` moves from `site.services.id` to
`content.items.id`) buying nothing on the day.

**The footgun:** its version (`…000000`) sorts BEFORE the five drop migrations
that ARE applied. A later `supabase db push` against dev will see it missing and
apply it — out of band, unannounced, changing the payload the Cloudflare Worker
serves for every sitepage. It belongs to the services re-home; whoever runs that
should apply it as their first step, deliberately. Anyone running `db push`
before then should know it is in the queue.

## 5. What prod must NOT inherit

Prod should never be walked through dev's two-step. Four tables are still legacy
on dev and are planned work:

- `site.shop_brands` + `site.shop_products` —
  `docs/superpowers/plans/2026-08-17-shop-brands-rehome.md`.
- `site.services` ×3 — that plan's spec §11, scoped but not planned. The Fresha
  read half has never been cut over (~30 query sites, a public booking surface).

Reconcile prod **after** those land, so it takes one consistent teardown rather
than dev's two.

## 6. The lesson this slice paid for, restated for prod

Dev's coverage gate was green on 2026-08-16 and red on 2026-08-17, because a
scrape ran in between and minted 23 legacy rows with no `content.*` twin. Net row
counts FELL while uncovered rows appeared, so the totals concealed it.

**On prod the equivalent gate must be taken inside the same window as the DROP,
after the write lanes are dead — not the day before.** Prod carries no customer
data today, which makes the same mistake cheap; that will not be true forever,
and this is the paragraph to re-read when it isn't.
