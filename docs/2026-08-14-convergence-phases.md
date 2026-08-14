# Convergence — phase detail

Companion to `2026-08-14-convergence-RUNBOOK.md` (conventions, constraints,
baseline) and `convergence-log.md` (findings, decisions).

Phases 1–2 are executable detail. Phases 3–8 are settled at decision level;
their implementation detail is written immediately before each runs, because a
plan written before Phase 1 executes is stale by Phase 4.

---

## Sequencing correction (found while planning)

**The `channel` kind cannot be retired in Phase 1.** Spotify and SoundCloud
still produce it until Phase 4 converts them to `track`. Retiring it early
leaves a broken interim where two live connectors project into a kind the
registry no longer declares.

`ChannelCardProjector`'s only consumers are twitch/skool/strava — all demoted
in Phase 1 — so **the projector dies in Phase 1, the kind dies in Phase 4.**

`article` has exactly one producer (Substack), demoted in Phase 1, so the
`article` kind retires cleanly in Phase 1.

---

# Phase 1 — Lean out + `commerce_probe` fix

**Goal:** delete provably dead code and one live bug, shrinking the surface
every later phase operates on.

**Halts the run on failure** (foundational).

## 1.1 — `commerce_probe` constraint (do first; independent of everything else)

`CommerceProbeJob::ORIGIN = 'commerce_probe'` violates
`source_intents_origin_check`. Every probe that resolves a store throws 23514
at `SourceReconciler.php:181`.

- Migration: `supabase/migrations/<ts>_source_intents_allow_commerce_probe.sql`
  ```sql
  ALTER TABLE routing.source_intents DROP CONSTRAINT source_intents_origin_check;
  ALTER TABLE routing.source_intents ADD CONSTRAINT source_intents_origin_check
    CHECK (origin = ANY (ARRAY['paste','website_import','link_in_bio',
      'bio_harvest','google_business','staff','reproject','commerce_probe']));
  ```
- Test first: a Pest test asserting `SourceReconciler` can write an intent with
  `origin='commerce_probe'`. Must fail before the migration, pass after.
- **Live gate:** after applying, assert on dev that an insert with
  `origin='commerce_probe'` succeeds.
- Commit alone — it is a bug fix, unrelated to the demotions.

## 1.2 — Demotions

Delete, per platform: the connector class, its `ConnectorRegistry` line, its
`ProjectorRegistry` entry, its `SourceProvisioner::identifierFor()` branch,
and its `PlatformRegistry` fetch wiring — leaving a `PD::linkOnly()`
registration plus its detector so the platform still connects as a link.

| Platform | Connector | Projector | Notes |
|---|---|---|---|
| twitch | `TwitchConnector` | `ChannelCardProjector` (channel), `TwitchVodProjector` (video) | vods never worked — `unavailable` on all 4 sources |
| skool | `SkoolConnector` | `ChannelCardProjector` | 0 sources, 0 items |
| strava | `StravaConnector` | `ChannelCardProjector` | 0 sources, 0 items |
| gumroad | `GumroadConnector` | `GumroadProductProjector` | real `product` capability, zero adoption |
| substack | `SubstackConnector` | `SubstackArticleProjector` | 1 `article` item |

Then delete `ChannelCardProjector` and `TwitchVodProjector` (no remaining
consumers).

**Tests to delete** in `tests/Unit/Ingest/ProjectionTest.php`:
- "projects a substack post into an article"
- "projects a normalized account card into a channel item for every og-scrape source"
- "projects a twitch vod as a video with duration and the vod embed variant"

Keep (still live until Phase 4): the two Spotify and one SoundCloud channel tests.

## 1.3 — Retire the `article` kind

Substack was its only producer.
- Remove `'article'` from `KindRegistry::KINDS`.
- Delete the 1 orphan `article` row and its `source_items`/facets rows on dev.
- **Do NOT narrow the DB CHECK domain** — see log F9. Leave a comment in
  `KindRegistry` recording that the DB domain is a permissive backstop.

## 1.4 — Delete the `profile_fields` seam

Three connectors declare a `profile` stream targeting `profile_fields`, which
`ProjectorRegistry` defers to "field bindings (plan §14)" — a layer that does
not exist anywhere in `app/`. Their output is discarded; `IdentitySync` reads
the connection payload, not the stream.

- Remove the `profile` stream from `FreshaConnector`, `GoogleBusinessConnector`,
  `InstagramConnector` manifests, and their `profileMessages()` methods.
