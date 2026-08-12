# Wire change — slice 0b, manual write lane (2026-08-11)

Backend-only execution; the frontends are told, not designed around
(spec `2026-08-11-content-pool-convergence-design.md`, owner decision).

## `POST /api/content/pools/{pool}/items`

**Consuming repo:** Partna-App (dashboard pool page). partna-monorepo is
unaffected — it renders the public sitepage from the built document and never
calls this endpoint.

**Before shape:** HTTP 201,
`{ selection: [...], library: [...], latestItemId: string|null }`

**After shape:** HTTP 201,
`{ selection: [...], library: [...], latestItemId: string|null }` — identical.

There is no `data` envelope on this endpoint: `ApiController::success()` returns
`response()->json($data, $status)` unwrapped. Noted because the public profile
route *does* have one, and the two are easy to conflate.

## Behaviour changes — three, none of them shape

### 1. A hand-added URL can now fold into an item that already exists

The write goes through `ProjectionWriter::writeManualItem()`, which resolves
identity before returning. A URL matching an item already in the pool folds
**into that item** instead of creating a second one.

So `selection` may not grow by one, and `library` may not gain an entry. Any
client assuming "POST an item → exactly one new row appears" is now wrong.
**Re-render from the returned `selection` / `library` rather than appending
optimistically.**

This is the point of the change, not a side effect: before it, a hand-add and
the synced item for the same video were two unrelated rows, and the next
connector run actively severed the hand-added one from its own source record.

### 2. A hand-add is also an edit of the matched item's displayed title

The folded-in item keeps the **owner's** headline and link. The manual source
sits at `priority 200` against a connection's `100`, and `ValueResolver`
resolves `f_text.headline` and `f_link.url` by source priority descending.

So hand-adding a URL that matches a synced item retitles that item to whatever
the owner typed. If the title field was left blank the headline becomes the
URL's host, which is rarely what an owner wants on an item that already had a
real title — worth a confirm step in the UI when the response comes back with a
`selection` that did not grow.

### 3. POSTing the same URL twice is idempotent

The coord is derived from the URL (`manual:sha1(strtolower(trim(url)))`)
instead of a fresh UUID per request, so the second POST upserts the first. It
still returns **201**, and it no longer creates a duplicate item, a duplicate
source item, or a duplicate pin.

The pin is now conditional for the same reason: a fold-in can return an item
the owner has already pinned, and `site.section_items` carries
`UNIQUE (section_id, item_id)`. Previously that combination would have raised
23505 and surfaced as a **500**.

Why derived rather than random: two manual coords carrying one URL poison that
URL as a joining key for the whole resolution run — `Resolver::poisonedKeys()`
drops a value a single source contributes twice, and a user has exactly one
manual source — which stops the synced item unioning too.

## Also worth knowing

A hand-add restamps `last_seen_at` on every item of that kind in the user's
library, because `refreshItemCaches()` batches the whole resolved set.
`library` is ordered by `last_seen_at DESC`, so its order collapses to a tie
after any hand-add. Pre-existing behaviour for connector runs; new for this
endpoint.

A hand-added item of a slugged kind now gets a public URL slug minted
automatically, through the same `ContentItemSlugAllocator::ensureCurrent()`
path a projected item uses. `slug` and `aliases` on the pool item payload
(slice 2) therefore populate for hand-adds without further work.

## Not changed

The endpoint's request shape, status codes and validation rules are untouched:
`url` (required, https), `title` (optional, ≤300), `kind` (optional, must be
one of the pool's kinds).

Standalone `resource_kind: "event"` rows on the legacy integrations wire are
**not** migrated by this slice. Slice 2's manifest notes they "move to the pool
when the manual write lane lands" — the lane now exists, but pointing the
Tickets & Events card at it is a separate change with its own wire impact.

**No action required** if the client already re-renders from the response body.
