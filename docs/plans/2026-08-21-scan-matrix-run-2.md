# Scan matrix run 2 — 10 Instagrams × 10 Google Businesses × both account types

Owner handoff 2026-08-21 ~00:10 AEST (going to bed): "go find 10 more test
instagrams of real ones and google businesses and for each test for partna
account and business account and do same task and fixes so another giant
run … same loops until works". Same authority as the 2026-08-20 run
(docs/plans/2026-08-20-scan-refinement-and-real-account-testing.md):
build/commit/push/deploy to dev freely, wipe/modify TEST accounts freely
(real users out of scope), unconstrained Apify/Places spend, critic per
fix, every found issue ledgered AND fixed in-run, full suite before every
push, post-deploy log scan, loop-until-clean per item.

## Test rig

- Accounts: `gsnwilliams` (partna) and `user-kvjm7i` (business), reset via
  `partna:reset-test-user` between items. Tokens minted by password grant
  (creds in session scratchpad).
- Local: artisan serve :8000 + supervised queue workers against dev
  Supabase; workers restarted after every code change.
- Connect wires: POST /api/platforms/instagram/connect {username}; POST
  /api/platforms/google-business/connect {placeId,name,lat,lng,address}.

## Roster — Google Businesses (Places-verified 2026-08-21)

| # | Business | placeId | Sector notes |
|---|---|---|---|
| B1 | Industry Beans (Fitzroy) | ChIJ_7ffMiFD1moR5NCnlSFhECk | specialty coffee — food caps |
| B2 | Chin Chin (Melbourne) | ChIJ__3SxLdC1moRvQXAfpwM1hI | fine dining — reservations lane |
| B3 | Uncle Joe's Barber (Fremantle) | ChIJKXvHIjChMioRT9clotCMebQ | barber — booking lane |
| B4 | Rakis on Collins | ChIJm4-VE7ZC1moRomVs1qgUOG4 | hair salon — booking lane |
| B5 | Vic Market Tattoo | ChIJoT40qTZd1moRMa7iRTJlJ_E | tattoo studio |
| B6 | DOH - Daily Oven Heaven | ChIJJQ0AiQ1d1moRA4o9Jhre-K0 | bakery |
| B7 | Flowers Vasette (Fitzroy) | ChIJbcg-FiND1moRIGHS6JmEGJk | florist |
| B8 | Lune Croissanterie (Fitzroy) | ChIJ1zV4U_Zo1moR9MXOJ461Jd0 | bakery — big socials |
| B9 | Aurora Spa Retreat (St Kilda) | ChIJ__8TkW9o1moR85f02tf4sDM | day spa — booking |
| B10 | Grill'd Burgers - Southern Cross | ChIJGYYnVbRC1moRLlHxcRXg6jo | chain outlet — ordering lane |

Each B#: **pass A on business account** (full sync: workplace + socials +
reservations/ordering per food caps + menus + reviews), **pass B on partna
account** (workplace + socials + booking only — capability-gated scope, a
regression here is a capability bug). Verify: workplace fields + sources,
connection set matches the listing's real links, no junk identities, no
cross-item leftovers after the reset, swap/disconnect cleanup (RT-2) when
moving to the next item.

## Roster — Instagrams (existence validated by the connect itself;
substitute + ledger on a dead/renamed handle)

| # | Handle | Why |
|---|---|---|
| I1 | industrybeans | business IG, site + store links |
| I2 | chinchinrestaurant | restaurant IG — reservations/site links |
| I3 | lunecroissant | bakery IG — store/locations links |
| I4 | flowersvasette | florist IG — shop lane candidate |
| I5 | vicmarkettattoo | studio IG — booking/site |
| I6 | ruelofficial | AU artist — media/linktree lanes |
| I7 | gflip | AU artist — media lanes |
| I8 | tashsultana | AU artist — linktree, tour/merch |
| I9 | thejunglegiants | AU band — merch store + music |
| I10 | peachprc | AU artist — link-in-bio lanes |

Each I#: **pass A on partna account**, **pass B on business account**.
Verify against ground truth read from the real bio + its link-in-bio page
(fetch the linktree/beacons page directly, enumerate every link): every
link accounted for as connection / item / suggestion / card with correct
identity and zero junk; single-account social rule; items land in the
right pools; store links auto-connect + T1 five-pin where a root store
exists.

## Standing rules (same as run 1)

1. Reproduce → root cause → fix at the right layer → targeted tests →
   Sonnet critic told to REFUTE → full parallel suite → commit.
2. Ledger EVERY finding here (M-#), including deliberate non-captures.
3. Push/deploy in batches; `cloud env:logs partna development` scan after
   each deploy; real-browser (dashboard) verification for claims that are
   about rendering.
4. Loop until the item is clean, then move on. Two failed corrections on
   the same issue → stop, restate, restart the loop with what was learned.

## Ledger (append as found)

- **M-1** (I1 industrybeans live, both lanes): sprout.link (Sprout Social's
  link-in-bio) was not a known LIB host — the page carded as an inert
  "sprout.link" link and its buttons were never routed. On industrybeans
  the ONE button is the ROOT store URL, so the whole store was lost (round
  1: deep-page suggestion only). Fixed: LinkInBioDetector host +
  LinkInBioApiUnroller::sprout() reading /{slug}/page.json (seam read off
  sprout's own bundle; buttons[].destination_url + is_active verified on
  two live pages; social_links [] everywhere → skipped per never-guess
  rule). Round 2 live: sprout unrolled → root URL routed → Shopify store
  AUTO-connected, 8 products imported, T1 pinned exactly 5, sprout card
  gone. Pinned by 2 new LinkInBioApiUnrollerTest cases.

- **M-2** (B1-on-partna live): a GoogleBusinessEnrichJob retry (attempt 1
  killed mid-flight by a job timeout after a 125s Apify Places hang) saw
  its OWN half-finished Instagram placeholder via has() and filed a
  conflict finding — the placeholder stayed "pending" with no username
  forever, since nothing re-dispatches a lost InstagramConnectJob (#LIFE-5
  class). Fixed: a pending placeholder whose payload source is
  google-business is an unfinished obligation → re-dispatch the scrape
  (updateOrCreate + uniqueId connectionId:username make it idempotent); an
  enriched or user-connected Instagram still conflicts. Pinned by 2 new
  GoogleBusinessInstagramReservedSegmentTest cases. Live: stranded row
  healed by re-running the enrich through the new path.

### Item status

- I1 industrybeans (partna): CLEAN round 2 — IG + auto-connected store
  (8 products, 5 pins), 3 titled deep-page cards, 12 media, zero junk.
- B1 Industry Beans listing (business): CLEAN round 1 except the M-1
  sprout card (website-scan lane hit the same gap) — workplace full,
  listing socials all real (fb/tiktok/linkedin/ig), 5 reviews, 3 menu
  items, 10 media.

## Progress

- [ ] B1–B10 × {business, partna}
- [ ] I1–I10 × {partna, business}
- [ ] Final: full suite, deploys verified, close-out + report
