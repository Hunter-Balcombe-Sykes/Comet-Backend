# Instagram Thin-Scrape Detection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop a zero-post Instagram scrape from being indistinguishable from a complete one — retry it once, flag the build when it stays thin, and never let a thin refresh destroy a live user's payload or mirrored media.

**Architecture:** One conservative predicate (`InstagramScraper::isThinProfile()`) at the single choke point every Instagram scrape passes through. `ProfileFetchResult` gains a `thin` flag. The pre-account build path accepts a thin profile but stamps `thin_scrape_at` on the build; the connect/refresh path rejects it, which preserves existing data *by never calling `seed()`* — `seed()`'s stale-reclaim is what deletes the R2 objects.

**Tech Stack:** PHP 8.4, Laravel 12, Pest 4, PostgreSQL (Supabase) with a SQLite in-memory test lane, Apify (`apify~instagram-profile-scraper`).

**Spec:** `docs/superpowers/specs/2026-08-11-instagram-thin-scrape-design.md`

## Global Constraints

- **Never create Laravel migration files.** All schema changes are raw SQL in `supabase/migrations/`. A Composer guard rejects Laravel migrations.
- **Tests run SQLite, production is Postgres.** A green `composer test` does not prove the Postgres DDL is right. New columns must be added to the SQLite test schema in `tests/Pest.php` *and* verified against the migration DDL.
- **Apply the migration to dev Supabase BEFORE merging** (`supabase link --project-ref glncumufgaqcmqhzwrxm` → `db push --dry-run` → `db push`). Merging code that reads a column dev does not have yields `SQLSTATE 42703`.
- **The `Http` facade is the only permitted outbound transport.** No `curl_*`, no direct Guzzle. This work adds no new hosts — the Apify endpoint is an existing ConstantEndpoint (`Category A`).
- **Every cache key must carry a TTL.** Never `Cache::forever()`. (This plan adds no cache keys; `ApifyBudget` already handles its own TTLs.)
- **Comment for WHY, not what.** 4-space indent, LF. Brief docblocks on public methods, one line above non-trivial blocks. No banners or restatements.
- **Run `php artisan pint`** before each commit. Note `composer test --filter` is broken in this repo — use `./vendor/bin/pest --filter` directly.
- Branch: `feat/instagram-thin-scrape`, already created off `development`, spec committed at `f8c51c176`.

---

### Task 1: The thin predicate

The predicate and the flag, with no consumer behaviour change yet. This task is safe to merge on its own: nothing reads `thin` until Task 3.

**Files:**
- Modify: `app/Services/Platforms/ProfileFetchResult.php`
- Modify: `app/Services/Platforms/InstagramScraper.php` (add method after `fetchProfileResult()`, around line 120)
- Test: `tests/Unit/Platforms/InstagramProfileFetchTest.php` (append)

**Interfaces:**
- Produces: `InstagramScraper::isThinProfile(array $profile): bool` — public, used by Tasks 2 and 3.
- Produces: `ProfileFetchResult::ok(array $profile, bool $thin = false): self` and the readonly property `public bool $thin`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Platforms/InstagramProfileFetchTest.php`:

```php
// ── Thin-profile predicate ───────────────────────────────────────────────────
// A 2xx profile can carry a name, a follower count and a picture while its post
// timeline is simply absent (@crucibletattooco, 2026-08-10 10:22 UTC — the same
// account re-probed clean the next day). postsCount and latestPosts are the
// count and the contents of ONE upstream container, so they fail together.

it('flags the observed fault: followers present, postsCount absent, no posts', function () {
    $thin = (new InstagramScraper)->isThinProfile([
        'username' => 'crucibletattooco',
        'fullName' => 'Crucible Tattoo Co.',
        'followersCount' => 30042,
        'businessCategoryName' => 'None',
        'private' => false,
        // postsCount and latestPosts both absent — the container never arrived.
    ]);

    expect($thin)->toBeTrue();
});

it('flags a self-contradicting profile that claims posts but ships none', function () {
    $thin = (new InstagramScraper)->isThinProfile([
        'username' => 'crucibletattooco',
        'followersCount' => 30042,
        'postsCount' => 4164,
        'latestPosts' => [],
        'private' => false,
    ]);

    expect($thin)->toBeTrue();
});

// The conservative half of the predicate. A false positive tells a real
// prospect their build is broken; a false negative costs one thin site.
it('does NOT flag a genuinely empty account (postsCount 0 with no posts is self-consistent)', function () {
    $thin = (new InstagramScraper)->isThinProfile([
        'username' => 'brandnew',
        'followersCount' => 0,
        'postsCount' => 0,
        'latestPosts' => [],
        'private' => false,
    ]);

    expect($thin)->toBeFalse();
});

it('does NOT flag a sparse but real account', function () {
    // roberthuntercuts, live on dev: 3 followers, 1 post.
    $thin = (new InstagramScraper)->isThinProfile([
        'username' => 'roberthuntercuts',
        'followersCount' => 3,
        'postsCount' => 1,
        'latestPosts' => [['shortCode' => 'abc', 'displayUrl' => 'https://x/1.jpg']],
        'private' => false,
    ]);

    expect($thin)->toBeFalse();
});

it('does NOT flag a private account, which legitimately exposes no posts', function () {
    $thin = (new InstagramScraper)->isThinProfile([
        'username' => 'locked',
        'followersCount' => 500,
        'private' => true,
    ]);

    expect($thin)->toBeFalse();
});

