# Platform & pool convergence — SCOPE (2026-08-14)

Status: **scoping only.** No implementation decisions locked, no code changed
beyond one unrelated commit (`3c3ded765`, Uber Eats detector path pattern).

Every figure below was re-derived from the **dev database**
(`glncumufgaqcmqhzwrxm`) on 2026-08-14 and every mechanism was read from
source, not from plan documents. Where an existing spec disagrees, the
numbers here supersede it — see §7.

---

## 0. The one-paragraph problem

The new content architecture (`content.*` + `app/Ingest`) is built, live and
working for 14 sources. It was **never switched on** for everything else:
five domains write to both stores with legacy authoritative, one domain
(menu) never migrated at all, six "category" pseudo-platforms strand their
connections outside the pipeline entirely, and the cross-source identity
system emits 2 of its 17 key classes. Nothing was torn down. The result is
two parallel implementations of most content types, where both look correct
and only one is connected.

---

## 1. Verified state (dev, 2026-08-14)

### 1.1 Per-domain

| Domain | Legacy rows | `content.items` | Reality |
|---|---|---|---|
| **menu** | 318 items / 44 cats / 402 links / 5 menus | **0** | never migrated |
| shop | 51 products | 51 `product` | mirrored, legacy authoritative |
| services | 82 | 80 `service` | mirrored, legacy authoritative |
| media | 41 `site_media` | 67 `media` | landed |
| events | — | 16 `event` | landed |
| reviews | — | 15 `review` | slice 6 in flight |
| watch | — | 133 `video` | landed |
| listen | — | 223 `release`, 112 `episode` | landed |
| **links** | 23 live connections | **0** `link` | never migrated |

`ShopContentWriter` upserts **from** `site.shop_brands` **into** `content.*`.
Legacy is still the source of truth; `content.*` is a mirror. Services is the
same shape.

### 1.2 The three registries (distinct, frequently conflated)

| Registry | File | Purpose | Count |
|---|---|---|---|
| `PlatformRegistry` | `app/Providers/PlatformRegistryServiceProvider.php` | connect vocabulary — gives `/platforms/{key}/connect` | 40 keys |
| `ConnectorRegistry` | `app/Ingest/ConnectorRegistry.php` | ingest — `source_key` → Connector | 21 |
| `ProjectorRegistry` | `app/Ingest/Projection/ProjectorRegistry.php` | `source_key` + stream → Projector | 21 |

Plus the **catalog** (`app/Catalog/Definitions/*`, 108 files → compiled
artefact) which is classification/routing only, and `KindRegistry` /
`PoolRegistry` on the content side.

### 1.3 Kinds vs pools

`KindRegistry` declares **14 kinds**. `PoolRegistry` defines **6 pools**
covering `video, track, release, episode, media, event, service, product`.

Declared kinds with **no pool**: `menu_item`, `link`, `channel`, `article`,
`review`, `document`.

- `channel` is `SourceProfile::Identity` — an account card + player, not pool
  content. Correct by design (being retired anyway, see §3).
- `review` is slice 6's job.
- **`menu_item` and `link` are real gaps** — declared, data lives in legacy.

### 1.4 Ingest reality

50 `ingest.sources`, 56 streams, 171 runs, 614 `record_state`. **14 source
keys have sources.** Seven connectors exist but have **never been
provisioned**: `uber_eats`, `doordash`, `square`, `gumroad`, `skool`,
`strava`, `youtube_music`.

Items produced per surface:

| Surface | Items | Kind |
|---|---|---|
| apple_music.artist | 190 | release |
| apple_podcasts.show | 112 | episode |
| youtube.channel | 73 | video |
| vimeo.account | 59 | video |
| fresha.book | 59 | service |
| google_business.listing | 45 | media + review |
| bandcamp.artist | 33 | release |
| instagram.profile | 12 | media |
| eventbrite.organiser | 10 | event |
| humanitix.organiser | 6 | event |
| spotify / twitch / soundcloud | 4 / 4 / 1 | channel |
| substack.publication | 1 | article |

`content.items` holds **0 `track` items** — no track source has ever run.

### 1.5 The identity system is 2/17 wired

`KeyClass` declares 17 keys across three tiers (Joining / Corroborating /
Evidential), kind-scoped, with canonicalisation and min-length rules.

`ProjectionWriter::writeIdentityKeys()` emits exactly two:

```
platform_object  707      canonical_url  557      (= 1264, the whole table)
```

`item_merges` = 0. `identity_candidates` = 0. `identity_decisions` = 0.
**Cross-source merging has never executed.** Two items merge only on an exact
canonical-URL match, which never happens across platforms.

