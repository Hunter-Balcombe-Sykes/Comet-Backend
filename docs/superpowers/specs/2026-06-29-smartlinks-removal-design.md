# SmartLinks Removal — Design Spec

**Date:** 2026-06-29
**Status:** Approved (design) — pending spec review → implementation plan
**Owner:** Josh

## Context

SmartLinks (the dashboard "Links" page → 7 API endpoints → `site.smart_links` table) is a
URL-paste rich-card feature. The newer **Platform Integrations** system
(`site.platform_connections` + `app/Services/Platforms/`) covers the same content platforms
(Spotify, Apple Music, Bandcamp, Apple Podcasts, YouTube, Vimeo, …) and more, via connect-an-account
feeds. In the dev DB **every `smart_links` row is soft-deleted (0 live)** while
`platform_connections` holds live data — Integrations is the de-facto active surface. Prod is on the
pre-standalone schema and **does not have the `smart_links` table at all**.

Decision: **remove SmartLinks entirely — code and database** — because its capabilities are now served
by Integrations/Connections.

### The one complication

`app/Services/SmartLinks/` is not all SmartLinks. Five classes are **shared HTTP/parsing primitives**
that ~20 Integration scrapers depend on. They live under `SmartLinks/` only for historical reasons and
**must survive** the removal.

## Goals

- Delete all SmartLinks **feature** code (resolver/refresher/validator/registry/image/visitor-url,
  extractors, model, observer, controller, requests, resource, refresh command + cron, public-payload
  section).
- Drop the `site.smart_links` table and its dedicated DB objects.
- **Preserve** the shared primitives by relocating them to a neutral namespace, with zero behaviour
  change to Integrations.
- Zero downtime for the live dashboard and public sitepages during the transition.

## Non-goals

- No change to any Integrations/Platforms behaviour.
- No data migration (all SmartLinks rows are already soft-deleted; dev-only).
- Frontend code is **documented**, not executed here (separate repo, read-only from backend sessions).

## Locked decisions

1. **Shared primitives** → relocate to a new neutral namespace **`App\Services\Http`**.
2. **Database** → **hard drop**, no archival.
3. **Cutover** → **backend-first, tolerant window** (backend ships removal behind empty-response shims so
   FE deploy order is irrelevant; shims deleted in a later backend phase).

## Inventory (verified 2026-06-29)

### KEEP — relocate to `App\Services\Http` (5 classes, self-contained, no delete-set coupling)

| Current | New |
|---|---|
| `app/Services/SmartLinks/SafeUrlFetcher.php` | `app/Services/Http/SafeUrlFetcher.php` |
| `app/Services/SmartLinks/SafeUrlException.php` | `app/Services/Http/SafeUrlException.php` |
| `app/Services/SmartLinks/MetadataParser.php` | `app/Services/Http/MetadataParser.php` |
| `app/Services/SmartLinks/ParsedUrl.php` | `app/Services/Http/ParsedUrl.php` |
| `app/Services/SmartLinks/ParsedMetadata.php` | `app/Services/Http/ParsedMetadata.php` |

Test: `tests/Unit/SmartLinks/SafeUrlFetcherTest.php` → `tests/Unit/Http/SafeUrlFetcherTest.php`.

**Importers to update** (`use App\Services\Http\…`): the Platforms services that import `SafeUrlFetcher`
(20 importers) / `MetadataParser` — `AppleSearch`, `BandcampScraper`, `BigCartelScraper`, `DeezerApi`,
`EventbriteScraper`, `GenericShopScraper`, `GoogleBusinessService`, `HumanitixScraper`, `LinkCardScraper`,
`OEmbedService`, `PinterestScraper`, `ShopifyScraper`, `SkoolScraper`, `SquarespaceScraper`,
`StravaClubScraper`, `TwitchScraper`, `VimeoApi`, `WooCommerceScraper`, `YoutubeScraper` — plus
`app/Http/Controllers/Api/Platforms/FreshaController.php` and any `tests/Unit/Platforms/*` /
`tests/Feature/Platforms/*` test that imports a moved class. The relocation is a **pure path swap**: no
container bindings or config FQCN references exist (all autowired); the 5 classes reference only each
other and move as a unit.

