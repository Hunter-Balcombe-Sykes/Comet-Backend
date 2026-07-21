<?php

// Unit 11 W3 (LIFE-21) — the lock-boundary fix. BandcampHighlights::apply()
// used to call $this->scraper->enrichPrices() TWICE (latest tile + curated
// highlights), and GenericPlatformController::highlights() called
// $strategy->recentItems() directly — both from INSIDE withConnectionLock().
// A lock is a deliberate bottleneck (it serialises concurrent work); holding
// one across a vendor fetch lets a stranger's latency decide how long that
// bottleneck lasts.
//
// CACHE_STORE=array in phpunit.xml, so Cache::lock() here is a REAL ArrayLock
// (confirmed non-re-entrant in-process — see BookingXorLockTest /
// GoogleBusinessEnrichConcurrencyTest for the same probe idiom this file
// reuses: acquire-and-immediately-release to detect contention).
//
// Lock key note: GenericPlatformController::highlights() calls
// withConnectionLock($user, $callback), which builds the platform-wide key
// CacheKeyGenerator::platformConnectionLock('bandcamp', $user->id) — no
// per-account suffix exists any more (removed 2026-07-21; ScheduledRefresh /
// ConnectFetchJob / GoogleBusinessEnrichJob now build this exact same key for
// every account of a platform, closing the lost-update gap where a suffixed
// writer and an unsuffixed writer built different strings and never excluded
// each other). So even though bandcamp is a multi-account platform (its row's
// resource_id is 'acct-<hash>', not 'bandcamp'), the highlights() lock is
// always the platform-wide key — every /highlights save for one user+platform
// serialises against every other, across all that platform's accounts, AND
// against a concurrent scheduled refresh or connect-fetch job on any of them.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\BandcampScraper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

// HELPER COLLISION WARNING: hsUser()/hpUser() are already declared by
// HighlightsSnapshotTest.php / HighlightsPickerTest.php — Pest loads every
// test file into one process, so this file's helper is uniquely prefixed.
function hlbUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function hlbLockKey(User $user): string
{
    return CacheKeyGenerator::platformConnectionLock('bandcamp', $user->id);
}

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it("prepare()'s enrichPrices call runs OUTSIDE the per-user connection lock", function () {
    $user = hlbUser('lb1');

    $connectProfile = ['name' => 'Artist', 'thumbnail' => null, 'items' => [
        ['itemId' => 'album-1', 'name' => 'Album One', 'thumbnail' => 't1', 'link' => 'https://artist.bandcamp.com/album/one'],
    ]];

    // Every enrichPrices call (connect's + the highlights save's) probes the
    // lock and records whether it was already held. connect() never locks at
    // all, so its entry is a sanity baseline; the save-time entry is the
    // proof this test exists for.
    $heldFlags = [];
    $this->mock(BandcampScraper::class, function ($m) use ($connectProfile, $user, &$heldFlags) {
        $m->shouldReceive('normalizeOrigin')->andReturn('https://artist.bandcamp.com');
        $m->shouldReceive('fetchProfile')->once()->andReturn($connectProfile);
        $m->shouldReceive('enrichPrices')->andReturnUsing(function (array $items) use ($user, &$heldFlags) {
            $probe = Cache::lock(hlbLockKey($user), 1);
            $acquired = (bool) $probe->get();
            if ($acquired) {
                $probe->release();
            }
            $heldFlags[] = ! $acquired;

            return $items;
        });
    });

    actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://artist.bandcamp.com'])
        ->assertOk();

    actingAsUser($user)->postJson('/api/platforms/bandcamp/highlights', ['itemIds' => ['album-1']])
        ->assertOk();

    // Against unfixed code (apply() calling enrichPrices twice from INSIDE
    // withConnectionLock's closure), the save-time probe collides with the
    // lock the outer withConnectionLock() call is already holding and fails
    // to acquire, so this array contains a `true`.
    expect($heldFlags)->not->toContain(true);
});

