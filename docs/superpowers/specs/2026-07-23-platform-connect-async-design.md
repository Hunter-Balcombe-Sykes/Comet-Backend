# Async platform connect — the six bespoke controllers

**Status:** design complete, awaiting sign-off (2026-07-23). **No code written.**
**Source:** roadmap item #12 of `docs/reviews/2026-07-23-worker-async-layer-review.md` §5a.
**Prior art:** `docs/superpowers/plans/2026-07-20-platform-connect-async.md` — shipped, and covers a
**disjoint** set of platforms. Read its status note first.
**Frontend contract:** `docs/frontend-contracts/2026-07-23-platform-connect-async.md`.

---

## 0. Three corrections to the brief

This design contradicts three things the task was set up with. All three were verified against the
tree on 2026-07-23 and each changes the answer.

**1. The deferred-connect lever is armed, not inert.** `PlatformDescriptor.php:54` reads
`private bool $deferredConnect = false;`, which looks like a flag nobody sets. Its setter has **eight
call sites** — `PlatformRegistryServiceProvider.php:150,177,196,211,225,238,251,265`
(`strava`, `spotify`, `youtube`, `youtube-music`, `vimeo`, `twitch`, `pinterest`, `bandcamp`). The
first conjunct at `ConnectResolver.php:68` is therefore *true* for eight platforms;
`PARTNA_CONNECT_DEFERRED=spotify` would flip that platform to async today. The setters live in a
provider because this registry builds descriptors with a fluent builder at boot — grepping the
descriptor class alone misses them.

**2. There is no `deferredFailureMessage`.** The real accessor pair is
`connectFetchError(string)` / `connectFetchErrorMessage()` (`PlatformDescriptor.php:333-343`). Its
docblock states the intent exactly: *"that message can no longer be delivered as a 422 response body
(the request already returned 202 before the fetch ran), so it is stored here and surfaced verbatim
by the eventual connect-status endpoint."*

**3. The scope table named the wrong Shop endpoints.** `ShopController::brands():98` and
`selection():420` are **pure local DB reads** — `ShopBrand::with('products')` and an array walk, zero
network. Nothing to convert. The heavy endpoints are `addBrand():108` (`POST /brands`) and
`setProducts():360` (`PUT /brands/{id}/selection`). The review cites `ShopController.php:108-115`
correctly; the transcription into the task brief drifted to the GET handlers.

---

## 1. Corrected scope

The brief says "six controllers, twelve endpoints." The real surface is different in composition:
three of the named twelve do no I/O at all, and five network-bound endpoints were omitted.

| Controller | In scope (network-bound) | Named but **not** in scope (zero I/O) | Omitted but in scope |
|---|---|---|---|
| `ShopController` | `addBrand():108`, `setProducts():360` | `brands():98`, `selection():420` | `addProduct():~460`, `updateBrand():~215` (conditional), `brandProducts():~344` (cache-miss only) |
| `AppleController` | `connectMusic():63`, `connectPodcast():101` | — | — |
| `FreshaController` | `connect():57`, `team():130`, `employeeServices():205` | `selection():223` | `saveSelection():149` (**re-fetches live**) |
| `EventbriteController` | `connect():59` | — | `addEvent():65` |
| `HumanitixController` | `connect():58` | — | `addEvent()` |
| `SkoolController` | `connect():31` | `selection():52` | — |

**Worst cases are also understated.** None of the six opens a `FetchBudget` — the 20 s wall-clock
ceiling from the prior plan's Phase 1 reaches only `ConnectResolver`, `HighlightsPicker` and
`YoutubeThumbnailResolver`. Under `SafeUrlFetcher`'s per-hop model (8 s × 6 hops, doubled by the
one-shot 403 alternate-UA retry at `:101-114`) ≈ 96 s per `fetch()`:

| Endpoint | Fetches | Worst case |
|---|---|---|
| `ShopController::addBrand` | up to 8 sequential probes (Shopify ×1, Woo ×2, Squarespace ×4, generic ×1) + up to 2 profile fetches | **~768–960 s** |
| `AppleController::connectMusic` | 2 sequential + 1 best-effort genre | ~192–288 s |
| `AppleController::connectPodcast` | 2 sequential (`/search` → id → `/lookup?id=`) | ~192 s |
| `SkoolController::connect` | 2 sequential (`/about`, then root) | ~192 s |
| `FreshaController::employeeServices` | 1 GraphQL (12 s) + `fetchLocation` fallback | ~108 s |
| `FreshaController::{connect,team}` | 1 each | ~96 s each |
| `Eventbrite/Humanitix::connect` | 1 page + 1 pooled batch | ~144–192 s |

---

## 2. The recommendation

> **Neither (a) nor (b). Reuse the shipped *mechanism*, keep the bespoke *routers*.**
>
> The generic deferred-connect machinery is already built, tested and route-emitting. Its three
> load-bearing parts — `ConnectFetchJob`, the `pending: true` write, and
> `connectFetchErrorMessage()` — are **independent of `GenericPlatformController`**. Adopt those
> three in the bespoke controllers behind the existing `PARTNA_CONNECT_DEFERRED` lever. Do not move
> the six onto the registry router, and do not hand-roll six copies of Instagram.

