# Platform & Content Architecture — Foundational Design

**Date:** 2026-07-27 · **Status:** Draft for review

## 1. The problem

Partna aggregates content from ~80 external platforms (social media, music, booking,
events, reservations, ordering, etc.) for individual professionals' public profile
pages. We have two competing architectures:

| | V4 (active on `development`) | V5 (reverted 2026-07-27) |
|---|---|---|
| **Platform identity** | Code descriptors in `PlatformRegistryServiceProvider` — one `PD::make()` block per platform | Database rows in `v5.platform_definitions` with inheritance chains (base → category → platform) |
| **Content model** | Siloed — each platform's data lives as a JSONB blob in `IntegrationConnection.payload` | Unified — `v5.items` + `v5.item_values` with cross-platform merge keys |
| **Adding a platform** | One `PD::make()` block + a fetch strategy class | DB migration + config entry + code adapter — two sources of truth |
| **Scraper→API swap** | Change a closure in the registry | Change DB row + code adapter |

V4 gets platform identity right. V5 gets content pooling right. Neither gets both.
This document proposes merging the two: **code-driven platform identity + a
first-class content pooling service.**

---

## 2. What the industry does

We researched 13+ platforms — content aggregators (Curator.io, RebelMouse, Smash
Balloon, Walls.io, Flockler), integration platforms (Zapier, Pipedream, n8n, IFTTT,
Make), and content standards (ActivityPub, W3C Activity Streams 2.0). Key findings:

### Platform identity lives in code, not databases

Every major integration platform defines its connectors/apps in code — Node.js
modules (Zapier), TypeScript classes (n8n), `.app.mjs` files (Pipedream).
**Make.com is the lone database-defined exception**, and even it stores only
configuration (endpoint URLs, field names), not behavior. The actual HTTP calls,
pagination, and error handling are executed by Make's runtime engine, not defined
in database rows.

**The rule:** if two platforms differ in WHAT THEY DO (scrape vs API call, OAuth vs
API key, poll vs webhook), use code. If they differ in WHAT DATA THEY STORE (name,
category, refresh interval), use a database. Platforms differ in behavior. Code wins.

### Content normalization: thin envelope, raw preservation

Every content aggregation platform surveyed follows the same pattern:

```sql
-- Shared envelope (queryable, sortable)
platform, external_id, entity_type, content_text, media_type, published_at

-- Type-specific rich data (per-platform variance)
raw_payload JSONB     -- the original API response, preserved VERBATIM
type_specific JSONB   -- normalized platform-specific fields
```

**`raw_payload` is load-bearing.** Never discard it. It enables re-normalization
when your schema evolves, and preserves platform richness the envelope can't capture.
EAV (entity-attribute-value) with a single VARCHAR `value` column is universally
condemned — PostgreSQL JSONB with GIN indexes is the settled best practice.

### Scraper→API migration uses the Strategy pattern

The canonical approach is hexagonal architecture: a technology-agnostic interface
(the "port"), with each data source — scraper, official API, mock — as an "adapter"
implementing that interface. Domain code never knows which adapter is active.

### Content pooling across platforms

Curator.io, RebelMouse, and SMDT all use the same model: per-platform ingestion
adapters → normalize into unified schema → store → serve through one API. Items
from different platforms with the same identity key (ISRC for music, URL for videos,
SKU for products) merge into a single row with per-source value tracking.

---

## 3. Recommended architecture: three layers

Think of it like a restaurant kitchen:

