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

---

## 2026-08-14 · Phase 1 EXECUTED (branch `feat/phase-1-lean-out`, dev only)

Doc hygiene (Unit 0) plus Phase 1 units 1.1–1.4. Checkpoint with live SQL is
parent spec **§20**. Counts: `content.items` 723 → 722, `ingest.streams`
59 → 49, `content.f_channel` unchanged at 9.

### F18 — `f_channel`'s 9 rows are NOT all spotify/soundcloud

The phases doc justified "f_channel still 9" with "(spotify/soundcloud still
produce channel)". Measured: **spotify 4 + twitch 4 + soundcloud 1**. So the
criterion holds only because twitch's landed items were LEFT IN PLACE. The
exit criterion and its own parenthetical explanation disagreed with the data;
the criterion was followed, the explanation is corrected here.

**Consequence for Phase 4:** retiring the `channel` kind must dispose of
twitch's 4 rows explicitly. They do not go away when spotify and soundcloud
convert to `track`, because twitch no longer has a connector to reproject.

### F19 — de-sourcing ≠ deleting; `auto_sync` is the existing retirement seam

The five demoted platforms keep their `ingest.sources` rows with
`auto_sync = false`. `SourceScheduler::scoreDue()` filters on that flag (so
nothing is ever claimed, and `RunSourceJob` never reaches the deleted
connector), and `IngestProjectCommand` `continue`s past any stream with no
projector (so a `--rebuild` does not drop their content). This was chosen over
deleting the rows precisely because deletion would have taken twitch's 4
`f_channel` items with it — see F18. Reversible: flip the flag back.

### F20 — the profile_fields seam landed 6 records and 0 items, confirming F15

Measured before deletion: 10 `profile` streams (fresha 4, google_business 4,
instagram 2), 6 `record_state` / 6 `record_versions` between them, and
**0 `content.source_items`**. The seam cost fetches and produced nothing, for
months. Deleted with its rows. `IdentitySync` and `site.workplaces` untouched.

### F21 — a domain guard that reads ONE migration by name goes stale silently

`SourceIntentDomainTest` extracted the origin domain from
`20260727120000_routing_schema.sql` alone. Unit 1.1's superseding `ALTER`
would have left it comparing the original text — passing while live and test
diverged. This is F9's failure mode, live, in a second guard. Fixed by
resolving the *effective* domain (newest `ADD CONSTRAINT` for the column,
inline declaration as fallback). **`ContentKindDomainParityTest` still has the
unfixed version of this problem**, which is exactly why Phase 1 did not narrow
the kind CHECK.

### F22 — the two drivers disagree about CHECK violations

Postgres raises 23514 on the intent insert. SQLite does not: the insert goes
through `insertOrIgnore()`, and `INSERT OR IGNORE` swallows CHECK violations
as well as unique ones, so the row is silently skipped and it is
`SourceReconciler`'s own "Could not upsert source intent" invariant that
throws. A regression test phrased as "does not throw" would be testing two
different mechanisms; assert the ROW EXISTS instead.

### Decision — the `PD::linkOnly()` half of 1.2 was NOT executed

