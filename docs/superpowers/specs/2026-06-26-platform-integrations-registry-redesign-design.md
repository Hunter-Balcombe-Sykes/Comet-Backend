# Platform Integrations — Registry & Strategy Redesign

**Date:** 2026-06-26
**Status:** Design approved — pending spec review → implementation plan
**Scope:** Backend (`Comet-Backend` / Partna). No frontend changes (API contract frozen).
**Supersedes context:** builds on `2026-06-09-platform-connection-authz-and-form-requests-design.md` (authz/Form Requests already landed).

---

## 1. Problem

Platform integrations (Instagram, Fresha, Shopify/Shop, YouTube, Spotify, ~30 total) were added one-at-a-time without a shared spine. The result is **high coupling, low cohesion**:

- **"A platform" is defined in ~6 places.** Adding one touches: the DB `CHECK` constraint, a controller, a Form Request, a Resource, a scraper/API service, and a `match()` arm in `PlatformRefresher`. One conceptual change sprays across many files — classic **shotgun surgery**.
- **Untyped JSON-blob access.** `site.platform_connections.payload` is `jsonb`, cast to generic `array`, and read via ~29 scattered `data_get($row->payload, 'key')` calls, each re-checking `is_array()` by hand, each Resource re-allowlisting the same fields. The payload's shape is implicit and undocumented, varying per platform.
- **A central hard-coded dispatcher.** `PlatformRefresher` is a `match()` over platform keys — the one truly tight coupling point; adding a refreshable platform means editing it.
- **An ever-growing CHECK constraint.** Six migrations so far, each appending platform strings — schema churn for what is really application-level config.

### What is already sound (and stays)
This is **not** a greenfield rewrite. The existing soft-contract layer is kept:
- Single `site.platform_connections` table, one row per connection, keyed `(user_id, platform, resource_id)`.
- `ManagesIntegrationConnection` trait (CRUD, per-user Redis locks, multi-account helpers).
- `SingleSelectionPlatformController` base class (17 simple platforms inherit it).
- `IntegrationConnection` model + observer (cache purge on write).

`jsonb` storage is the **right** call for heterogeneous, render-only, third-party display snapshots — forcing 30 platforms into typed columns would couple us to upstream schemas we don't control. The problem is not *where* the data lives; it is the **lack of a typed boundary in the application layer** and the **lack of a single definition of what a platform is**.

---

## 2. Goals & non-goals

### Goals
1. **One declaration per platform.** Adding platform #31 is a single descriptor in one file — no migration, no `match()` edit, no scattered changes.
2. **Typed payload boundary.** Replace blob `data_get` access with per-archetype DTOs; tolerance for upstream/old-snapshot drift lives in one place.
3. **Kill the central `match()`.** The refresher and detector read the registry; they stop knowing about individual platforms.
4. **Durable, additive extension.** The predictable futures (new platform, new category, paid-tier gating, an eventual OAuth/webhook platform) are additive — implement one interface, slot it in — not structural rewrites.
5. **Zero observable change.** Every route and JSON response stays byte-identical; the frontend is untouched.

### Non-goals (YAGNI — explicitly out)
- **No OAuth / API-key / webhook subsystems built.** Their strategy interfaces are *defined* (seams) but have no implementation and no `platform_tokens` table until a real platform needs them.
- **No API-surface reshaping.** The messy bits (dual `/integrations` + `/platforms` route registration, bespoke per-platform routes) are left alone — that is cross-repo work for a later, optional project. The registry makes it trivial later.
- **No storage model change** beyond dropping one CHECK constraint. Single table + `jsonb` stays.
- **No per-platform tables.**

---

## 3. Locked decisions