```
┌──────────────────────────────────────────────────────────────┐
│  LAYER 1: THE MENU — Platform Identity (CODE)               │
│  "What platforms exist and what can they do?"               │
│                                                              │
│  One file: PlatformRegistryServiceProvider.php               │
│                                                              │
│  Category defaults — defined ONCE, inherited by all          │
│  platforms in that category:                                 │
│                                                              │
│    PD::category(Cat::Video, [                                │
│      'refresh_interval' => 12 * 3600,   // 12 hours          │
│      'source_method'    => 'api',                            │
│      'feeds'            => [ContentPool::Watch],             │
│      'normalizer_base'  => VideoItemNormalizer::class,       │
│    ]);                                                       │
│                                                              │
│  Platforms — only specify what DIFFERS from the category:    │
│                                                              │
│    PD::make('youtube')                                       │
│      ->label('YouTube')                                      │
│      ->category(Cat::Video)  // inherits ALL Video defaults  │
│      ->fetch(fn () => new YoutubeFetch);                     │
│                                                              │
│    PD::make('vimeo')                                         │
│      ->label('Vimeo')                                        │
│      ->category(Cat::Video); // identical defaults, zero cfg │
│                                                              │
│    PD::make('twitch')                                        │
│      ->label('Twitch')                                       │
│      ->category(Cat::Video)                                  │
│      ->refreshEvery(4 * 3600); // override ONE thing         │
│                                                              │
│  Change one line → affects every platform that didn't        │
│  override. Change Video refresh to 6 hours? One edit.        │
│  Swap all video platforms from HtmlScrape to API? Change     │
│  the category default — platforms that explicitly set a      │
│  fetch strategy keep theirs; the rest inherit the new one.   │
│                                                              │
│  Why code, not a database: categories can hold BEHAVIOR      │
│  defaults (normalizer base class, default strategy type)     │
│  that a database row can't. Two sources of truth for the     │
│  same fact means one of them is always out of date.          │
└──────────────────────────────┬───────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────┐
│  LAYER 2: THE DELIVERY — Fetch Strategies (CODE)            │
│  "How do we get data from each platform?"                   │
│                                                              │
│  Inheritance hierarchy — only ~5 ways to get data exist:     │
│                                                              │
│                     BaseFetcher (abstract)                    │
│      handles: rate limiting, retries, error logging,         │
│      conditional fetch (ETag/304), circuit breaker           │
│                         │                                    │
│     ┌───────┬───────────┼───────────┬──────────┐            │
│     ▼       ▼           ▼           ▼          ▼            │
│  ApiFetch ApifyFetch OEmbedFetch HtmlScrape GraphQLFetch     │
│     │       │           │           │          │            │
│     ▼       ▼           ▼           ▼          ▼            │
│  Spotify  Instagram  SoundCloud  Bandcamp   Fresha           │
│  Google   (future:   Mixcloud    Square     (future:         │
│  Apple    Instagram              OpenTable   more)           │
│  YouTube  ApiFetch)                                         │
│                                                              │
│  Each leaf class is 20-40 lines: a URL + a field map.        │
│  The parent handles everything repetitive.                   │
│                                                              │
│  Every fetcher returns a RawFetchResult DTO with THREE       │
│  things (this is load-bearing):                              │
│                                                              │
│    RawFetchResult {                                          │
│      raw: array,        // untouched original response       │
│      items: array,      // normalized for content service    │
│      profile: array,    // display metadata (name, avatar)   │
│    }                                                        │
│                                                              │
│  The "raw" field is crucial — it's like keeping the          │
│  original receipt. If you change how you normalize data      │
│  later, you re-process the raw without re-fetching.          │
│                                                              │
│  Scraper → API swap: change which parent the leaf extends    │
│  and the normalizer class. That's it. The rest of the        │
│  system doesn't care how the data arrived.                   │
└──────────────────────────────┬───────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────┐
│  LAYER 3: THE PANTRY — Content Service (DATABASE)           │
│  "Where we store everything so it can be queried, merged,    │
│   and served across platforms"                               │
│                                                              │
│  Schema: `content` (new Postgres schema)                     │
│                                                              │
│  ┌─ content.items ──────────────────────────────────────┐   │
│  │  id, user_id, pool, identifier (merge key),           │   │
│  │  name, item_type, resolved_values (JSONB),            │   │
│  │  sort_order, is_selected, timestamps                  │   │
│  │                                                       │   │
│  │  UNIQUE (user_id, pool, identifier)  ← dedup key      │   │
│  │                                                       │   │
│  │  Example row:                                         │   │
│  │  pool=music, identifier=ISRC:USUM71600003            │   │
│  │  → merges Spotify + Apple Music + SoundCloud          │   │
│  └───────────────────────────────────────────────────────┘   │
│                                                              │
│  ┌─ content.item_values ────────────────────────────────┐   │
│  │  item_id, source_platform (string, e.g. 'spotify'),   │   │
│  │  source_connection_id, field_name, value,             │   │
│  │  is_manually_set (survives refresh if true)           │   │
│  │                                                       │   │
│  │  UNIQUE (item_id, source_platform, field_name)        │   │
│  │                                                       │   │
│  │  Spotify → album_name: "After Hours"                  │   │
│  │  Apple   → collection_name: "After Hours"             │   │
│  │  Both map to the same item. Display picks winner.     │   │
│  └───────────────────────────────────────────────────────┘   │
│                                                              │
│  ┌─ content.item_sources ───────────────────────────────┐   │
│  │  item_id, connection_id — which connections feed      │   │
│  │  this item (used for source-specific display)         │   │
│  └───────────────────────────────────────────────────────┘   │
│                                                              │
│  ┌─ content.raw_payloads ───────────────────────────────┐   │
│  │  connection_id, fetched_at, payload (JSONB)           │   │
│  │                                                       │   │
│  │  The load-bearing table. Original API/scraper         │   │
│  │  responses preserved verbatim. Enables re-            │   │
│  │  normalization without re-fetching.                   │   │
│  └───────────────────────────────────────────────────────┘   │
│                                                              │
│  ┌─ content.resolution_rules (optional, can defer) ─────┐   │
│  │  user_id, item_type, field_name, rule                 │   │
│  │  Defaults: text→most_recent, image→highest_resolution │   │
│  └───────────────────────────────────────────────────────┘   │
│                                                              │
│  ┌─ content.resolution_audit (optional, can defer) ─────┐   │
│  │  item_id, field_name, winner, losers_json             │   │
│  │  Records every merge decision for debugging           │   │
│  └───────────────────────────────────────────────────────┘   │
│                                                              │
│  Read path:                                                  │
│  ┌─ Redis/KV projection ────────────────────────────────┐   │
│  │  user:{id}:pool:{pool_name} → items[]                 │   │
│  │  Built on write, invalidated on change.               │   │
│  │  Profile pages read from cache, not Postgres.         │   │
│  └───────────────────────────────────────────────────────┘   │
│                                                              │
│  Pools (PHP enum, NOT database rows):                        │
│    Watch, Music, Podcasts, Services, Menu, Products,         │
│    Events, Links, Media, Reviews                             │
│                                                              │
│  New pool = one enum case + one migration for any CHECK      │
│  constraints. No CRUD UI needed.                             │
└──────────────────────────────────────────────────────────────┘
```

