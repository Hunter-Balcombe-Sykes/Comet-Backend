<?php

use App\Jobs\Platforms\ScanPreviousWebsiteContentJob;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Queue::fake() is called AGAIN after each test's setup, right before
// invoking the command — Workplace::create() with a previous_website set
// now ALSO dispatches via WorkplaceObserver's own trigger (Task A4.11),
// which would otherwise contaminate these assertions about what the
// BACKFILL COMMAND specifically dispatches (confirmed directly: the first
// draft of this file asserted against a job the observer had pushed during
// setup, not one the command under test pushed).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupWorkplacesTable();
});

// Single reset point for the two tests below that freeze time — if the test
// body throws before reaching its own reset line, the frozen clock would
// otherwise leak into every later test in this file.
afterEach(function () {
    Carbon::setTestNow();
});

it('dispatches a content scan for every workplace with a previous_website already set', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    Queue::fake();
    Workplace::create(['site_id' => (string) $site->id, 'previous_website' => 'https://venue.example']);
    Queue::fake(); // reset — isolate the command's own dispatch from the observer's

    $this->artisan('partna:backfill-previous-website-content-scan')->assertSuccessful();

    Queue::assertPushed(ScanPreviousWebsiteContentJob::class, fn ($job) => $job->userId === (string) $user->id
        && $job->siteId === (string) $site->id
        && $job->url === 'https://venue.example');
});

it('skips a workplace with a blank previous_website', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    Workplace::create(['site_id' => (string) $site->id]);
    Queue::fake();

    $this->artisan('partna:backfill-previous-website-content-scan')->assertSuccessful();

    Queue::assertNotPushed(ScanPreviousWebsiteContentJob::class);
});

it('dry-run dispatches nothing', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();
    Queue::fake();
    Workplace::create(['site_id' => (string) $site->id, 'previous_website' => 'https://venue.example']);
    Queue::fake(); // reset — isolate the command's own dispatch from the observer's

    $this->artisan('partna:backfill-previous-website-content-scan --dry-run --stagger-seconds=10')
        ->expectsOutputToContain('staggered 10s apart')
        ->assertSuccessful();

    Queue::assertNotPushed(ScanPreviousWebsiteContentJob::class);
});

it('is a safe no-op when there are no workplaces with a previous_website', function () {
    Queue::fake();
    $this->artisan('partna:backfill-previous-website-content-scan')->assertSuccessful();
    Queue::assertNotPushed(ScanPreviousWebsiteContentJob::class);
});

// Raw bulk inserts — not factories: ~2 statements instead of ~600 for 201 rows,
// and they bypass WorkplaceObserver entirely so the command's own dispatches are
// never contaminated by the observer's previous_website-changed trigger (no
// Queue::fake()-twice dance needed).
//
// One DISTINCT user_id per row (not a shared factory-made User) — the command
// reads user_id straight off the site row with no FK check in this stand-in
// schema, and ScanPreviousWebsiteContentJob's ShouldBeUnique lock keys on
// userId alone. A shared user_id across every row would make every dispatch
// but the first collide on the same unique lock and get silently swallowed,
// which is a real production behaviour (a re-run for the SAME user within
// 300s) but not what these stagger tests are pinning.
function insertStaggerFixture(int $count, ?int $withoutSiteAt = null): void
{
    $siteRows = [];
    $workplaceRows = [];

    for ($i = 0; $i < $count; $i++) {
        $siteId = (string) Str::uuid();

        if ($i !== $withoutSiteAt) {
            $siteRows[] = [
                'id' => $siteId,
                'user_id' => (string) Str::uuid(),
                'subdomain' => 'stagger-'.$i.'-'.Str::random(6),
            ];
        }

        $workplaceRows[] = [
            'site_id' => $siteId,
            'previous_website' => 'https://venue-'.$i.'.example',
        ];
    }

    if ($siteRows !== []) {
        DB::table('site.sites')->insert($siteRows);
    }
    DB::table('site.workplaces')->insert($workplaceRows);
}

function pushedDelays(): Collection
{
    return Queue::pushed(ScanPreviousWebsiteContentJob::class)
        ->map(fn ($j) => $j->delay === null ? 0 : (int) now()->diffInSeconds($j->delay))
        ->values();
}

it('staggers cumulatively across the whole run, not per batch of 200', function () {
    Carbon::setTestNow(now());
    insertStaggerFixture(201);
    Queue::fake();

    $this->artisan('partna:backfill-previous-website-content-scan')->assertSuccessful();

    $delays = pushedDelays();

    // FAILS BEFORE THE FIX: the old code re-indexes at the ->values() chunk
    // boundary, so job #201 (the sole member of the second chunk of 200) lands
    // back at delay 0 alongside job #1 instead of continuing the ramp — two
    // jobs racing at the same instant is exactly the defect this test pins.
    expect($delays->all())->toBe(range(0, 200 * 2, 2));
    expect($delays->filter(fn ($d) => $d === 0)->count())->toBe(1);
});

it('paces the ramp by jobs dispatched, not by rows scanned', function () {
    Carbon::setTestNow(now());
    // Second workplace (index 1) points at a site_id with no matching site row —
    // inserted as a workplace only, never as a site.
    insertStaggerFixture(4, withoutSiteAt: 1);
    Queue::fake();

    $this->artisan('partna:backfill-previous-website-content-scan --stagger-seconds=10')->assertSuccessful();

    $delays = pushedDelays();

    // Three jobs dispatched (one row skipped for a null site), no hole in the
    // schedule: delay tracks jobs DISPATCHED, not the row's position in $rows.
    expect($delays->all())->toBe([0, 10, 20]);
});

it('--limit caps the run', function () {
    insertStaggerFixture(5);
    Queue::fake();

    $this->artisan('partna:backfill-previous-website-content-scan --limit=2')->assertSuccessful();

    Queue::assertPushed(ScanPreviousWebsiteContentJob::class, 2);
});

it('rejects a negative --stagger-seconds instead of silently flooding the billed queue', function () {
    insertStaggerFixture(3);
    Queue::fake();

    $this->artisan('partna:backfill-previous-website-content-scan --stagger-seconds=-5')
        ->assertFailed();

    // The number that matters: nothing reached the billed scraping queue.
    Queue::assertNotPushed(ScanPreviousWebsiteContentJob::class);
});
