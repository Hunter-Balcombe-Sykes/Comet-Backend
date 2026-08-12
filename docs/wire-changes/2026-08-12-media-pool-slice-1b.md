# Wire change — slice 1b, media pool (2026-08-13)

Appends to `2026-08-12-media-pool-slice-1a.md`; it does not restart that lineage.
Read 1a first — `frames[]` and everything about `thumbnail` is defined there and
is **unchanged** here.

Backend-only execution; the frontends are told, not designed around
(spec `2026-08-12-media-pool-slice-1b-design.md`).

> **STATUS: pending dev deploy.** Updated with live figures once the commands
> have run against dev.

## `GET /api/public/profiles/{handle}` and `GET /api/content/pools/{pool}`

**Consuming repos:**
- `/api/public/profiles/{handle}` — partna-monorepo (`@partnaau/design-system`), public render
- `/api/content/pools/{pool}` — Partna-App, authenticated dashboard

### Changed: `frames[]` gains an optional `attribution` object

Additive. No existing key changed or removed.

    "frames": [
      { "url": "https://lh3.googleusercontent.com/place-photos/…",
        "width": 1200, "height": 800,
        "role": "gallery", "alt": null,
        "attribution": {
          "authors": [{ "name": "Jo Rivera", "uri": "https://maps.google.com/maps/contrib/1234" }],
          "maps_uri": "https://maps.google.com/photo/abc",
          "flag_uri": "https://maps.google.com/flag/abc"
        } }
    ]

- **Present only on Google-sourced frames.** Absent on uploads and Instagram.
- **Every key is optional, and the whole object is often absent.** Google
  supplies attribution for only about half the photos it returns — 60 of 110
  live entries on dev. An absent object means Google gave us nothing, not that
  we dropped it. `authors[].uri` may be null even when `name` is present.
- **If you render a Google photo, you must render its credit.** Google's Places
  terms require the author credited and the photo linked back to Maps via
  `maps_uri` wherever it is displayed. `flag_uri` is the content-reporting link
  the same terms require you to expose. **This is a licence condition, not a
  nicety** — a Google frame rendered with no credit is a terms breach on a
  surface we control.
- Where the object is absent there is nothing to display and nothing is owed.

### Now populated: Google photos resolve to servable URLs

Before 1b, the ten Google media items carried a ref and no URL, so every frame
was omitted and `pools.media` showed an empty gallery for Google-only sites
(1a's manifest called this out — *"today: the ten ref-only Google photos, which
1b makes servable"*). Those frames now carry a real `url`.

**Treat a Google photo URL as perishable.** It is an unkeyed
`lh3.googleusercontent.com/place-photos/…` link that **dies at roughly 30 days**
— measured: 200 at 27 days, 403 at 29. Do not cache it beyond the payload TTL,
do not persist it client-side, and do not build a permalink out of it. The
backend re-resolves on a 7-day cadence.

### Behaviour change: Google media cannot be pinned

`POST /api/content/pools/media/selection/{item}` returns **403** with
`{"message": "This photo comes from Google and cannot be pinned."}` when the
item is Google-sourced.

- **403, not 404** — the owner does own the item; the restriction is on the
  verb, not the resource.
- The item is still returned by `GET /api/content/pools/media` and still
  renders on the public payload. Only permanence is withheld.
- Dashboard implication: the pin control on a Google photo should be disabled
  or absent rather than left to fail. A tooltip explaining that Google photos
  rotate is more useful than an error toast.
- Uploads and Instagram media pin exactly as before.

**Owner-facing consequence, stated plainly:** the six dev sites whose entire
selection is Google photos have an empty background picker today and will still
have one after this slice. Google photos flow to the page automatically; they do
not flow into the picker. Filling it is uploads, Instagram, or a product
decision about whether the backdrop may draw from a live borrowed feed — out of
scope here, and deliberately not papered over.

### Unchanged, deliberately

- **`gallery` and `designMedia` are still live** and still populated. They
  cannot retire until Partna-App's Media page and the monorepo gallery render
  read `pools.media` instead. New gallery work should read `pools.media` +
  `frames`; existing readers keep working.
- **`thumbnail` is still a bare string**, with `cover → poster → gallery` role
  priority. See 1a's manifest for why, and for the `frames[0]` vs `cover()`
  divergence once multi-frame items land.
- No change to any other pool.

## Why Instagram looks different from Google

Both appear as `kind: "media"` with `frames[]`, and the difference is not
cosmetic:

| | Google Business | Instagram / uploads |
|---|---|---|
| Bytes | never stored — displayed live | mirrored to R2, ours |
| URL lifetime | ~30 days | stable |
| Identity | **rotates every fetch** | stable |
| Pinnable | no | yes |
| Attribution | required on display | none |

A Places photo's resource name is reissued on every Details fetch, so the same
photograph is a different item id a week later. Nothing client-side should
assume a Google media item id is durable — not for analytics keys, not for
"seen" state, not for deep links. Instagram and upload item ids are durable.
