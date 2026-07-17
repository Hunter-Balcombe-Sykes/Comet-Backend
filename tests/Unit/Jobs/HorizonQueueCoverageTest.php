<?php

use App\Jobs\Cache\WarmPublicSiteCacheJob;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Jobs\Platforms\DeleteMirroredMediaJob;
use App\Jobs\Platforms\InstagramConnectJob;
use App\Jobs\Platforms\MenuFetchJob;
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

it('MenuFetchJob timeout stays under the scraping connection retry_after and its supervisor timeout (JOB-103)', function () {
    // Golden rule: a queue connection's retry_after must EXCEED the longest job
    // $timeout consumed on it, else Redis re-hands a still-executing job to a second
    // worker (for MenuFetchJob: a concurrent double-scrape + duplicate menu rows).
    // Resolve the 'scraping' queue → its Horizon default supervisor → that
    // supervisor's connection → that connection's retry_after (config/queue.php),
    // rather than hardcoding 'redis_scraping' — so this test breaks loudly if the
    // queue is ever moved back onto a connection with a shorter retry_after.
    $horizon = require base_path('config/horizon.php');
    $defaults = $horizon['defaults'];

    $supervisorName = null;
    foreach ($defaults as $name => $supervisorConfig) {
        if (in_array('scraping', (array) ($supervisorConfig['queue'] ?? []), true)) {
            $supervisorName = $name;
            break;
        }
    }

    expect($supervisorName)->not->toBeNull('no Horizon default supervisor consumes the scraping queue');

    $connection = $defaults[$supervisorName]['connection'];
    $supervisorTimeout = $defaults[$supervisorName]['timeout'];
    $retryAfter = config("queue.connections.{$connection}.retry_after");

    $job = new MenuFetchJob('00000000-0000-0000-0000-000000000001');

    // Strictly LESS THAN, not <=: an equal value still races Horizon's own
    // SIGKILL against Redis's re-queue at the exact same instant.
    expect($job->timeout)->toBeLessThan($retryAfter)
        ->and($job->timeout)->toBeLessThanOrEqual($supervisorTimeout);
});

it('every Horizon default supervisor connection retry_after covers its own worker timeout (JOB-103)', function () {
    // Generic guard for the redis_gdpr / redis_video / redis_scraping pattern: a
    // supervisor's worker-level timeout must never exceed its connection's
    // retry_after. This is how JOB-103 (MenuFetchJob $timeout=600 on the shared
    // redis connection's retry_after=360) would have been caught automatically.
    //
    // Honest limitation: this only checks each SUPERVISOR's configured timeout
    // floor, not every individual job's $timeout — there's no generic way to
    // instantiate every job class in this loop to read that property, so a job
    // whose own $timeout exceeds ITS supervisor's timeout would slip through this
    // check alone. The MenuFetchJob-specific test above covers that gap for the
    // scraping lane; new long-running jobs on other lanes need the same explicit
    // per-job assertion.
    $horizon = require base_path('config/horizon.php');

    $violations = [];
    foreach ($horizon['defaults'] as $name => $supervisorConfig) {
        $connection = $supervisorConfig['connection'];
        $retryAfter = config("queue.connections.{$connection}.retry_after");

        if ($retryAfter === null || $retryAfter < $supervisorConfig['timeout']) {
            $violations[] = sprintf(
                '%s: connection "%s" retry_after=%s < supervisor timeout=%d',
                $name,
                $connection,
                var_export($retryAfter, true),
                $supervisorConfig['timeout']
            );
        }
    }

    expect($violations)->toBeEmpty(implode("\n", $violations));
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
