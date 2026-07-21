<?php

use App\Jobs\Platforms\ScanPreviousWebsiteContentJob;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Queue;

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

    $this->artisan('partna:backfill-previous-website-content-scan --dry-run')->assertSuccessful();

    Queue::assertNotPushed(ScanPreviousWebsiteContentJob::class);
});

it('is a safe no-op when there are no workplaces with a previous_website', function () {
    Queue::fake();
    $this->artisan('partna:backfill-previous-website-content-scan')->assertSuccessful();
    Queue::assertNotPushed(ScanPreviousWebsiteContentJob::class);
});
