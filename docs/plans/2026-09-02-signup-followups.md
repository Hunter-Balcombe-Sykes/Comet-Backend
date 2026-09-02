# Signup follow-ups — live-test findings, fixes to execute in one run

> Opened 2026-09-02 from the owner's live signups after the 2026-09-01 plan
> closed. Each item is cause → fix → files → check. The owner keeps adding
> test cases; the plan grows until they say it is complete, then it runs.

## Execution contract (the fast path that worked on 2026-09-02)

Inline, no subagents. Build every item straight through; per item only the
targeted test file(s) + pint + phpstan on touched files; the full backend
suite runs in the BACKGROUND while the next item is built (it costs no
wall-clock that way); dashboard tsc/lint/vitest and pages typecheck/audit
once at the end of their batch. No browser previews unless an item says
so — the owner tests visuals. ONE live proof at the very end: tear down
and rebuild the test accounts through the public flow and read the
result off the DB/wire (the campaign harness in the scratchpad, or
`fleet:rebuild`), then deploy backend → dashboard → pages. Commit per
item with the reasoning in the message; push at the end of the run.

Test accounts for the final proof: jordan.dimitriadis (1–5), teegandyson
(6–8), jessejensz (9), plus one business (GB) rebuild for regression.

---

## 1. TikTok video card with no image

**Cause (confirmed on melbournehairspecialist).** The card's cover asset was
mirrored, but the stored file is a **video/mp4 (8.7 KB)** — TikTok's `cover`
field sometimes points at the short animated cover, and
`TiktokVideosNormalizer::coverUrl()` takes `cover` before `origin_cover`
(the static JPEG). MediaMirror sniffs the bytes, stores `.mp4`, and the
card renders `<img src=….mp4>` → blank. 20 of 21 covers were real images.

**Fix.**
- `app/Services/Platforms/ScrapeCreators/TiktokVideosNormalizer.php`
  `coverUrl()`: order `origin_cover`, then `cover`, then `dynamic_cover`.
- `app/Services/Media/MediaMirror.php`: an IMAGE-role fetch whose sniffed
  bytes are video must not be stored as the cover — treat as a miss (leave
  the asset unmirrored, log `media_mirror.cover_not_image`), so the card
  keeps the source URL until it expires rather than a broken file.
- Repair: re-project Jordan's affected item (one asset) — covered by the
  final rebuild.

**Check.** `TiktokVideosNormalizerTest` (fixture with both fields → origin
wins); a MediaMirror unit with an mp4 body on an image role → not stored.

## 2. Display name / handle "MELBOURNE HAIR SPECIALIST" for a partna person

**Cause (confirmed).** `NameShapeGate::apply()` correctly judged the Instagram
fullName a descriptor phrase and called `nameFromHandle('jordan.dimitriadis')`
— which returned null: it strips the separator and splits the letters
against dictionaries, and `resources/names/given.txt` lacks "jordan",
`family.txt` lacks "dimitriadis". So the business string stayed, caps and
all; the handle became `melbournehairspecialist`.

**Fix.** `app/Services/Profile/NameShapeGate.php`
- `nameFromHandle()`: try the SEPARATOR split first — a handle matching
  `^[a-z]{2,}([._])[a-z]{2,}$` (optionally a third token) whose parts are
  alphabetic and not descriptors is the person's own word boundary; accept
  it as first/last without the surname dictionary (dictionary scan stays as
  the fallback for `jordandimitriadis`-shaped handles). Title-case the
  result.
- `apply()`: an ALL-CAPS display name that is a person shape (two or three
  alphabetic tokens, none a descriptor) is title-cased; brand shapes keep
  their casing.
- DESCRIPTORS += `specialist`, `specialists`, `expert`, `pro`, `coach`,
  `trainer`, `educator` (audit the list once while there).
- Partna only where the rule concerns a person: the gate already receives
  the handle; business accounts (google_business source) never route here.

**Check.** `NameShapeGateTest`: `MELBOURNE HAIR SPECIALIST` +
`jordan.dimitriadis` → "Jordan Dimitriadis" (first "Jordan", last
"Dimitriadis"); `JORDAN DIMITRIADIS` → title-cased; a brand fullName with a
non-person handle unchanged. Final rebuild mints `jordandimitriadis`.

## 3. YouTube: connected twice AND carded as a link

