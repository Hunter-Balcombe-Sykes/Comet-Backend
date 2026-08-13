# Wire change — slice 3a, owner-authored services (2026-08-13)

Backend-only execution. Spec: `docs/superpowers/specs/2026-08-12-slice-3a-services-owner-authored-design.md`.
21 owner-authored services (18 live, 3 soft-deleted, 3 users) move from
`site.services` (`source IS NULL`) into `content.*`. The 61 Fresha-scraped
services (`source = 'fresha'`) are **not** migrated and still come from
`site.services` — that half is slice 3b's job.

> **STATUS: local verification only, this document.** The dev-database half
> of Task 7 (running `content:backfill-owner-services` against dev and pasting
> the live SQL assertions) is being run separately by the repo owner, not from
> this session. Nothing below claims dev has been backfilled.

## Headline: every shape is UNCHANGED — only the backing store moved

**Request and response shapes did not change on any of the 8 endpoints, the
public profile payload, or the DSAR export.** That was the acceptance
criterion for the slice (spec §6), pinned by
`tests/Feature/Api/User/ServiceEndpointCutoverTest.php`'s
`'keeps the response shape unchanged'` cases and by
`ServiceResource`'s unchanged field list. Consuming repos need change
nothing to keep working — this manifest exists so they know what moved
underneath them, not because there is anything to update.

## Endpoints — consuming repo and what changed

**Consuming repo for all 8: Partna-App** (authenticated dashboard).
`ServicePolicy` continues to authorise every one; `ContentItemPolicy` is
kind-agnostic and covers the new `content.items` rows.

| Endpoint | Before | After |
|---|---|---|
| `GET /api/services` | list from `site.services` | same shape — **now a MERGED list**, see below |
| `POST /api/services` | insert into `site.services` | write through the manual lane (`ManualServiceWriter`); pinned into `content.section_items` on create |
| `GET /api/services/{service}` | read `site.services` | read `content.*` via `ManualServiceItems` |
| `PATCH /api/services/{service}` | update `site.services` | write through the manual lane |
| `DELETE /api/services/{service}` | soft-delete `site.services` | sets `content.items.removed_at` |
| `POST /api/services/reorder` | write `site.services.sort_order` | write `content.section_items.sort_key` |
| `POST /api/services/reorder-layout` | write `site.services.sort_order` + category membership | write `content.section_items.sort_key`; category membership unaffected (owner half has none) |
| `POST /api/services/{service}/restore` | clear `site.services.deleted_at` | clear `content.items.removed_at` — see "restore vs. the one-way rule" below |

### `GET /services` returns a MERGED list — the one behavioural note

The dashboard list is owner-authored services from `content.*` plus
Fresha services from `site.services`, concatenated and ordered by a single
shared `sort_order` scale (`content.section_items.sort_key` and
`site.services.sort_order` share one numeric space via
`services_user_sort_order_uq` — `UNIQUE(user_id, sort_order) WHERE deleted_at
IS NULL`, global per user, not scoped by source). A consumer reading the list
cannot tell which half a row came from except by the existing `source` field
(`null` = owner-authored, `'fresha'` = scraped) — that field's meaning is
unchanged.

### Deferred to 3b, unchanged in 3a

`POST /services/resync`, `POST /services/{service}/resync`,
`PATCH /services/{service}/category`, and all six
`/service-categories/*` routes stay on `site.services` untouched — every
live category (18 of 18) belongs to Fresha, so 3a's owner-authored cutover
has no category work to do.

## Public surface

| Surface | Consuming repo | Status |
|---|---|---|
| `GET /api/public/profiles/{handle}` — services section | partna-monorepo (`@partnaau/design-system`) | **shape unchanged.** `SitepageDataResolverService::buildServicesData()` now reads live `content.items` of kind `service` (via the new `services` pool) instead of `site.services WHERE source IS NULL`. Price, duration and the owner's `sort_order` all still render — durations and prices are reconstructed from `content.offers` / `content.f_duration` rather than read off `site.services` columns directly. |
| Booking visibility (`BookingVisibility`) | — | **semantics unchanged.** Gate stays "at least one active manual service", re-expressed against `content.*`. |
| Section visibility (`ServicesVisibility`) | — | **semantics unchanged**, re-expressed against `content.*`. |

