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
`skool`, `strava`, `gumroad`, `substack`. With Spotify/SoundCloud converted,
`channel` loses every producer: retire the kind, `ChannelCardProjector`, and
the `f_channel` facet. `article` likewise loses its only producer.

### W4 — Menu convergence
Add the `menu` pool; wire `menu_item` end to end; migrate 318
`site.item_slugs` rows (**0 retired today** — no redirect history to preserve
yet, so this is cheapest now); cut `PublicMenuController` over to
`content.*`; re-home slug allocation off `MenuItemObserver`.

### W5 — Links pool
`link` kind exists (`Mirror`). Add the pool. Manual entry + website/Linktree
harvest input; no platform sources it. Migrate the 23 `partna.custom_link`
connections into it.

### W6 — Pseudo-platform retirement
Six categories stop being connectable; `PlatformCategory` remains as
grouping metadata. Promote the real ordering brands (`uber_eats`,
`doordash`, `menulog`) to their own surfaces.

### W7 — Cutover + teardown
Flip reads off legacy for shop/services/media/events; drop the parallel
`site.*` item tables. Includes closing the `site.content_selection` write
path (§1.9) — `ContentController` still writes it and
`IndividualProfilePayloadBuilder` still reads it.

### W9 — Identity / profile-field bindings
`profile_fields` is declared by three connectors and has **no
implementation** (§1.8). Either build the field-binding layer plan §14
describes, or fold `IdentitySync` into the ingest pipeline as the
implementation of that seam. Must preserve what legacy already does
correctly: account-type precedence
(`AccountCapabilities::google_business_full_sync`) and per-field provenance
(`workplaces.field_sources`, which drives the "Synced from Google" badge).

Until this lands, `site.workplaces` cannot be retired and Google Business /
Instagram / Fresha identity sync stays on the legacy path — so this is a
**hard prerequisite for "no legacy in use"**, not an optional extra.

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

## 5. Open decisions

1. `substack` / `article` — demote confirmed, but `article` has no pool
   either. Retire the kind, or keep it for a future posts pool?
2. Sequencing within W4: promote ordering brands before or after the menu
   pool exists? (Before = data lands nowhere; after = one extra step.)
3. Whether `link` items support per-platform links (they inherit
   `item_links` for free, but may not want it).

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
without policies blocks all access. Owner decision required.
