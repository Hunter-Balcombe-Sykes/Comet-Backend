> **SUPERSEDED (2026-08-27):** built and shipped by
> `docs/2026-08-26-menu-item-deep-links-and-cleanup-plan.md` — see its RUN
> CHECKPOINTS. Kept only as the original research record; delete with the
> plan when the run completes.

# Handoff: per-item Uber Eats deep links (research complete, nothing built)

Context: sitepage menu items now show an "ORDER ON" overlay with platform
wordmarks, but every dish links to the STORE page. We researched whether we
can link each dish to its own Uber Eats item modal. **Answer: yes — proven
empirically on 2026-08-26.** This doc is the full state of that research for
planning the build in a fresh chat. No code has been changed anywhere.

## What we store today (and why it's store-level)

- The scraper's normalized item shape (`app/Services/Platforms/MenuApifyScraper.php`
  header comment, ~line 27) is: `externalId, name, description, price, image,
  rating, ratingCount, badges` — no per-item URL. For Uber Eats specifically,
  `UberEatsMenuDriver::mapItems` hardcodes `'externalId' => null` (~line 48)
  because the memo23 actor's default output has no per-item id.
- Per-dish rows in `site.menu_item_platforms` DO have `pickup_url` /
  `delivery_url` (`MenuFetchJob::platformRows`, ~line 566), but the values are
  filled from the STORE link in `MenuMerger::platformEntry` (~line 605): the
  mode-typed store URL, else the bare store URL. Every dish on a store gets
  the same pair. The pickup/delivery split is about which storefront MODE you
  land on, not which item.
- So: nothing item-specific is persisted anywhere today.

## The URL anatomy (verified against a live example)

Example item URL (LATTE at ST. ALi):
`https://www.ubereats.com/au/store/st-ali/nK322-yMR8iIAJcSkfzELQ/f81f8200-767f-54ae-abee-57414d64a219/01b765b1-523c-5e23-9693-699c97e5cd91/7861fa32-2b88-5610-a708-da1785f6dbff`

- `nK322-yMR8iIAJcSkfzELQ` = urlsafe-base64 of the store UUID
  (`9cadf6db-ec8c-47c8-8800-971291fcc42d`) — decode with 2 pad chars.
- The three trailing UUIDs are `sectionUuid / subsectionUuid / itemUuid` —
  confirmed by reading the page's embedded state (`itemUuid` appears verbatim).
- They are UUIDv5s but NOT derivable from names — we tested uuid5 over
  store/section/sub namespaces × name casings; no match. Uber generates them
  server-side. You need the actor to hand them over.
- There is also a quickView form the actor emits ready-made (see below):
  `{storeUrl}?mod=quickView&modctx=<urlencoded JSON of the 4 uuids>&ps=1`.

## The proof: same actor, one input flag

The memo23 actor (`memo23~uber-eats-scraper`, the one MenuApifyScraper already
rents) has an input option **`includeItemCustomizations: true`** ("fetches
Uber Eats item option trees"). Test run on ST. ALi, 2026-08-26:

- Run id `dTQULHB7Vbuhb1WNN`, dataset `Q5oiHGiDFl8lOU9Yp`, SUCCEEDED in ~15s,
  82 items. (Baseline run for comparison: dataset `mCRmFlLmVvpJXteIH` — 85
  items, default flags, no ids.)
- With the flag, EVERY menuItems entry gains:
  `itemUuid`, `sectionUuid`, `subsectionUuid`, `href` (the ready-made
  quickView deep link), `basePrice`, `isSoldOut`, `hasCustomizations`,
  `customizationGroupCount/OptionCount`, `customizations[]` (full option
  trees: sizes, milks, add-ons, each with uuid/title/min/max/price).
- The LATTE row's three uuids EXACTLY match the example URL above — so either
  URL form can be constructed: append `/{sectionUuid}/{subsectionUuid}/{itemUuid}`
  to the store URL we already store, or prefix `href` with `https://www.ubereats.com`.

Inspect the dataset anytime:
`curl "https://api.apify.com/v2/datasets/Q5oiHGiDFl8lOU9Yp/items?token=$APIFY_TOKEN&limit=1"`
(token = `APIFY_TOKEN` in Comet-Backend/.env).

## Build sketch (not started)

1. `UberEatsMenuDriver::buildInput` → add `includeItemCustomizations: true`;
   `mapItems` → carry `itemUuid` into the waiting `externalId` slot and pass
   the item URL (href or composed path form) through the normalized shape.
2. Persist per-item: add an item-level URL to the merged dish →
   `menu_item_platforms` rows (`MenuFetchJob::platformRows` /
   `MenuMerger::platformEntry` — today's platformEntry only knows the store
   link; it needs the per-item field plumbed alongside). Persist `externalId`
   too — it makes future re-scrape matching exact instead of name-hash.
3. Projection/wire: `MenuProjectionMapper` offers + `PoolResolver` links so
   the sitepage wire's per-dish `links[]` carries the item URL. The frontend
   "ORDER ON" overlay (already live) needs zero changes — it renders whatever
   platform links the wire hands it.

## Open questions / caveats for planning

- Cost: the flag makes the actor fetch option trees per item — more compute
  per run. The 82-item test still ran ~15s; watch the billed units on the
  Apify console for run `dTQULHB7Vbuhb1WNN` vs a baseline run.
- Uber-only so far. DoorDash (`DoorDashMenuDriver`) DOES get `ddExternalId`
  in its raw payload (currently documented as dropped) — its item-page URL
  anatomy needs the same research pass. Square likewise.
- Mode question: the item URL has no pickup/delivery mode in it — decide
  whether one item link replaces both mode URLs on the card, or rides
  alongside them.
- Sold-out: `isSoldOut` arrives free with the flag — worth persisting while
  in there? (Could hide/badge sold-out dishes on the sitepage.)
- The scrape-vs-projection dedup work in
  `docs/2026-08-26-backend-fixes-for-dev.md` (items 1–3) touches the same
  files (MenuFetchJob / matcher logic) — sequence this with that plan so the
  backend dev isn't rebasing over us.

## Related live context

- ollies (dev) has ST. ALi connected with the Uber Eats store above; menu has
  89 items, 66 from Uber. The sitepage "ORDER ON" overlay is already deployed
  (pages version `95ad95be`) and currently deep-links to the store URL.
