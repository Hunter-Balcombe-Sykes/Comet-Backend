# YouTube Data API Swap — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the HTML/RSS YouTube scrape with the official YouTube Data API v3, preserving the existing controller contract and response shape so nothing downstream (frontend, DB, routes) changes.

**Architecture:** The fetch already lives behind a single service (`YoutubeScraper`) that the controller and cron call through two methods. We rename it to `YoutubeClient`, swap its internals from `SafeUrlFetcher` HTML/RSS scraping to two `Http`-facade calls against `googleapis.com` (`channels.list` → `playlistItems.list`), and persist the uploads-playlist ID so the daily refresh is a single 1-unit API call instead of re-resolving the channel each run. Field mapping is 1:1, so the JSON contract is byte-for-byte preserved.

**Tech Stack:** PHP 8.2, Laravel 12, Laravel `Http` facade, Pest 4. YouTube Data API v3 (API-key auth, no OAuth).

---

## Why this is low-risk

- The controller and cron only ever call `normalizeHandle()` and the recent-videos fetch. Preserve those signatures + return shape and the **existing controller tests pass unchanged** (they mock the service object, not HTTP).
- `normalizeHandle()` is pure string logic — copied verbatim, no behaviour change.
- No DB migration, no route change, no frontend change. Only the data source changes.

## File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `config/services.php` | Modify | Add `youtube.key` (reads `YOUTUBE_API_KEY`), mirroring the existing `apify` entry. |
| `.env.example` | Modify | Document `YOUTUBE_API_KEY=`. |
| `app/Services/Platforms/YoutubeClient.php` | Create | API client: `normalizeHandle`, `resolveUploadsPlaylistId`, `fetchUploads`, `fetchRecentVideos`. |
| `app/Services/Platforms/YoutubeScraper.php` | Delete | Replaced by `YoutubeClient` (done last, after all references repointed). |
| `app/Http/Controllers/Api/Platforms/YoutubeController.php` | Modify | Inject `YoutubeClient`; persist `uploadsPlaylistId` on connect; add `recentFor()` helper. |
| `app/Services/Platforms/PlatformRefresher.php` | Modify | Inject `YoutubeClient`; single-call refresh via stored ID; fix dropped `latest`. |
| `tests/Feature/Platforms/YoutubeClientTest.php` | Create | Exercises the real client against faked `googleapis.com` JSON. |
| `tests/Feature/Platforms/PlatformRefresherTest.php` | Create | Proves the cron uses one call + preserves `latest`/`highlights`. |
| `tests/Feature/Platforms/ScraperPlatformsConnectionTest.php` | Modify | Update YouTube connect mock to the new method set + class name. |
| `tests/Feature/Platforms/PlatformFixesTest.php` | Modify | Update `mock()` class name (call path unchanged for id-less payloads). |

## API reference (the two calls)

```
GET https://www.googleapis.com/youtube/v3/channels
    ?part=contentDetails&forHandle={handle}&key={KEY}        # 1 quota unit
→ items[0].contentDetails.relatedPlaylists.uploads  (the "UU…" playlist id)

GET https://www.googleapis.com/youtube/v3/playlistItems
    ?part=snippet,status&playlistId={UU…}&maxResults=15&key={KEY}   # 1 quota unit
→ items[].snippet.{title,description,resourceId.videoId}, items[].status.privacyStatus
```

The uploads playlist returns newest-first (matches the old RSS ordering). `link` and `thumbnail` are **built from the videoId exactly as today**, so those URLs are identical. Private/deleted uploads (which the RSS feed omitted) are filtered by `privacyStatus !== 'public'`.

**Known limitation (documented, not handled):** `forHandle` resolves modern `@handles`. Legacy `/c/CustomName` URLs have no API resolver — they return 404 (same practical outcome as the current scraper, which is already unreliable for them). Out of scope; revisit only if a user hits it.

---

### Task 1: Add the YouTube API key config

