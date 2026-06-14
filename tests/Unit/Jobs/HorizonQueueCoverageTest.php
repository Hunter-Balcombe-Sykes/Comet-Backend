<?php

use App\Jobs\Cache\WarmPublicSiteCacheJob;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Jobs\Platforms\DeleteMirroredMediaJob;
use App\Jobs\Platforms\InstagramConnectJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Guard against the "queue with no worker" regression that previously stranded
// WarmPublicSiteCacheJob when it was dispatched to a 'cache' queue that had no
// supervisor configured. These tests assert that every queue used by the isolated
// jobs appears in at least one supervisor's queue list for each relevant
// Horizon environment block.

it('cloudflare queue is covered in the production environment', function () {
    expect(envCoversQueue('production', 'cloudflare'))->toBeTrue(
        'supervisor-cloudflare must be registered in production — jobs will strand otherwise'
    );
});

it('cache-warm queue is covered in the production environment', function () {
    expect(envCoversQueue('production', 'cache-warm'))->toBeTrue(
        'supervisor-cache-warm must be registered in production — jobs will strand otherwise'
    );
});

it('cloudflare queue is covered in the development environment', function () {
    expect(envCoversQueue('development', 'cloudflare'))->toBeTrue(
        'cloudflare queue must appear in at least one development supervisor queue list'
    );
});

it('cache-warm queue is covered in the development environment', function () {
    expect(envCoversQueue('development', 'cache-warm'))->toBeTrue(
        'cache-warm queue must appear in at least one development supervisor queue list'
    );
});

it('cloudflare queue is covered in the local environment', function () {
    expect(envCoversQueue('local', 'cloudflare'))->toBeTrue(
        'cloudflare queue must appear in at least one local supervisor queue list'
    );
});

it('cache-warm queue is covered in the local environment', function () {
    expect(envCoversQueue('local', 'cache-warm'))->toBeTrue(
        'cache-warm queue must appear in at least one local supervisor queue list'
    );
});

it('scraping queue is covered in the production environment (JOB-2)', function () {
    expect(envCoversQueue('production', 'scraping'))->toBeTrue(
        'supervisor-scraping must be registered in production — InstagramConnectJob never ran without it'
    );
});

it('scraping queue is covered in the development environment (JOB-2)', function () {
    expect(envCoversQueue('development', 'scraping'))->toBeTrue(
        'scraping queue must appear in at least one development supervisor queue list'
    );
});

it('scraping queue is covered in the local environment (JOB-2)', function () {
    expect(envCoversQueue('local', 'scraping'))->toBeTrue(
        'scraping queue must appear in at least one local supervisor queue list'
    );
});

it('InstagramConnectJob is dispatched to the scraping queue', function () {
    expect((new InstagramConnectJob('u', 'someuser', 'c'))->queue)->toBe('scraping');
});

it('InstagramConnectJob is unique per connection to prevent double-billing Apify (LIFE-1)', function () {
    $job = new InstagramConnectJob('u', 'someuser', 'conn-123');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        // Keyed on connection + username so a true duplicate dedups but an account
        // switch (same row, different username) still runs.
        ->and($job->uniqueId())->toBe('conn-123:someuser')
        // Window must outlast the run so a duplicate can't slip in mid-scrape.
        ->and($job->uniqueFor)->toBeGreaterThanOrEqual($job->timeout);
});

it('CloudflareCachePurgeJob is dispatched to the cloudflare queue', function () {
    expect((new CloudflareCachePurgeJob('test-handle'))->queue)->toBe('cloudflare');
});

it('SyncSubdomainToKvJob is dispatched to the cloudflare queue', function () {
    expect((new SyncSubdomainToKvJob('00000000-0000-0000-0000-000000000001'))->queue)->toBe('cloudflare');
});

it('WarmPublicSiteCacheJob is dispatched to the cache-warm queue', function () {
    expect((new WarmPublicSiteCacheJob('test-subdomain'))->queue)->toBe('cache-warm');
});

it('DeleteMirroredMediaJob is dispatched to the scraping queue (SCALE-7)', function () {
    expect((new DeleteMirroredMediaJob('platforms/instagram/1'))->queue)->toBe('scraping');
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Returns true if any supervisor in the named environment covers the given queue.
 *
 * For environments that only list process-count overrides (like production),
 * the queue list is resolved from the defaults block, which is how Horizon
 * itself merges the two arrays at runtime.
 */
function envCoversQueue(string $env, string $queue): bool
{
    $horizon = require base_path('config/horizon.php');
    $envBlock = $horizon['environments'][$env] ?? [];
    $defaults = $horizon['defaults'] ?? [];

    foreach ($envBlock as $supervisorName => $supervisorConfig) {
        // Env block may be a partial override (production: only minProcesses/maxProcesses)
        // or a full definition (development/local: includes 'queue').
        $queueList = $supervisorConfig['queue']
            ?? $defaults[$supervisorName]['queue']
            ?? [];

        if (in_array($queue, (array) $queueList, strict: true)) {
            return true;
        }
    }

    return false;
}
