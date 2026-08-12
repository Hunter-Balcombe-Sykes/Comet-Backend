<?php

// Slice 1a: a hard-deleted site.site_media row must not dangle a pointer.
// SET NULL (not CASCADE — item_media may still reference the asset; not
// RESTRICT — SiteMedia::forceDelete() runs in user-deletion flows).
//
// This proves the FK behaviour end-to-end: an asset row survives when its
// referenced site_media row is hard-deleted, and the site_media_id column
// becomes NULL. Self-provisioned schema like IngestCascadeDeletionTest.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

const MEDIA_FK_MIGRATION_SQL_PATH = 'supabase/migrations/20260812090000_content_media_assets_site_media_id.sql';

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS site');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS content');

    $pg->statement('DROP TABLE IF EXISTS content.media_assets CASCADE');
    $pg->statement('DROP TABLE IF EXISTS site.site_media CASCADE');

    // Base site.site_media table (minimal DDL for FK test).
    $pg->statement('CREATE TABLE site.site_media (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL,
        storage_path text NULL
    )');

    // Base content.media_assets table without the site_media_id FK yet.
    $pg->statement('CREATE TABLE content.media_assets (
        id text PRIMARY KEY NOT NULL,
        user_id text NOT NULL,
        fingerprint text NOT NULL,
        source_url text NULL,
        storage_path text NULL,
        mime_type text NULL,
        width integer NULL,
        height integer NULL,
        palette text NULL,
        variant_family text NULL,
        blurhash text NULL,
        created_at text NOT NULL,
        UNIQUE (user_id, fingerprint)
    )');

    // Apply the real migration SQL, read off disk — not retyped.
    mediaFkApplyMigrationSql(MEDIA_FK_MIGRATION_SQL_PATH);
});

afterAll(function () {
    $pg = DB::connection('pgsql');
    foreach (['content.media_assets', 'site.site_media'] as $t) {
        $pg->statement("DROP TABLE IF EXISTS {$t} CASCADE");
    }
});

/**
 * Strip the transaction wrapper (BEGIN/COMMIT/SET LOCAL) and comments from a
 * migration file and execute the remaining ALTER/CREATE statements one at a time.
 */
function mediaFkApplyMigrationSql(string $relativePath): void
{
    $sql = (string) file_get_contents(base_path($relativePath));

    // Strip -- comments (to end of line).
    $sql = preg_replace('/--.*$/m', '', $sql) ?? $sql;

    $statements = array_filter(array_map('trim', explode(';', $sql)), function (string $s) {
        if ($s === '') {
            return false;
        }
        $upper = strtoupper($s);

        return ! str_starts_with($upper, 'BEGIN') && ! str_starts_with($upper, 'COMMIT') && ! str_starts_with($upper, 'SET LOCAL');
    });

    foreach ($statements as $statement) {
        DB::connection('pgsql')->statement($statement);
    }
}

it('sets media_assets.site_media_id to NULL when the upload row is hard-deleted', function () {
    $pg = DB::connection('pgsql');

    // Insert a site_media row.
    $mediaId = (string) Str::uuid();
    $pg->table('site.site_media')->insert([
        'id' => $mediaId,
        'user_id' => (string) Str::uuid(),
        'storage_path' => 'test/path.jpg',
    ]);

    // Insert an asset row pointing at the site_media row.
    $assetId = 'asset-'.Str::random(8);
    $pg->table('content.media_assets')->insert([
        'id' => $assetId,
        'user_id' => 'user-'.Str::random(8),
        'fingerprint' => 'fp-'.Str::random(8),
        'source_url' => null,
        'storage_path' => null,
        'mime_type' => null,
        'width' => null,
        'height' => null,
        'palette' => null,
        'variant_family' => null,
        'blurhash' => null,
        'created_at' => now()->toDateTimeString(),
        'site_media_id' => $mediaId,
    ]);

    // Verify the asset points at the media row before deletion.
    $assetBefore = $pg->table('content.media_assets')->where('id', $assetId)->first();
    expect($assetBefore->site_media_id)->toBe($mediaId);

    // Hard-delete the site_media row.
    $pg->table('site.site_media')->where('id', $mediaId)->delete();

    // Assert the asset row survives and site_media_id is now NULL (SET NULL worked).
    $assetAfter = $pg->table('content.media_assets')->where('id', $assetId)->first();
    expect($assetAfter)->not->toBeNull();
    expect($assetAfter->site_media_id)->toBeNull();
});
