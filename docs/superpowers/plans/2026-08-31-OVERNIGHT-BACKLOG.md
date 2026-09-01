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

## E. Discovery findings (2026-08-31, six of eight investigations reported)

Every row below carries a file:line, a live curl, or a SQL result. High severity only in this pass;
the medium and low rows live in the workflow journal and merge in at consolidation.

### E1 — The share image on 18 partna sites is a 404 · `TODO` · high

`head-builder.ts:198` falls back to `new URL('/og.png', canonicalUrl)`, and the comment above it claims
the route "always exists, so og:image is never omitted". **That route was deleted** — added in `a595652`,
removed by `575dfee` ("The reset: pages torn to the document…"). Any site with no media item publishes a
404 as its Open Graph image.

### E2 — SVG-only square logos never reach the wire — including Sepia, from the owner's screenshot · `TODO` · high

`SitepageDataResolverService.php:711-715` reads `variantUrls()`, takes `optimized ?? original ?? ''`, and
**returns `[]` for the whole singleton when there is no raster variant** — so `url_svg` and `url_icon` are
discarded with it. 18 business sites have a ready `logo_square` that the page never sees. This is why
Sepia shows a generated "S": it *has* a logo.

### E3 — Reviews are already live for partna accounts, and the person-scoping is broken · `TODO` · high

A surprise: 14 partna users already hold 140 review items and four sites render a Reviews rail today.
The owner's "only ones mentioning their name" rule **already exists** — and does not work:

- `PersonNameMatch.php:47-51` takes the **first word** of the display name and accepts any token ≥3 chars,
  with no stopword or name check. A display name starting "The" or "Kebab" admits the venue's entire
  review set. Live on dev — including a negative review of the venue and praise for other staff.
- The employee-scoped bypass (`PoolResolver.php:1734-1737`) `continue`s before the review facet is read,
  so a review whose own attribution names a *different person* cannot be vetoed.
- The **aggregate** rating, count and Google summary publish unscoped, which `fcab35c` never covered.

This makes B2 a repair, not a build — and F3 (name derivation) is its hard dependency, as expected.

### E4 — ollies publishes another business's rating badge · `TODO` · high

`https://ollies.partna.au/reviews` renders **"5/5 · Based on 174 reviews"** beside a single review card,
while the account's own 6 live reviews and true 4.2/3925 Google rating are suppressed. Two competing
`google_business` connections for one place id leave two rating aggregates, and the wrong one wins.

### E5 — A claimed cafe cannot have a menu, because capability is a rename of account_type · `TODO` · high

`can_use_multipage_site: $isBusiness` and `BUSINESS_ONLY = ['menu','reviews']`. ollies is a Google-Business
cafe filed as `account_type='partna'`, so its **105 ingested menu items ship in the payload and render
nowhere**, while unclaimed `sepia` renders the identical page. Four accounts are in this state
(`account_type='partna'` with `sector_source='google-business'`). Capability should follow what the
account *is*, not the enum it was filed under.

### E6 — Section analytics and scroll-synced URLs are dead on every scroll site · `TODO` · high

`init-behaviors.ts:23` passes `document.body` as the observer root and `behaviors.ts:59` reads
`root.children` — but scroll's panels sit one level deeper. So page impressions and dwell observe nothing
on the entire live architecture.

### E7 — Three analytics beacons are silently discarded · `TODO` · high

- **Every `mailto:`/`tel:` click 422s.** `tracker.ts:64-95` fires for any cross-origin href — and
  `mailto:`/`tel:` have origin `"null"` — then `ClickRequest.php:29` validates `url:http,https`.
  Directly relevant: Task 1.2 just added `tel:` links, so those clicks would never record.
- **The RUM beacon is 100% discarded.** The tracker sends `subdomain`; `AnalyticsController.php:770-774`
  reads `handle`.
- `publicConfig.analyticsEndpoint` points at `/api/analytics`, a route that does not exist.

### E8 — Two dashboard controls are broken outright · `TODO` · high

