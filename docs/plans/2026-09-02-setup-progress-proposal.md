# Proposal: showing the setup as it happens (owner decision — nothing built)

> Asked 2026-09-02, after the signup plan closed: (a) a centred "your site
> is still being set up" popup with a loader on the ASTRO sitepage until
> the build run finishes, and (b) a live view on the DASHBOARD signup of
> what is being fetched as it connects — platform names, some of the media
> being grabbed. This is the proposal for both; it builds only on what the
> run already produces. Nothing here is started.

## What exists today (the parts to build on)

- **Pre-ready, the sitepage already answers honestly.** While a build is
  `pending`/`building` the address serves Partna's own "This site is being
  prepared" document (`apps/pages/src/lib/site-preparing.ts`, ruled
  2026-08-31), refreshing itself every 60s. The public profile wire
  carries `buildState`. So (a) is only about the window AFTER ready — the
  30–120s in which the page is live but platforms, workplace, media
  mirrors and the menu are still landing.
- **The public build poll** `GET /public/signup/builds/{id}` carries
  `build_state`, `subdomain`, `site_url` and, since 9h, `tiers`
  (`content_filled_at`, `enriched_at`, stamped lazily on the poll). The
  dashboard's `site-building-card.tsx` reads exactly this and draws a
  two-row ladder (pending → building). So (b) is about widening what the
  poll says, not adding a second channel.
- **The stages already exist as jobs**, each with a clear "it landed"
  moment: SourcePrefetch (identity verified), media seed
  (`instagram.seed_media_oe`, N images + N videos), BioMentionChainsJob
  (venues checked), workplace corroboration (`fresha.workplace_link.
  connected`), platform routing (`platforms.instagram.bio_links_routed`),
  GoogleBusinessEnrichJob, MenuFetchJob (`fetch_status=ok`, dish count),
  MirrorMediaAssetJob (per asset), WebsiteGalleryScanJob (grabs). Today
  they only log.

## The one primitive both surfaces need: a build progress ledger

A small append-only record per build, written by the jobs at the moment
each piece of work lands, read by the poll.

```
core.pre_account_build_events
  id            uuid
  build_id      uuid  (FK pre_account_builds, cascade)
  stage         text  -- identity | platforms | media | workplace | menu | mirrors | website | done
  status        text  -- started | landed | skipped | failed
  label         text  -- the sentence the UI shows, written by the producer
  payload       jsonb -- optional: platform slugs, counts, up to ~6 thumbnail URLs
  created_at    timestamptz
```

- One helper, `BuildProgress::note($buildId, $stage, $status, $label,
  $payload = [])`, called from the jobs listed above at their existing
  log lines — the log line and the event are the same fact, so the
  producers don't drift. Fire-and-forget (a ledger write must never fail a
  build), ~12 call sites.
- **"Finished"** — the thing (a) waits for — is a `done` event, written
  when the build's fan-out has settled. The honest definition is "no
  build-scoped job left": each producer increments a per-build pending
  counter in Redis when it dispatches follow-ups and decrements on
  completion; the decrement that reaches zero after `enriched`/`content`
  have landed writes `done`. A 10-minute ceiling stamps `done` regardless
  (the same clock the preparing page and the mirror TTL already use), so
  a stuck vendor call can never keep a loader on screen forever.
- The poll grows one field: `progress: {done: bool, events: [...]}` — the
  events since an optional `?after=<event id>` cursor, capped at 50. The
  dashboard's existing poller keeps its cadence (2s) and just renders more.
- The sitepage needs the same answer keyed by HANDLE, not build id (the
  visitor has no id): `GET /public/sites/{handle}/progress` returning
  `{done, stage_counts}` only — no labels, no thumbnails (a visitor is not
  the owner; they see a loader, not the person's feed). Rate-limited like
  the prewarm route, cacheable for 5s.

Estimated effort: backend ledger + helper + poll field + handle endpoint
≈ 1.5 days including tests (the PG-lane stand-in for the new table is
half a day of that — the DDL guards will insist on it).

## (a) The sitepage overlay

- Rendered by `SiteDocument`/the dispatcher when the profile wire says the
  build is ready but not `done` — the wire gains `buildProgress: {done}`
  from the same ledger (one field, alongside `buildState`).
- A centred card in kit tokens (`--dk-gray-0` ground, `--dk-radius`, the
  hairline), the site's display name as the eyebrow, the line "Your site
  is still being set up", and the loader: the kit has no spinner — a
  three-dot pulse on `--dk-motion-*` is the honest primitive (I would add
  it to the design system as `ui/Loader`, not draw it inline; the
  showcase approves it first per the component rules).
- Client script polls `/public/sites/{handle}/progress` every 5s and
  removes the overlay on `done`, then reloads the document once so the
  finished pool/workplace render (the page is SSR per request — the
  reload is what picks up what landed).
- Dismissible (a small "Keep looking" link): the page beneath is real,
  and a visitor who wants to browse the half-built site may. It never
  shows on a claimed site or a site older than the 10-minute ceiling.
- Cost: ~0.5 day in `apps/pages`, plus the Loader primitive.

Two things the owner should decide here: whether visitors other than the
owner should see the overlay at all (alternative: show it only when the
page is opened from the signup flow, via a one-time `?setup=1` the
dashboard appends — everyone else gets the live page as it is), and
whether the overlay should say what stage it is on ("Finding your
platforms…") or stay a plain loader. The handle endpoint above supports
either; the one-time-param option is my recommendation, because a
stranger arriving in the first minute is rare and the plain live page is
already correct.

## (b) The dashboard signup feed

- `site-building-card.tsx` keeps its ladder for pending/building and, once
  `progress.events` arrive, grows a feed under it: one row per landed
  event, newest last, the label verbatim from the producer, and where the
  payload carries thumbnails, a strip of up to six (the same media-card
  family the pools use; `sizedImageUrl` at the small width).
- Example sequence for a partna Instagram signup, from the run's own
  timeline: "Found your Instagram" (identity, +3s) → "Grabbing 12 photos
  and 5 reels" + thumbnails (media, +21s) → "Checking 3 places mentioned
  in your bio" (workplace started, +35s) → "Workplace: Akro Studio"
  (+38s) → "Connected TikTok, Square and Google Business" with the three
  platform icons (+61s) → "Saving your media 14 of 28" (mirrors, counts
  update in place) → "Done — your site is ready".
- Skips are said plainly ("No bio links to check"), failures softly
  ("Couldn't reach Instagram just now — your site works without it"), the
  same lossy-vendor posture the build already has. Nothing is invented
  beyond what a producer wrote (CLAUDE.md: never invent a field the
  backend doesn't return).
- Cost: ~1 day in the dashboard, mostly the feed + thumbnail strip; the
  poll hook already exists.

## Order, if approved

1. Ledger + helper + `done` semantics + poll field (backend).
2. Dashboard feed (b) — it exercises every event, which is the test of
   the ledger's labels.
3. Handle endpoint + sitepage overlay (a), with the owner's answer on
   who sees it.

Total ≈ 3 days, all inside existing patterns; no new infrastructure, no
new vendor spend (the ledger writes ride the jobs that already run).
