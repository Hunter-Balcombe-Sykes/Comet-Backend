SEM-1 is confirmed: `youtubePayload()` lines 73-82 clearly omit `'latest' => $latest`, while `appleMusicPayload()` line 120 and `applePodcastPayload()` line 142 both include it. The asymmetry is unambiguous.

`★ Insight ─────────────────────────────────────`
The Apple refreshers use `...$payload` spread as the base (preserving all existing keys including `latest`, `input`, and any future additions) before overriding only the refreshed fields. YouTube reconstructs the array from scratch — a structurally different approach that requires every key to be enumerated explicitly, which is exactly how `latest` got left out. The spread pattern is more resilient to future payload-shape additions.
`─────────────────────────────────────────────────`

# Code Quality Audit — 2026-06-06

**Branch:** development
**Lens:** Bundle 'code-quality' audit across 2 focused themes: AI slop & low-value code (SLOP-*) and semantic correctness — type-valid-but-wrong behaviour (SEM-*). SLOP is a taste/maintainability pass graded against CLAUDE.md house style; SEM hunts plausible-but-wrong logic that compiles and passes Larastan.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Services/Platforms/PlatformRefresher.php`
- `app/Http/Controllers/Api/Platforms/AppleController.php`
- `app/Http/Controllers/Api/Platforms/YoutubeController.php`
- `app/Http/Controllers/Api/Platforms/FreshaController.php`
- `app/Http/Controllers/Api/Platforms/FacebookController.php`
- `app/Http/Controllers/Api/Platforms/InstagramController.php`
- `app/Http/Controllers/Api/Platforms/ShopifyController.php`
- `app/Http/Controllers/Api/Platforms/TiktokController.php`
- `app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php`
- `app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php`
- `app/Services/Platforms/AppleSearch.php`
- `app/Services/Platforms/YoutubeScraper.php`
- `app/Services/Platforms/EventbriteScraper.php`
- `app/Models/Core/Site/IntegrationConnection.php`
- `app/Policies/IntegrationConnectionPolicy.php`
- `app/Observers/Core/IntegrationConnectionObserver.php`
- `app/Console/Commands/RefreshIntegrationConnectionsCommand.php`
- `tests/Feature/Platforms/RefreshPlatformConnectionsCommandTest.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#SEM-1** · P1 — YouTube daily cron omits `latest` key, blanking the "Most Recent" tile after first refresh
    - **Where:** `app/Services/Platforms/PlatformRefresher.php` — `youtubePayload()` return array (lines 73–82)
    - **Affects:** Every active YouTube integration after the first `integrations:refresh` cron run. The dashboard "Most Recent" tile reads `payload['latest']` for its content — after a cron refresh that key is absent, so the tile goes blank. The flat back-compat fields (`name`, `description`, `link`, `thumbnail`) survive but the canonical nested key does not. Apple Music and Apple Podcast refreshers are unaffected — they use `...$payload` spread which preserves `latest` implicitly.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `PlatformRefresher::youtubePayload()`, align with `appleMusicPayload()` / `applePodcastPayload()` by spreading `$payload` as the base and overriding only the refreshed fields. This preserves `latest` and future payload keys without enumerating them explicitly:
          ```php
          return [
              ...$payload,
              'latest' => $latest,
              'name' => $latest['name'],
              'description' => $latest['description'],
              'link' => $latest['link'],
              'thumbnail' => $latest['thumbnail'],
              // highlights preserved by the spread; no need to re-state
          ];
          ```
        - If the spread approach is not desired, add `'latest' => $latest` to the existing explicit array at minimum.
        - Extend `tests/Feature/Platforms/RefreshPlatformConnectionsCommandTest.php` — the existing YouTube test asserts on `payload['name']` and `payload['highlights']` but not `payload['latest']`. Add: `expect($conn->payload['latest'])->toBe(['videoId' => 'v9', 'name' => 'New Video', ...])` to lock in the contract.
    - **Technical:** `YoutubeController::connect()` documents the invariant in a comment: `"The nested 'latest' is the canonical shape (same as a highlight item) and is what the dashboard reads to render the 'Most Recent' tile."` Both `connect()` and `highlights()` write `latest` consistently. `youtubePayload()` reconstructs the stored array from scratch and enumerates five keys explicitly — all the flat back-compat fields and `highlights` — but `latest` is not in the list. `appleMusicPayload()` and `applePodcastPayload()` both use `...$payload` as the base, so `latest` and all other previously-stored keys survive regardless of what is or isn't explicitly named. The structural difference between Apple (spread-then-override) and YouTube (enumerate-from-scratch) is the root cause. The test gap is what let this survive: `RefreshPlatformConnectionsCommandTest` asserts `payload['name']` but never `payload['latest']`, so the regression was invisible to CI.
    - **Plain English:** A user's YouTube widget has two parts: a "Current Headline" (the latest video, stored under a key called `latest`) and "Pinned Favourites" (the videos they manually chose). When a user connects their channel or picks highlights, both parts are written correctly. But the overnight auto-refresh writes only the headline *text* — it quietly deletes the "Current Headline" label itself. The front page looks for that label to know what to display; when it's missing, the tile shows nothing. The Apple refresh works correctly because it copies the entire old whiteboard first and then updates just the changed parts. YouTube needs to do the same.
    - **Evidence:**
        ```php
        // PlatformRefresher::youtubePayload() — 'latest' absent:
        return [
            'handle' => $handle,
            'name' => $latest['name'],
            'description' => $latest['description'],
            'link' => $latest['link'],
            'thumbnail' => $latest['thumbnail'],
            // User-chosen highlights are preserved — the cron only refreshes the
            // auto-latest tile, not the curated picks.
            'highlights' => $payload['highlights'] ?? [],
        ];

        // YoutubeController::connect() — 'latest' IS stored (documented as canonical):
        $selection = [
            'handle' => $handle,
            // Flat fields retained for partna-pages + back-compat. The nested
            // `latest` is the canonical shape (same as a highlight item) and is
            // what the dashboard reads to render the "Most recent" tile.
            'name' => $latest['name'],
            'description' => $latest['description'],
            'link' => $latest['link'],
            'thumbnail' => $latest['thumbnail'],
            'latest' => $latest,           // ← absent in youtubePayload()
            'highlights' => $highlights,
        ];

        // Compare PlatformRefresher::appleMusicPayload() — spread preserves 'latest':
        return [
            ...$payload,                   // ← YouTube omits this spread
            'latest' => $latest,
            'name' => $latest['name'],
            'thumbnail' => $latest['thumbnail'],
            'releaseDate' => $latest['releaseDate'],
            'link' => $latest['link'],
        ];
        ```

