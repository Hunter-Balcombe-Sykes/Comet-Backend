# Wire change — slice 1a, media pool (2026-08-12)

Backend-only execution; the frontends are told, not designed around
(spec `2026-08-12-media-pool-slice-1a-design.md`, owner decision 2026-08-12).

> **STATUS (2026-08-12): LIVE on dev.** Merged to `development`, deployed, and
> both commands have run against dev: 10 `pool:media` sections re-shaped, 25
> uploads backfilled (verified: 45 media items, 25 upload-backed assets, all 25
> resolving with non-null `frames[0].url` in both library and selection).
> **Prod: NOT run** — prod carries no media items at all; the migration and
> commands reach prod with the next prod deploy cycle.

## `GET /api/public/profiles/{handle}` and `GET /api/content/pools/{pool}`

**Consuming repos:**
- `/api/public/profiles/{handle}` — partna-monorepo (`@partnaau/design-system`), public render
- `/api/content/pools/{pool}` — Partna-App, authenticated dashboard

### Changed: every `poolItem` gains `frames`

Additive on every pool — same contract `startsAt`/`venue`/`price` follow.
No existing key changed or removed.

    "frames": [
      { "url": "https://…", "width": 2400, "height": 1600,
        "role": "cover", "alt": "A shopfront" }
    ]

- Populated ONLY for `kind: "media"` items; `[]` for every other kind.
- Ordered by the item's frame position (positional, NOT role priority).
- `width`/`height` are the SERVED rendition's dimensions — reserve layout
  space with them; they are why the key exists. Either may be null.
- `role` is one of `cover|poster|gallery`. (The schema permits `avatar` and
  `logo`, but `PoolResolver.php:306` filters the query to these three roles
  only; avatar and logo may appear in future slices.)
- A frame that cannot resolve to a URL is OMITTED, never null — an item can
  legitimately carry `frames: []` (today: the ten ref-only Google photos,
  which 1b makes servable).
- A media item may represent a video upload — its frame is the video's
  still/poster rendition (`image/webp`), and nothing in the payload
  currently distinguishes it from a photo item; a richer video shape is
  1b+ work.

### Unchanged, deliberately: `thumbnail`

Still a bare string (cover → poster → gallery role priority), on every pool.
For a media item with a single upload frame (the case until 1b), `frames[0]`
is the same asset `thumbnail` resolves to — read dimensions from there. When
multi-frame items land (1b, Instagram carousels), match on role `'cover'`
rather than position 0 if you need the thumbnail's dimensions, as `frames()`
is positional while `cover()` is role-priority and the two will diverge.
Making `thumbnail` an object was rejected: it is live on watch/listen/events
today and would break three surfaces to serve one.

### Now populated on dev: `pools.media`

The backfill (`content:backfill-upload-media`) and section re-shape
(`content:reshape-media-sections`) have run on dev: the media pool resolves
25 upload-backed items with servable URLs and dimensions.
The legacy `gallery` / `designMedia` payload keys remain on the wire and
should continue to work; new gallery work going forward should read
`pools.media` + `frames` instead.