**Files:**
- Modify: `config/services.php`
- Modify: `.env.example`

- [ ] **Step 1: Add the service config**

In `config/services.php`, add alongside the existing `apify` entry:

```php
'youtube' => [
    'key' => env('YOUTUBE_API_KEY'),
],
```

- [ ] **Step 2: Document the env key**

In `.env.example`, add near the other integration keys:

```
YOUTUBE_API_KEY=
```

- [ ] **Step 3: Verify config resolves**

Run: `php artisan config:clear && php artisan tinker --execute="echo config('services.youtube.key') === null ? 'null-ok' : 'set';"`
Expected: prints `null-ok` (no key locally yet — the client guards on this).

- [ ] **Step 4: Commit**

```bash
git add config/services.php .env.example
git commit -m "feat(youtube): add YOUTUBE_API_KEY service config"
```

---

### Task 2: Build the YoutubeClient (TDD)

**Files:**
- Create: `tests/Feature/Platforms/YoutubeClientTest.php`
- Create: `app/Services/Platforms/YoutubeClient.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Platforms/YoutubeClientTest.php`:

```php
<?php

use App\Services\Platforms\YoutubeClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.youtube.key' => 'test-key']);
});

// Fake both API endpoints; URL globs distinguish the two list calls.
function fakeYoutubeApi(array $channels, array $playlistItems): void
{
    Http::fake([
        'www.googleapis.com/youtube/v3/channels*' => Http::response($channels, 200),
        'www.googleapis.com/youtube/v3/playlistItems*' => Http::response($playlistItems, 200),
    ]);
}

it('resolves the uploads playlist id from channels.list', function () {
    fakeYoutubeApi(
        ['items' => [['contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU_abc']]]]],
        ['items' => []],
    );

    expect(app(YoutubeClient::class)->resolveUploadsPlaylistId('mkbhd'))->toBe('UU_abc');
});

it('returns null when channels.list has no items', function () {
    fakeYoutubeApi(['items' => []], ['items' => []]);

    expect(app(YoutubeClient::class)->resolveUploadsPlaylistId('nope'))->toBeNull();
});

it('maps playlistItems into the video contract and builds link + thumbnail', function () {
    fakeYoutubeApi(
        ['items' => [['contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU_abc']]]]],
        ['items' => [[
            'snippet' => [
                'title' => 'My Video',
                'description' => 'desc',
                'resourceId' => ['videoId' => 'vid123'],
            ],
            'status' => ['privacyStatus' => 'public'],
        ]]],
    );

    expect(app(YoutubeClient::class)->fetchUploads('UU_abc'))->toBe([[
        'videoId' => 'vid123',
        'name' => 'My Video',
        'description' => 'desc',
        'link' => 'https://www.youtube.com/watch?v=vid123',
        'thumbnail' => 'https://i.ytimg.com/vi/vid123/hqdefault.jpg',
    ]]);
});

it('skips private and deleted uploads (parity with the old RSS feed)', function () {
    fakeYoutubeApi(
        ['items' => [['contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU_abc']]]]],
        ['items' => [
            ['snippet' => ['title' => 'Public', 'description' => '', 'resourceId' => ['videoId' => 'pub']], 'status' => ['privacyStatus' => 'public']],
            ['snippet' => ['title' => 'Private video', 'description' => '', 'resourceId' => ['videoId' => 'prv']], 'status' => ['privacyStatus' => 'private']],
        ]],
    );

    $videos = app(YoutubeClient::class)->fetchUploads('UU_abc');

    expect($videos)->toHaveCount(1);
    expect($videos[0]['videoId'])->toBe('pub');
});

it('chains channel resolution then uploads in fetchRecentVideos', function () {
    fakeYoutubeApi(
        ['items' => [['contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU_abc']]]]],
        ['items' => [['snippet' => ['title' => 'V', 'description' => '', 'resourceId' => ['videoId' => 'v1']], 'status' => ['privacyStatus' => 'public']]]],
    );

    $videos = app(YoutubeClient::class)->fetchRecentVideos('mkbhd');

    expect($videos)->toHaveCount(1);
    expect($videos[0]['videoId'])->toBe('v1');
});

it('returns null when the API key is missing', function () {
    config(['services.youtube.key' => null]);

    expect(app(YoutubeClient::class)->fetchRecentVideos('mkbhd'))->toBeNull();
});

it('normalizes handles from bare, @-prefixed, and URL forms', function () {
    $client = app(YoutubeClient::class);
    expect($client->normalizeHandle('@casey'))->toBe('casey');
    expect($client->normalizeHandle('casey'))->toBe('casey');
    expect($client->normalizeHandle('https://youtube.com/@casey'))->toBe('casey');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Platforms/YoutubeClientTest.php`