---

## P3 — Nice to have

- [ ] **#SLOP-1** · P3 — Five near-duplicate Apple Music/Podcast method pairs create five independent drift surfaces
    - **Where:** `app/Http/Controllers/Api/Platforms/AppleController.php` — pairs `connectMusic`/`connectPodcast`, `musicRecent`/`podcastRecent`, `musicHighlights`/`podcastHighlights`, `musicSelection`/`podcastSelection`, `forgetMusic`/`forgetPodcast`
    - **Affects:** Developer applying a future bug fix — a change made to one method in a pair will almost certainly miss the sibling. Five independent copy-paste surfaces in one file.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract a private generic method per operation that accepts the platform constant (`self::MUSIC` / `self::PODCAST`), the scraper callable, and any platform-specific field names (`releaseDate` vs `description`).
        - Keep all ten public methods as one-line adapters passing platform-specific arguments to the private generic.
        - The `forgetMusic`/`forgetPodcast` pair is already three lines each — collapsing them is optional but makes the pattern uniform.
    - **Technical:** CLAUDE.md's "three similar lines > a premature abstraction" tolerates small duplication, but these pairs run 8–20 lines each, repeated five times across one file. The only structural differences are: the platform constant, the scraper method invoked (`fetchAlbums` vs `fetchEpisodes`), the response array key (`albums` vs `episodes`), and one back-compat flat field name (`releaseDate` vs `description`). The `SEM-1` finding in this audit illustrates the exact failure mode — a fix applied to `musicHighlights` was not applied to `podcastHighlights` or the refresher, and the `latest` key drifted. A parameterised private helper per operation eliminates five drift surfaces while keeping the public API identical.
    - **Plain English:** There are two almost-identical sets of instructions for Apple Music and Apple Podcasts, copied side by side five times in the same file. When someone fixes a bug in the Music version months from now, they will almost certainly forget the Podcast copy. The right move is to write one set of instructions that takes "music" or "podcast" as a parameter — the public-facing method names stay the same, they just each call the shared helper with their own argument.
    - **Evidence:**
        ```php
        // musicRecent
        public function musicRecent(Request $request): JsonResponse
        {
            $input = data_get($this->read($this->currentUser($request), self::MUSIC), 'input');
            if (! $input) {
                return $this->error('Connect an Apple Music artist first.', 404);
            }
            $albums = $this->apple->fetchAlbums($input);
            if ($albums === null) {
                return $this->error('Could not load recent albums.', 502);
            }

            return $this->success(['albums' => $albums]);
        }

        // podcastRecent — identical structure, different constant, method, key, and error text
        public function podcastRecent(Request $request): JsonResponse
        {
            $input = data_get($this->read($this->currentUser($request), self::PODCAST), 'input');
            if (! $input) {
                return $this->error('Connect an Apple Podcast first.', 404);
            }
            $episodes = $this->apple->fetchEpisodes($input);
            if ($episodes === null) {
                return $this->error('Could not load recent episodes.', 502);
            }

            return $this->success(['episodes' => $episodes]);
        }
        ```

