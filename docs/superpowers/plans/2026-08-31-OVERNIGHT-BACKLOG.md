# Overnight backlog — golden-standard pass

Living list. Every issue found tonight, big or small, related or unrelated, lands here with evidence.
Nothing is marked done without a citation. Started 2026-08-31 ~23:00 local.

**Standing context:** the software is not live. No real users, every account is a test account. Downtime,
destructive rebuilds and deleted test data are all acceptable. Where there is a choice, take the best
design, not the smallest diff.

---

## Status key

`TODO` · `IN PROGRESS` · `DONE` (with commit + evidence) · `DECISION` (owner's call) · `DROPPED` (with reason)

---

## A. Identity: the Instagram profile picture

The owner's screenshot: browser tabs for `sepia.partna.au` and `emdinonhair.partna.au` both show a
generated initial-letter favicon ("S", "E") rather than a real picture. Investigated directly.

### A1 — The headshot is never used as the favicon · `TODO` · high

Every site in the fleet renders the generated initial-letter SVG, including accounts that HAVE a ready
headshot. `head-builder.ts` only falls back to the generated mark when `brand.logoSquare.urlIcon` is
absent — and it never considers `profile.headshot` at all.

```
curl https://emdinonhair.partna.au/  →  <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,…">
curl https://designdivine.partna.au/ →  same, despite headshot being present and ready on its wire
```

**Fix:** favicon precedence for a partna account should be headshot → generated initial; for a business
account, logoSquare → generated initial. Needs a square/icon variant of the headshot (see A4).

### A2 — The social share image is a random Instagram post, not the person · `TODO` · high

`og:image` and `twitter:image` point at whatever the first media item is.

```
emdinonhair  og:image = …/platforms/instagram/1787827388/photo.jpg
sepia        og:image = …/platforms/instagram/1788173763/photo.jpg
```

**Fix:** for a partna account the share image should be the headshot (cropped to ~1200×630 or served
square with a generated backdrop); a business should prefer its logo, then a listing photo. A random
post photo is the last resort, not the default.

### A3 — Eight partna accounts have no headshot row at all · `TODO` · medium

76 of 84 partna accounts carry a `site_media` row with `purpose='headshot'`, all `ready` and `active`.
Zero of 125 business accounts do, which is correct by design. The eight without:

```
barber-in-law · channel303 · emdinonhair · eoinmccarthyhair
sammypdf · simondoylehair2 · tobiasbalcombe · traethebarber
```

Every one of them predates the 2026-08-27 T17 headshot work, so this looks like a coverage gap rather
than an ongoing defect — but it needs proving on a fresh build, and the eight need backfilling.

### A4 — Confirm the variants each surface needs actually exist · `TODO` · medium

A favicon wants a small square (a 192px PNG `urlIcon` was observed on jjsavani); og:image wants ~1200×630.
Establish what the media pipeline emits for a headshot today and add what is missing.

### A5 — The headshot's placement in the rendered identity · `TODO` · medium

The owner asks that it "shows in identity on frontend". Establish where the scroll architecture should
carry it — header, lander, about — and whether it does today.

---

## B. Reviews for partna accounts

### B1 — Reviews appear only on business accounts · `TODO` · high

The 27-account audit found a `reviews` pool on all 17 business builds and none of the 10 partna builds.
A monorepo commit (`fcab35c`, "Reviews page for partna sites: drop the isBusiness guard on page
synthesis") suggests the frontend guard was lifted, so the gap may be upstream in ingest.

**Open:** what sources can produce reviews for an individual? Their own Google listing (partna accounts
are built from Instagram, so usually none), a booking platform such as Fresha, or the Google listing of
the workplace they are linked to.

### B2 — RULED (owner, 2026-08-31): only reviews that name the person · `TODO` · high

> "no only ones mentioning their name should"

A workplace's reviews are the workplace's. A review that says *"Emma was fantastic"* on the salon's
listing genuinely is a review of Emma, and is the only kind that belongs on her page. Everything else
stays with the salon.

So the partna reviews pool is the workplace's review set filtered by **name containment against the
person**, not the whole set and not nothing.

Consequences to design around:

- **Hard dependency on F3.** Name matching is only as good as the stored name, and 37 of 84 partna
  accounts currently hold a descriptor, an emoji or a raw handle where the name should be. Filtering
  reviews by `first_name = "Melbourne"` would surface everything and by `last_name = "✨"` nothing.
  **B2 must land after F3, and its acceptance must be measured on re-gated names.**
- **Reuse the matcher we already trust.** `FreshaStaffMatcher`'s vanity-name tier already solves
  "does this person's name appear in this free text", with ambiguity and short-token guards learned
  from real failures. A second, weaker implementation of the same idea is how these things drift.
- **A short or common given name is the hazard.** "Em", "Jo", "Lily" will false-positive on ordinary
  prose. The guard has to be at least as strict as the Fresha one, and it is better to show no review
  than to attribute a stranger's words to someone who did not earn them.
- **Attribution must stay visible.** A review shown on Emma's page came from Star Barber's listing;
  the surface should say so rather than implying she was reviewed directly.

### B3 — Where the reviews come from for an individual · `TODO` · medium

B2 defines the filter; this is the source. Enumerate what is actually reachable for a partna account:
the linked workplace's Google listing (the main one — `LinkFreshaVenueToGoogleJob` already establishes
that link), a booking platform that carries reviews, or their own listing where one exists. Plumb the
ones that are real.

---

## C. Media pool ordering

### C1 — Most recent reel should lead the auto media pool · `TODO` · medium

Owner request. Needs: a way to tell a reel from a photo in what we store, the current ordering rule for
the media pool, and the right insertion point so the rule composes with `newest|smart|manual` rather
than fighting them.

### C2 — RULED (owner, 2026-08-31): the lander follows the media order, and is never set separately · `TODO` · medium

> "make it so the lander uses the same order that is given from the media […] so the consumer, whatever
> isn't set separately, it gets the down-the-road fix related to the actual media fix"

The rule is architectural, and wider than the reel question: **the media pool's order is the single
source of truth, and every consumer of it derives.** The lander takes whatever the pool puts first. So
when C1 promotes the newest reel, the lander follows automatically — with no second setting to keep in
step, and no chance of the two disagreeing.

That makes the reel decision moot by construction: a video lander is simply what happens when the newest
item is a reel, which is the honest answer.

Work this implies:

- **Verify the derivation is real, not incidental.** `apps/pages` reads `content.media.deck[0]` for the
  backdrop and `.slice(1)` for the gallery, which already looks derived. Confirm nothing upstream pins a
  separate lander image — a `design`-pool hero, a stored backdrop column, or a hand-set sort order — and
  if anything does, remove it rather than syncing it.
- **Audit every other media consumer for the same pattern.** Anywhere a surface picks a specific image
  instead of taking position 0, it will drift the same way. Find them all now while the rule is fresh.
- **The lander must handle a video first item properly** — poster frame, autoplay/loop behaviour, and a
  graceful still where autoplay is refused. The gallery already does this; the lander needs to be at
  least as good, since it is the first thing a visitor sees.

---

## D. From the 27-build audit (2026-08-31)

Full report: the Cold Build Audit artifact. Plan: `2026-08-31-cold-build-defect-remediation.md`.

| id | title | status |
|---|---|---|
| F1 | Platform tiles beacon an item type the API rejects (422 on every Platforms view) | `DONE` — mono `271ecb0`; live check pending deploy |
| F2 | A build still `pending` publishes a page with the person's name | `TODO` — Phase 4 |
| F3 | Name derivation wrong on 37 of 84 Instagram accounts | `TODO` — Phase 3 |
| F4 | The business phone reaches the JSON-LD and nothing else | `DONE` — mono `7b860a7`; live check pending |
| F5 | 68 of 209 accounts have no sector | `TODO` — Phase 7 |
| F6 | Two YouTube connections fail every refresh forever | `PARTIAL` — `303fefd59`; review found the gate still reachable, Wave 1.5 |
| F7 | A `profile.php` Facebook link provisions a dead source | `PARTIAL` — `472ffc36d`; discards a usable id, Wave 1.5 |
| F8 | An Uber Eats brand URL provisions a menu source that never runs | `PARTIAL` — `a6329551b`; review says the lane is inert anyway, Wave 1.5 |
| F9 | Malformed JSON-LD address, national-format phone | `DONE` — mono `aa78019`; live check pending |
| F10 | Boilerplate meta description while a real bio goes unused | `DONE` — mono `655ea33`; live check pending |
| F11 | TikTok thumbnails 403 and render blank | `TODO` — Phase 6 |
| F12 | Two tests fail only under `--parallel` | `DONE` — already fixed by another lane in `55344a402`, proven by restoring the pre-fix file |
| F13 | Purge failures — and they got worse, 36 in 31 minutes | `TODO` — Phase 5, measure first |
| F14 | Logo variants land inconsistently | `TODO` — Phase 6 |
| F15 | No services connector for Booksy / Cliniko / NowBookIt / Timely | `TODO` — own plan |
| F16 | `fleet:new` half-runs a batch | `TODO` — Phase 8 |
| F17 | A business that exists only on Instagram cannot be built | `DECISION` |
| F18 | A sector front can push `home` out of first place | `TODO` — Phase 7 |
| F19 | No business build gets a contact email, so every form is dark | `TODO` — Phase 8 |
| F20 | A thin build is indistinguishable from a good one | `TODO` — Phase 8 |
| F21 | Two placeholder accounts (`business`, `business1`) | `TODO` — Phase 8 |
| F22 | A null sector silently revokes menu/reservations/ordering; the OCR scan bails unlogged | `TODO` — Phase 7 |

### Wave 1 review findings (12 majors) — all `TODO`, Wave 1.5 in flight

- Four tests do not pin their fix; proven by mutation (deleting the fix line leaves them green).
- Platform tiles lost tap-to-open on touch devices — a regression this work introduced.
- The YouTube fix still creates the unusable connection; adds `@José` → `Jos` truncation and
  `/about` → username `about`.
- The Facebook fix discards a numeric page id already present in the payload.
- The Uber Eats guard is on a lane that is never scheduled.

---

## E. Areas under investigation

`golden-standard-discovery` is sweeping these read-only; findings merge in when it reports.

- Frontend/backend contract drift — every field either side sends, reads, or silently drops.
- `ollies.partna.au` — the only claimed account, and the closest thing to a real customer site.
- Every open Nightwatch issue, classified fixed-stale / live / infra / noise.
- The dashboard against the backend.
- A wide hunt for anything nobody has named.
