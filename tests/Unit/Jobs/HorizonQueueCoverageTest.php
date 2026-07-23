<?php

use App\Jobs\Cache\WarmPublicSiteCacheJob;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Jobs\Platforms\ConnectFetchJob;
use App\Jobs\Platforms\DeleteMirroredMediaJob;
use App\Jobs\Platforms\InstagramConnectJob;
use App\Jobs\Platforms\MenuFetchJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
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

// R3-CACHE-1: priority order IS the fix — a takedown's bulk fan-out only
// competes with real-time purges if this ever regresses. Test the invariant
// explicitly so a future "tidy up the queue list" can't silently undo it.
it('cloudflare_bulk is listed AFTER cloudflare on supervisor-1, so bulk purges never compete with real-time ones', function () {
    $horizon = require base_path('config/horizon.php');
    $queue = $horizon['defaults']['supervisor-1']['queue'];

    $cloudflareIndex = array_search('cloudflare', $queue, true);
    $bulkIndex = array_search('cloudflare_bulk', $queue, true);

    expect($cloudflareIndex)->not->toBeFalse()
        ->and($bulkIndex)->not->toBeFalse()
        ->and($bulkIndex)->toBeGreaterThan($cloudflareIndex);
});

it('cloudflare_bulk queue is covered in every Horizon environment', function () {
    foreach (['production', 'development', 'local'] as $env) {
        expect(envCoversQueue($env, 'cloudflare_bulk'))->toBeTrue(
            "cloudflare_bulk queue must appear in at least one {$env} supervisor queue list"
        );
    }
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

it('platform_connect queue is covered in the production environment', function () {
    expect(envCoversQueue('production', 'platform_connect'))->toBeTrue(
        'supervisor-platform-connect must be registered in production — jobs will strand otherwise'
    );
});

it('platform_connect queue is covered in the development environment', function () {
    expect(envCoversQueue('development', 'platform_connect'))->toBeTrue(
        'platform_connect queue must appear in at least one development supervisor queue list'
    );
});

it('platform_connect queue is covered in the local environment', function () {
    expect(envCoversQueue('local', 'platform_connect'))->toBeTrue(
        'platform_connect queue must appear in at least one local supervisor queue list'
    );
});

// Generic sweep: the per-queue tests above pin four historically-bitten lanes,
// but a NEW queue name added without a supervisor strands its jobs silently
// (JOB-2: InstagramConnectJob never ran in any env). This discovers every queue
// name app code can dispatch to — onQueue()/viaQueue() literals, config-driven
// names, $queue property defaults, per-connection defaults — and asserts each
// has a consuming supervisor in every Horizon environment block.
it('every queue any app code can dispatch to is consumed by a supervisor in every environment', function () {
    $queues = discoveredQueueNames();

    // Sanity floor: the historically known set is 14 queues. A smaller result
    // means the discovery regexes rotted, not that the platform shrank.
    expect(count($queues))->toBeGreaterThanOrEqual(14);

    $missing = [];
    foreach (['production', 'development', 'local'] as $env) {
        foreach ($queues as $queue) {
            if (! envCoversQueue($env, $queue)) {
                $missing[] = "{$env}: {$queue}";
            }
        }
    }

    expect($missing)->toBeEmpty(
        "Queue with no consuming supervisor — jobs dispatched there strand as a silent backlog:\n - "
        .implode("\n - ", $missing)
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

/**
 * Every queue name app code can dispatch to: onQueue()/viaQueue() calls with a
 * literal or config-driven name (tolerating a (string) cast), $queue property
 * defaults, and the per-connection default queues from config/queue.php.
 * Dynamic names (onQueue($var)) are invisible to this scan — the count floor in
 * the calling test guards against the regexes rotting outright.
 */
function discoveredQueueNames(): array
{
    $queues = ['default'];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $src = (string) file_get_contents($file->getPathname());

        preg_match_all(
            "/(?:onQueue|viaQueue)\\(\\s*(?:\\(string\\)\\s*)?(?:config\\(\\s*'([^']+)'|'([^']+)')/",
            $src,
            $matches
        );
        foreach ($matches[1] as $i => $configKey) {
            $queues[] = $configKey !== '' ? config($configKey) : $matches[2][$i];
        }

        preg_match_all("/public\\s+(?:string\\s+)?\\\$queue\\s*=\\s*'([^']+)'/", $src, $propMatches);
        foreach ($propMatches[1] as $literal) {
            $queues[] = $literal;
        }

        // Constructor-style assignment ($this->queue = ...), used by the
        // moderation jobs to dodge a PHP 8.4 typed-trait-property conflict.
        preg_match_all(
            "/\\\$this->queue\\s*=\\s*(?:\\(string\\)\\s*)?(?:config\\(\\s*'([^']+)'|'([^']+)')/",
            $src,
            $assignMatches
        );
        foreach ($assignMatches[1] as $i => $configKey) {
            $queues[] = $configKey !== '' ? config($configKey) : $assignMatches[2][$i];
        }
    }

    // Jobs routed by onConnection() alone land on the connection's default queue.
    foreach (config('queue.connections') as $connection) {
        $queues[] = $connection['queue'] ?? null;
    }

    return array_values(array_unique(array_filter(
        $queues,
        fn ($queue) => is_string($queue) && $queue !== ''
    )));
}

// Sweep: every ShouldBeUnique job whose run can outlast its own dedupe lock is a
// duplicate waiting to happen. ShouldBeUnique's lock is a cache lock with
// TTL = $uniqueFor, force-released only on CLEAN completion — a timeout-kill or
// uncaught throw leaves it to expire on its own TTL. So uniqueFor <= timeout
// means a slow job loses dedupe protection while still running.
//
// This invariant was previously asserted for exactly ONE job (InstagramConnectJob)
// while the same rule silently broke on CloudflareCachePurgeJob when its $timeout
// was raised 15 -> 180 against an unchanged uniqueFor of 120. A codebase-wide rule
// pinned in one place is not pinned.
//
// Exemptions carry a justification, mirroring PolicyCoverageTest's allowlist shape.
it('every ShouldBeUnique job holds its lock at least as long as it can run', function () {
    // job class => [constructor args, why it is exempt (or null if it must comply)]
    $jobs = [
        CloudflareCachePurgeJob::class => [['some-handle'], null],
        SyncSubdomainToKvJob::class => [['00000000-0000-0000-0000-000000000001'], null],
        InstagramConnectJob::class => [['u', 'someuser', 'c'], null],
        ConnectFetchJob::class => [['00000000-0000-0000-0000-000000000001', 'bandcamp'], null],
    ];

    $violations = [];

    foreach ($jobs as $class => [$args, $exemptReason]) {
        if ($exemptReason !== null) {
            continue;
        }

        $job = new $class(...$args);

        // Only meaningful when the job declares both knobs.
        if (! property_exists($job, 'uniqueFor') || ! property_exists($job, 'timeout')) {
            continue;
        }

        if ($job->uniqueFor <= $job->timeout) {
            $violations[] = sprintf(
                '%s: uniqueFor=%d must exceed timeout=%d',
                class_basename($class),
                $job->uniqueFor,
                $job->timeout
            );
        }
    }

    expect($violations)->toBeEmpty(
        "A ShouldBeUnique job can outrun its own dedupe lock, so a duplicate can slip in mid-run:\n - "
        .implode("\n - ", $violations)
    );
});

// Generic companion to the explicit list above: reflection over DEFAULT property
// values needs no constructor args, so it auto-covers every ShouldBeUnique job in
// app/Jobs with zero maintenance — the hardcoded list stays for jobs whose knobs
// are assigned in the constructor (invisible to getDefaultProperties()).
// ShouldBeUniqueUntilProcessing is excluded on purpose: its lock releases when
// processing STARTS, so uniqueFor only guards queue-wait and may legitimately be
// shorter than timeout (SyncSubdomainToKvJob documents exactly this trade).
it('every ShouldBeUnique job with property-default knobs holds its lock longer than it can run', function () {
    $violations = [];
    $checked = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Jobs'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $class = 'App\\'.str_replace('/', '\\', substr($file->getPathname(), strlen(app_path()) + 1, -4));
        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract()
            || ! $reflection->implementsInterface(ShouldBeUnique::class)
            || $reflection->implementsInterface(ShouldBeUniqueUntilProcessing::class)) {
            continue;
        }

        $defaults = $reflection->getDefaultProperties();
        $uniqueFor = $defaults['uniqueFor'] ?? null;
        $timeout = $defaults['timeout'] ?? null;

        // A missing uniqueFor is the dangerous case, not a tolerated one: with no
        // uniqueFor, UniqueLock::acquire() falls back to `?? 0`, and RedisLock::acquire()
        // treats 0 as "no expiry" — a plain SETNX. A worker killed mid-job (OOM, deploy,
        // timeout) strands that lock in Redis forever; every later dispatch for the same
        // uniqueId is silently discarded (no exception, no failed_jobs row). This must
        // fail the test, not skip it.
        if (! is_int($uniqueFor)) {
            $violations[] = sprintf(
                '%s: ShouldBeUnique with no $uniqueFor default takes a PERMANENT lock (SETNX, no expiry) — a killed worker strands it forever and every later dispatch is silently discarded',
                class_basename($class)
            );

            continue;
        }

        if (! is_int($timeout)) {
            continue; // timeout constructor-driven — nothing to compare uniqueFor against yet
        }

        $checked++;

        if ($uniqueFor <= $timeout) {
            $violations[] = sprintf(
                '%s: uniqueFor=%d must exceed timeout=%d',
                class_basename($class),
                $uniqueFor,
                $timeout
            );
        }
    }

    // Floor guards the reflection plumbing itself — ~20 plain-unique jobs exist
    // today, so a near-zero count means the scan broke, not that jobs vanished.
    expect($checked)->toBeGreaterThanOrEqual(15)
        ->and($violations)->toBeEmpty(
            "A ShouldBeUnique job can outrun its own dedupe lock, so a duplicate can slip in mid-run:\n - "
            .implode("\n - ", $violations)
        );
});

// The deliberate exception, pinned so it reads as intentional rather than as a
// miss: a FOLLOW-UP purge keeps a SHORT lock (30s) on purpose, well under its own
// timeout. Its dispatch delay is 120s, and a lock outliving that delay would
// coalesce away the follow-up owed to a LATER edit — leaving that edit's stale
// re-pin uncovered for the router's 24h HTML TTL. Dropping a redundant follow-up
// is cheap; dropping a later edit's follow-up is not.
it('exempts the follow-up purge from that rule on purpose, because a stale lock would swallow a later edit', function () {
    $followUp = new CloudflareCachePurgeJob('some-handle', null, followUp: true);

    expect($followUp->uniqueFor)->toBeLessThan($followUp->timeout)
        ->and($followUp->uniqueFor)->toBeLessThan((int) config('partna.cache.purge_followup_seconds', 120));
});