| # | Decision | Choice | Rationale |
|---|----------|--------|-----------|
| 1 | Abstraction depth | **Spine + strategy seams** | Same architecture as a "full framework," minus the speculative OAuth/webhook subsystems that have no consumer to test against. The OAuth future is additive in *both* — so the framework buys nothing structural, only earlier (likely-wrong) code. |
| 2 | Migration scope | **All ~30 migrated** | One pattern in the codebase, no drift. The ~17 thin per-platform controllers are deleted (folded into the generic registry-driven controller); the 3 bespoke ones (Instagram/Fresha/Shop) are *retained* but join the registry for their identity. Done incrementally, but the end state is total. |
| 3 | API contract | **Frozen byte-for-byte** | Pure backend-internal refactor; golden-master test guards it; ships with zero cross-repo coordination. Change one variable at a time — don't bolt a contract change onto a large internal rewrite. |
| 3a | Platform declaration mechanism | **Typed descriptors + archetype presets (Approach C)** | Single-file overview *and* type-safety/behavior; the 3 bespoke platforms stay in the same registry as the 27 simple ones — no "two mechanisms" split. |
| 4a | `payload` validation | **Drop the DB CHECK; registry is the gate** | The CHECK was per-platform schema churn. Registry-driven app validation + a coverage test deliver "add a platform = zero migration." The lone schema change in the project. |
| 4b | Payload DTO mechanism | **Plain PHP `readonly` DTOs** | No new dependency. (`spatie/laravel-data` reserved for if/when validation + TS-generation is wanted.) |
| 4c | Capability gating | **Baked in now (`availableFor(User)`), default all-enabled** | Per CLAUDE.md's `AccountCapabilities` mandate. Central checkpoints exist from day one; future paid-tier gating = set a flag on a descriptor, not 30 ad-hoc `if`s. |

---

## 4. Architecture overview

```
PlatformRegistry  ──lists──▶  PlatformDescriptor (one per platform, ×30)
                                   │
   ┌───────────────────────────────┼───────────────────────────────┐
   │ key, label, category          │ availableFor(User)  (capability gate)
   ▼                               ▼
 ConnectStrategy   FetchStrategy   RefreshStrategy   Payload DTO + Resource
   UrlConnect        NoFetch         NoRefresh          LinkPayload   …
  (OAuthConnect*)    OEmbedFetch     OnDemandRefresh    EmbedPayload  …
  (ApiKeyConnect*)   <perPlatform>   ScheduledRefresh   FeedPayload   …
                                    (WebhookRefresh*)   SelectionPayload …
                                                        ShopPayload

   * = seam interface only, NOT implemented (no consumer yet)

Consumers read the registry, never a hard-coded platform list:
  - Generic controllers     → look up descriptor by route param
  - PlatformRefresher        → foreach ($registry->refreshable() …) call its fetch
  - ProviderDetector         → registry categories + descriptor->detect(url)
  - Validation               → platform ∈ $registry->keys()
```

---

## 5. The registry & descriptors (Approach C)

A `PlatformRegistry` (built in a service provider) lists all platforms as `PlatformDescriptor` value objects. Simple platforms use a one-line preset; bespoke ones construct a full descriptor:

```php
// preset — the 6 link-only platforms, one line each
PlatformDescriptor::linkOnly('linkedin', 'LinkedIn', LinkConnectionResource::class),

// preset — the 3 oEmbed platforms
PlatformDescriptor::oEmbed('spotify', 'Spotify', SpotifyConnectionResource::class),

// bespoke — same registry, carries its own strategies
PlatformDescriptor::make('fresha')
    ->label('Fresha')->category(Category::Booking)
    ->connect(UrlConnect::class)->fetch(FreshaFetch::class)
    ->refresh(OnDemandRefresh::class)
    ->payload(SelectionPayload::class)->resource(FreshaSelectionResource::class),
```

**Why C over a class-per-platform (A) or a config array (B):** the deciding factor is the bespoke three (Fresha picker, Instagram async job, Shop multi-brand). Any mechanism that handles them differently from the 27 simple ones recreates the fragmentation in a new shape. C makes *every* platform answer the same questions in the same list — the only difference is how many lines its descriptor needs.

---

## 6. The four strategy axes

Most strategies are **generic and shared**; only genuinely platform-specific fetch logic (the existing scrapers) stays per-platform, now behind one interface.

