# Convergence — running log

Append-only. Decisions are recorded **before** acting on them. Context
compacts; this file does not.

---

## 2026-08-14 · Planning session (owner asleep, execution deferred)

### Status: PLANNING COMPLETE, EXECUTION NOT STARTED

Branch `feat/platform-pool-convergence` exists, rebased onto
`origin/development` (which had moved 10 commits). **No code changed.**

### Why execution is deferred

`jhunter7333` pushed 10 commits between 00:33 and 01:54; it is now 03:22.
That cadence (5–20 min apart) reads as an active session, not an end-of-day
push. Their work lands in `app/Ingest/ProjectionWriter.php` and
`app/Ingest/SourceProvisioner.php` — two of the four files Phase 2 depends on.

Their work is **complementary, not contradictory**: services (slice 3)
hardening — `LegacyServiceSortOrder` (fixes a live 500 from a global
`services_user_sort_order_uq`), reorder consolidation, cache-lane
invalidation, and a genuinely useful fix letting Fresha `book-now` share URLs
provision a source.

One expiry note: `LegacyServiceSortOrder` manages `site.services.sort_order`,
and Phase 7 drops `site.services`. It is not wasted work — it fixes a real bug
today — but Phase 7 must **delete** it rather than port it.

**Morning action:** confirm that session is finished, or carve lanes
explicitly (suggested split: this run takes ingest identity keys + menu/links
pools + teardown; the other keeps services).

---

## Findings that changed the plan

### F1 — The merge engine is built, tested, and starved (not missing)

`App\Content\Identity\Resolver` is complete and pure: joining → user-`same` →
user-`different` cut → corroborating (cross-source only) → evidential
candidates, with poisoned-key detection. **21 unit tests** in
`tests/Unit/Content/ResolverTest.php` already pin exactly the semantics we
need, including cross-source corroboration and category-corroborated short
dish names.

It is fully wired: `ProjectionWriter::resolveItems()` (line ~628) reads
`content.identity_keys`, builds `SourceItem`s, calls `Resolver::resolve()`,
then `bindGroup()` and `recordCandidates()` — on every projection.

`ProjectionWriter::writeIdentityKeys()` (line 490) emits exactly **two** of
seventeen `KeyClass` values: `platform_object` (which embeds the platform, so
it can never match cross-source by construction) and `canonical_url` (which
never matches across platforms). Therefore the Joining tier can union nothing,
and Corroborating/Evidential are empty — which is why `item_merges` = 0 and
`identity_candidates` = 0.

**Consequence: Phase 2 is one method plus emission tests, not a build.**
Materially smaller than the scope doc assumed.

### F2 — `writeIdentityKeys()` survived the 10 new commits unchanged
Verified post-rebase: still two key classes, moved 480 → 490. Phase 2's
analysis holds against current `origin/development`.

### F3 — Phase 7's dump gate works today
Proven against real tables: `site.menu_items` 318, `site.menu_categories` 44,
`site.menu_item_categories` 402 — all exact matches to live, 200K dump with
schema and data. The gate is a real assertion that passes now.

### F4 — Two 3am traps, already paid for once each
- `pg_dump` is **not on PATH** → `/opt/homebrew/opt/libpq/bin/pg_dump`
- The DB password must come from Laravel config, never shell-parsed from
  `.env` — shell parsing auth-failed while Laravel connected fine.

### F5 — `commerce_probe` is a live bug (fix in run)
`CommerceProbeJob::ORIGIN = 'commerce_probe'`, but
`source_intents_origin_check` allows only
`paste, website_import, link_in_bio, bio_harvest, google_business, staff,
reproject`. Every probe that resolves a store throws `23514` at
`SourceReconciler.php:181`. The probe itself succeeds; only the intent write
fails. 2 occurrences in 24h, 1 user affected.

### F6 — Nightwatch #427 was already fixed; do not act on it
`products_curated_at` ambiguity was fixed in `fb8491bfc` (2026-08-13 08:17),
ten hours after the exception fired. Lesson: Nightwatch shows history —
always check whether a fix already landed before acting.

