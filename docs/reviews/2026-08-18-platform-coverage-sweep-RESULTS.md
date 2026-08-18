# Platform coverage sweep — RESULTS, 2026-08-18

Prompt: `2026-08-18-platform-coverage-sweep-PROMPT.md`. Test: `tests/Feature/Platforms/CatalogClassificationSweepTest.php`.
Run: `9229efb7e` · `2026-08-18 13:25 UTC` · `110` surfaces (RETIRED_SURFACES excluded: 6, out of 116 total in the compiled artefact).

## 1. Headline

| bucket | count |
|---|---|
| connectable (a hand table answers) | 74 |
| link-only (only the catalog answers — the P8 backlog, by design) | 28 |
| invisible (`classify()` → null) — **the finding class N1 was made of** | 8 |

74 + 28 + 8 = 110, matching the sweep's surface count exactly (no `no-probe` rows once the fixture was filled).

## 2. The invisible list (ranked by plausibility a real user links it)

| # | surface | probe URL | routing_class | why it matters |
|---|---|---|---|---|
| 1 | `squarespace.store` | `https://acme.squarespace.com/shop` | shop | Squarespace is one of the most common small-business website+store platforms. `classifyFromCatalog()` deliberately nulls every `shop`-class surface in `ShopConnections::PROVIDER_SURFACE` (a "provider" store) regardless of path, so `classify()` can never answer for a Squarespace link — the commerce probe is the only path in by design (see §6). |
| 2 | `woocommerce.store` | `https://woocommerce.com/store/acme` | shop | Same provider-surface null-out as Squarespace — WooCommerce is self-hosted on the merchant's own domain, so there is no host signal to detect against in the first place; the catalog entry exists only so the dashboard picker can offer it. |
| 3 | `stan.store` | `https://stan.store/acme` | shop | A named, real host (`stan.store`) with a working catalog detector — but a non-empty path always returns null for a non-provider shop surface ("a deeper path (a product page) keeps the probe" — see `classifyFromCatalog()` docblock). A bare-root `https://stan.store/` would also fail the same code path once the surface becomes a listed provider. Correct-by-design, same trade as Gumroad. |
| 4 | `generic.store` | `https://shop.example.com/products/acme-item` | shop | The catch-all for a business's own self-hosted commerce (Product JSON-LD only, no brand host). Has **zero catalog detectors** — structurally cannot be recognised by host, by design; it exists purely as `ShopConnections::surfaceFor()`'s unknown-provider fallback, never as a `classify()` target. |
| 5 | `direct.book` | `https://acme-salon.example.com/book` | booking | The booking-page fallback for a business with no named brand — same shape as `generic.store`: zero detectors, `lifecycle: hidden`, documented as "usually the business's own site; last arm only, after every real brand has been tried." |
| 6 | `skool.community` | `https://www.skool.com/acme` | content | A real, popular host with **zero catalog detectors** despite a live surface entry — the note says it was demoted to link-only on 2026-08-16 ("the bespoke SkoolController and its scraper were deleted with the demotion") but no detector was ever wired to replace it. This is the one genuine, undocumented-as-permanent gap in the list — everything else above and below it has an explicit "this returns null on purpose" note in the artefact; this one does not. |
| 7 | `google_business.listing` | `https://www.google.com/maps/place/Acme+Salon/@-33.865,151.209,17z` | content | `identifier_kind: place_id`, not `url` — a Maps URL was never going to be how this surface is identified. Connects only through the bespoke Places-search flow (§16), never through link classification. Included in the sweep because the surface exists in the catalog, but it is not really a "link a user pastes" case at all. |
| 8 | `partna.manual_product` | `https://acme.example.com/shop/handmade-necklace` | shop | Partna's own internal manual-product-add mechanism (`ShopConnections::INDIVIDUAL_SURFACE`), not a third-party platform. No detector exists because there is nothing external to detect — a pro adds these directly in the dashboard. Least relevant "link" case of the eight. |

## 3. The link-only list (by design today; P8 sizes it)

28 surfaces classify successfully but land in category `link` — recognised, never auto-connected, no probe spent. This is exactly the P8 backlog (`docs/plans/2026-07-28-p8-deletion-readiness.md`): promoting any row below to a real connection means teaching `LinkRouter` the catalog's routing classes, which this sweep does not do and is not scoped to do.