- The **"Display a workplace page?" toggle double-encodes its body and writes nothing** —
  `workplace-visibility.tsx:70-73` calls `JSON.stringify` on a body that `api()` already stringifies.
- The **video upload cap is 500 MB client-side against a 200 MB server limit**, so the whole file
  uploads and then 422s.

### E9 — The headshot is seeded at build time only · `TODO` · high

`HeadshotAutoSeeder` has exactly two references: the class, and `GeneratePreAccountSiteJob.php:200`.
No claim-time hook, no refresh hook, no backfill command. 35 partna sites will never get one — 20 of
them **despite the source image already sitting on our CDN**. And `surfaces.instagram.profilePicUrl` is
on the wire, typed on the frontend, and read by nothing: a free fallback nobody wired up.

### E10 — A reel is not a stored concept · `TODO` · medium (blocks C1)

`InstagramConnector.php:196` captures `type` ∈ {Video, Image, Sidecar} into `record_versions.doc`, and
`InstagramMediaProjector.php:60-70` never projects it. The right home for the promotion rule is a pass in
`PoolResolver::assemble()` between `order()` and `applyLocks()` — not a write-time boost, not a pin.

Two live facts that shape C1: **`deck[0]` is the full-bleed autoplaying backdrop on every scroll site**, so
this rule flips 78 sites from a still to a muted looping reel — which is exactly what the owner's C2 ruling
asks for. And the audit's `.slice(1)` gallery concern was **against dead code**: that branch is staple, and
zero sites use staple.

### E11 — The edge holds pages for 24 hours while the app asks for 30 seconds · `TODO` · high

Found by deploying. After pushing both repos and deploying the pages worker, **none of the fixes were
visible** — `sepia` still emitted `data-item-type="platform"`, `amano` still had the boilerplate
description. Not a failed deploy; a 24-hour edge cache:

```
cache-control: public, max-age=15, stale-while-revalidate=30, s-maxage=86400
cf-cache-status: HIT   age: 7918
```

`max-age=15` and `stale-while-revalidate=30` match `PAGE_CACHE` in `apps/pages/src/lib/launch.ts:18`
exactly. **`edgeTtl` there is 30. The edge is serving 86400** — 2,880× what the application asked for.
Something between the Worker and the visitor is rewriting `s-maxage`; the router worker or a zone cache
rule are the candidates.

Two consequences, and the second is the serious one:

1. A deploy or a content edit is invisible for up to a day unless a purge succeeds. I had to purge by
   hand to verify my own deploy — after which all four fixes appeared immediately.
2. **It makes the purge lane load-bearing, and the purge job does not know that.**
   `CloudflareCachePurgeJob.php:60` justifies its whole retry policy with *"a late purge is harmless —
   the edge holds s-maxage=31, so the worst case is a slightly longer stale window"*. The real window is
   24 hours, and there have been **299 terminal purge failures in three days** with no Nightwatch issue
   and no APM. That is the user-visible consequence of the purge failures nobody had connected.

Fix: find what rewrites `s-maxage` and make the served value the one the app asks for; then correct the
purge job's stated risk model, which is load-bearing reasoning built on a wrong number.

### E12 — GYG's brand URL bills Apify every 15 minutes, forever · `DONE` · `d829bf6a8`

The Wave 1.5 recon found the loop: `menu:retry-unavailable` re-forces `MenuFetchJob` every 15 minutes,
`RetryUnavailableMenusCommand.php:36-41` selects on `last_fetched_at >= now()-6h`, and
`MenuFetchJob.php:209` **advances `last_fetched_at` on every failed attempt** — so a permanently dead
URL never ages out of its own retry window. Each pass burns `FALLBACK_ATTEMPTS` billed Apify runs,
because `responseRetryable()` cannot tell "this is not a store page" from "we were bot-blocked".

Fixed with the scrape guard in `MenuSource::entries()` plus the retry-window correction.

---

## F. Deploy log

