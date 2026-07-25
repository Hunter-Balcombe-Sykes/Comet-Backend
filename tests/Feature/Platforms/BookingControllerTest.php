<?php

use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function bookingUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

it('detect custom URL returns 202 + minimal card and enqueues EnrichLinkCardJob for booking', function () {
    Queue::fake();
    Http::fake();

    $user = bookingUser('bookuser');

    $res = actingAsUser($user)->postJson('/api/platforms/booking/detect', [
        'url' => 'https://www.example.com/book',
    ]);

    $res->assertStatus(202)
        ->assertJsonPath('provider', 'custom')
        ->assertJsonPath('status', 'pending');

    Http::assertNothingSent();
    Queue::assertPushed(EnrichLinkCardJob::class, fn ($j) => $j->platform === 'booking');
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
        'url' => 'https://www.example.com/book',
    ])->assertStatus(202);

    $res = actingAsUser($user)->getJson('/api/platforms/booking/detect/status');
    $res->assertOk()->assertJsonPath('status', 'pending');
});