it('does NOT flag a healthy profile', function () {
    $thin = (new InstagramScraper)->isThinProfile([
        'username' => 'simondoylehair',
        'followersCount' => 11065,
        'postsCount' => 365,
        'latestPosts' => array_fill(0, 12, ['shortCode' => 'x']),
        'private' => false,
    ]);

    expect($thin)->toBeFalse();
});

// businessCategory "None" is this account's NORMAL value — the successful
// re-probe returns it, as do natgeo and hungryjacksau with full data. It must
// never influence the predicate.
it('ignores businessCategoryName entirely', function () {
    $thin = (new InstagramScraper)->isThinProfile([
        'username' => 'natgeo',
        'followersCount' => 268999742,
        'postsCount' => 31813,
        'latestPosts' => array_fill(0, 12, ['shortCode' => 'x']),
        'businessCategoryName' => 'None',
        'private' => false,
    ]);

    expect($thin)->toBeFalse();
});

it('treats an explicit error item as classify()\'s business, not thinness', function () {
    $thin = (new InstagramScraper)->isThinProfile([
        'username' => 'ghost',
        'error' => 'not_found',
    ]);

    expect($thin)->toBeFalse();
});

it('carries a thin flag on the result object, defaulting to false', function () {
    expect(ProfileFetchResult::ok(['username' => 'x'])->thin)->toBeFalse()
        ->and(ProfileFetchResult::ok(['username' => 'x'], thin: true)->thin)->toBeTrue()
        ->and(ProfileFetchResult::failed(ProfileFetchFailure::Transport)->thin)->toBeFalse();
});
```

Add the import at the top of the file, beside the existing `use` statements:

```php
use App\Services\Platforms\ProfileFetchResult;
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Platforms/InstagramProfileFetchTest.php --filter="thin|flag|ignores businessCategoryName"`
Expected: FAIL — `Call to undefined method App\Services\Platforms\InstagramScraper::isThinProfile()`

- [ ] **Step 3: Add the `thin` flag to `ProfileFetchResult`**

Replace the whole class body in `app/Services/Platforms/ProfileFetchResult.php`:

```php
<?php

namespace App\Services\Platforms;