### F8 — CORRECTION: do NOT delete the `document` kind (needs owner decision)

I earlier recommended deleting `document` as "0 items, no producer, unrelated
to `site.site_documents`". The first half was right — `site.site_documents` is
the built-sitepage JSON cache, not user files. **The recommendation was still
wrong.**

There is a real user-documents feature: `site.site_media` with
`pool='documents'` (2 rows on dev), managed by
`App\Http\Controllers\Api\User\Account\UserDocumentController` via
`SiteMedia::POOL_DOCUMENTS`.

So `document` is the **third instance of the same pattern** as `link` and
`menu_item`: a declared `KindRegistry` kind whose data currently lives in a
legacy store, awaiting a pool. It is not dead weight.

Documents have **no platform source at all** — verified: no connector among
the 26 streams targets `document`; `UserDocumentController` is upload-only. The
2 dev rows are hand-uploaded test files (a uni tutorial PDF, and — notably — a
PNG sitting in the documents pool, so the upload path does not constrain mime
type).

**OWNER DECISION (2026-08-14): no documents pool.** `site.site_media`
pool='documents' stays as it is. The `document` kind is **not deleted** either
(deleting a kind with a live feature behind it is the harder thing to undo);
it simply stays declared and unpooled.

Owner decisions #4 and #10 (`document` kind → delete) are superseded.

### F9 — Kind deletion: narrow the registry, NOT the DB CHECK domain

`content.source_items.kind` and `content.items.kind` each carry a DB CHECK
constraint listing all 14 kinds, guarded by
`tests/Postgres/ContentKindDomainParityTest.php`.

That guard **extracts the clause from two specific migration files by name**
(`20260729150000_source_items_kind_check_not_valid.sql` and
`20260727140000_content_schema.sql`) and asserts the two domains are
set-equal. A *new* narrowing migration would therefore not be read by it —
the test would keep comparing the original text and keep passing — while its
hardcoded `CONTENT_ITEMS_KIND_DOMAIN` (14 values) silently went stale. Fixing
that properly means rewriting the guard's extraction to understand superseding
migrations.

Verified separately: **nothing binds `KindRegistry` to the DB domain.**
`KindRegistry::kinds()` has no callers in `app/` or `tests/`.

**Decision:** when retiring a kind, Phase 1 will
- remove it from `KindRegistry` (the app-level truth),
- delete its projector/connector/registry entries (the real dead code),
- delete its orphan rows (9 `channel`, 1 `article`),
- and **leave the DB CHECK domain permissive**, documented as a deliberate
  backstop rather than the source of truth.

Rationale: removing an unused value from a CHECK domain buys almost nothing,
while the migration + guard-rewrite it forces is disproportionate churn and
risks leaving a guard that reads as authoritative but no longer is. A
permissive backstop with a narrow application registry is the safer asymmetry.

### F10 — Phase 2 is narrower than planned: two joining keys have NO data

`content.f_catalog` (84 rows) — the facet that would carry them:

```
isrc: 0    gtin: 0    sku: 51    handle: 51    vendor: 49
```

The columns exist; **no connector populates `isrc` or `gtin`**. Confirmed by
grep — the only mention in `app/Ingest/` is the facet's own column list.
`ApplePodcastsConnector` likewise carries **no guid/enclosure**, so `FeedGuid`
and `EnclosureUrl` are unemittable too.

So of the Joining tier, only `ContentDigest` is emittable — and it is
worthwhile: `content.media_assets` holds 914 assets, all fingerprinted, **716
distinct**, so ~198 are cross-source duplicates it would unify.

Emittable today, by data actually present:

