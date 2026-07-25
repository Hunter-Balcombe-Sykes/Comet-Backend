<?php

namespace Tests\Support\SchemaDrift;

use Illuminate\Database\Connection;

/**
 * Reads the ATTACH-ed SQLite test schema built by tests/Pest.php helpers.
 * Schemas map to attached database names (core, site, ...), so PRAGMA and
 * sqlite_master are addressed per-schema.
 */
class SqliteIntrospector
{
    public function __construct(private Connection $conn) {}

    public function tableExists(string $schema, string $table): bool
    {
        return $this->tableDdl($schema, $table) !== null;
    }

    /** null = column absent from the sqlite table. */
    public function columnNotNull(string $schema, string $table, string $column): ?bool
    {
        foreach ($this->conn->select("PRAGMA {$schema}.table_info({$table})") as $col) {
            if ($col->name === $column) {
                return (bool) $col->notnull;
            }
        }

        return null;
    }

    /** @return string[] column names actually declared on the table; [] if the table doesn't exist. */
    public function columns(string $schema, string $table): array
    {
        return array_map(
            fn ($col) => $col->name,
            $this->conn->select("PRAGMA {$schema}.table_info({$table})")
        );
    }

    public function tableDdl(string $schema, string $table): ?string
    {
        $row = $this->conn->selectOne(
            "SELECT sql FROM {$schema}.sqlite_master WHERE type = 'table' AND name = ?",
            [$table]
        );

        return $row->sql ?? null;
    }
}