Expected: FAIL — `Class "App\Services\Platforms\YoutubeClient" not found`.

- [ ] **Step 3: Write the client**

Create `app/Services/Platforms/YoutubeClient.php`:

```php
<?php

namespace App\Services\Platforms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Fetches a YouTube channel's recent uploads via the official YouTube Data API
// v3 (API-key auth, no OAuth). Two calls: channels.list resolves the channel's
// uploads-playlist id (1 quota unit), playlistItems.list returns the recent
// uploads (1 unit). Replaces the former HTML/RSS scrape — same return shape, so
// the controller, cron, and sitepage payload are unchanged.
class YoutubeClient
{
    private const BASE = 'https://www.googleapis.com/youtube/v3';

    // Reduce any of bare handle / @handle / full URL to a bare handle.
    public function normalizeHandle(string $input): string
    {
        $s = trim($input);
        if (str_starts_with($s, 'http') && preg_match('~youtube\.com/(?:@|c/|user/)([A-Za-z0-9._-]+)~i', $s, $m)) {
            return $m[1];
        }

        return ltrim($s, '@');
    }

    /**
     * The channel's uploads-playlist id ("UU…"), or null if the channel can't be
     * resolved. The uploads playlist is the canonical "all uploads, newest first"
     * feed and its id is the stable handle→content link we persist on connect.
     */
    public function resolveUploadsPlaylistId(string $handle): ?string
    {
        $res = $this->get('/channels', [
            'part' => 'contentDetails',
            'forHandle' => $handle, // API accepts the handle with or without a leading @
        ]);
        if ($res === null) {
            return null;
        }

        return $res->json('items.0.contentDetails.relatedPlaylists.uploads');
    }

    /**
     * Recent uploads for a known uploads-playlist id, newest first, up to $limit.
     * Returns null on a failed call; an empty array for a channel with no public
     * uploads. Private/deleted entries are filtered (the old RSS feed omitted them).
     *
     * @return list<array{videoId:string, name:string, description:string, link:string, thumbnail:string}>|null
     */
    public function fetchUploads(string $uploadsPlaylistId, int $limit = 15): ?array
    {
        $res = $this->get('/playlistItems', [
            'part' => 'snippet,status',
            'playlistId' => $uploadsPlaylistId,
            'maxResults' => min($limit, 50),
        ]);
        if ($res === null) {
            return null;
        }

        $out = [];
        foreach ($res->json('items', []) as $item) {
            if (($item['status']['privacyStatus'] ?? 'public') !== 'public') {
                continue;
            }
            $videoId = $item['snippet']['resourceId']['videoId'] ?? null;
            if (! $videoId) {
                continue;
            }

            $out[] = [
                'videoId' => $videoId,
                'name' => $item['snippet']['title'] ?? '',
                'description' => $item['snippet']['description'] ?? '',
                'link' => "https://www.youtube.com/watch?v={$videoId}",
                'thumbnail' => "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Convenience: resolve the channel then fetch its recent uploads in one call.
     * Used where only a handle is known (back-compat for connections saved before
     * the uploads-playlist id was persisted).
     *
     * @return list<array{videoId:string, name:string, description:string, link:string, thumbnail:string}>|null
     */
    public function fetchRecentVideos(string $handle, int $limit = 15): ?array
    {
        $uploadsPlaylistId = $this->resolveUploadsPlaylistId($handle);
        if ($uploadsPlaylistId === null) {
            return null;
        }

        return $this->fetchUploads($uploadsPlaylistId, $limit);
    }

    // Single place for the key guard + request + non-200 logging. Returns the
    // Response on 200, else null (the caller maps null to the existing 404/502).
    private function get(string $path, array $query): ?\Illuminate\Http\Client\Response
    {
        $key = config('services.youtube.key');
        if (! $key) {
            Log::warning('YouTube API key missing; skipping fetch.', ['path' => $path]);

            return null;
        }

        $res = Http::timeout(8)->get(self::BASE.$path, [...$query, 'key' => $key]);
        if (! $res->ok()) {
            // Surfaces quota-exceeded / bad-key / not-found in Cloud logs.
            Log::warning('YouTube API call failed.', ['path' => $path, 'status' => $res->status()]);

            return null;
        }

        return $res;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Platforms/YoutubeClientTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Platforms/YoutubeClient.php tests/Feature/Platforms/YoutubeClientTest.php
git commit -m "feat(youtube): add YoutubeClient backed by YouTube Data API v3"
```

