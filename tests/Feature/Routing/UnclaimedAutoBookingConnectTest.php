<?php

use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Routing\Importers\LinkInBioImporter;
use Illuminate\Support\Facades\DB;
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
    setupPreAccountBuildsTable();
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

it('suggests (never auto-connects) a Fresha link that arrives via an aggregator unroll for an unclaimed user', function () {
    // REVERSED by the setup-dialog run (A.2, owner decision 1, 2026-09-02):
    // R14's auto-connect gave the demo site a booking CTA, but the new
    // sign-up contract is "nothing is connected for them except the source
    // platform" — the harvest becomes a banded Choose the setup dialog asks,
    // and the Fresha venue itself reaches the person via the pre-scrape
    // lane's listing candidates (A.4/A.5), not a silent connect.
    Queue::fake();
    freshaBioPage();

    $user = createTenant('unclaimed-agg');
    $user->forceFill(['status' => 'unclaimed', 'first_name' => 'Simon', 'last_name' => 'Doyle'])->save();

    app(LinkInBioImporter::class)->import($user, 'https://example.com/simon');

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();

    $intent = DB::table('routing.source_intents')
        ->where('user_id', $user->id)->where('surface_key', 'fresha.book')->first();
    expect($intent)->not->toBeNull()
        ->and($intent->state)->toBe('proposed');

    Queue::assertNotPushed(ConnectFetchJob::class);
});

it('does NOT auto-connect for a claimed user either — same suggest-only outcome as the unclaimed lane now', function () {
    // Pre-2026-09-03 this pinned a NARROWER guarantee: the harvest still
    // auto-connected (Place) for a claimed user, and this test only checked
    // that AutoBookingConnectDispatcher's team-picker auto-fill was skipped
    // for them (gated on $user->isUnclaimed() in SourceReconciler.php).
    // Since "nothing a harvester found ever auto-connects" (owner,
    // 2026-09-03), decide() never reaches Place for this indirect,
    // unconfirmed 'link_in_bio' origin regardless of claim state — so no
    // connection is written at all any more, for a claimed user exactly as
    // for an unclaimed one (the sibling test above). The picker-preserving
    // claim/unclaimed split in AutoBookingConnectDispatcher only fires once
    // a Place actually reaches applyIntent() (e.g. via the suggestions-inbox
    // accept lane), which this harvest path can no longer do.
    Queue::fake();
    freshaBioPage();

    $user = createTenant('claimed-agg');
    $user->forceFill(['status' => 'active', 'first_name' => 'Simon', 'last_name' => 'Doyle'])->save();

    app(LinkInBioImporter::class)->import($user, 'https://example.com/simon');

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();

    $intent = DB::table('routing.source_intents')
        ->where('user_id', $user->id)->where('surface_key', 'fresha.book')->first();
    expect($intent)->not->toBeNull()
        ->and($intent->state)->toBe('proposed');

    Queue::assertNotPushed(ConnectFetchJob::class);
});
