# Wire change — slice 6, reviews pool (2026-08-13)

Google reviews move from the legacy `platform_connections` payload to the content
pool lane. Backend-only execution; the frontends are told, not designed around
(spec `docs/superpowers/specs/2026-08-12-slice-6-reviews-design.md`).

> **STATUS (2026-08-13): PENDING — not verified on dev.** The branch is complete
> and the two migrations (`20260813110000` `f_review.author_uri`,
> `20260813110001` `content.source_stats`) are applied to
> `glncumufgaqcmqhzwrxm`, but slice 6's Task 10 (verify on dev) has **not run**
> and nothing below has been observed on a live payload. Treat every shape here
> as the code's contract, not as measured behaviour.
>
> Dev state measured 2026-08-13, before verification: `content.f_review` holds
> 15 rows — 5 on claimed (active) accounts with attribution, 10 on unclaimed
> accounts with author fields stripped at landing. `content.source_stats` holds
> **0 rows**; nothing has been re-projected since the aggregates lane landed, so
> `stats` is absent from every pool payload until a Google source runs again.
>
> **Prod: NOT run**, and out of scope for this slice. Prod has never had the
> content-pool migrations applied.

## `GET /api/public/profiles/{handle}` and `GET /api/content/pools/{pool}`

**Consuming repos:**
- `/api/public/profiles/{handle}` — partna-monorepo (`@partnaau/design-system`), public render
- `/api/public/profiles/{handle}/integrations` — same, the legacy platform payload
- `/api/content/pools/{pool}` — Partna-App, authenticated dashboard

### Added: `pools.reviews`

A seventh pool, alongside `watch`, `listen`, `media`, `events`, `services` and
`shop`. Same envelope as every other pool — `{items, latestItemId}` — plus the
`stats` key below.

- **`latestItemId` is always `null`.** Reviews are deliberately not in
  `LATEST_TAG_POOLS`: Google returns a vendor-curated sample of about five, and
  a "Latest" tag would present that sample as a chronology of the business's
  feedback.
- **The pool is absent when the selection is empty**, exactly like every other
  pool — including when the owner has switched the platform's reviews off (see
  below). An absent `pools.reviews` is not an error state.
- Ordering is `recency` from the section shape, not owner-chosen.

### Changed: every pool item gains a `review` block

Additive, and **present on every pool item regardless of kind** — the same
contract `startsAt` / `venue` / `price` / `frames` already keep. `null` off
every kind but `review`; no existing key changed or removed.

    "review": {
      "rating": 4.5,
      "text": "Excellent service, would recommend.",
      "authorName": "Jo Rivera",
      "authorPhotoUrl": "https://lh3.googleusercontent.com/a/…",
      "authorUri": "https://www.google.com/maps/contrib/1234…",
      "reviewedAt": "2026-07-01T10:00:00Z"
    }

- `rating` is a float on the 5-point scale and is always present — a Places
  review with no rating is not projected at all.
- **`authorName`, `authorPhotoUrl` and `authorUri` are null for an unclaimed
  (pre-claim) site.** The redaction is applied when the record LANDS, not when
  it is read, so a claim does not retroactively restore attribution — the
  fields stay null until a fresh billed fetch past the 7-day freshness window.
  Render an anonymous review; do not treat a missing name as a bug.
- `text` and `reviewedAt` are populated for claimed and unclaimed alike.
- `reviewedAt` is the reviewer's publish time, not our ingest time.
- **If you render `authorName` or `authorPhotoUrl` you are republishing a third
  party's personal data**, which is what `docs/legal/reviewer-data-disclosure.md`
  governs. `authorUri` is the reviewer's Google contributor profile and is
  carried deliberately so the pool lane reaches parity with the retired legacy
  payload rather than silently dropping a published field.

### Added: `pools.reviews.stats` — where the aggregates went

    "pools": {
      "reviews": {
        "items": [ … ],
        "latestItemId": null,
        "stats": {
          "ratingAvg": 4.8,
          "ratingCount": 127,
          "summaryText": "Customers praise the friendly staff."
        }
      }
    }

- **Absent when there is nothing to show**, the same contract `collections`
  keeps on `pools.shop`. Any of the three inner values may independently be
  `null`.
