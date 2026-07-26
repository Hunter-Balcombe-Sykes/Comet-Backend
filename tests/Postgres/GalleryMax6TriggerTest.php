<?php

// Regression sentinel for core.enforce_site_gallery_max6() — the Postgres
// trigger (supabase/migrations/20260726000000_baseline_pilot.sql: function
// lines 191-227, trigger line 3455) that hard-caps site.site_media's
// 'gallery' pool at 6 non-deleted, non-failed rows per site. This is a real
// PL/pgSQL RAISE EXCEPTION inside a BEFORE INSERT/UPDATE trigger — SQLite
// cannot reproduce it, so this can only run for real against Postgres (see
// Tests\PostgresTestCase for the skip-without-Postgres guard). Runs via
// `composer test:pg` / phpunit.pg.xml.
//
// Why this exists: scripts/launch-check/k6/seed.sql seeds exactly 6 gallery
// images for the load-test handle — not the 40 the harness's plan originally
// assumed — because raw SQL hit this trigger head-on when built 2026-07-26.
// If this cap ever changes (or the trigger is dropped), that seed silently
// becomes wrong or starts erroring; this test is what should catch it first.
//
// Minimal self-provisioned schema (like tests/Postgres/ItemSlugAllocator*
// Test.php): only site.site_media plus the real trigger function/trigger
// body, copied verbatim from the migration — a regression sentinel needs the
// actual trigger, not a re-implementation of its logic.

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS core');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS site');
    $pg->statement('DROP TABLE IF EXISTS site.site_media');
    $pg->statement('CREATE TABLE site.site_media (
        id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        site_id           uuid NOT NULL,
        path              text NOT NULL,
        pool              varchar(20) NOT NULL DEFAULT \'gallery\',
        processing_state  varchar(20) NOT NULL DEFAULT \'ready\',
        deleted_at        timestamptz
    )');

    // Verbatim from baseline_pilot.sql:191-227.
    $pg->statement('CREATE OR REPLACE FUNCTION core.enforce_site_gallery_max6() RETURNS trigger
        LANGUAGE plpgsql
        SET search_path TO \'\'
        AS $$
    declare
      cnt int;
    begin
      if new.pool is distinct from \'gallery\' then
        return new;
      end if;

      if new.deleted_at is not null then
        return new;
      end if;

      if tg_op = \'UPDATE\' then
        if old.site_id = new.site_id and old.deleted_at is null and new.deleted_at is null then
          return new;
        end if;
      end if;

      select count(*)
        into cnt
      from site.site_media si
      where si.site_id = new.site_id
        and si.pool = \'gallery\'
        and si.deleted_at is null
        and si.processing_state <> \'failed\'
        and (tg_op <> \'UPDATE\' or si.id <> new.id);

      if cnt >= 6 then
        raise exception \'Gallery limit reached: max 6 images per site\';
      end if;

      return new;
    end;
    $$');

    // Verbatim from baseline_pilot.sql:3455.
    $pg->statement('CREATE OR REPLACE TRIGGER enforce_site_gallery_max6
        BEFORE INSERT OR UPDATE OF site_id, deleted_at ON site.site_media
        FOR EACH ROW EXECUTE FUNCTION core.enforce_site_gallery_max6()');
});

it('allows exactly 6 ready gallery rows per site and raises on the 7th', function () {
    $pg = DB::connection('pgsql');
    $siteId = (string) Str::uuid();

    for ($i = 1; $i <= 6; $i++) {
        $pg->table('site.site_media')->insert([
            'site_id' => $siteId,
            'path' => "img-{$i}.webp",
            'pool' => 'gallery',
            'processing_state' => 'ready',
        ]);
    }

    $thrown = null;
    try {
        $pg->table('site.site_media')->insert([
            'site_id' => $siteId,
            'path' => 'img-7.webp',
            'pool' => 'gallery',
            'processing_state' => 'ready',
        ]);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toContain('Gallery limit reached: max 6 images per site');

    // The failed 7th insert must not have landed — confirms the trigger
    // fired BEFORE the row was written, not as some after-the-fact cleanup.
    expect($pg->table('site.site_media')->where('site_id', $siteId)->count())->toBe(6);
});

it('does not count failed or soft-deleted rows toward the cap', function () {
    // The trigger's own count query filters existing rows to processing_state
    // <> 'failed' AND deleted_at is null — but it does NOT exempt the INCOMING
    // row based on its own state (only an incoming deleted_at IS NOT NULL row
    // short-circuits, before the count runs at all). So a synthetic direct
    // insert of an already-'failed' row as the "7th" still trips the cap —
    // that's not how real uploads land (they start 'pending'/'ready' and
    // transition to 'failed' later via UPDATE). Test the realistic shape
    // instead: failed/deleted rows already on the table free up real slots.
    $pg = DB::connection('pgsql');
    $siteId = (string) Str::uuid();

    for ($i = 1; $i <= 5; $i++) {
        $pg->table('site.site_media')->insert([
            'site_id' => $siteId,
            'path' => "img-{$i}.webp",
            'pool' => 'gallery',
            'processing_state' => 'ready',
        ]);
    }
    $pg->table('site.site_media')->insert([
        'site_id' => $siteId,
        'path' => 'img-failed.webp',
        'pool' => 'gallery',
        'processing_state' => 'failed',
    ]);
    $pg->table('site.site_media')->insert([
        'site_id' => $siteId,
        'path' => 'img-deleted.webp',
        'pool' => 'gallery',
        'processing_state' => 'ready',
        'deleted_at' => now(),
    ]);
    expect($pg->table('site.site_media')->where('site_id', $siteId)->count())->toBe(7);

    // Only 5 non-failed, non-deleted rows exist — a 6th real slot must be allowed.
    $pg->table('site.site_media')->insert([
        'site_id' => $siteId,
        'path' => 'img-6-real.webp',
        'pool' => 'gallery',
        'processing_state' => 'ready',
    ]);
    expect($pg->table('site.site_media')->where('site_id', $siteId)->count())->toBe(8);

    // Now 6 non-failed/non-deleted rows exist — a 7th real slot must raise.
    $thrown = null;
    try {
        $pg->table('site.site_media')->insert([
            'site_id' => $siteId,
            'path' => 'img-7-real.webp',
            'pool' => 'gallery',
            'processing_state' => 'ready',
        ]);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toContain('Gallery limit reached: max 6 images per site');
});