Unemitted and directly relevant: `Isrc` + `TitleDuration` + `TitleRelease`
(tracks/releases), `OfferingName` / `OfferingNameInCategory` (menu + service
name dedup — the behaviour legacy `MenuMerger` does by hand), `Gtin14`
(products), `FeedGuid` / `EnclosureUrl` (episodes), `ContentDigest` (media).

### 1.6 Pseudo-platforms

Six categories exist **twice** — correctly as `PlatformCategory` metadata,
and incorrectly as connectable platforms mapped to hidden surfaces:

```
custom        → partna.custom_link     (23 live connections)
online-ordering → partna.order_link    (10)
shop          → partna.storefront      (6)
reservations  → partna.reserve_link    (2)
booking       → partna.booking_link    (0)
events-custom → partna.manual_event    (0)
```

`SourceProvisioner::sourceKeyFor()` takes the brand prefix before the dot;
`ConnectorRegistry::has('partna')` is false. **41 live connections are
structurally invisible to ingest.**

Capability gating does **not** depend on these — `RoutingCapabilityGate`
keys on `routing_class`, which travels with `surface_key`. Retiring the
pseudo-platforms does not weaken gating.

### 1.7 Stream → target → pool, complete (26 streams / 21 connectors)

Every stream declared by every connector, read from source:

| Target kind | Connectors | Pool? |
|---|---|---|
| `video` | youtube, vimeo, twitch/vods | ✅ watch |
| `release` | apple_music, bandcamp | ✅ listen |
| `episode` | apple_podcasts | ✅ listen |
| `track` | **youtube_music** | ✅ listen |
| `media` | google_business, instagram | ✅ media |
| `event` | eventbrite, humanitix | ✅ events |
| `service` | fresha | ✅ services |
| `product` | gumroad | ✅ shop |
| `review` | google_business | ⚠️ slice 6 |
| **`menu_item`** | **doordash, square, uber_eats** | ❌ **no pool** |
| `article` | substack | ❌ no pool |
| `channel` | skool, soundcloud, spotify, strava, twitch | ❌ retiring (§3 W3) |
| **`profile_fields`** | **fresha, google_business, instagram** | ❌ **no implementation** |

### 1.8 `profile_fields` — declared, entirely unbuilt

Three connectors declare an Identity-profile `profile` stream targeting
`profile_fields`. `ProjectorRegistry`'s header says these "resolve through
field bindings (plan §14), not here."

**Field bindings do not exist.** `grep -rn 'field_binding|FieldBinding|
fieldBinding' app/` returns zero matches. So these streams run (google_business
`profile` health is `ok` on 3 sources) and their output goes nowhere.

The work is done today by legacy `App\Services\Platforms\IdentitySync`, which
folds a Google Business payload into `site.workplaces` + `core.users` mirror
columns, with account-type precedence
(`AccountCapabilities::google_business_full_sync`) and per-field provenance in
`workplaces.field_sources`.

This is the **third** instance of the same pattern (alongside identity keys
2/17 and the missing pools): the new architecture declares the seam, the
legacy implementation is what actually runs.

### 1.9 Legacy selection path still live

`site.content_selection` holds 95 rows and `ContentSelectionService` is still
written by `ContentController` and read by
`IndividualProfilePayloadBuilder`. `ContentSelectionMigrator` (slice 1b D10)
exists and deliberately migrates only uploads (3 of 89 at the time it was
written), dropping google-photo and ig-* refs for stated reasons. The legacy
write path was never closed.

### 1.10 Embeds

`f_embed` = 141 rows: **132 are YouTube/Vimeo `video` embeds** (inline
playback of sourced items — load-bearing for the Watch pool), 9 are
`channel` account players (spotify 4, twitch 4, soundcloud 1).

The facet, `FacetRegistry`, and `SectionCandidates`' `f_embed` mapping all
stay. Only the **account-player pattern** is removed. `KindRegistry` lists
`f_embed` among `track`'s facets, so per-track embeds remain available later.

---

## 2. Uber Eats — the worked example

Everything downstream already exists:

| Component | Status |
|---|---|
| Catalog surface `uber_eats.order` | ✅ (was `notConnectable()`) |
| Detector path pattern | ✅ added `3c3ded765` (32 → 79 confidence) |
| `ConnectorRegistry['uber_eats']` | ✅ `UberEatsMenuConnector` |
| `ProjectorRegistry['uber_eats']['menu']` | ✅ `MenuItemProjector` |
| `SourceProvisioner::identifierFor` `'uber_eats'` branch | ✅ |
| Apify actor config | ✅ `memo23~uber-eats-scraper` |
| **A flow that writes `uber_eats.order`** | ❌ |
| **A `menu` pool to land in** | ❌ |

