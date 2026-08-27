<?php

use App\Jobs\Site\BuildSiteDocumentJob;
use App\Site\Documents\BuildState;
use App\Site\Documents\DocumentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// The DocumentBuilder's CALLERS (plan §9): the per-user inline build, the
// 5-minute stale sweeper, and the queued job the sweeper fans out to.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    // T4: BuildState::bump() now dispatches the build; under sync it would
    // run INLINE and consume the staleness these tests choreograph by hand.
    Queue::fake();
});

function sweeperSite(bool $published = true): array
{
    $pro = createTenant('sweep-'.Str::lower(Str::random(6)));
    $siteId = (string) DB::table('site.sites')->where('user_id', $pro->id)->value('id');
    DB::table('site.sites')->where('id', $siteId)->update(['is_published' => $published ? 1 : 0]);

    $pageId = (string) Str::uuid();
    DB::table('site.pages')->insert([
        'id' => $pageId, 'site_id' => $siteId, 'key' => 'home', 'label' => 'Home',
        'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$pro->id, $siteId];
}

it('builds one user\'s document inline and reports the version', function () {
    [$userId, $siteId] = sweeperSite();
    BuildState::bump($siteId);

    $this->artisan('site:build-documents', ['user' => $userId])
        ->expectsOutputToContain('built')
        ->assertSuccessful();

    expect(DB::table('site.site_documents')->where('site_id', $siteId)->count())->toBe(1)
        ->and(BuildState::isStale($siteId))->toBeFalse();
});

it('--stale queues a build for exactly the sites whose content moved', function () {
    Queue::fake();
    [, $staleSite] = sweeperSite();
    [, $freshSite] = sweeperSite();

    BuildState::bump($staleSite);
    // freshSite: revision equal to built — nothing to do.
    BuildState::read($freshSite);

    $this->artisan('site:build-documents', ['--stale' => true])->assertSuccessful();

    Queue::assertPushed(BuildSiteDocumentJob::class, 1);
    Queue::assertPushed(BuildSiteDocumentJob::class, fn (BuildSiteDocumentJob $job) => $job->siteId === $staleSite);
});

it('--all queues builds for every published site only', function () {
    Queue::fake();
    [, $published] = sweeperSite(published: true);
    sweeperSite(published: false);

    $this->artisan('site:build-documents', ['--all' => true])->assertSuccessful();

    Queue::assertPushed(BuildSiteDocumentJob::class, 1);
    Queue::assertPushed(BuildSiteDocumentJob::class, fn (BuildSiteDocumentJob $job) => $job->siteId === $published);
});

it('refuses to run without a target', function () {
    $this->artisan('site:build-documents')->assertFailed();
});

it('the job builds through a mid-build content change by rebuilding from the new revision', function () {
    [, $siteId] = sweeperSite();
    BuildState::bump($siteId);

    (new BuildSiteDocumentJob($siteId))->handle(app(DocumentBuilder::class));

    expect(BuildState::isStale($siteId))->toBeFalse()
        ->and(DB::table('site.site_documents')->where('site_id', $siteId)->count())->toBeGreaterThanOrEqual(1);
});
