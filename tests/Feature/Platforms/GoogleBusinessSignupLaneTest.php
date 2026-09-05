<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\GoogleBusinessAutoSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// A.13 (Phase-A proof catch, 2026-09-03): the Akro Studio rebuild proved the
// GBP enrichment still auto-connected square + instagram on a SIGN-UP build,
// bypassing the A.2/A.3 contract. On the sign-up lane every listing link now
// routes through LinkRoutingService as a banded intent.
//
// Corrected 2026-09-06: A.13 originally also kept a direct "post-claim
// connect" seed for booking/reservations on a CLAIMED user, reasoning that
// their own already-verified listing could be trusted. That carve-out WAS
// the GlossGenius/Fresha bug (same shape as the Square bug this file's
// booking test used to guard) — a claimed user's booking/reservation
// discovery now ALSO always routes through the suggestion pipeline. The only
// remaining direct-seed lane is the staff/ManyChat build (autoConnectBooking).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupNotificationsTable();
    setupRoutingTables();
    setupIngestTables();
    setupContentTables();
});

function gbslUser(string $status): User
{
    return User::factory()->create(['account_type' => 'business', 'status' => $status]);
}

it('routes listing links as banded intents on a sign-up build and connects nothing', function () {
    Queue::fake();
    $user = gbslUser('unclaimed');

    $findings = app(GoogleBusinessAutoSync::class)->seed(
        (string) $user->id,
        [
            'booking' => ['https://www.fresha.com/a/gbsl-venue'],
            'socials' => ['instagram' => 'https://www.instagram.com/gbsl_studio/'],
        ],
        'GBSL Studio',
    );

    expect($findings)->toBeEmpty()
        ->and(IntegrationConnection::query()->where('user_id', $user->id)->exists())->toBeFalse();

    $intents = DB::table('routing.source_intents')->where('user_id', $user->id)->get()->keyBy('surface_key');
    expect($intents)->toHaveKeys(['fresha.book', 'instagram.profile'])
        ->and($intents['fresha.book']->state)->toBe('proposed')
        ->and($intents['fresha.book']->band)->toBeIn(['auto', 'suggest'])
        ->and($intents['fresha.book']->origin)->toBe('google_business')
        ->and($intents['instagram.profile']->band)->toBeIn(['auto', 'suggest']);
});

it('suggests rather than connects a booking discovery for a claimed user', function () {
    Queue::fake();
    $user = gbslUser('active');

    app(GoogleBusinessAutoSync::class)->seed(
        (string) $user->id,
        ['booking' => ['https://www.fresha.com/a/gbsl-claimed']],
        'GBSL Claimed',
    );

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
    $intent = DB::table('routing.source_intents')->where('user_id', $user->id)->where('surface_key', 'fresha.book')->firstOrFail();
    expect($intent->state)->toBe('proposed');
});

it('keeps the direct booking seed for an unclaimed STAFF build (autoConnectBooking)', function () {
    Queue::fake();
    $user = gbslUser('unclaimed');

    app(GoogleBusinessAutoSync::class)->seed(
        (string) $user->id,
        ['booking' => ['https://www.fresha.com/a/gbsl-staff']],
        'GBSL Staff',
        null,
        true,
    );

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeTrue();
});
