#!/usr/bin/env php
<?php

/**
 * Dumps NOT NULL + CHECK constraints from the LIVE dev Supabase Postgres
 * into schema-snapshot.json. The SchemaDriftGuardTest Pest gate compares the
 * SQLite test schema against this snapshot — so the snapshot, not the
 * migration SQL, is the source of truth (it also catches migrations applied
 * directly to Supabase that never landed in the repo).
 *
 * Usage: php scripts/launch-check/refresh-schema-snapshot.php
 * Requires SUPABASE_ACCESS_TOKEN in scripts/launch-check/.env
 */
const PROJECT_REF = 'glncumufgaqcmqhzwrxm'; // dev ONLY — never the prod ref
const SCHEMAS = "'core','site','notifications','analytics','audit','moderation','content','ingest','routing','catalog'";

$dir = __DIR__;

// Minimal .env parse (no framework boot — this is a standalone CLI tool).
$token = getenv('SUPABASE_ACCESS_TOKEN') ?: '';
if ($token === '' && is_file("$dir/.env")) {
    foreach (file("$dir/.env", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), 'SUPABASE_ACCESS_TOKEN=')) {
            $token = trim(explode('=', $line, 2)[1]);
        }
    }
}
if ($token === '') {
    fwrite(STDERR, "SUPABASE_ACCESS_TOKEN missing — copy .env.example to .env and fill it in.\n");
    exit(1);
}

/** POST a SQL query to the Supabase Management API, return decoded rows. */
function pgQuery(string $token, string $sql): array
{
    $ch = curl_init('https://api.supabase.com/v1/projects/'.PROJECT_REF.'/database/query');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}", 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['query' => $sql]),
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status !== 200 && $status !== 201) {
        fwrite(STDERR, "Management API query failed (HTTP {$status}): {$body}\n");
        exit(1);
    }

    return json_decode($body, true) ?? [];
}

$columns = pgQuery($token, '
    SELECT c.table_schema AS schema, c.table_name AS "table",
           c.column_name AS "column", (c.is_nullable = \'NO\') AS not_null
    FROM information_schema.columns c
    WHERE c.table_schema IN ('.SCHEMAS.')
    ORDER BY 1, 2, c.ordinal_position');

$checks = pgQuery($token, '
    SELECT n.nspname AS schema, rel.relname AS "table",
           con.conname AS name, pg_get_constraintdef(con.oid) AS definition
    FROM pg_constraint con
    JOIN pg_class rel ON rel.oid = con.conrelid
    JOIN pg_namespace n ON n.oid = rel.relnamespace
    WHERE con.contype = \'c\' AND n.nspname IN ('.SCHEMAS.')
    ORDER BY 1, 2, 3');

$migration = pgQuery($token,
    'SELECT version FROM supabase_migrations.schema_migrations ORDER BY version DESC LIMIT 1');

$snapshot = [
    'generated_at' => gmdate('c'),
    'project_ref' => PROJECT_REF,
    'latest_migration' => $migration[0]['version'] ?? 'unknown',
    'columns' => $columns,
    'checks' => $checks,
];

file_put_contents("$dir/schema-snapshot.json",
    json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

echo 'Snapshot written: '.count($columns).' columns, '.count($checks)
    ." CHECK constraints, latest migration {$snapshot['latest_migration']}\n";
