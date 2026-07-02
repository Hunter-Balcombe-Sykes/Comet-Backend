# Foundational durability & extensibility: shotgun surgery, denormalization debt, leaky abstraction boundaries

Find the structural decisions that work fine today at pre-beta scale but will force a painful rewrite — a breaking migration, a refactor across dozens of files, or a re-modelling of stored data — in a few months/years as the platform scales and accumulates features. This is NOT a bug hunt. It is an architecture-durability review: where will adding the *next* thing (a new platform integration, a new notification, a new block/link type, a new design var) cost far more edits than it should, and where is data stored in a shape that can't be indexed, constrained, joined, or evolved without a rewrite.

Bias toward findings on code touched in the **last ~4 weeks** — the new platform-integration / menu-scraping subsystem and anything that pushes structured data into JSON. The goal is to fix the foundation now, while it is cheap, not after ten more platforms have copied the pattern.

## Background — the durability smells

**Shotgun surgery (extensibility friction).** Adding one conceptual thing requires editing many files in lockstep. The tell: a new platform needs a new route + a new `XxxController` + a new `XxxScraper` + a new `XxxConnectionResource` + a new enum case + a new `match($platform)` arm in three different files. Each copy drifts (one forgets rate-limiting, one forgets the capability gate, one validates differently). The durable shape is a **registry / factory / interface + config map**: one new class implementing a shared contract, registered in one place, with shared behaviour (auth, validation, rate-limit, connection persistence, refresh scheduling) owned by a base class or the dispatcher — so adding plat/N is one file, not five.

**Denormalization debt (JSON that should be relational).** Structured data lives in a `jsonb` / array-cast column when it is actually queried, filtered, validated field-by-field, joined against, uniqueness-constrained, or grows unboundedly per row. JSON is the right tool for genuinely freeform / write-once / audit-trail / opaque-passthrough data. It is the wrong tool when the app reaches *into* the blob to read or enforce a known set of fields — those want real columns (typed, indexed, CHECK-constrained, FK-referenced) or a child table (one row per element, with its own PK/FK/index). The tell: PHP code that does `$model->settings['design']['accent']`, SQL that does `payload->>'status' = '...'` in a WHERE/JOIN, validation rules enumerating JSON sub-keys, or a JSON array that the code appends to without bound.

**Leaky abstraction boundaries.** Business logic that the codebase's own rules say belongs in a Service has leaked into controllers; cross-cutting concerns (auth, validation, rate-limiting, error shaping, connection CRUD) are re-implemented per-variant instead of owned by a shared base/middleware/trait; the same scraper boilerplate (HTTP client setup, retry, response normalisation, persistence) is copy-pasted across N scrapers instead of living in one base class.

**Schema/contract decisions that will need a breaking change to scale.** Hardcoded enum sets in a CHECK constraint that should be a lookup table; access paths that can only be served by scanning a JSON blob because the value isn't a column; string-keyed soft coupling where a FK belongs; a column/table whose meaning is overloaded (one column storing two unrelated concepts depending on a type flag).

## Highest-risk surfaces today