### 3a. Category inheritance in the code registry

One of the user's concerns: "if I want to change the refresh interval for ALL video
platforms, do I have to edit every single one?" The answer is no — the code registry
supports category-level defaults with per-platform overrides.

**How it works:** When a platform calls `->category(Cat::Video)`, the descriptor
starts with every default defined for the Video category. Each subsequent fluent
call (`->refreshEvery(...)`, `->fetch(...)`, etc.) overrides only that specific
property. The platform gets everything it didn't explicitly set from the category.

```
Registration-time resolution (happens once at boot, no runtime overhead):

PD::category(Cat::Video, [                    // ← the defaults
    'refresh_interval' => 12 * 3600,
    'source_method'    => 'api',
    'feeds'            => [ContentPool::Watch],
    'normalizer_base'  => VideoItemNormalizer::class,
]);

PD::make('vimeo')->category(Cat::Video);
// → refresh: 12h (inherited), feeds: [Watch] (inherited), normalizer: VideoItemNormalizer (inherited)

PD::make('twitch')->category(Cat::Video)->refreshEvery(4 * 3600);
// → refresh: 4h (OVERRIDDEN), feeds: [Watch] (inherited), normalizer: VideoItemNormalizer (inherited)

PD::make('youtube')->category(Cat::Video)->feeds([ContentPool::Watch, ContentPool::Links]);
// → refresh: 12h (inherited), feeds: [Watch, Links] (OVERRIDDEN), normalizer: VideoItemNormalizer (inherited)
```

**Why this beats database-driven inheritance:**

| | Database (v5) | Code registry (this design) |
|---|---|---|
| Resolution | Query-time: JOIN platform → category → base on every read | Registration-time: merge once at boot, result cached in singleton |
| What can be a default | Only scalar values (strings, numbers, booleans) | Anything — class names, closures, enums, arrays of strategies |
| Change propagation | UPDATE a row → takes effect immediately (no deploy needed, but no audit trail) | Edit the category defaults → deploy → takes effect (git history = audit trail) |
| Two sources of truth? | Yes — DB row says "YouTube exists," fetcher class says what YouTube means | No — the registry IS the source of truth |

**When to use which:**