**2026-09-01 ~04:00 UTC** — backend `development` pushed (11 commits), monorepo `main` pushed (6),
pages worker deployed (version `1fdc7499`). Verified live after a manual purge:

| fix | evidence |
|---|---|
| F1 platform beacon | sepia: `data-item-type="platform"` × 0, `data-action="platform:"` × 5 |
| F4 reach-me pair | ultra-tune: `href="tel:0399582100"`; sweetcakesofmine: `href="mailto:natasha@…"` |
| F9 structured data | amano: `telephone "+64 98736654"`, address carries `addressLocality`/`postalCode`/`addressCountry` |
| F10 meta description | amano: "Hip, bohemian, wood-&-brick restaurant & bakery serving locally sourced Italian cuisine." |

Still an Instagram post photo for `og:image` — that is Wave 2, in flight.

### E13 — One account was publishing another person's face · `DONE` · `4feced1b6` · was: critical

The most serious finding of the night, and it had been live for as long as batch builds have existed.

`InstagramConnectionSeeder.php:90` derived the R2 mirror prefix as
`'platforms/instagram/' . $connection->created_at->timestamp` — **a bare unix second, with no account
component**, while `$userId` sat in scope. Any two Instagram connections created inside the same second
shared a folder, and the second to mirror overwrote the first's `profile.jpg`.

Live on dev when found, two pairs serving byte-identical pictures:

```
aerial-studio          profilePicUrl: …/platforms/instagram/1787835720/profile.jpg
mr-bap                 profilePicUrl: …/platforms/instagram/1787835720/profile.jpg
melbourne-acupuncture  profilePicUrl: …/platforms/instagram/1788085840/profile.jpg
the-cobblers-last      profilePicUrl: …/platforms/instagram/1788085840/profile.jpg
```

Three things make it worse than the line looks:

- **A batch build is the condition that causes it.** A fleet run creates several connections per second,
  so the collision rate *rises with throughput* — exactly backwards for an identity path.
- **We amplified it hours earlier.** `profilePicUrl` went from a wire field nothing read to the og:image
  fallback tier, turning a latent collision into a published share image.
- **A second harm on the same line:** `DeleteMirroredMediaJob` is dispatched with the payload's folder
  (`IntegrationConnectionObserver:551` and `:638`), so disconnecting one account deletes another
  account's mirrored media.

Fixed by keying the prefix on the connection uuid, and by making the rule a named method rather than an
anonymous string concat — being one inline expression is part of why it survived so long. Mutation gate:
restoring the old scheme kills all three tests. **The four existing rows still share their folder until
re-mirrored** — that backfill is in Wave 4, not folded silently into the fix.

**Look for this class elsewhere.** Any storage prefix keyed on a clock rather than an identity has the
same defect. Wave 4 is auditing the other mirror lanes.

---

## G. Deploy log, second pass

**2026-09-01 ~07:45 UTC.** Schema first, deliberately: `content.source_items.ingest_selection_ref`
applied to dev before the code that reads it, because the person-scope gate selects that column and code
ahead of schema 500s the reviews pool on every person's page. Then backend `development` (4 commits) and
monorepo `main` (4), then the pages worker (`9b70ba24`).

Verified live after purge:

| | before | after |
|---|---|---|
| `broken-oven` reviews | 7 strangers', incl. a 1★ venue review and praise for two other people | **0** |
| `ollies` review badge | a hair salon's "5/5 · Based on 174 reviews" on a coffee shop | **null** |
| `jjsavani` og:image | a random Instagram post | the headshot |
| `emdinonhair` og:image | a random Instagram post | the Instagram profile picture |

Suites: backend **10,123 passing**, pages **205 passing** (94 at the start of the night).

---

## H. Waves 5 & 7 — high-severity findings (read-only, ~55 investigations total)

Every row proven against live HTTP, a SQL result or a file:line. The heavy ones:

### Serving-plane / security
- **Three "not public" controls do not bind on the public read path** — the owner publish toggle, a
  moderation takedown, and the pre-account claim gate. Two proved live against dev-api. An unpublished or
  taken-down site is still readable by URL.
