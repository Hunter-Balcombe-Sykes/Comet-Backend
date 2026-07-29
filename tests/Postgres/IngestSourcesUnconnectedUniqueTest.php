<?php

// DINT-6 regression sentinel. sources_unique_per_connection UNIQUE
// (connection_id, source_key) (supabase/migrations/20260727130000_ingest_schema
// .sql:44) is silently inert for NULL-connection rows because Postgres
// treats every NULL as distinct — N connection-less rows could share a
// source_key. The partial unique index (20260729150010) makes the
// invariant total without a SET NOT NULL, which the plan rejected because
// four test files deliberately insert NULL connection_id (SourceSchedulerTest
// .php:31, RunSourceJobClaimReleaseTest.php:42, BandcampConnectorTest.php:180,
// AccountDeletionPurgeIngestEffectsTest.php:68).

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS ingest');
    $pg->statement('DROP TABLE IF EXISTS ingest.sources CASCADE');

    $pg->statement('CREATE TABLE ingest.sources (
        id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id       uuid NOT NULL,
        connection_id uuid,
        source_key    text NOT NULL,
        CONSTRAINT sources_unique_per_connection UNIQUE (connection_id, source_key)
    )');

    applySourcesUniqueIdxMigration('20260729150010_ingest_sources_unconnected_unique_idx.sql');
});

/** Strip comments and run the remaining CONCURRENTLY statement verbatim (no txn wrapper to strip). */
function applySourcesUniqueIdxMigration(string $filename): void
{
    $sql = (string) file_get_contents(base_path("supabase/migrations/{$filename}"));
    $sql = preg_replace('/--.*$/m', '', $sql) ?? $sql;

    $statements = array_filter(array_map('trim', explode(';', $sql)), fn (string $s) => $s !== '');

    foreach ($statements as $statement) {
        DB::connection('pgsql')->statement($statement);
    }
}

function insertSource(?string $connectionId, string $sourceKey, ?string $userId = null): void
{
    DB::connection('pgsql')->table('ingest.sources')->insert([
        'user_id' => $userId ?? (string) Str::uuid(),
        'connection_id' => $connectionId,
        'source_key' => $sourceKey,
    ]);
}

it('rejects two connection-less sources sharing (user_id, source_key)', function () {
    $userId = (string) Str::uuid();
    insertSource(null, 'bandcamp', $userId);

    $thrown = null;
    try {
        insertSource(null, 'bandcamp', $userId);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getCode())->toBe('23505');
});

it('accepts the same source_key for two connection-less sources under DIFFERENT users', function () {
    insertSource(null, 'bandcamp');
    insertSource(null, 'bandcamp');

    expect(DB::connection('pgsql')->table('ingest.sources')->where('source_key', 'bandcamp')->count())->toBe(2);
});

it('still accepts two rows sharing (user_id, source_key) when connection_id is distinct and non-null — the original constraint is unchanged', function () {
    $userId = (string) Str::uuid();

    insertSource((string) Str::uuid(), 'bandcamp', $userId);
    insertSource((string) Str::uuid(), 'bandcamp', $userId);

    expect(DB::connection('pgsql')->table('ingest.sources')->where('user_id', $userId)->where('source_key', 'bandcamp')->count())->toBe(2);
});