- **Change a category default** when the change applies to "most platforms in this category." Platforms that explicitly override the property keep their override. Example: "all video platforms now refresh every 6 hours" → change the Video category default. Twitch (which overrode to 4 hours) stays at 4 hours.
- **Change a platform override** when one platform genuinely differs. Example: "Twitch streams are live content, refresh every 30 minutes" → Twitch was already overriding, so change its specific value.
- **Add a platform** when a new service enters a category. Example: "add Dailymotion" → `PD::make('dailymotion')->label('Dailymotion')->category(Cat::Video)`. Done. Inherits everything.

This is the same inheritance model as v5's `base → category → platform` chain, but
implemented in code instead of SQL. Same power, zero database queries, and the
defaults can include things a database column can't express.

---

## 4. Content pools

A pool is a logical grouping of content items. Pools are defined as a PHP enum
(build-time decision, not runtime data). Platforms declare which pools they feed
via `->feeds()` on the descriptor.

| Pool | Example item types | Example platforms |
|------|-------------------|-------------------|
| `watch` | video, embed | YouTube, Vimeo, Twitch |
| `music` | track, embed | Spotify, Apple Music, SoundCloud, Bandcamp |
| `podcasts` | episode, embed | Apple Podcasts, Spotify |
| `services` | service | Fresha, Booksy, Vagaro |
| `menu` | menu_item | Square, OpenTable, Uber Eats |
| `products` | product | Shopify, Gumroad |
| `events` | event | Eventbrite, Humanitix, Ticketek |
| `links` | link | TikTok, Instagram, X, LinkedIn |
| `media` | image, video | Instagram, Pinterest |
| `reviews` | review | Google Business |

Items deduplicate within a pool by `(user_id, pool, identifier)`. The identifier
is pool-specific:
- **Music:** ISRC code (industry-standard unique track ID)
- **Watch:** Video URL or platform-specific ID
- **Products:** SKU or product URL
- **Services:** Service name + platform (fuzzy match with manual merge)
- **Events:** Event URL or platform-specific ID
- **Links:** URL (exact match)

When two platforms feed the same item (e.g., same track on Spotify AND Apple Music),
they share one `content.items` row with two `content.item_values` rows — one per
source platform. Conflict resolution picks the winner per field.

---

## 5. Fetcher inheritance explained

Every platform uses one of ~5 fetch methods. The base classes handle all shared
behavior; leaf classes are thin.

```
BaseFetcher (abstract)
├── Handles: rate limit backoff, retry with jitter, error logging,
│            conditional fetch (If-None-Match / If-Modified-Since),
│            circuit breaker (stop trying if upstream is down)
│
├── ApiFetcher — for platforms with REST APIs
│   ├── Handles: Bearer/auth headers, pagination, JSON parsing
│   ├── Child implements: endpoint URL, itemsFromResponse(), normalizeItem()
│   ├── Used by: Spotify, YouTube, Vimeo, Apple Music, Google Business,
│   │            Eventbrite, Humanitix, Twitch
│   └── Example leaf (SpotifyFetch, ~30 lines):
│       endpoint = 'https://api.spotify.com/v1/me/player/recently-played'
│       normalizeItem() maps track.name, track.album.name, track.duration_ms...
│
├── ApifyFetcher — for platforms scraped via Apify actors
│   ├── Handles: actor dispatch, polling for completion, result dataset fetch
│   ├── Child implements: actor ID, input builder, normalizeItem()
│   ├── Used by: Instagram, Google Business (pre-API), Square menus,
│   │            Uber Eats menus
│   └── Scraper→API path: child changes from extends ApifyFetcher to
│       extends ApiFetcher. Normalizer changes. That's it.
│
├── OEmbedFetcher — for platforms with oEmbed endpoints
│   ├── Handles: oEmbed discovery, fallback URL parsing, embed HTML
│   ├── Child implements: endpoint URL (if known), normalizeItem()
│   ├── Used by: Spotify (embed mode), SoundCloud, Mixcloud, Tidal
│   └── These usually return a single embed, not a list
│
├── HtmlScrapeFetcher — for platforms with no API (parse HTML)
│   ├── Handles: HTTP fetch, DOM parsing, CSS selector extraction
│   ├── Child implements: URL template, selectors, normalizeItem()
│   ├── Used by: Bandcamp, Strava Club, Skool
│   └── Most fragile — first candidates for API migration
│
└── GraphQLFetcher — for platforms with GraphQL APIs
    ├── Handles: query execution, variable interpolation, error parsing
    ├── Child implements: query string, variables, normalizeItem()
    └── Used by: Fresha (current), potential future platforms
```

