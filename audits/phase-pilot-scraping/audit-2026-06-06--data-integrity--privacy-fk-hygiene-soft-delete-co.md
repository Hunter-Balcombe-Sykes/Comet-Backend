`★ Insight ─────────────────────────────────────`
Key verification findings from tool checks:
1. **DINT-6 (no purge mechanism) is factually wrong** — `IntegrationConnection::class` is listed in `PurgeSoftDeleted::PURGE_HANDLED`, and `partna:purge-soft-deletes` runs daily at 03:20 in `routes/console.php`. Drop this finding.
2. No `IntegrationConnectionResource` class exists — confirming DINT-5's raw-payload claim.
3. `IntegrationConnectionObserver::deleted()` only fires `CloudflareCachePurgeJob`, no R2 cleanup — confirming DINT-4.
`─────────────────────────────────────────────────`

# Data Integrity & Privacy Audit — 2026-06-06

**Branch:** development
**Lens:** Data integrity & privacy: FK hygiene, soft-delete coherence, orphan rows, JSONB drift, PII inventory, retention
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- supabase/migrations/20260602150238_create_platform_connections.sql
- app/Models/Core/Site/IntegrationConnection.php
- app/Http/Controllers/Api/Platforms/ShopifyController.php
- app/Http/Controllers/Api/Platforms/FreshaController.php
- app/Http/Controllers/Api/Platforms/AppleController.php
- app/Http/Controllers/Api/Platforms/YoutubeController.php
- app/Http/Controllers/Api/Platforms/InstagramController.php
- app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php
- app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php
- app/Services/Platforms/PlatformRefresher.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Console/Commands/PurgeSoftDeleted.php
- routes/console.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#DINT-3** · P1 — Apple Music / Podcast + YouTube highlights have no concurrency guard — concurrent saves from the same user silently lose one set of selections
    - **Where:** app/Http/Controllers/Api/Platforms/AppleController.php:musicHighlights, podcastHighlights; app/Http/Controllers/Api/Platforms/YoutubeController.php:highlights
    - **Affects:** Professionals curating Apple Music, Apple Podcast, or YouTube highlights — a second dashboard tab saving a different pick set overwrites the first silently.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the read→mutate→write cycle in each highlights method with a per-user, per-platform Redis lock: `Cache::lock("platforms:{platform}:lock:{$user->id}", 10)->block(5, ...)`.
        - Release the lock in a `finally` block so a 502 from the scraper still releases the guard.
        - `put()` / `writeConnection()` can remain as-is; only the controller-level orchestration needs guarding.
    - **Technical:** `musicHighlights()` reads the selection via `$this->read($user, self::MUSIC)`, merges the refreshed latest-tile and new highlights into the in-memory array, then writes back via `$this->put()` → `IntegrationConnection::updateOrCreate`. Between the `read` and the `put`, a concurrent request can read the same stale payload, apply its own highlights choice, and write — the last writer wins and the earlier save is silently discarded. The `updateOrCreate` is atomic at the DB row level but cannot protect the in-row JSONB mutation. `podcastHighlights()` and `YoutubeController::highlights()` are structurally identical.
    - **Plain English:** A user has two dashboard tabs open — one tab saves a set of YouTube highlight videos while the other saves a different set. Whichever tab finishes last wins, and the first tab's picks vanish without any error message. A simple "one writer at a time" lock prevents the two tabs from stepping on each other.
    - **Evidence:**
        ```php
        // AppleController::musicHighlights — read, mutate, write with no lock
        $selection = $this->read($user, self::MUSIC);
        // ... modify $selection['highlights'], refresh $selection['latest'] ...
        $this->put($user, self::MUSIC, $selection);
        ```
        ```php
        // YoutubeController::highlights — same pattern
        $selection = $this->readConnection($user);
        // ... modify $selection['highlights'], refresh latest fields ...
        $this->writeConnection($user, $selection);
        ```