Call this **route (c)**.

### Why not (a) — migrate onto the registry

Route (a) is blocked by data-model and route-surface facts for four of the six, and each blocker is
structural rather than effort:

- **Shop.** Content lives in `site.shop_brands` / `site.shop_products`
  (`20260704160000_shop_brands_products.sql:15-38`), not in a JSONB payload. The
  `IntegrationConnection` row is one-per-user with its payload frozen to the marker
  `{"storage":"relational"}` (`ShopController.php:80`). The registry's entire write path assumes one
  parsed selection array per row. Migrating Shop means teaching the registry about child-table
  writers, or abandoning FOUND-25's relational model. Plus 15 bespoke routes with no registry
  equivalent.
- **Eventbrite / Humanitix.** They carry a **second resource archetype** — organiser accounts *plus*
  independently addable standalone events as `resource_kind='event'` rows, with their own 10-event
  cap. `writeAccountConnection` has no notion of a second resource kind per platform.
- **Fresha.** A Square-XOR conflict check (`:69-71`), a `can_use_booking` / `can_book_storewide`
  capability branch (`:64-66`, `:83`), a `FreshaServiceProjector` that writes `core.users` and
  `Service` rows, and a cross-platform lock. `PlatformDescriptor.php:502-506` explicitly documents
  `availableFor()`/`requiresCapability()` as covering only fully registry-driven connects, and names
  Fresha as one of the exceptions that gates itself inline.
- **Apple.** One controller serves two platform slugs via a mutable `$activePlatform` property
  (`AppleController.php:48,55-58`), on bespoke routes `/apple/music/connect` and
  `/apple/podcast/connect`. Registry routing would change the URLs — a contract break for zero
  functional gain.

Only **Skool** is a clean (a) candidate, and even it is already `SingleSelection`-shaped
(`PlatformRegistryServiceProvider.php:460`) while lacking only a `ConnectStrategy`. Migrating one of
six does not buy a shared lever, because route (c) gives the same lever without the migration.

### Why not (b) — hand-roll six copies of Instagram

The decisive argument is not effort, it is that **the exemplar is the weaker of the two existing
implementations**.

`GenericPlatformController::connectStatus()` has a 5-minute staleness escape hatch that flips a
stuck `pending` row to `failed` (`:236-253`, proven by `DeferredConnectTest.php:220-260`).
`InstagramController::connectStatus()` **has no such check** — a row stranded by a dead worker polls
`pending` forever. Copying Instagram six times copies that defect six times, then requires six
separate fixes when it is noticed.

Route (b) also forfeits: one kill switch, one merge-not-replace pending-write contract, one
failure-message convention, and one place to fix the next bug. It buys nothing route (c) doesn't
already have.

### Why (c) works — the mechanism is already decoupled

| Part | Where it lives | Why it needs no router change |
|---|---|---|
| The job | `ConnectFetchJob(connectionId, platform)` | Resolves its own descriptor from the constructor arg and calls `$descriptor->fetchStrategy()->fetch($connection)` (`:102`). Never references `GenericPlatformController`. |
| The pending write | `ManagesIntegrationConnection::writeConnection(..., pending: true)` (`:175-185`) and `writeAccountConnection(..., pending: true)` (`:408-439`) | Lives in the shared trait **the six bespoke controllers already use**. The `$pending` parameter exists today and is simply never passed `true` from their call sites. |
| Merge-not-replace | `upsertConnection($mergePayload)` (`:132-134`) | Same trait. Guards reconnect-blanking and the 304 traps. |
| The failure message | `connectFetchError()` / `connectFetchErrorMessage()` (`PlatformDescriptor.php:333-343`) | A descriptor field, readable by anything holding a descriptor. All six slugs already have descriptors. |
| The `pending` state | `last_refresh_status` CHECK | Already permits `'pending'` — `20260616000000_allow_pending_refresh_status.sql`. **Zero migrations** for five of six. |
| The lever | `config('partna.connect.deferred')` (`config/partna.php:1405`) | A comma-separated slug list read by `in_array`. Nothing binds it to `ConnectResolver`; a bespoke controller can consult the same key. |

**One lever for both families.** Reuse `PARTNA_CONNECT_DEFERRED` rather than inventing a second flag.
`ConnectResolver.php:70` already gates on it for the eight registry platforms; the bespoke concern
checks the same key for its slug. One env var, one kill switch, one mental model.

### What route (c) requires that does not exist yet

A small shared concern — call it `DefersBespokeConnect` — providing three things to a bespoke
controller:

1. `shouldDeferConnect(string $slug): bool` — the `in_array($slug, config('partna.connect.deferred'))` check.
2. `deferredConnectResponse(IntegrationConnection $row, array $partial, string $statusUrl)` — builds the 202.
3. `bespokeConnectStatus(...)` — the poll action, **with the 5-minute staleness check ported from
   `GenericPlatformController.php:236-253`**, not from `InstagramController`.

