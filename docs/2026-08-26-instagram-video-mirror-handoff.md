# Handoff: Instagram media-pool videos never mirror (diagnosed, fix scoped, NOT built)

Symptom: on ollies (dev), the scroll gallery's video cards show only their
poster thumbnails. The sitepage `<video>` elements get MediaError code 4 —
their `src` is a raw `instagram.fcks6-1.fna.fbcdn.net/o1/v/…` URL that no
longer resolves. Diagnosis is COMPLETE (2026-08-26, evidence below); the fix
is scoped but not implemented. Frontend needs nothing — it already falls
back to the poster when a video won't load.

## Root cause (proven)

**The Apify Instagram actor returns `latestPosts[].videoUrl` values that are
already expired on arrival.** Instagram signs `/o1/v/` video URLs with a
`oe=<hex epoch>` expiry (~24h life). All five of ollies' media-pool video
assets carry `oe=` stamps decoding to **2026-08-25 10:32–13:02 UTC** — the
connect that ingested them ran **2026-08-26 02:42**, so every URL was ~15h
dead before we ever fetched. The actor served a cached crawl (`efg` payload
decodes with `urlgen_source: "www"`, i.e. harvested from instagram.com web).

The mirror pipeline itself is NOT broken and needs no repair:
- `MirrorMediaAssetJob` dispatched on time (02:44, 2 min after mint).
- `MediaMirror::stream()`'s video branch ran, got the 403, recorded
  `fetch_failed`, retried once, stopped. Every asset row reads
  `mirror_attempts: 2, mirror_last_reason: 'fetch_failed',
  mirror_eligible: true, storage_path: NULL`.
- Cloud logs confirm: `media_mirror.failed` ×5, reason `fetch_failed`,
  host `instagram.fcks6-1.fna.fbcdn.net`, 02:44:22–27.

**Why images/posters work:** Instagram IMAGE URLs (`scontent…cdninstagram.com`)
sign with much longer expiries — the same stale payload's photos and video
posters mirrored fine. Hence "thumbnails show, videos don't".

## Ruled out (don't re-litigate)

- **UA/WAF gate: NO.** A freshly-signed `/o1/v/` URL serves `206 video/mp4`
  to every UA tested — including our exact
  `Mozilla/5.0 (compatible; PartnaBot/1.0; +https://partna.au)` and the bare
  fallback `PartnaBot/1.0`. (Expired URLs DO differ by UA — bots get a bare
  403, browser UAs get a 302 to `video.xx.fbcdn.net` that then 403s — but
  that's post-mortem behaviour, not the cause.)
- **Our profile cache: NO.** `CacheKeyGenerator::instagramProfile` TTL is
  900s (`partna.limits.platforms.instagram.profile_reuse_seconds`) — can't
  produce 15h staleness. The staleness is actor-side.
- **Dispatch/wiring gap: NO.** Video mirroring is fully built
  (`MediaMirror` video branch: ftyp sniff, 80MB cap, streams to R2 as mp4).

## Unresolved wrinkle (minor)

The connect-card hero reel (`InstagramConnectionSeeder::mirrorVideo`,
plain `Http::` fetch) SUCCEEDED at 02:42:31 from the same payload —
`site.platform_connections.payload.videoUrl` points at our storage. Its
source URL isn't stored, so we can't read its `oe=`; most plausible is that
the newest post's URL in the actor payload was fresher than the older
posts'. Not load-bearing for the fix, but if you want certainty, log the
`oe=` at seed time.

## The scoped fix

Key enabler #1: **`https://www.instagram.com/p/{shortCode}/embed/` serves a
freshly-signed video URL with NO login** — verified live 2026-08-26: the
embed page's `<video>` src was a `/o1/v/` URL with `oe=` ~36h in the future,
and it served 206 to PartnaBot UA.

Key enabler #2: **we store each post's shortcode** — the source coord is
`instagram:acct-{hash}:{shortCode}` (`content.source_items.coord`), e.g.
`instagram:acct-64c9bb79f1f4e83c:DcXMRT9OtrX`.

Proposed shape:
1. **Pre-flight expiry check**: before fetching any fbcdn `/o1/v/` URL
   (projection mint or mirror attempt), parse `oe=` (hex epoch). Already
   expired / near expiry → skip the doomed fetch, go straight to refresh.
2. **Refresh path**: item coord → shortCode → fetch
   `instagram.com/p/{shortCode}/embed/` → extract the fresh `/o1/v/` URL
   from the embed HTML → update `media_assets.source_url` → mirror as
   normal. Bounded attempts, defensive extraction (unauthenticated surface:
   can rate-limit or change markup); terminal state stays "poster only",
   which the frontend already renders gracefully.
3. **No data surgery needed**: the five stuck assets keep
   `mirror_eligible: true`, so they revive as soon as the refresh path runs.

## Pointers (dev DB = supabase ref glncumufgaqcmqhzwrxm; laravel-boost database-query works)

- ollies: site `019e5c37-9a82-70a3-a04c-f2f20a4dcf70`,
  user `019e5c37-9a69-725c-b3a9-6a345af0376d`
- Stuck video assets (`content.media_assets`): `457ee7cb-…`, `4967387f-…`,
  `2d2ff817-…`, `138046b1-…`, `6f6a9e5c-…` (all `source_url LIKE '%/o1/v/%'`,
  `storage_path IS NULL`)
- Items ↔ shortcodes: join `content.item_media` → `content.source_items.coord`
- Code: `app/Services/Media/MediaMirror.php` (video branch ~L116-200, fail
  reasons), `app/Jobs/Media/MirrorMediaAssetJob.php`,
  `app/Services/Http/SafeUrlFetcher.php` (`tryFetchToFile` ~L326, 403-retry
  swaps UA bot→barer-bot, fine as-is), `app/Services/Platforms/InstagramScraper.php`
  (`latestMedia` ~L415, profile cache ~L63),
  `app/Services/Platforms/InstagramConnectionSeeder.php` (hero-reel mirror
  ~L80-107, `attemptMirrorVideo` ~L497)
- `oe=` decode: `int(hex, 16)` → unix epoch UTC
- Frontend consumer (already shipped, no changes needed): scroll gallery in
  `partna-monorepo/apps/pages` renders media-pool videos muted/loop/autoplay
  with poster fallback; a mirrored URL landing in `frames[]` lights them up
  automatically.