it("the picker's live re-fetch (HighlightsPicker::items -> recentItems -> fetchProfile) also runs OUTSIDE the lock", function () {
    // This is the OTHER half of the same defect: GenericPlatformController::
    // highlights() used to call $strategy->recentItems() directly from
    // inside withConnectionLock(). Forces the picker's live-fetch branch by
    // aging the connect-time snapshot past the freshness TTL.
    $user = hlbUser('lb2');

    $connectProfile = ['name' => 'Artist', 'thumbnail' => null, 'items' => [
        ['itemId' => 'album-1', 'name' => 'Album One', 'thumbnail' => 't1', 'link' => 'https://artist.bandcamp.com/album/one'],
    ]];
    $saveProfile = ['name' => 'Artist', 'thumbnail' => null, 'items' => [
        ['itemId' => 'album-2', 'name' => 'Album Two', 'thumbnail' => 't2', 'link' => 'https://artist.bandcamp.com/album/two'],
    ]];

    $fetchCalls = 0;
    $fetchHeldFlags = [];
    $this->mock(BandcampScraper::class, function ($m) use ($connectProfile, $saveProfile, $user, &$fetchCalls, &$fetchHeldFlags) {
        $m->shouldReceive('normalizeOrigin')->andReturn('https://artist.bandcamp.com');
        $m->shouldReceive('fetchProfile')->andReturnUsing(function () use (&$fetchCalls, &$fetchHeldFlags, $connectProfile, $saveProfile, $user) {
            $fetchCalls++;
            if ($fetchCalls === 2) {
                // The save-time re-fetch — the one this test targets.
                $probe = Cache::lock(hlbLockKey($user), 1);
                $acquired = (bool) $probe->get();
                if ($acquired) {
                    $probe->release();
                }
                $fetchHeldFlags[] = ! $acquired;
            }

            return $fetchCalls === 1 ? $connectProfile : $saveProfile;
        });
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn (array $items) => $items);
    });

    actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://artist.bandcamp.com'])
        ->assertOk();

    // Age the snapshot past the freshness TTL so picker->items() takes the
    // live branch on the highlights save below (see HighlightsPicker::fresh()).
    IntegrationConnection::where('user_id', $user->id)->where('platform', 'bandcamp')
        ->update(['last_refreshed_at' => now()->subHours(25)]);

    actingAsUser($user)->postJson('/api/platforms/bandcamp/highlights', ['itemIds' => ['album-2']])
        ->assertOk()
        ->assertJsonPath('highlights.0.itemId', 'album-2');

    expect($fetchCalls)->toBe(2); // sanity: the live branch really fired
    // Against unfixed code, $strategy->recentItems() (-> fetchProfile) runs
    // directly inside withConnectionLock()'s closure, so this probe cannot
    // acquire and the flag is `true`.
    expect($fetchHeldFlags)->toBe([false]);
});

it('collapses two enrichPrices rounds into one on save, without dropping the latest-tile price', function () {
    $user = hlbUser('lb3');

    $items = [
        ['itemId' => 'album-0', 'name' => 'Latest', 'thumbnail' => 't0', 'link' => 'https://artist.bandcamp.com/album/latest'],
        ['itemId' => 'album-1', 'name' => 'One', 'thumbnail' => 't1', 'link' => 'https://artist.bandcamp.com/album/one'],
    ];
    $connectProfile = ['name' => 'Artist', 'thumbnail' => null, 'items' => $items];

    $calls = 0;
    $this->mock(BandcampScraper::class, function ($m) use ($connectProfile, &$calls) {
        $m->shouldReceive('normalizeOrigin')->andReturn('https://artist.bandcamp.com');
        $m->shouldReceive('fetchProfile')->once()->andReturn($connectProfile);
        $m->shouldReceive('enrichPrices')->andReturnUsing(function (array $items) use (&$calls) {
            $calls++;
            foreach ($items as $i => $item) {
                $items[$i]['price'] = '9.99';
                $items[$i]['currency'] = 'USD';
            }

            return $items;
        });
    });

    actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://artist.bandcamp.com'])
        ->assertOk();

    $response = actingAsUser($user)->postJson('/api/platforms/bandcamp/highlights', ['itemIds' => ['album-1']])
        ->assertOk();

    // 1 call from connect() + 1 from the save's prepare(). Against unfixed
    // code, apply() ALSO calls enrichPrices twice (latest tile + highlights),
    // so this would be 3.
    expect($calls)->toBe(2);

    // Collapsing the two apply()-time calls into prepare()'s one must not
    // silently drop pricing on either output.
    $response->assertJsonPath('latest.price', '9.99')
        ->assertJsonPath('highlights.0.price', '9.99');
});