The user-facing flow writes `partna.order_link`. That single mapping starves
the whole chain. The one `uber_eats.order` row in dev is a
`ShowcaseSeedCommand` seed (`"source": "showcase"`), not a real connection.

Corrections to earlier analysis: writes were never blocked
(`LegacyPlatformMap::isKnownSurface()` falls back to the compiled catalog),
and `notConnectable()` is display metadata only — never read by routing.

---

## 3. Scope

Priority is **correctness and cleanliness of the end state**, explicitly not
speed of any individual piece landing. (Owner, 2026-08-14.)

### 3.0 Boundary — BACKEND ONLY (owner, 2026-08-14)

This programme covers the backend only: `Comet-Backend` schema, ingest,
pools, registries and the retirement of legacy stores. It explicitly does
**NOT** include:

- `partna-monorepo/apps/dashboard` — the dashboard's pool pages, connect
  sheet, or queries
- `partna-monorepo/apps/pages` — the Astro sitepage renderer

Those follow as separate work once this lands. W8's documentation pass is
what makes that possible: the wire contracts and architecture map must be
accurate enough that the frontend work can be planned without re-deriving
any of this.

### 3.0.1 End-state pools (confirmed, owner 2026-08-14)

Nine content pools:

| Pool | Kind(s) | Status |
|---|---|---|
| watch | `video` | working |
| listen | `track`, `release`, `episode` | working; W2 adds real track sourcing |
| media | `media` | working |
| events | `event` | working |
| services | `service` | working |
| shop | `product` | working (registry key `shop`, not `sell`) |
| **menu** | `menu_item` | **W4 — new** |
| **custom links** | `link` | **landed 2026-08-15** (registry key `custom_links`) |
| **reviews** | `review` | landed (slice 6, merged + live-verified 2026-08-14) |

Plus `platforms` in the dashboard — the connection-manager surface, not a
content pool.

Kinds retired by this programme: `article` (Phase 1, with Substack) and
`channel` (**Phase 4** — its projectors die in Phase 1, but Spotify/SoundCloud
keep producing the kind until Phase 4 converts them to `track`). The
`document` kind is **KEPT** (owner, 2026-08-14 — see W10). Identity data
(`profile_fields`) is not a pool and stays in `site.workplaces` (W9).

### W1 — Identity keys
Emit the remaining `KeyClass` values, minimum: `Isrc`, `TitleDuration`,
`TitleRelease` (listen), `OfferingName*` (menu/services), `Gtin14`
(products). Exercise the merge engine — it has never run under load, and
`mergeInto()` hard-deletes uncurated losers (convergence spec §7.3).
**Prerequisite for W2 and W4** — without it both generate duplicates.

### W2 — Listen pool becomes real
- `youtube_music` is **already a complete, free (`CostClass::Free`, keyless
  channel RSS) `track` source** and the only producer of the `track` kind. It
  has simply never been provisioned. **Keep and light it up — it is the
  reference implementation.**
- Add Apify-backed track sourcing for `spotify` and `soundcloud`, following
  `config/partna.php:878` (`actor` + `host_pattern` + `driver` + daily cap).
- Delete `SpotifyChannelProjector` and `SoundcloudChannelProjector`.
- Dedup across all three via W1. Multi-link output needs no new work:
  `PoolResolver::linkSet()` already emits one entry per platform, synced
  beating hand-saved `content.item_links`.

### W3 — Lean out
De-source `twitch` (owner decision, 2026-08-14 — do not re-investigate),
`skool`, `strava`, `gumroad`, `substack`. `ChannelCardProjector` (and
`TwitchVodProjector`) die here — their only consumers are the demoted
platforms. The `channel` KIND does **not** retire here: Spotify and SoundCloud
still produce it until Phase 4 converts them to `track`; the kind (and its 9
`f_channel` rows) retires in Phase 4. `article` loses its only producer here
and retires immediately.

### W4 — Menu convergence
Add the `menu` pool; wire `menu_item` end to end; migrate 318
`site.item_slugs` rows (**0 retired today** — no redirect history to preserve
yet, so this is cheapest now); cut `PublicMenuController` over to
`content.*`; re-home slug allocation off `MenuItemObserver`.