Roughly one file plus one route per platform. Everything else is reuse.

---

## 3. The seam, per endpoint

The governing constraint: **preserve inline validation, defer only the separable fetch.** A design
that defers validation is wrong however clean it looks. Below, per platform, exactly where that seam
falls — and where it does not fall cleanly, said plainly.

### 3.1 Fresha — the seam is already open (and the brief's premise does not hold here)

**For Fresha's endpoints the vendor fetch is *not* the validation.** Every 4xx is local:

| Check | Kind | Citation |
|---|---|---|
| `can_use_booking` capability → 403 | local | `FreshaController.php:64-66` |
| Square XOR → 409 | local DB `exists()` | `:69-71` |
| URL regex `fresha.com/.../a/<slug>` → 422 | local, FormRequest | `PlatformRegistryServiceProvider.php:362` |
| "No Fresha URL connected yet." → 404 | local | `:132-135`, `:211` |

The fetch decides only **200 vs 502**. And those three 502 strings
(`FreshaScraper.php:73,77,82`) are **already scrubbed in production**: `bootstrap/app.php:222-224`
passes an `HttpException`'s own message through only for status `< 500`, so with `APP_DEBUG=false` a
502 ships `{"message":"An error occurred"}`. Nothing a user reads today is lost by deferring.

**Individual/team mode splits for free.** `writeConnection()` at `:119-123` uses only `$url` (parsed
at `:75`, pre-fetch) and `$existing` (read at `:116`, pre-fetch). The fetched `$menu` is used *only*
to shape the response body at `:125` — it is never persisted. The write can happen synchronously
before the 202.

**Storewide mode does not.** There `projector->sync($user, $menu['services'])` (`:88`) and the
persisted `selection` (`:89-101`) genuinely depend on the fetch. This branch needs a real pending
row plus job.

**`team()` needs its own conversion.** It reads the stored URL cheaply but then performs the *same*
live scrape as `connect()` (`:137`) with no cache. Making `connect()` async does not make `team()`
fast; it is a separate ~96 s synchronous GET.

**Two risks the deferral introduces:**

- **The Square XOR becomes a TOCTOU.** Today the gap between the check (`:69`) and the write is the
  fetch. Moving the write into a job stretches that gap to queue latency — long enough for a
  concurrent Square connect to land in between and leave both booking providers active, which is the
  exact invariant the check exists to prevent. **Mitigation: re-assert the XOR inside the job's
  locked write, not only in the controller.**
- **`FreshaServiceProjector::sync()` takes `pg_advisory_xact_lock(hashtext('services:{user_id}'))`**
  (`FreshaServiceProjector.php:125-129`) — the same key manual service edits use. In a job it is held
  while the user may be editing services in the dashboard.

### 3.2 Apple — mechanically the cleanest, semantically the weakest

**The deferred half already exists and runs in production.** `AppleMusicFetch::fetch()` /
`ApplePodcastFetch::fetch()` read exactly one key, `$payload['input']`
(`Strategies/Fetch/AppleMusicFetch.php:20-23`), and both are registered
(`PlatformRegistryServiceProvider.php:269-277`) for the refresh cron. A pending row needs
`{input: "<string>"}` and nothing else; `ConnectFetchJob` completes it with no new content code.
This is the prior plan's "the seam is already cut" argument applying to a platform that plan never
examined.

**But Apple has no syntactic gate at all.** Validation is `['required','string','max:200']` with no
regex (`:378-379`), and the two iTunes calls are strictly dependent — `/search?term=` resolves free
text to a numeric id, then `/lookup?id=` fetches content (`AppleSearch.php:143,156` → `:29,59`). The
id cannot be obtained without a network hop.

There is no equivalent of the prior plan's YouTube remedy here. YouTube could assert a handle
charset because handles have one; **artist names are free text, and `"Radiohead"` and `"asdkfj"` are
syntactically identical.** So the honest options are:

- **(i) RECOMMENDED — accept it.** A typo'd artist writes a pending row that resolves to `failed`
  within seconds and is visible via the poll. The messages
  *"Could not find that Apple Music artist or an album."* / *"...that Apple Podcast or an episode."*
  (`AppleController.php:199,215,233`) move from a synchronous 404 body to
  `connectFetchErrorMessage()`, surfaced verbatim by the poll. The row is a stub, not garbage: it
  carries the user's own input and is replaced or removed on retry.
- **(ii) Keep Apple synchronous.** Defensible, but Apple is the second-worst latency offender, so
  this forfeits most of the win.

**This is a sign-off item, not an implementation detail.** It is the one place where "preserve inline
validation" cannot be fully honoured, and the design should not pretend otherwise.

The URL fast-path already inside `AppleSearch` (`:138-140`, `:151-153` — `music.apple.com/artist/…/(\d+)`
and `podcasts.apple.com/…/id(\d+)`) could be hoisted to become a *genuine* inline gate **for pasted
URLs only**, giving a real syntactic check for that input shape while free-text names stay under (i).

