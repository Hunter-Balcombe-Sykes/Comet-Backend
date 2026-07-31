<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be used unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('DB_SEARCH_PATH', 'public,core,site,notifications,analytics'),
            'sslmode' => env('DB_SSLMODE', 'require'),
            // Set statement timeout (30 seconds)
            'statement_timeout' => env('DB_STATEMENT_TIMEOUT', 30000),
            // Set lock timeout (10 seconds)
            'lock_timeout' => env('DB_LOCK_TIMEOUT', 10000),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */
    /*
    | Timeouts — why there are two bands, not one.
    |
    | phpredis defaults BOTH `timeout` (connect) and `read_timeout` to 0.0 = wait
    | forever. Unset, a hung or packet-dropping Redis hangs the PHP process with no
    | bound at all. (A *refused* socket is not the dangerous case — the OS refuses
    | immediately, measured 34-52 ms during the 2026-07-31 Redis-down drill.)
    |
    | Values decided 2026-07-31 off measured numbers: whole-request p99 is 376 ms and
    | the worst legitimate single Redis op observed was a 314 ms `supabase:jwks` lock,
    | so 3.0 s on the request path is ~10x the real ceiling — it will not trip in
    | normal operation but caps a user-facing hang at 3 s instead of forever.
    | 2.0 s connect is ~400x a same-region Valkey connect (~1-5 ms) and only ever
    | makes a failover fail *sooner* than the ~75 s kernel SYN timeout it replaces.
    |
    | The request path can take the tight bound because nothing on it issues a
    | server-side blocking command: `Cache::lock()->block()` is a PHP-side usleep
    | poll (Illuminate\Cache\Lock::block), so each op is a single short SET NX.
    */
    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
        ],

        // WORKER PATH. Every redis queue connection in config/queue.php
        // (redis, redis_video, redis_gdpr, redis_scraping) resolves HERE via
        // REDIS_QUEUE_CONNECTION, and Horizon's `use` is 'default' too. Each of
        // those sets block_for, so Laravel issues `BLPOP <key> <block_for>` and the
        // server legitimately holds this socket for block_for seconds on EVERY idle
        // worker poll.
        //
        // >>> read_timeout MUST stay above the LARGEST block_for, or every worker
        // >>> throws `read error on connection` continuously — a live queue outage.
        // block_for is env-tunable (REDIS_QUEUE_BLOCK_FOR, PARTNA_VIDEO_QUEUE_BLOCK_FOR,
        // PARTNA_GDPR_QUEUE_BLOCK_FOR, PARTNA_SCRAPING_QUEUE_BLOCK_FOR — all default 5).
        // 15.0 is 3x that default. Raise block_for anywhere and raise this with it.
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'username' => env('REDIS_USERNAME', null),
            'port' => env('REDIS_PORT', 6379),
            'database' => env('REDIS_DB', 0),
            'timeout' => (float) env('REDIS_TIMEOUT', 2.0),
            'read_timeout' => (float) env('REDIS_QUEUE_READ_TIMEOUT', 15.0),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'username' => env('REDIS_USERNAME', null),
            'port' => env('REDIS_PORT', 6379),
            'database' => env('REDIS_CACHE_DB', 1),
            // Request path — no server-side blocking command runs on this connection.
            'timeout' => (float) env('REDIS_TIMEOUT', 2.0),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 3.0),
            // LZ4 value compression. Measured 2026-07-28 on dev (phpredis 6.3.0):
            // whole cache keyspace 157,968 B → 45,560 B (3.47×); pro:model:{id}
            // 8,256 B → 2,616 B (3.16×). Nothing grew, down to 1-byte values.
            //
            // Compression only, NO serializer — do not re-add one. RedisStore::serialize()
            // already returns serialize($value), so phpredis only ever sees a string:
            // SERIALIZER_IGBINARY measured 8,248 B against 8,256 B uncompressed, i.e. nothing,
            // and 8 B *worse* than LZ4 alone when paired with it.
            //
            // Turning this ON needs no flush: phpredis passes through payloads that don't
            // look compressed, so pre-existing entries stay readable and age out on their
            // TTLs. Turning it OFF is the unsafe direction — compressed bytes read without
            // the flag unserialize() to false, i.e. a cached falsy hit. Roll back by bumping
            // CACHE_PREFIX in the same deploy, not by flushing.
            // See docs/superpowers/specs/2026-07-28-redis-cache-compression-design.md §7.
            //
            // ZSTD is also compiled in and measured 4.42× on the same keyspace, at ~4× the
            // decompression cost. Left on the table: this sits on the authenticated hot path
            // and the extra headroom isn't needed yet.
            'options' => array_filter([
                'compression' => env('REDIS_CACHE_COMPRESSION', false) && defined('Redis::COMPRESSION_LZ4')
                    ? Redis::COMPRESSION_LZ4
                    : null,
            ]),
        ],

        'session' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'username' => env('REDIS_USERNAME', null),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_SESSION_DB', '2'),
            // Request path.
            'timeout' => (float) env('REDIS_TIMEOUT', 2.0),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 3.0),
        ],

        // Dormant queue-override slot: nothing points REDIS_QUEUE_CONNECTION here
        // today, but if anything ever does it inherits the BLPOP behaviour above —
        // so it carries the worker-path read_timeout, NOT the request-path one.
        'queue' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'username' => env('REDIS_USERNAME', null),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_QUEUE_DB', '3'),
            'timeout' => (float) env('REDIS_TIMEOUT', 2.0),
            'read_timeout' => (float) env('REDIS_QUEUE_READ_TIMEOUT', 15.0),
        ],

        // Dedicated DB for atomic lock keys so Cache::flush() on the data
        // connection never releases locks held by in-flight workers.
        'cache_locks' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'username' => env('REDIS_USERNAME', null),
            'port' => env('REDIS_PORT', 6379),
            'database' => env('REDIS_CACHE_LOCKS_DB', 4),
            // Request path. Carries the supabase:jwks lock, the slowest legitimate
            // single op measured anywhere (314 ms) — still ~10x under the 3.0 s bound.
            'timeout' => (float) env('REDIS_TIMEOUT', 2.0),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 3.0),
        ],

    ],
];