### W5 — Custom links pool — **BUILT 2026-08-15** (checkpoint: parent spec §22)
`link` kind exists (`Mirror`). Add the pool: registry key `custom_links`,
kind `link`. Fed by the **23 `partna.custom_link` connections ONLY** — the
other 18 pseudo-platform connections (order links, storefronts, reservations)
do NOT migrate here; they keep their own lanes until Phase 6 (see the
decisions section). Link items do **not** carry `item_links`. Manual entry +
website/Linktree harvest input; no platform sources it.

Shipped as `PoolRegistry` config plus `CustomLinkBackfiller` +
`content:backfill-custom-links` (idempotent on a URL-derived coord). Two
things a later phase must not re-derive wrongly:

- **The pool is a SNAPSHOT until Phase 6.** `CustomLinksController` still owns
  every write and does not mint content items, so a link added after the
  backfill runs appears only on the legacy lane until the command is re-run.
  Moving that write path is Phase 6's, by construction — it is the phase where
  `partna.custom_link` stops being connectable at all. `CustomLinkBackfiller::
  linkProjection()` is public as the seam for it.
- **Both lanes publish the same link** until slice 7 retires the legacy read.
  `/integrations`' `custom` entry is unchanged and untouched by this work.

### W6 — Pseudo-platform retirement
Six categories stop being connectable; `PlatformCategory` remains as
grouping metadata. Promote the real ordering brands (`uber_eats`,
`doordash`, `menulog`) to their own surfaces.

### W7 — Cutover + teardown
Flip reads off legacy for shop/services/media/events; drop the parallel
`site.*` item tables. Includes closing the `site.content_selection` write
path (§1.9) — `ContentController` still writes it and
`IndividualProfilePayloadBuilder` still reads it.

### W9 — Delete the `profile_fields` seam (owner decision, 2026-08-14)

`profile_fields` are **user-owned fields, not items**: a business's opening
hours, address, phone and website auto-syncing from their connected Google
Business, so they never retype them. `site.workplaces` is the identity store
for exactly this and is **not** a parallel implementation of a pool — there
is only one of it.

`IdentitySync` already implements this correctly, with semantics the
`profile_fields` seam never specified: account-type precedence
(`AccountCapabilities::google_business_full_sync` — Google authoritative for
business accounts, gap-fill only for `partna`) and per-field provenance
(`workplaces.field_sources`, which drives the "Synced from Google" badge).

**Decision: option (b).** Do NOT build field bindings. Declare `IdentitySync`
the identity implementation, remove `profile_fields` as a projection target,
and drop the three `profile` streams — their output is already discarded
(`IdentitySync` reads the connection payload, not the ingest stream), so this
loses nothing at runtime and deletes an unbuilt abstraction.

Consequence: `site.workplaces` is **not** legacy and is not retired. W9
leaves the critical path — it becomes a deletion, not a build.

### W10 — The `document` kind is KEPT (owner decision REVERSED, 2026-08-14)
The original delete recommendation was wrong (convergence-log F8): there is a
**live user-documents feature** behind it — `site.site_media` with
`pool='documents'` (2 rows on dev), managed by `UserDocumentController` via
`SiteMedia::POOL_DOCUMENTS`. `document` is the third instance of the
declared-kind-awaiting-a-pool pattern, alongside `link` and `menu_item`.
**No documents pool is built** (documents have no platform source; the lane is
upload-only) and the kind stays declared and unpooled. Nothing to do.

### W8 — Documentation truth pass
Rewrite root + backend `CLAUDE.md`, correct the convergence spec's stale
figures, and add an **access/logistics** section so this research is never
repeated. See §6.

---

## 4. Leanness — what teardown actually removes

Menu is the clearest case (44 `*Menu*.php` files today):

- **~29 deleted** — 5 models, 2 observers, 4 drivers, 8 services
  (`MenuApifyScraper`, `MenuMerger`, `MenuPayloadComposer`, `MenuSource`,
  `MenuItemDeepLinks`, …), `MenuFetchJob`, 3 controllers, 5 form requests
- **5 kept** — the three ingest connectors, `MenuItemProjector`, `MenuRecords`
- **~8 retargeted** — the menu scan/AI lane points at `content.*`
- **6 tables dropped** (1,092 rows)

Repeat for shop, services, links and the six pseudo-platforms and most of a
parallel implementation of every content type goes. End state: one store, one
write path, one read path — and the whole class of defect where two systems
both look correct and only one is connected disappears.

---

## 4b. Known-failing sources — investigated, NOT systemic

Checked 2026-08-14 per owner request. Neither warrants a code fix:

