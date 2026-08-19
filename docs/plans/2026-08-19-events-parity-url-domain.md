# Events parity: every platform gets Eventbrite-grade item/account handling

Owner ask (2026-08-19): "look at Eventbrite and how it has the strong handling
setup for knowing what is an item and to add it as an item and what is an
account — for all the platforms that are connecting ones and ones that also
have item types, make them work the same, and improve the URL and domain
setup we have." Full permission, ship live when done, real tests.

## What "Eventbrite-grade" means today (the gold standard, verbatim from code)

1. **Account vs item is a grammar, not a guess** — organiser pages (`/o/…`)
   carry a DeepLinkWithSlug detector per regional TLD; single-event pages
   (`/e/…`) are `reservedPaths` on the surface, so they can NEVER auto-connect
   as an account (`LinkProjector::score`), and instead fall through to
   `LinkInBioImporter` → `WebsiteLinkHarvester::classify` → `EventsSeeder`
   which writes a rich events-POOL ITEM (`ManualEventWriter::addStandalone`).
2. **Full domain coverage** — 25 regional TLDs enumerated once in the catalog
   definition.
3. **Canonical URL template** on the surface.

## The gaps found (discovery, this session)

- **Events pool hand-add is a bare card for EVERY platform.**
  `PoolItemCreateController::store` has a STORE-FIRST lane for `shop` but no
  EVENT-FIRST lane for `events`: pasting ANY ticket page (Eventbrite included)
  on the Events page writes headline=host + f_link, no dates/venue/price. The
  pool blurb ("read straight from the ticket page you paste") is aspirational.
- **Luma catalog detector mis-connects events as calendars.** Bare
  `lu.ma/<slug>` (usually an EVENT — WLH::classify says so at
  WebsiteLinkHarvester.php:510-518) scores 75 as a `luma.calendar` account —
  the exact bug Eventbrite's reservedPaths fixed (organiser row placed from an
  event link). Flagged as a tension in Luma.php's own docblock, never resolved.
- **Only eventbrite+humanitix can seed items from scans.**
  `EventsSeeder::PLATFORMS = ['eventbrite','humanitix']`; classify() returns
  `events-custom` for Luma/Partiful/Ticketmaster/Meetup so the seeder refuses
  and the link becomes a plain card.
- **Ticket sellers (ticketmaster/ticketek/oztix/ra/trybooking) have no item
  lane at all** — host-only MarketplaceListing detectors (correctly never
  auto-connect), but their event URLs land as plain link cards everywhere.
- **Domain lists are 5-way duplicated and divergent.** Eventbrite TLDs: 25 in
  the catalog def, 25 in classify()'s regex, 25 in EventbriteScraper::TLDS,
  **2** in ItemLinkRules::HOSTS, **3** in dashboard connect.ts match. Ticketmaster:
  21 in catalog + classify, 2 in dashboard. residentadvisor.net exists in the
  dashboard match but not in Hosts::aliases.
- **ItemLinkRules events roster** is eventbrite+humanitix only — an event item
  can't carry a hand-added Ticketmaster/Luma alternate link.

## Design

The two bespoke scrapers' event-node mapping is already field-for-field
identical (HumanitixScraper::parseEventNode ↔ EventbriteScraper::parseEvent) —
extract it once, then a generic JSON-LD reader gives every other platform the
same item lane. The events POOL lane goes host-agnostic (like shop's
product-first lane reads any product page): any page with Event JSON-LD
becomes a real event item. The SCAN lane extends to the known platforms.

### Phase 1 — shared mapping + generic reader (backend)
- `app/Services/Platforms/Support/SchemaOrgEventNode.php`: node → scraped
  event shape {name, venue, location, startDate, endDate, description,
  startsAt, endsAt, price, priceMin, currency, availability, soldOut, image,
  link}, extracted verbatim; both scrapers adopt it.
- `app/Services/Platforms/EventPageReader.php` (extends PlatformScraper):
  `read(url)` = fetch → jsonLdNodes → first node with startDate → map.
  Bespoke dispatch: eventbrite/humanitix URLs delegate to the existing
  scrapers. `organiserPlatform(url)` names eventbrite `/o/` and humanitix
  `/host/` pages so callers can redirect to connect.

### Phase 2 — EVENT-FIRST events-pool hand-add (backend)
- `PoolItemCreateController`: `events` lane mirroring the shop lane —
  organiser page → 422 "connect it as a platform"; Event JSON-LD found →
  `ManualEventWriter` (cap-checked, 422 mirrors the writer's limit) +
  checked-words overrides + pin; no markup → existing card path.

### Phase 3 — scan-lane generalization (backend)
- classify(): Luma/Partiful/Ticketmaster arms return REAL platform keys;
  add ticketek/oztix/trybooking/resident-advisor event arms; meetup stays
  events-custom (no platform key; the pool lane covers it).
- EventsSeeder: seedStandalone dispatches bespoke (eventbrite/humanitix) or
  EventPageReader (everything else); PLATFORMS extended (incl. events-custom
  passthrough for meetup-shaped hosts). seedAccount unchanged.

### Phase 4 — catalog + URL/domain hygiene (backend)
- Luma: organiser detector becomes `/user/<slug>` only; canonicalUrl
  `https://lu.ma/user/{handle}`. Bare slugs fall through to the item lane.
- Partiful: `reservedPaths('/e/')` (explicit Eventbrite-style guard).
- TLD single source: catalog defs expose `hosts()`; classify() + ItemLinkRules
  consume them. residentadvisor.net → ra.co in Hosts::aliases.
- ItemLinkRules: events roster += luma, partiful, ticketmaster, ticketek,
  oztix, trybooking, resident-advisor; HOSTS from the shared consts
  (eventbrite full 25, ticketmaster full 21, ticketek 4, ra.co, oztix.com.au,
  trybooking.com, lu.ma, partiful.com).

### Phase 5 — dashboard
- connect.ts: eventbrite + ticketmaster match regexes get full TLD sets.
- Anything else only if verification shows it's needed (tiles for luma /
  partiful only if a connect route exists — else paste-routing covers them).

### Phase 6 — tests, deploy, live verification
- Feature tests: pool events lane (JSON-LD fixture, organiser 422, cap 422,
  no-markup fallback), classify keys, seeder generic arm, Luma catalog
  projection change, ItemLinkRules roster/hosts, TLD helper equivalence.
- Full suite → deploy development → live API verification (paste real event
  URLs on a dev account) → dashboard typecheck/lint → push → verify
  app.partna.au events add flow in the Browser pane.

## Execution ledger

- [ ] Phase 1 — SchemaOrgEventNode + EventPageReader
- [ ] Phase 2 — events lane in PoolItemCreateController
- [ ] Phase 3 — classify keys + EventsSeeder generic arm
- [ ] Phase 4 — Luma/Partiful catalog, TLD single-source, ItemLinkRules
- [ ] Phase 5 — dashboard connect.ts domains
- [ ] Phase 6 — tests green, deployed, live-verified

(Tick with the real commit hash as each phase lands.)