- **Platform integration subsystem (newest, largest, most duplicated):** `app/Http/Controllers/Api/Platforms/` (~38 controllers) and `app/Services/Platforms/` (~36 services — `*Scraper.php`, `*Service.php`, `PlatformScraper.php`, `PlatformRefresher.php`, `ProviderDetector.php`, `PlatformInput.php`, the `Concerns/ManagesIntegrationConnection.php` trait). Central question: is there ONE canonical "add a platform" path, or does each platform fan out across a controller + scraper + resource + job + enum case + route? Where is the shared contract, and what concerns are NOT yet shared (rate-limiting, capability gating, validation, connection persistence, refresh scheduling, error shaping)?
- **Connection / integration storage:** `app/Models/Core/Site/IntegrationConnection.php` and its migration — what is in `payload` / `metadata` JSON vs real columns, and is any of it queried/filtered/validated/constrained? `platforms jsonb` column. Refresh status / scheduling fields.
- **Menu & catalog storage (new scraping output):** `app/Models/Core/Site/MenuItem.php` (`modifiers`, `badges` jsonb), `MenuMerger`, `MenuSource`, `EventsCatalog`/`EventsPayload`. Scraped data normalised into queryable rows, or dumped into JSON?
- **Site configuration JSON:** `app/Models/Core/Site/Site.php` `settings` JSONB and `app/Models/Core/Site/Block.php` casts — which sub-keys does the app read/write/validate by name (those are columns-in-waiting), vs genuinely freeform.
- **Cross-cutting "add one thing" paths:** notification dispatch wiring, block/link type registration, design-kit var addition, `AccountCapabilities` gates — does adding one require edits in 1 place or many?
- **Config home:** `config/partna.php` — per-variant behaviour that should be a single registry/config entry vs hardcoded across files.

## Use the lens prefix `FOUND` for findings

Number them `FOUND-1`, `FOUND-2`, … sequentially across the whole audit, regardless of category.

## Findings categories

### (1) Shotgun surgery — adding one thing touches N files
- A new platform / integration that requires a new controller AND scraper AND resource AND job AND enum case AND route AND a `match()`/`switch` arm edited in multiple files. Identify the *exact* file set that must change to add one platform today, and propose the registry/factory/interface that collapses it to one class + one registration.
- Any `match($platform)` / `switch`/ big `if-elseif` ladder over a type/platform/provider string that appears in **more than one file** — each is an edit site that will be forgotten. These want a single dispatch table or polymorphic interface.
- Per-variant controllers/services that are ~90% identical boilerplate differing only in a constant (endpoint, provider name, field mapping) — candidates for a single parameterised/base implementation.
- Cross-cutting registration that isn't centralised: a new notification, block type, link type, or design-kit var that requires touching a model + a resource + a validator + a migration + a frontend contract in lockstep with no single source of truth.

### (2) Denormalization debt — JSON that should be columns or a child table
- `jsonb` / `array`-cast columns whose **known sub-keys are read or written by name** in PHP (`$x->settings['foo']['bar']`) or filtered in SQL (`col->>'k' = …` in WHERE/JOIN/ORDER) — these fields want real, typed, indexable columns.
- JSON arrays the code **appends to unboundedly** (grows per event/scrape/action) — these want a child table (one row per element) so they can be paginated, indexed, and pruned.
- JSON holding data that needs a **uniqueness / FK / CHECK constraint** the DB can't enforce on a blob (e.g. a connection's external id, a status enum, a handle reference).
- Validation rules (Form Requests) that **enumerate JSON sub-keys** field-by-field — if the shape is known and enforced, it is a table/column shape, not freeform JSON.
- For each: state whether the right move is (a) promote sub-keys to columns on the same table, (b) extract a 1:N child table, or (c) leave as JSON (genuinely freeform/opaque/write-once) — and say which and why. Do NOT flag legitimate JSON: audit/event payloads, opaque third-party passthrough, write-once snapshots, truly schemaless user settings.

### (3) Leaky abstraction boundaries
- Business logic in `Http/Controllers/` that the project's own rules place in `Services/` (orchestration, external calls, multi-step writes) — especially in the Platforms controllers.
- Cross-cutting concerns re-implemented per-variant instead of shared: auth/capability gating, input validation, rate-limiting, error/response shaping, connection CRUD, pagination — anywhere the same concern is hand-rolled in many controllers rather than owned by a base controller / middleware / trait / Form Request.
- Scraper/HTTP boilerplate (client construction, retry/backoff, timeout, response normalisation, persistence) duplicated across `*Scraper.php` instead of inherited from a base class or composed from one client.
- Resource classes that hand-build the same shape per platform instead of a shared base resource + per-platform delta.

