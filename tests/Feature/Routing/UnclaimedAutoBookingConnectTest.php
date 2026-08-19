<?php

use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Routing\Importers\LinkInBioImporter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * The aggregator lane's half of R14.
 *
 * A Fresha link found by unrolling a Linktree reaches SourceReconciler, not
 * LinkRouter — and on that lane LinkInBioScanJob::$autoConnectBooking is
 * VESTIGIAL (its own docblock says so since the 2026-08-18 migration), so no
 * auto-connect was ever dispatched. Widening the pre-account gate alone leaves
 * this road to the identical symptom: a selection-less connection publishing
 * nothing on a public unclaimed page.
 *
 * The discriminator here is the USER's claim state, not a caller flag. A flag
 * has to be threaded correctly through every hop and one already lost it;
 * "this site has no owner in front of it" is a fact the reconciler can read,
 * and it stops being true the moment the site is claimed — which is exactly
 * when a picker becomes the right answer again.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupNotificationsTable();
    setupRoutingTables();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    setupIntegrationConnectionsTable();
});

function freshaBioPage(): void
{
    Http::fake(['*' => Http::response(
        '<html><body><a href="https://www.fresha.com/a/anseo-studio-v0v92jna">Book now</a></body></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);
}

it('auto-connects a Fresha link that arrives via an aggregator unroll for an unclaimed user', function () {
    Queue::fake();
    freshaBioPage();

    $user = createTenant('unclaimed-agg');
    $user->forceFill(['status' => 'unclaimed', 'first_name' => 'Simon', 'last_name' => 'Doyle'])->save();

    app(LinkInBioImporter::class)->import($user, 'https://example.com/simon');

    // The connection must actually have been placed — otherwise the dispatch
    // assertion below could pass for the wrong reason on a lane that never ran.
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeTrue();

    Queue::assertPushed(ConnectFetchJob::class);
});

it('does NOT auto-connect for a claimed user — they keep the picker', function () {
    // The important half. Without this, the change quietly takes the team-member
    // picker away from every real dashboard user whose bio page names a salon.
    Queue::fake();
    freshaBioPage();

    $user = createTenant('claimed-agg');
    $user->forceFill(['status' => 'active', 'first_name' => 'Simon', 'last_name' => 'Doyle'])->save();

    app(LinkInBioImporter::class)->import($user, 'https://example.com/simon');

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeTrue();

    Queue::assertNotPushed(ConnectFetchJob::class);
});