| surface | display name | routing_class |
|---|---|---|
| `apple_music.artist` | Apple Music | content |
| `apple_podcasts.show` | Apple Podcasts | content |
| `bandcamp.artist` | Bandcamp | content |
| `bandcamp.store` | Bandcamp | content |
| `bella_booking.book` | Bella Booking | booking |
| `buymeacoffee.page` | Buy Me a Coffee | social |
| `circle.community` | Circle | content |
| `codepen.profile` | CodePen | social |
| `easi.order` | EASI | ordering |
| `gitlab.profile` | GitLab | social |
| `gumroad.store` | Gumroad | shop |
| `hungrypanda.order` | HungryPanda | ordering |
| `kajabi.courses` | Kajabi | content |
| `kick.channel` | Kick | social |
| `kitomba.book` | Kitomba | booking |
| `ko_fi.page` | Ko-fi | social |
| `medium.profile` | Medium | social |
| `mixcloud.player` | Mixcloud | content |
| `oztix.tickets` | Oztix | events |
| `phorest.book` | Phorest | booking |
| `resident_advisor.tickets` | Resident Advisor | events |
| `shortcuts.book` | Shortcuts | booking |
| `strava.club` | Strava | content |
| `substack.publication` | Substack | social |
| `ticketek.tickets` | Ticketek | events |
| `tidal.player` | Tidal | content |
| `trybooking.tickets` | TryBooking | events |
| `zenoti.book` | Zenoti | booking |

Seven of these (`bella_booking.book`, `easi.order`, `hungrypanda.order`, `kitomba.book`, `phorest.book`, `shortcuts.book`, `zenoti.book`) are `booking`/`ordering` routing-class surfaces with a real catalog detector but **no entry in the hand-maintained `BOOKING_HOSTS`/`ORDERING_PLATFORM` tables** in `WebsiteLinkHarvester` — they only classify at all because of the N1 catalog fallback. They are candidates for promotion to the hand tables (same treatment as the other 40-odd booking/ordering brands already there) independent of the larger P8 migration, but that promotion is not this sweep's job — recorded here as evidence only.

## 4. Fixture debt — surfaces with no `canonical_url_template` (hand-written URL in probe-urls.php; these rot silently)

63 of the 110 in-scope surfaces declare no `canonical_url_template` and needed a hand-written probe URL in `tests/fixtures/catalog/probe-urls.php`. Each was checked against its surface's detector (`registrable_key`/`path_pattern`/`subdomain_pattern`) in `bootstrap/catalog/compiled.php` before being written — a bare homepage would have produced a false invisible reading for several of these (e.g. `resdiary.reserve` requires the identifier on a subdomain; `uber_eats.order` requires a two-segment `/store/{slug}/{id}` path; `apple_music.artist`/`apple_podcasts.show` require the `music.`/`podcasts.` subdomain plus a path prefix).