- Remove `'profile_fields'` from `StreamSpec`'s documented target values.
- Update `ProjectorRegistry`'s header comment — delete the field-bindings claim.
- Tests: `tests/Unit/Ingest/ProjectionTest.php`,
  `tests/Feature/Ingest/InstagramConnectorTest.php`,
  `tests/Feature/Ingest/LanderTest.php` reference `profile_fields` — update.
- **`IdentitySync` and `site.workplaces` are untouched.** They are the identity
  implementation and the identity store; neither is legacy.
- Retire the now-dead `ingest.streams` rows for those `profile` streams on dev.

## 1.5 — NOT in this phase (reversed)

**`document` kind is not deleted** — see log F8. There is a live user-documents
feature (`site.site_media` pool='documents', `UserDocumentController`), making
`document` the third instance of the declared-kind-awaiting-a-pool pattern,
alongside `link` and `menu_item`. Needs an owner decision: leave legacy, or add
a documents pool in Phase 3.

## Exit criteria (evidence, not assertion)

- [ ] Full suite green
- [ ] `commerce_probe` intent write succeeds against dev
- [ ] `article` absent from `KindRegistry`; 0 `article` rows in `content.items`
- [ ] `ChannelCardProjector`, `TwitchVodProjector` and the five connectors deleted
- [ ] No `profile_fields` target anywhere in `app/`
- [ ] `content.f_channel` still 9 (spotify/soundcloud still produce channel — correct until Phase 4)

---

# Phase 2 — Identity keys

**Goal:** make cross-source merging actually happen.

**Halts the run on failure** (foundational — later phases would produce
duplicate-laden data that looks correct).

## What this is NOT

Not a build. `App\Content\Identity\Resolver` is complete, pure, and covered by
**21 unit tests** (`tests/Unit/Content/ResolverTest.php`) that already pin
joining/corroborating/evidential semantics, cross-source rules, poisoning,
kind-scoping, user decisions and idempotence. It is fully wired into
`ProjectionWriter::resolveItems()` and runs on every projection.

The entire gap is that `ProjectionWriter::writeIdentityKeys()` (line ~490)
emits **2 of 17** key classes: `platform_object` (embeds the platform, so it
can never match cross-source by construction) and `canonical_url` (never
matches across platforms). Joining can union nothing; Corroborating and
Evidential are empty. Hence `item_merges` = 0, `identity_candidates` = 0.

## 2.1 — Emit the missing keys

Extend `writeIdentityKeys()` to derive, where the projection carries the data:

| KeyClass | Tier | Kinds | Source in projection |
|---|---|---|---|
| `Isrc` | Joining | track | `f_catalog` / connector-supplied |
| `Gtin14` | Joining | product | `f_catalog` |
| `FeedGuid`, `EnclosureUrl` | Joining | episode | feed record |
| `ContentDigest` | Joining | media, document | media fingerprint |
| `TitleRelease` | Corroborating | release, track | headline + release |
| `TitleDuration` | Corroborating | track | headline + `f_duration` |
| `TitleOnly` | Corroborating | video, track, release, episode, article | headline |
| `OfferingName`, `OfferingNameInCategory` | Corroborating | menu_item, service | headline (+ collection) |
| `EventOccurrence` | Corroborating | event | `f_occurrence` |
| `NamePriceBand`, `TitleLoose`, `AuthorDateBody` | Evidential | various | — candidates only |

Each key class already declares its own `kinds()`, `minLength()` and
`canonicalise()` — emission must respect `appliesTo($kind)` and skip values
below `minLength()`. No new semantics; only derivation.

## 2.2 — Tests (emission, not resolution)

New file `tests/Unit/Ingest/IdentityKeyEmissionTest.php`. Resolution is already
covered; this asserts **which keys a projection produces**:
- a `track` projection with an ISRC emits an `Isrc` key
- a `track` projection without one emits no `Isrc` key
- a `menu_item` emits `OfferingNameInCategory` when it has a collection, and
  `OfferingName` when it does not
- a title shorter than `minLength()` emits no title key
- a key class is never emitted for a kind it does not declare

## 2.3 — Live verification (the real gate)

Green Pest proves nothing about Postgres.

```bash
php artisan ingest:project --dry-run          # baseline: 47 streams, 586 records
php artisan ingest:project --rebuild
```

Then assert on dev:
- `content.identity_keys` shows **more than 2 distinct `key_class` values**
- `content.item_merges` reviewed **row by row**, not counted — every merge must
  be defensible. A wrong merge is worse than no merge: `mergeInto()` hard-deletes
  uncurated losers.
- `content.identity_candidates` populated (evidential pairs surfacing for review)
- `content.items` total has not collapsed unexpectedly