**Cause (confirmed from the Linktree unroll tally: connected 5, noted 1).**
The Linktree carried several YouTube entries: one by handle
(`@jordan.dimitriadis`) and one by channel id (`UCeqs…`) each became a
connection — the dedupe key is `resource_id`, and handle ≠ id — and a
third, `…/@jordan.dimitriadis?sub_confirmation=1`, hit
`LinkInBioImporter`'s "known brand, shape no detector claims → card" branch
(`no-rule-matched` → `seedCustom`) and became the "Jordan Dimitriadis"
custom link.

**Fix.**
- Classify on a NORMALISED URL: strip query/fragment (and known tracking
  params) before the catalog detectors run — in `WebsiteLinkHarvester::
  classify()` (or the router's single normalisation point, whichever every
  lane shares). `?sub_confirmation=1` is still the channel.
- `app/Routing/Importers/LinkInBioImporter.php` note lane: before
  `seedCustom()`, if the URL's host resolves to a platform the user already
  has connected (or that this run just placed — `$placedKeys`), fold it
  (tally `folded`) instead of carding it.
- `app/Services/Platforms/Strategies/Connect/YoutubeConnect.php`: resolve
  an `@handle` to its channel id at connect (the scraper's
  `channelIdFrom()`), and key `resource_id` on the channel id for BOTH
  shapes so they dedupe to one connection; the display handle stays on the
  payload.
- Setup feed: the Linktree unroll is where most platforms connect, so it
  writes the `platforms` row too — `BuildProgress::noteForUser(...,
  STAGE_PLATFORMS, LANDED, "Connected N platforms from your Linktree",
  ['platforms' => slugs])` at the end of `LinkInBioImporter`'s run; the
  seeder's "nothing to connect yet" row stays honest for the bio itself.

**Check.** Importer test: a handle URL, an id URL and a `?sub_confirmation`
URL for one channel → ONE youtube connection, zero link cards, tally
`folded` 2. YoutubeConnect test: handle and id inputs produce the same
`resource_id`. Feed test: the unroll's platforms row lands.

## 4. Link cards without a share image (forms.gle and friends)

**Cause (confirmed).** `https://forms.gle/MjjTEdpaBNUAS56t5` redirects to
docs.google.com, which DOES serve `og:image` (the form header) to a browser
UA. `SafeUrlFetcher` follows redirects, so the difference is the request
identity: `Mozilla/5.0 (compatible; PartnaBot/1.0; +https://partna.au)`.
Estate-wide 277 of 541 link items have no cover; the imageless hosts are
bot-hostile or JS-only (SevenRooms 9, Beatport 8, OpenTable 7, Facebook 5,
Etix, Foodstorm, lnk.bio, Juno).

**Fix.**
- Verify first (5 min): fetch the docs.google.com form with the PartnaBot
  UA; if `og:image` is absent, add a browser-shaped UA for the SHARE-META
  fetch only (`config('partna.http_fetch.share_meta_user_agent')`, used by
  `LinkCardScraper::snapshot()`), leaving the bot UA on every other fetch.