it('prices exactly the 5 real picks when the request also carries an unresolvable id (chosenIndices trap) — VACUOUS against pre-W3 code, labelled', function () {
    // Both the OLD apply() (->filter()->take(5)) and the NEW chosenIndices()
    // helper already resolve-then-take correctly, so this test does not
    // distinguish pre-W3 controller behaviour from post-W3 — it exists to pin
    // the correctness trap the plan calls out for the NEW code: a naive
    // prepare() that did array_slice($chosenIds, 0, 5) BEFORE resolving ids
    // would silently leave the 5th real pick (album-5) unpriced whenever an
    // unresolvable id sorts ahead of it in the request, exactly as here
    // ('nope' is first).
    $user = hlbUser('lb4');

    $items = array_map(fn (int $i) => [
        'itemId' => "album-{$i}", 'name' => "Album {$i}", 'thumbnail' => "t{$i}",
        'link' => "https://artist.bandcamp.com/album/{$i}",
    ], range(0, 5)); // album-0 (latest tile) .. album-5
    $connectProfile = ['name' => 'Artist', 'thumbnail' => null, 'items' => $items];

    // UNION of ids seen across every save-time enrichPrices call — not just
    // "the second call". Deliberate: pre-W3 code makes TWO separate save-time
    // calls (latest tile, then highlights) whose ids only add up to the full
    // set across BOTH calls; keying on "the second call" would make this
    // test fail against unfixed code for the WRONG reason (a call-count
    // difference, not the chosenIndices trap it's meant to isolate) and
    // falsely contradict the "vacuous against pre-W3 code" label below.
    $pricedDuringSave = [];
    $sawConnectCall = false;
    $this->mock(BandcampScraper::class, function ($m) use ($connectProfile, &$pricedDuringSave, &$sawConnectCall) {
        $m->shouldReceive('normalizeOrigin')->andReturn('https://artist.bandcamp.com');
        // No call-count constraint: pre-W3 code re-fetches at save time (this
        // test isn't about that — HighlightsSnapshotTest covers it), fixed
        // code serves the connect-time snapshot. Both are fine here since
        // every call returns the same fixture.
        $m->shouldReceive('fetchProfile')->andReturn($connectProfile);
        $m->shouldReceive('enrichPrices')->andReturnUsing(function (array $items) use (&$pricedDuringSave, &$sawConnectCall) {
            // First call is connect()'s own (single-item) latest-tile pricing;
            // every call after that is part of the save this test checks.
            if (! $sawConnectCall) {
                $sawConnectCall = true;
            } else {
                $pricedDuringSave = [...$pricedDuringSave, ...array_column($items, 'itemId')];
            }
            foreach ($items as $i => $item) {
                $items[$i]['price'] = '5.00';
            }

            return $items;
        });
    });

    actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://artist.bandcamp.com'])
        ->assertOk();

    $response = actingAsUser($user)->postJson('/api/platforms/bandcamp/highlights', [
        'itemIds' => ['nope', 'album-1', 'album-2', 'album-3', 'album-4', 'album-5'],
    ])->assertOk();

    // All 5 REAL picks priced, plus album-0 (latest tile, priced
    // unconditionally) — 'nope' contributes nothing. A naive slice-then-
    // resolve would only price album-1..album-4 here (the first 5 raw ids,
    // one of which is the unresolvable 'nope').
    $pricedDuringSave = array_unique($pricedDuringSave);
    sort($pricedDuringSave);
    expect($pricedDuringSave)->toBe(['album-0', 'album-1', 'album-2', 'album-3', 'album-4', 'album-5']);

    expect($response->json('highlights.*.itemId'))->toBe(['album-1', 'album-2', 'album-3', 'album-4', 'album-5']);
    expect($response->json('latest.price'))->toBe('5.00');
    foreach ($response->json('highlights') as $h) {
        expect($h['price'])->toBe('5.00');
    }
});