| Key | Tier | Viable? |
|---|---|---|
| `ContentDigest` | Joining | ✅ 914 fingerprints |
| `TitleRelease` | Corroborating | ✅ release: 223 items, all with `f_published` |
| `TitleOnly` | Corroborating | ✅ all kinds have `f_text` |
| `OfferingName*` | Corroborating | ✅ service 80; menu_item after Phase 3 |
| `EventOccurrence` | Corroborating | ✅ 16 events, 16 `f_occurrence` |
| `TitleDuration` | Corroborating | ⚠️ track-scoped; **0 track items exist**; release/episode have 0 duration |
| `Isrc`, `Gtin14` | Joining | ❌ no data |
| `FeedGuid`, `EnclosureUrl` | Joining | ❌ no data |

**Consequence for Phase 4.** ISRC is the natural joining key for music. Without
it, Spotify↔SoundCloud↔YouTube-Music dedup falls back to `TitleRelease` /
`TitleOnly` — corroborating tier, so cross-source only, which is correct but
weaker. **When selecting Apify actors in Phase 4, prefer one that returns
ISRC**; that single field is the difference between reliable joining-tier
identity and title-matching.

Owner decision needed: extract ISRC/GTIN in this run (connector work, expands
Phase 2/4), or accept title-based dedup for now.

### F11 — Phase 3 slug migration is collision-free (and reveals a dual-write)

`site.item_slugs` UNIQUE `(user_id, slug)`; `content.item_slugs` UNIQUE
`(user_id, slug)` — **scopes match**, so the old kickoff's feared
silent-row-drop does not apply.

Checked for real collisions before migrating: **9 collide, and every one is
`item_type='event'`. No `menu_item` collides.** The 318-slug menu migration is
clean.

The collisions expose something else: **events dual-write slugs.** Slice 2
copied event slugs into `content.item_slugs` (16 rows) without removing the
legacy rows (11 remain). Phase 7 should delete the legacy event slugs.

### F12 — Phase 3 pool addition needs no migration

`PoolSectionProvisioner::ensure()` creates a pool's page + section lazily on
first read, idempotent under the `(site, key)` unique indexes. And there is
**no CHECK constraint on `site.pages.key` or `site.sections.key`** — verified
against `pg_constraint`. Adding `menu` and `links` is therefore
`PoolRegistry` config only (`POOLS`, `PAGE_KEYS`, `PAGE_LABELS`,
`SECTION_SHAPE`); the schema needs nothing.

The weight in Phase 3 is the **data** migration (318 slugs, menu_item rows),
not the pool wiring.

### F13 — Phase 7 read surface, enumerated

Files reading the legacy stores that Phase 7 retires:

- `site.menus` — `MenuController`, `MenuContentController`,
  `PublicMenuController`, `MenuScanApplier`, `MenuPayloadComposer`,
  `SitepageDataResolverService`
- `site.shop_products` — `ShopController` only
- `site.services` — `FreshaController`, `UserServiceController`,
  `StaffServiceManagementController`, `FreshaServiceProjector`,
  `UserCacheService`, `LegacyServiceSortOrder`
- `site.content_selection` — `ContentController`,
  `ReplaceContentSelectionRequest`, `GoogleBusinessService`,
  `IndividualProfilePayloadBuilder`, `ContentSelectionService`

Note `SitepageDataResolverService` and `IndividualProfilePayloadBuilder` are
**public-payload** readers — the cutover changes what sitepages serve, so they
are the two to verify hardest.

---

## 2026-08-14 midday — reconciled against 19 further commits from `jhunter7333`

### F14 — Slice 6 (reviews) has LANDED. Drop it from this scope.

`feat(pools): reviews pool — exclusion only, no pins, no manual adds` plus
`content.source_stats`, `f_review.author_uri`, reviewer-name PII purging, and
`feat(wire): retire the legacy review read`.

End-state pool count is unchanged at 9; **this programme now only owes `menu`
and `links`.**

His `PoolRegistry` diff is the **template for Phase 3** and confirms F12 —
pool addition is registry config (`POOLS`, `PAGE_KEYS`, `PAGE_LABELS`,
`SECTION_SHAPE`) plus one test, no migration. He also added capability flags
worth copying the shape of: `EXCLUDE_ONLY_POOLS`,
`MANUAL_ADD_FORBIDDEN_POOLS`, `allowsPin()`, `allowsManualAdd()`.