Each leaf class answers only two questions:
1. **Where are the items in the response?** — `itemsFromResponse(array $body): array`
2. **What shape should each item be?** — `normalizeItem(array $raw): array`

That's it. No HTTP logic, no retries, no error handling — the parent does all that.

---

## 6. Content normalizers

Fetchers return raw data. Normalizers turn raw data into content items. Each
platform has its own normalizer, but they inherit from a shared parent per content
type:

```
MusicItemNormalizer (abstract)          VideoItemNormalizer (abstract)
├── Schema: name, artist, album,       ├── Schema: title, description,
│            ISRC, duration, image               URL, thumbnail, duration
├── SpotifyNormalizer                  ├── YouTubeNormalizer
├── AppleMusicNormalizer               ├── VimeoNormalizer
├── SoundCloudNormalizer               └── TwitchNormalizer
└── BandcampNormalizer
```

The normalizer's job is: `mapRawToNormalized(array $raw): NormalizedItem`. The
parent class handles validation, pool routing, and the normalized schema definition.

**Why normalizers are separate from fetchers:** when you swap Instagram from Apify
to Meta API, the fetcher changes (different HTTP calls) AND the normalizer changes
(different raw JSON shape). But the normalized output — the items table — stays
the same. Keeping them separate means you can write a new normalizer for the new
API without touching the fetcher for the old scraper during transition.

**Re-normalization without re-fetching:** raw payloads are preserved in
`content.raw_payloads`. If your normalizer was wrong, or you improve it, or you
migrate scraper→API with historical data, you run the new normalizer against the
stored raw payloads. No API calls needed. This is why `raw_payloads` is
load-bearing.

---

## 7. Scraper → API migration: step by step

Concrete example — Instagram moves from Apify scraping to Meta's official API:

### Before
```
InstagramFetch extends ApifyFetcher  →  RawFetchResult { raw: {...apify shape...} }
InstagramNormalizer_Apify            →  maps apify JSON → content.items
```

### Step 1: Write the new fetcher + normalizer (no user impact)
```
InstagramApiFetch extends ApiFetcher →  RawFetchResult { raw: {...meta api shape...} }
InstagramNormalizer_Api              →  maps meta api JSON → content.items
```

### Step 2: Feature-flag both, shadow-run new (no user impact)
```
Existing users: Apify path (unchanged)
Test users:     API path, compare outputs silently
```
Validate that the new normalizer produces identical item identifiers and
comparable field values. Fix discrepancies.

### Step 3: Ramp (per-user rollout)
```
0% → 10% → 50% → 100% of users on the API path
```

### Step 4: Re-normalize historical data
```
For all existing Instagram items:
  Read stored raw_payload (from Apify fetches)
  Run through InstagramNormalizer_Api
  Update item_values
```

### Step 5: Deprecate old fetcher
```
Delete InstagramFetch (ApifyFetcher subclass)
Delete InstagramNormalizer_Apify
ApifyFetcher stays — other platforms still use it
```

At no point does the content schema change. At no point do profile pages break.
At no point do users see different data. The swap is entirely within the
fetcher/normalizer layer.

---

## 8. What changes from current v4

### New files
| File | Purpose |
|------|---------|
| `app/Services/Content/ContentPool.php` | PHP enum of 10 pools |
| `app/Services/Content/ContentIngestionService.php` | Orchestrates fetch → normalize → merge → store |
| `app/Services/Content/Normalizers/` | Per-platform normalizer classes |
| `app/Services/Content/ConflictResolver.php` | Resolves per-field conflicts from item_values |
| `app/Models/Content/Item.php` | Eloquent model for `content.items` |
| `app/Models/Content/ItemValue.php` | Eloquent model for `content.item_values` |
| `app/Models/Content/ItemSource.php` | Eloquent model for `content.item_sources` |
| `app/Models/Content/RawPayload.php` | Eloquent model for `content.raw_payloads` |
| `app/Http/Controllers/Api/Content/` | Public API endpoints for pooled content |
| `app/Services/Platforms/Strategies/Fetch/BaseFetcher.php` | Abstract base for all fetchers |
| `app/Services/Platforms/Strategies/Fetch/ApiFetcher.php` | REST API base |
| `app/Services/Platforms/Strategies/Fetch/ApifyFetcher.php` | Apify actor base |
| `app/Services/Platforms/Strategies/Fetch/OEmbedFetcher.php` | oEmbed base |
| `app/Services/Platforms/Strategies/Fetch/HtmlScrapeFetcher.php` | HTML scraping base |
| `app/Services/Platforms/Strategies/Fetch/GraphQLFetcher.php` | GraphQL base |
| `app/Services/Platforms/Strategies/Fetch/RawFetchResult.php` | DTO replacing `array` return |

