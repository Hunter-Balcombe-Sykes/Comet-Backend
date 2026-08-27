<?php

use App\Jobs\Ingest\RunSourceJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * T3 sequencing (2026-08-27 unclaimed-signup quality plan, issue 3): the
 * eager Fresha ingest used to fire the moment the CONNECTION ROW was created
 * — before ConnectFetchJob had fetched anything, so before any selection
 * existed. Measured on the simondoylehair build: first ingest at +0s wrote
 * "no_selection", 0 services; the correct ingest only came ~2 min later when
 * the payload write changed selection_ref ('reselected'). The empty first
 * pass then fed the site-document's empty first state.
 *
 * Fresha's manifest now declares eagerNeedsFetchedPayload: its ingest reads
 * OUR stored payload/selection, so an eager run before the first fetch can
 * only ever produce the empty answer. The 'reselected' rerun at payload
 * write is the correctly-ordered ingest and is unchanged. Connectors that
 * read the VENDOR directly (bandcamp et al) keep their eager-on-create run.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIntegrationConnectionsTable();
    setupIngestTables();
    Queue::fake();
});

function feisUser(string $handle): User
{
    $user = User::create([
        'handle' => $handle, 'handle_lc' => strtolower($handle), 'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle), 'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(), 'primary_email' => "{$handle}@example.com",
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'subdomain' => $handle,
        'is_published' => 1, 'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    return $user->fresh();
}

it('does not eager-run the fresha source while the connection is still pending its first fetch', function () {
    $user = feisUser('feispending');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/some-salon-abc123'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
    ]);

    Queue::assertNotPushed(RunSourceJob::class);
});

it('eager-runs the fresha source when the fetched payload lands its selection', function () {
    $user = feisUser('feisselect');

    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/some-salon-abc123'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
    ]);
    Queue::assertNotPushed(RunSourceJob::class);

    // ConnectFetchJob's write: payload with the auto-selection, row marked ok.
    $connection->update([
        'payload' => [
            'url' => 'https://www.fresha.com/a/some-salon-abc123',
            'selection' => ['mode' => 'storewide', 'employeeId' => null],
            'raw' => ['services' => []],
        ],
        'last_refreshed_at' => now(),
        'last_refresh_status' => 'ok',
    ]);

    Queue::assertPushed(RunSourceJob::class);
});

it('a vendor-reading connector still eager-runs on create while pending (bandcamp control)', function () {
    $user = feisUser('feiscontrol');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-feiscontrol',
        'payload' => ['url' => 'https://someartist.bandcamp.com'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
    ]);

    Queue::assertPushed(RunSourceJob::class);
});