- [ ] **#DINT-2** · P1 — Fresha service-visibility toggle has no concurrency guard — concurrent show/hide of different services silently loses one toggle
    - **Where:** app/Http/Controllers/Api/Platforms/FreshaController.php:setServiceVisibility
    - **Affects:** Professionals using the Fresha booking integration — toggling services from multiple sessions can cause one toggle to silently disappear.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `setServiceVisibility()` with a per-user Redis lock: `Cache::lock("platforms:fresha:lock:{$user->id}", 10)->block(5, ...)`, released in `finally`.
        - Alternatively, replace the full-payload rewrite with a targeted PostgreSQL `jsonb_set` update on `payload->'selection'->'hiddenServiceIds'` to make the mutation atomic at the DB layer without needing a distributed lock.
    - **Technical:** `setServiceVisibility()` reads the full connection payload via `$this->readConnection($user)`, mutates `selection['hiddenServiceIds']`, then writes the entire payload back via `$this->writeConnection()` → `updateOrCreate`. Two concurrent dashboard sessions toggling different services each read a stale `hiddenServiceIds` array and write back their version — the second write silently overwrites the first. The partial unique index `idx_platform_connections_unique_active` protects row-level uniqueness but not intra-row JSONB correctness.
    - **Plain English:** A professional uses two browser tabs to manage their Fresha listing — one tab hides a haircut service, another tab shows a colour service. If both saves land close together, whichever tab finishes last wins and the other tab's change is lost, leaving the public page in the wrong state with no error shown.
    - **Evidence:**
        ```php
        // FreshaController::setServiceVisibility — full payload read → mutate → rewrite
        $payload = $this->readConnection($user);
        $selection = data_get($payload, 'selection');
        // ... toggle $selection['hiddenServiceIds'] ...
        $this->writeConnection($user, ['url' => data_get($payload, 'url'), 'selection' => $selection]);
        ```

- [ ] **#DINT-1** · P1 — Shopify multi-brand map has no concurrency guard — concurrent brand writes can silently overwrite each other
    - **Where:** app/Http/Controllers/Api/Platforms/ShopifyController.php:addBrand, setProducts, removeBrand, updateBrand
    - **Affects:** Professionals managing Shopify brands — concurrent dashboard actions (e.g. adding two brands quickly, or opening two tabs) can silently drop a brand or revert a product selection.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap every brand-mutation method with a per-user Redis lock: `Cache::lock("platforms:shopify:lock:{$user->id}", 10)->block(5, ...)`, released in `finally`.
        - Lock scope must span the full `brandMap()` read through the `writeConnection()` write.
        - Emit a 423 / 409 response if the lock times out so the dashboard can retry.
    - **Technical:** All four mutation methods follow the same pattern: `$map = $this->brandMap($user)` reads the entire brand map from the `payload` JSONB column, mutates the in-memory PHP array, then calls `$this->writeConnection($user, $map)` → `IntegrationConnection::updateOrCreate`. Between the read and the write, a second request can read the same stale map, apply its own change, and write — the last write wins and silently discards the first. The `updateOrCreate` is atomic at the Postgres row level but both callers are mutating the same JSON blob, so one caller's changes are invisible to the other. This is especially dangerous for `addBrand` + `setProducts` running concurrently from two tabs during initial setup.
    - **Plain English:** Adding brands works like editing a shared document — if two people (or two browser tabs) open the document, make different changes, and both click save, only the last save survives. The first person's change is silently overwritten, and their brand might vanish from the list with no error shown. A "do not disturb" lock while each save is in progress prevents this.
    - **Evidence:**
        ```php
        // ShopifyController::addBrand — reads full brand map, mutates, writes back
        $map = $this->brandMap($user);
        // ... check cap, build brand entry ...
        $map[$id] = [...];
        $this->writeConnection($user, $map);
        ```
        ```php
        // Same read→write pattern in removeBrand
        $map = $this->brandMap($user);
        unset($map[$id]);
        $this->writeConnection($user, $map);
        ```

---

## P2 — Should fix

