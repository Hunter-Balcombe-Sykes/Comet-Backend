# Wire change — slice 1a, media pool (2026-08-12)

Backend-only execution; the frontends are told, not designed around
(spec `2026-08-12-media-pool-slice-1a-design.md`, owner decision 2026-08-12).

> **STATUS: code merged to `media-pool-slice-1a` branch only.** The backfill
> command (`content:backfill-upload-media`) and section re-shape command
> (`content:reshape-media-sections`) have not run anywhere yet. Dev execution
> is Task 9; prod carries no media items at all. A frontend integrating against
> dev/prod today must not assume `pools.media` is populated.

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

### Unchanged, deliberately: `thumbnail`

Still a bare string (cover → poster → gallery role priority), on every pool.
For a media item with a single upload frame (the case until 1b), `frames[0]`
is the same asset `thumbnail` resolves to — read dimensions from there. When
multi-frame items land (1b, Instagram carousels), match on role `'cover'`
rather than position 0 if you need the thumbnail's dimensions, as `frames()`
is positional while `cover()` is role-priority and the two will diverge.
Making `thumbnail` an object was rejected: it is live on watch/listen/events
today and would break three surfaces to serve one.

### Ready for population: `pools.media`

Once Task 9's backfill (`content:backfill-upload-media`) and section re-shape
(`content:reshape-media-sections`) run on dev, the media pool will resolve
upload-backed items (25 uploads exist in dev's `site.site_media` source data).
The legacy `gallery` / `designMedia` payload keys remain on the wire and
should continue to work; new gallery work going forward should read
`pools.media` + `frames` instead.
