<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
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
    setupUsersTable();
    setupSitesTable();
    setupSubdomainAliasesTable();
    setupBlocksTable();
    setupMediaTables();
});

function seedTouchFixture(): array
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'touchtest',
        'handle_lc' => 'touchtest',
        'display_name' => 'Touch Test',
        'first_name' => 'Touch Test',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => '2020-01-01 00:00:00',
        'updated_at' => '2020-01-01 00:00:00',
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $proId,
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

    // user_id/site_id removed from Block's $fillable (S4 Tier 2b) — both NOT
    // NULL, so Block::create() would now 500 on a dropped FK. Set directly.
    $block = new Block([
        'block_type' => 'newsletter',
        'block_group' => 'sections',
        'sort_order' => 0,
        'is_active' => true,
        'is_enabled' => true,
        'settings' => ['body' => 'Hi'],
    ]);
    $block->user_id = $fixture['pro_id'];
    $block->site_id = $fixture['site_id'];
    $block->save();

    Queue::assertPushed(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) {
        return $job->handle === 'touchtest';
    });
});

it('Block::save (update) dispatches CloudflareCachePurgeJob via the touch() chain', function () {
    $fixture = seedTouchFixture();
    // user_id/site_id removed from Block's $fillable (S4 Tier 2b) — set directly.
    $block = new Block([
        'block_type' => 'link',
        'block_group' => 'links',
        'title' => 'Original',
        'url' => 'https://a.example.test',
        'sort_order' => 0,
        'is_active' => true,
        'is_enabled' => true,
        'settings' => [],
    ]);
    $block->user_id = $fixture['pro_id'];
    $block->site_id = $fixture['site_id'];
    $block->save();

    // Fake AFTER create() so we only count purges fired by the update path.
    Queue::fake();

    $block->title = 'Renamed';
    $block->save();

    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('Block::delete dispatches CloudflareCachePurgeJob via the touch() chain', function () {
    $fixture = seedTouchFixture();
    // user_id/site_id removed from Block's $fillable (S4 Tier 2b) — set directly.
    $block = new Block([
        'block_type' => 'link',
        'block_group' => 'links',
        'title' => 'About to die',
        'url' => 'https://b.example.test',
        'sort_order' => 0,
        'is_active' => true,
        'is_enabled' => true,
        'settings' => [],
    ]);
    $block->user_id = $fixture['pro_id'];
    $block->site_id = $fixture['site_id'];
    $block->save();

    Queue::fake();
    $block->delete();
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('SiteMedia::create dispatches CloudflareCachePurgeJob via the touch() chain', function () {
    Queue::fake();
    $fixture = seedTouchFixture();

    (new SiteMedia([
        'pool' => 'content',
        'path' => 'images/test.webp',
        'media_type' => 'image',
        'processing_state' => 'ready',
        'sort_order' => 0,
        'is_active' => true,
    ]))->site()->associate($fixture['site_id'])->save();

    Queue::assertPushed(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) {
        return $job->handle === 'touchtest';
    });
});

it('SiteMedia::delete dispatches CloudflareCachePurgeJob via the touch() chain', function () {
    $fixture = seedTouchFixture();
    $media = new SiteMedia([
        'pool' => 'content',
        'path' => 'images/test.webp',
        'media_type' => 'image',
        'processing_state' => 'ready',
        'sort_order' => 0,
        'is_active' => true,
    ]);
    $media->site()->associate($fixture['site_id']);
    $media->save();

    Queue::fake();
    $media->delete();
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('SiteMedia::delete clears the public payload cache via SiteObserver (invalidation, not just purge)', function () {
    Queue::fake();
    $fixture = seedTouchFixture();
    $media = new SiteMedia([
        'pool' => 'content',
        'path' => 'images/test.webp',
        'media_type' => 'image',
        'processing_state' => 'ready',
        'sort_order' => 0,
        'is_active' => true,
    ]);
    $media->site()->associate($fixture['site_id']);
    $media->save();

    // Seed the key AFTER create() so the create's own invalidation can't pre-clear it.
    $key = CacheKeyGenerator::publicSitePayload('touchtest');
    Cache::put($key, ['stale' => true], 600);

    $media->delete();

    expect(Cache::get($key))->toBeNull();
});

// Bulk reorder paths bypass Eloquent observers (mass `update(['sort_order'])`
// via the query builder). The controllers explicitly call `$site->touch()`
// after the transaction commits to bridge the gap. Pin that contract here.

// EDGE-4: deleting a site must purge the Cloudflare edge cache, not just Redis —
// otherwise the deleted page is served from the edge for up to 24h/7d. SiteObserver::deleted
// dispatches the purge mirroring saved(). (Account-purge cascades via DB FK and bypasses
// this observer, handling edge invalidation separately — this covers the direct-delete path.)

it('Site::delete dispatches CloudflareCachePurgeJob for the deleted handle (EDGE-4)', function () {
    $fixture = seedTouchFixture();
    Queue::fake();

    Site::find($fixture['site_id'])->delete();

    Queue::assertPushed(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) {
        return $job->handle === 'touchtest';
    });
});

it('explicit $site->touch() after a mass update dispatches CloudflareCachePurgeJob', function () {
    $fixture = seedTouchFixture();
    Queue::fake();

    Site::find($fixture['site_id'])->touch();

    Queue::assertPushed(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) {
        return $job->handle === 'touchtest';
    });
});

// CACHE-2: SiteMediaObserver dirty-flag guard — saved() should only trigger the
// CF purge chain when a cache-affecting column actually changed. No-op saves
// (alt_text, caption, processing_error edits) must NOT fire CloudflareCachePurgeJob.

it('SiteMedia save changing only alt_text does NOT dispatch CloudflareCachePurgeJob', function () {
    $fixture = seedTouchFixture();
    $media = new SiteMedia([
        'pool' => 'gallery',
        'path' => 'images/test.webp',
        'media_type' => 'image',
        'processing_state' => 'ready',
        'sort_order' => 0,
        'is_active' => true,
    ]);
    $media->site()->associate($fixture['site_id']);
    $media->save();

    // Fake AFTER create so we only count purges from the update.
    Queue::fake();

    $media->alt_text = 'Updated alt text';
    $media->save();

    Queue::assertNotPushed(CloudflareCachePurgeJob::class);
});

it('SiteMedia save changing is_active dispatches CloudflareCachePurgeJob', function () {
    $fixture = seedTouchFixture();
    $media = new SiteMedia([
        'pool' => 'gallery',
        'path' => 'images/test2.webp',
        'media_type' => 'image',
        'processing_state' => 'ready',
        'sort_order' => 1,
        'is_active' => true,
    ]);
    $media->site()->associate($fixture['site_id']);
    $media->save();

    Queue::fake();

    $media->is_active = false;
    $media->save();

    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('SiteMedia save changing processing_state to ready dispatches CloudflareCachePurgeJob', function () {
    $fixture = seedTouchFixture();
    $media = new SiteMedia([
        'pool' => 'gallery',
        'path' => 'images/pending.webp',
        'media_type' => 'image',
        'processing_state' => 'pending',
        'sort_order' => 2,
        'is_active' => true,
    ]);
    $media->site()->associate($fixture['site_id']);
    $media->save();

    Queue::fake();

    $media->processing_state = 'ready';
    $media->save();

    Queue::assertPushed(CloudflareCachePurgeJob::class);
});
