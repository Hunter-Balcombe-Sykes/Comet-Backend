<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Pins the behaviour that BlockObserver + SiteMediaObserver advance
// `sites.updated_at` on every mutation, which fires SiteObserver::saved
// which dispatches `CloudflareCachePurgeJob`. That chain is what keeps
// edits at `<handle>.partna.au` near-instant (~5–30s globally) — without
// it, the Cloudflare edge cache holds stale HTML for the `s-maxage`
// window (~5 min) before refreshing.
//
// We assert the queue dispatch (the observable outcome) rather than the
// intermediate timestamp on `sites.updated_at`, since Eloquent's
// `afterCommit = true` semantics interact awkwardly with the test
// scope's transaction wrapping.

beforeEach(function () {
    setupProfessionalsTable();
    setupSitesTable();
    setupBlocksTable();
    setupMediaTables();
});

function seedTouchFixture(): array
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'touchtest',
        'handle_lc' => 'touchtest',
        'display_name' => 'Touch Test',
        'professional_type' => 'professional',
        'account_type' => 'individual',
        'status' => 'active',
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $proId,
        'subdomain' => 'touchtest',
        'is_published' => 1,
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
    ]);

    return ['pro_id' => $proId, 'site_id' => $siteId];
}

it('Block::create dispatches CloudflareCachePurgeJob via the touch() chain', function () {
    Queue::fake();
    $fixture = seedTouchFixture();

    Block::create([
        'professional_id' => $fixture['pro_id'],
        'site_id' => $fixture['site_id'],
        'block_type' => 'bio',
        'block_group' => 'sections',
        'sort_order' => 0,
        'is_active' => true,
        'is_enabled' => true,
        'settings' => ['body' => 'Hi'],
    ]);

    Queue::assertPushed(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) {
        return $job->handle === 'touchtest';
    });
});

it('Block::save (update) dispatches CloudflareCachePurgeJob via the touch() chain', function () {
    $fixture = seedTouchFixture();
    $block = Block::create([
        'professional_id' => $fixture['pro_id'],
        'site_id' => $fixture['site_id'],
        'block_type' => 'link',
        'block_group' => 'links',
        'title' => 'Original',
        'url' => 'https://a.example.test',
        'sort_order' => 0,
        'is_active' => true,
        'is_enabled' => true,
        'settings' => [],
    ]);

    // Fake AFTER create() so we only count purges fired by the update path.
    Queue::fake();

    $block->title = 'Renamed';
    $block->save();

    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('Block::delete dispatches CloudflareCachePurgeJob via the touch() chain', function () {
    $fixture = seedTouchFixture();
    $block = Block::create([
        'professional_id' => $fixture['pro_id'],
        'site_id' => $fixture['site_id'],
        'block_type' => 'link',
        'block_group' => 'links',
        'title' => 'About to die',
        'url' => 'https://b.example.test',
        'sort_order' => 0,
        'is_active' => true,
        'is_enabled' => true,
        'settings' => [],
    ]);

    Queue::fake();
    $block->delete();
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('SiteMedia::create dispatches CloudflareCachePurgeJob via the touch() chain', function () {
    Queue::fake();
    $fixture = seedTouchFixture();

    SiteMedia::create([
        'site_id' => $fixture['site_id'],
        'professional_id' => $fixture['pro_id'],
        'pool' => 'content',
        'path' => 'images/test.webp',
        'media_type' => 'image',
        'processing_state' => 'ready',
        'sort_order' => 0,
        'is_active' => true,
    ]);

    Queue::assertPushed(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) {
        return $job->handle === 'touchtest';
    });
});

it('SiteMedia::delete dispatches CloudflareCachePurgeJob via the touch() chain', function () {
    $fixture = seedTouchFixture();
    $media = SiteMedia::create([
        'site_id' => $fixture['site_id'],
        'professional_id' => $fixture['pro_id'],
        'pool' => 'content',
        'path' => 'images/test.webp',
        'media_type' => 'image',
        'processing_state' => 'ready',
        'sort_order' => 0,
        'is_active' => true,
    ]);

    Queue::fake();
    $media->delete();
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

// Bulk reorder paths bypass Eloquent observers (mass `update(['sort_order'])`
// via the query builder). The controllers explicitly call `$site->touch()`
// after the transaction commits to bridge the gap. Pin that contract here.

it('explicit $site->touch() after a mass update dispatches CloudflareCachePurgeJob', function () {
    $fixture = seedTouchFixture();
    Queue::fake();

    \App\Models\Core\Site\Site::find($fixture['site_id'])->touch();

    Queue::assertPushed(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) {
        return $job->handle === 'touchtest';
    });
});