### 3.3 Eventbrite / Humanitix — accounts split, standalone events do not

**Accounts split cleanly.** `resource_id` is `accountResourceId()` = `sha1` of the normalised URL
(`ManagesIntegrationConnection.php:361-363`) — derivable **before** the fetch. So is the 5-account
cap: it is evaluated after the fetch today (`EventsPlatformController.php:76-88`) purely because of
code order, not a data dependency, and can be hoisted ahead of the 202.

**Standalone events do not.** `resource_id = 'event-'.$payload['id']` is derived from the *fetched*
page's own declared link (`EventsPayload::withIds`, `EventsPayload.php:35-43`;
`EventbriteScraper::parseEvent`'s `link` can differ from the posted URL, `:189,201-204`). A pending
row cannot be correctly keyed before the fetch. **Recommendation: `addEvent()` stays synchronous in
this programme** — it is one fetch (~96 s), not the multi-fetch case, and reconciliation would cost
more than it saves.

**Undeliverable if deferred:** *"Could not load that Eventbrite page."* / *"...that Humanitix page."*
(`EventsPlatformController.php:70`) → move to `connectFetchErrorMessage()`.

**`EventsCatalog` is a second synchronous writer of the same rows.** `POST /api/platforms/events/add`
(`routes/api/platforms.php:212`) calls the same scrapers and writes the same
`site.platform_connections` rows, and its own comment flags the existing race
(`EventsCatalog.php:222-227`). Converting the controllers leaves the same logical action with two
different latency contracts depending on which endpoint the client hits. **Either convert both or
state explicitly that `events/add` stays synchronous** — do not leave this undecided.

### 3.4 Skool — smallest, with two wrinkles

Local: URL regex plus a reserved-slug blocklist (`SkoolScraper.php:24-34`). Pending payload:
`{url}`. Undeliverable if deferred: *"Could not read that Skool community — check the URL."*

**Wrinkle 1 — status-code divergence.** Skool returns **404** for the same semantic condition where
Eventbrite/Humanitix return **422** (`SkoolController.php:43` vs `EventsPlatformController.php:70`).
The generic poll shape collapses both to `{status:'failed', error}` at HTTP 200, so any client using
the status code to distinguish "bad input" from "not found" loses that signal. Flag it in the
contract rather than silently normalising.

**Wrinkle 2 — the write is deliberately unlocked.** `ManagesIntegrationConnection.php:315-317` names
`skool` in the PWL-16 register as having nothing to race. Deferring the write to a job reintroduces
exactly the concurrency the comment argues away. **Take the lock in the job.**

### 3.5 Shop — blocked without a migration at the time this was written; unblocked 2026-07-24, see below

**Original framing (2026-07-23) — two independent blockers:**

1. **Blocker 1 — the identity is the probe result.** `brand_id` is what detection computes — a numeric
   shop id from Shopify's `meta.json`, `bigcartel-{account}`, or a host slug for Woo/Squarespace/generic.
   There is no cheap `identify()`; provider-agnosticism is the feature
   (`ShopProviderDetector.php:5` — the user never picks the provider). A correctly-keyed placeholder
   cannot be written before the fetch.
2. **Blocker 2 — the status has nowhere to live.** `site.shop_brands` has no status column
   (`20260704160000_shop_brands_products.sql:15-38`), and the connection row's payload is the frozen
   marker `{"storage":"relational"}`, so `last_refresh_status` cannot express "brand A pending,
   brand B ready."

**Verification (2026-07-24, `.superpowers/sdd/PLAN-2026-07-24-connect-shop.md` + its progress ledger)
found the framing above understated the problem by one blocker and carried one stale premise:**

3. **Blocker 3 — the two-blocker framing missed a second `NOT NULL` probe output.** `provider` is
   `NOT NULL` on `site.shop_brands` exactly like `brand_id`
   (`20260704160000_shop_brands_products.sql:18-19` — `brand_id text NOT NULL`, `provider text NOT
   NULL`), and `provider` is *also* an output of the same probe that computes `brand_id`. Blocker 1
   framed this as one identity problem; it is two required columns, both outputs of the call being
   deferred, not one.

**PREMISE-STALE — "reuse `ConnectFetchJob`" does not hold.** `ConnectFetchJob::handle()` resolves the
platform's registered `FetchStrategy`. Shop's is `ShopFetch`, the 6-hourly **latest-mode product
re-sync** over brands that already exist — it never touches `ShopProviderDetector` and cannot turn a
pasted URL into a brand. Reusing it would throw `FetchNotModifiedException` → `markOk()` → a row that
reports success having created nothing. Corroborated by `DefersBespokeConnect`'s own docblock: *"A
future platform needing a different job writes its own dispatch and does not use this helper."*
→ Shop needed its own job.

4. **Blocker 4 — `ConnectFetchJob::uniqueId()` collapses N brands into one job.** Its `uniqueId()` is
   `"{platform}:{connectionId}"`, `uniqueFor = 120`. Shop is the only platform where one connection row
   fans out to up to `MAX_BRANDS = 5` brands — two brands added within 120 s of each other would share
   a `uniqueId`, and the second job would be silently dropped, stranding that brand `pending` forever.