- **The public API 500s at ~20 concurrent** — Supabase pooler in session mode. Serving-plane fragility.
- **RLS is not evaluated on the serving path at all** — `app_backend` has `rolbypassrls=true`, and 36
  RLS-enabled tables (incl. `core.users`, `site.sites`) have no `app_backend` policy and work only by the
  bypass. So the 115 RLS advisories are noise; the real wins are unindexed FKs on the account-deletion
  cascade and 8 duplicate-index pairs.

### Prod promotion is an outage waiting to happen
- **Prod is frozen at the 2026-07-26 baseline: 4 migrations applied, dev has 156.** Prod is missing four
  entire schemas — `catalog`, `content`, `ingest`, `routing` = 66 tables — plus 7 `site.*` tables. Its
  code is equally frozen (`origin/production` is 2,270 commits behind), so today it is self-consistent.
  **The danger is the promotion**: `production` is the default branch, the push IS the deploy, there is no
  CI gate, and a fast-forward ships 2,270 commits querying 66 non-existent tables. Prod DB is empty
  (`core.users` = 0), so the right move is a **from-zero re-baseline**, not a 153-file replay. Ordered
  plan is in the Wave 7 report. **Do not promote to production without it.**

### Claim flow
- **Issue 22's migration backfill is wrong**: all 9 claimed dev builds are `published_by_claim=false` with
  `is_published=true`, so releasing any of them reproduces the exact issue-22 exposure.
- **The claim token is unreachable end to end** — no frontend reads `?t=`, the `/claim` redirect drops the
  query string. **196 outreach builds on dev are permanently unclaimable.**
- `release()` is a partial inverse of `claim()` — leaves contact-routing email, early-access link, expiry
  and invite stamp behind, so a released site can hand a stranger's enquiries to the next claimer.

### Accessibility — the sitepage is unusable without a mouse
- **Every card and nav row is a `<div>` with a click listener** — 115 on ollies. No keyboard user can open
  anything; assistive tech is never told they are interactive. No `<main>`, `<nav>`, `<h1>` or skip link on
  any live site. Page changes update only the URL — title, headings and live regions stay silent.
- **A contrast bug is live**: `emit-kit-css.ts:117` puts near-white ink on mid-luminance accents — sepia is
  at 1.85:1 right now.
- **Notch handling is absent**: `viewport-fit=cover` is set but `env(safe-area-inset-*)` appears zero times,
  so the nav renders inside the Dynamic Island on every notched iPhone.

### Empty states — a thin site renders as literally nothing
- **The home page never renders content on scroll** (`p.id === 'home' ? null`); its only substance is
  `media.deck[0]` as a background. Five live accounts serve HTTP 200, `robots: index`, and a **blank white
  viewport**. A section whose presence gate and render derivation disagree falls through to `<p>{p.id}</p>`
  — the raw page id as unstyled text.

### Cost
- **The pre-account build lane spends Apify without touching ApifyBudget**; the legacy `integrations:refresh`
  Places lane re-bills ~700 Place Photos/day because its carry-forward key can never match; the same 146
  Google listings are billed twice within seconds by two lanes blind to each other.

### Test suite
- No coverage driver, no coverage gate, no mutation run anywhere in CI. **Four production fail-closed boot
  guards are "tested" by grepping their own source** — one was inverted and all ten tests stayed green. The
  schema-drift CI check compares against a snapshot predating all 156 migrations, with the staleness test
  that would say so switched off.

### Doctrine
- **18 stale/wrong claims across the five doctrine files**, four load-bearing — incl. `partna.au` served by
  an undocumented `apps/marketing` workspace while the hub attributes it to the frozen Partna-Frontend, and
  the edge-TTL claim (30s not 86400) that misled work all night.

Full detail (11–18 findings each, incl. medium/low) is in the Wave 5 and Wave 7 task outputs.

---

