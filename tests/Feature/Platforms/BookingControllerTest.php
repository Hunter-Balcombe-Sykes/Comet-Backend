<?php

use App\Jobs\Content\EnrichPoolLinkJob;
use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\LinkPoolReader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Convergence Phase 6: a booking link with no brand home goes to the links
    // POOL (owner ruling 2A), so these cases need the content lane too.
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

function bookingUser(string $handle): User
{
    $user = User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);

    // A pool item needs a section, which hangs off the SITE — the unbranded
    // fallback silently writes nothing for a siteless user.
    $site = new Site(['subdomain' => $handle, 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
}

it('detect a recognised booking brand returns 202 + minimal card on that BRAND surface', function () {
    Queue::fake();
    Http::fake();

    $user = bookingUser('bookuser');

    // Convergence Phase 6: Treatwell has its own catalog surface, so the card is
    // a treatwell.book row — not the retired shared `partna.booking_link` key.
    // The WIRE is unchanged: still provider 'custom', still next 'custom-saved'.
    $res = actingAsUser($user)->postJson('/api/platforms/booking/detect', [
        'url' => 'https://www.treatwell.co.uk/place/some-salon/',
    ]);

    $res->assertStatus(202)
        ->assertJsonPath('provider', 'custom')
        ->assertJsonPath('next', 'custom-saved')
        ->assertJsonPath('status', 'pending');

    $row = IntegrationConnection::where('user_id', $user->id)->where('routing_class', 'booking')->firstOrFail();
    expect($row->surface_key)->toBe('treatwell.book');
    expect(IntegrationConnection::where('user_id', $user->id)
        ->where('surface_key', 'partna.booking_link')->exists())->toBeFalse();

    Http::assertNothingSent();
    // Family key on the job (the lock), brand surface for the row lookup.
    Queue::assertPushed(EnrichLinkCardJob::class, fn ($j) => $j->platform === 'booking' && $j->surfaceKey === 'treatwell.book');
});

it('detect a booking link NO brand claims still gives them a Book button', function () {
    Queue::fake();
    Http::fake();

    $user = bookingUser('bookdirect');

    // Owner ruling 2026-08-16. A booking page nothing recognises — nearly always
    // the business's own site — lands on `direct.book` and renders as a normal
    // booking card. Before Phase 6 it was a `partna.booking_link` row and it
    // worked; retiring that key without a home would have emptied the card.
    actingAsUser($user)->postJson('/api/platforms/booking/detect', [
        'url' => 'https://www.example.com/book-appointment',
    ])->assertStatus(202)
        ->assertJsonPath('provider', 'custom')
        ->assertJsonPath('next', 'custom-saved');

    $row = IntegrationConnection::where('user_id', $user->id)->firstOrFail();
    expect($row->surface_key)->toBe('direct.book');

    actingAsUser($user)->getJson('/api/platforms/booking/status')
        ->assertOk()->assertJsonPath('connected', true);

    Queue::assertPushed(EnrichLinkCardJob::class);
});

it('detect a link that is demonstrably NOT a booking page sends it to the pool', function () {
    Queue::fake();
    Http::fake();

    $user = bookingUser('bookpool');

    // The one case that still pools (owner ruling 2A): the URL classified, and
    // it classified as something else entirely. Calling an Instagram profile a
    // booking page would be us being wrong on purpose — `direct.book` is for
    // links nothing recognises, not for links we recognise as not-booking.
    actingAsUser($user)->postJson('/api/platforms/booking/detect', [
        'url' => 'https://www.instagram.com/somesalon',
    ])->assertStatus(202)
        ->assertJsonPath('next', 'link-saved')
        ->assertJsonPath('routedTo.pool', 'custom_links')
        ->assertJsonPath('selection', null);

    expect(IntegrationConnection::where('user_id', $user->id)->count())->toBe(0);
    expect(app(LinkPoolReader::class)->cards($user))->toHaveCount(1);

    actingAsUser($user)->getJson('/api/platforms/booking/status')
        ->assertOk()->assertJsonPath('connected', false);

    Queue::assertPushed(EnrichPoolLinkJob::class);
    Queue::assertNotPushed(EnrichLinkCardJob::class);
});

it('detect fresha URL stays synchronous 200 with no job dispatched', function () {
    Queue::fake();

    $user = bookingUser('freshuser');

    $res = actingAsUser($user)->postJson('/api/platforms/booking/detect', [
        'url' => 'https://www.fresha.com/a/some-salon',
    ]);

    $res->assertOk()
        ->assertJsonPath('provider', 'fresha');

    Queue::assertNotPushed(EnrichLinkCardJob::class);
});

it('detect square URL stays synchronous 200 with no job dispatched', function () {
    Queue::fake();

    $user = bookingUser('squareuser');

    $res = actingAsUser($user)->postJson('/api/platforms/booking/detect', [
        'url' => 'https://squareup.com/appointments/book/some-store',
    ]);

    $res->assertOk()
        ->assertJsonPath('provider', 'square');

    Queue::assertNotPushed(EnrichLinkCardJob::class);
});

it('detect/status returns pending while the enrichment job is running', function () {
    Queue::fake();
    Http::fake();

    $user = bookingUser('polluser');

    actingAsUser($user)->postJson('/api/platforms/booking/detect', [
        'url' => 'https://www.treatwell.co.uk/place/some-salon/',
    ])->assertStatus(202);

    $res = actingAsUser($user)->getJson('/api/platforms/booking/detect/status');
    $res->assertOk()->assertJsonPath('status', 'pending');
});