it('a duplicate chosen id collapses to one highlight without costing the user a distinct pick — PINS NEW behaviour, vacuous by definition against pre-W3 code', function () {
    // Review follow-up (W3 independent review, round 2): chosenIndices()
    // dedupes — the original apply() (collect($chosenIds)->map()->filter()->
    // take(5)) did NOT, so a duplicate id occupied two of the five slots
    // there, dropping a later distinct pick to make room. Deduping is the
    // better outcome (a repeated id shouldn't cost the user a slot) and is
    // being KEPT deliberately — this test exists only to PIN the exact shape
    // of the new behaviour, not to compare it against the old one, so it is
    // vacuous by definition against pre-W3 code (there is no "before" to
    // diverge from — this scenario is new).
    //
    // An EARLIER version of chosenIndices() capped at MAX_HIGHLIGHTS on the
    // raw (pre-dedup) submission order and only deduped afterwards — that
    // meant a duplicate sitting early in the request could crowd a later
    // distinct pick out of the window entirely (5 submitted ids, one a
    // repeat, yielded only 4 highlights). Fixed to dedupe BEFORE capping, so
    // the cap counts DISTINCT resolvable picks: a duplicate anywhere in the
    // request costs nothing, and 5 distinct real ids always yield 5
    // highlights regardless of where the duplicate sits. The second
    // assertion below (dup moved to the end) is what actually pins that fix
    // — position-dependence was the observable symptom of the bug.
    $user = hlbUser('lb6');

    $connectProfile = ['name' => 'Artist', 'thumbnail' => null, 'items' => array_map(fn (int $i) => [
        'itemId' => "album-{$i}", 'name' => "Album {$i}", 'thumbnail' => "t{$i}",
        'link' => "https://artist.bandcamp.com/album/{$i}",
    ], range(0, 5))]; // album-0 (latest tile) .. album-5

    $this->mock(BandcampScraper::class, function ($m) use ($connectProfile) {
        $m->shouldReceive('normalizeOrigin')->andReturn('https://artist.bandcamp.com');
        $m->shouldReceive('fetchProfile')->andReturn($connectProfile);
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn (array $items) => $items);
    });

    actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://artist.bandcamp.com'])
        ->assertOk();

    // Duplicate FIRST: 6 raw ids, 5 distinct, album-1 submitted twice up front.
    $dupFirst = actingAsUser($user)->postJson('/api/platforms/bandcamp/highlights', [
        'itemIds' => ['album-1', 'album-1', 'album-2', 'album-3', 'album-4', 'album-5'],
    ])->assertOk();

    // Deduped to ONE album-1 — and the user's 5th real pick (album-5) is NOT
    // dropped to make room for the duplicate.
    expect($dupFirst->json('highlights.*.itemId'))->toBe(['album-1', 'album-2', 'album-3', 'album-4', 'album-5']);
    expect($dupFirst->json('highlights'))->toHaveCount(5);

    // Duplicate LAST: same 5 distinct ids, same duplicated id, only its
    // position in the submission changes. This is the assertion that pins
    // the actual fix — the earlier (cap-then-dedupe) code was
    // position-dependent (this variant already gave 5; only "dup first" was
    // broken), so a result that MATCHES dupFirst exactly is what proves the
    // cap no longer depends on where the duplicate sits.
    $dupLast = actingAsUser($user)->postJson('/api/platforms/bandcamp/highlights', [
        'itemIds' => ['album-1', 'album-2', 'album-3', 'album-4', 'album-5', 'album-1'],
    ])->assertOk();

    expect($dupLast->json('highlights.*.itemId'))->toBe($dupFirst->json('highlights.*.itemId'));
    expect($dupLast->json('highlights'))->toHaveCount(5);
});

it('the in-lock re-read means a concurrent write landing before the lock is acquired survives the save', function () {
    // Proves the "authoritative re-read UNDER the lock" line in
    // GenericPlatformController::highlights() is load-bearing. prepare()
    // (via its enrichPrices mock) runs BEFORE the lock is acquired — proven
    // by the lock-boundary tests above — so it's the perfect hook to
    // simulate a concurrent write (e.g. a second highlights save, or a
    // scheduled refresh) landing in the gap between the outside-lock read
    // and the lock being taken.
    $user = hlbUser('lb5');

    $connectProfile = ['name' => 'Artist', 'thumbnail' => null, 'items' => [
        ['itemId' => 'album-1', 'name' => 'Album One', 'thumbnail' => 't1', 'link' => 'https://artist.bandcamp.com/album/one'],
    ]];

    $this->mock(BandcampScraper::class, function ($m) use ($connectProfile, $user) {
        $m->shouldReceive('normalizeOrigin')->andReturn('https://artist.bandcamp.com');
        $m->shouldReceive('fetchProfile')->once()->andReturn($connectProfile);
        $m->shouldReceive('enrichPrices')->andReturnUsing(function (array $items) use ($user) {
            $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'bandcamp')->first();
            // Guard: connect()'s own enrichPrices call fires before the row
            // exists at all — only the save-time call (row already created)
            // should simulate the concurrent write.
            if ($row) {
                $row->update(['payload' => [...$row->payload, 'artist' => 'Concurrently Updated Artist']]);
            }

            return $items;
        });
    });

    actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://artist.bandcamp.com'])
        ->assertOk();

    actingAsUser($user)->postJson('/api/platforms/bandcamp/highlights', ['itemIds' => ['album-1']])
        ->assertOk();

    $stored = IntegrationConnection::where('user_id', $user->id)->where('platform', 'bandcamp')->first()->payload;

    // If the write were based on the OUTSIDE-lock read (no in-lock re-read),
    // apply() would run against the payload as it stood before the
    // concurrent write landed, and writeConnection() would clobber 'artist'
    // back to the original connect-time value — the lost update the re-read
    // exists to prevent.
    expect($stored['artist'])->toBe('Concurrently Updated Artist');
});
