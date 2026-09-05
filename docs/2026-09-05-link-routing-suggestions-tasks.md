# Link routing / suggestions — running task list

Started 2026-09-05 from the `squeakprobarber` test signup
(`tobiasindarwin@gmail.com`), diagnosing the extra "Your links" rows (an
already-connected Booksy account, and JRLUSA showing as both an online store
connection AND a custom link). Add to this as we go; delete the file when
everything in it has shipped.

Status: `OPEN` ready to build · `HELD` needs an owner decision first ·
`DONE` shipped (leave the entry until the whole file goes).

**Nothing in here is being executed yet.** Do not start until the owner says
go.

**Standing rule for every task in this file:** each fix ships with a
regression test built from the REAL data below — the actual URLs, timestamps
and payloads observed on the `squeakprobarber` signup — not an invented
fixture.

---

## 1. OPEN — Single-slot platforms: show every account found as a candidate suggestion, let the person pick one

**What happens today:** `LinkRouter::routeClassified()` has a first-link-per-
platform lock
([LinkRouter.php:116-118](../app/Services/Platforms/LinkRouter.php#L116-L118)):

```php
if (! $itemCategory && ! $slotSelfManaged && isset($ctx->seenPlatforms[$platform])) {
    return RouteResult::custom();
}
```

The first Booksy/Facebook/etc. link found in a run claims the platform slot.
Every other link for that same platform in the same run is dumped straight to
`custom()` — a plain link card, no suggestion row, no memory of it being the
same platform. Live evidence: `squeakprobarber`'s bio carried two Booksy URLs
— the venue's own listing (id `100214`, which won the slot and got connected)
and the barber's personal Booksy profile (id `47636`, which fell to a bare
custom-link card reading "SqueakProbarber - Orlando - Book Online...").

**What to build:** for single-slot categories (`booking`, and worth
evaluating for `shop`), every account found should become its own candidate
suggestion — all shown together — and the person picks ONE to actually
connect. This already has a working precedent in the codebase:
`site.workplace_candidates` + `WorkplaceCandidates::adopt()`
([WorkplaceCandidates.php](../app/Routing/WorkplaceCandidates.php)) — many
`proposed` rows, adopting one sets it `adopted` and supersedes every sibling
in the same call.

There's also a partial precedent already wired for a DIFFERENT pair of
categories: `reservations` and `online-ordering` skip the dead-end above and
call `SourceReconciler::recordCapBlock()`
([LinkRouter.php:451-471](../app/Services/Platforms/LinkRouter.php#L451-L471)),
which files a `blocked`/`cap_reached` intent rendering as a "Swap" offer in
the suggestions inbox. `booking` never got that treatment.

**Concrete pieces:**
- [ ] `LinkRouter.php:116-118` — for single-slot categories, stop
  short-circuiting to bare `custom()`. Route to something that records an
  ADDITIONAL candidate row instead of dropping it (extend `recordCapBlock`'s
  Swap mechanism, or a `routing.source_intents`-based candidate state modeled
  on `workplace_candidates`).
- [ ] Whatever records the candidate needs "adopt supersedes siblings"
  semantics, same as `WorkplaceCandidates::adopt()`.
- [ ] Only ONE candidate per platform should ever carry `band: auto`
  (pre-ticked) at a time — the rest arrive `band: suggest`, unticked — so
  Continue never tries to apply two accepts for the same platform in one
  batch and trip `withBookingXorLock`'s conflict path.
- [ ] Frontend: `SetupPayload::suggestionRows()` already returns a flat list
  and `get-started-flow.tsx` already renders `pass.suggestions` as
  independent rows, so multiple same-platform rows should already display —
  confirm there's no dedup-by-platform on the client that would need
  removing.
- [ ] Once one candidate is adopted, prune any custom-link card its sibling
  discovery already wrote (ties into item 2 below).

## 2. OPEN — Don't write a custom-link card for a URL that already became a platform suggestion

**Live evidence (squeakprobarber, JRLUSA):**
- `routing.source_intents`: `shopify.store` / JRLUSA, `first_seen_at
  00:23:51`, `origin: commerce_probe`, `band: auto`.
- `content.source_items` (the `custom_links` pool): a JRLUSA card, ALSO
  `first_seen_at 00:23:51` — same second.

**Root cause, fully deterministic — not a race, not "found twice":**
1. `CommerceProbeJob` probes jrlusa.com, confirms it's a Shopify store via
   `StoreBrandSeeder::seed()` → `PlacementPolicy::decide()`.
2. `PlacementPolicy::decide()` NEVER returns `Verdict::Place` for a signup
   build — only `Verdict::Choose`
   ([PlacementPolicy.php:184-186](../app/Routing/PlacementPolicy.php#L184-L186)):
   *"Sign-up builds connect nothing by themselves… every match is a Choose
   the setup dialog renders."* This correctly files the suggestion.
3. Back in `CommerceProbeJob::handle()`, the fallback check only recognises
   an actual placement as success — `seedStore()` returns `true` only when
   `outcome === 'placed'`
   ([CommerceProbeJob.php:329-330](../app/Jobs/Platforms/CommerceProbeJob.php#L329-L330)).
   A `Choose` outcome is `not_placed`, so this reads as **failure**, even
   though a correct suggestion was just filed.
4. Because it "failed," `handle()` falls through to
   `$links->seedCustom($user, $this->url)`
   ([CommerceProbeJob.php:150-152](../app/Jobs/Platforms/CommerceProbeJob.php#L150-L152)),
   writing the duplicate custom-link card in the same request.

This will happen for **every** signup that finds a shop (or hits the
`seenPlatforms` short-circuit from item 1) via the commerce probe — systemic,
not JRLUSA-specific.

**What to build:**
- [ ] `CommerceProbeJob::handle()` (or `seedStore()`'s return contract) needs
  to treat "filed a Choose/candidate suggestion" as a resolution, not a
  miss — so the discovery lane doesn't call `seedCustom()` for a URL that
  already has a live intent.
- [ ] More generally: `CustomLinkSeeder::seedCustom()`/`writeCard()` should
  refuse (or the caller should skip calling it) when a `routing.source_intents`
  row already exists for the same URL/user with a non-terminal state
  (`proposed`, `verifying`, `applied`) — this covers the item-1 candidate
  case too, so a sibling Booksy account doesn't ALSO get a stray link card.
- [ ] Once a suggestion is later accepted (Continue) and becomes a real
  connection, prune any custom-link card that was written for that exact URL
  before the fix above ships (a one-off cleanup, or a check inside
  `SuggestionApplier::apply()`).
- [ ] Regression test: seed a signup-build shop discovery for a URL,
  assert exactly ONE artifact exists afterward (the `routing.source_intents`
  row) and zero `custom_links` cards — using jrlusa.com's real shape from
  this signup.

## 3. OPEN — A shop connected by accepting a suggestion never fetches its products

**Symptom (squeakprobarber):** JRLUSA ticked in Get Started's "Your online
store" step, Continue pressed, the `shopify.store` connection was created at
`00:32:11` — and the "Your products" (`items.shop`) step never appeared.

**Proof:** `content.collections` has **zero rows** for this user — no
`storefront` collection was ever minted, so the `shop` pool is empty, and
`SetupPayload::composePass()` correctly omits an empty, ready item pass
([SetupPayload.php:270-272](../app/Services/Setup/SetupPayload.php#L270-L272)).
Nothing is wrong with the step — the catalog was simply never pulled.

**Root cause:** two lanes create a shop connection; only one fetches the
catalog.
1. **Paste/confirmed lane** — `StoreBrandSeeder::seed()` reaches
   `Verdict::Place` and dispatches `ShopInitialFillJob` on a new brand
   ([StoreBrandSeeder.php:236-238](../app/Services/Brand/StoreBrandSeeder.php#L236-L238)).
2. **Suggestion-accept lane** — `SetupBatchApplier` →
   `SuggestionApplier::apply()` creates the `IntegrationConnection` with raw
   writes ([SuggestionApplier.php:162-231](../app/Routing/SuggestionApplier.php#L162-L231))
   and never touches `StoreBrandSeeder` or `ShopInitialFillJob`. Its only
   enrichment hook, `ConnectFetchJob`, is gated by
   `ConnectionPayload::contentIsOwed()`, which is true only for
   `routing_class === 'content'`
   ([ConnectionPayload.php:37-41](../app/Routing/ConnectionPayload.php#L37-L41))
   — `shop` never qualifies. The comment beside it says *"shop rows enrich
   through their own connect jobs"*, but nothing in this lane calls one.

Same family as item 2: the accept lane creates a connection without doing
what the seeder-driven creation path does.

**What to build:**
- [ ] `SuggestionApplier::apply()` — when it creates a NEW connection whose
  `routing_class === 'shop'`, dispatch `ShopInitialFillJob` (afterCommit,
  same as the `ConnectFetchJob` dispatch beside it) with the store's
  `collectionId`. Mirror what `StoreBrandSeeder::seed()` does on the paste
  lane; keep the "first connect only" guard so a re-accept of an existing
  store doesn't re-fill.
- [ ] Check whether the accept lane has a `collectionId` to hand over at all
  — `StoreBrandSeeder::upsertBrand()` is what mints the `content.collections`
  storefront row, and the accept lane skips it. The fix may need to route the
  accept through `StoreBrandSeeder` (or call `upsertBrand` + `ShopInitialFillJob`)
  rather than just dispatching the fill job against a row that doesn't exist.
- [ ] Confirm `ShopInitialFillJob` emits `STAGE_SHOP` `started`/`landed`
  build-progress rows so `items.shop` reads not-ready (skeleton) while the
  fetch runs instead of being omitted, then appears once products land.
- [ ] Regression test from this signup: accept a `shopify.store` suggestion
  on a signup build, assert a `content.collections` storefront row exists and
  `ShopInitialFillJob` was dispatched.

## 4. OPEN — Get Started "Your products" step: product search + per-store dropdown

**Ask (owner, 2026-09-05):** on the `items.shop` step card, add a search
tool for products, and a dropdown toggle so the person can switch between
each of the online stores they have connected and pick from that store's
products.

**What's already on the wire (no new backend plumbing needed for the
basics):** pool items already carry per-item store attribution and search
fields — `collectionIds` ([PoolResolver.php:1766](../app/Site/Pools/PoolResolver.php#L1766)),
plus `vendor` and `description`
([PoolResolver.php:73](../app/Site/Pools/PoolResolver.php#L73)) — and the
resolver builds a per-collection stores map keyed by `collectionId`
([PoolResolver.php:2347-2410](../app/Site/Pools/PoolResolver.php#L2347-L2410)),
so store names for the dropdown exist too. Today the step just renders a
flat `ChoiceGrid` of every product from every store.

**What to build:**
- [ ] Frontend, `get-started-flow.tsx` items branch: a search input over
  `name` / `vendor` / `description`, filtering the rendered grid client-side.
  Use the existing `FilterBar` primitive (`apps/dashboard/CLAUDE.md` — "the
  one toolbar row"), not a bespoke input.
- [ ] Frontend: a store dropdown (existing `Select` primitive — device
  picker on mobile for free) listing each connected store, defaulting to
  "All stores", filtering items by `collectionIds`. Hide the dropdown when
  only one store is connected — a one-option select is noise.
- [ ] Confirm the `items.shop` pass wire carries the stores map / store names
  the dropdown needs (it may currently only ship `items` — check
  `SetupPayload::composePass()`'s `items.*` branch, which puts
  `resolvedPools['library']` rows on the wire verbatim). If store names
  aren't on the pass, add them there rather than re-deriving on the client.
- [ ] Depends on item 3 — nothing to search or filter until the catalog
  actually lands.
