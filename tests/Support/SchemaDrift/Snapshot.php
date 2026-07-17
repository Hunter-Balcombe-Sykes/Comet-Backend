<?php

namespace Tests\Support\SchemaDrift;

/**
 * Immutable view over schema-snapshot.json (produced by
 * scripts/launch-check/refresh-schema-snapshot.php).
 */
class Snapshot
{
    /** @param array<int, array{schema:string,table:string,column:string,not_null:bool}> $columns
     *  @param array<int, array{schema:string,table:string,name:string,definition:string}> $checks */
    private function __construct(
        public readonly array $columns,
        public readonly array $checks,
        public readonly string $latestMigration,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            columns: $data['columns'] ?? [],
            checks: $data['checks'] ?? [],
            latestMigration: $data['latest_migration'] ?? 'unknown',
        );
    }

    public static function fromFile(string $path): self
    {
        return self::fromArray(json_decode(file_get_contents($path), true) ?? []);
    }
}
