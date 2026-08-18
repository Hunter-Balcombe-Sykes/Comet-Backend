# Handoff — continue the platform / pool test→fix→retest run

Written 2026-08-18 ~17:30 AEST at the end of the overnight + morning session.
Give this file to a fresh chat as its plan. It assumes the working style that
produced the last two days of results: **probe with a real account, read the
ground truth in the DB/wire, fix, re-probe, gate, log**.

## 0. Read first (in this order, ~10 min)

1. `docs/overnight-2026-08-18/LOG.md` — every finding, fix, live proof and open
   item from the run (W0–W11, then the morning session: listen restructure,
   merge proofs, categories, #13–#19, review fixes, "set live"). The final
   sections list what is still open.
2. `docs/2026-08-18-overnight-run-plan.md` §0 (the method) and §9 (owner rulings
   R1–R20 — these are fixed; do not re-litigate).
3. `docs/2026-08-18-prod-deploy-steps.md` — the one-off data steps; **already
   run against the live (dev) database**. The dormant `production` env is a
   separate owner decision (see LOG "Set live").
4. `docs/overnight-2026-08-18/W10-apify-actor-probes.md` and
   `W8-parity-sweep.md` (with its correction note) if you touch actors or
   dashboard parity.

## 1. Where things live and how to run them

- **Repos/lanes**: backend `~/Developer/Comet-Backend` (work on a branch off
  `development`, merge back and push — Laravel Cloud auto-deploys
  `development` = the LIVE API, `dev-api.partna.au`); dashboard
  `~/Developer/partna-monorepo/apps/dashboard` (commit on `main`, push —
  Vercel builds `app.partna.au`); sitepage `apps/pages` (deploy only via
  `npm run deploy`; it renders no pool items yet — another lane's rebuild).
  **The `production` Laravel Cloud env has been stopped since July; "live" is
  development.** Do not push to the `production` branch.
- **Local**: `bash storage/logs/overnight/restart-workers.sh` (Comet) starts
  8-worker `artisan serve :8000` (`--no-reload`), 2× `queue:work`
  (`--timeout=900`), `schedule:work`. Config/routes are cached locally — after
  editing `.env` run `php artisan config:cache`. Dashboard: launch config
  `dashboard` (:3000, points at localhost:8000). If a job dies mid-run it can
  leave a `ShouldBeUnique` lock (`laravel_unique_job:App\Jobs\…:{userId}`) —
  `Cache::lock($key)->forceRelease()`.
- **Test account**: `broken-oven` (tobiasinthe6@outlook.com), currently
  `account_type = business` (swap with `PATCH /api/me {"account_type":"partna"}`
  to test partna-only things: booking brands, Fresha team picker).
  Connected right now: Fresha (Vision Hair Studio, storewide, 40 services),
  Spotify + Apple Music (Tame Impala), Uber Eats + DoorDash (Souva King),
  YouTube (@veritasium), Vimeo, YouTube Music, Bandcamp (kingkrule), Instagram
  (@gypsea_lust), Google Business (Lower East by RÜH), Bopple, gumroad /
  mixcloud / tidal link cards, humanitix ×2, eventbrite ×2, soundcloud ×2,
  ~40 socials/brand cards.
- **Helper commands** (all dev-only unless noted): `partna:as <handle> METHOD
  /api/... [--json='{...}'] [--query=a=b] [--raw]` (act as a user through the
  kernel), `ingest:run --source=<id> --sync` (run one ingest source now),
  `ingest:project --source=<id>` (re-project landed records — use after
  projector changes), `partna:connect-sweep <handle> --fixtures=… --skip=…
  --out=…` (fixtures at the scratchpad `fixtures.json`; regenerate if the
  scratchpad is gone — 97 keys, url + handle per platform),
  `content:reshape-pool-sections <pool>`, `content:refresh-item-caches`,
  `content:retire-legacy-gphoto-records`, `partna:reset-test-user`.
- **Reading the wire**: `php artisan partna:as broken-oven GET
  /api/content/pools/<pool> --raw | python3 …` — pool = watch | listen | media |
  events | services | shop | reviews | custom_links | menus. Item keys of note:
  `format`, `album`, `trackNumber`, `sources[]`, `links[]`, `overrides[]`,
  `duplicateCandidates[]`, `collectionIds`, `collectionPositions`,
  `origin`, `selected`; pool-level `linkRoster`, `collections`.
- **Money**: Apify (spotify, spotify_releases, soundcloud, instagram, menus,
  google-business actors) and Places are billed; budgets in
  `config/partna.php` `limits.apify.actors` / places. Effects are cached ~5 min
  locally (`EffectLedger` freshness) — a re-run inside that window is free but
  returns the same data.

## 2. The model as it now stands (test against THIS)

**Listen (restructured 2026-08-18)**
- Kinds `track` / `release` / `episode`; every item carries `format` ∈
  album | ep | single | compilation | track | episode; a track carries
  `album` (parent release, `f_catalog.collection_title`) and `trackNumber`.
- Per platform: Spotify → tracks (top tracks, covers via oEmbed) + releases
  (discography actor, covers); Apple Music → releases (format from
  " - Single"/" - EP" suffix or track count) + songs (ONE per title: own
  collection > album > earliest); SoundCloud → tracks (with ISRC); YouTube
  Music → tracks (Topic channel); Bandcamp → releases (album / track page =
  single); Apple Podcasts → episodes.
- Auto rule = one arm PER FORMAT behind its own switch: releases/episodes on
  `auto_sync_latest` ("Newest release / episode"), tracks on
  `auto_sync_latest_track` ("Newest song / track"). Apple Music shows both
  switches. The arm binds the item's own kind (a track can never ride the
  release arm).
- Identity: `TitleRelease` (title|primary artist) merges cross-source within a
  kind; same-source duplicate values poison only within (source, kind);
  removed connections' sources do not vote; `OfferingName` floor is 4 for
  dishes/services (one owner's catalogue). Candidates the resolver won't merge
  show as `duplicateCandidates` → dashboard "Duplicate?" chip → Same/Different.

**Menus / services (category-first, 2026-08-18)**
- Every dish/service may sit in several categories; Category control on every
  item sheet (scraped included — non-manual dishes go through a
  categories-only PATCH path); order lives INSIDE categories
  (`collectionPositions`) and among categories; pool tables are select-mode
  with bulk remove; the Categories sheet expands each category to its items
  (drag reorder, "Add here", remove-from-category).
- Fresha: `deletesOnExhaustive`, venue currency stamped, eager run on connect
  AND on selection change (partna picks a stylist; business is storewide with
  optional narrowing via `POST /platforms/fresha/selection/storewide`).
- Menus (Uber Eats / DoorDash / Square) delete on exhaustive runs; the delete-
  guard (≥40% and ≥5 vanish) freezes deletion; a selection change pre-charges
  absences so it does not trip. Two lanes still write dishes: the legacy
  `MenuFetchJob` (manual source, merges platforms by name) and the per-platform
  ingest sources (`uber_eats:` / `doordash:` coords, run on Resync or by
  hand) — identity keys merge them, but keep an eye on it (see §4).

**Everything else**: disconnect = hide (LiveSourceScope; retired source_items
hide too); media pool = 5 newest per source, opt-in; GB photos stable identity;
reels play; per-platform links + add-link roster on every item sheet; source
list with sync badges; overrides make every field a real SyncedField.

## 3. Test plan — do these, in order, each with the probe → fix → re-probe loop

Every step: state the exact account/platform/URL, quote the wire or SQL, fix
on a branch, test (Pest / tsc+eslint), re-probe live, push, and append to
LOG.md as a new dated section.

**A. Listen, second pass (highest value)**
1. Fresh artist on both Spotify + Apple + SoundCloud + Bandcamp (pick one
   with all four, e.g. a mid-size indie act): connect in each order
   (Apple first, then Spotify; SoundCloud last) and prove: one item per
   release/track with N platform links; formats right (album/EP/single);
   tracks carry `album`; auto selection = newest release + newest track per
   platform; no duplicate (format, title) groups except genuine credit
   disagreements. Reproject after any projector change
   (`ingest:project --source`).
2. Podcasts: connect an Apple Podcasts show; episodes get `format=episode`,
   "Newest episode" switch works, they don't collide with music.
3. Turn switches off/on per platform in the sheet (Rules) and confirm the
   selection changes exactly per format (Apple: release off keeps the song).
4. Open X5: an Apple song with no `releaseDate` takes the Latest tag by
   first_seen ("Runway Houses City Clouds (2020 Mix)") — fix by filling from
   its collection's date in `AppleMusicConnector::pullSongs` or sorting undated
   last in `SectionCandidates::connectionSourceLatestArm`.
5. Spotify releases: `SpotifyReleasesAdapter` field names were read off one
   probe run (`Release Name`, `Release Type`, `All Cover Art URLs`, `Share URL`)
   — verify against a second artist; check the 640px cover rewrite renders.
6. Dashboard `/listen`: Type chip, Artist · Album subtitle, type facet, stacked
   source circles, item sheet Type/Album facts, Links + Sources sections,
   Duplicate? flow (find or seed a candidate: two items sharing `title_loose`).

**B. Menus / services categories, second pass**
1. Uber Eats + DoorDash + Menulog same restaurant: dishes merge across all
   three (0 duplicate titles), each with per-platform links; the ingest-lane
   dishes vs legacy-lane dishes do not double up after a Resync
   (`POST /platforms/uber_eats/refresh` runs the ingest source — check menus
   pool count before/after).
2. Categories sheet: drag-reorder inside a synced category and inside a
   hand-made one; "Add here" for a dish and for a service; remove-from-
   category; category reorder; verify `collectionPositions` on the wire and
   the order in the Categories sheet after reload. Services with none →
   Uncategorised block goes with the layout POST.
3. Item sheet multi-category on a scraped dish AND an ingest-lane dish AND a
   Fresha service; untick a vendor category (expect it to reappear on the next
   sync — document, or decide with the owner whether owner unticks should
   stick: `collection_items` PK lacks `source_id`).
4. Bulk remove on /menu and /services; verify items still being auto-published
   refuse with the toast.
5. Fresha: partna account — pick a stylist, change stylist, verify services
   swap within a minute (eager run) and the departed ones hide (not after 3
   runs); business — storewide ↔ narrowed round trip.

**C. Auto-sourcing / rules across every pool**
1. For each sourcing platform connected: flip its Rules toggle(s) off → the
   auto rows leave the selection; on → they return; `Latest` tag correct.
2. Resync button per platform: ingest run lands (`ingest.runs`), cooldown 429
   on rapid repeat, 10-min per-source skip; scheduler cadence (`ingest:dispatch`
   every 15 min) picks up free sources; paid ones only when allow-listed
   (`partna.ingest_scheduled_paid_sources`).
3. Disconnect a platform → its items hide from selection + library; reconnect →
   they return (item_anchors) with the same ids.
4. Media: IG posts + reels selectable, reels play (`frames[].kind=video`), GB
   photos selectable, N=5 newest per source honoured after a new post lands.

**D. Connect matrix, second pass (business AND partna)**
1. Re-run `partna:connect-sweep` as **partna** (booking brands + Fresha team
   mode need it) — the last run was as business (22 booking brands 403 by
   capability). Fixtures with dead upstream pages (bandcamp gadigal-madness,
   vimeo staffpicks/nasa, circle/ordermate) should be replaced.
2. For every 2xx: an `ingest.sources` row where the platform sources; the
   Platforms table shows a human name (`ConnectionDisplayName`) not a URL;
   the connect-sheet summary shows name/avatar.
3. Suggestions inbox: connect an Instagram whose bio has links / a Google
   listing with booking + ordering links; check what auto-connects vs lands in
   "Found on your platforms" (thresholds in `RoutingPolicy`), Add / Not now,
   blocked reasons; "Found on Instagram/Google" section in the sheet with
   Use-this-one on a conflict.

**E. Regressions to keep an eye on**
- `MenuFetchJob` can exceed 600 s with two actors (local worker timeout now
  900); a killed job leaves its unique lock.
- `latest_per_auto_source` values with an empty kinds list = "own kind" arm on
  `auto_sync_latest` — the listen section rule must keep explicit values.
- Cached pre-W8 pool wires lack `linkRoster`/`sources` (helpers guard it).
- Bulk remove is N sequential requests (accepted; batch endpoint later).
- Sitepage renders no pool items yet — do not "verify on the sitepage" for
  pool content; the wire is the proof.

## 4. Open questions for the owner (carry forward)

- Owner unticks a vendor-synced category → next sync re-adds it (PK has no
  source_id). Should an owner decision stick (needs a decisions row / PK change)?
- Two menu lanes (legacy MenuFetchJob merge vs per-platform ingest sources):
  keep both (identity merges them) or retire the legacy lane?
- Dormant `production` env / prod Supabase — revive, or formally retire and
  point `api.partna.au` at development?
- Monorepo GitHub remote returned "Repository not found" from ~13:20
  2026-08-18 (pushes before that landed; Vercel built c0b94ab). Check repo
  access/rename before relying on Vercel's git deploys.

## 5. Gates before saying "done" (same as before)

Pest green for touched areas + full suite before merge (last full run: 8355
passed / 0 failed), `npm run typecheck && npm run lint` in apps/dashboard,
`vendor/bin/pint --test`, live re-probe on the wire, push, and a post-deploy
check (`curl https://dev-api.partna.au/api/health`, `vercel ls` shows a new
Ready production build). Log everything in `docs/overnight-2026-08-18/LOG.md`
(or a new dated LOG if you prefer — link it from the old one).
