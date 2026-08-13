<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // BaseModel forces every Eloquent model onto the 'pgsql' connection.
        // Without this redirect, every model query in tests tries to reach the
        // real Supabase host via DB_HOST and fails with DNS errors. Point the
        // 'pgsql' connection at the same in-memory SQLite the test suite uses,
        // so models still resolve their forced connection but it goes nowhere
        // dangerous.
        config(['database.connections.pgsql' => array_merge(
            config('database.connections.sqlite'),
            ['name' => 'pgsql']
        )]);

        // Also make pgsql the DEFAULT connection in tests, so unqualified calls
        // like DB::table('foo') and DB::statement(...) hit the same PDO handle
        // that BaseModel-forced models use. Without this, ATTACH DATABASE and
        // CREATE TABLE statements run on a different SQLite handle than the
        // models, so models can't see the tables.
        config(['database.default' => 'pgsql']);

        DB::purge('pgsql');

        // Parallel-test Redis isolation (#50): ~17 suites call Redis::flushdb
        // for per-file isolation, which on the SHARED default databases nukes
        // sibling workers' cache/queue state mid-test — the moderation/
        // streaming/session flake family (each member passes solo). Under
        // `--parallel`, point EVERY logical Redis connection at one
        // worker-private database (token 1..N → DB 1..N; local Redis ships 16
        // DBs, so ≤15 workers). flushdb then only ever clears the calling
        // worker's own DB. Solo runs keep the 0-4 defaults.
        // `app` is in this list because TokenRevocationService, VerifyBotToken,
        // PerTargetReportThrottle, LiveStatusInjector, CircuitBreaker,
        // EnquirySpamBlocklist, CacheLockService and RecordCacheMetrics all read
        // and write through Redis::connection('app'). Leave it out and the
        // service half of those suites sits on DB 0 while the assertions read
        // the worker-private DB.
        //
        // The two loops MUST stay separate. RedisManager is handed its config
        // BY VALUE in RedisServiceProvider::register(), so it snapshots
        // database.redis at the moment it is first resolved — and Redis::purge()
        // is what resolves it. Rewriting config and purging in one loop
        // therefore freezes the snapshot on the first iteration, and every
        // connection after it keeps its original database. That bug is why this
        // block silently remapped only `default` for its whole life.
        $token = env('TEST_TOKEN');
        if ($token !== false && $token !== null && $token !== '' && (int) $token > 0) {
            $db = (int) $token;
            $connections = ['default', 'app', 'cache', 'session', 'queue', 'cache_locks'];

            foreach ($connections as $name) {
                config(["database.redis.{$name}.database" => $db]);
            }

            foreach ($connections as $name) {
                Redis::purge($name);
            }
        }

        // MediaDiskResolver trusts $_ENV/$_SERVER over config (Laravel Cloud
        // injects runtime env vars that deploy-cached config can't see). Under
        // phpunit, dotenv loads .env into those superglobals, so a developer's
        // (or CI's) SIDEST_MEDIA_DISK=media would silently override the suites'
        // config('partna.media_disk') overrides and point media-test writes at
        // the real S3 disk. Clear the probe keys per-test; the tests that
        // exercise the probe itself (MediaDiskResolverTest) set them explicitly
        // mid-test.
        unset(
            $_ENV['PARTNA_MEDIA_DISK'], $_SERVER['PARTNA_MEDIA_DISK'],
            $_ENV['SIDEST_MEDIA_DISK'], $_SERVER['SIDEST_MEDIA_DISK'],
        );
    }
}
