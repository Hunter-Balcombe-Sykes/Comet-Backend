# Architecture handoff — why the platform registry lives in code, not a database

**Date:** 2026-07-27 · **From:** Josh (backend) · **For:** design / frontend

When we add a new platform (say, BlueSky), we add a few lines of PHP in one file. That's it.
No database migration, no config changes, no touching five different files.

This doc explains why we landed on this approach instead of storing platforms in the
database or using other patterns. The short answer: **platforms differ in behavior, not
data — and code models behavior better than database rows.**

---

## What we chose

A **registry pattern** — one file (`PlatformRegistryServiceProvider.php`) where every
platform is declared as a short block of code:

```php
// A simple platform (link-only — store a URL, nothing else):
PD::linkOnly('tiktok', 'TikTok', LinkConnectionResource::class),

// A complex platform (scraped feed, async refresh, content highlights):
PD::make('youtube')
    ->label('YouTube')
    ->category(PlatformCategory::Content)
    ->connect(new UrlConnect(...), 'Not a valid YouTube URL')
    ->fetch(fn () => new YoutubeFetch(...))
    ->highlights(fn () => new YoutubeHighlights(...))
    ->refreshable(true)
    ->resource(YoutubeConnectionResource::class);
```

Each block tells you everything about that platform: what it's called, what category it
belongs to, how users connect it, whether it auto-refreshes, what data it produces.
One place. No looking at five files to understand one platform.

---

## Why not a database?

V5 of the platform system (on a branch) stores platform definitions as rows in a
`platform_definitions` table. It sounds logical — "platforms are data, put them in the
database." The problem is that platforms are mostly **behavior**, not data.

A platform needs to:
- Know how to fetch data (scrape a website, call an API, dispatch an Apify actor)
- Know how to validate a URL the user pastes in
- Know how to refresh on a schedule
- Know which content pool its data feeds into

None of that can be stored in a database row. It's code. So a database-driven system
ends up with the same information in **two places** — a row that says "YouTube exists"
and a scraper class that actually knows what YouTube means. Two sources of truth for
the same thing means one of them is always wrong, or at least out of date.

**The rule of thumb:** if two platforms differ in WHAT THEY DO, use code. If they differ
in WHAT DATA THEY STORE, use the database. Platforms differ in behavior. Code wins.

(Content — users' actual posts, tracks, videos — IS data and DOES belong in the
database. That's a separate layer. This is just about defining what platforms exist.)

---

## Why not the alternatives?

### Config arrays (`config/platforms.php`)

Looks appealingly simple — just a big array of platform settings. But arrays have no
types. Nothing stops `'catagory' => 'socail'` from shipping. And arrays can't hold
behavior — you can't put a Closure or a strategy object in a config file. You end up
writing string references like `'fetch_strategy' => YoutubeFetch::class` and then
building a resolver that turns those strings back into objects. The resolver becomes
the actual registry, just hidden somewhere else. Two places to look, again.

### PHP 8 Attributes (`#[Platform('youtube')]`)

Each platform class self-registers by carrying an attribute. The framework scans
files at boot and discovers them automatically. Great pattern for plugin ecosystems
where third-party developers drop in unknown classes. Partna has one developer and
~80 known platforms — nobody is dropping in surprise platform definitions. Attributes
add magic (why isn't this platform loading? check the attribute, the scanner config,
the cache) for a problem we don't have.

### Class-per-platform

Every platform extends a base `Platform` class with typed properties. Cleanly separates
Spotify from YouTube. But the 22 link-only platforms (TikTok, Facebook, X, LinkedIn...)
differ only in name and icon. They don't need 22 class files. The fluent descriptor
handles both cases — one line for simple platforms, a full chain for complex ones — in
the same pattern.

### Manager/Driver (Laravel's built-in pattern)

Every platform is a "driver" behind a uniform interface — like how Laravel's cache
system has `file` and `redis` drivers that both implement `get()`/`put()`. This works
when every driver shares the same contract. Partna's platforms don't. Instagram is an
async Apify job. LinkedIn is just a URL. Fresha is a multi-step picker. They share a
set of optional capabilities (some can fetch, some can refresh, some have highlights),
not one uniform interface.

---

## What this means for the frontend

**Nothing changes.** The API contract is frozen. Every endpoint returns byte-identical
JSON, proven by golden-master tests. The registry is a backend-internal refactor —
same data, same routes, same responses.

When you need to know "does this platform support X?" ask for the data, and the
backend will tell you. The registry just makes it so adding platform #31 is one
line instead of a six-file change.

---

## The decision in one sentence each

- **V4 registry for platform identity** — because platforms are behavior, and code
  models behavior better than database rows or config arrays.
- **Database for content storage** — because users' actual data needs querying,
  sorting, and merging, and normalized tables do that better than JSON blobs.
- **Config for environment settings** — API keys, rate limits, feature flags.
- **Never two sources of truth for the same fact** — that's the principle everything
  else follows from.
