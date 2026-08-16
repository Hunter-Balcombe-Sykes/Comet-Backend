# Wire change — slice 7, legacy teardown (2026-08-12)

Backend-only execution. Spec: `docs/superpowers/specs/2026-08-12-slice-7-teardown-design.md`.
Plan: `docs/superpowers/plans/2026-08-12-slice-7-teardown.md`.

One manifest for the slice; each unit appends its own section as it lands.

---

## Fresha `payload.selection` — composed from `content.*` (Task 11, unit C)

**Consuming repos: partna-monorepo** (`@partnaau/design-system`, the public
booking card on `GET /api/public/profiles/{handle}` →
`integrations.fresha.selection`) **and Partna-App** (unchanged — it already
reads this lane).

`FreshaServiceProjector` no longer writes `site.services` rows and no longer
composes the blob out of them. `services[]` is now read live from `content.*`
through `FreshaServiceItems` — the same `kind = 'connection'` lane, the same
nine-key reproduction, that `FreshaSelectionResource` has served the dashboard
since slice 3b. The blob itself stays on the public wire (spec D3); only its
source moved.

### 1. Owner-edited Fresha prices are no longer honoured — **deliberate removal**

**Owner ruling, 2026-08-16 (spec D3a).** Editing a Fresha-synced service used to
"detach" it from the sync (`site.services.is_manual`), and the public blob then
served the owner's own `price` / `priceValue` / `name` / `duration` /
`description` instead of the vendor's. **The public blob now always serves the
vendor's numbers.**

Why it could not be carried across: an owner's edited PRICE has no
representation in `content.*`. `content.offers` is a set-union COLLECTION, and
`FacetRegistry` excludes collections from `content.manual_overrides` by design
("no single value to override"). Building an offers override lane was
considered and rejected — see D3a.

Why it is safe: measured on dev before the ruling, all 61 live
`site.services WHERE source = 'fresha'` rows carry `is_manual = false`, and none
carries a non-null non-zero `price_cents`. The feature was never used.

**This also CLOSES a live divergence rather than opening one.** Since 3b the
dashboard (`FreshaSelectionResource`) has read `content.*` and so already
ignored these edits, while the public blob honoured them — an owner who edited a
price saw the old price on their dashboard and the new one on their public page.
The two surfaces now agree.

**Unaffected:** `title` / `description` / `duration` overrides. Those are
singleton facets and keep working through `content.manual_overrides`
(`PUT /api/content/items/{item}/overrides`), and `POST /api/services/resync`'s
content half still reverts them.

### 2. `services[]` is the fixed nine-key shape on the public wire too

**Before.** A synced service contributed its raw scrape entry **verbatim** —
whatever keys Fresha emitted the day it was scraped, in Fresha's own key order,
with the vendor's own display strings (`"A$65"`, `currency: "AUD"`).

**After.** Each entry is `FreshaServiceItems`' reproduction: exactly these nine
keys, in this order —

```
name, price, category, currency, duration, serviceId, priceValue,
description, hasVariants
```

This is the identical change slice 3b already made to the dashboard resource
(see that manifest, §1), now reaching the public blob. The same round-trip rules
apply: `price` is reconstructed from `qualifier` + `amount_minor` (`"from $108"`,
`"$120"`, `"free"`), cents render only when non-zero (`4950` → `"$49.50"`), the
`$` stays **bare**, and `currency` is **null** rather than a guessed `"AUD"`.

**A consumer reading a key outside the nine loses it**, and one parsing `price`
must accept a bare `$`.

### 3. An empty booking menu when `content.*` has no rows for the connection

Same intended behaviour 3b documented for the dashboard, now on the public blob:
a connection whose stored blob is populated but which has no `content.*` service
items renders `services: []`. Serving the stored blob instead would serve the
stale prices this whole cutover exists to remove (spec §1.4 measured 22 of 23
understated on one salon, `$360` published as `$180` on another).

**Deploy consequence, and it is a step not an optimisation:**
`ingest.sources.selection_ref` must be synced per Fresha connection before the
first scheduled run matters, exactly as 3b's manifest states. Do not reach for
`ingest:backfill-sources` unqualified.

Spec open question 2 is now load-bearing here: **`anseo-studio`'s Fresha
connection has no ingest source** (`SourceProvisioner::freshaSlug()` matches only
`fresha.com/…/a/<slug>` and that row is a `book-now/…?pId=` URL). Until that is
widened or the row written off, that connection's public booking menu composes
empty.

### 4. `hiddenServiceIds` moved onto the blob

Unchanged on the wire — still a sibling key of `services[]`, still carrying ids
that also appear in `services[]` (the dashboard needs them present to render the
un-hide affordance).

What changed is where it is **stored**. It used to be derived from
`site.services.is_active`, which `compose()` read back out. `content.*` has no
`is_active` — a pool EXCLUDE is the owner-authored equivalent and does not apply
to the connection lane — so the list now lives on the stored blob itself, which
is where `FreshaSelectionResource` already read it from.
`POST /api/platforms/fresha/service-visibility` writes it there and nowhere
else; `compose()` only PRUNES it to ids still live in `content.*`, so hiding a
service and then deleting it leaves no dangling id.

### 5. Menu ORDER still comes from the stored scrape

`payload.raw.services` stays private and stays stored. Its remaining jobs are
the vendor's own menu order — an entry listed there keeps that position — and
`revert()`'s source for the legacy rows. A service live in `content.*` but not
yet in the stored scrape is **appended** rather than hidden until the next
refresh.

### Not changed

- `url`, `storeName`, `mode`, `employee` — owner/vendor facts, untouched.
- `PublicIntegrationConnectionResource`'s `'fresha' => ['url', 'selection']`
  allowlist.
- Suppression and departure semantics. An owner deletion is
  `content.items.removed_at` (which `ProjectionWriter` never clears, so a later
  scrape cannot resurrect it); a service leaving Fresha is
  `content.source_items.removed_at` (which IS cleared on reappearance, so it
  restores when it returns). Never the reverse — recording an owner deletion on
  `source_items` would resurrect it.
- First-occurrence dedup for a `serviceId` Fresha lists under several
  categories.
- The two-surface rule: a Fresha service still never reaches the public services
  section, and an owner-authored service still never reaches the booking
  surface. Pinned by `tests/Feature/Content/ServiceTwoSurfaceTest.php` and again
  by this task's own `FreshaSelectionFromContentTest`.

### Advisory-lock TTLs narrowed (not deleted)

`FreshaController::connect()` (both locks), `saveSelection()` (both locks) and
`FreshaConnectFetch::fetchStorewide()`'s raw `Cache::lock` were each raised to
**30s** because they held a lock across `sync()`'s Postgres transaction and its
inner `pg_advisory_xact_lock('services:{user_id}')`. Those writes are gone —
`sync()` is a `content.*` read — so all five are back on the **10s** default
every other platform caller uses. Every lock itself is unchanged and still
mandatory: they are what re-assert the Square booking XOR and keep
`ConnectFetchJob` / `ScheduledRefresh` / `saveSelection` /
`setServiceVisibility` from interleaving (PWL-5).

`forget()`'s 30s is **untouched** — it is justified by its own per-row
`Service::delete()` teardown loop, not by the projection.

### Not dropped

`site.services` survives this task. `FreshaServiceProjector::revert()` is the
one method here that still reads it, serving
`UserServiceController::resync()`/`resyncBulk()`'s §C2 legacy branch; both call
sites short-circuit before reaching it for every live row. It goes with the
table in phase 6.