---

### Task 3: Repoint the controller + persist the uploads-playlist id

**Files:**
- Modify: `app/Http/Controllers/Api/Platforms/YoutubeController.php`
- Modify: `tests/Feature/Platforms/ScraperPlatformsConnectionTest.php`

- [ ] **Step 1: Update the YouTube connect test mock (failing first)**

In `tests/Feature/Platforms/ScraperPlatformsConnectionTest.php`:

Change the import `use App\Services\Platforms\YoutubeScraper;` → `use App\Services\Platforms\YoutubeClient;`

Replace the body of the `it('connects a YouTube channel scoped to the authenticated user', ...)` test's mock + assertions:

```php
it('connects a YouTube channel scoped to the authenticated user', function () {
    $user = scraperUser('ytuser');

    $this->mock(YoutubeClient::class, function ($m) {
        $m->shouldReceive('normalizeHandle')->andReturn('mychannel');
        $m->shouldReceive('resolveUploadsPlaylistId')->andReturn('UU_mychannel');
        $m->shouldReceive('fetchUploads')->andReturn([
            ['videoId' => 'v1', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't'],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/youtube/connect', ['channel' => '@mychannel'])
        ->assertOk()
        ->assertJsonPath('handle', 'mychannel')
        ->assertJsonPath('uploadsPlaylistId', 'UU_mychannel')
        ->assertJsonPath('latest.videoId', 'v1');

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'youtube')->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Platforms/ScraperPlatformsConnectionTest.php --filter="connects a YouTube channel"`
Expected: FAIL — controller still type-hints `YoutubeScraper` / calls `fetchRecentVideos`, so the mock methods don't match.

- [ ] **Step 3: Rewrite the controller**

In `app/Http/Controllers/Api/Platforms/YoutubeController.php`:

Change the import `use App\Services\Platforms\YoutubeScraper;` → `use App\Services\Platforms\YoutubeClient;`

Update the docblock's "Scraping lives in…" line to "Fetching lives in App\Services\Platforms\YoutubeClient (YouTube Data API v3)."

Change the constructor:

```php
    public function __construct(private readonly YoutubeClient $client) {}
```

Replace `connect()`:

