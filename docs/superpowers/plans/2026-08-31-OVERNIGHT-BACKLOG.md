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

### B2 — DECISION: should a workplace's reviews appear on an employee's personal page? · `DECISION`

A barber linked to a Google-listed salon: the salon's reviews are not the barber's. Showing them is
arguably misattribution; hiding them leaves an empty page. This is a product judgement, not a bug.

---

## C. Media pool ordering

### C1 — Most recent reel should lead the auto media pool · `TODO` · medium

Owner request. Needs: a way to tell a reel from a photo in what we store, the current ordering rule for
the media pool, and the right insertion point so the rule composes with `newest|smart|manual` rather
than fighting them.

### C2 — Interaction: the lander uses `media.deck[0]` as its backdrop · `TODO` · medium

`apps/pages` renders `content.media.deck[0]` as the lander backdrop and `.slice(1)` as the gallery. If
the newest reel leads the pool it becomes the BACKDROP, not the first gallery tile. That may be exactly
right — a video lander is striking — or may not be. Resolve before shipping C1.

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