### Changed files
| File | Change |
|------|--------|
| `FetchStrategy` interface | Return type changes from `array` to `RawFetchResult` |
| `PlatformDescriptor` | Adds `->feeds(ContentPool)`, `->contentNormalizer(class)`, static `::category(Cat, defaults)` for category-level inheritance |
| `PlatformRefresher` | After fetch, routes `RawFetchResult` to `ContentIngestionService` |
| `PlatformRegistryServiceProvider` | Category defaults declared via `PD::category()`; each descriptor gains `->feeds()` + `->contentNormalizer()` calls |

### New database migrations (under `supabase/migrations/`)
1. Create `content` schema + `content.items`, `content.item_values`, `content.item_sources`, `content.raw_payloads` tables
2. (Optional, can defer) `content.resolution_rules`, `content.resolution_audit`

### What stays unchanged
- `PlatformRegistryServiceProvider` — same file, same registration pattern
- `PlatformDescriptor` — same fluent API, two new optional methods
- `ConnectStrategy`, `HighlightsStrategy`, `RefreshStrategy` — unchanged
- `IntegrationConnection` — continues to exist; new writes also populate content tables
- All existing API endpoints — no breaking changes

---

## 9. Why not the alternatives

### Why not store platform definitions in the database (v5's approach)?

The "just add a row" argument is seductive but misleading. Here's why:

**You have to write code either way.** A database row can store "Spotify exists"
and "it's in the Music category." It cannot fetch data from Spotify's API. It
cannot normalize that data into content items. It cannot validate a Spotify URL.
Those are code. So you're adding a row AND writing code — the row is extra work,
not replacement work.

Here's the real comparison:

```
┌──────────────────────────────────────────────────────────────────┐
│  DATABASE-DRIVEN (v5)           │  CODE-DRIVEN (this spec)       │
├──────────────────────────────────────────────────────────────────┤
│ 1. INSERT platform_definitions  │ 1. PD::make('spotify')         │
│    (name, logo, category, ...)  │      ->label('Spotify')        │
│                                 │      ->category(Cat::Music)    │
│ 2. Write SpotifyFetch           │                                 │
│ 3. Write SpotifyNormalizer      │ 2. Write SpotifyFetch           │
│ 4. Write SpotifyConnect         │ 3. Write SpotifyNormalizer      │
│                                 │ 4. Write SpotifyConnect         │
│ 5. Migration to seed the row    │                                 │
│    (or manual INSERT, or seeder)│                                 │
│                                 │                                 │
│ 6 places touched                │ 4 places touched                │
│ Identity + behavior in 2 places │ Identity + behavior in 1 place  │
└──────────────────────────────────────────────────────────────────┘
```

Steps 2-4 are identical. The only difference is step 1: a SQL INSERT vs a PHP
block. The PHP block is less total work because there's no migration, no seeding,
and everything about the platform is in one file.

**"But what about link-only platforms?"** — The best case for database rows. A
platform that just stores a URL, no fetching. In this design that's
`PD::linkOnly('tiktok', 'TikTok', LinkConnectionResource::class)` — one line.
In v5 it's an INSERT + a migration. The code approach is still less work.

**"But editing all the code!"** — You don't edit "all the code." You add ONE block
to ONE file. If the platform fits an existing category, it inherits everything. If
you need to change something for the whole category, you change ONE line in the
category defaults. That's the inheritance model in §3a.

**The real danger: two sources of truth.** The database row says
"YouTube.refresh_interval = 12 hours" but the fetcher class actually refreshes
every 24 hours because someone changed the code and forgot to update the row. Or
vice versa. Which one is right? No way to know without reading both. A code-driven
registry physically cannot have this bug — the descriptor IS the source of truth.