- [ ] **#DINT-4** · P2 — Instagram mirrored images are never deleted when a connection is removed or purged
    - **Where:** app/Http/Controllers/Api/Platforms/InstagramController.php:forget; app/Observers/Core/IntegrationConnectionObserver.php; app/Console/Commands/PurgeSoftDeleted.php
    - **Affects:** R2 storage costs — every connect/disconnect cycle leaves a `platforms/instagram/{timestamp}/` folder of orphaned image and profile-picture files in object storage that are never reclaimed.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Store the R2 folder path in the payload at write time: add `'_folder' => $folder` inside `buildSelection()` alongside the other payload keys.
        - In `IntegrationConnectionObserver`, listen to `forceDeleted()` (in addition to the existing `deleted()`) and dispatch a queued job that reads `payload['_folder']` and deletes all objects under that prefix from `Storage::disk('media')`.
        - Add the same cleanup to a soft-delete observer hook (or in `forget()` directly) so objects are removed at disconnect time rather than waiting for the 30-day purge.
    - **Technical:** `connect()` and `saveSelection()` mirror Instagram CDN images to R2 via `mirrorAll()` under `platforms/instagram/{timestamp}/`, and mirror the profile picture to `{folder}/profile.jpg`. The folder path is ephemeral — it exists only inside the stored R2 public URLs in `payload['images']` and `payload['profilePicUrl']`, with no first-class `_folder` column. When `forget()` calls `$this->forgetConnection()` → `SoftDeletes::delete()`, the DB row is marked `deleted_at` but the R2 objects are untouched. `PurgeSoftDeleted` (which includes `IntegrationConnection::class` in `PURGE_HANDLED` and runs daily) calls `forceDelete()` on the row after 30 days, but this too makes no R2 API call. `IntegrationConnectionObserver::deleted()` fires on soft-delete and already dispatches `CloudflareCachePurgeJob` — extending it to also queue R2 cleanup (gated on `platform === 'instagram'`) is the natural hook.
    - **Plain English:** Every time someone connects their Instagram, we copy their recent photos into our image storage. When they disconnect, we mark the connection as removed in our database — but the photo copies stay in storage forever, like leaving luggage at an airport after your flight has left. Each connect/disconnect cycle adds another unclaimed folder. Storing the folder name when we create it, and using that name to clean up the files when the connection is removed, closes the leak.
    - **Evidence:**
        ```php
        // Images mirrored to R2 — folder path not stored separately
        $folder = 'platforms/instagram/'.now()->timestamp;
        $images = $this->mirrorAll($coverUrls, $folder);
        $selection = $this->buildSelection($username, $profile, $folder, 'automatic', $images, ...);
        $this->writeConnection($user, $selection);
        ```
        ```php
        // forget() soft-deletes the row only — no storage cleanup
        public function forget(Request $request): JsonResponse
        {
            $this->forgetConnection($this->currentUser($request));
            return $this->success(['selection' => null]);
        }
        ```
        ```php
        // Observer::deleted() only purges Cloudflare edge cache, not R2 objects
        public function deleted(IntegrationConnection $connection): void
        {
            $this->purge($connection); // → CloudflareCachePurgeJob only
        }
        ```

