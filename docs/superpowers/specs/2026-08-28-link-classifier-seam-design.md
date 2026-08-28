# Link classifier seam — decision doc

**Date:** 2026-08-28
**Status:** decision requested; nothing implemented
**Subject:** `WebsiteLinkHarvester::classify()`'s four hand-maintained host
constants vs. the catalog-driven `LinkProjector` / `LinkRoutingService` lane.
**Branch:** `audit-fix/link-classifier-seam-2026-08-28` (worktree
`.worktrees/link-classifier-seam`, off `origin/development` @ `c4146c38a`)

Everything below is either **[V]** verified by reading code / running one test
file / querying `bootstrap/catalog/compiled.php`, or **[I]** inferred and
labelled as such.

---

## 0. Premise check — five corrections before costing anything

The brief's shape is right; five of its specifics are not, and two of them
change the answer.

### 0.1 Two of the four cited commits are not this bug shape [V]

| Commit | Files touched | Same root cause? |
|---|---|---|
| `7ca811fec` | `WebsiteLinkHarvester.php` + 3 `Catalog/Definitions` + `compiled.php` | **Yes** — catalog knew, harvester didn't |
| `fd55e662d` | `WebsiteLinkHarvester.php` + `Bopple.php` + `compiled.php` | **Yes** |
| `92ff6c72e` | `ConnectionDisplayName.php` + its test **only** | No — display-name fallback |
| `dfa6e3cd5` | `ConnectionDisplayName.php` + its test **only** | No — WhatsApp card copy |

`git show --stat` for both display-name commits shows no harvester and no
catalog file. They are same-day, same-wave (plan-03 batches 2 and 4), but they
are card-rendering defects downstream of routing, not classifier drift.

The drift shape is nonetheless real and recurring — the honest count is larger
than four, not smaller. `git log --since=2026-08-01 -- app/Services/Platforms/WebsiteLinkHarvester.php`
returns **22 commits in 28 days** [V]; the ones that are literally "the tables
learn a host the catalog already had" are `5c2572c10`, `9b4e383cc`,
`42e37556e`, `76e2264ca`, `7ca811fec`, `fd55e662d`, `612c05844`, `e7c376ab0`,
`fb0da3592`. **Nine.** The premise understates the problem.

### 0.2 There are 13 `classify()` call sites in `app/`, not 9 [V]

