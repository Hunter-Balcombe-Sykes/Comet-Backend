<?php

// Applied-schema lane. R8 (Instagram build wave, 2026-08-18): `storage_path IS
// NULL` collapsed "never dispatched", "queued", "running" and "permanently
// dead" into one indistinguishable value, so 32 unmirrored assets could not be
// explained by the one warning line the wave produced. These columns are what
// make the row the record — ProjectionWriter reads mirror_attempts on every
// projection pass, so a missing column here is a hard failure, not a gap.

use Illuminate\Support\Facades\DB;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class)->in(__FILE__);

/** @return object{data_type: string, is_nullable: string, column_default: ?string}|null */
function mirrorStateColumn(string $name): ?object
{
    return DB::connection('pgsql')->selectOne(<<<'SQL'
        SELECT data_type, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_schema = 'content' AND table_name = 'media_assets'
          AND column_name = ?
    SQL, [$name]);
}

it('counts consecutive mirror failures in a NOT NULL integer defaulting to zero', function () {
    $col = mirrorStateColumn('mirror_attempts');

    expect($col)->not->toBeNull('content.media_assets.mirror_attempts is missing');
    expect($col->data_type)->toBe('integer');

    // NOT NULL with a zero default is what lets the dispatch filter read
    // `mirror_attempts >= max` without a COALESCE, and what makes every
    // pre-existing row read as "never failed" rather than "unknown".
    expect($col->is_nullable)->toBe('NO');
    expect($col->column_default)->toBe('0');
})->group('postgres');

it('keeps the last attempt time nullable so "never tried" stays expressible', function () {
    $col = mirrorStateColumn('mirror_last_attempt_at');

    expect($col)->not->toBeNull('content.media_assets.mirror_last_attempt_at is missing');
    expect($col->data_type)->toBe('timestamp with time zone');

    // NULL is the whole point: it is the only value that says "this asset has
    // never been attempted", which is precisely the state R8 could not see.
    expect($col->is_nullable)->toBe('YES');
    expect($col->column_default)->toBeNull();
})->group('postgres');

it('stores the failure reason as unconstrained text', function () {
    $col = mirrorStateColumn('mirror_last_reason');

    expect($col)->not->toBeNull('content.media_assets.mirror_last_reason is missing');
    expect($col->data_type)->toBe('text');
    expect($col->is_nullable)->toBe('YES');
})->group('postgres');

it('puts no CHECK constraint on the reason vocabulary', function () {
    // Deliberate. The reasons are MediaMirror::fail()'s slugs; a CHECK here
    // would mean a migration every time a new failure mode is named, and the
    // predictable result is a reason that gets folded into an existing slug to
    // avoid the migration — losing the remedy the slug existed to carry.
    $checks = DB::connection('pgsql')->select(<<<'SQL'
        SELECT con.conname
        FROM pg_constraint con
        JOIN pg_class rel ON rel.oid = con.conrelid
        JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
        JOIN pg_attribute att ON att.attrelid = rel.oid AND att.attnum = ANY (con.conkey)
        WHERE nsp.nspname = 'content' AND rel.relname = 'media_assets'
          AND con.contype = 'c' AND att.attname = 'mirror_last_reason'
    SQL);

    expect($checks)->toBeEmpty();
})->group('postgres');