5. **Blocker 5 — the shared poll action cannot be reused.** `DefersBespokeConnect::bespokeConnectStatus()`
   reads `last_refresh_status` off the **connection** row — one status field describing N brands, the
   same limitation as Blocker 2 restated at the poll layer, not just the write layer.

**The resolution — design path (c), hybrid.** Detection stays fully synchronous, so both `NOT NULL`
columns (`brand_id`, `provider` — Blocker 3) are truthful at write time; a real `ShopBrand` row is
written immediately with `connect_status = 'pending'`; only the profile fetch
(`name`/`currency`/`favicon`/`logo`) defers to a new brand-keyed job, `ShopBrandConnectJob`, closing
Blockers 4 and 5 and the `ConnectFetchJob` premise together — its uniqueness key is the brand row, not
the connection, and it carries its own poll action. Per-brand state lives in a new nullable
`connect_status` / `connect_error` column pair. No sentinel `provider`, no provisional-key rename, no
`UNIQUE (connection_id, brand_id)` collision, no id-swap on the wire.

**The honest cost note.** Only 3 of the 6 provider paths defer any network work at all: shopify (2
calls), woocommerce (2 calls), squarespace (1 call). BigCartel, generic and client-assisted defer
**zero** — their profile is already in memory at detection time, so they stay synchronous `200`s (see
the frontend contract §8 for the resulting provider-dependent status code). The 1–4-call probe cascade
that dominates Shop's latency stays synchronous by design on every path — that is what keeps `brand_id`
and `provider` truthful. W1's 20 s `FetchBudget` already bounded that cascade's worst case; W9 only
shortens the *typical* case, and only for three of the six providers.

**Superseded 2026-07-24** — the recommendation below was this design's near-term answer before the
verification above and Josh's decision to proceed with all 7 units. Kept for the record, not deleted:

> **Shop's best near-term fix is not async at all — it is a `FetchBudget`** (see W1). That converts a
> ~768 s tail into ~20 s for a fraction of the work and with no contract change.

Two further Shop notes for whoever picks it up: `setProducts()` runs its vendor fetch **inside** the
10 s Redis lock (`:365,376-377`), which can expire mid-closure while the DB transaction at `:389-399`
is still open; and the cache-purge observer never fires on Shop writes because the payload is frozen,
so every mutator calls `IntegrationConnectionCacheRefresher::refresh()` explicitly — a job writing
`ShopBrand` rows must do the same, since the observer watches `IntegrationConnection` only.

### 3.6 Do the three "identical" controllers share one shape?

**No — the resemblance is at the HTTP layer only.** They share the field name, `required|string|max:500`,
and the "local regex → fetch → error-if-null → write" outline. They diverge on:

| Aspect | Eventbrite | Humanitix | Skool |
|---|---|---|---|
| Fetch-miss status | 422 | 422 | **404** |
| Lock on write | yes | yes | **none** (PWL-16) |
| Response envelope | flat array with `id` | same | `SkoolConnectionResource`, **no `id`** |
| Normalisation | regex only | **can itself be a network fetch** (`HumanitixScraper.php:42-49`) | regex + reserved-slug blocklist |
| Write-adjacent endpoints | 4 | 4 | 1 |
| Account cap | 5 | 5 | n/a (single selection) |

Humanitix is the notable one: resolving a bare event URL to its host page is a live fetch *inside
what looks like local validation*. Any shared wrapper must parameterise at minimum the failure status,
the lock decision, and the response-shape translation — so treat these as **three units, not one**.

---

## 4. Cross-cutting constraints

Every unit must honour these. Each is drawn from a specific existing comment or defect.

1. **Guards run in the controller, before dispatch.** `InstagramController.php:44-46`. Any cap,
   capability, XOR or budget check belongs pre-enqueue so its rejection is synchronous and free.
2. **Dispatch stays outside the row lock.** `InstagramController.php:60-68`: under
   `QUEUE_CONNECTION=sync`, `dispatch()` runs `handle()` inline, so dispatching inside a 10 s lock
   would hold it across the entire fetch.
3. **Always resolve to a terminal state; never `release()`.** `ConnectFetchJob.php:179-206` — under
   `SyncQueue`, `release()` is a silent no-op, stranding the row `pending` forever.
4. **Port the staleness check from the generic controller, not from Instagram.**
   `GenericPlatformController.php:236-253`.
5. **A pending row is publicly active.** `writeConnection(pending: true)` sets `is_active => true`
   (`:179`). This resolves the prior plan's open P2-17 question in code: pending rows render. Every
   one of the six must have its public render path verified against a partial payload.
6. **Re-check `assertPlatformAvailable()` at write time.** `ManagesIntegrationConnection.php:87-92`.
   A staff disable between the 202 and the job must still block the write. Note
   `ConnectFetchJob.php:164-177` currently updates the row directly and **bypasses** this — a gap to
   close if the job is reused for a platform with a live staff kill switch.
