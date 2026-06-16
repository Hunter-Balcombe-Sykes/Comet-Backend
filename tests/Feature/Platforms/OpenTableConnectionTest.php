<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function otUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'individual',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('connects an OpenTable profile link and builds the widget embed', function () {
    $user = otUser('ot1');

    $res = actingAsUser($user)->postJson('/api/platforms/opentable/connect', [
        'url' => 'https://www.opentable.com.au/restaurant/profile/266537?ref=11025',
    ])
        ->assertOk()
        ->assertJsonPath('rid', '266537')
        ->assertJsonPath('url', 'https://www.opentable.com.au/restaurant/profile/266537?ref=11025');

    $embed = $res->json('embedUrl');
    expect($embed)->toContain('https://www.opentable.com.au/widget/reservation/canvas?');
    expect($embed)->toContain('rid=266537');
    expect($embed)->toContain('domain=comau');   // com.au → comau
    expect($embed)->toContain('iframe=true');

    // Stored under the single google-business-style row for the platform.
    $payload = IntegrationConnection::query()
        ->where('user_id', $user->id)->where('platform', 'opentable')
        ->firstOrFail()->payload;
    expect($payload['rid'])->toBe('266537');
    expect($payload['embedUrl'])->toContain('rid=266537');
});

it('rejects an OpenTable slug link with no restaurant id', function () {
    $user = otUser('ot2');

    actingAsUser($user)->postJson('/api/platforms/opentable/connect', [
        'url' => 'https://www.opentable.com.au/r/ollies-pizza-parlour-brunswick',
    ])
        ->assertStatus(422);

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'opentable')->exists())->toBeFalse();
});

it('rejects a non-OpenTable url', function () {
    $user = otUser('ot3');

    actingAsUser($user)->postJson('/api/platforms/opentable/connect', [
        'url' => 'https://www.google.com/maps/reserve/v/dine/c/abc',
    ])
        ->assertStatus(422);
});

it('reads the OpenTable selection back', function () {
    $user = otUser('ot4');

    actingAsUser($user)->postJson('/api/platforms/opentable/connect', [
        'url' => 'https://www.opentable.com.au/restaurant/profile/266537',
    ])->assertOk();

    actingAsUser($user)->getJson('/api/platforms/opentable/selection')
        ->assertOk()
        ->assertJsonPath('selection.rid', '266537')
        ->assertJsonPath('selection.embedUrl', fn ($url) => str_contains((string) $url, 'rid=266537'));
});

it('suggests the OpenTable link harvested from Google Business', function () {
    $user = otUser('ot6');

    // Google Business connected, with OpenTable as the reservation provider.
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'payload' => [
            'name' => "Ollie's Pizza Parlour",
            'reservation' => [
                'url' => 'https://www.opentable.com.au/restaurant/profile/266537?ref=11025',
                'provider' => 'OpenTable',
                'links' => [['name' => 'opentable.com.au', 'url' => 'https://www.opentable.com.au/restaurant/profile/266537?ref=11025']],
            ],
        ],
    ]);

    actingAsUser($user)->getJson('/api/platforms/opentable/suggestion')
        ->assertOk()
        ->assertJsonPath('suggestion.url', 'https://www.opentable.com.au/restaurant/profile/266537?ref=11025')
        ->assertJsonPath('suggestion.name', "Ollie's Pizza Parlour");
});

it('returns no OpenTable suggestion without a Google Business reservation', function () {
    $user = otUser('ot7');

    // No Google Business at all.
    actingAsUser($user)->getJson('/api/platforms/opentable/suggestion')
        ->assertOk()
        ->assertJsonPath('suggestion', null);

    // Google Business connected but a non-OpenTable reservation provider.
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'payload' => [
            'name' => 'Some Cafe',
            'reservation' => ['url' => 'https://resy.com/cities/syd/some-cafe', 'provider' => 'Resy'],
        ],
    ]);

    actingAsUser($user)->getJson('/api/platforms/opentable/suggestion')
        ->assertOk()
        ->assertJsonPath('suggestion', null);
});

it('exposes only the allowlisted OpenTable fields publicly', function () {
    $user = otUser('ot5');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'opentable',
        'resource_id' => 'opentable',
        'payload' => [
            'url' => 'https://www.opentable.com.au/restaurant/profile/266537',
            'rid' => '266537',
            'name' => 'Ollies Pizza Parlour Brunswick',
            'embedUrl' => 'https://www.opentable.com.au/widget/reservation/canvas?rid=266537&domain=comau&iframe=true',
            '_secret' => 'should-not-leak',
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $payload = $this->getJson('/api/public/profiles/ot5/integrations')
        ->assertOk()
        ->json('data.platforms.opentable.0.payload');

    expect($payload)->toHaveKeys(['url', 'rid', 'name', 'embedUrl']);
    expect($payload)->not->toHaveKey('_secret');
});