// Outcome of InstagramScraper::fetchProfileResult(): either the raw actor item
// or the reason it failed. Exactly one of $profile / $failure is non-null.
//
// $thin marks a SUCCESSFUL fetch whose post timeline was missing — a profile we
// have, but should not treat as complete. It is only ever set on the ok() path:
// a failure has no profile to be thin about.
final readonly class ProfileFetchResult
{
    private function __construct(
        public ?array $profile,
        public ?ProfileFetchFailure $failure,
        public bool $thin = false,
    ) {}

    public static function ok(array $profile, bool $thin = false): self
    {
        return new self($profile, null, $thin);
    }

    public static function failed(ProfileFetchFailure $failure): self
    {
        return new self(null, $failure);
    }
}
```

- [ ] **Step 4: Add the predicate to `InstagramScraper`**

Insert into `app/Services/Platforms/InstagramScraper.php`, immediately after `fetchProfileResult()` ends (after line 120) and before `adapterFor()`:

```php
    /**
     * A 2xx profile whose post timeline never arrived.
     *
     * postsCount and latestPosts are the count and the contents of ONE upstream
     * container, so they fail together — this is a single signal, never two
     * independent checks. Observed 2026-08-10 on @crucibletattooco: name,
     * follower count, picture and category all present; those two absent. The
     * same account re-probed clean the next day.
     *
     * Deliberately conservative. postsCount === 0 WITH no posts is
     * self-consistent and indistinguishable from a genuinely empty account, so
     * it is NOT thin: a false positive tells a real prospect their build is
     * broken, while a false negative costs one thin site.
     *
     * businessCategoryName is deliberately absent from this predicate. "None" is
     * the normal value for an account with no subvertical — natgeo and
     * hungryjacksau both carry it with complete data.
     */
    public function isThinProfile(array $profile): bool
    {
        // An explicit error item is the adapter's classify() call to make.
        if (is_string(data_get($profile, 'error'))) {
            return false;
        }

        // Private accounts legitimately expose no posts.
        if (data_get($profile, 'private') === true) {
            return false;
        }

        $posts = data_get($profile, 'latestPosts');
        if (is_array($posts) && $posts !== []) {
            return false;
        }

        // Absent or non-numeric: the container never arrived (the observed fault).
        $count = data_get($profile, 'postsCount');
        if (! is_numeric($count)) {
            return true;
        }

        // Claims posts but shipped none.
        return (int) $count > 0;
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Platforms/InstagramProfileFetchTest.php`
Expected: PASS — all tests in the file, including the pre-existing ones.

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Services/Platforms/InstagramScraper.php app/Services/Platforms/ProfileFetchResult.php tests/Unit/Platforms/InstagramProfileFetchTest.php
git add app/Services/Platforms/InstagramScraper.php app/Services/Platforms/ProfileFetchResult.php tests/Unit/Platforms/InstagramProfileFetchTest.php
git commit -m "feat(instagram): detect a profile whose post timeline never arrived

postsCount and latestPosts are the count and the contents of one upstream
container, so they fail together — one signal, not two. Conservative by
design: a self-consistent 0/0 is NOT thin, because a false positive tells a
real prospect their build is broken.

No consumer reads the flag yet."
```

---

### Task 2: One bounded, budget-gated retry

**Files:**
- Modify: `app/Services/Platforms/InstagramScraper.php` (restructure `fetchProfileResult()`, lines 35-120)
- Test: `tests/Unit/Platforms/InstagramProfileFetchTest.php` (append)

**Interfaces:**
- Consumes: `isThinProfile()` from Task 1.
- Produces: `fetchProfileResult()` now performs at most 2 HTTP calls and returns `thin: true` only after a retry has failed to recover (or been denied budget).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Platforms/InstagramProfileFetchTest.php`:

```php
// ── Thin retry ───────────────────────────────────────────────────────────────
// The fault is transient (@crucibletattooco scraped clean the next day), so one
// retry is worth a paid actor run. Exactly one: a retry without a bound is an
// outage amplifier.

/** A complete profile the predicate accepts. */
function healthyProfileItem(): array
{
    return [
        'username' => 'crucibletattooco',
        'fullName' => 'Crucible Tattoo Co.',
        'followersCount' => 30041,
        'postsCount' => 4164,
        'private' => false,
        'latestPosts' => array_fill(0, 12, ['shortCode' => 'x', 'displayUrl' => 'https://x/1.jpg']),
    ];
}

/** The 2026-08-10 fault: header fields present, timeline absent. */
function thinProfileItem(): array
{
    return [
        'username' => 'crucibletattooco',
        'fullName' => 'Crucible Tattoo Co.',
        'followersCount' => 30042,
        'businessCategoryName' => 'None',
        'private' => false,
    ];
}

it('retries once when the first scrape comes back thin, and reports the recovery', function () {
    Http::fake(['api.apify.com/*' => Http::sequence()
        ->push([thinProfileItem()], 201)
        ->push([healthyProfileItem()], 201)]);

    $result = (new InstagramScraper)->fetchProfileResult('crucibletattooco');

    expect($result->thin)->toBeFalse()
        ->and($result->profile['postsCount'])->toBe(4164);
    Http::assertSentCount(2);
});

it('gives up after exactly one retry and reports the profile as thin', function () {
    Http::fake(['api.apify.com/*' => Http::sequence()
        ->push([thinProfileItem()], 201)
        ->push([thinProfileItem()], 201)
        ->push([healthyProfileItem()], 201)]);

    $result = (new InstagramScraper)->fetchProfileResult('crucibletattooco');

    expect($result->thin)->toBeTrue()
        ->and($result->profile['followersCount'])->toBe(30042);
    // Never a third call — the third fake response must go unused.
    Http::assertSentCount(2);
});

it('does not retry a healthy first scrape', function () {
    Http::fake(['api.apify.com/*' => Http::response([healthyProfileItem()], 201)]);

    $result = (new InstagramScraper)->fetchProfileResult('crucibletattooco');

    expect($result->thin)->toBeFalse();
    Http::assertSentCount(1);
});

// The scraper does not otherwise claim Apify budget — the controllers do
// (InstagramController:381, RefreshController:183). Without this gate the retry
// would spend paid runs the daily cap never sees.
it('skips the retry when the Apify daily cap is exhausted', function () {
    config(['partna.limits.apify.actors.instagram' => 0]);
    Http::fake(['api.apify.com/*' => Http::sequence()
        ->push([thinProfileItem()], 201)
        ->push([healthyProfileItem()], 201)]);

    $result = (new InstagramScraper)->fetchProfileResult('crucibletattooco');

    expect($result->thin)->toBeTrue();
    Http::assertSentCount(1);
});

it('does not retry a hard failure — only thinness earns a second paid run', function () {
    Http::fake(['api.apify.com/*' => Http::response('', 500)]);

    $result = (new InstagramScraper)->fetchProfileResult('crucibletattooco');

    expect($result->failure)->toBe(ProfileFetchFailure::UpstreamError);
    Http::assertSentCount(1);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Platforms/InstagramProfileFetchTest.php --filter="retr|thin"`
Expected: FAIL — `Http::assertSentCount(2)` fails with 1 actual call; the "gives up" test reports `thin` false.

- [ ] **Step 3: Restructure `fetchProfileResult()` into attempt + retry**

In `app/Services/Platforms/InstagramScraper.php`, add the import beside the existing ones at the top of the file:

```php
use App\Services\Cache\ApifyBudget;
```

Replace the entire `fetchProfileResult()` method (lines 35-120, from the docblock ending `...while Meta was down.` through the closing brace before `adapterFor()`) with the following three methods. The body of `attemptFetch()` is the old `fetchProfileResult()` body verbatim — token check, adapter lookup, POST, `successful()` check, item shape check, `classify()` — with only the method name and signature changed:

```php
    // Same fetch, but reporting WHY it failed. fetchProfile()'s bare null could
    // not distinguish a handle that genuinely does not exist from an upstream
    // break, so callers labelled every failure "source not found" — telling real
    // prospects their own Instagram account doesn't exist while Meta was down.
    //
    // A thin result (post timeline missing) earns ONE retry: the fault is
    // transient — @crucibletattooco returned zero posts on 2026-08-10 and 4,164
    // the next day — and a second paid run is cheap against a site built empty.
    public function fetchProfileResult(string $username, ?string $userId = null): ProfileFetchResult
    {
        $first = $this->attemptFetch($username, $userId);

        if ($first->profile === null || ! $this->isThinProfile($first->profile)) {
            return $first;
        }

        // This class does not otherwise claim Apify budget — the controllers do
        // (InstagramController::guardApifyBudget, RefreshController). Claim here
        // or the retry spends a paid run outside the daily cap. Denied means
        // denied: report thin rather than spending anyway.
        if (! app(ApifyBudget::class)->tryClaim('instagram')) {
            $this->logThinProfile($username, $userId, $first->profile, retried: false, recovered: false);

            return ProfileFetchResult::ok($first->profile, thin: true);
        }

        $retry = $this->attemptFetch($username, $userId);

        if ($retry->profile !== null && ! $this->isThinProfile($retry->profile)) {
            $this->logThinProfile($username, $userId, $first->profile, retried: true, recovered: true);

            return $retry;
        }

        // Still thin (or the retry broke outright): keep the first profile — it
        // is degraded, not absent, and the build path needs something to render.
        $this->logThinProfile($username, $userId, $first->profile, retried: true, recovered: false);

        return ProfileFetchResult::ok($first->profile, thin: true);
    }

    // One run of the actor. Extracted so the thin retry above is exactly one
    // extra call and cannot become a loop. Body is the previous
    // fetchProfileResult() verbatim — only the name and visibility changed.
    private function attemptFetch(string $username, ?string $userId = null): ProfileFetchResult
    {
        $token = config('services.apify.token');
        if (! $token) {
            return ProfileFetchResult::failed(ProfileFetchFailure::NotConfigured);
        }

        $actor = (string) config('partna.instagram.actor');
        $adapter = $this->adapterFor($actor);
        if (! $adapter instanceof InstagramActorAdapter) {
            // Fail closed rather than guess an input shape: each actor has its
            // own schema and sending the wrong body is a hard 400, which would
            // surface as a failed scrape on EVERY build. No username in this
            // context — it's a config fault, not a user one.
            Log::warning('instagram.actor.unadapted', ['actor' => $actor]);

            return ProfileFetchResult::failed(ProfileFetchFailure::NotConfigured);
        }

        try {
            $response = Http::withToken($token)
                ->timeout((int) config('partna.limits.apify.run_sync_timeout_seconds', self::RUN_SYNC_TIMEOUT_SECONDS))
                ->post(
                    'https://api.apify.com/v2/acts/'.$actor.'/run-sync-get-dataset-items',
                    $adapter->input($username),
                );
        } catch (Throwable $e) {
            report($e);
            // Hash the handle before logging — public on Instagram, but pairing it
            // with our internal user_id in long-retained logs builds a durable,
            // joinable identity record that shouldn't outlive the request. Lowercase
            // first: Instagram usernames are case-insensitive, so two connect attempts
            // for the same account ("DocPizza" vs "docpizza") must hash identically or
            // they won't correlate in the logs.
            Log::warning('instagram.apify.threw', ['username_hash' => hash('sha256', mb_strtolower($username)), 'user_id' => $userId, 'error' => $e->getMessage()]);

            return ProfileFetchResult::failed(ProfileFetchFailure::Transport);
        }

        // 201 Created on success — ->ok() would only accept exactly 200.
        if (! $response->successful()) {
            // Server errors (5xx) indicate genuine Apify infra failures worth alerting on;
            // 4xx (e.g. 404 for an unknown username) are expected and log-only.
            if ($response->status() >= 500) {
                report(new \RuntimeException('Apify scrape failed with status '.$response->status()));
            }
            Log::warning('instagram.apify.not_ok', [
                'username_hash' => hash('sha256', mb_strtolower($username)),
                'user_id' => $userId,
                'status' => $response->status(),
            ]);

            return ProfileFetchResult::failed(ProfileFetchFailure::UpstreamError);
        }

        $items = $response->json();
        if (! is_array($items) || empty($items) || ! is_array($items[0])) {
            Log::warning('instagram.apify.bad_items', [
                'username_hash' => hash('sha256', mb_strtolower($username)),
                'user_id' => $userId,
                'type' => gettype($items),
                'count' => is_array($items) ? count($items) : 0,
            ]);

            return ProfileFetchResult::failed(ProfileFetchFailure::MalformedPayload);
        }

        // A 2xx run can still carry a per-item scrape failure: the dataset item
        // is profile-shaped (username/url/scrapedAt) but its fields are null and
        // it carries an "error" string instead. Treat that as a failed fetch —
        // not a valid (empty) profile — so callers retry instead of silently
        // persisting a blank account. Only the adapter knows whether that error
        // means "no such handle" or "couldn't read it".
        if ($failure = $adapter->classify($items[0])) {
            Log::warning('instagram.apify.error_item', [
                'username_hash' => hash('sha256', mb_strtolower($username)),
                'user_id' => $userId,
                'error' => $items[0]['error'],
                'failure' => $failure->value,
            ]);

            return ProfileFetchResult::failed($failure);
        }

        return ProfileFetchResult::ok($items[0]);
    }

    // Hash the handle before logging, for the reason given in attemptFetch(): a
    // raw handle beside our internal user_id builds a durable joinable identity
    // record in long-retained logs.
    private function logThinProfile(string $username, ?string $userId, array $profile, bool $retried, bool $recovered): void
    {
        $posts = data_get($profile, 'latestPosts');

        Log::warning('instagram.thin_profile', [
            'username_hash' => hash('sha256', mb_strtolower($username)),
            'user_id' => $userId,
            'postsCount' => data_get($profile, 'postsCount'),
            'followersCount' => data_get($profile, 'followersCount'),
            'latestPosts' => is_array($posts) ? count($posts) : 0,
            'retried' => $retried,
            'recovered' => $recovered,
        ]);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Platforms/InstagramProfileFetchTest.php`
Expected: PASS — including every pre-existing test in the file. If the pre-existing actor-input-shape tests fail, `attemptFetch()` did not receive the old body verbatim.

- [ ] **Step 5: Commit**

```bash
php artisan pint app/Services/Platforms/InstagramScraper.php tests/Unit/Platforms/InstagramProfileFetchTest.php
git add app/Services/Platforms/InstagramScraper.php tests/Unit/Platforms/InstagramProfileFetchTest.php
git commit -m "feat(instagram): retry a thin scrape once, gated on the Apify daily cap

The fault is transient, so one extra paid run is worth it. Exactly one, and
only after ApifyBudget::tryClaim — this class does not otherwise claim budget
(the controllers do), so an ungated retry would spend outside the daily cap."
```

---

### Task 3: The refresh path rejects a thin scrape and preserves what exists

The data-loss fix. `seed()`'s stale-reclaim deletes every mirrored file it did not write this run, so a thin refresh blanks the payload *and* unlinks `photo.jpg` / `reel.mp4` / `reel-cover.jpg` from R2 for a live claimed user. Not running `seed()` is the fix.

**Files:**
- Create: `app/Services/Platforms/ThinProfileException.php`
- Modify: `app/Services/Platforms/InstagramScraper.php` (`fetchProfile()`, lines 26-29)
- Modify: `app/Jobs/Platforms/InstagramConnectJob.php` (lines 125-137 and `failed()`)
- Test: `tests/Feature/Platforms/InstagramThinRefreshTest.php` (create)

**Interfaces:**
- Consumes: `ProfileFetchResult::$thin` (Task 1), `fetchProfileResult()` (Task 2).
- Produces: `App\Services\Platforms\ThinProfileException`; `InstagramConnectJob` records `last_refresh_error='thin_scrape'`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Platforms/InstagramThinRefreshTest.php`:

```php
<?php

use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// A refresh that comes back thin must change NOTHING. InstagramConnectionSeeder
// rebuilds $images from scratch and then deletes every mirrored file it did not
// write this run — so seeding a thin profile blanks the payload AND unlinks the
// user's photo and reel from R2. The guard is that seed() never runs.

beforeEach(function () {
    config([
        'services.apify.token' => 'test-token',
        'partna.instagram.actor' => 'apify~instagram-profile-scraper',
        'partna.media_disk' => 'media',
    ]);
    Storage::fake('media');
});

it('preserves the mirrored media and the payload when a refresh comes back thin', function () {
    $user = User::factory()->create();
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => [
            'username' => 'crucibletattooco',
            'postsCount' => 4164,
            'images' => ['https://cdn.example/photo.jpg'],
            'videoUrl' => 'https://cdn.example/reel.mp4',
            '_folder' => 'platforms/instagram/1700000000',
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $folder = 'platforms/instagram/1700000000';
    Storage::disk('media')->put("{$folder}/photo.jpg", 'photo-bytes');
    Storage::disk('media')->put("{$folder}/reel.mp4", 'reel-bytes');
    Storage::disk('media')->put("{$folder}/reel-cover.jpg", 'cover-bytes');

    // Thin on both the first call and the retry.
    Http::fake(['api.apify.com/*' => Http::response([[
        'username' => 'crucibletattooco',
        'fullName' => 'Crucible Tattoo Co.',
        'followersCount' => 30042,
        'private' => false,
    ]], 201)]);

    try {
        // Constructor order is (userId, username, connectionId) — NOT the
        // connection-first order the name might suggest.
        (new InstagramConnectJob($user->id, 'crucibletattooco', $connection->id))
            ->handle(app(App\Services\Platforms\InstagramScraper::class),
                app(App\Services\Platforms\InstagramConnectionSeeder::class),
                app(App\Services\Platforms\InstagramAutoSync::class));
    } catch (Throwable) {
        // $this->fail() surfaces here outside a queue worker; the assertions below
        // are the point.
    }

    // THE assertion: the bug deletes these files. Asserting only on the payload
    // would pass while the media was being destroyed.
    Storage::disk('media')->assertExists("{$folder}/photo.jpg");
    Storage::disk('media')->assertExists("{$folder}/reel.mp4");
    Storage::disk('media')->assertExists("{$folder}/reel-cover.jpg");

    $connection->refresh();
    expect($connection->payload['images'])->toBe(['https://cdn.example/photo.jpg'])
        ->and($connection->payload['postsCount'])->toBe(4164)
        ->and($connection->payload['videoUrl'])->toBe('https://cdn.example/reel.mp4');
});

it('records thin_scrape as the refresh error so it is distinguishable from real breakage', function () {
    $user = User::factory()->create();
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => ['username' => 'crucibletattooco', 'postsCount' => 4164],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    Http::fake(['api.apify.com/*' => Http::response([[
        'username' => 'crucibletattooco',
        'followersCount' => 30042,
        'private' => false,
    ]], 201)]);

    (new InstagramConnectJob($user->id, 'crucibletattooco', $connection->id))
        ->failed(new App\Services\Platforms\ThinProfileException('thin'));

    $connection->refresh();
    expect($connection->last_refresh_status)->toBe('unavailable')
        ->and($connection->last_refresh_error)->toBe('thin_scrape');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Platforms/InstagramThinRefreshTest.php`
Expected: FAIL — `Class "App\Services\Platforms\ThinProfileException" not found`, and the first test fails its `assertExists` because `seed()` ran and deleted the files.

- [ ] **Step 3: Create the exception**

Create `app/Services/Platforms/ThinProfileException.php`:

```php
<?php

namespace App\Services\Platforms;

use RuntimeException;

// A scrape that succeeded but carried no post timeline, after the retry. Typed
// rather than a bare RuntimeException so InstagramConnectJob::failed() can
// record 'thin_scrape' instead of the generic 'job_failed' — a transient
// upstream degradation and a genuinely broken connection need to be
// distinguishable when reading the connection rows back.
class ThinProfileException extends RuntimeException {}
```

- [ ] **Step 4: Make `fetchProfile()` null on thin**

Replace `fetchProfile()` in `app/Services/Platforms/InstagramScraper.php` (lines 26-29):

```php
    // Run the profile scraper, returning the first dataset item (the profile,
    // with latestPosts) or null on any failure / missing token.
    //
    // A thin profile also returns null, and that is load-bearing: this method's
    // one caller returns BEFORE seed() on null, and seed()'s stale-reclaim
    // deletes every mirrored file it did not write this run. Not running seed()
    // is what preserves a live user's payload and their R2 objects.
    //
    // $userId is threaded for log correlation. platform_connection_id is
    // intentionally not threaded: the connection row is written only AFTER a
    // successful scrape, so it doesn't exist at log time.
    public function fetchProfile(string $username, ?string $userId = null): ?array
    {
        $result = $this->fetchProfileResult($username, $userId);

        return $result->thin ? null : $result->profile;
    }
```

- [ ] **Step 5: Branch the connect job on thinness**

In `app/Jobs/Platforms/InstagramConnectJob.php`, add the import beside the existing ones:

```php
use App\Services\Platforms\ThinProfileException;
```

Replace lines 125-137 (the `fetchProfile` call and its null guard):

```php
        $result = $scraper->fetchProfileResult($this->username, $this->userId);

        if ($result->profile === null || $result->thin) {
            // Hard-fail loudly so Horizon records a failure and Nightwatch alerts —
            // a silent markFailed()+return made Horizon mark the job "succeeded",
            // hiding a broken auto-connect (JOB-4). No retry: re-running re-bills the
            // Apify scrape. failed() marks the connection 'unavailable' for the user.
            //
            // Returning HERE, before seed(), is also what preserves an existing
            // connection's payload and mirrored R2 files on a thin refresh.
            $this->fail($result->thin
                ? new ThinProfileException(
                    "Instagram scrape returned no posts for @{$this->username} (user {$this->userId})"
                )
                : new \RuntimeException(
                    "Instagram scrape returned no profile for @{$this->username} (user {$this->userId})"
                ));

            return;
        }

        $profile = $result->profile;
```

Then replace the `markFailed` call inside `failed()`:

```php
        if ($connection) {
            $this->markFailed($connection, $e instanceof ThinProfileException ? 'thin_scrape' : 'job_failed');
        }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Platforms/InstagramThinRefreshTest.php`
Expected: PASS — both tests.

Then run the neighbouring suites that exercise this job, since its signature path changed:

Run: `./vendor/bin/pest tests/Feature/Platforms/InstagramAsyncConnectTest.php tests/Feature/Platforms/InstagramJobSeederLockTest.php tests/Unit/Platforms/InstagramScraperTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
php artisan pint app/Services/Platforms/ app/Jobs/Platforms/InstagramConnectJob.php tests/Feature/Platforms/InstagramThinRefreshTest.php
git add app/Services/Platforms/ThinProfileException.php app/Services/Platforms/InstagramScraper.php app/Jobs/Platforms/InstagramConnectJob.php tests/Feature/Platforms/InstagramThinRefreshTest.php
git commit -m "fix(instagram): a thin refresh must not wipe a live user's media

InstagramConnectionSeeder::seed() rebuilds \$images from scratch and then
deletes every mirrored file it did not write this run. Fed a thin profile it
blanked the payload and unlinked photo.jpg/reel.mp4/reel-cover.jpg from R2 for
an already-connected user, on the ~12h refresh sweep, with no alarm.

The fix is to return before seed() rather than to guard the delete: not
running the destructive code cannot be broken by a later edit to it."
```

---

### Task 4: The `thin_scrape_at` column

**Files:**
- Create: `supabase/migrations/20260811090000_pre_account_builds_thin_scrape.sql`
- Modify: `app/Models/Core/User/PreAccountBuild.php` (docblock ~line 23, `$casts` ~line 84)
- Modify: `tests/Pest.php` (the defensive ALTER list, lines 568-572)

**Interfaces:**
- Produces: `core.pre_account_builds.thin_scrape_at` (`timestamptz NULL`), cast `'datetime'`, **not** fillable.

- [ ] **Step 1: Write the migration**

Create `supabase/migrations/20260811090000_pre_account_builds_thin_scrape.sql`:

```sql
-- core.pre_account_builds.thin_scrape_at — stamped when a build's Instagram
-- scrape came back with no post timeline (spec 2026-08-11).
--
-- The build stays build_state='ready'. A thin site still renders, and a
-- genuinely sparse account must never be told its build failed — so this is a
-- separate "looks suspect" axis, not a new build state. build_state carries
-- this table's only CHECK constraint and is deliberately NOT widened.
--
-- Not folded into failure_code: that column is documented as meaningful only
-- when build_state='failed', and UserStaffResource + PreAccountBuildStatusResource
-- pass it straight to the wire, so a 'ready' build carrying a failure code
-- would read as broken in the staff UI.
--
-- Nullable, no default, no index: reads are per-build or a small staff-side
-- scan, and the table is low-cardinality.
--
-- ROLLBACK: ALTER TABLE core.pre_account_builds DROP COLUMN thin_scrape_at;

ALTER TABLE core.pre_account_builds
    ADD COLUMN IF NOT EXISTS thin_scrape_at timestamptz NULL;
```

- [ ] **Step 2: Add the column to the SQLite test schema**

In `tests/Pest.php`, extend the defensive ALTER list inside `setupPreAccountBuildsTable()` (currently lines 568-572) — this is exactly what that block exists for:

```php
    // Defensive ALTER for suites that created core.pre_account_builds before
    // contact_email existed. Mirrors migration 20260721120000.
    foreach ([
        'contact_email TEXT NULL',
        'invited_at TEXT NULL',
        'auto_invite INTEGER NOT NULL DEFAULT 1',
        // Mirrors migration 20260811090000 (thin-scrape marker).
        'thin_scrape_at TEXT NULL',
    ] as $col) {
```

- [ ] **Step 3: Add the cast and docblock to the model**

In `app/Models/Core/User/PreAccountBuild.php`, add to the `@property` block after the `failure_code` line (~line 23):

```php
 * @property \Illuminate\Support\Carbon|null $thin_scrape_at Stamped when the source scrape returned no post timeline. Independent of build_state — a thin build stays 'ready'.
```

Extend `$casts` (~line 84):

```php
    protected $casts = [
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
        'invited_at' => 'datetime',
        'thin_scrape_at' => 'datetime',
        'auto_invite' => 'boolean',
    ];
```

Leave `$fillable` untouched — `thin_scrape_at` is a state column, and SEC-4 keeps state columns out of mass assignment. Extend the existing `$fillable` comment to say so:

```php
    // user_id / built_by_staff_id deliberately NOT fillable — set via associate().
    // SEC-4: build_state/claimed_at/failure_code drive the state machine and are
    // also excluded — writers use forceFill()/direct assignment (a silently
    // dropped write here strands a build in the wrong state with zero error).
    // thin_scrape_at is a state column on the same grounds.
```

- [ ] **Step 4: Verify the column exists in the test lane**

Run: `./vendor/bin/pest tests/Schema/PreAccountBuildHandleRaceTest.php`
Expected: PASS — proves `setupPreAccountBuildsTable()` still builds a usable table.

- [ ] **Step 5: Apply the migration to dev Supabase**

This must happen **before** the branch merges — code that reads a column dev does not have fails with `SQLSTATE 42703`.

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run     # confirm ONLY 20260811090000 is pending
supabase db push
```

Then confirm the column landed:

```bash
supabase db push --dry-run     # expect: no pending migrations
```

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Models/Core/User/PreAccountBuild.php tests/Pest.php
git add supabase/migrations/20260811090000_pre_account_builds_thin_scrape.sql app/Models/Core/User/PreAccountBuild.php tests/Pest.php
git commit -m "feat(pre-account): add thin_scrape_at to core.pre_account_builds

A separate 'looks suspect' axis rather than a new build_state or a reused
failure_code: the build stays 'ready' because a thin site still renders and a
genuinely sparse account must never be told its build failed, and failure_code
is wire-exposed and documented as meaningful only when build_state='failed'.

Applied to dev Supabase before merge."
```

---

### Task 5: The build path flags a thin scrape

**Files:**
- Modify: `app/Services/PreAccount/Generators/InstagramSourceGenerator.php` (lines 52-60 and after the `seed()` try/catch, ~line 108)
- Test: `tests/Feature/PreAccount/ThinScrapeBuildTest.php` (create)

**Interfaces:**
- Consumes: `ProfileFetchResult::$thin` (Task 1), `thin_scrape_at` (Task 4).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PreAccount/ThinScrapeBuildTest.php`:

```php
<?php

use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// A build that captures nothing must not be indistinguishable from one that
// captures everything. It stays 'ready' — the site renders, and a genuinely
// sparse account must never be told its build failed — but it is flagged.

beforeEach(function () {
    setupPreAccountBuildsTable();
    config([
        'services.apify.token' => 'test-token',
        'partna.instagram.actor' => 'apify~instagram-profile-scraper',
        'partna.media_disk' => 'media',
    ]);
    Storage::fake('media');
});

it('marks a build whose scrape stayed thin, without failing it', function () {
    $user = User::factory()->create();
    Site::factory()->create(['user_id' => $user->id, 'is_published' => false]);
    $build = PreAccountBuild::factory()->create([
        'source_type' => 'instagram',
        'source_ref' => 'crucibletattooco',
        'built_via' => PreAccountBuild::VIA_SIGNUP,
    ]);
    $build->user()->associate($user)->save();

    // Thin on the first call and on the retry.
    Http::fake(['api.apify.com/*' => Http::response([[
        'username' => 'crucibletattooco',
        'fullName' => 'Crucible Tattoo Co.',
        'followersCount' => 30042,
        'businessCategoryName' => 'None',
        'private' => false,
    ]], 201)]);

    (new GeneratePreAccountSiteJob($build->id, 'instagram'))
        ->handle(app(App\Services\PreAccount\SourceGeneratorRegistry::class));

    $build->refresh();
    expect($build->build_state)->toBe(PreAccountBuild::STATE_READY)
        ->and($build->failure_code)->toBeNull()
        ->and($build->thin_scrape_at)->not->toBeNull();
});

it('leaves thin_scrape_at null on a healthy build', function () {
    $user = User::factory()->create();
    Site::factory()->create(['user_id' => $user->id, 'is_published' => false]);
    $build = PreAccountBuild::factory()->create([
        'source_type' => 'instagram',
        'source_ref' => 'simondoylehair',
        'built_via' => PreAccountBuild::VIA_SIGNUP,
    ]);
    $build->user()->associate($user)->save();

    Http::fake(['api.apify.com/*' => Http::response([[
        'username' => 'simondoylehair',
        'fullName' => 'Simon Doyle',
        'followersCount' => 11065,
        'postsCount' => 365,
        'private' => false,
        'latestPosts' => array_fill(0, 12, [
            'shortCode' => 'x',
            'displayUrl' => 'https://cdn.example/p.jpg',
            'timestamp' => 1700000000,
        ]),
    ]], 201)]);

    (new GeneratePreAccountSiteJob($build->id, 'instagram'))
        ->handle(app(App\Services\PreAccount\SourceGeneratorRegistry::class));

    $build->refresh();
    expect($build->build_state)->toBe(PreAccountBuild::STATE_READY)
        ->and($build->thin_scrape_at)->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/PreAccount/ThinScrapeBuildTest.php`
Expected: FAIL — the first test finds `thin_scrape_at` null.

- [ ] **Step 3: Stamp the build in the generator**

In `app/Services/PreAccount/Generators/InstagramSourceGenerator.php`, add the import beside the existing ones:

```php
use App\Models\Core\User\PreAccountBuild;
```

Then, immediately after the `seed()` try/catch block ends (before the PRIV-2 `forceFill` strip), insert:

```php
        // Flag, don't fail: the site still renders, and a genuinely sparse account
        // must never be told its build failed. Scoped to this user's LIVE Instagram
        // build — pre_account_builds_live_source_unique guarantees at most one.
        // A direct update, matching the SEC-4 convention that state columns are not
        // mass-assignable; nothing observes this column, so no cache to invalidate.
        if ($result->thin) {
            PreAccountBuild::query()
                ->where('user_id', $user->id)
                ->where('source_type', 'instagram')
                ->whereNull('claimed_at')
                ->update(['thin_scrape_at' => now()]);
        }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/PreAccount/ThinScrapeBuildTest.php`
Expected: PASS — both tests.

- [ ] **Step 5: Run the surrounding pre-account suite**

Run: `./vendor/bin/pest tests/Feature/PreAccount/`
Expected: PASS — particularly `UnclaimedGatingTest`, which pins the unclaimed-site behaviour this path feeds.

- [ ] **Step 6: Commit**

```bash
php artisan pint app/Services/PreAccount/Generators/InstagramSourceGenerator.php tests/Feature/PreAccount/ThinScrapeBuildTest.php
git add app/Services/PreAccount/Generators/InstagramSourceGenerator.php tests/Feature/PreAccount/ThinScrapeBuildTest.php
git commit -m "feat(pre-account): flag a build whose scrape captured no posts

@crucibletattooco finished build_state='ready', failure_code=null — identical
to the two builds either side of it that captured 12 posts each. It stays
'ready' (the site renders) but now carries thin_scrape_at, so a zero-post
capture is queryable and re-runnable instead of silent."
```

---

### Task 6: Full-suite verification

**Files:** none — verification only.

- [ ] **Step 1: Run the full suite**

Run: `COMPOSER_PROCESS_TIMEOUT=0 composer test`
Expected: PASS. Without `COMPOSER_PROCESS_TIMEOUT=0` the run dies partway and reads as a failure.

- [ ] **Step 2: Run the static analysis gate**

Run: `./vendor/bin/phpstan analyse --memory-limit=2G`
Expected: no new errors. `ProfileFetchResult::ok()`'s new optional parameter and the `thin_scrape_at` cast are the likely sources if any appear.

- [ ] **Step 3: Confirm the dev migration is applied**

Run: `supabase db push --dry-run`
Expected: no pending migrations. If `20260811090000` is still listed, Task 4 Step 5 did not complete — do it now, before the branch merges.

- [ ] **Step 4: Verify against dev with a real thin-shaped payload**

Confirm the predicate agrees with the stored evidence rather than only with fixtures:

```bash
~/.composer/vendor/bin/cloud tinker development --timeout 120 --code '
$s = app(App\Services\Platforms\InstagramScraper::class);
$thin = ["username" => "crucibletattooco", "fullName" => "Crucible Tattoo Co.", "followersCount" => 30042, "businessCategoryName" => "None", "private" => false];
$ok = ["username" => "simondoylehair", "postsCount" => 365, "followersCount" => 11065, "private" => false, "latestPosts" => array_fill(0, 12, ["shortCode" => "x"])];
echo json_encode(["thin_flagged" => $s->isThinProfile($thin), "healthy_flagged" => $s->isThinProfile($ok)]);
'
```

Expected: `{"thin_flagged":true,"healthy_flagged":false}`

- [ ] **Step 5: Report**

State plainly what passed and what did not, with the actual command output. Do not claim completion on an unrun suite.

---

## Notes for the implementer

**The one test that matters most** is the `Storage::disk('media')->assertExists(...)` in Task 3. The harm on the refresh path is deleted objects in storage, not a blanked payload field — a test asserting only on the payload goes green while the media is being destroyed.

**Do not "improve" the predicate** by flagging `postsCount === 0` with no posts. It is self-consistent and indistinguishable from a genuinely empty account. `maha.restaurant` on dev (0 followers / 0 posts, two rows) is plausibly the same upstream fault, and is deliberately left unflagged — catching it needs a guess the data does not support, and the cost of a false positive (telling a real prospect their build is broken) exceeds the cost of a false negative (one thin site someone re-runs).

**Do not add `businessCategory` to the predicate.** `"None"` is the normal value for an account with no subvertical; the successful re-probe of the failing account returns it, as do `natgeo` and `hungryjacksau` with complete data. It appeared in the original bug report as a symptom and is not one.

**Task 2's `attemptFetch()` must receive the old `fetchProfileResult()` body verbatim.** The pre-existing actor-input-shape tests in `InstagramProfileFetchTest.php` are the tripwire: if they fail after Task 2, the body was altered in the move.