**A Fresha-sourced service never appears in the public services section** —
pinned by the two-surface regression test the parent spec mandates. The
services `content.*` pool only carries the manual/owner source; Fresha stays
on its existing composed booking blob until 3b.

## DSAR export

`DataExportPayloadBuilder` keeps its existing `services` and
`service_categories` section keys unchanged (2026-08-05 precedent: DSAR
allowlists retain legacy keys so previously-stored payloads stay
disclosable).

- **Owner-authored rows** now stream from `content.*` via
  `ManualServiceItems::exportRows()` — reading `site.services` for them post-
  cutover would export pre-edit values forever after the first dashboard
  edit, since the manual write lane no longer writes back to the legacy
  table.
- **The exported `id` for a backfilled owner row is the ORIGINAL
  `site.services.id`**, not the new `content.items.id`. `ServiceBackfiller`'s
  coord is `manual:{site.services.id}`, and `exportRows()` recovers it by
  parsing that coord and cross-checking the candidate uuid against a real
  `site.services` row for the same user (coord shape alone is ambiguous —
  `store()` mints syntactically identical `manual:{uuid}` coords for
  brand-new items with no legacy row at all). A DSAR is the one artifact
  where identifier continuity across the cutover matters: a subject who
  exports before and after the cutover sees the SAME service under the SAME
  id. A post-cutover `create` has no legacy id to recover and falls back
  honestly to the `content.items.id`.
- **Fresha rows (source = `'fresha'`) are unchanged** — still streamed
  straight off `site.services`, 3b's problem.

## `restore` and the one-way removal rule

`restore` clears `content.items.removed_at` — and that does **not** weaken
the rule that a connector projection must never resurrect an item its owner
deleted (`ProjectionWriter` never clears `removed_at` on reappearance). The
rule protects against a *sync* silently undoing a deletion; `restore` is a
deliberate human act on the endpoint. Pinned by test: restore clears it, and
a subsequent projection run afterwards does not.

## Deliberate non-change: `PurgeSoftDeleted.php:107`

`app/Console/Commands/PurgeSoftDeleted.php:107` still reads `site.services`
and purges legacy rows there on the normal 30-day soft-delete schedule. This
is **deliberate, not a gap** — `site.services` remains the Fresha half's
store until slice 7 drops the table, and Fresha's soft-deleted rows
(`deleted_origin = 'sync'`) must keep expiring on their existing schedule.
Do not "finish the job" by repointing this command at `content.*` — the
owner-authored half's removal lifecycle is governed entirely by
`content.items.removed_at`, which has no analogous purge command in this
slice.

## New artisan command

```
content:backfill-owner-services {--dry-run} {--user=}
```

`app/Console/Commands/BackfillOwnerServices.php`, backed by
`app/Services/Migration/ServiceBackfiller.php`. Idempotent on the coord
(`manual:{legacy_uuid}`) — a re-run upserts rather than duplicating. Scope:
`site.services WHERE source IS NULL` (21 rows on dev, measured 2026-08-12).
Writes through the slice-0b manual lane (`ProjectionWriter::writeManualItem()`),
never raw. `--user` limits the run to one user id; `--dry-run` reports counts
without writing.

## Not in this slice — carried to 3b

- The connector fix, the storewide-vs-employee-menu reversal, and everything
  Fresha-shaped: `/services/resync`, `/services/{service}/resync`,
  `/services/{service}/category`, all six `/service-categories/*` routes.
- `service_categories` → `content.collections`, `service_category_assignments`
  → `content.collection_items` (16 live categories, 61 assignments, all
  Fresha).
- `StaffServiceManagementController` — the parallel staff-surface controller
  with no `source` filtering on any of its nine actions. Same defect class
  3a exists to fix, found during review, deferred deliberately (spec §7).
- `deleted_origin` is not carried into `content.*` — its semantics are
  already expressed there (`items.removed_at` for `'user'`, absence of a live
  `source_item` for `'sync'`). See spec §3.7.
- `offers.availability` stays NULL for services — an unbookable service is a
  pool exclude, not a third availability spelling. See spec §3.8.

## Not dropped

`site.services`, `site.service_categories` and
`site.service_category_assignments` are **not** dropped by this slice — that
is slice 7. Both the Fresha half's reads/writes and `PurgeSoftDeleted`'s
purge of legacy rows continue against the live table.
