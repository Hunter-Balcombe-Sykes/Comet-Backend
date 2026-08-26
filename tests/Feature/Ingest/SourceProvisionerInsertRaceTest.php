<?php

// #LIFE-14 regression: SourceProvisioner::sync() used to be a bare pre-read
// SELECT -> conditional INSERT with no catch anywhere in the call chain. Two
// concurrent saves for the SAME connection that both see "no existing row"
// would both attempt the INSERT; the loser hit sources_unique_per_connection
// (supabase/migrations/20260727130000_ingest_schema.sql:57) and raised an
// unhandled UniqueConstraintViolationException.
//
// insertOrIgnore BEHAVIOUR is provable here: the sqlite stand-in
// (tests/Pest.php:3259) carries the real UNIQUE (connection_id, source_key),
// so `insert or ignore` really does resolve the conflict to 0 rows. What
// SQLite cannot supply is a second independently-committing connection — the
// DB::listen injection below reproduces the ORDERING (winner's row exists by
// the time the caller's own insert runs), not real concurrency. The real
// 23505-on-a-real-constraint proof lives in the PG lane counterpart,
// tests/Postgres/SourceProvisionerInsertRacePgTest.php.

use App\Ingest\SourceProvisioner;
use App\Jobs\Ingest\RunSourceJob;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
});

// File-local, deliberately NOT reused from SourceProvisionerTest.php: helpers
// defined in a sibling file only exist when both files land in the SAME
// paratest process, so borrowing them makes this file pass serially and fail
// under `php artisan test --parallel`. Distinct names because both files DO
// share a process on a serial run, where a redeclare is fatal.
function raceUser(): string
{
    return createTenant('race-'.Str::lower(Str::random(6)))->id;
}

/** @param array<string, mixed> $attributes */
function raceConnection(string $userId, array $attributes): IntegrationConnection
{
    return IntegrationConnection::create($attributes + [
        'user_id' => $userId,
        'resource_id' => 'acct-'.substr(sha1(Str::random(8)), 0, 16),
        'payload' => [],
        'is_active' => true,
    ]);
}

it('falls through to the update path and still writes this caller\'s identity when the insert is lost to a concurrent winner', function () {
    Bus::fake();
    $userId = raceUser();
    $connection = raceConnection($userId, ['platform' => 'bandcamp', 'payload' => ['url' => 'https://real.bandcamp.com']]);
    // Wipe what the observer already provisioned so sync() below sees a
    // genuine no-existing-row case and actually reaches the insert.
    DB::table('ingest.sources')->where('connection_id', $connection->id)->delete();

    $fresh = $connection->fresh();

    $injected = false;
    DB::listen(function ($query) use (&$injected, $fresh) {
        if ($injected) {
            return; // fires exactly once.
        }
        if (! str_contains($query->sql, 'sources')) {
            return;
        }
        if (! in_array($fresh->id, $query->bindings, true)) {
            return;
        }

        $injected = true;

        // A concurrent winner that already committed its row for this exact
        // (connection_id, source_key) pair — by the time execution returns to
        // sync(), $existing is already fixed at null (the SELECT already ran),
        // so the caller's own insertOrIgnore below races straight into this row.
        DB::table('ingest.sources')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $fresh->user_id,
            'connection_id' => $fresh->id,
            'source_key' => 'bandcamp',
            'surface_key' => 'bandcamp.artist',
            'identifier' => 'https://winner-placeholder.bandcamp.com',
        ]);
    });

    $result = app(SourceProvisioner::class)->sync($fresh);

    expect($injected)->toBeTrue('the race was never injected — every assertion below is vacuous');
    expect($result)->toBe(['status' => 'updated', 'source_key' => 'bandcamp']);

    $rows = DB::table('ingest.sources')->where('connection_id', $fresh->id)->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->identifier)->toBe('https://real.bandcamp.com');
});

it('buys no eager run for the race loser (the money assertion, observer level)', function () {
    Bus::fake();
    $userId = raceUser();

    // instagram is a PAID connector (CostClass::Actor) that opts into
    // eagerOnConnect — exactly the connector class where a race-loser bonus
    // dispatch would spend real money if #LIFE-14's insertOrIgnore alone (no
    // fall-through gate) were the whole fix: it would report 'created' and
    // maybeRunEagerly() would dispatch a second paid actor run.
    $injected = false;
    DB::listen(function ($query) use (&$injected, $userId) {
        if ($injected) {
            return; // fires exactly once.
        }
        if (! str_contains($query->sql, 'sources')) {
            return;
        }
        // The connection id isn't known until IntegrationConnection::create()
        // below assigns its own UUID, so pull it out of THIS statement's own
        // bindings instead of asserting it up front.
        $connectionId = collect($query->bindings)->first(
            fn ($binding) => is_string($binding) && Str::isUuid($binding)
        );
        if ($connectionId === null) {
            return;
        }

        $injected = true;

        DB::table('ingest.sources')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'connection_id' => $connectionId,
            'source_key' => 'instagram',
            'surface_key' => 'instagram.profile',
            'identifier' => 'winner_placeholder',
        ]);
    });

    IntegrationConnection::create([
        'user_id' => $userId,
        'platform' => 'instagram',
        'resource_id' => 'acct-'.substr(sha1(Str::random(8)), 0, 16),
        'payload' => ['username' => 'real_account'],
        'is_active' => true,
    ]);

    expect($injected)->toBeTrue('the race was never injected — the assertion below is vacuous');
    Bus::assertNotDispatched(RunSourceJob::class);
});
