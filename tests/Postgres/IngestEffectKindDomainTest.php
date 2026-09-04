<?php

// SCHEMA-2 regression sentinel. ingest.effects is the charge-once MONEY
// ledger (C6) — supabase/migrations/20260727130000_ingest_schema.sql:162
// documented `-- http | actor | api | ai` for `kind` but enforced nothing.
// This test binds the REAL migration file, not a hand-copy.
//
// It pinned the SUPERSEDED 20260729150002 (four values) until 2026-09-04,
// eleven days after 20260902010000 widened the domain to admit 'vendor' — so
// the sentinel was silently disarmed on the money ledger it exists to guard:
// the dataset below never offered 'vendor', so nothing was ever rejected and
// the suite stayed green. A rollback to the four-value CHECK (that migration
// documents one, at :14-16) would have gone unnoticed while every eager run of
// the seven ScrapeCreators connectors raised 23514 in production.
//
// The by-name pin stays deliberately: it proves the migration SQL is
// executable, which a glob over the directory would not. What makes the pin
// self-correcting is the LAST test in this file, which derives the writers
// from app/ rather than from anyone's memory.

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS ingest');
    $pg->statement('DROP TABLE IF EXISTS ingest.effects');
    $pg->statement('CREATE TABLE ingest.effects (
        digest text PRIMARY KEY,
        kind   text NOT NULL,
        status text NOT NULL DEFAULT \'ok\'
    )');

    applyAlterFromEffectsMigration('20260902010000_effects_kind_check_admits_vendor.sql');
});

/** Strip BEGIN/COMMIT/SET LOCAL/comments and run the remaining statements verbatim. */
function applyAlterFromEffectsMigration(string $filename): void
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

it('accepts every documented kind', function (string $kind) {
    // actor:  {Doordash,UberEats,Square}MenuConnector, InstagramConnector,
    //         FacebookConnector, TiktokConnector, SpotifyTracksConnector,
    //         MusicTrackPull
    // api:    GoogleBusinessConnector.php:137
    // vendor: the seven ScrapeCreators lanes — BlueskyConnector.php:77,
    //         TwitchConnector.php:87, ThreadsConnector.php:85,
    //         PinterestConnector.php:69, SpotifyPodcastsConnector.php:90,
    //         FacebookEventsConnector.php:79, TiktokShopConnector.php:81
    // http:   written by the EffectLedger test suite
    // ai:     reserved by the column comment, unused today
    DB::connection('pgsql')->table('ingest.effects')->insert([
        'digest' => hash('sha256', $kind),
        'kind' => $kind,
    ]);

    expect(DB::connection('pgsql')->table('ingest.effects')->where('kind', $kind)->exists())->toBeTrue();
})->with(['http', 'actor', 'api', 'ai', 'vendor']);

it('rejects a kind outside the money ledger domain', function () {
    $thrown = null;
    try {
        DB::connection('pgsql')->table('ingest.effects')->insert([
            'digest' => hash('sha256', 'apify'),
            'kind' => 'apify',
        ]);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getCode())->toBe('23514');
});

// The guard that matters, mirroring IngestAnomalyKindDomainTest's own final
// test. The dataset above is hand-written, which is exactly how this file spent
// eleven days pinned to a superseded migration without anyone noticing: nothing
// connected the CHECK to the code that writes into it. This derives the kinds
// from the source, so a connector added on a branch that cannot see this file
// fails HERE rather than on the charge-once money path in production.
it('the CHECK domain covers every kind literal written anywhere in app/', function () {
    $appDir = dirname(__DIR__, 2).'/app';
    $written = [];

    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $src = (string) file_get_contents($file->getPathname());
        // HttpIo::effect($kind, …) is the single seam every billed call goes
        // through — see GoogleBusinessConnector.php:23 — so the first argument
        // IS the ledger's kind. Direct EffectLedger::once() calls are picked up
        // by the second pattern.
        if (preg_match_all("/->effect\(\s*'([a-z_]+)'/", $src, $m)) {
            foreach ($m[1] as $lit) {
                $written[$lit] = true;
            }
        }
        if (preg_match_all("/EffectLedger::once\(\s*[^,]+,\s*'([a-z_]+)'/", $src, $m)) {
            foreach ($m[1] as $lit) {
                $written[$lit] = true;
            }
        }
    }

    $written = array_keys($written);
    sort($written);
    expect($written)->not->toBeEmpty('found no effect kind writers — the scan is broken, not the code');

    $rejected = [];
    foreach ($written as $kind) {
        try {
            DB::connection('pgsql')->table('ingest.effects')->insert([
                'digest' => hash('sha256', 'scan:'.$kind),
                'kind' => $kind,
            ]);
        } catch (QueryException) {
            $rejected[] = $kind;
        }
    }

    expect($rejected)->toBe([], sprintf(
        "These kind literals are written by app/ but REJECTED by effects_kind_check:\n  %s\n".
        "Widen the CHECK with a new migration, then repoint this file's beforeEach at it.\n".
        'Scanned writers: %s',
        implode("\n  ", $rejected),
        implode(', ', $written)
    ));
});