- [ ] **#DINT-5** · P2 — Public integration endpoint returns raw JSONB payload without an explicit allowlist — inadvertently scraped PII or internal keys reach the unauthenticated CDN-cached edge
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php:52–61
    - **Affects:** All sitepage visitors — scraped upstream content (YouTube video descriptions containing contact info, Eventbrite organiser details, Instagram captions, Fresha employee data) is served verbatim via a public, unauthenticated endpoint with aggressive CDN caching; any PII the scraper captured becomes publicly cached.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a `PublicIntegrationPayloadResource` (or per-platform shaping inside the controller) that enumerates the fields each platform's `payload` is allowed to expose publicly.
        - For platforms that store internal housekeeping keys (e.g. `_folder` once DINT-4's fix lands, `imagesDropped`, scraper metadata fields), explicitly exclude them in the public shape.
        - Document the public payload contract per platform alongside `docs/api.md` so future platform additions are forced to declare their public surface.
    - **Technical:** `PublicIntegrationController::show()` fetches all active connections, selects `['platform', 'resource_id', 'payload', 'last_refreshed_at']`, and returns `$r->payload` directly in the map lambda — zero transformation. The `payload` JSONB column stores the raw scraped blob as shaped by each controller; its structure is undocumented and unbounded. A YouTube description can legally contain a creator's booking email address. An Eventbrite event can contain organiser phone numbers. A Fresha scrape includes employee display names and job titles. While all of this data was scraped from public sources, serving it via a public API endpoint with `cf: {cacheEverything: true}` (per `CloudflarePurgeService::purgeHandle`) amplifies reach and persistence beyond the originating platform. This also violates the codebase architecture rule ("Resource classes for all API responses — never raw Eloquent").
    - **Plain English:** The public page for any Partna user directly republishes whatever was scraped from their connected platforms — word for word. If a YouTube video description contains an email address or phone number, that gets served through Partna's public API and cached by Cloudflare for five minutes. It's like scanning every document in someone's filing cabinet and posting it on a public noticeboard, rather than carefully choosing what to display. A curated list of which fields are safe to show publicly would prevent accidents.
    - **Evidence:**
        ```php
        // payload returned verbatim — no Resource transform, no field selection
        ->get(['platform', 'resource_id', 'payload', 'last_refreshed_at'])
        ->groupBy('platform')
        ->map(fn ($rows) => $rows->map(fn (IntegrationConnection $r) => [
            'resourceId' => $r->resource_id,
            'payload'    => $r->payload,          // raw JSONB blob
            'lastRefreshedAt' => $r->last_refreshed_at?->toIso8601String(),
        ])->values())
        ```

---

## P3 — Nice to have

- [ ] **#DINT-6** · P3 — `PlatformRefresher` returns `null` for both "bad payload shape" and "network failure" — debugging refresh failures is unnecessarily hard
    - **Where:** app/Services/Platforms/PlatformRefresher.php:youtubePayload, eventbritePayload, appleMusicPayload, applePodcastPayload
    - **Affects:** On-call visibility — when `integrations:refresh` marks a connection `unavailable`, there is no way to distinguish a missing `handle` key (corrupt payload) from a live scrape failure (network/upstream down) in Nightwatch.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In each `*Payload` private method, distinguish the "missing required key" early-return from the "scraper returned empty/null" return by using `last_refresh_status = 'error'` with a `last_refresh_error = "missing_key: handle"` message for shape problems, reserving `'unavailable'` for upstream scraper failures.
        - Log a `Log::warning` at the shape-failure branch with the platform and connection ID so Nightwatch can surface it without treating it as a normal scraper outage.
    - **Technical:** Each private handler uses `$payload['key'] ?? null` followed by `if (! $key) { return null; }`. The `null` return feeds into `refresh()` → `'last_refresh_status' => 'unavailable'` — the same status used when a live network call to YouTube or Eventbrite fails. A DB hand-edit, a bad migration backfill, or a future platform controller change that renames a key all produce the same `unavailable` outcome as a genuine outage. Separating shape errors from network errors requires only one extra branch in each handler.
    - **Plain English:** When the nightly update job marks a YouTube connection as "unavailable," it looks exactly the same whether the YouTube scraper was actually down or whether someone accidentally deleted a field from the stored data. Adding a one-line check that says "this is a data problem, not a network problem" makes debugging much faster when something breaks.
    - **Evidence:**
        ```php
        // youtubePayload — missing 'handle' is indistinguishable from a scrape failure
        private function youtubePayload(array $payload): ?array
        {
            $handle = $payload['handle'] ?? null;
            if (! $handle) {
                return null;  // same path as fetchRecentVideos() returning null
            }
            // ...
        }
        ```

- [ ] **#DINT-7** · P3 — `created_at` and `updated_at` columns have no `DEFAULT now()` — rows inserted outside Eloquent get NULL timestamps
    - **Where:** supabase/migrations/20260602150238_create_platform_connections.sql
    - **Affects:** Data integrity for any `site.platform_connections` row inserted via raw SQL, `DB::table()->insert()`, migrations, or bulk-import scripts — `created_at`/`updated_at` would be NULL, breaking any query that orders or filters by these columns.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a new migration:
          ```sql
          ALTER TABLE site.platform_connections
              ALTER COLUMN created_at SET DEFAULT now(),
              ALTER COLUMN updated_at SET DEFAULT now();
          ```
        - All current code paths go through Eloquent (`BaseModel` + `timestamps = true`) so no existing rows are affected; this is purely a defensive guardrail for future non-Eloquent writes.
    - **Technical:** The migration declares both timestamp columns as bare `timestamptz` with no `DEFAULT` clause. Eloquent's model layer auto-populates these on create/update, so all current application paths are safe. However, `PurgeSoftDeleted::purgeModel()` calls `$row->forceDelete()` (Eloquent), `DB::table(...)->insert()` in tests, and any future raw-SQL migration seeding data directly would produce NULL timestamps. The `RefreshIntegrationConnectionsCommand` orders by `last_refreshed_at ASC NULLS FIRST` which already handles NULLs, but `created_at`-based ordering or age-based queries would silently mis-sort NULL-timestamp rows.
    - **Plain English:** The "date created" and "date updated" fields for platform connections rely entirely on the application to fill them in when saving. If anyone ever adds a row directly in the database — during a data migration, a quick fix, or a future script — those fields would be left blank. It takes one line in a migration to make the database fill them in automatically, which prevents a subtle future headache.
    - **Evidence:**
        ```sql
        -- No DEFAULT clause on either timestamp column
        created_at            timestamptz,
        updated_at            timestamptz,
        deleted_at            timestamptz
        ```
