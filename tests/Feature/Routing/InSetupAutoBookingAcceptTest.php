<?php

use App\Catalog\CompiledCatalog;
use App\Jobs\Platforms\ConnectFetchJob;
use App\Jobs\Platforms\SquareAutoSelectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Routing\SuggestionApplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * The accept lane's half of the signup auto-pick (2026-09-04).
 *
 * A booking connection accepted through Get Started (SetupBatchApplier →
 * VerifyLinkJob → SuggestionApplier::apply) is born selection-less, and until
 * now the accept lane never enriched it — the claimed owner always paid a
 * client picker round-trip, even when the staff matcher would have identified
 * them instantly (simondoylehair, dev, 2026-09-04). SuggestionApplier now
 * hands the newborn row to AutoBookingConnectDispatcher, but ONLY while the
 * person is still in setup: post-setup accepts keep the picker-first flow,
 * and FreshaAutoSelector's picker-preserving degrade means a claimed partna
 * can only ever gain their OWN menu from this lane.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    setupIntegrationConnectionsTable();
});

function seedBookingIntentRow(string $userId, string $surfaceKey, string $identifier, string $url): object
{
    $id = (string) Str::uuid();
    DB::table('routing.source_intents')->insert([
        'id' => $id,
        'user_id' => $userId,
        'surface_key' => $surfaceKey,
        'routing_class' => 'booking',
        'identifier' => $identifier,
        'canonical_url' => $url,
        'state' => 'proposed',
        'origin' => 'link_in_bio',
        'first_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DB::table('routing.source_intents')->where('id', $id)->first();
}

function acceptFresha(User $user): IntegrationConnection
{
    $intent = seedBookingIntentRow((string) $user->id, 'fresha.book', 'anseo-studio-v0v92jna', 'https://www.fresha.com/a/anseo-studio-v0v92jna');
    $surface = CompiledCatalog::surface('fresha.book');

    return app(SuggestionApplier::class)->apply($user, $intent, $surface);
}

it('enriches a fresha accept in the background while the person is still in setup', function () {
    Queue::fake();
    $user = createTenant('insetup-accept'); // site row has no setup_completed_at → in setup

    $connection = acceptFresha($user);

    expect(($connection->fresh()->payload)['connectMode'] ?? null)->toBe('auto');
    Queue::assertPushed(ConnectFetchJob::class);
});

it('does NOT enrich once setup is completed — the picker-first flow stays', function () {
    Queue::fake();
    $user = createTenant('postsetup-accept');
    DB::table('site.sites')->where('user_id', $user->id)->update(['setup_completed_at' => now()]);
    $user->unsetRelation('site');

    $connection = acceptFresha($user);

    expect(($connection->fresh()->payload)['connectMode'] ?? null)->toBeNull();
    Queue::assertNotPushed(ConnectFetchJob::class);
});

it('does NOT enrich when the kill switch is off', function () {
    Queue::fake();
    config(['partna.connect.auto_booking.enabled' => false]);
    $user = createTenant('killswitch-accept');

    acceptFresha($user);

    Queue::assertNotPushed(ConnectFetchJob::class);
});

it('routes a square accept to its own auto-select arm', function () {
    Queue::fake();
    $user = createTenant('square-accept');
    $intent = seedBookingIntentRow((string) $user->id, 'square.book', 'sq-merchant', 'https://book.squareup.com/appointments/abc/location/def');
    $surface = CompiledCatalog::surface('square.book');

    app(SuggestionApplier::class)->apply($user, $intent, $surface);

    Queue::assertPushed(SquareAutoSelectJob::class);
    Queue::assertNotPushed(ConnectFetchJob::class);
});
