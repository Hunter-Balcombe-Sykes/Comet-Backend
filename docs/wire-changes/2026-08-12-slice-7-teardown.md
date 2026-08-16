# Wire changes — slice 7, legacy teardown (2026-08-12)

Spec: `docs/superpowers/specs/2026-08-12-slice-7-teardown-design.md`.
Plan: `docs/superpowers/plans/2026-08-12-slice-7-teardown.md`.

One file per slice; each unit that changes something a consumer can see appends
a section here.

---

## Unit E — `site.content_selection` retired (phase 4, task 16)

**Owner ruling 2026-08-14 (binding): these keys are DELETED OUTRIGHT, not
dual-served.** `apps/pages` reads of them break **by design**. The pages app is
being rebuilt, not repaired, so there is no compatibility shim, no empty-array
placeholder and no transition alias. The replacement curation lane is
`pool:media` pins, which already exists and already ships in this same payload
under `profile.pools.media`.

### Keys that died

`GET /api/public/profiles/{handle}` — response `data`:

| Key | Was | Now |
|-----|-----|-----|
| `designMedia` (top level) | `DesignMediaItem[]` — the owner's resolved Content Selection (uploads / Google photos / Instagram reel+post), ordered by position | **absent** |
| `siteImages` (top level) | `{ logoFull?, logoSquare?, placeholder? }`, each `{url, urlHd, urlSvg, urlIcon}`; `{}` when nothing set | **absent** |
| `profile.gallery` | `GalleryImage[]` from the `site.site_media` gallery pool | **absent** |
| `profile.curatedGallery` | the same Content Selection projection as `designMedia`, in the resolver's row shape | **absent** |

`curatedGallery` is not named in the spec's key list but could not survive it:
it was the second consumer of `ContentSelectionService::resolve()`, which is
deleted with the table's other code.

Everything else on the payload is unchanged. `profile.pools` (including
`pools.media`), `designKit`, `architectureId`, `publicConfig`, `pageOrder`,
`popularity`, `rankedActions`, `ordering` and `policies` all keep their shapes.

### Owner routes that died

All four are gone from `routes/api/user.php`; the URLs now 404.

- `GET /api/content/selection`
- `PUT /api/content/selection`
- `PUT /api/content/instagram-auto`
- `PUT /api/content/google-photos`

**Kept:** `GET /api/content/library`, `POST /api/content/uploads`,
`DELETE /api/content/uploads/{upload}`. The library is still the browse surface
(content-pool uploads + referenced Google Business photos + referenced Instagram
post images); only the ordered *selection* on top of it retired.

The `content_photos` flag on the Google Business connection's `display_settings`
lost its **write** verb with `PUT /api/content/google-photos`. The library still
**honours** an already-stored `content_photos: false` (google photos stay out of
`GET /api/content/library`), so no owner's existing opt-out silently reverses.

### Code deleted

- `app/Services/Site/ContentSelectionService.php`
- `app/Models/Core/Site/ContentSelection.php`
- `app/Policies/ContentSelectionPolicy.php` (+ its `Gate::policy` registration)
- `app/Http/Requests/Api/User/Content/{ReplaceContentSelection,SetContentInstagramAuto,SetContentGooglePhotos}Request.php`
- `ContentController::{selection,replaceSelection,setInstagramAuto,setGooglePhotos}`
- `IntegrationConnectionObserver::{seedContentFromGoogle,reconcileContentInstagramSlots}`
  — a Google Business connect no longer seeds picks, and an Instagram connect no
  longer reserves reel/post slots. `enableContentInstagramAuto` (the pool
  auto-sync flag) is untouched and still fires.
- `SitepageDataResolverService::buildCuratedGalleryData` and its
  `curated_gallery_resolve` presence probe.
- `IndividualProfilePayloadBuilder::{buildGallery,buildDesignMedia,buildSiteImages}`.

### Data

Not dropped here. `site.content_selection` (95 rows on dev) still exists —
phase 6 owns the DROP migration. Slice 1b already carried the 3 `upload` picks
into `pool:media` pins; the other 92 (85 `google-photo`, 7 `ig-*`) are
deliberately **not** carried — checkpoint §15, not re-litigated.

### Residuals a later unit should know about

- **`pageOrder` can still advertise a `gallery` page.** `presentPageIds()` still
  sets `gallery` presence from ready `site.site_media` gallery-pool rows, but the
  payload no longer carries any gallery data. The presence lane was out of this
  task's scope; the rebuilt pages app either drops the page or the presence probe
  moves to `pool:media`.
- **`SitepageDataResolverService::{getGallery,getDesignSingletons}` are kept**
  with no production consumer. Both read tables that survive slice 7, both stay
  test-covered, and the rebuilt pages app will want a projection to attach to.
- **The k6 harness's gallery invariant lost its wire-level guard.** The
  `gallery-item-needs-a-webp-variant` rule was pinned by the two gallery-engine
  tests in `IndividualProfileControllerTest`, which went with the key. The
  resolver-level `getGallery()` filter is unchanged; nothing asserts it end-to-end
  any more.