| surface | probe URL | routing_class | resulting bucket |
|---|---|---|---|
| `acuity.book` | `https://acuityscheduling.com/schedule.php?owner=12345678` | booking | connectable |
| `bella_booking.book` | `https://bellabooking.com/acme` | booking | link-only |
| `booksy.book` | `https://booksy.com/en-us/1234567_acme-salon` | booking | connectable |
| `boulevard.book` | `https://boulevard.io/book/acme` | booking | connectable |
| `direct.book` | `https://acme-salon.example.com/book` | booking | invisible |
| `genbook.book` | `https://genbook.com/biz/acme` | booking | connectable |
| `glossgenius.book` | `https://book.glossgenius.com/acme` | booking | connectable |
| `kitomba.book` | `https://kitomba.com/book/acme` | booking | link-only |
| `mangomint.book` | `https://acme.mangomint.com/book` | booking | connectable |
| `mindbody.book` | `https://clients.mindbodyonline.com/classic/ws?studioid=1234567` | booking | connectable |
| `noterro.book` | `https://noterro.com/book/acme` | booking | connectable |
| `ovatu.book` | `https://ovatu.com/book/acme` | booking | connectable |
| `phorest.book` | `https://www.phorest.com/book/acme-salon` | booking | link-only |
| `schedulicity.book` | `https://www.schedulicity.com/book/acme` | booking | connectable |
| `setmore.book` | `https://acme.setmore.com/` | booking | connectable |
| `shortcuts.book` | `https://acme.shortcuts.com.au/` | booking | link-only |
| `simplybook_me.book` | `https://acme.simplybook.me/v2/` | booking | connectable |
| `square.book` | `https://acme.square.site/` | booking | connectable |
| `timely.book` | `https://acme.gettimely.com/book` | booking | connectable |
| `treatwell.book` | `https://www.treatwell.co.uk/place/acme-salon` | booking | connectable |
| `vagaro.book` | `https://www.vagaro.com/acme` | booking | connectable |
| `zenoti.book` | `https://acme.zenoti.com/webstoreNew/services` | booking | link-only |
| `bopple.order` | `https://bopple.me/acme` | ordering | connectable |
| `chownow.order` | `https://order.chownow.com/order/1234/locations/1234` | ordering | connectable |
| `deliveroo.order` | `https://deliveroo.com/menu/london/acme/acme-restaurant` | ordering | connectable |
| `doordash.order` | `https://www.doordash.com/store/acme-1234567/` | ordering | connectable |
| `easi.order` | `https://easi.com.au/order/acme` | ordering | link-only |
| `grubhub.order` | `https://www.grubhub.com/restaurant/acme-123-main-st-sydney/1234567` | ordering | connectable |
| `hungrypanda.order` | `https://www.hungrypanda.co/en/restaurant/acme-1234567` | ordering | link-only |
| `just_eat.order` | `https://www.just-eat.co.uk/restaurants-acme/menu` | ordering | connectable |
| `menulog.order` | `https://www.menulog.com.au/restaurants-acme/menu` | ordering | connectable |
| `order_online.order` | `https://order.online/store/acme-1234567/` | ordering | connectable |
| `ordermate.order` | `https://ordermate.online/acme` | ordering | connectable |
| `skipthedishes.order` | `https://www.skipthedishes.com/acme-restaurant` | ordering | connectable |
| `slice.order` | `https://slicelife.com/restaurants/acme-pizza/menu` | ordering | connectable |
| `square.order` | `https://acme.square.site/s/order` | ordering | connectable |
| `toast.order` | `https://order.toasttab.com/online/acme-restaurant` | ordering | connectable |
| `uber_eats.order` | `https://www.ubereats.com/store/acme-restaurant/1234567` | ordering | connectable |
| `wolt.order` | `https://wolt.com/en/aus/sydney/restaurant/acme` | ordering | connectable |
| `zomato.order` | `https://www.zomato.com/sydney/acme-restaurant/order` | ordering | connectable |
| `chope.reserve` | `https://chope.co/singapore-restaurants/restaurant/acme` | reservations | connectable |
| `eat_app.reserve` | `https://eatapp.co/book/acme-restaurant` | reservations | connectable |
| `nowbookit.reserve` | `https://www.nowbookit.com/Reservations/Create?RestaurantId=1234567` | reservations | connectable |
| `quandoo.reserve` | `https://www.quandoo.com/place/acme-restaurant-1234567` | reservations | connectable |
| `resdiary.reserve` | `https://acme.resdiary.com/` | reservations | connectable |
| `resy.reserve` | `https://resy.com/cities/syd/venue/acme` | reservations | connectable |
| `sevenrooms.reserve` | `https://www.sevenrooms.com/reservations/acme` | reservations | connectable |
| `tablecheck.reserve` | `https://www.tablecheck.com/en/acme-restaurant/reserve` | reservations | connectable |
| `tablein.reserve` | `https://tablein.com/book/acme-restaurant` | reservations | connectable |
| `thefork.reserve` | `https://www.thefork.com/restaurant/acme-r1234567` | reservations | connectable |
| `oztix.tickets` | `https://oztix.com.au/events/acme-event` | events | link-only |
| `resident_advisor.tickets` | `https://ra.co/events/1234567` | events | link-only |
| `ticketek.tickets` | `https://premier.ticketek.com.au/shows/show.aspx?sh=ACMESH26` | events | link-only |
| `ticketmaster.tickets` | `https://www.ticketmaster.com/acme-event/event/1234567` | events | connectable |
| `trybooking.tickets` | `https://www.trybooking.com/events/acme` | events | link-only |
| `apple_music.artist` | `https://music.apple.com/us/artist/acme/1234567` | content | link-only |
| `apple_podcasts.show` | `https://podcasts.apple.com/us/podcast/acme/id1234567` | content | link-only |
| `generic.store` | `https://shop.example.com/products/acme-item` | shop | invisible |
| `google_business.listing` | `https://www.google.com/maps/place/Acme+Salon/@-33.865,151.209,17z` | content | invisible |
| `partna.manual_product` | `https://acme.example.com/shop/handmade-necklace` | shop | invisible |
| `squarespace.store` | `https://acme.squarespace.com/shop` | shop | invisible |
| `vimeo.account` | `https://vimeo.com/acme` | content | connectable |
| `woocommerce.store` | `https://woocommerce.com/store/acme` | shop | invisible |