**Trap his commit message records:** the pool test MUST live in
`tests/Feature/Content/`. `AuditPipelineIntegrityTest` fails any new
`tests/Feature` child directory that `codebase_chunks()` does not map, and
`scripts/audit` belongs to another lane.

He also documented `channel` and `article` as "deliberately poolless", which
agrees with this programme retiring them.

### F15 — CORRECTION (his finding is better than mine): field bindings existed

I wrote that field bindings "do not exist — zero matches in `app/`". True of
today's code, wrong about why. Verified in migrations:

```
20260728150000_field_bindings.sql        built
20260805110000_drop_field_bindings.sql   deliberately dropped
```

His characterisation is the accurate one: *"The profile stream is not an
unfinished lane, it is a redundant one. Its intended consumer was deliberately
deleted."* He also measured it more precisely — **3 record_versions, 0
source_items**.

**The conclusion is unchanged and now independently corroborated:** delete the
`profile_fields` seam, keep `IdentitySync`, keep `site.workplaces`. His spec
reaches the same verdict and assigns it to `slice-7-teardown`.

### F16 — His `slice-7-teardown` kickoff already exists and overlaps Phase 7

`docs/superpowers/plans/2026-08-12-slice-7-teardown-KICKOFF-PROMPT.md`.
It is more rigorous than my Phase 7 in one respect worth adopting: **"no slice
may cite another slice's checkpoint as evidence for its own claims — re-run
each coverage assertion yourself."**

Two things in it are now stale:
- It states *"Supabase is on the Free plan: no PITR, no managed backups. A
  dropped table is gone."* **The owner upgraded to Pro on 2026-08-14** — daily
  backups now exist. The `pg_dump` gate (F3) remains the surgical control.
- Its Gate 2 is overridden — see F17.

**Phase 7 should not be re-designed here.** Hand this plan to him and let him
reconcile it with his own slice-7 plan; he owns that lane.

### F17 — Gate 2 is unmet, and the owner has overridden it

His kickoff gates the media half of teardown on both frontends consuming
`pools.media` first. Verified — **the gate is genuinely unmet**:

- `apps/pages` still reads `designMedia` in `content/types.ts`,
  `resolve-site-content.ts`, `lib/fetch-profile.ts`, `lib/site-render.ts`,
  `pages/[...path].astro`
- **nothing** consumes `pools.media` in either frontend

**OWNER DECISION (2026-08-14): the frontend is allowed to break, and will be
REBUILT afterwards — not repaired.** Gate 2 does not apply to this programme.

This is stronger than "breaking is tolerable" and simplifies Phase 7
materially: there is **no wire-compatibility to preserve**. Legacy wire keys
(`designMedia`, `gallery`, `siteImages` and the rest of the compatibility
surface slice 1a deliberately kept) can be **removed outright** rather than
maintained alongside `pools.media`. Phase 7 should delete them, not dual-serve.

Phase 8's documentation pass is what makes the rebuild plannable — it must
therefore describe the *new* wire accurately rather than diffing it against a
legacy shape nobody will implement again.

### F7 — Pre-existing dev noise, not caused by this run
`#371` cache-SLO violations, `#429`/`#430` Redis timeouts. Recurring on dev
before any of this work.

---

## Verified capability baseline (all tested 2026-08-14)

| Capability | State |
|---|---|
| Supabase MCP | dev `glncumufgaqcmqhzwrxm`, prod `edplucmvkcnokyygxqsb` (never touch) |
| artisan against dev | `ingest:project --dry-run` → 47 streams, 586 records, 0 failed |
| Apify | token valid (`partna`, Starter); actor `memo23~uber-eats-scraper` returned 29 items with the exact field shape `UberEatsMenuConnector` expects |
| Apify budget | $2.75 of $29 used; **run cap $18** |
| `pg_dump` | verified with real data (see F3) |
| Nightwatch | app `a1698025-90b3-426d-94ae-4b85ae5bb4c2` |
| `cloud env:logs` | works; `--minutes` window appears lagged |