```php
    // POST /api/platforms/youtube/connect — store the auto-latest video for the user.
    public function connect(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $validated = $request->validate([
            'channel' => ['required', 'string', 'max:200'],
        ]);

        $handle = $this->client->normalizeHandle($validated['channel']);
        if ($handle === '') {
            return $this->error('Enter your YouTube channel.', 422);
        }

        $uploadsPlaylistId = $this->client->resolveUploadsPlaylistId($handle);
        if ($uploadsPlaylistId === null) {
            return $this->error('Could not find that YouTube channel or its latest video.', 404);
        }

        $videos = $this->client->fetchUploads($uploadsPlaylistId);
        if (empty($videos)) {
            return $this->error('Could not find that YouTube channel or its latest video.', 404);
        }
        $latest = $videos[0];

        // Reconnecting the SAME channel keeps the chosen highlights; switching to
        // a different channel resets them (they belonged to the old channel).
        $existing = $this->readConnection($user);
        $highlights = data_get($existing, 'handle') === $handle ? data_get($existing, 'highlights', []) : [];

        $selection = [
            'handle' => $handle,
            // Persisted so daily refreshes skip channel re-resolution (1 API call, not 2).
            'uploadsPlaylistId' => $uploadsPlaylistId,
            // Flat fields retained for partna-pages + back-compat. The nested
            // `latest` is the canonical shape (same as a highlight item).
            'name' => $latest['name'],
            'description' => $latest['description'],
            'link' => $latest['link'],
            'thumbnail' => $latest['thumbnail'],
            'latest' => $latest,
            'highlights' => $highlights,
        ];
        $this->writeConnection($user, $selection);

        return $this->success($selection);
    }
```

Replace `recent()`:

```php
    // GET /api/platforms/youtube/recent — the last 15 videos for the highlights picker.
    public function recent(Request $request): JsonResponse
    {
        $selection = $this->readConnection($this->currentUser($request));
        if (! data_get($selection, 'handle')) {
            return $this->error('Connect a YouTube channel first.', 404);
        }

        $videos = $this->recentFor($selection);
        if ($videos === null) {
            return $this->error('Could not load recent videos for that channel.', 502);
        }

        return $this->success(['videos' => $videos]);
    }
```

In `highlights()`, replace the single fetch line:

```php
        $videos = $this->scraper->fetchRecentVideos(data_get($selection, 'handle'));
```

with:

```php
        $videos = $this->recentFor($selection);
```

Add the private helper (e.g. just below `selection()`):

```php
    // Recent uploads for a saved selection. Prefers the stored uploads-playlist id
    // (one API call); falls back to resolving from the handle for rows saved before
    // the id was persisted.
    private function recentFor(array $selection): ?array
    {
        $uploadsPlaylistId = data_get($selection, 'uploadsPlaylistId');
        if ($uploadsPlaylistId) {
            return $this->client->fetchUploads($uploadsPlaylistId);
        }

        $handle = data_get($selection, 'handle');

        return $handle ? $this->client->fetchRecentVideos($handle) : null;
    }
```

- [ ] **Step 4: Run the YouTube controller tests to verify they pass**

Run: `php artisan test tests/Feature/Platforms/ScraperPlatformsConnectionTest.php`
Expected: PASS (all platform connect tests, including YouTube).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/Platforms/YoutubeController.php tests/Feature/Platforms/ScraperPlatformsConnectionTest.php
git commit -m "feat(youtube): controller uses YoutubeClient + persists uploads-playlist id"
```

---

### Task 4: Optimize the cron refresh + fix the dropped `latest`

**Files:**
- Modify: `app/Services/Platforms/PlatformRefresher.php`
- Create: `tests/Feature/Platforms/PlatformRefresherTest.php`
- Modify: `tests/Feature/Platforms/PlatformFixesTest.php`

- [ ] **Step 1: Write the refresher test (failing first)**

Create `tests/Feature/Platforms/PlatformRefresherTest.php`:

```php
<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\YoutubeClient;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function refresherUser(): User
{
    return User::create([
        'handle' => 'ytcron',
        'handle_lc' => 'ytcron',
        'display_name' => 'YT Cron',
        'account_type' => 'individual',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'ytcron@example.com',
    ]);
}

