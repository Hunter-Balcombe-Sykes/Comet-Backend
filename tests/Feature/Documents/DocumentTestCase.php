<?php

namespace Tests\Feature\Documents;

// Boots minimum SQLite schema for document feature tests.
// Delegates to the global helpers defined in tests/Pest.php to avoid
// duplicating CREATE TABLE statements — single source of truth.
class DocumentTestCase
{
    public static function boot(): void
    {
        setupUsersTable();
        setupSitesTable();
        setupMediaTables();
        setupBlocksTable();
    }
}
