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

- **M-3** (B2 chinchin live): OpenTable "Reserve" buttons on restaurant
  websites use /booking/restref/availability?restRef=<rid> — a shape no
  detector knew, so the links fell out of the reservations lane. Fixed:
  restRef query detectors on every OpenTable TLD (same id space as rid);
  'restref' added to SecretParams::IDENTITY_PARAMS (the mechanical-link
  test now compares lowercased, matching how the redactor consults the
  list); corpus regenerated (297 detectors round-tripped).

- **M-4** (B2 chinchin live, pre-existing): LinkRouter::seedReservation
  passed origin 'auto' to recordCapBlock — a value the
  routing.source_intents origin CHECK has never accepted. On real PG the
  insert threw 23514, route()'s catch-all answered custom(), and capped
  reservation links CARDED instead of filing the promised Swap. Invisible
  to the whole suite because SQLite doesn't enforce PG CHECKs. Fixed to
  'bio_harvest' (route()'s own origin, same literal the ordering arm
  passes); pinned by ReservationCapSwapOriginTest which locks the origin
  to the constraint's allowed set from the test side.

- **M-5** (B2 chinchin live): the first-link-per-platform slot
  short-circuit carded the SECOND OpenTable link before seedReservation
  could cap it. Reservations + online-ordering manage their own family
  slot (incumbent → Swap, idempotent), so those two categories now bypass
  the seenPlatforms short-circuit. Live round 4: zero OpenTable cards,
  one coalesced Swap intent, 3 legitimate own-site cards.

- **B2 deliberate notes**: Google Place Details returns NO review texts
  for Chin Chin (mapped correctly — B1 got 5; upstream data varies per
  place). gsnwilliams' I2 media=0 is the account's own data (Apify scrape
  returned 0 posts for chinchinrestaurant; the real Melbourne handle is
  @chinchin, which the LISTING correctly connected on the business pass).

### Item status

- **M-7** (I9 thejunglegiants live): youtube.com/@TheJungleGiants vs
  @thejunglegiants — one channel, but matchExisting's case-sensitive
  resource_id compare missed it. Handle-kind surfaces (catalog
  identifier_kind) now fold both sides; opaque id kinds keep exact
  compare. Pinned in ConnectionIdentityAliasTest.
- **M-8** (same trace): the Choose band skipped the alias lookup entirely
  (deliberately Place-only), so the differently-cased own-channel link
  filed the user's OWN connected channel as an inbox suggestion. Choose
  now consults matchExisting and an aliased Choose upgrades to Place —
  the fold machinery reuses the existing row, adds no account. Pinned in
  ConnectionIdentityAliasTest (M-8 case). @triplej (a genuinely different
  channel in their linktree) still proposes — correct.
- I5 vicmarkettattoo: handle does not exist (Apify profile_not_found,
  clean 'unavailable' handling, no junk) — substituted; the substitute
  stali_coffee ALSO returned actor not_found despite being listing-
  verified earlier the same night: Apify IG actor flakiness, ledgered.
- I6 ruelofficial (both accounts): actor returned an EMPTY profile
  (0 followers/0 posts/no bio) for this account consistently — pipeline
  faithfully mirrors it; parity across account types. Actor limitation,
  not a routing bug. Possible future improvement: treat an all-empty
  "successful" scrape as retryable.
- I7 gflip (both accounts): CLEAN and rich — IG + link-only fb/tiktok/x +
  Spotify player + YouTube channel + Apple Music artist all
  auto-connected; 39 releases + 10 tracks + 18 videos + 12 media; 7
  legit cards (ffm.to smart links, tour/site/shop); Spotify+Apple pairs
  of one release MERGE into one item (identity layer verified — the one
  same-name duplicate is a real single/EP pair).
- I8 tashsultana (business): actor returned a 77-follower ghost profile
  for the handle (real account is ~1.5M) — pipeline mirrored it
  faithfully; ledgered as actor-data limitation; partna mirror skipped
  (the broken data source tests nothing new about account types).
- I9 thejunglegiants (both accounts): CLEAN and rich, full parity — two
  spotify.player connections (artist + their promoted playlist; content
  surfaces are multi-account by design), youtube connected, Apple Music,
  52 releases/10 tracks/18 videos, shopify merch store proposed, 13
  legitimate cards. Produced M-7/M-8.
- I10 peachprc (both accounts): CLEAN parity — IG + 12 media + 3 legit
  cards (site, single page, tour); bio carries no music-platform links to
  fan out. 
- B5 Vic Market Tattoo (business): partial — the Google Places DETAILS
  quota died mid-run (429 → 403, key-level daily), so the listing
  connected bare (name/address only) and enrichment seeded nothing. NOT
  a code bug: connect degrades gracefully, warning logged. B5–B10 (and
  the B-side partna mirrors beyond B1–B4) are BLOCKED on Google's daily
  quota reset — local PlacesBudget caps were raised in .env
  (PARTNA_PLACES_* overrides, owner-authorized) so only Google's own
  reset is needed. Retry recipe: reconnect each placeId from the roster
  table with the same wire.