it('refreshes a YouTube connection with a single uploads call and keeps latest + highlights', function () {
    $this->mock(YoutubeClient::class, function ($m) {
        $m->shouldNotReceive('resolveUploadsPlaylistId');  // stored id → no channel re-resolve
        $m->shouldReceive('fetchUploads')->once()->andReturn([
            ['videoId' => 'new1', 'name' => 'New', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't'],
        ]);
    });

    $user = refresherUser();
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'youtube',
        'payload' => [
            'handle' => 'mychannel',
            'uploadsPlaylistId' => 'UU_mychannel',
            'name' => 'Stale',
            'latest' => ['videoId' => 'old', 'name' => 'Stale'],
            'highlights' => [['videoId' => 'h1', 'name' => 'Pinned']],
        ],
    ]);

    app(PlatformRefresher::class)->refresh($conn->fresh());

    $payload = $conn->fresh()->payload;
    expect($payload['latest']['videoId'])->toBe('new1');         // nested latest refreshed (bug fix)
    expect($payload['name'])->toBe('New');                       // flat back-compat field refreshed
    expect($payload['uploadsPlaylistId'])->toBe('UU_mychannel'); // id preserved
    expect($payload['highlights'])->toHaveCount(1);              // curated picks preserved
    expect($payload['highlights'][0]['videoId'])->toBe('h1');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/Platforms/PlatformRefresherTest.php`
Expected: FAIL — current `youtubePayload()` calls `fetchRecentVideos` (which would call `resolveUploadsPlaylistId`, violating `shouldNotReceive`) and drops `latest`.

- [ ] **Step 3: Update the refresher**

In `app/Services/Platforms/PlatformRefresher.php`:

Change the import/constructor type `YoutubeScraper $youtube` → `YoutubeClient $youtube` (add `use App\Services\Platforms\YoutubeClient;` is unnecessary — same namespace; just change the type-hint).

Replace `youtubePayload()`:

```php
    private function youtubePayload(array $payload): ?array
    {
        // Prefer the stored uploads-playlist id (1 API call); resolve from the
        // handle only for rows saved before the id was persisted, then backfill it.
        $uploadsPlaylistId = $payload['uploadsPlaylistId'] ?? null;
        $handle = $payload['handle'] ?? null;
        if (! $uploadsPlaylistId && $handle) {
            $uploadsPlaylistId = $this->youtube->resolveUploadsPlaylistId($handle);
        }
        if (! $uploadsPlaylistId) {
            return null;
        }

        $videos = $this->youtube->fetchUploads($uploadsPlaylistId);
        if (empty($videos)) {
            return null;
        }
        $latest = $videos[0];

        // Preserve handle + curated highlights; refresh only the "most recent"
        // tile. Spreading $payload (and re-setting latest) keeps the nested
        // `latest` the cron previously dropped — parity with the Apple tiles.
        return [
            ...$payload,
            'uploadsPlaylistId' => $uploadsPlaylistId,
            'latest' => $latest,
            'name' => $latest['name'],
            'description' => $latest['description'],
            'link' => $latest['link'],
            'thumbnail' => $latest['thumbnail'],
        ];
    }