- `ratingAvg` / `ratingCount` are the place's Google star average and total
  rating count; `summaryText` is Google's own prose summary of the reviews.
- **This key is where `rating`, `reviewCount` and `reviewSummary` went** when
  they left the integrations payload below. It is the only place they are now
  served.
- Sourced from the pool's own selection, so an owner who switched reviews off
  has no `stats` either — publishing a 4.8 for someone who hid their reviews
  would republish, in summary form, exactly what they hid.
- `stats` appears on `pools.reviews` only. No other pool carries it.

### Removed: four `google-business` keys on `/integrations`

`GET /api/public/profiles/{handle}/integrations` no longer publishes, for the
`google-business` platform:

| Removed key | Now served by |
|---|---|
| `reviews` | `pools.reviews.items[].review` |
| `reviewSummary` | `pools.reviews.stats.summaryText` |
| `rating` | `pools.reviews.stats.ratingAvg` |
| `reviewCount` | `pools.reviews.stats.ratingCount` |

- **The platform entry itself stays**, unlike the events and shop retirements:
  `url`, `name`, `address`, `lat`, `lng`, `businessStatus`, `category`, `phone`,
  `website`, `hours`, `links`, `editorialSummary`, `amenities` and `photos` all
  still publish from this lane.
- **This retires a read, not a fetch.** `GoogleBusinessService` still populates
  all four keys and the authenticated dashboard resource still reads them, so
  nothing about billing or the owner's toggle moves.
- A frontend that renders reviews from `/integrations` shows nothing after this
  change. Read `pools.reviews` instead.

### Behaviour change: `headline` is null on review items

`headline` used to be the reviewer's display name (via
`content.items.headline_cache`). It is now **null by contract** on every review
item, and `description` is null with it — the reviewer's name lived in two
copies that redaction, `content:prune-orphaned-review-pii` and the DSAR
omission did not reach. `content:purge-review-headline-pii` removed the
existing ones.

**Render the card from the `review` block.** `review.authorName` is the one copy
those three controls all govern. Do not fall back to `headline` for a title —
it is null on purpose and any future value there would be ungoverned again.

### Behaviour change: reviews can be hidden, but not pinned or reordered

Reviews are the first pool that is not the owner's own content, and curation is
**exclusion only**:

| Verb | Endpoint | Result |
|---|---|---|
| Pin | `POST /api/content/pools/reviews/selection/{item}` | **422** "Reviews can be hidden, but not pinned." |
| Reorder | `PUT /api/content/pools/reviews/order` | **422** "Reviews can be hidden, but not reordered." |
| Pin (section lane) | `PUT /api/site/sections/{id}/items/{item}` with `state: pinned` | **422**, same message |
| Hand-author | `POST /api/content/pools/reviews/items` | **422** "Reviews come from your connected platforms and cannot be added by hand." |
| Exclude | `PUT /api/site/sections/{id}/items/{item}` with `state: excluded` | **200** |
| Deselect | `DELETE /api/content/pools/reviews/selection/{item}` | **200** |

- **422, not 403** — the restriction is on the pool, not on the owner's rights
  over the resource.
- Dashboard implication: hide or disable the pin and drag handles on the reviews
  page rather than letting them fail. The remove control stays.
- Every other pool pins, reorders and accepts hand-authored items exactly as
  before.

### Behaviour change: the owner's reviews toggle now governs the pool

The `reviews` display toggle on a Google Business connection previously only
filtered the legacy `/integrations` payload. It now also removes the items from
`pools.reviews` — from the public selection, from the dashboard library, and
from `stats`. An owner who switched reviews off has no reviews page at all.

The gate is keyed per **source**, not per pool, so a second review platform
would not go dark with the first.

### Unchanged, deliberately

- **The fetch, the billing and the effect digest.** Reviews still ride the one
  `places.details` call shared with `profile` and `media`.
- **`DsarPayloadFilter::THIRD_PARTY_KEYS` keeps all four legacy keys**, so
  already-stored payloads stay disclosable — the 2026-08-05 precedent.
- Reviewer identity remains **withheld from the account holder's DSAR export**,
  now including `content.source_stats.summary_text`. The star average and review
  count are business facts about the owner and are **not** withheld.
- No change to any other pool, and no change to the `google-business` keys the
  authenticated dashboard reads.