### DELETE — SmartLinks feature code

**Services** (`app/Services/SmartLinks/`): `SmartLinkResolver`, `SmartLinkRefresher`,
`SmartLinkValidator`, `SmartLinkTypeRegistry`, `SmartLinkImageService`, `SmartLinkVisitorUrl`,
`ResolvedSmartLink`, `ResolvedSmartLinkData`, `UrlNormalizer`, and the entire `Extractors/` directory
(`SmartLinkExtractor`, `ITunesExtractor`, `OEmbedExtractor`, `ShopifyExtractor`, `SpotifyExtractor`,
`StructuredDataExtractor`, `Concerns/ExtractorHelpers`).
→ After relocate + delete, `app/Services/SmartLinks/` is empty → remove the directory.

**Model / observer:**
- `app/Models/Core/Site/SmartLink.php`
- `app/Observers/Core/SmartLinkObserver.php`
- `app/Providers/EventServiceProvider.php` — remove the `use` lines (SmartLink, SmartLinkObserver) and the
  `SmartLink::observe(SmartLinkObserver::class);` registration.

**HTTP:**
- `app/Http/Resources/SmartLinkResource.php`
- `app/Http/Requests/Api/User/Site/{PreviewSmartLinkRequest, ReorderSmartLinksRequest,
  StoreSmartLinkRequest, UpdateSmartLinkRequest}.php`
- `UserSmartLinkController` — reduced to a dependency-free **tolerant shim** (see Phase 1·b); all
  SmartLinks imports + the constructor (`SmartLinkResolver`, `SmartLinkImageService`,
  `SmartLinkRefresher`) removed; method params drop the deleted `SmartLink` model binding (use string id).

**Console:**
- `app/Console/Commands/RefreshSmartLinksCommand.php`
- `routes/console.php` — remove the `Schedule::command('smartlinks:refresh')…` block (incl. its comment).

**Authorization:** none required — `SitePolicy` has **no** smart-link methods; the controller authorizes
against the **site**, so deleting the controller is sufficient. (`config/partna.php` likewise has **no**
smart-link keys — the `smart_*` entries are `smart_booking`, unrelated.)

### MODIFY — public payload