| Source | Identifier | State |
|---|---|---|
| fresha | `some-salon-abc123` | fake `ShowcaseSeedCommand` seed — will always fail |
| fresha | `edward-scissorhands-…` | 3 failures, last run 08-08 |
| fresha | `vision-hair-studio-…`, `brotherwolf-…` | **ok**, ran 08-13 |
| bandcamp | `amiinaband` | 5 failures |
| bandcamp | `kinggizzard` ×2, `thesonnywilsons` | **ok** |

Fresha's most recent runs succeeded; the historical
`shouldShowAllEmployees: true` defect the convergence spec §1.6 describes is
**already fixed** in `FreshaConnector` (now `false`). The residual
`unavailable` stream health is stale, plus one real salon whose Fresha page
likely changed. Bandcamp is 3-of-4. Both are per-account, not code.

## 5. Decisions

### 5.1 Owner rulings, 2026-08-14 (recorded before execution)

1. **Serial execution.** One fresh session per slice/phase, run in the order
   fixed by `docs/superpowers/plans/2026-08-14-convergence-session-prompts.md`.
   No umbrella branch: each session branches off latest `origin/development`
   and merges when its exit criteria pass.
2. **The 18 non-custom-link connections take the natural mapping** (Phase 6):
   order links → the promoted ordering brands (`uber_eats`/`doordash`/
   `menulog`), storefronts → the shop lane, reservations → the booking
   surface. Only the 23 `partna.custom_link` connections feed the
   `custom_links` pool.
3. **Google aggregates stay on-demand only** for the remaining 11
   google_business sources — no scheduled refresh cadence is added by this
   programme.
4. **The RLS gap is ACCEPTED and recorded** (see §8): no access path exists —
   zero `anon`/`authenticated` grants and PostgREST is silenced. Revisit
   pre-pilot.
5. **anseo-studio's Fresha connection is written off** — the salon's Fresha
   site returns 410 Gone. Not a code defect; no further investigation.
6. **Slice 7 deletes `BackfillClaimedGoogleBusinessReviewsCommand`.**
7. **`article` retires in Phase 1** (its only producer, Substack, is demoted
   there). Resolves former open decision 1.
8. **`link` items do NOT carry `item_links`.** Resolves former open
   decision 3.

### 5.2 Still open

1. Sequencing within W4: promote ordering brands before or after the menu
   pool exists? (Before = data lands nowhere; after = one extra step.
   In the session order, slice-4-menus runs before phase-6-pseudo-platforms,
   so the pool exists first by construction.)

---

## 6. Access & logistics (for §W8, verified 2026-08-14)

- **Supabase MCP works.** Dev `glncumufgaqcmqhzwrxm`, prod
  `edplucmvkcnokyygxqsb`. Read-only SQL against dev is the fastest way to
  establish truth — use it instead of trusting figures in plan docs.
- **`cloud env:logs partna development` works** (binary at
  `~/.composer/vendor/bin/cloud`). Returns structured JSON. The `--minutes`
  window appears lagged; widen it.
- **`php artisan tinker` cannot reach the database** — `.env` carries
  `Host: EXAMPLE`. Use the Supabase MCP instead. Tinker is still fine for
  pure-PHP evaluation (catalog projector scoring etc.).
- **No `timeout(1)` on this Mac** — use the tool's own timeout.
- `php artisan catalog:compile` then `php artisan routing:corpus` after any
  catalog change; **run `./vendor/bin/pint`** on generated artefacts or the
  diff is thousands of formatting-only lines.

## 7. Corrections to existing documents

- The convergence spec's menu counts (370 items, 52 categories, 464 item
  categories, 6 menus) are **all stale**: actual 318 / 44 / 402 / 5.
- Its kickoff expects `content.item_slugs` = 0; actual **16**.
- `notConnectable()` is display metadata (`CatalogSurfacesController` only),
  not a routing gate — do not cite it as one.
- `LegacyPlatformMap` is not a write gate; `isKnownSurface()` falls back to
  the compiled catalog.

## 8. Security findings (surfaced, not actioned)

RLS disabled and exposed to `anon`/`authenticated`:
`ingest.record_versions_p0`–`p7` (parent has RLS; direct partition access
does not), `content.storefronts` (carries `referral_query` — affiliate
revenue), `content.source_stats`.

Remediation is `ALTER TABLE … ENABLE ROW LEVEL SECURITY`, but enabling
without policies blocks all access.

**Owner ruling 2026-08-14: ACCEPTED and recorded, not actioned.** There is no
access path today — the `anon`/`authenticated` roles hold zero grants on
these objects and the PostgREST Data API is disabled on the project. Revisit
before pilot.