7. **The first content fill must not use `saveQuietly()`.** `IntegrationConnectionObserver::saved()`
   purges the edge cache on `wasChanged('payload')` (`:44-51`); `ConnectFetchJob`'s bookkeeping
   writes are deliberately quiet (`:248,263`). Copy-pasting the quiet pattern onto the content write
   silently kills the purge.
8. **Tests must not use the sync driver to prove async behaviour.** `Queue::fake()` to assert
   dispatch; instantiate the job and call `handle()` directly to assert behaviour. On `sync`, a poll
   immediately after connect returns `ready`, never `pending` — dev cannot exercise the pending state.
9. **Postgres vs SQLite.** `payload` is `NOT NULL DEFAULT '{}'`; a `null` placeholder passes SQLite
   and 500s in production with `SQLSTATE 23502`. Assert payload *content* as the enforceable proxy.

---

## 5. B3 — work units

Gate classification per `scripts/audit/fix-flow.md` §1a: a unit pauses for sign-off if it is P0, or
touches auth/money/DB-schema, or is L/XL effort.

| # | Unit | Scope | Effort | Gate | Depends on | Contract change? |
|---|---|---|---|---|---|---|
| **W1** ✅ | **`FetchBudget` for the six** | Open a budget in `ShopController`, `AppleController`, `FreshaController`, `EventsPlatformController`, `SkoolController`. Pure Phase-1 parity. | **M** | no | — | **none** |
| **W2** ✅ | `DefersBespokeConnect` concern | The shared trait: flag check, 202 builder, poll action with the staleness check ported from `GenericPlatformController.php:236-253`. No platform wired yet. | **M** | no | — | none (inert) |
| **W3** ✅ | Apple (both slugs) | `connectFetchError()` on `apple-music`/`apple-podcast`; pending write `{input}`; dispatch `ConnectFetchJob`; two status routes. `FetchStrategy` already exists. | **S/M** | no — **discharged by decision 1** | W2 | 202 + poll |
| **W4** ✅ | Skool | Pending write `{url}`; **take the lock in the job**; status route. Reconcile `selection()`'s pending-vs-absent ambiguity. | **S** | no | W2 | 202 + poll |
| **W5** ✅ | Eventbrite + Humanitix accounts **+ `EventsCatalog`** | Hoist the cap ahead of the 202; pending write; status route ×2. Convert `EventsCatalog::addByUrl`'s **organiser branch** (§6.1) to share those same two poll endpoints. `addEvent()` and the event/custom branches stay synchronous. | **M/L** | no | W2 | 202 + poll |
| **W6** ✅ | Fresha individual mode | Write the URL-only row synchronously, 202, defer the menu fetch. | **M** | **yes** — capability + XOR | W2 | 202 + poll |
| **W7** ✅ | Fresha storewide | Real pending row + job; **re-assert the XOR in the job's locked write**; handle the advisory-lock overlap. | **L** | **yes** — capability, money-adjacent (booking), L | W6 | 202 + poll |
| **W8** ⏸ | Fresha `team()` | Independent conversion — it is its own ~96 s synchronous GET. | **M** | no | W6 | new |
| **W9** ✅ | Shop | Migration for a per-brand status column (or provisional-key reconciliation); pending `ShopBrand` row; explicit cache refresh in the job. | **XL** | **yes** — DB migration + XL | W1, W2 | 202 + poll |

**Phase 3 shipped 2026-07-24** — branch `audit-fix/connect-async-impl-2026-07-24`, 15 commits,
awaiting review/merge. **W2–W7 complete; W8 deliberately dropped** (⏸) — the frontend contract's
"Not yet specified" section states `GET /fresha/team` is *"scheduled separately"* with its contract
*not final*, and that governs over this table's "new" entry. W6's `payload.teamMenu` snapshot lays
its groundwork. An extra unit, **`CA-SM`**, was added mid-run at Josh's direction to fix three live
defects in the shared machinery, and **`CA-DM`** adds a consolidated dark-merge proof.

**Seven corrections to this design were established during implementation** — read these before
trusting the sections above:

1. **§3.1's headline XOR risk is backwards.** "Moving the write into a job stretches that gap to
   queue latency" is false for the shape implemented: the row-creating write never moves into the
   job, only the content fill does. Deferral **narrows** the window, because the pending row — what
   makes `hasConflictingConnection('fresha')` true — is written *before* the 202.
2. **§3.1 misses the hazard that actually matters.** The reason W7 needs `bookingXorLock` is that
   `FreshaServiceProjector::sync()` **resurrects rows tombstoned as `deleted_origin='sync'`**, so a
   `forget()` landing mid-flight has its service teardown silently undone — not the TOCTOU.
3. **§3.1's "nothing is persisted" stops one step short.** If nothing is persisted, the poll's
   `ready` body has nothing to return, while the contract demands the full old-200 shape. W6 must
   add a private `payload.teamMenu` snapshot — new persistence this design never mentions.