**If any merge looks wrong, halt.** Do not proceed to Phase 3 on a bad identity
layer.

## Exit criteria

- [ ] Full suite green
- [ ] >2 key classes present on dev after `--rebuild`
- [ ] Every `item_merges` row individually inspected and defensible
- [ ] `content.items` count explained (a drop = merges; must reconcile exactly)

---

# Phases 3–8 — settled decisions, detail deferred

**3 — Pools: menu + links.** No documents pool (owner, 2026-08-14 — documents
have no platform source; upload-only). Reviews already landed in slice 6, so
these two are all this programme owes.

Use **`jhunter7333`'s reviews-pool commit `8dd1ff989` as the template** — it is
`PoolRegistry` config only (`POOLS`, `PAGE_KEYS`, `PAGE_LABELS`,
`SECTION_SHAPE`) plus one test, confirming no migration is needed (F12/F14).
Copy the shape of his capability flags (`allowsPin()`, `allowsManualAdd()`)
where menu/links need equivalents.

**Trap:** the pool test MUST go in `tests/Feature/Content/` —
`AuditPipelineIntegrityTest` fails any new `tests/Feature` child directory that
`codebase_chunks()` does not map.

Then: migrate 318 `site.item_slugs` → `content.item_slugs` (**verified
collision-free**, F11 — 0 retired today, cheapest it will ever be); re-home slug
allocation off `MenuItemObserver`, which dies with the table; move the 41
`partna.*` connections into the links pool.

**4 — Listen sourcing.** Provision `youtube_music` (free, keyless RSS, the only
real `track` producer, never provisioned). Select and validate Apify actors for
Spotify/SoundCloud; write connectors + `track` projectors; delete
`SpotifyChannelProjector` and `SoundcloudChannelProjector`; **retire the
`channel` kind here** (last producers gone). Dedup across all three relies on
Phase 2 keys.

**5 — Live verification (spends money).** `ingest:backfill-sources` provisions
uber_eats/doordash/square; dispatch; project. Gate:
`content.source_items` kind=`menu_item` > 0 (currently 0 — `MenuItemProjector`
has never run for anyone, including reachable Square).

**6 — Pseudo-platform retirement.** Six categories stop being connectable;
`PlatformCategory` stays as grouping metadata. Promote `uber_eats`/`doordash`/
`menulog` to real surfaces. Gating unaffected — `RoutingCapabilityGate` keys on
`routing_class`, which travels with `surface_key`.

**7 — Cutover + teardown. RECONCILE, DO NOT RE-DESIGN.**

`jhunter7333` already owns this lane:
`docs/superpowers/plans/2026-08-12-slice-7-teardown-KICKOFF-PROMPT.md`. Fold
the items below into that plan rather than running a competing Phase 7.

Adopt from his kickoff: **"no slice may cite another slice's checkpoint as
evidence for its own claims — re-run each coverage assertion yourself."**

Two corrections his kickoff needs (F16/F17):
- It says *"Supabase is on the Free plan: no PITR, no managed backups"*. The
  owner **upgraded to Pro on 2026-08-14** — daily backups exist. The `pg_dump`
  gate stays the surgical control regardless.
- Its **Gate 2 (frontends must consume `pools.media` first) is overridden.**
  The gate is genuinely unmet — `apps/pages` still reads `designMedia`, nothing
  reads `pools.media` — but the owner has ruled the **frontend may break and
  will be REBUILT afterwards**. So there is no wire compatibility to preserve:
  delete the legacy wire keys outright (`designMedia`, `gallery`, `siteImages`
  and the rest of slice 1a's compatibility surface) rather than dual-serving.

Mechanics: 7a `pg_dump` → 7b assert dumped rows == live rows per table (verified
working today, 318/44/402) → 7c flip reads, drop tables, close the
`site.content_selection` write path.

Also in scope: **delete `LegacyServiceSortOrder`** (manages
`site.services.sort_order`, dies with that table) and **the 11 legacy event
slugs** left behind by slice 2's dual-write (F11, owner-approved).

**8 — Documentation truth pass.** Rewrite root + backend `CLAUDE.md`; correct
the convergence spec's stale figures; `docs/wire-changes/` entries per contract
change; record access/logistics so this research is never repeated.

Since the **frontend is being rebuilt, not repaired** (owner, 2026-08-14), this
pass must describe the **new wire on its own terms** — not as a diff against a
legacy shape nobody will implement again. It is the input to the frontend
rebuild, so it carries more weight than a normal docs pass: the pool payloads,
the 9-pool model, kinds/facets, and the platform→source→pool chain all need to
be readable by someone with no memory of the legacy system.
