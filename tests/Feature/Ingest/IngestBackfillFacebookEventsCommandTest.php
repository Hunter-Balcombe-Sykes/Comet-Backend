<?php

use App\Jobs\Ingest\RunSourceJob;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Item 11a (2026-09-01): the one-shot walk that turns the facebook
// connections already live at ship time into events sources — same
// provisioner as the observer hook, so backfilled and connect-time rows
// cannot drift. Provisioning is free; --eager is the explicit spend stamp.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    Bus::fake([RunSourceJob::class]);
});

function backfillFbConnection(string $url): IntegrationConnection
{
    $connection = IntegrationConnection::create([
        'user_id' => createTenant('bf-'.Str::lower(Str::random(6)))->id,
        'platform' => 'facebook',
        'resource_id' => 'acct-'.substr(sha1(Str::random(8)), 0, 16),
        'payload' => ['url' => $url],
        'is_active' => true,
    ]);

    // The observer's satellite hook (wired 2026-09-01) provisions on create;
    // drop that row so the fixture is what this command exists for — a
    // connection that PREDATES the hook and has no satellite yet.
    DB::table('ingest.sources')
        ->where('connection_id', $connection->id)
        ->where('source_key', 'facebook_events')
        ->delete();

    return $connection;
}

it('provisions satellites for existing facebook connections without stamping spend', function () {
    $a = backfillFbConnection('https://www.facebook.com/thetotehotel');
    $b = backfillFbConnection('https://www.facebook.com/cornerhotel');

    $this->artisan('ingest:backfill-facebook-events')->assertExitCode(0);

    $rows = DB::table('ingest.sources')->where('source_key', 'facebook_events')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('identifier')->sort()->values()->all())
        ->toBe(['https://www.facebook.com/cornerhotel', 'https://www.facebook.com/thetotehotel'])
        // No trigger by default: rows sit inert until --eager or a listing in
        // partna.ingest_scheduled_paid_sources — provisioning is not a spend
        // decision.
        ->and($rows->filter(fn ($r) => (bool) $r->needs_eager_run))->toHaveCount(0)
        ->and($rows->filter(fn ($r) => (bool) $r->auto_sync))->toHaveCount(0);

    // Idempotent — a re-run changes nothing.
    $this->artisan('ingest:backfill-facebook-events')->assertExitCode(0);
    expect(DB::table('ingest.sources')->where('source_key', 'facebook_events')->count())->toBe(2);
});

it('stamps the one-shot eager obligation only with --eager, only on created rows', function () {
    backfillFbConnection('https://www.facebook.com/thetotehotel');

    $this->artisan('ingest:backfill-facebook-events')->assertExitCode(0);
    $this->artisan('ingest:backfill-facebook-events', ['--eager' => true])->assertExitCode(0);

    // The second run found the row already provisioned ('unchanged'), so the
    // paid one-shot was NOT stamped — --eager buys runs for NEW rows only.
    expect((bool) DB::table('ingest.sources')->where('source_key', 'facebook_events')->value('needs_eager_run'))
        ->toBeFalse();

    $fresh = backfillFbConnection('https://www.facebook.com/cornerhotel');

    $this->artisan('ingest:backfill-facebook-events', ['--eager' => true])->assertExitCode(0);

    expect((bool) DB::table('ingest.sources')
        ->where('connection_id', $fresh->id)->where('source_key', 'facebook_events')
        ->value('needs_eager_run'))->toBeTrue();
});

it('writes nothing on a dry run', function () {
    backfillFbConnection('https://www.facebook.com/thetotehotel');

    $this->artisan('ingest:backfill-facebook-events', ['--dry-run' => true])->assertExitCode(0);

    expect(DB::table('ingest.sources')->where('source_key', 'facebook_events')->count())->toBe(0);
});