4. **§3.3/§6.1's "unlocked-write race … remains a separate defect" is STALE.** PWL-13 (commit
   `2b0307d5`, 2026-07-22 — the day *before* this design) already closed it: both
   `EventsCatalog::storeAccount()` and `storeStandalone()` call `withPlatformLock()`. That fix's
   own justification comment, however, *is* invalidated by W5 and was rewritten.
5. **§3.3's "the cap … can be hoisted ahead of the 202" describes work that is not needed** — and
   cannot be done as described. The cap lives in `ManagesIntegrationConnection::writeAccountConnection()`
   and needs the canonical key to tell a new account from a reconnect; a bare `count() >= 5` would
   422 a legitimate reconnect. Writing the pending row before the 202 satisfies it by ordering alone.
6. **§3.3 treats Eventbrite and Humanitix as splitting identically; §3.6's own table contradicts it.**
   `HumanitixScraper::resolveHostUrl()` is a **live network fetch** and it *is* the row's identity,
   so Humanitix keeps one synchronous hop; only the account scrape defers.
7. **§3.2 miscounts Apple's failure messages** — there are **two**, not three; the third citation
   lands in unrelated closure code.

**Three live defects in the shared mechanism were found and fixed** (unit `CA-SM`), all of which
predated this design and affect the eight already-armed registry platforms:
`ConnectFetchJob` never opened a `FetchBudget` despite its own comment claiming one bounded the
fetch (so the vendor call ran unbounded against a 45 s timeout); the hourly refresh cron selected
`pending` rows and could flip a stranded one to a bogus `ok`, defeating the 5-minute staleness
backstop; and the job's locked write bypassed `assertPlatformAvailable()`.

**W1 shipped 2026-07-24** — branch `audit-fix/connect-fetchbudget-2026-07-24`, awaiting review/merge.
**W9 unblocked 2026-07-24** — branch `audit-fix/connect-shop-async-2026-07-24`
(`.superpowers/sdd/PLAN-2026-07-24-connect-shop.md`). Verification found the §3.5 two-blocker framing
understated the problem and carried one stale premise; design path (c) — synchronous detection,
deferred profile fetch, a new nullable `connect_status` column — resolves all five findings (see the
updated §3.5). Migration, model, the brand-identity seam and the job (Units 1–3) shipped and were
independently reviewed; the deferred `addBrand()` + poll wiring (Unit 4) and this document's sibling
frontend contract (Unit 6) are in flight as of this write.
**Seven** call-site groups, not six: `EventsController::add()` (the smart-detect facade over the same
scrapers) was unbudgeted too and is included. Two adjacent null-deref defects surfaced and were fixed
with it — `ShopifyScraper::fetchProducts()` and `HumanitixScraper::resolveHostUrl()` each read
`$res['status']` on a `tryFetch()` that returns **null** on transport failure, so the intended 502/422
became a 500 via `ErrorException`; both are pre-existing, and an exhausted budget makes that path
routine. `FreshaController` additionally translates `SafeUrlException|ConnectionException` → 502,
because `FreshaScraper` is the only scraper using the throwing `fetch()`.

Known residual (not addressed by W1): `FreshaScraper::fetchEmployeeServices()` calls the booking
GraphQL through a raw `Http::…->timeout(12)->post()` rather than `SafeUrlFetcher`, so it is
budget-blind — `saveSelection()`'s worst case is the 20 s budget **plus** that 12 s timeout, ≈32 s.
Bounding it needs the GraphQL leg routed through `SafeUrlFetcher` (or given its own deadline check),
which is a scraper change outside W1's remit.

### Backend-only, shippable before the frontend

**W1 and W2 are shippable immediately and change no contract.** W1 in particular is the highest
value-per-risk item in this document: it caps Shop's ~768 s and Apple's ~192 s tails at 20 s with no
async work, no flag, no frontend, and no migration. If only one thing ships from this design, ship W1.

**W3–W8 are also effectively backend-only at merge time.** Because they gate on
`PARTNA_CONNECT_DEFERRED`, each lands inert: with the env var unset, every response stays
byte-identical. The frontend becomes a prerequisite only for *activating* a slug, not for merging its
code. This mirrors the prior plan's §2f sequencing.

Per decision 3, the first slice is **W1 → W2 → W3 → W4 → W5 → W6 → W7 → W8** — the whole programme
bar Shop — all merged dark, with activation held until the frontend ships polling (decision 5).

### Sequence

See §7. The merge order and the activation order deliberately differ.

---

## 6. Decisions — signed off 2026-07-23

All six open questions resolved by Josh. Recorded here so the implementation run needs no further
input on scope.

