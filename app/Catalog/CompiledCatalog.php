<?php

namespace App\Catalog;

/**
 * Reader for bootstrap/catalog/compiled.php — the opcache-resident artefact
 * `catalog:compile` emits. This is the ONLY runtime source of platform
 * identity; the catalog schema in Postgres is a projection for FKs/analytics
 * and is never read on request paths.
 */
class CompiledCatalog
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    public static function path(): string
    {
        return base_path('bootstrap/catalog/compiled.php');
    }

    public static function isCompiled(): bool
    {
        return is_file(self::path());
    }

    /** @return array<string, mixed> */
    public static function load(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }
        if (! self::isCompiled()) {
            throw new CatalogNotCompiled('bootstrap/catalog/compiled.php is missing — run `php artisan catalog:compile`.');
        }

        return self::$data = require self::path();
    }

    public static function digest(): string
    {
        return (string) self::load()['digest'];
    }

    /** @return array<string, array<string, mixed>> surface key => surface array */
    public static function surfaces(): array
    {
        return self::load()['surfaces'];
    }

    /** @return array<string, mixed>|null */
    public static function surface(string $key): ?array
    {
        return self::surfaces()[$key] ?? null;
    }

    /** @return array<string, array<string, mixed>> brand key => brand array */
    public static function brands(): array
    {
        return self::load()['brands'];
    }

    /** @return array<string, array<string, mixed>> detector id => detector array */
    public static function detectors(): array
    {
        return self::load()['detectors'];
    }

    /** @return array<string, string> alias host => registrable key */
    public static function hostAliases(): array
    {
        return self::load()['host_aliases'];
    }

    /** @return list<string> */
    public static function suffixOverrides(): array
    {
        return self::load()['suffix_overrides'];
    }

    /** Test seam — drops the memoised artefact so a test can swap it. */
    public static function flush(): void
    {
        self::$data = null;
    }
}