## 5. Findings (evidence only, no fixes proposed)

- **F1 — `skool.community` has a live catalog surface but zero detectors.** The surface's own note says it was demoted to link-only on 2026-08-16 "with the demotion" of `SkoolController`, but no detector was ever added to replace bespoke recognition. Every other invisible surface in §2 carries an explicit "this is null on purpose" note in its own catalog entry (provider-store null-out, place-id identifier, internal-only surface, zero-detector fallback-by-design); `skool.community` is the one row without that story — it is simply unreachable via `classify()` today. Evidence: `bootstrap/catalog/compiled.php` → `surfaces['skool.community']['detectors']` is `[]`; `classify('https://www.skool.com/acme')` returns `null`.
- **F2 — Seven booking/ordering surfaces answer only through the N1 catalog fallback, never the hand tables.** `bella_booking.book`, `easi.order`, `hungrypanda.order`, `kitomba.book`, `phorest.book`, `shortcuts.book`, `zenoti.book` are `booking`/`ordering` routing_class surfaces with real catalog detectors, sitting in the `link-only` bucket rather than `connectable` (§3) purely because `BOOKING_HOSTS`/`ORDERING_PLATFORM` in `WebsiteLinkHarvester` never had rows added for them, even though every sibling brand in the same routing_class did. Evidence: `classify()` for each returns `category: 'link'` via `classifyFromCatalog()`, not the hand table.
- **F3 — `stan.store`, `squarespace.store` and `woocommerce.store` are structurally invisible to `classify()` for any URL shape, not just the probed one.** `classifyFromCatalog()`'s shop-class branch nulls a provider surface unconditionally, and nulls a non-provider shop surface on any non-empty path — so no probe URL could have produced anything but `invisible` for these three regardless of how it was written. This is the intended trade documented in the method's own docblock (the commerce probe reads the product), not a coverage gap — recorded here because the sweep's evidence should say so explicitly rather than the report silently agreeing with the design.
- **F4 — `generic.store`, `direct.book` and `partna.manual_product` carry zero catalog detectors by construction.** They exist as named fallback/internal surfaces (`ShopConnections`' unknown-provider bucket, the no-brand booking page, the manual-add bucket) rather than as detectable third-party platforms — no probe URL, realistic or not, could make them classify. Structural, not a gap.
- **F5 — `google_business.listing` is `identifier_kind: place_id`, so URL classification was never its connection mechanism.** It connects exclusively through the Places-search flow (§16). Included in the invisible bucket only because the sweep is comprehensive over every non-retired surface, including ones this classifier was never meant to reach.

No detector was modified, added, or weakened to produce these results — every number above reflects the code as it stands on `9229efb7e`.

## 6. Correct-by-design (so the next reader does not re-raise them)

- `link` category is deliberate — recognised, never auto-connected, spends no probe.
- Storefront hosts (Gumroad, stan.store, Squarespace, WooCommerce) return null on purpose — the probe arm reads the product.
- `generic.store`, `direct.book`, `partna.manual_product` have no detector by construction (fallback/internal surfaces, not third-party platforms to recognise).
- `google_business.listing` connects via the bespoke Places-search flow, not link classification (`identifier_kind: place_id`).
- The gate matrix (§4 of the prompt's Phase 4) is Task 7's file, not this one — see `.superpowers/sdd/2026-08-18-pipeline-assurance-A-B/task-7-brief.md` for where the routing-class → gate mapping lands.