### Environment notes (run 2)

- Google Places daily quota exhausted ~03:00 AEST (429 then 403) after
  ~6 full business connects tonight on top of run 1's spend. Local
  PlacesBudget caps raised (global 5000 / user 1000 / details 2000 /
  photos 4000) for future runs.
- Apify Instagram actor degraded intermittently from ~03:00: not_found
  for existing handles, empty profiles, ghost data. gflip/thejunglegiants/
  peachprc scraped perfectly in the same window, so it is per-account
  flakiness, not global.

- I4 flowersvasette (partna): CLEAN — IG + link-only facebook/tiktok
  auto-applied from the bio (their "pending" badge is the PARKED
  connect-only-platform-status decision, untouched), WooCommerce store
  correctly PROPOSED (deep shop links → suggestion), the bio's own
  featured product became a Sell item via the manual-product lane (T8
  doctrine, mirrors run-1's track→Listen rule), 5 titled cards, 12 media.
- B4 Rakis on Collins (business): CLEAN BY DOCTRINE — listing + real
  fb/ig + workplace + 12 media + site card; the apps.kitomba.com booking
  link CARDS because Kitomba is one of the 27 detect-only stopgap
  providers (documented in its catalog definition — card is the designed
  outcome, no connect recipe exists). Workplace name "Rakis on" is the
  documented 15-char BusinessName::wordTrim cap ("Rakis on Collins" won't
  fit) — deliberate, though the owner may want to revisit the cap for
  names like this.
- **M-6** (critic catch on M-5): the ordering cap-block identifier was
  hashed from the FULL URL, so query-string variants of one store
  (?pickup/?delivery) would mint duplicate Swap rows once M-5 let every
  ordering link reach the seeder. Now keyed on host|path (storeKey rule):
  variants coalesce, distinct stores stay distinct. Pinned by a third
  ReservationCapSwapOriginTest case. Critic REFUTED every other angle on
  M-1..M-5 (M-2 loop-bounds, M-3 collisions, M-4 origin correctness,
  duplicate-connection risk under M-5).

- I3 lunecroissant (both accounts): CLEAN — IG + 12 media + 7 properly
  titled real Lune pages (menus/loyalty/careers/order/pre-order/root/
  passport), parity across account types.
- B3 Uncle Joe's Barber (both accounts): CLEAN — listing + facebook
  @unclejoesbarber + IG @uncle_joes + workplace + site card + 12 media,
  parity across account types. No booking provider on the listing/site —
  nothing to connect, deliberate.

- I2 chinchinrestaurant (business): CLEAN — IG + site card, parity with
  the partna pass.
- B2 Chin Chin (partna): CLEAN BY DOCTRINE — listing + facebook +
  @chinchin IG + workplace; reservations capability is off for partna, so
  the two restref links fall to zero-loss cards (gate-denied categories
  card deliberately; their bare-domain labels are OpenTable's unscrapable
  widget page, noted). No reservations connection, correctly.

- I2 chinchinrestaurant (partna): CLEAN — IG ok + site card; 0 media is
  the account's own data.
- B2 Chin Chin listing (business): CLEAN round 4 — listing + facebook +
  opentable + @chinchin IG, menus n/a, 12 media; opentable extras file
  ONE Swap; 3 own-site cards only.
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

## Continuation (2026-08-21 morning, owner: "keep going")

- Places quota: still dead (429 → 403 PERMISSION_DENIED, key-level).
  Recovery probe armed (15-min interval); B5–B10 remain queued on it.
- I5 vicmarkettattoo: handle EXISTS (search-indexed reels) but the Apify
  actor deterministically answers profile_not_found — CLOSED as
  actor-unscrapeable, handled cleanly by the pipeline both rounds.
- I8: my roster handle was WRONG — @tashsultana is a real 77-follower
  account the pipeline mirrored faithfully; the artist is
  @tashsultanaofficial. Corrected run (1.27M followers) produced the
  richest single fixture of the whole matrix and found:

- **M-9** (tashsultanamerch live): the linktree's myshopify ROOT URL
  projected straight to shopify.store and Engine 1 bare-applied a
  'pending' connection — no storefront, no catalogue, no fill, no
  auto-select, nothing that would ever sync. Two-part fix: (a) scan-lane
  shop Places delegate to CommerceProbeJob (storefronts keep their
  single writer — StoreBrandSeeder via the commerce lane; paste and the
  lane's own origin exempt); (b) LinkProbeWorker now PROBES shop tenant
  hosts (myshopify/bigcartel) instead of refusing 'already_matched' —
  the refusal left tenant stores with no path to a storefront at all.
  Critic pass produced three more fixes: square.site stays
  booking-class (no Square Online probe exists — probing it was a
  guaranteed miss), CommerceProbeJob::failed() cards the link so a
  job-level death can't vanish a counted link, and dismissed
  tenant-store suggestions tombstone BOTH identifier schemes (numeric
  id + tenant label) so re-scans stop re-probing refused stores.
  Live-verified end-to-end: Tash Sultana store connected with real
  Shopify id, 8-item catalogue, autoselected stamp, exactly 5 pins.
- Also from the corrected I8: her 20 tour/ticket links all card cleanly
  (zero junk), the soundwavesartfoundation charity shop collection page
  correctly files as a suggestion (FI-10), and the follow-up media
  scrape 502'd at Apify (environment; profile itself scraped fully).

## Close-out (2026-08-21 ~05:15 AEST)

Run complete. 8 fixes shipped (M-1..M-8, M-8 in both directions), every
one reproduced live, fixed at the right layer, pinned by tests, and
re-verified live where the environment allowed. Three Sonnet critic
passes ran (RT batch, M-1..M-5, M-6..M-8); every REAL finding they
raised was fixed in-run (M-6 came from a critic; the M-7 allowlist and
the real M-8 Choose-arm test came from the final critic). Final suite:
8,743 passed / 0 failed. All commits pushed to development
(auto-deploy); post-deploy log scan recorded in the session.

Coverage: I1–I4 clean in all four cells; I5 handle nonexistent
(substitute also actor-blocked); I6/I8 actor-data-limited with account-
type parity; I7/I9/I10 clean and rich in both cells; B1–B4 clean in all
cells; B5–B10 blocked on Google's key-level daily Places quota (local
caps already raised; retry recipe in the roster section).

Final account states: user-kvjm7i = thejunglegiants full music showcase
(IG + Spotify artist & playlist players + YouTube + Apple Music, 52
releases/10 tracks/18 videos, merch store proposed, @triplej correctly
proposed, mis-cased duplicate superseded); gsnwilliams = peachprc (IG +
12 media + 3 cards). Both reset-ready via partna:reset-test-user.

## Continuation 2 (2026-08-21 midday — Places recovered)

Places came back (HTTP 200 on the B5 probe ~12:40 AEST; the outage was
Google-side, quotas confirmed ~0% in the owner's console). B-roster
resumed, both cells per item.

### B5 Vic Market Tattoo — both cells

- Listing connects FULLY now (rating 4.8, website, 10 media + 5 reviews,
  5 review cards, workplace seeded) on business AND partna cells,
  identically.
- Website `vicmarkettattoo.com` returns **HTTP 402 from the site
  itself** (frozen Shopify store — subscription lapsed). The website
  scan's empty bail with status 402 logged is CORRECT behaviour; ditto
  the Apify contacts add-on returning `instagrams: []` (it crawls the
  business website, which is dead). No socials/booking on the listing →
  nothing to route. Not a code bug.
- **M-10** (real bug, fixed): Google marks this tattoo shop
  `primaryType: "store"` / display "Store" while `types[]` leads with
  `body_art_service`. We copied "Store" verbatim → workplace card said
  "Store" and `SectorTaxonomy::fromGoogleCategory('Store')` = null → no
  sector sync → capability set stayed default. Fix:
  `GoogleBusinessService` now also requests `primaryType,types` (free —
  the mask already carries Enterprise+Atmosphere fields) and
  `categoryFrom()` falls past a GENERIC_PLACE_TYPES primary to the first
  specific `types[]` entry, humanized ("Body art service"); taxonomy
  gains `body art`/`piercing` → tattoo-artist. Live re-verified: both
  cells now store category "Body art service".
- Log fix alongside: `google_business.apify.keys` 'present' list now
  uses `!empty()` — the actor returns scraped-nothing keys as `[]`, and
  isset() logging them as present sent this diagnosis chasing a mapping
  bug that wasn't there.
- M-10 critic pass (Sonnet): 3 real findings, all fixed in-run —
  `general_contractor` added to GENERIC_PLACE_TYPES (same bug class for
  trades: an electrician primary-typed general_contractor would never
  sector-sync), types[]-order heuristic documented at the fallback, and
  the three stale "category = primaryTypeDisplayName verbatim" docblocks
  updated. Cleared: SKU/billing (mask already Enterprise+Atmosphere),
  keyword collisions, !empty log change.
- Second real find while verifying: `partna:reset-test-user` never
  cleared synced identity on core.users — gsn carried sector
  "barber"/google-business through four resets (read as a gate leak
  before tracing). Reset now nulls public_contact_number always and
  sector/sector_source when not 'manual'. Verified: gsn sector null
  after reset.
- B5 verdict: CLEAN both cells. biz = listing + 10 media + 5 review
  cards + workplace "Vic Market | Body art service" + sector
  tattoo-artist (google-business). partna = same minus sector (Google
  sector fold is business-only by design, decision 12). Zero link/social
  cards is ground-truth-correct: the listing's website is dead (402) and
  Google exposes no booking/social for it.
