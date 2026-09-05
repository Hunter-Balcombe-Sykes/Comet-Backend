<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\LinkPoolReader;
use App\Services\Platforms\LinkRouter;
use App\Services\Platforms\RouteContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * 2026-09-05, two related fixes to the same live shape (squeakprobarber: a
 * venue's own Booksy listing beside the barber's personal Booksy profile,
 * found in the same bio):
 *
 *  1. Booking joined reservations'/ordering's self-managed slot
 *     (LinkRouter::routeClassified's $slotSelfManaged) — the seenPlatforms
 *     short-circuit used to eat the second link before either seeder got a
 *     chance to answer it at all.
 *  2. Owner policy: a harvest never auto-adds a platform, only ever suggests
 *     one. Booking's legacy seedBooking()->resolveBookingLink()->write() was
 *     the one category still connecting the FIRST link on the spot; it now
 *     routes through the same Engine-1 bridge ('link' category) as every
 *     other harvested surface, UNLESS $ctx->autoConnectBooking is set — the
 *     one carve-out (a staff/ManyChat build has nobody at a setup dialog to
 *     accept a suggestion) that keeps the immediate connect.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    setupRoutingTables();
    Http::fake();
});

function bookingSlotUser(array $attrs = []): User
{
    $user = User::factory()->create($attrs);
    $site = new Site(['subdomain' => 'bks'.substr((string) $user->id, 0, 8), 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
}

it('proposes the first Booksy account as a pre-ticked suggestion and the second as an unticked one, never a card', function () {
    // Live shape (squeakprobarber, 2026-09-05): the venue's own Booksy
    // listing (100214) and the barber's personal Booksy profile (47636) in
    // the same bio. Default RouteContext = autoConnectBooking:false, the
    // self-serve shape.
    $user = bookingSlotUser(['account_type' => 'business']);
    $ctx = new RouteContext;

    $first = app(LinkRouter::class)->route($user, 'https://booksy.com/en-us/100214_the-venue', $ctx);
    $second = app(LinkRouter::class)->route($user, 'https://booksy.com/en-us/47636_the-barber', $ctx);

    // Neither auto-connects — both are Choose, filed as suggestions.
    expect($first->handled)->toBeTrue()
        ->and($first->outcome)->toBe('custom')
        ->and($second->handled)->toBeTrue()
        ->and($second->outcome)->toBe('custom');
    expect(IntegrationConnection::where(['user_id' => $user->id, 'routing_class' => 'booking'])->count())->toBe(0);

    $intents = DB::table('routing.source_intents')
        ->where('user_id', $user->id)->where('surface_key', 'booksy.book')
        ->orderBy('first_seen_at')->get();
    expect($intents)->toHaveCount(2)
        ->and($intents[0]->state)->toBe('proposed')
        ->and($intents[0]->band)->toBe('auto')
        ->and($intents[1]->state)->toBe('proposed')
        ->and($intents[1]->band)->toBe('suggest');

    // Neither link fell through to a plain custom-link card.
    expect(app(LinkPoolReader::class)->cards($user->refresh()))->toHaveCount(0);
});

it('still connects a second DIFFERENT booking brand as a cross-brand suggestion, unaffected by the platform-slot change', function () {
    $user = bookingSlotUser(['account_type' => 'business']);
    $ctx = new RouteContext;

    app(LinkRouter::class)->route($user, 'https://booksy.com/en-us/100214_the-venue', $ctx);
    $fresha = app(LinkRouter::class)->route($user, 'https://www.fresha.com/a/the-venue', $ctx);

    expect($fresha->handled)->toBeTrue()
        ->and($fresha->outcome)->toBe('custom');
    expect(IntegrationConnection::where(['user_id' => $user->id, 'routing_class' => 'booking'])->count())->toBe(0);
    expect(DB::table('routing.source_intents')->where('user_id', $user->id)->where('routing_class', 'booking')->count())->toBe(2);
});

it('keeps the immediate connect for a staff/ManyChat build (autoConnectBooking), including the second-account slot fix', function () {
    // The one carve-out: nobody is at a setup dialog to accept a suggestion
    // for an outreach build, so this shape keeps seedBooking()'s legacy
    // write() — unchanged by the 2026-09-05 policy change, only the
    // seenPlatforms short-circuit fix (slot 1, above) applies to it.
    $user = bookingSlotUser(['account_type' => 'business']);
    $ctx = new RouteContext(autoConnectBooking: true);

    $first = app(LinkRouter::class)->route($user, 'https://booksy.com/en-us/100214_the-venue', $ctx);
    $second = app(LinkRouter::class)->route($user, 'https://booksy.com/en-us/47636_the-barber', $ctx);

    expect($first->outcome)->toBe('seeded')
        ->and($second->outcome)->toBe('conflict')
        ->and($second->handled)->toBeTrue();

    $booking = IntegrationConnection::where(['user_id' => $user->id, 'routing_class' => 'booking'])->get();
    expect($booking)->toHaveCount(1)
        ->and($booking->first()->payload['url'])->toBe('https://booksy.com/en-us/100214_the-venue');
    expect(app(LinkPoolReader::class)->cards($user->refresh()))->toHaveCount(0);
});