`app/Services/PublicSite/IndividualProfilePayloadBuilder.php`:
- Replace `'smart_links' => $this->buildSmartLinks($site)` with the constant `'smart_links' => []`
  (keep the key so partna-pages + dashboard don't break on a missing field during the window).
- Delete `buildSmartLinks()`, `shapeSmartLink()`, `smartLinkPrice()` and the `SmartLink` /
  `SmartLinkVisitorUrl` imports.

### Tests

- **Delete:** `tests/Unit/SmartLinks/{SmartLinkTypeRegistryTest, UrlNormalizerTest,
  SmartLinkVisitorUrlTest, SmartLinkValidatorTest}.php`, `tests/Feature/SmartLinks/SmartLinkResolverTest.php`,
  `tests/Feature/Observers/SmartLinkObserverBustTest.php`. (Empty `SmartLinks/` test dirs → remove.)
- **Relocate:** `SafeUrlFetcherTest` → `tests/Unit/Http/`.
- **Modify** `tests/Pest.php`: remove the `CREATE TABLE IF NOT EXISTS site.smart_links (…)` block (≈ line
  414) — the SQLite stand-in for the dropped table.

### Database

New forward migration `supabase/migrations/20260629000000_drop_smart_links.sql`:

```sql
-- Remove the SmartLinks feature (superseded by Platform Integrations / platform_connections).
-- CASCADE drops the dedicated trigger (set_timestamp_smart_links), RLS policy
-- (smart_links_app_backend_all), indexes, and inline FK/CHECK constraints.
-- The shared public.set_updated_at() function is intentionally NOT dropped — site.platform_connections
-- (and others) reuse it. IF EXISTS makes this a safe no-op on prod (which lacks the table).
DROP TABLE IF EXISTS site.smart_links CASCADE;
```

Apply to **dev** (`glncumufgaqcmqhzwrxm`) via `supabase db push --dry-run` then `supabase db push`. The
historical create/harden migrations stay untouched (immutable history); `20260624010000` only *mentions*
smart_links in a comment, so it is unaffected.

## Phased execution

### Phase 1 — Backend removal (this plan executes), branch off `development`
1. **Relocate** the 5 primitives → `App\Services\Http`; update all importers + relocate `SafeUrlFetcherTest`.
2. **Neutralize reads:** public payload → `smart_links: []`; gut `UserSmartLinkController` to a tolerant
   shim whose 7 methods return empty success shapes matching the FE's expected types, with no dependency
   on deleted code or the table:
   - `GET /smart-links` → `200 { "smart_links": [] }`
   - `POST /smart-links/preview` → `200 { "smart_link": null }` (neutral empty preview)
   - `store` / `update` / `reorder` / `refresh` → `200 { "smart_link": null }`
   - `destroy` → `204 No Content`
3. **Delete** all feature code, model, observer (+ EventServiceProvider line), requests, resource,
   command + cron, and the SmartLinks test files; remove the `tests/Pest.php` table seed.
4. **Drop** the table (migration above; apply to dev).
5. **Verify** (see Testing).

### Phase 2 — Backend final cleanup (follow-up, after FE ships)
Once the FE no longer calls `/smart-links` nor reads the `smart_links` payload key: delete the shim
controller, the 7 routes (`routes/api/user.php` lines ~152–162 + the import), and the `smart_links`
payload key entirely.

### Frontend (separate repo — documented, NOT executed here)
Remove `app/(app)/account/(dashboard)/links/` (page + `smart-link-card`, `smart-link-editor-modal`,
`smart-links-section`, `use-smart-links`, `smart-links.module.css`), `lib/smart-links/`
(`api.ts`, `types.ts`), the `lib/routes.ts` entry + nav item, and any `smart_links` rendering in
public/profile components. (Optional: drop the "distinct from Smart Links" comment in
`integrations/custom-links-section.tsx`.)

### Deploy ordering
1. **BE Phase 1** (push `development`; apply dev migration) — safe anytime; shims keep FE working.
2. **FE removal** deploy.
3. **BE Phase 2** — remove shims/routes/payload key.

## Testing

- `composer test` green after Phase 1 (full suite, run in the main checkout — not a worktree).
- Relocated `SafeUrlFetcherTest` passes under `tests/Unit/Http`.
- New: tolerant-endpoint test — `GET /smart-links` returns `{ smart_links: [] }`; `store`/`preview`/
  `refresh` return `200`, never `500`.
- Public-payload test asserts `smart_links === []`.
- `php artisan pint`; `composer guard:no-laravel-migrations`; `PolicyCoverageTest` (ensure no dangling
  allowlist/registration references the removed `SmartLink` model); AccountCapabilities sweep.

## Risks & verification

- **Lingering references:** after relocation, grep `App\Services\SmartLinks` across `app/`, `tests/`,
  `routes/`, `config/` — must be **zero** (dir removed). Also grep for bare `SmartLink` model usages.
- **Test-schema drift:** removing the `tests/Pest.php` `smart_links` seed must not break another test
  (none currently depends on it beyond the deleted SmartLinks tests).
- **Public sitepage render:** partna-pages maps over `smart_links`; emitting `[]` (not removing the key)
  during the window prevents a render break. The key is only removed in Phase 2, after FE is updated.
- **Route-model binding:** the `{smartLink}` params use a `whereUuid` regex constraint, not model
  binding — the shim must take a string id (the `SmartLink` model is gone).
- **Prod safety:** prod lacks the table; `DROP TABLE IF EXISTS` is a no-op there.
