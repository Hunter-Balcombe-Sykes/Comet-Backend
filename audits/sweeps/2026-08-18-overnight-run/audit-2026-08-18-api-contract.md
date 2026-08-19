# API Contract & Resource Leakage Audit — 2026-08-18

**Branch:** HEAD
**Lens:** API Contract & Resource Leakage: raw model fields bleeding through, over-fetching, inconsistent pagination
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Http/Resources/Routing/RoutingConnectionResource.php
- app/Http/Controllers/Api/Platforms/DisplaySettingsController.php
- app/Http/Controllers/Api/Platforms/FreshaController.php
- app/Http/Controllers/Api/Platforms/GenericPlatformController.php
- app/Http/Controllers/Api/Platforms/RefreshController.php
- app/Http/Controllers/Api/Routing/RoutingController.php
- app/Site/Pools/PoolResolver.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/Analytics/ContentPopularityReader.php
- app/Console/Commands/ComputeContentPopularityScores.php
- app/Models/Core/Site/IntegrationConnection.php (verification only)

## Progress

- P1 High: 0 of 1 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 3 complete

---

## P1 — Fix before pilot launch

- [ ] **API-1** · P1 — Pool item payload mixes ISO-8601 and naive local timestamps on the public wire
    - **Where:** app/Site/Pools/PoolResolver.php:584, 834-835, 860
    - **Affects:** Every unauthenticated public sitepage visitor viewing dated content — `publishedAt` (all pools), `firstSeenAt` (all pools), and `startsAt` (events/menus pools). Also the owner's dashboard, which reads the same `resolve()` output.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply the same `Carbon::parse(...)->toIso8601String()` conversion already used for `sources[].lastSeenAt`/`lastSyncedAt` to `publishedAt`, `firstSeenAt`, and `startsAt` in the `$out[$itemId]` array.
        - Add a wire-shape assertion (alongside `PoolWireShapeTest`) that every top-level datetime field on a pool item contains a UTC offset.
    - **Technical:** The `sources` sub-builder explicitly converts naive Postgres `Y-m-d H:i:s` strings to ISO-8601-with-zone via the local `$iso` closure at line 584, with an inline comment noting the exact failure mode: "a browser's `Date()` would read [it] as LOCAL time (a +10h badge — review)." Commit `27cfe5c87` ("review fixes (W6/W8) … source timestamps go out ISO-8601") shipped that fix for the nested `sources[]` array only. The three top-level fields that carry the same query-builder-sourced naive strings — `publishedAt` (from `content.f_published`), `firstSeenAt` (from `content.items.first_seen_at`), and `startsAt` (from `content.f_occurrence`) — were never converted, so the identical bug the review fix targeted survives on the fields visitors actually see: an event's start time and a post's published date. This is not a rare-collision hardening gap; it fires on every request for every site with dated content, which is the documented common case for the events/menus/watch/listen pools.
    - **Plain English:** Imagine printing an event's start time on a flyer but forgetting to say which time zone it's in. Some computers reading that flyer will guess wrong and show the event several hours early or late. The team already fixed this for one part of the page (which platform posted something) but not for the actual "starts at" and "published" times visitors rely on to know when something is happening.
    - **Evidence:**
        ```php
        'publishedAt' => $ov('f_published.published_from', $published[$itemId] ?? null),
        'firstSeenAt' => $item->first_seen_at,
        ...
        'startsAt' => $occursAt[$itemId] ?? null,
        ```
        ```php
        // Timestamps go out as ISO-8601 with zone: the query builder
        // hands back naive "Y-m-d H:i:s" strings which a browser's
        // Date() would read as LOCAL time (a +10h badge — review).
        $iso = fn ($v) => $v === null ? null : Carbon::parse((string) $v)->toIso8601String();
        ```

## P2 — Should fix