```

- [ ] **Step 4: Run the refresher test to verify it passes**

Run: `php artisan test tests/Feature/Platforms/PlatformRefresherTest.php`
Expected: PASS.

- [ ] **Step 5: Update the existing highlights-refresh test's class name**

In `tests/Feature/Platforms/PlatformFixesTest.php`:

Change the import `use App\Services\Platforms\YoutubeScraper;` → `use App\Services\Platforms\YoutubeClient;`

In the `it('refreshes the YouTube "most recent" tile when highlights are updated', ...)` test, change `$this->mock(YoutubeScraper::class, ...)` → `$this->mock(YoutubeClient::class, ...)`. The mocked method stays `fetchRecentVideos` — the seeded payload has no `uploadsPlaylistId`, so `recentFor()` takes the handle fallback path and still calls it.

- [ ] **Step 6: Run the fixes test to verify it passes**

Run: `php artisan test tests/Feature/Platforms/PlatformFixesTest.php --filter="YouTube"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Platforms/PlatformRefresher.php tests/Feature/Platforms/PlatformRefresherTest.php tests/Feature/Platforms/PlatformFixesTest.php
git commit -m "feat(youtube): single-call cron refresh via stored id; fix dropped latest"
```

---

### Task 5: Delete the old scraper + full verification

**Files:**
- Delete: `app/Services/Platforms/YoutubeScraper.php`

- [ ] **Step 1: Confirm no remaining references**

Run: `grep -rn "YoutubeScraper" app/ tests/ routes/ config/`
Expected: no matches.

- [ ] **Step 2: Delete the orphaned scraper**

```bash
git rm app/Services/Platforms/YoutubeScraper.php
```

- [ ] **Step 3: Run the full suite**

Run: `composer test`
Expected: PASS (no failures, no errors). Note: run in the main checkout, not a `.claude/worktrees/` copy (feature tests break there).

- [ ] **Step 4: Style-check only the changed lines**

Run: `vendor/bin/pint app/Services/Platforms/YoutubeClient.php app/Http/Controllers/Api/Platforms/YoutubeController.php app/Services/Platforms/PlatformRefresher.php tests/Feature/Platforms/YoutubeClientTest.php tests/Feature/Platforms/PlatformRefresherTest.php`
Expected: clean (or auto-fixed) — do NOT run repo-wide pint (baseline is not clean).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor(youtube): drop the HTML/RSS scraper, fully replaced by the API client"
```

---

## Manual steps (Josh — one-time, ~10 min, before live verification)

These can happen any time before Step "live verification" below; the code ships and tests pass without them.

1. **Google Cloud project** — at console.cloud.google.com, create or pick a project.
2. **Enable the API** — APIs & Services → Library → enable **"YouTube Data API v3"**.
3. **Create an API key** — APIs & Services → Credentials → Create credentials → API key.
4. **Restrict the key** — on the key, API restrictions → restrict to "YouTube Data API v3". Skip IP/referrer restriction (Laravel Cloud egress IPs aren't static).
5. **Set the key** — add `YOUTUBE_API_KEY` to Laravel Cloud env vars for **development** and **production** (recommend two separate keys so they rotate independently), plus your local `.env` for live testing.

Not needed: OAuth / consent screen / app verification (reading public data), and no billing account (10,000 units/day free tier is active without it).

## Live verification (needs a real key)

After the key is in your local `.env`:

```bash
php artisan tinker --execute="dd(app(\App\Services\Platforms\YoutubeClient::class)->fetchRecentVideos('mkbhd'));"
```

Expected: an array of ~15 videos with `videoId`/`name`/`link`/`thumbnail`. Then connect a real channel via the dashboard and confirm the "Most recent" tile + highlights picker render identically to before.

---

## Self-Review

- **Spec coverage:** Swap to API ✓ (Tasks 2–4). Preserve contract ✓ (return shape identical; existing controller tests pass with only mock-name/method updates). Rename to `YoutubeClient` ✓ (Tasks 2–5). Cron optimization ✓ (Task 4, stored `uploadsPlaylistId` → single call). `latest`-drop bug fix ✓ (Task 4). Config + manual steps ✓ (Task 1 + manual section). No frontend/DB/route change ✓ (none touched).
- **Placeholder scan:** none — every code step has complete code and exact commands.
- **Type consistency:** `resolveUploadsPlaylistId(): ?string`, `fetchUploads(string,int): ?array`, `fetchRecentVideos(string,int): ?array`, `normalizeHandle(string): string` used identically across client, controller (`recentFor`), refresher, and tests. Payload key `uploadsPlaylistId` consistent across connect, `recentFor`, refresher, and both new tests.
- **Known gap (intentional):** legacy `/c/CustomName` URLs are not API-resolvable → 404 (matches current behaviour). Documented above; not handled.