### E14 — A build-time edge render serves raw Instagram URLs until someone purges · `TODO` · high

Owner-reported on `bydannydixon.partna.au/gallery`: one photo and a row of empty boxes. Diagnosis, proven
live 2026-09-01: the edge held a render made DURING the build, before media mirroring finished — its six
gallery images pointed at raw `scontent-*.cdninstagram.com` URLs, which browsers refuse (hotlink
protection; curl 200s the same URL, a browser gets nothing). The wire had all five mirrored items the
whole time. A manual purge fixed it instantly — fresh render, all images from our CDN.

This is E11 (24h edge TTL) × F11-class (raw vendor URL served when the mirror is not ready) compounding.
**Durable fix: fire a purge when a site's media mirroring completes** — the moment the mirrored URLs
exist is the moment the cached pre-mirror render is wrong. Same shape as the doc-rebuild-on-content-write
rule (T4).

---

## J. RE-AUDIT VERDICT (2026-08-31 21:59–22:56 UTC, fresh 23-account batch on the fixed code)

Batch: 23/23 ready, zero failures, zero rejected specs. Instagram builds 14–58s, Google 2–6s;
scraping drained 23 min after last ready. Full detail in the Wave 6 task output.

### The honest table

**FIXED, live evidence (8):** F1 platform beacons · F4 tel: links (both account types) · F9 JSON-LD
address+phone · F10 real meta descriptions · F16 whole-batch dispatch · L1 review attribution
(155 wires read card-by-card, zero violations) · L2 venue aggregate (5 badges recomputed, all match)
· L3 mirror-folder collision (209 connections, 209 distinct folders, legacy pairs repaired).

**PARTIAL (5):** F2 (failed builds 404; pending window remains) · F6 (no new failures in-window; two
legacy rows) · F11 (mirrored on most; TikTok CDN still failing on some) · F15 (Booksy+Treatwell
connectors mapped; Cliniko/NowBookIt not) · F21 (dark by design at the edge).

**STILL OPEN (10):** F3 — NameShapeGate does not exist at HEAD; 8/12 fresh IG accounts wrong, brands
split into fake person names ("The Edit", "Tension Music"). F5 — worse: 79/238 sector-null. F7 —
username 'p' stored from a /p/ path. F8 — **a /brand-city/ URL evaded the /brand/ guard** (schnitz).
F13 — 12 purge failures in-window. F14 — SVG 422s now the terminal mode. F17, F18 (17/30 profiles
don't lead with home — wider than the sector-front case), F19, F20, F22.

**NOT MEASURED (6):** F12 (suite-side), L5–L8 (no sweep drove a browser).

### New defects the re-audit surfaced (top of 31)

- **141 of 230 live sites link a stylesheet that 404s after a deploy** — asset-hash churn × the 24h
  edge cache; they render unstyled until purged. The biggest visitor-facing defect on the platform,
  and the generalisation of E11/E14.
- **harper-blohm-cheese-shop publishes another company's identity**, and its Website resolves to
  gambling spam.
- **Nightwatch has recorded nothing since 2026-08-30 23:00** — the batch ran with telemetry dark, so
  "no new issues" has meant nothing for two days.
- The five deliberately-dark handles serve complete payloads on the wire.
- OCR menu lane returned zero items on 7/7 fresh restaurants despite menu-dense photos every time;
  25/78 food accounts have no menu.
- ~12 concurrent sitepage renders exhaust the Supabase session pool → public 500s (matches the
  Wave 5 pooler finding).
- Seven sitepages publish a byte-identical og:image belonging to another handle (share-image variant
  of the folder-collision class — needs the same uuid keying).
- 'Sports School' classifies a sailing school as a gym; 13xx phone numbers get a wrong +61 transform;
  grilld og:image is 88px.

Full list of 31 with evidence: Wave 6 task output (`wusdiwedb`).

---

## I. Still running

- Nightwatch: a verdict on all 50 open issues (fixed-stale / live / infra / by-design-noise).
- The wide hunt for anything nobody has named.
