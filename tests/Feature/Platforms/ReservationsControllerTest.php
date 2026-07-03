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

function reservationsUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'account_type' => 'individual',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

it('detect custom URL returns 202 + minimal card and enqueues EnrichLinkCardJob for reservations', function () {
    Queue::fake();
    Http::fake();

    $user = reservationsUser('resuser');

    $res = actingAsUser($user)->postJson('/api/integrations/reservations/detect', [
        'url' => 'https://www.example.com/reserve',
    ]);

    $res->assertStatus(202)
        ->assertJsonPath('provider', 'custom')
        ->assertJsonPath('status', 'pending');

    Http::assertNothingSent();
    Queue::assertPushed(EnrichLinkCardJob::class, fn ($j) => $j->platform === 'reservations');
});

it('detect opentable URL stays synchronous 200 with no job dispatched', function () {
    Queue::fake();

    $user = reservationsUser('otuser');

    $res = actingAsUser($user)->postJson('/api/integrations/reservations/detect', [
        'url' => 'https://www.opentable.com.au/restaurant/profile/266537',
    ]);

    $res->assertOk()
        ->assertJsonPath('provider', 'opentable');

    Queue::assertNotPushed(EnrichLinkCardJob::class);
});

it('detect/status returns pending while the enrichment job is running', function () {
    Queue::fake();
    Http::fake();

    $user = reservationsUser('respolluser');

    actingAsUser($user)->postJson('/api/integrations/reservations/detect', [
        'url' => 'https://www.example.com/reserve',
    ])->assertStatus(202);

    $res = actingAsUser($user)->getJson('/api/integrations/reservations/detect/status');
    $res->assertOk()->assertJsonPath('status', 'pending');
});
