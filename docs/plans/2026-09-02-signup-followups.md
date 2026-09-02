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

Test accounts for the final proof: jordan.dimitriadis (partna, Instagram —
the case that surfaced 1–5), plus one business (GB) rebuild.

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

---

## Final proof (after every item ships)

1. Rebuild jordan.dimitriadis via the public flow; read: handle
   `jordandimitriadis`, display "Jordan Dimitriadis"; exactly one youtube
   connection; zero link cards for YouTube URLs; every TikTok video with
   an image cover; forms.gle with a share image; actions[0] = Book; the
   feed's platforms row from the Linktree.
2. One business rebuild for regression (the-famished-wolf GB place).
3. Deploy backend → dashboard → pages; spot-check the live pages by curl.
