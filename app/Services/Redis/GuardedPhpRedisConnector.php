<?php

namespace App\Services\Redis;

use App\Services\Redis\Exceptions\RedisUnavailableException;
use Closure;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Support\Arr;
use Throwable;

/**
 * PhpRedisConnector that returns GuardedPhpRedisConnection instances and also
 * guards the CONNECT itself.
 *
 * Why connect needs its own guard, not just command(): under a hung server
 * `PhpRedisConnector::createClient()`'s `establishConnection()` call blocks,
 * and so does the `select($db)` that follows it — each a fresh `timeout`-
 * bound wait, paid again for every NEW connection. A single public-profile
 * request opens up to five distinct Redis connections (cache, cache_locks,
 * app, plus reconnects), so an unguarded connect() alone could cost up to
 * 5 × timeout before a single command guard ever gets a chance to run.
 * connect() therefore short-circuits when the breaker is already open, and
 * trips it on a connect-time transport failure the same way command() does.
 *
 * CLUSTER IS OUT OF SCOPE. connectToCluster() is intentionally NOT
 * overridden — Redis Cluster is rejected at this repo's scale (CLAUDE.md),
 * so cluster connections fall through to the parent's unguarded behaviour
 * unchanged.
 */
class GuardedPhpRedisConnector extends PhpRedisConnector
{
    /** @param  Closure(): RedisRequestBreaker  $breaker  Resolver, not an instance — see GuardedPhpRedisConnection. */
    public function __construct(private readonly Closure $breaker) {}

    public function connect(array $config, array $options)
    {
        $breaker = ($this->breaker)();

        if ($breaker->isOpen()) {
            // The connection NAME isn't known yet — RedisManager::configure()
            // sets it via setName() only after connect() returns — so this
            // names the connecting attempt honestly rather than inventing a
            // connection name that doesn't exist yet.
            throw RedisUnavailableException::forSkippedCommand('(connecting)', 'connect', $breaker->reason());
        }

        // Copied from PhpRedisConnector::connect() (vendor/laravel/framework/
        // src/Illuminate/Redis/Connectors/PhpRedisConnector.php) — the parent
        // builds the PhpRedisConnection object inline with `new`, so there is
        // no seam to override just the returned class. Keep this block
        // byte-for-byte with the vendor method aside from the return type;
        // re-check it on every Laravel upgrade. RedisBreakerWiringTest is
        // what catches a silent drift between the two.
        $formattedOptions = Arr::pull($config, 'options', []);

        if (isset($config['prefix'])) {
            $formattedOptions['prefix'] = $config['prefix'];
        }

        $connector = function () use ($config, $options, $formattedOptions) {
            return $this->createClient(array_merge(
                $config, $options, $formattedOptions
            ));
        };

        try {
            $client = $connector();
        } catch (Throwable $e) {
            if (RedisRequestBreaker::isTransportFailure($e)) {
                $breaker->trip($e, '(connecting)', 'connect');
            }

            throw $e;
        }

        return new GuardedPhpRedisConnection($client, $connector, $config, $this->breaker);
    }
}