- [ ] **#SLOP-2** · P3 — "Refresh most-recent tile" block copy-pasted verbatim across three highlight methods — same pattern that produced SEM-1
    - **Where:** `app/Http/Controllers/Api/Platforms/AppleController.php` (`musicHighlights`, `podcastHighlights`) and `app/Http/Controllers/Api/Platforms/YoutubeController.php` (`highlights`)
    - **Affects:** Future additions to the `latest` payload contract. If a new field is added to the tile shape, all three blocks must be updated — one will be missed. The comments themselves acknowledge the mirroring ("see musicHighlights", "mirrors AppleController").
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract a private method on each controller (or a trait method on `ManagesIntegrationConnection`) that accepts the freshest items array, a reference to `$selection`, and the platform-specific back-compat field name (`releaseDate` vs `description`), and mutates `$selection` in place.
        - This makes the tile-refresh contract explicit and ensures `PlatformRefresher` has a single source of truth to consult when implementing the equivalent cron logic — which is precisely what was missing when `youtubePayload()` was written and `latest` was omitted (SEM-1).
    - **Technical:** All three blocks share the same structure and even comment text acknowledging the copy. The only variation is one field name: `releaseDate` in `musicHighlights`, `description` in `podcastHighlights` and `highlights`. The connection to SEM-1 is direct: `PlatformRefresher::youtubePayload()` reimplements this same pattern in a fourth location without a shared reference to consult — the absence of a canonical helper made it easy to miss `latest` when enumerating the array manually. Codifying the pattern as a reusable method creates the reference point that was missing.
    - **Plain English:** The same five-line "update the most-recent tile" block appears in three places, with comments pointing back at each other saying "this mirrors that one." The SEM-1 bug happened in a fourth place that needed the same pattern but had no shared helper to follow, so it left out a key. A single reusable helper would make it impossible to forget.
    - **Evidence:**
        ```php
        // AppleController::musicHighlights
        // Refresh the "Most recent" tile too. This re-fetch is newest-first, so
        // a release that landed since connect would otherwise leave `latest`
        // (and the flat back-compat fields) stale while only highlights updated.
        if (isset($albums[0])) {
            $latest = $albums[0];
            $selection['latest'] = $latest;
            $selection['name'] = $latest['name'];
            $selection['thumbnail'] = $latest['thumbnail'];
            $selection['releaseDate'] = $latest['releaseDate'];
            $selection['link'] = $latest['link'];
        }

        // AppleController::podcastHighlights — same shape, 'description' not 'releaseDate'
        // Refresh the "Most recent" tile too (see musicHighlights) — a newer
        // episode published since connect would otherwise leave `latest` stale.
        if (isset($episodes[0])) {
            $latest = $episodes[0];
            $selection['latest'] = $latest;
            $selection['name'] = $latest['name'];
            $selection['thumbnail'] = $latest['thumbnail'];
            $selection['description'] = $latest['description'];
            $selection['link'] = $latest['link'];
        }

        // YoutubeController::highlights — comment: "mirrors AppleController"
        // Refresh the "Most recent" tile too (mirrors AppleController) — a video
        // published since connect would otherwise leave `latest` (and the flat
        // back-compat fields) stale while only the highlights updated.
        if (isset($videos[0])) {
            $latest = $videos[0];
            $selection['latest'] = $latest;
            $selection['name'] = $latest['name'];
            $selection['description'] = $latest['description'];
            $selection['link'] = $latest['link'];
            $selection['thumbnail'] = $latest['thumbnail'];
        }
        ```