Raised for an owner ruling rather than done; full reasoning in spec §20's
final section. In short: gumroad and substack were already `linkOnly`;
converting twitch/skool/strava would delete live user-facing connect flows,
resources and routes (and twitch's live-status lane) behind **7 live
connections**, which Phase 1 does not name and which is a product decision.
The ingest goal is met regardless — none of the five can produce a
`content.item` any more. Recommendation: fold it into Phase 6.

### F23 — `GET /api/content/kinds` does not exist and never did

`KindRegistry`'s docblock described itself as "the wire behind
`GET /api/content/kinds` (plan §6/§16)". Verified 2026-08-14: no such route in
`routes/`, and a live 404 on dev. The endpoint was planned and never built.
The registry's only caller in `app/` is `SectionRuleRules`, which uses `has()`
to reject a section rule naming an unknown kind.

Consequence: retiring a kind from `KindRegistry` is **not** a public-wire
change, so Phase 1 unit 1.3 needs no wire manifest. It is the same defect
class as the `profile_fields` seam (F20) and the ProjectorRegistry
field-bindings sentence — a document outliving its subject — and it is why
Phase 8's documentation pass is worth doing properly.

### F24 — Phase 2's identity baseline, re-measured at Phase 1 exit

The RUNBOOK records `content.identity_keys` = 1264 (platform_object 707 +
canonical_url 557). Measured 2026-08-14 after Phase 1:

```
identity_keys 1233 | distinct key_class 2 (canonical_url, platform_object)
item_merges 0 | identity_candidates 0 | content.items 722
```

Phase 1 accounts for only 2 of the 31-row difference (the `article` item's
keys). The rest moved between the RUNBOOK baseline being written and now —
other sessions' projections landing, not this phase. **Do not reconcile Phase 2
against 1264.** The numbers above are the verified entry state.

The premise Phase 2 rests on is intact and re-confirmed: still exactly **2 of
17 key classes**, `item_merges` and `identity_candidates` both still **0**, so
the merge engine has genuinely never run.

### F25 — identity resolution is kind-scoped, so the `link` fold cannot fire

`Resolver::mayUnion()` sanctions exactly one cross-kind union: a `link` is an
unidentified thing, so anything may absorb it (a pasted URL later identified
as a track folds into it). **That path is unreachable through
`ProjectionWriter`.** Both callers of `resolveItems($userId, $kind)` —
`projectStream()` at :243 with `$projector::kind()`, and `writeManualItem()` at
:410 with the projection's own kind — scope the resolution to ONE kind, so a
`link` source item and a `track` source item are never in the same `resolve()`
call to begin with. `PoolItemCreateController`'s kind-change refusal already
records the same fact from the other side.

Two consequences, neither a defect today:

- It is what makes emitting `TitleOnly` safe at all: a podcast episode and a
  YouTube video sharing a title cannot fuse, because they are resolved in
  separate passes. The kind gate inside `mayUnion()` is belt to that braces.
- **The custom links pool inherits it.** A `link` item minted by that pool will
  NOT fold into a synced item of another kind, however exactly the URLs match,
  until something resolves across kinds. Worth knowing before the pool promises
  otherwise; the fix, if it is ever wanted, is a resolution pass scoped to the
  user rather than to (user, kind), not a change to the key set.

### F26 — `ASCII//TRANSLIT` made identity keys platform-dependent

`KeyClass::normalizeText()` transliterated via `iconv('UTF-8',
'ASCII//TRANSLIT')`, which delegates to the C library rather than to PHP.
Measured on both, 2026-08-14:

```
                macOS libiconv        Cloud (glibc, POSIX locale)
Beyoncé      => 'beyonc\'e'        => 'beyonc?'
Björk        => 'bj"ork'           => 'bj?rk'
Straße       => 'strasse'          => 'strasse'
```

After the `[^a-z0-9]+` pass that is `beyonce`/`bjork` locally against
**`beyonc`/`bj rk`** on dev — the same title yielding different identity keys
depending on which machine derived it, and on Cloud yielding a mangled one
(`bj rk` is two tokens, so it can never match `bjork` from a second source).
glibc under the container's POSIX locale does not transliterate at all; it
substitutes `?`.

Inert before Phase 2, because `normalizeText()` had no caller that reached the
database — every text-derived key class was unemitted. Emission made it
load-bearing, so Phase 2 fixes it, in two passes and worth recording as two:

1. **First attempt (wrong):** delete the modifier marks iconv emits, on the
   evidence of the macOS output alone. That converges macOS on `beyonce` and
   does nothing at all for glibc's `?`. It shipped, and the dev rebuild is what
   exposed it — `title_release` values read `…|beyonc`. **A live assertion
   caught what the whole test suite could not, which is invariant #1 earning
   itself rather than being observed.**
2. **The fix:** an explicit `TRANSLITERATIONS` table on `KeyClass` covering
   Latin-1 Supplement and Latin Extended-A, replacing the iconv call outright.
   No C library, no locale, identical on every platform, and pinned by
   `IdentityKeyEmissionTest`'s accent cases so a future edit fails a test
   instead of a database.

Two consequences recorded rather than fixed: anything outside Latin (Cyrillic,
CJK, emoji) still collapses to spaces, which is what glibc already did — a
Cyrillic title is a singleton, not a merge candidate; and `don't` now folds to
`dont` rather than `don t`, deliberately, because a contraction spelled two
ways is one thing.

### F27 — the pool lane minimises link URLs; the legacy custom-link payload does not

`ProjectionWriter::URL_COLUMNS` lists `f_link.url`, so every URL landing on the
content lane passes through `SecretParams::minimiseUrl()` (#PRIV-5) and any
tracking- or secret-named query param is stored as `key=[redacted]`. The legacy
`site.platform_connections.payload` stores whatever the owner pasted, verbatim.

Measured against the 23 live custom links, 2026-08-15: exactly **one** URL
differs between the two lanes —

```
https://youtube.com/@gsnwilliams?si=XEbw1D8mHVXbiY43
  → https://youtube.com/@gsnwilliams?si=[redacted]
```

`si` is YouTube's share-tracking param and the destination ignores it, so the
migrated link resolves. Recorded rather than fixed, for three reasons:

- It is the deliberate, uniform behaviour of the whole content lane, not
  something the custom-links pool introduced. Exempting `link` would make it the
  one kind whose URLs are not minimised.
- **The coord is derived from the RAW url**, before minimisation, so coverage
  and the hand-add fold-in are unaffected: an owner re-typing the full URL lands
  on the same `manual:{sha1}` and therefore the same item.
- It is only visible while the two lanes run side by side. Phase 6 retires the
  legacy copy, and the question disappears with it.

The trap for a later phase: a custom link whose query carries something
LOAD-BEARING and tracking-named would break on the pool lane while still working
on `/integrations` — and it would break silently, because both lanes publish. If
one ever turns up, the fix is that param's classification in `SecretParams`, not
an exemption for the pool.

### F28 — the custom-link coord and the connection `resource_id` share a hash basis

Noticed while pre-computing the expected coords for the live gate, then
confirmed in code rather than inferred from samples:

```
CustomLinksController::add():101   $rid   = 'link-'  .substr(sha1(strtolower($url)), 0, 16)
CustomLinkBackfiller::run()        $coord = 'manual:'.sha1(strtolower(trim($url)))
```

The connection's `resource_id` is the first 16 hex characters of the coord's
hash. Nothing coordinated this — the coord convention was chosen to match
`PoolItemCreateController`'s hand-add lane (so a re-typed link folds onto the
migrated item), and it landed on the same basis the live write path had been
using since custom links were built.

Two things follow.

**Phase 6 gets a free join.** A migrated item can be traced back to the
connection that produced it by string prefix —
`resource_id = 'link-' || substr(coord, 8, 16)` — with no lookup table and no
new column. That is exactly what Phase 6 needs when it retires a connection and
has to find the item standing in for it. **Pinned by a test**
(`CustomLinkBackfillerTest`, "shares a hash basis with the connection
resource_id"), because it is a coincidence of two independent derivations that
neither side declares as a contract: change the basis on either and the join
breaks silently, at the moment nobody is testing it.

**The duplicate-URL dedupe is live defence, not dead code.** It reads at first
glance as redundant — `idx_platform_connections_unique_active` is UNIQUE on
`(user_id, surface_key, resource_id) WHERE deleted_at IS NULL`, and
`resource_id` is URL-derived, so one owner cannot hold two live links on the
same URL. But the controller derives it WITHOUT trimming while the backfiller's
coord trims, so `'  url  '` and `'url'` are two permitted live connections that
collapse to ONE coord. Without the dedupe those two rows would write the same
coord twice in one run and count as two links, making the coverage gate
unreconcilable. The test reproduces it with the whitespace pair rather than an
impossible exact-duplicate pair.
