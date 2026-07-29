<?php

// DINT-7 regression sentinel. site.sections carries both page_id and site_id
// as independent NOT NULL FKs to different tables
// (supabase/migrations/20260727150000_sections_and_documents.sql:36-37) with
// nothing correlating them — SectionPolicy.php:69 authorises off
// sections.site_id directly, while other paths join through pages, and the
// two could silently disagree. This test binds the REAL composite FK from
// 20260729150014, not a hand-copy.
//
// The third case (UPDATE site_id alone) is the property a BEFORE-INSERT-only
// trigger would have missed — the plan rejected that approach specifically
// because a declarative FK is checked on UPDATE of either column for free.

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS site');
    $pg->statement('DROP TABLE IF EXISTS site.sections CASCADE');
    $pg->statement('DROP TABLE IF EXISTS site.pages CASCADE');

    $pg->statement('CREATE TABLE site.pages (
        id      uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        site_id uuid NOT NULL
    )');

    $pg->statement('CREATE TABLE site.sections (
        id      uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        page_id uuid NOT NULL REFERENCES site.pages (id) ON DELETE CASCADE,
        site_id uuid NOT NULL
    )');

    applySectionsFkMigration('20260729150012_pages_id_site_unique_idx.sql');
    applySectionsFkMigration('20260729150013_pages_id_site_unique_constraint.sql');
    applySectionsFkMigration('20260729150014_sections_page_site_fk_not_valid.sql');
});

/** Strip BEGIN/COMMIT/SET LOCAL/comments and run the remaining statements verbatim. */
function applySectionsFkMigration(string $filename): void
{
    $sql = (string) file_get_contents(base_path("supabase/migrations/{$filename}"));
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

function createPage(string $siteId): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.pages')->insert(['id' => $id, 'site_id' => $siteId]);

    return $id;
}

it('rejects a section whose site_id disagrees with its page\'s site_id', function () {
    $pageSiteId = (string) Str::uuid();
    $pageId = createPage($pageSiteId);

    $thrown = null;
    try {
        DB::connection('pgsql')->table('site.sections')->insert([
            'page_id' => $pageId,
            'site_id' => (string) Str::uuid(), // a DIFFERENT site
        ]);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getCode())->toBe('23503');
});

it('accepts a section whose site_id matches its page\'s site_id', function () {
    $siteId = (string) Str::uuid();
    $pageId = createPage($siteId);

    DB::connection('pgsql')->table('site.sections')->insert([
        'page_id' => $pageId,
        'site_id' => $siteId,
    ]);

    expect(DB::connection('pgsql')->table('site.sections')->where('page_id', $pageId)->count())->toBe(1);
});

it('rejects an UPDATE that moves site_id alone to a different site — the case a BEFORE-INSERT trigger would miss', function () {
    $siteId = (string) Str::uuid();
    $pageId = createPage($siteId);

    $sectionId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sections')->insert([
        'id' => $sectionId,
        'page_id' => $pageId,
        'site_id' => $siteId,
    ]);

    $thrown = null;
    try {
        DB::connection('pgsql')->table('site.sections')->where('id', $sectionId)->update([
            'site_id' => (string) Str::uuid(),
        ]);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getCode())->toBe('23503');
});