- [ ] **API-2** · P2 — The full site-wide popularity map is placed on the public wire without filtering to currently-live content
    - **Where:** app/Services/PublicSite/IndividualProfilePayloadBuilder.php:95, 132-135; app/Services/Analytics/ContentPopularityReader.php:33-51
    - **Affects:** Unauthenticated public profile visitors; content identifiers of items the owner has since hidden, unpublished, or removed.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Filter `$ranks` (or `ContentPopularityReader::forSite()`) down to `content_key`s that are actually present in the resolved `pools`/`page_order`/`ranked_actions` for this build, before assigning it to `'popularity'`.
        - Alternatively, delete a `content_popularity_scores` row immediately when its owning item is removed/unpublished, rather than relying on multi-cycle score decay to fade it out.
        - Add a regression test asserting a removed/hidden item's `content_key` never appears in `popularity` after removal, even inside the current fade window.
    - **Technical:** `ContentPopularityReader::forSite()` reads every row in `analytics.content_popularity_scores` for the site with no join back to `content.items.removed_at` or any pool-selection/liveness filter — it is a pure `site_id` + `content_type != 'action'` scan. `ComputeContentPopularityScores` fades a stale key out gradually (0.7·new + 0.3·prev blend, deleted only once the blended score drops below `SCORE_FLOOR = 0.05`), so a `content_key` for an item the owner just hid, unpublished, or deleted can persist in the scores table — and therefore in the public `popularity` map — for multiple 15-minute compute cycles after it stopped being served anywhere else in the payload. `IndividualProfilePayloadBuilder::build()` assigns this raw map straight to the public wire key `popularity` with no cross-check against `pools`/`page_order`. The per-item `popularityRank` annotations already used elsewhere are scoped correctly (only selected items carry a rank); this full map is not.
    - **Plain English:** Behind the visible page, the site keeps a scoreboard ranking everything a visitor has ever engaged with. When an owner hides or deletes something, it should vanish from view completely — but the scoreboard entry lingers for a while, and the whole scoreboard (not just what's shown on the page) currently ships to every visitor. Someone reading the raw page data could learn that an item still exists and how popular it was, even though the owner already took it down.
    - **Evidence:**
        ```php
        // One indexed read of content_popularity_scores per build (behind the 60s
        // public-profile cache). Ranks ANNOTATE the content arrays + drive
        // pageOrder — arrays are NEVER reordered (live architectures read them
        // positionally). Empty maps when scoring hasn't run for the site.
        $ranks = $this->popularity->forSite($site?->id);
        ```
        ```php
            // Full popularity map (content_type => content_key => rank) so the ONE
            // theme can order ANY item type uniformly, without per-platform payload
            // surgery. Same $ranks the per-item annotations below already use.
            'popularity' => $ranks,
        ```
        ```php
        $rows = DB::connection('pgsql')
            ->table('analytics.content_popularity_scores')
            ->where('site_id', $siteId)
            ->where('content_type', '!=', RankedActionsComputer::CONTENT_TYPE)
            ->orderBy('content_type')
            ->orderBy('rank')
            ->get(['content_type', 'content_key', 'rank']);
        ```

- [ ] **API-3** · P2 — A `QueryException` while resolving any one pool silently drops every pool from the public profile response
    - **Where:** app/Services/PublicSite/IndividualProfilePayloadBuilder.php:240-251
    - **Affects:** Unauthenticated public profile visitors; clients rendering the profile's watch/listen/media/menus/shop/services/events/custom-links pools.
    - **Technical:** `buildPools()` loops over `PoolRegistry::POOLS`, but `catch (QueryException) { return []; }` returns from the whole method on the first failure, not just the failed pool, and the exception is neither logged nor reported to Nightwatch. A partial database fault (one lane's table temporarily unreachable) is therefore indistinguishable, at the HTTP layer, from "this owner selected no content for any pool" — a 200 that looks identical to a legitimately empty profile. CLAUDE.md's observability contract is explicit that a failure needing attention "must throw or `$this->fail($e)`"; a bare swallow here means Nightwatch never sees it. The comment justifying the catch ("Partial test envs may not provision the content/sections tables … in production they always exist") is about *why* the guard exists for tests, not a case for silencing a genuine production fault the same way.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the `try`/`catch` inside the `foreach` so a single pool's failure is skipped (`continue`) without discarding the others already resolved.
        - Log or `report()` the caught exception so a real production fault still reaches Nightwatch, even though the response degrades gracefully.
    - **Plain English:** If one shelf in the stockroom can't be checked, the store currently puts up an "everything's empty" sign for the *entire* store and keeps the lights on — a visitor can't tell "the owner has nothing to show" apart from "something broke behind the scenes." Worse, nobody on the team gets notified when this happens, so a real outage could go unnoticed.
    - **Evidence:**
        ```php
        private function buildPools(?Site $site): array
        {
            if (! $site) {
                return [];
            }

            $out = [];
            foreach (array_keys(PoolRegistry::POOLS) as $pool) {
                try {
                    $resolved = $this->pools->resolve($site, $pool);
                } catch (QueryException) {
                    // Partial test envs may not provision the content/sections
                    // tables (the getContentMedia precedent); in production they
                    // always exist. A missing lane yields no pools, never a 500.
                    return [];
                }
        ```

## P3 — Nice to have

- [ ] **API-4** · P3 — `POST /api/routing/links` omits the `unrolled` key on the normal routing path
    - **Where:** app/Http/Controllers/Api/Routing/RoutingController.php:81-93, 147
    - **Affects:** Dashboard clients parsing the `POST /api/routing/links` response.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'unrolled' => false` to the normal-path success envelope (`['status' => $status, 'outcome' => $outcome, 'unrolled' => false] + $result`).
    - **Technical:** The link-in-bio branch (URL detected as a linktr.ee/beacons.ai/etc. page) returns a 202 body that includes `'unrolled' => true`. The normal routing branch's 202 body is `['status' => $status, 'outcome' => $outcome] + $result` with no `unrolled` key at all. A client that wants to know whether a submission was unrolled must use `?? false` defensively rather than reading the field directly — a minor same-endpoint shape inconsistency, category 5.
    - **Plain English:** Imagine a form that sometimes includes a checkbox and sometimes leaves it off the page entirely depending on what you typed. The app reading the response has to guess whether "no checkbox" means "unticked" or "this version of the form doesn't have one." Always printing the checkbox, ticked or not, removes the guesswork.
    - **Evidence:**
        ```php
        return $this->success([
            'status' => 'pending',
            'outcome' => in_array($write['status'] ?? null, ['created', 'exists'], true) ? 'link' : null,
            ...
            'unrolled' => true,
        ], 202);
        ```
        ```php
        return $this->success(['status' => $status, 'outcome' => $outcome] + $result, 202);
        ```

- [ ] **API-5** · P3 — `GET` display-settings returns default toggles when no active connection exists, but `PATCH` returns 404 for the same state
    - **Where:** app/Http/Controllers/Api/Platforms/DisplaySettingsController.php:59-74 (show), 104-106 (update)
    - **Affects:** Dashboard clients calling `GET /platforms/{platform}/display-settings` before connecting an integration — they cannot tell "connected with default toggles" apart from "not connected" from the GET response alone, while PATCH disagrees.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Make `show()` return the same 404 as `update()` when `$rows->isEmpty()` (i.e. no active connection for the platform), or add an explicit `connected: bool` field so both endpoints agree on resource existence without changing the HTTP status contract.
    - **Technical:** `show()` only checks whether the descriptor declares display toggles; if the user has no active connection row for the platform, `$rows` is empty, the `foreach` `continue`s past every toggle, and `shapeToggles()` fills every entry from its declared default — returning a fully-formed, valid-looking 200 payload. `update()` explicitly 404s in the identical no-connection state. Per CLAUDE.md, public/unowned-resource semantics should be consistent (404 = doesn't exist), and here the read and write paths disagree about whether the resource exists at all.
    - **Plain English:** It's like calling a restaurant and hearing the full seating chart with default table settings, even though the restaurant hasn't opened yet. But if you try to actually book a table, the system says "restaurant not found." The two answers disagree about whether the restaurant exists.
    - **Evidence:**
        ```php
        $stored = [];
        foreach ($defs as $def) {
            $key = $def['key'];
            if ($rows->isEmpty()) {
                continue;
            }
        ```
        ```php
        if ($connections->isEmpty()) {
            return $this->error('Connect this integration first.', 404);
        }
        ```

- [ ] **API-6** · P3 — PATCH display-settings response reflects only the last connection saved, while GET reports the effective any-on state across all connections
    - **Where:** app/Http/Controllers/Api/Platforms/DisplaySettingsController.php:59-74 (show), 118-143 (update)
    - **Affects:** Owner dashboard for multi-account platforms with display toggles (e.g. YouTube with several connected channels) — the PATCH confirmation can show a different toggle state than the very next GET.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Reshape the PATCH response using the same "any row has it on" merge `show()` uses, computed across all `$connections` after the save loop, instead of `$merged = $current` inside the loop (which leaves only the last-iterated row's state).
    - **Technical:** `show()`'s explicit contract (documented in the class's own comment block) is: a toggle reads ON if ANY active row has it on (absent = default), OFF only when every row says false — because `update()` writes every account of the platform. But inside `update()`'s save loop, `$merged = $current;` is reassigned on every iteration, so after the loop `$merged` holds only the final connection's per-row settings, not the any-on aggregate. If two rows diverge on an untouched toggle, the PATCH response can show a state that the immediately following GET contradicts.
    - **Plain English:** Imagine a room with several light switches that together control one light, and the status page says the light is "on" if any switch is flipped on. After you change one switch, though, the confirmation screen only reports that one switch's position, not the room's actual light state — so the app can say "off" right after saving, while reloading the settings page still correctly says "on."
    - **Evidence:**
        ```php
        $anyOn = $rows->contains(fn (array $settings) => (bool) ($settings[$key] ?? $default) === true);
        $stored[$key] = $anyOn;
        ```
        ```php
        foreach ($connections as $connection) {
            $current = (array) ($connection->display_settings ?? []);
            foreach ($incoming as $key => $enabled) {
                if ((bool) $enabled === ($defaultByKey[$key] ?? true)) {
                    unset($current[$key]);
                } else {
                    $current[$key] = (bool) $enabled;
                }
            }
            $connection->display_settings = $current === [] ? null : $current;
            $connection->save(); // observer → cache purge + payload rebuild
            $merged = $current;
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Public profile payload correctness:** API-1, API-2, API-3
    - **Why grouped:** All three sit in the `IndividualProfilePayloadBuilder`/`PoolResolver` pipeline that builds `GET /api/public/profiles/{handle}`; each is a wire-correctness or fail-open defect on the same public payload build path.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

- **Bundle 2 — DisplaySettingsController GET/PATCH parity:** API-5, API-6
    - **Why grouped:** Same file, same root cause — `show()` and `update()` were written against slightly different empty/multi-row semantics and now disagree with each other.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

- **Bundle 3 — Routing response envelope polish:** API-4
    - **Why grouped:** Single small, isolated fix; a good candidate to fold into whichever session next opens `RoutingController.php` under CLAUDE.md's opportunistic-fix policy rather than running its own session.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

None.
