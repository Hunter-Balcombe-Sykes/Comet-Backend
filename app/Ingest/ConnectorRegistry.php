<?php

namespace App\Ingest;

use App\Ingest\Connectors\BandcampConnector;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Runtime\Connector;

/**
 * source_key => connector class. The only place that mapping is allowed to
 * live: RunSourceJob and the dispatch command both resolve through here
 * rather than constructing a connector directly, so adding a connector is a
 * one-line registration and nothing else has to learn its class name.
 *
 * An unknown source_key throws rather than returning null. A row in
 * ingest.sources naming a connector that doesn't (or no longer) exist is a
 * real bug — a bad migration, a removed connector, a typo — and must surface
 * loudly rather than have the dispatcher quietly skip it forever.
 */
final class ConnectorRegistry
{
    /** @var array<string, class-string<Connector>> */
    private const MAP = [
        'bandcamp' => BandcampConnector::class,
    ];

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::MAP);
    }

    public static function for(string $key): Connector
    {
        return app(self::classFor($key));
    }

    public static function manifestFor(string $key): Manifest
    {
        return self::classFor($key)::manifest();
    }

    /** @return array<string, class-string<Connector>> */
    public static function all(): array
    {
        return self::MAP;
    }

    /** @return class-string<Connector> */
    private static function classFor(string $key): string
    {
        if (! self::has($key)) {
            throw new \InvalidArgumentException("Unknown ingest source_key: {$key}");
        }

        return self::MAP[$key];
    }
}