### ① ConnectStrategy
- **`UrlConnect`** — paste a public URL/handle, validate, store. **The only one built — covers all 30.** Instagram's async job, Fresha's picker, and Shop's multi-brand are *post-connect* behavior (fetch/flow), not separate connect strategies.
- **Seam-only:** `OAuthConnect`, `ApiKeyConnect`.

### ② FetchStrategy — `fetch(IntegrationConnection): array`
- **`NoFetch`** — null-object for link-only platforms (store URL, fetch nothing).
- **`OEmbedFetch`** — shared impl wrapping today's `OEmbedService`.
- **Per-platform fetchers** — today's `InstagramScraper`, `YoutubeScraper`, `VimeoApi`, `EventbriteScraper`, etc., *adapted to implement `FetchStrategy`*. Scrape logic is not rewritten — just placed behind the interface (which also makes it mockable in tests).

### ③ RefreshStrategy — composes with fetch
- **`NoRefresh`** — static.
- **`OnDemandRefresh`** — manual endpoint + cooldown (today's `RefreshController`).
- **`ScheduledRefresh`** — daily cron (today's `PlatformRefresher`).
- **Seam-only:** `WebhookRefresh`.

`ScheduledRefresh`/`OnDemandRefresh` are generic — they call `descriptor.fetch()` and write the result back. **This is what kills the `PlatformRefresher` `match()`:** the refresher becomes `foreach ($registry->refreshable() as $p) { $p->refresh()->run($p); }` and never needs editing for a new platform. Highest-leverage decoupling in the redesign.

### ④ Payload + Resource
Typed snapshot shape + its JSON serializer. See §7.

### Cross-cutting capabilities (composed only where used)
- **`Budget`** — Instagram's Apify daily cap (today's `InstagramApifyBudget`).
- **`MediaMirror` + cleanup hook** — Instagram's R2 rehost + `_folder` + disconnect observer.
- **`Detection`** — `detect(url)` for smart-detect categories (booking / reservations / events / online-ordering).

---

## 7. Archetype → strategy mapping

Five primary archetypes cover the bulk; a handful of specials are just descriptors with a custom strategy mix (same machinery). Exact per-platform archetype assignment for edge platforms (e.g. `tidal`, `mixcloud`) is finalized during implementation — each is a one-line descriptor regardless.

| Archetype | Platforms | Fetch | Refresh | Payload DTO |
|-----------|-----------|-------|---------|-------------|
| **Link-only** | linkedin, x, threads, reddit, tiktok, facebook, skool, strava, custom-links | `NoFetch` | `NoRefresh` | `LinkPayload` |
| **oEmbed** | spotify, soundcloud, deezer | `OEmbedFetch` | `OnDemand` | `EmbedPayload` |
| **Scraped/API feed** | youtube, youtube-music, vimeo, twitch, instagram, pinterest, bandcamp, eventbrite, humanitix, apple-music, apple-podcast, google-business | per-platform | `Scheduled`/`OnDemand` | `FeedPayload` |
| **Picker** | fresha, square, opentable, resdiary, nowbookit | per-platform | `OnDemand` | `SelectionPayload` |
| **Multi-brand** | shop (shopify / woocommerce / bigcartel / squarespace) | per-provider | `OnDemand` | `ShopPayload` |

**Specials** (own descriptor, custom strategies): `events-custom` (standalone events), `custom` (custom links list), `google-business` (auto-sync that seeds Instagram/OpenTable/Uber Eats rows), menu fetch (online-ordering), and the smart-detect **category pseudo-platforms** (`booking`, `reservations`, `online-ordering`) which resolve to a concrete provider via `Detection`.

**Bespoke controllers retained** (implementing the same descriptor contract, not collapsed into a generic controller): Instagram (async Apify job + polling), Fresha (multi-step team/services picker), Shop (multi-brand CRUD).

---

## 8. Typed payloads

One `readonly` DTO per archetype: `LinkPayload`, `EmbedPayload`, `FeedPayload`, `SelectionPayload`, `ShopPayload` (+ specials). Each owns:

- **`fromArray(array): self`** — lenient hydration. Tolerates missing keys (old snapshots) and extra keys (upstream drift). *This is the single home for the `?? null` tolerance currently scattered across ~29 `data_get` sites.*
- **typed properties** — `$payload->selection->employee` replaces `data_get($row->payload, 'selection.employee')`.
- **`toArray(): array`** — back to the `jsonb` column shape.

**Round-trip:** `payload` (jsonb array) → `fromArray()` → typed DTO → typed code → `toArray()` → store. **Output:** DTO → Resource → JSON.

Because the contract is frozen, each DTO is constrained from both ends: it must carry every field its current Resource emits (or the golden-master test fails), and it may carry internal-only fields the Resource omits (e.g. Instagram's `_folder`). The DTO becomes the **honest, complete schema** of what a platform stores — currently implicit across controller + scraper + Resource. The Resource shrinks to a dumb typed-property → JSON mapping with no defensive `?? null` noise.

**Deletes:** the ~29 `data_get` sites, the hand-rolled `is_array()` checks, and the duplicate field-allowlisting in every Resource.

---

## 9. Storage, validation, capability gating

- **Storage — unchanged.** `site.platform_connections`, single table, `payload jsonb`. Revisit *only* if cross-payload relational querying is ever needed (unlikely for link-in-bio render).
- **Validation — registry is the gate.** Drop the DB `CHECK` constraint (one `DROP CONSTRAINT` migration — the lone schema change). A Form Request rule validates `platform ∈ $registry->keys()`; a coverage test asserts every storable platform value resolves in the registry. Adding platform #31 = zero migration. Trade: lose a DB-level safety net against a buggy write, mitigated by app-level validation + the registry as a hard gate (acceptable pre-customers).
- **Capability gating — baked in now.** `PlatformDescriptor::availableFor(User): bool` reads `AccountCapabilities`; returns true for all today. Central checkpoints (connect flow, dashboard list, public render) exist from day one, so future paid-tier/account-type gating is a per-descriptor flag, not 30 ad-hoc checks.

---

## 10. Migration strategy (strangler, never red)

Order is **risk-ascending**, and the golden master is captured **first**.

1. **Capture the golden master.** Snapshot current JSON responses for all 30 platforms' read endpoints into fixtures — the frozen contract.
2. **Build the spine alongside old code** — registry, `PlatformDescriptor`, strategy interfaces, 5 payload DTOs, generic controllers. Wired to nothing yet; suite green.
3. **Migrate archetype-by-archetype, simplest first:** Link-only (9) → oEmbed (3) → Scraped/API feed → Picker → Shop + specials → **the 3 bespoke last.** Per platform: point descriptor at the spine → delete its old controller → run golden-master + full suite → green → commit.
4. **Collapse the centralizers:** `PlatformRefresher` `match()` → registry iteration; `ProviderDetector` → registry-driven; the `DROP CONSTRAINT` migration; remove now-dead trait paths.

Simplest-first de-risks the abstraction itself — discover a wrong descriptor shape on LinkedIn (57 lines, no fetch), not on Instagram's async job. By the bespoke three, the spine has proven itself against 27 platforms. Old controllers are deleted the moment their replacement goes green, so the two-pattern window is hours-per-platform, never the whole project.

**One spec → one plan**, staged into these archetype batches; each batch is independently shippable and green.

---

## 11. Testing

- **Golden-master contract test (centerpiece).** Seed known `payload` rows, hit each read endpoint, assert JSON byte-identical to the captured fixture. Makes "freeze the contract" a *proof*; fails the instant a refactor drifts one field.
- **Fetch is mockable.** Connect endpoints that trigger external scrapes swap in a fake `FetchStrategy` — no live Apify/oEmbed calls in tests. A testability win the silos lack.
- **Archetype parity tests** — per DTO: `fromArray(toArray(x)) === x`; lenient hydration (missing → null, extra → ignored).
- **Registry coverage test** — every descriptor resolves; every storable platform value is in the registry. *Does the job the dropped CHECK used to.*
- **Existing per-platform feature tests stay** — extra parity guards, not deletions.

**SQLite/Postgres note (per CLAUDE.md):** we drop a Postgres `CHECK` (never enforced in SQLite anyway) and replace it with **app-level** registry validation that runs identically on both engines — so validation gets *stronger* in CI, not weaker.

---

## 12. Durability — how the predicted futures land (additively)

This validates the "won't need structural rework" claim by naming the futures and showing each is a one-interface addition, not a restructure:

| Future | How it lands |
|--------|--------------|
| **Platform #31 (URL-based)** | One `PlatformDescriptor` line. Zero migration, zero refresher/detector edits. |
| **Paid-tier / account-type gating** | Set a capability flag on the relevant descriptors; central `availableFor()` checkpoints already exist. |
| **Upstream API shape change** | Fix one `fromArray()`; lenient hydration already absorbs missing fields. |
| **A real OAuth platform** (e.g. Shopify app, IG Graph API) | Implement `OAuthConnect` (seam already defined) + add a `platform_tokens` table for *that* class of platform. Spine, registry, payloads, refresher all unchanged. |
| **Webhook-driven platform** | Implement `WebhookRefresh` (seam defined) + a webhook ingress route. Registry iteration already supports per-platform refresh strategies. |

**The one future that would force real change** is a *domain* pivot from "render-only display connectors" to "authenticated two-way sync" (writing back to providers). Even then the registry/descriptor spine survives; only the new strategy implementations + a token table are added. The `jsonb` storage model only truly breaks if cross-payload relational querying becomes a requirement.

---

## 13. Risks & mitigations

| Risk | Mitigation |
|------|------------|
| Golden-master misses an endpoint → silent contract drift | Enumerate fixtures from the route list programmatically; assert count == registered integration read-routes. |
| Dropping the CHECK lets a bug write a bad `platform` | Form Request rule + registry gate + coverage test; pre-customer blast radius. |
| Bespoke platforms (Instagram/Fresha/Shop) strain the descriptor shape | Migrated **last**, after the spine is proven on 27 platforms; they keep custom controllers, only their *identity* joins the registry. |
| Adapting scrapers to `FetchStrategy` changes behavior | Each scraper adaptation guarded by its existing feature test + golden master before delete. |
| SQLite/Postgres schema drift | Validation moves to app-level (engine-agnostic); the only schema change is a `DROP CONSTRAINT`. |

---

## 14. File-level impact sketch (indicative)

**New:**
- `app/Services/Platforms/Registry/PlatformRegistry.php`, `PlatformDescriptor.php`, `Category.php`
- `app/Services/Platforms/Strategies/Connect/{UrlConnect,OAuthConnect*,ApiKeyConnect*}.php`
- `app/Services/Platforms/Strategies/Fetch/{NoFetch,OEmbedFetch}.php` + interface
- `app/Services/Platforms/Strategies/Refresh/{NoRefresh,OnDemand,Scheduled,WebhookRefresh*}.php` + interface
- `app/Services/Platforms/Payloads/{LinkPayload,EmbedPayload,FeedPayload,SelectionPayload,ShopPayload}.php`
- `app/Providers/PlatformRegistryServiceProvider.php` (or registration in an existing provider)
- `tests/Feature/Platforms/GoldenMasterContractTest.php`, archetype parity + registry coverage tests
- `supabase/migrations/<ts>_drop_platform_connections_check.sql`

**Changed:**
- Existing scrapers/APIs → implement `FetchStrategy`
- `PlatformRefresher` → registry iteration
- `ProviderDetector` → registry-driven
- Resources → consume typed DTOs
- `SingleSelectionPlatformController` → generic, registry-driven

**Deleted:**
- The ~17 thin per-platform controllers (folded into the generic registry-driven controller)
- Scattered `data_get($row->payload, …)` access throughout

---

## 15. Open questions (resolve during planning)
- Final archetype assignment for edge platforms: `tidal`, `mixcloud`, `apple-music` vs `apple-podcast` fetch path.
- Whether the generic controller fully replaces `SingleSelectionPlatformController` or extends it.
- Exact golden-master fixture seeding for endpoints that today trigger a live fetch on connect.
