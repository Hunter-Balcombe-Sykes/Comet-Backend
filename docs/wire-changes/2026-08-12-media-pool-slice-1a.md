# Wire change — slice 1a, media pool (2026-08-12)

Backend-only execution; the frontends are told, not designed around
(spec `2026-08-12-media-pool-slice-1a-design.md`, owner decision 2026-08-12).

## `GET /api/public/profiles/{handle}` and `GET /api/content/pools/{pool}`

**Consuming repos:** partna-monorepo (`@partnaau/design-system`), Partna-App.

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
- `role` is one of `cover|gallery|poster|avatar|logo`.
- A frame that cannot resolve to a URL is OMITTED, never null — an item can
  legitimately carry `frames: []` (today: the ten ref-only Google photos,
  which 1b makes servable).

### Unchanged, deliberately: `thumbnail`

Still a bare string (cover → poster → gallery role priority), on every pool.
For a media item, `frames[0]` is the same asset `thumbnail` resolves to —
read dimensions from there. Making `thumbnail` an object was rejected: it is
live on watch/listen/events today and would break three surfaces to serve one.

### Now populated: `pools.media`

The media pool now resolves upload-backed items (25 on dev after backfill).
The legacy `gallery` / `designMedia` payload keys STAY until both frontends
stop reading them — nothing to change today, but new gallery work should
read `pools.media` + `frames`, not the legacy keys.