**When would database rows win?**
1. If non-engineers needed to add platforms without touching code. But they can't —
   someone still has to write the fetcher, normalizer, and connect strategy.
2. If platforms were added/removed at runtime without deploys. But they aren't — a
   new fetcher class requires a deploy anyway.
3. If there were 500+ platforms and the single provider file became unwieldy. At
   that scale you'd split the file, not move identity to a database.

The database approach solves problems Partna doesn't have. And it creates a problem
Partna would have immediately: two sources of truth for every platform fact.

Every major integration platform agrees: Zapier, Pipedream, n8n, and IFTTT all
define integrations in code. Make.com is the lone database-defined exception, and
even it stores only configuration (endpoint URLs, field names), not behavior.

### Why not config arrays?
Arrays have no types. Nothing stops `'catagory' => 'socail'` (typo) from shipping.
Arrays can't hold behavior — you can't put a closure or strategy object in a config
file. You'd need a resolver that turns string references into objects, and that
resolver becomes the actual registry, just hidden somewhere else.

### Why not PHP 8 Attributes (`#[Platform('youtube')]`)?
Great pattern for plugin ecosystems where third-party developers drop in unknown
classes. Partna has one developer and ~80 known platforms — nobody is dropping in
surprise platform definitions. Attributes add magic (why isn't this platform
loading? check the attribute, the scanner config, the cache) for a problem we
don't have.

### Why not a single-class-per-platform pattern?
Spotify and Apple Music would get their own class files with typed properties.
Clean separation for complex platforms. But the 22 link-only platforms (TikTok,
Facebook, X, LinkedIn...) differ only in name and icon — they don't need 22 class
files. The fluent descriptor handles both cases (one line for simple, full chain
for complex) in the same pattern.

---

## 10. Open questions for review

1. **Should `raw_payloads` be a separate table or a column on `item_sources`?**
   A separate table allows multiple historical snapshots per connection (useful for
   debugging and re-normalization). A column is simpler. Recommendation: separate
   table, but only keep the most recent raw payload per connection (overwrite on
   each fetch).

2. **Do we need `resolution_rules` and `resolution_audit` from day one, or can they
   be deferred?** The default resolution rules (text→most_recent, image→highest_res,
   date→earliest) handle 90% of cases. Custom rules and audit trails can be added
   when the first real conflict-surfacing bug appears. Recommendation: defer.

3. **Should pools be a PHP enum or database rows?** An enum means adding a pool is
   a code change (one case + one migration for any CHECK constraints). Database
   rows allow non-engineers to manage pools but add a CRUD surface nobody has asked
   for. Recommendation: PHP enum — pools change at the speed of product decisions,
   which is a code-change speed.

4. **Migration path for existing `IntegrationConnection.payload` data?** Existing
   payloads stay where they are. New fetches populate both the legacy payload column
   AND the content tables. Over time, reads migrate to the content tables. The
   legacy payload column can be dropped in a future cleanup migration. No rush.

5. **Should the content service live in a separate Postgres schema (`content`) or
   alongside existing tables?** A separate schema makes the boundary explicit,
   allows separate backup/restore policies, and prevents accidental joins across
   the boundary. Recommendation: `content` schema.

---

## 11. Summary: one sentence per decision

- **Platform identity in code** — because platforms differ in behavior, and code
  models behavior better than database rows.
- **Category-level inheritance** — because changing "all video platforms refresh
  every 6 hours" should be one edit, not fifteen. Category defaults merge at
  registration time; platforms override only what differs.
- **Content in a pooled item model** — because users' data needs querying, sorting,
  deduplication, and merging across platforms, and normalized tables do that better
  than JSONB blobs.
- **Raw payloads preserved verbatim** — because re-normalization without re-fetching
  is the foundation that makes scraper→API swaps safe.
- **Fetchers inherit from ~5 base classes** — because there are only ~5 ways to get
  data from any platform, and the leaf classes should be 20-40 lines of field mapping.
- **Normalizers separate from fetchers** — because the data shape can change
  independently of the transport mechanism.
- **Pools as a PHP enum** — because pools are a product concept, not user-generated
  data, and they change at code-deploy speed.
- **CQRS-lite for reads** — because profile pages should read from a pre-built cache,
  not join across content tables at render time.
