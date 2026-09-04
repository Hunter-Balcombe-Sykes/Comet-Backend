# Legacy Surface Inventory: superseded lanes, duplicate surfaces, and vestigial config still shipping in the backend

Hunt **backend code that belongs to a lane the platform has already replaced** — a second way of doing something the product now does exactly one other way. This is NOT a generic dead-code hunt: a private unused method is out of scope. What matters here is **a surviving parallel lane**, because two lanes for one concept is what confuses the frontend and makes a reader believe a retired feature is still real.

The platform has moved through several large convergences. In each, a new lane became canonical and the old one was *supposed* to go:

| Concept | CANONICAL lane (keep) | SUPERSEDED lane (hunt for survivors) |
|---|---|---|
| Curated content (services, menu items, shop, media, links, events, reviews, watch, listen) | `content.*` tables → `PoolResolver` / `PoolWire` → `data.profile.pools.<pool>` | `site.services`, `site.menu_items`, `site.shop_*`, `site.content_selection` and any reader/writer still shaped around them |
| Public site payload | `GET /api/public/profiles/{handle}` → `IndividualProfileController` → `IndividualProfileResource` | `GET /api/public/site` + `/api/public/site-by-slug` → `PublicSiteController` → `SiteCacheService::buildPayloadFromDb()` → the `site.public_site_payload` DB view |
| Image pools | the single `content` pool (`config('partna.upload_limits')`) | the retired `gallery` pool and its max-6 trigger (dropped 2026-09-02) |
| Routed links | `LinkRouter`/`LinkRoutingService` → `SourceReconciler` → a real brand surface | the retired pseudo-platform lane: `partna.*_link`, `partna.manual_event`, `custom`/`booking`/`reservations`/`online-ordering`/`events-custom` category controllers |
| Design | `site.design_kits` (column-per-var) + `architecture_id` | `site.themes`, `settings.design.*`, `skeleton_id`, theme-picker machinery |
| Account shape | `AccountCapabilities` | direct branching on `account_type` |
| Analytics | live event tables | `analytics.site_metrics_daily`/`_hourly` rollups, `content.source_routes`, `content.item_refs` |

**Deliberate survivors — these are NOT findings, do not report them:**
- `site.menus` and `site.menu_platform_links` survive on purpose (bookkeeping rows with live readers).
- `MenuItem`, `MenuCategory`, `MenuItemPlatform`, `Service`, `ServiceCategory`, `ShopBrand` are **table-less DTO carriers** hydrated unpersisted (`exists = false`) for the dashboard shape. They must stay, and must stay in `PurgeSoftDeleted::PURGE_EXEMPT`.
- `content.f_file` is a live facet, empty only because the `document` kind is poolless.
- `profile.services` (owner-authored) and `pools.services` (union incl. scraped) are **deliberately different surfaces** — not a duplicate.
- `partna.manual_product` is hidden but deliberately NOT retired.
- `AccountType::Individual` is kept for safe legacy casting.
- Anything whose comment or config explicitly says it is kept on purpose, deferred, or transitional.

## Use the lens prefix `LEGACY` for findings

Number them `LEGACY-1`, `LEGACY-2`, … sequentially.

**Tiering.** P2 is the ceiling for pure confusion; P1 only where the duplicate lane can serve *wrong or stale data to a real consumer*, or where two registrations of the same route resolve to different middleware. Never P0.

## Findings categories

### (1) Duplicate public surface — two endpoints for one concept
Two routes, controllers, resources, or services that answer the same product question by different machinery. Name both, say which is canonical, and cite the evidence for which one real clients call. **A route registered twice (same URI+method in two files) is always a finding** — say which registration Laravel actually binds and whether the two carry different middleware.

### (2) Superseded reader/writer still wired
Code that still reads or writes a superseded lane from the table above. Includes: a DB view whose SELECT still projects keys nobody consumes; a service that hand-assembles a payload the Resource layer now owns; a job or command that maintains a store nothing reads.

### (3) Retired-feature residue
Controllers, requests, resources, policies, jobs, commands, middleware, enum cases, model scopes or casts for a feature that was explicitly retired. Cite the retirement (commit, migration, or spec) where you can find it.

### (4) Vestigial configuration
`config/partna.php` keys, `.env.example` vars, feature flags, or registered bindings whose only consumer is gone. Prove the consumer is gone with a grep.

### (5) Naming drift that misleads the frontend
A wire key, column, or class whose name says one thing and whose content is another — `skeleton_id` carrying `architecture_id`, a `gallery` key fed from the `content` pool, a `platform` column carrying a surface key. These are P2 by default because the frontend reads the name, not the migration history.

### (6) Orphaned test scaffolding for a retired lane
Test helpers, stand-in table builders, or fixtures that provision a superseded lane's shape. Only report where the production code they exercised is itself gone.

## Per-finding requirements

- Cite the category number (1–6).
- Quote the offending code verbatim as Evidence with `file:line`.
- **State the exact grep that proves supersession**: the canonical lane's location AND the search showing the old lane has no remaining legitimate consumer. A supersession claim without both greps is not allowed.
- Say explicitly whether removal is **safe now**, **blocked on a frontend confirmation**, or **blocked on prod schema reconciliation** (production lacks the `content`, `ingest`, `routing` and `catalog` schemas entirely — anything whose fix assumes `content.*` exists in prod is blocked).
- Give the fix as a concrete deletion or rename.

## Anti-false-positive directive (adjudicator)

You have `Read`/`Grep`/`Glob` over the repo. The scan tier saw only the scoped files and **cannot** know whether a class is resolved from the container by string, bound in a provider, referenced in `config/`, or reached through a route file it never read. Before confirming any LEGACY finding:

- **Grep the whole repo for the symbol** — including `config/`, `routes/`, `app/Providers/`, and string references. A live reference anywhere means drop it.
- **Check the deliberate-survivors list above.** Re-reporting one of those is the single most likely failure mode of this lens. Drop it.
- **A comment saying a path is legacy is evidence, not a finding by itself** — the finding is that the path is still *wired*. If the comment says it is kept pending a frontend confirmation, report it as blocked, not as safe-to-delete.
- **Never propose deleting a table-less DTO model.**
- **Do not report frontend-repo dead code** — that is `cross-repo-dead-code`.
- **Do not report generic unused private methods or unreachable branches** — that is `code-quality-slop`.

A short, provable inventory beats a long list of "looks old." When the grep is not conclusive, drop it.

## Suggested scope groups

```
--scope app/Services/Cache --scope app/Http/Controllers/Api/PublicSite --scope app/Models/Views
--scope routes
--scope config/partna.php
--scope app/Services/PublicSite --scope app/Site
--scope app/Http/Resources
--scope app/Models/Core/Site
```

## Exhaustiveness directive

Read every file in scope. Legacy lanes hide in a service's protected method, a view-backed model, a route file that duplicates another, and a config key nobody grepped. But never invent a finding to pad the list, and never report a deliberate survivor — an empty category is the correct output when a lane really did get cleaned up.