`grep -rn -- '->classify(' app/` minus the three false positives
(`IngestStatusWriteback::classify`, `DesignKitAutopilot`'s font classifier,
`InstagramScraper`'s adapter):

| # | Call site | Which categories it reads |
|---|---|---|
| 1 | `app/Http/Requests/Platforms/PlatformConnectRequest.php:72` | `platform` only (via `legacyFor`) — **not** `category` |
| 2 | `app/Http/Controllers/Api/Routing/RoutingController.php:146` | `content-item`, `event` only |
| 3 | `app/Services/Platforms/LinkRouter.php:76` | all 9 (the `match` at `:126-150`) |
| 4 | `app/Services/Platforms/LinkRouter.php:197` (`routeOrdering`) | `online-ordering` only |
| 5 | `app/Routing/Importers/WebsiteImporter.php:92` | `content-item`, `event`, `event-organiser` |
| 6 | `app/Routing/Importers/LinkInBioImporter.php:445` | `social` |
| 7 | `app/Routing/Importers/LinkInBioImporter.php:596` | `content-item`, `event`, `event-organiser` |
| 8 | `app/Services/Platforms/InstagramAutoSync.php:142` | null-check + `label`; delegates to LinkRouter |
| 9 | `app/Services/Platforms/GoogleBusinessAutoSync.php:412` | one caller-supplied category (`booking`) |
| 10 | `app/Services/Platforms/PreviousWebsiteGate.php:67` | `platform` only |
| 11 | `app/Services/Platforms/Strategies/Connect/BrandLinkConnect.php:52` | `label` only |
| 12 | `app/Services/Content/PastedLinkClassifier.php:65` | `event`, `event-organiser`, `social`, `shop` |
| 13 | `app/Services/Onboarding/OnboardingSuggestions.php:270` | `booking`, `reservations`, `online-ordering`, `event*` |

The brief's `ScanPreviousWebsiteContentJob.php:134` and
`GoogleBusinessEnrichJob.php:117` are **injection points, not call sites** —
both lines are `handle()` signatures. `grep -n 'classify\|harvest(' ` on those
two files returns exactly one hit: `GoogleBusinessEnrichJob.php:154`,
`$harvester->harvest($website)`. Which leads to the correction that matters
most:

### 0.3 `harvest()` is a SECOND consumer of the four constants — with no catalog fallback at all [V]

`harvestHtml()` (`WebsiteLinkHarvester.php:449-514`) walks
`SOCIAL_HOSTS` (`:466`), `RESERVATION_HOSTS` (`:475`), `isSquareOrderingUrl`
(`:485`), `ORDERING_HOSTS` (`:491`) and `BOOKING_HOSTS` (`:499`) — and then
returns. **It never calls `classifyFromCatalog()`.** So on the website-harvest
lane a catalog-only brand is not merely mis-categorised, it is *absent from the
payload entirely*. That payload is the Apify-shaped subset
`GoogleBusinessAutoSync::seed()` consumes (`:26-31` docblock).

Consequence for costing: **"retire the four constants" is not one refactor, it
is two.** `classify()` has a catalog backstop; `harvest()` has none.

### 0.4 "Renders as a generic CUSTOM card when pasted" is false for the two highest-traffic lanes [V]

- **Paste** — `RoutingController` reads `classify()` only for `content-item`
  and `event` (`:147-148`); everything else falls through to
  `$this->routing->route(...)` at `:205-208`, which is pure
  `LinkRoutingService` → `LinkProjector`. The paste lane is already catalog-driven.
- **Bio scan / link-in-bio** — `LinkRouter`'s match has an arm
  `'link' => $this->seedCatalogLink($user, $url)` (`LinkRouter.php:142`), and
  `seedCatalogLink()` (`:383-403`) routes through `LinkRoutingService::route()`.
  Comment at `:135-141` says so explicitly (F6, 2026-08-20). A detect-only
  brand on this lane can already become a real connection.
- **Brand connect guard** — `PlatformConnectRequest` compares
  `LegacyPlatformMap::legacyFor($classified['platform'])` to the slug
  (`:83-88`). `classifyFromCatalog()` already returns the correct legacy
  platform (`WebsiteLinkHarvester.php:757`), so detect-only brands pass. Pinned
  by `tests/Feature/Platforms/Registry/BrandCoverageTest.php:108-125`.

Where the gap is **actually** live:

| Lane | file:line | What a detect-only brand gets |
|---|---|---|
| Website harvest → GB payload | `WebsiteLinkHarvester.php:449-514` | **absent entirely** — no reservation/order/booking row |
| GB booking surface pick | `GoogleBusinessAutoSync.php:412-414` | falls to `direct.book` instead of the brand |
| Ordering-only door | `LinkRouter.php:198` | `custom()` — refuses a real ordering brand |
| Onboarding prefill | `OnboardingSuggestions.php:274-279` | no prefill offered |
| Pool "does this belong here" | `PastedLinkClassifier.php:66-83` | no `account`/`store` answer → generic 422 copy |

That is the honest blast radius, and it is narrower and more specific than
"pasted links become custom cards".

### 0.5 `classifyFromCatalog()`'s own justification is stale [V]

Its docblock (`WebsiteLinkHarvester.php:713-717`) argues the constants must run
first because "the projector scores a bare host-only detector in the low 30s"
and would downgrade Booksy/GitHub/DoorDash. That was true when
`LinkProjector::FLOOR` was 35. **`FLOOR` is 25 today** (`LinkProjector.php:44`,
with a docblock at `:26-43` explaining that it was lowered from 35 for exactly
this reason). `Projection::matched()` is `surfaceKey !== null`
(`Projection.php:26-29`) — no confidence test. So `classifyFromCatalog()`
already *matches* those hosts; it just refuses to name their class.

The ordering argument is therefore no longer about identification. It survives
only as an argument about **category naming**, which is what §2 costs.

---

## 1. The seam, measured

### 1.1 Two vocabularies that do not line up [V]

`classify()` returns one of **9** categories (`WebsiteLinkHarvester.php:577-706`):
`content-item`, `social`, `booking`, `reservations`, `online-ordering`,
`event`, `event-organiser`, `shop`, `link`.

`routing_class` on a compiled surface has **7** values
(`php -r` over `bootstrap/catalog/compiled.php`, 159 surfaces):

| routing_class | surfaces |
|---|---|
| social | 39 |
| booking | 37 |
| content | 22 |
| ordering | 19 |
| events | 16 |
| shop | 14 |
| reservations | 12 |

### 1.2 The measured split [V]

`CATALOG_SWEEP_REPORT=1 ./vendor/bin/pest tests/Feature/Platforms/CatalogClassificationSweepTest.php`
(0.27s, 4 passed):

```
CATALOG SWEEP: {"connectable":96,"link-only":49,"invisible":13,"no-probe":0}
```

- **96 connectable** — `classify()` names a real category.
- **49 link-only** — `classify()` answers `category:'link'` (the detect-only set).
- **13 invisible** — `classify()` returns null, pinned in
  `tests/fixtures/catalog/known-invisible.php` (12 by policy: storefronts keep
  their probe).

The 49, grouped by their `routing_class`:

| routing_class | n | surfaces |
|---|---|---|
| social | 24 | bark.company, bluesky.profile, buymeacoffee.page, cameo.profile, cash_app.profile, codepen.profile, fiverr.profile, flickr.photos, gitlab.profile, houzz.pro, kick.channel, ko_fi.page, medium.profile, paypal.me, pinterest.profile, productreview.listing, substack.publication, tripadvisor.listing, trustpilot.listing, tumblr.profile, upwork.profile, venmo.profile, vsco.profile, yelp.listing |
| content | 13 | apple_music.artist, apple_podcasts.show, audiomack.artist, bandcamp.artist, bandcamp.store, beatport.artist, circle.community, dailymotion.channel, kajabi.courses, mixcloud.player, rumble.channel, strava.club, tidal.player |
| events | 7 | bandsintown.artist, dice.events, eventfinda.tickets, megatix.tickets, moshtix.tickets, skiddle.tickets, songkick.artist |
| booking | 3 | microsoft_bookings.book, shortcuts.book, wix_bookings.book |
| ordering | 1 | easi.order |
| shop | 1 | gumroad.store |

### 1.3 Wave 2, re-measured [V]

`fb0da3592` added **37** files under `app/Catalog/Definitions/`. Its diff on
`WebsiteLinkHarvester.php` adds exactly **three** host rows: `deezer` to
`SOCIAL_HOSTS`, `Cal.com` and `ClassPass` to `BOOKING_HOSTS` (plus their two
platform-map rows and one `SOCIAL_PLATFORM` row). Pinterest reached
`LINK_ONLY_HOSTS`, not a connectable table — deliberately, per that constant's
docblock (`:284-306`): a board must never consume a probe. So the brief's
"only 4 of 34" is "3 promoted + 1 explicitly held link-only, out of 37".

---

## 2. Option A — COLLAPSE

> Teach `classify()` to derive its category from
> `LegacyPlatformMap::routingClassFor($projection->surfaceKey)` and retire the
> four constants (or reduce them to genuine exceptions).

### A.1 What breaks

**A.1.a — 11 surfaces that are connectable TODAY get reclassified.** [V]
Measured by booting the app and diffing `classify()['category']` against
`routing_class` for all 158 probe-able surfaces:

| surface | today | routing_class | naive Option A |
|---|---|---|---|
| youtube.channel | `social` | content | `content` |
| youtube_music.channel | `social` | content | `content` |
| spotify.player | `social` | content | `content` |
| soundcloud.player | `social` | content | `content` |
| twitch.channel | `social` | content | `content` |
| vimeo.account | `social` | content | `content` |
| deezer.artist | `social` | content | `content` |
| eventbrite.organiser | `event-organiser` | events | `event` |
| humanitix.organiser | `event-organiser` | events | `event` |
| luma.calendar | `event-organiser` | events | `event` |
| partiful.events | `event-organiser` | events | `event` |

Both groups are **outright breakage, not reclassification**:

- `LinkRouter::gateAllows()` (`:245-256`) has no `content` arm →
  `default => false` → gate-denied. Its `match` at `:126-150` has no `content`
  arm either → `default => RouteResult::custom()`. So **YouTube, Spotify,
  SoundCloud, Twitch, Vimeo, Deezer and YouTube Music stop connecting on every
  scan lane.** These are the platform's most-connected brands.
- The `event` vs `event-organiser` split is a **path** distinction that no
  surface expresses. The catalog has `eventbrite.organiser` and no
  `eventbrite.event` at all (the `events` class is 16 surfaces, all organiser/
  ticketing pages — listed in §1.2's source data). `LinkRouter::seedEvent()`
  (`:330-359`) branches on exactly that string: `event` → `seedStandalone`
  (pool item), `event-organiser` → `seedAccount` (connection). Collapsing both
  to `event` turns every organiser page into a single pool item.

**A.1.b — 49 detect-only surfaces get promoted, 24 of them into `seedSocial`.** [V]
Under A, `bluesky.profile`, `ko_fi.page`, `paypal.me`, `venmo.profile`,
`cash_app.profile`, `yelp.listing`, `trustpilot.listing`, `tripadvisor.listing`,
`productreview.listing`, `pinterest.profile` and 14 others become
`category:'social'` → `gateAllows('social') === true` (`:246`) → `seedSocial`.

Four of those are **payment handles** and four are **third-party review
listings the user does not own**. Turning a Trustpilot listing into a connected
"social account" is a product decision with support and DSAR consequences, not
a refactor. Pinterest specifically reverses a written policy
(`LINK_ONLY_HOSTS` docblock, `:284-306`: boards must never spend a probe).

**A.1.c — per-call-site behaviour change.** [V]

| Call site | Change under A |
|---|---|
| `LinkRouter.php:76` | 11 breakages (A.1.a) + 49 promotions (A.1.b). The single biggest blast site |
| `LinkRouter.php:197` (`routeOrdering`) | `easi.order` starts routing — correct. No regression |
| `RoutingController.php:146` | `event-organiser` collapse means a pasted Eventbrite organiser page seeds a pool item instead of falling through to `LinkRoutingService`. Behaviour change on the paste wire |
| `WebsiteImporter.php:92` / `LinkInBioImporter.php:596` | same `event`/`event-organiser` collapse, `:635-638` |
| `LinkInBioImporter.php:445` | 24 new hosts count as `social` for the "did we find socials" test |
| `PastedLinkClassifier.php:66-83` | 24 new `account` answers, 4 organiser→event flips, `gumroad.store` becomes a `store` answer |
| `OnboardingSuggestions.php:274-279` | prefill starts firing for the 4 new booking/ordering brands — the one unambiguous win |
| `GoogleBusinessAutoSync.php:412` | `brandSurfaceFor($url,'booking')` starts resolving 3 more brands |
| `PlatformConnectRequest.php:72` | **no change** — reads `platform`, not `category` |
| `PreviousWebsiteGate.php:67`, `BrandLinkConnect.php:52`, `InstagramAutoSync.php:142` | **no change** — read `platform`/`label` |

**Does it silently reclassify existing users' live cards?** For *new* writes,
yes — the 11 in A.1.a. Existing rows are unaffected: connections are keyed by
`surface_key`/`platform`, and `classify()` is never replayed over stored rows
(no call site reads the DB) [V]. But `LinkInBioImporter`/`InstagramAutoSync`
re-run on refresh, so a re-scan **would** stop reconnecting YouTube/Spotify.
Whether an existing YouTube connection then looks orphaned is a frontend
question I did not verify — flagged **[I]**.

### A.2 Can the catalog express what the constants encode? — **No. Four hard blockers.** [V]

1. **`content` has no category.** 22 surfaces carry `routing_class: content`.
   `classify()` has no such category, `LinkRouter::gateAllows()` has no arm,
   `LinkRouter`'s `match` has no arm. Seven of the 22 are today's biggest
   `social` connections. Fixing this means inventing a `content` category and
   an ingest lane for it — which is a programme, not a collapse.

2. **`event` vs `event-organiser` is inexpressible.** No surface distinguishes
   a single event from an organiser page. Today it lives in per-brand inline
   path regexes (`WebsiteLinkHarvester.php:625-693`) that *deliberately*
   delegate pattern authority to each scraper's pure normalizer
   (`:625-631` docblock) so `classify()` can never drift from what the connect
   flow accepts. `routing_class` cannot carry it.

3. **`LINK_ONLY_HOSTS` is 4/5 not in the catalog at all.**
   `grep` over `compiled.php` surface keys: `amazon` ABSENT, `ltk` ABSENT,
   `liketoknow` ABSENT, `poshmark` ABSENT, `shopmy` ABSENT; only
   `pinterest.profile` exists. The affiliate-probe-starvation protection this
   constant documents (`:284-306` — "five of a run's six probes went on
   discovering that amazon.com is amazon.com") has **no catalog expression
   whatsoever**. It must survive Option A verbatim. **This is the real blocker
   the brief asked for.**

4. **No catalog field reproduces the connectable/link-only boundary.**
   I tested the two candidates:

   | | link-only (49) | everything else (109) |
   |---|---|---|
   | `is_connectable=true` + has `capabilities.connect` | 5 | 19 |
   | `is_connectable=true`, no connect capability | 15 | 79 |
   | `is_connectable=false` | 29 | 12 |

   20 of 49 detect-only surfaces are `is_connectable: true`; 12 connectable-
   today surfaces are `is_connectable: false`. Neither field is the boundary.
   Option A would have to **author** it.

Secondary, but non-trivial: `looksLikeProfile()` gates every `SOCIAL_HOSTS`
match (`:582`) and is what rejects share widgets
(`facebook.com/sharer/…`, `twitter.com/intent/tweet`) — pinned by
`tests/Unit/Platforms/WebsiteLinkHarvesterTest.php:168-170` and
`tests/Feature/Platforms/WebsiteLinkHarvesterShareWidgetTest.php`. The
projector's equivalent is per-surface `reserved_paths` (`LinkProjector.php:135-140`),
which is not the same rule and is not populated for every social surface.
Verifying equivalence for 39 social surfaces is its own task. **[I]** that it
is not equivalent today; I did not enumerate `reserved_paths` coverage.

### A.3 Test strategy

**Tests that pin current behaviour and would have to change — this is the red
flag the brief asked me to watch for:**

| File | classify() refs | What changes |
|---|---|---|
| `tests/Unit/Platforms/WebsiteLinkHarvesterTest.php` | 25 | the social/booking/reservations/ordering/event/shop datasets (`:110-283`) |
| `tests/Feature/Platforms/CatalogBackedClassificationTest.php` | 6 | **its entire thesis.** `:29-30` asserts `category => 'link'` for 6 brands; `:71-86` is a 12-case regression pin titled *"keeps every hand-table classification the catalog would have lost"* |
| `tests/Feature/Platforms/CatalogClassificationSweepTest.php` | 3 | `SweepProbeUrl::bucket()` (`tests/Support/Catalog/SweepProbeUrl.php:38-45`) defines link-only as `category === 'link'` — the bucket vocabulary itself dies |
| `tests/Feature/Platforms/LinkRouterGateMatrixTest.php` | 1 | the `'link'` row of `gate_matrix` (`:44`) and a new `content` row |
| `tests/Feature/Routing/MediaScanSeedTest.php` | 3 | `:144-153` pins YouTube/Spotify categories |
| `tests/Feature/Platforms/GenericEventSeedTest.php` | 2 | organiser/event split |
| `tests/Unit/Security/HostSpoofingHotfixTest.php` | 3 | spoof cases assert `platform`; likely survives **[I]** |
| `tests/Feature/Platforms/Registry/BrandCoverageTest.php` | 1 | reads `platform`; likely survives **[I]** |

`CatalogBackedClassificationTest` is not incidental coverage. It is the
written record of the N1 decision (its 20-line header, `:6-20`), and it says in
so many words that promoting these to connections "is the P8 migration
(`docs/plans/2026-07-28-p8-deletion-readiness.md`), not this fix". Option A is
that migration. Deleting the test is deleting the decision — the correct move
would be to rewrite it as the record of the *reversal*, with the reasoning.

**New test that would make the seam self-enforcing:** a derived
category-equivalence test — for every compiled surface, `classify()`'s category
must equal `CATEGORY_FOR_ROUTING_CLASS[routing_class]` unless the surface key
is in a named exception fixture. That is a genuinely good guard and it is
**equally available to Option B** (as an *inequality* ratchet), at a fraction
of the cost.

### A.4 Migration path and rollback

Not one commit. Minimum viable sequence, each independently revertable:

1. Invent + ship the `content` category end to end: `classify()` arm,
   `gateAllows()` arm, `LinkRouter` `match` arm, a seeder, gate-matrix row.
   (Or: map `content` → `social` and accept that YouTube is "social" forever,
   which re-introduces exactly the vocabulary mismatch A exists to remove.)
2. Author the connectable/link-only boundary as catalog data (a new surface
   field + `catalog:compile` change + `compiled.php` regeneration), because
   no existing field carries it.
3. Preserve `LINK_ONLY_HOSTS` verbatim as a pre-catalog exception list, or add
   Amazon/LTK/Poshmark/ShopMy to the catalog as never-connect surfaces.
4. Keep the `event`/`event-organiser` inline arms (they are path grammar, not
   host grammar) — so the "retire the four constants" claim is already only
   partly true.
5. Rewrite `harvest()`/`harvestHtml()` onto the catalog (§0.3) or keep the four
   constants alive purely for it — in which case nothing was retired.
6. Rewrite `CatalogBackedClassificationTest` and the sweep bucket vocabulary.

Rollback: revertable per step, but step 2 emits a new `compiled.php`, and
`CatalogLegacyMapTest` locks the legacy map to the applied migration
`20260727110001` four ways (`LegacyPlatformMap.php:11-19`). Any surface-schema
change has to keep all four representations agreeing.

### A.5 Effort — **XL**

Six sequenced steps, one of which is inventing a product category, one of which
is authoring catalog data that does not exist, one of which is a second
refactor of `harvest()`, and one of which reverses a written owner-era policy
for 24 brands including payment handles. Plus the `content` lane is genuinely
new product surface.

---

## 3. Option B — FORMALISE THE SPLIT

> Accept detect-only as permanent policy, document it once at the top of the
> constants, and make the boundary explicit and testable.

### B.1 What breaks

**Nothing.** Zero behaviour change at all 13 call sites — this option adds a
docblock, a fixture, and test assertions. [V] by construction.

The cost is not breakage, it is what stays broken: the five live gaps in §0.4
remain open. A new catalog booking brand still misses the GB payload, the
onboarding prefill, and `routeOrdering`.

That is why the recommendation in §4 pairs B with one bounded promotion rather
than taking B neat.

### B.2 Can the catalog express it?

**B does not need it to.** The whole point is that the boundary is a *policy*
the catalog does not carry (§A.2.4 proves it does not), so B writes the policy
down where it can be checked instead of pretending it is derivable.

The boundary that gets pinned is the one already computed by
`SweepProbeUrl::bucket()`: `connectable` / `link-only` / `invisible`.

### B.3 Test strategy

**Zero existing tests change.** [V] — nothing about the classifier moves.

**The new test — a second ratchet on the existing sweep.** The infrastructure
is already there: `CatalogClassificationSweepTest` derives its cases from the
compiled catalog at runtime (header, `:8-11`: "a hand list would rot exactly
the way that produced N1"), and `known-invisible.php` is already a working
ratchet for the null bucket, failing in **both** directions (`:62-73`).

Add `tests/fixtures/catalog/known-link-only.php` as its sibling and a fourth
`it()` that asserts:

- a surface that buckets `link-only` and is **not** in the fixture → FAIL
  ("new detect-only brand: add a harvester row, or record the decision here")
- a surface in the fixture that no longer buckets `link-only` → FAIL
  ("promoted; remove the row")

That is exactly the "so this isn't fixed a fifth time" mechanism. Adding a
catalog definition without a harvester row becomes a **red test at add-time
with an instruction in the failure message**, not a live find three weeks later.
It also converts the brief's own artefact — "of 37 new brands only 3 got
harvester support" — from an archaeology exercise into a diff.

The fixture is seeded from the measured 49 in §1.2, each with a one-line
reason.

### B.4 Migration path and rollback

One commit, three files (fixture, test, docblock). Rollback is `git revert`.
No `compiled.php` change, no migration, no wire change.

### B.5 Effort — **S**

Half a day including the 49 fixture reasons.

---

## 4. Recommendation

**Take Option B, and add exactly one bounded promotion inside it.** Not A.

### Why not A

A's blocking fact is not effort, it is that **`routing_class` is a placement
vocabulary, not a category vocabulary**, and the brief's premise that
`classifyFromCatalog()` "already computes" the answer is true only in the sense
that it computes a *different* answer. Three of the seven classes have no
category to map to (`content`), map many-to-one onto a distinction the catalog
cannot make (`events`), or must not map at all (`shop`, which
`classifyFromCatalog` already special-cases at `:743-753` to protect the
product probe). Collapsing them costs seven of the platform's biggest brands
and reverses a written policy for 24 more, in exchange for closing a gap that
two of the five affected lanes have *already* closed by a different route
(§0.4).

### Why B alone is not enough

B closes the *process* hole and none of the *product* holes. The recurring bug
is real; documenting it is not fixing it.

### The one promotion to add

Extend `classifyFromCatalog()` to return the real category — instead of
`'link'` — for exactly the three routing classes where the vocabularies are
1:1 and a real seeder exists, **and** the surface says it is connectable:

```
routing_class ∈ {booking, reservations, ordering}  AND  is_connectable === true
```

Measured effect today: **two surfaces**, `shortcuts.book` and `easi.order`. [V]
`microsoft_bookings.book` and `wix_bookings.book` are right-class but
`is_connectable: false`, so they correctly stay `link` — which matters, because
promoting them would have sent a non-connectable surface into `seedBooking()`.

Everything else is unchanged by construction: `social`, `content`, `events` and
`shop` keep answering `'link'`, so all 11 breakages in A.1.a and all 24
promotions in A.1.b simply do not occur.

Why this is the right cut:

- It fixes **the exact bug the cited commits are** — `7ca811fec` (four booking
  brands), `fd55e662d` (one ordering brand), `e7c376ab0` (T27a booking/ordering),
  `76e2264ca` (ordering). Every one is booking/reservations/ordering. None is
  social, content or events.
- It is **strictly more expressive than the constants for these classes.**
  `BOOKING_HOSTS`' own docblock (`:230-233`) says Wix and Microsoft Bookings
  are excluded because "only a PATH identifies a booking page, which this
  host-keyed map cannot express; they stay catalog-detector territory". The
  catalog *can* express a path. This promotion is how that territory finally
  gets used.
- It cannot regress a probe: `shop` is untouched, and
  `classifyFromCatalog()`'s existing shop guard (`:743-753`) is left alone.
- It is bounded by data, not by a hand list, so brand #38 works automatically —
  and the B ratchet fails loudly on anything that *isn't* covered.

Net: **B + narrow promotion, S/M, one branch, three commits, zero existing
tests changed.** A stays available as the record of what a full collapse would
cost, and §A.2's four blockers are the entry gate it would have to clear.

### What this deliberately does not do

- No pseudo-platform link lane. No `partna.*_link` surface, no
  `custom`/`booking`/`reservations`/`online-ordering`/`events-custom` category
  controller. Every promoted link still routes to its real brand surface via
  the existing seeders (CLAUDE.md **Do NOT**, and `LegacyPlatformMap::RETIRED`
  `:41-56`).
- No change to `harvest()`. Its gap (§0.3) is real and is recorded as a
  follow-up in the plan, not smuggled into this branch.
- No change to `known-invisible.php`. The 12 storefront/payment surfaces stay
  invisible on purpose.