- `app/Services/Content/LinkPoolWriter.php` + `EnrichPoolLinkJob`: when no
  share image lands and the host classifies to a catalog platform
  (OpenTable, SevenRooms, Facebook, Beatport…), stamp the item with the
  platform slug and let the card wear the PLATFORM BRAND TILE as its cover
  (the sitepage's PlatformCard face, brand colour + wordmark) — the same
  answer the platforms rail already gives for imageless brands.
- Astro `ScrollCard`/links rail: render the brand tile when the link item
  carries `platform` and no cover (tokens only; no new component — the
  `face` slot already exists).
- Re-enrich: a one-off `content:reenrich-links --missing-cover` command
  over the 277 (bounded, one probe per host).

**Check.** LinkCardScraper test with a recorded docs.google.com page;
writer test: a sevenrooms link with no og → `platform: sevenrooms`, cover
rendered as the tile on the sitepage (curl the page for the tile markup).

## 5. Actions order — forms.gle first for a barber

**Cause (confirmed).** The site's actions mode is `newest`
(`ActionSettings::DEFAULT_MODE = 'newest'`): the order is reverse-
chronological by connection time, and the forms.gle link was created last.
`smart` on a brand-new site has no popularity data and falls back to the
same newest-first tiebreak, so switching the default alone would not fix it.

**Fix.**
- `app/Site/Actions/ActionSettings.php`: `DEFAULT_MODE = 'smart'` (existing
  sites that never chose a mode follow, like the per-pool defaults did).
- `app/Site/Actions/ActionSlots::order()`: a COLD-START PRIOR when `$ranks
  === []` — tier by what the candidate is: `page:services` (Book) and any
  booking-category platform first; `page:contact` second; other pages;
  destination platforms (social/content); items last. Undated-last and
  connectedAt-desc stay as the tiebreak WITHIN a tier. Popularity ranks
  take over as soon as `actionRanksForSite()` returns anything.
- Sector skew: the tier already puts booking first whenever a Book page or
  booking platform exists, which is every service-sector account; a
  per-sector table is not needed for the first cut.

**Check.** `ActionSlotsTest`: with empty ranks, Book (services) → Contact →
pages → platforms → items; with ranks present, ranks win. Jordan's final
rebuild: `actions.entries[0]` is Book.

## 6. Discount codes from link-in-bio titles (teegandyson: "Gamma+ - CODE: TEEGAN10")

**Cause (confirmed).** The Linktree button title carried the code; the store
(gammaplus.com.au, WooCommerce) was connected, and `content.storefronts`
already has `discount_code` (StoreRecord::discountCode, the affiliate
field) — but the unroll never reads button TITLES, so nothing wrote it.

**Fix.**
- `LinkInBioApiUnroller` / `LinkInBioImporter`: carry each entry's title
  through as an observation attribute (the Linktree JSON has `title`).
- New `app/Services/Shop/DiscountCodeSniffer.php` (pure): from a title or
  description, extract a code — `CODE[:\s]+([A-Z0-9]{4,20})`, `use code X`,
  `promo X`, `discount code X`, `X% off with X`; uppercase alphanumerics
  only; never from the URL itself.
- Importer: when a placed link is a STORE (shopify/woocommerce/squarespace/
  bigcartel/generic) and its title sniffs a code, write `discount_code` on
  that storefront if empty (ShopConnections/StoreRecord update path). Log
  `link_in_bio.discount_code_adopted`.
- Setup feed: the shop row (item 8) shows "Code TEEGAN10 saved" when adopted.

**Check.** Sniffer unit table (the five shapes + negatives); importer test:
a store entry titled "Gamma+ - CODE: TEEGAN10" → storefront
`discount_code = TEEGAN10`; Teegan's rebuild shows it on the shop page.

## 7. Feed rows that never settle ("Checking 2 places…" spinner, "Saving your media 20 of 22")

**Cause (confirmed on teegandyson).**
- The workplace `started` row keeps its spinner after the `landed` row for
  the same stage arrives — `ProgressFeed` renders every event independently.
- 2 of 22 eligible Instagram assets failed their one mirror attempt
  (`mirror_attempts = 1`, `storage_path` null) and the done rule waits for
  `mirrored >= total`, so the row spins until the 10-minute ceiling.

**Fix.**
- Dashboard `ProgressFeed`: derive per-stage state — a `started` row whose
  stage has a later landed/skipped/failed row renders as done (tick), or
  collapses into it (item 8 replaces the list anyway; keep this in the
  shared feed model).
- `BuildProgressReader::mediaCounts()`/`isDone()`: an eligible asset counts
  as SETTLED when mirrored OR (attempted at least once and older than 2
  minutes) — the mirror job's own retry budget is spent by then; report
  `media.failed` so the row can read "Saved 20 of your 22 photos" instead
  of spinning. Never let a dead CDN URL hold the setup open.

**Check.** Reader test (one failed, aged asset → done); feed model test.

## 8. Live signup card — one thing at a time, showing the actual content

**Owner's outline (2026-09-02).** Replace the top strip + stacked list with a
single card that shows the CURRENT stage only (the previous line fades out,
the next fades in), and shows the real things being grabbed rather than
"pulled the listing": the media as bigger thumbnails as they land; the bio
mentions being checked as Instagram icon + @handle; the platforms being
connected as icon + name; the Google listing's actual name/rating/photos;
the store's products as they sync; the website photos. No "saving your
media" text — show the images instead. Once the site is live: under the
card, the URL in a copy field, a full-width Preview button (`#setup`), and a
clear line that content keeps being added while the sync finishes. The
same card mounts on the dashboard home.

**Fix — backend (payload enrichment, same ledger).**
- Stage set += `shop` (migration extends the CHECK); PreAccountBuildEvent
  STAGE_SHOP.
- Richer payloads, written by the producers that already write the rows:
  identity `{handle, avatar}`; media `{thumbnails: up to 8, videoPosters}`;
  workplace started `{mentions: [{handle, platform: 'instagram'}]}`, landed
  `{name, photo, address}`; platforms (bio + Linktree, item 3) `{platforms:
  [{slug, label, handle}]}`; listing `{name, rating, reviewCount, photos:
  3}`; menu `{dishes, photos: 3}`; website `{photos: 4}`; shop `{store,
  logo, products: [{name, image}] up to 4, discountCode}` from the store
  fill (ShopInitialFillJob / ShopContentWriter); ready `{siteUrl}`.
- The poll keeps `events` as the log; the card reads the LATEST event per
  stage for its "now" view.

**Fix — dashboard.** `components/blocks/setup-card.tsx` (new; replaces
BuildStrip + ProgressFeed in the flow and the SiteBuildingCard body): stage
text with a cross-fade (the app's motion tokens), a media grid (3-up,
thumbnails at the card's width), platform/mention chips with the kit icon
(PlatformTile) and label, listing/store/menu facts as short rows; after
ready: address copy field (the dashboard's existing copy-field primitive),
`Preview site` full-width button to `${siteUrl}#setup`, and the line
"Content keeps landing while the sync finishes — your site updates
itself." Fixture page under /dev/pages for the owner's visual pass.

**Check.** tsc/lint/vitest; the owner's own signup for the visuals (no pane
previews). Backend: PublicBuildEndpointsTest payload shapes.

## 9. Square Appointments — routing fix + Fresha-parity connector (spec: `docs/2026-09-02-square-appointments-parity-plan.md`)

**Cause (proven in the spec, §0.1 + Appendix A).** `square.book`'s detector is
host-only, so a Square Appointments deep link scored 32 against booking's
suggest bar of 55 and became a custom link on jessejensz; Google Business
then filled the Square slot with the bare `square.site` root.

**Method here (replaces the spec's per-task TDD ceremony; everything else in
the spec stands — its Global Constraints, file lists, interfaces, Out of
scope, and the blocked-file rule).** Read the spec's task in full before
building it. Build Tasks 1→4 straight through on a feature branch off
`development`, tests written alongside and run per task (targeted), the
full suite in the background; after any `app/Catalog/Definitions` change
run `php artisan catalog:compile && php artisan routing:corpus` and commit
the regenerated `bootstrap/catalog/compiled.php` +
`tests/fixtures/Routing/corpus-generated.php` in the same commit. Merge to
`development` when 1–4 are green (that deploys). **STOP before Task 5 and
ask the owner the spec's four open questions** (square.site root seeding,
per-service vs per-variation items, no-match fallback, refresh button);
Tasks 1–4 do not depend on them. Task 6 is dashboard (`main`); backend
deploys before it. Task 7 repairs jessejensz after the deploy. Gate on
AccountCapabilities, never account_type; no Square OAuth/Bookings API; no
reviews stream; Fresha unchanged except the Task 6 team-step generalisation.

- Task 1 — `Square.php` deep-link detector (`book.squareup.com/appointments/…`,
  `app.squareup.com/appointments/book/…`) at DeepLinkWithSlug; detector +
  importer tests; regenerate catalog + corpus.
- Task 2 — `SourceProvisioner`: `sourceKeyFor('square.book') → square_book`,
  identifier from the URL (merchant/unit/team_member_id).
- Task 3 — `SquareBookingPage` pure reader of the public buyer-widget JSON
  (parseUrl, widgetUrl, team, staffIdFor, services) + trimmed fixture.
- Task 4 — `SquareBookingConnector` + `SquareServiceProjector`, registered in
  both registries; per-service deep links.
- Task 5 (after the owner answers) — `SquareBookingClient`, team endpoints on
  the square group, `SquareAutoSelectJob`, SquareBinding, SourceReconciler /
  LinkRouter auto-connect parity.
- Task 6 — dashboard: one booking team step for Fresha and Square
  (`BookingPlatform` type, platform-parameterised queries).
- Task 7 — repair jessejensz (delete the wrong Square row + stray menu
  source, re-import the Linktree) and verify on the live page.

---

## Final proof (after every item ships)

1. Rebuild jordan.dimitriadis via the public flow; read: handle
   `jordandimitriadis`, display "Jordan Dimitriadis"; exactly one youtube
   connection; zero link cards for YouTube URLs; every TikTok video with
   an image cover; forms.gle with a share image; actions[0] = Book; the
   feed's platforms row from the Linktree.
2. One business rebuild for regression (the-famished-wolf GB place).
3. Deploy backend → dashboard → pages; spot-check the live pages by curl.