### (4) Schema & contract decisions that will need a breaking migration to scale
- Hardcoded enum sets in a Postgres `CHECK` (or a PHP enum mirrored in a CHECK) that will grow and currently force a migration on every addition — candidate lookup table or a documented, deliberately-narrow CHECK.
- Access paths only serveable by scanning a JSON blob because the value isn't a column/index — will not hold up under row growth.
- String-keyed soft coupling where a FK + index belongs (orphan risk, no referential integrity).
- Overloaded columns/tables (one column meaning two things by a type flag; one table conflating two entities) that will fork later.
- Missing indexes on columns that the new scraping/integration read paths filter or join on at scale.

### (5) Configuration vs code
- Per-platform / per-variant constants (endpoints, provider metadata, field maps, limits, feature toggles) hardcoded and scattered across files instead of one `config/partna.php` (or a registry) entry — so changing one behaviour means hunting across the subsystem.
- Magic numbers/strings for the same concept duplicated in multiple files (drift risk).

## What NOT to flag (keep signal high)
- Caching, rebuild-on-write, write-amplification, stampede/jitter — owned by the `scaling-antipatterns` and `caching` lenses; skip them here.
- Pure security vulns (authz bypass, injection, SSRF) — owned by the `security` lens; only flag security-relevant *structural* duplication (e.g. a shared guard missing so each controller re-implements authz inconsistently).
- Style/naming/formatting nits — owned by `code-quality-slop`.
- Genuinely freeform JSON (audit payloads, opaque passthrough, write-once snapshots, truly schemaless settings) — leave it.
- Premature abstraction: if only ONE variant exists today, do not demand a registry for one. Flag duplication that already exists (2+ copies) or is imminent given the trajectory (the platform subsystem), not hypothetical future variants.

## Output expectations
For each finding, make the *foundational* cost explicit: what specifically breaks or becomes expensive in 3–12 months if left (a breaking migration on a live table, an N-file edit per new platform, a query that can't be indexed), and the concrete durable shape that prevents it. Prefer a few high-leverage structural findings over many small ones. Where the fix is a data re-modelling (JSON → columns/table) or an abstraction (controllers → registry), sketch the target shape concretely enough that a follow-up `execute audit` session could plan it.

## Suggested per-domain scope groups (for `--codebase --bundle foundational`)
The full `app` + `routes` + `supabase/migrations` + `config/partna.php` scope is ~770K tokens — far over the per-scan recall ceiling — so codebase mode chunks it. The chunk map lives in `scripts/audit/audit.sh` → `codebase_chunks()` under `foundational-durability` (keep the two in sync when the tree moves). Each chunk is sized ≤ ~350KB of source; the two highest-signal surfaces (the Platforms controllers and the Platforms services) are isolated into their own chunks for maximum scan recall. Chunks:

- `platforms-controllers` — `app/Http/Controllers/Api/Platforms`
- `platforms-services` — `app/Services/Platforms`
- `schema-migrations` — `supabase/migrations`
- `models-config` — `app/Models config/partna.php` (model casts beside the config registry, for denormalization findings)
- `integration-cross-cutting` — `app/Jobs/Platforms app/Services/Notifications app/Jobs/Notifications app/Services/Accounts app/Services/FeatureFlags` (the "add one thing" registration paths)
- `controllers-user`, `controllers-staff-public` — remaining API controllers + shared base controllers/Concerns
- `services-core`, `services-vendor` — remaining services
- `requests-resources` — `app/Http/Requests app/Http/Resources` (Form Requests that enumerate JSON sub-keys; per-platform resource shapes)
- `routing-middleware-policies` — `routes app/Console app/Http/Middleware app/Policies app/Observers`
- `jobs-providers-rest` — remaining `app/Jobs/*` + `app/Providers app/Mail app/Notifications app/Listeners app/Exceptions app/DTOs app/Support app/Enums app/Rules app/Contracts app/helpers.php`