| # | Question | Decision |
|---|---|---|
| 1 | Apple free-text inputs (§3.2) | **Accept it** — option (i). A mistyped artist writes a pending row that resolves to `failed` within seconds, message surfaced verbatim via the poll. Apple does **not** stay synchronous. |
| 2 | Rollout order | **Confirmed:** `skool` → `apple-music,apple-podcast` → `eventbrite,humanitix` → `fresha`. |
| 3 | Is Fresha in the first slice? | **Yes.** W6 and W7 are in the first slice, not deferred to a second. |
| 4 | `EventsCatalog` (§3.3) | **Convert alongside.** `POST /platforms/events/add` is in scope — organiser branch only (see §6.1). |
| 5 | Frontend timing | **No slug is activated before the frontend ships polling.** W1–W8 merge dark; activation is a separate, later action. |
| 6 | Shop (§3.5) | ~~**W1 alone is the near-term answer.** W9 deferred.~~ **Superseded 2026-07-24** — W9 taken up on branch `audit-fix/connect-shop-async-2026-07-24` under design path (c). The deferral reasoning still holds on the numbers (see §3.5's cost note: 3 of 6 providers defer zero network work), so this was re-decided with the quantification on the table, not overturned by new information. |

**Consequences for §5's unit table:**

- **W3's gate is discharged** by decision 1. It no longer needs sign-off before implementation.
- **W5 grows** to cover `EventsCatalog::addByUrl`'s account branch — see §6.1.
- **W6 and W7 stay in the first slice.** W7 remains a blocker-gate unit under
  `scripts/audit/fix-flow.md` §1a on its own merits (capability-adjacent, booking-adjacent, L
  effort), so it still gets plan-then-sign-off at implementation time. That is the runbook's
  procedural gate, not a reopened scope question.
- **The whole programme merges inert.** Because every unit gates on `PARTNA_CONNECT_DEFERRED` and
  decision 5 holds activation until the frontend is ready, W1–W8 can land on `development` without
  any user-visible change.

> **One reading — CONFIRMED 2026-07-24.** Question 3 ("Is Fresha in the first slice?") is confirmed
> **yes**: W6 and W7 stay in the first slice. The opposite reading (defer W6/W7) would have been a
> two-row edit to the §7 sequence — that path is now closed. Phase 3 treats Fresha's inclusion as
> settled; W7 still carries its own procedural blocker-gate per §5, which is unaffected.

### 6.1 `POST /api/platforms/events/add` — scope of the conversion

`EventsCatalog::addByUrl()` (`:71-108`) has three branches, and **only one is deferrable**:

| Branch | Path | Deferrable? |
|---|---|---|
| Event URL | `($a['eventUrl'])($raw)` → `fetchEvent` → `storeStandalone` (`:85-92`) | **No.** Identity comes from the fetched page — the same blocker that keeps `addEvent()` synchronous (§3.3). |
| Organiser/host URL | `($a['accountUrl'])($raw)` → `fetchAccount` → `storeAccount` (`:95-102`) | **Yes.** `accountResourceId()` is derivable pre-fetch, identically to the per-platform `connect()`. |
| Custom link | `storeCustom($user, $raw)` (`:107`) | Out of scope — unchanged. |

So the conversion is **organiser branch only**, precisely mirroring the 2026-07-02 contract's
"custom branch only" precedent for `booking/detect` and `reservations/detect`. The response envelope
differs from the per-platform connects: `events/add` returns `{selection: <unified accounts+events
list>}` (`EventsController.php:33`), so its 202 carries the full list *including* the pending row,
the way the link-card contract returns `entries` — and its `statusUrl` points at the underlying
platform's status endpoint, since the row written is an ordinary eventbrite/humanitix account row.

This closes the two-latency-contracts hazard: after W5 both `POST /platforms/{eventbrite,humanitix}/connect`
and the organiser branch of `POST /platforms/events/add` return 202 and share one poll endpoint per
platform. It does **not** close the pre-existing unlocked-write race that `EventsCatalog.php:222-227`
flags — that remains a separate defect, out of scope here.

---

## 7. Revised sequence

```
W1  FetchBudget ×6 ──────────────────────► no gate, no contract change, ship first
      │
      └─ W2  DefersBespokeConnect ──┬─ W4  Skool                    ← activate 1st
                                    ├─ W3  Apple (both slugs)       ← activate 2nd
                                    ├─ W5  Eventbrite + Humanitix
                                    │      + EventsCatalog organiser branch  ← activate 3rd
                                    └─ W6  Fresha individual ─┬─ W7  Fresha storewide  ← activate 4th
                                                              └─ W8  Fresha team()

W9  Shop ── taken up 2026-07-24 (decision 6 superseded). Independent of W2–W8:
           shares only DefersBespokeConnect::shouldDeferConnect(), and runs its
           own job + its own per-brand poll (blockers 4 and 5, §3.5). Merges on
           its own branch; W1 had already removed its acute risk.
```

**Merge order follows the dependency arrows; activation order follows decision 2** — and the two are
deliberately different. W3 is implemented before W5 but Skool activates before Apple, because
activation order is about blast radius while merge order is about dependencies. Nothing activates
until the frontend ships polling (decision 5).
6. **Shop (§3.5).** ~~Confirm W1 alone is an acceptable near-term answer for Shop, deferring W9.~~
   **Answered 2026-07-24: W9 proceeds**, with the win explicitly quantified first — see §3.5.
