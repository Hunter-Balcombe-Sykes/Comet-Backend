<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function genericLinkUser(string $handle): User
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

it('x connect stores the canonical link and echoes {username,url}', function () {
    actingAsUser(genericLinkUser('gx1'))
        ->postJson('/api/platforms/x/connect', ['username' => '@janed'])
        ->assertOk()
        ->assertExactJson(['username' => 'janed', 'url' => 'https://x.com/janed']);
});

it('x connect returns the exact 422 message on unparseable input', function () {
    actingAsUser(genericLinkUser('gx2'))
        ->postJson('/api/platforms/x/connect', ['username' => 'https://x.com/home'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Enter your X handle or profile URL (x.com/yourname).');
});

it('x selection round-trips the stored payload and forget clears it', function () {
    $user = genericLinkUser('gx3');

    actingAsUser($user)->postJson('/api/platforms/x/connect', ['username' => 'janed'])->assertOk();

    actingAsUser($user)->getJson('/api/platforms/x/selection')
        ->assertOk()
        ->assertExactJson(['selection' => ['username' => 'janed', 'url' => 'https://x.com/janed']]);

    actingAsUser($user)->deleteJson('/api/platforms/x')
        ->assertOk()
        ->assertExactJson(['selection' => null]);

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'x')->whereNull('deleted_at')->exists())->toBeFalse();
});

it('linkedin connect stores an /in/ profile and echoes {username,url}', function () {
    actingAsUser(genericLinkUser('gli1'))
        ->postJson('/api/platforms/linkedin/connect', ['username' => 'https://www.linkedin.com/in/jane-doe/'])
        ->assertOk()
        ->assertExactJson(['username' => 'jane-doe', 'url' => 'https://www.linkedin.com/in/jane-doe/']);
});

it('linkedin connect returns the exact 422 message on a non-profile url', function () {
    actingAsUser(genericLinkUser('gli2'))
        ->postJson('/api/platforms/linkedin/connect', ['username' => 'https://www.linkedin.com/feed/'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Enter your LinkedIn profile URL (linkedin.com/in/yourname).');
});

it('threads connect stores the canonical link and echoes {username,url}', function () {
    actingAsUser(genericLinkUser('gth1'))
        ->postJson('/api/platforms/threads/connect', ['username' => '@janed'])
        ->assertOk()
        ->assertExactJson(['username' => 'janed', 'url' => 'https://www.threads.net/@janed']);
});

it('threads connect returns the exact 422 message on invalid input', function () {
    actingAsUser(genericLinkUser('gth2'))
        ->postJson('/api/platforms/threads/connect', ['username' => 'has spaces!'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Enter your Threads handle or profile URL (threads.net/@yourname).');
});

it('reddit connect stores a u/ profile and echoes {username,url}', function () {
    actingAsUser(genericLinkUser('grd1'))
        ->postJson('/api/platforms/reddit/connect', ['username' => 'u/janed'])
        ->assertOk()
        ->assertExactJson(['username' => 'janed', 'url' => 'https://www.reddit.com/user/janed/']);
});

it('reddit connect returns the exact 422 message on a non-profile url', function () {
    actingAsUser(genericLinkUser('grd2'))
        ->postJson('/api/platforms/reddit/connect', ['username' => 'https://www.reddit.com/about'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Enter your Reddit username or community (u/yourname or r/yourcommunity).');
});

it('tiktok connect normalizes @handle and echoes {username,url}', function () {
    actingAsUser(genericLinkUser('gtt1'))
        ->postJson('/api/platforms/tiktok/connect', ['username' => '@dancer'])
        ->assertOk()
        ->assertExactJson(['username' => 'dancer', 'url' => 'https://www.tiktok.com/@dancer']);
});

it('tiktok connect returns the exact 422 message when no handle survives', function () {
    actingAsUser(genericLinkUser('gtt2'))
        ->postJson('/api/platforms/tiktok/connect', ['username' => '@'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Enter your TikTok username or profile URL.');
});

it('facebook connect stores a vanity handle and echoes {username,url}', function () {
    actingAsUser(genericLinkUser('gfb1'))
        ->postJson('/api/platforms/facebook/connect', ['username' => 'jane.doe'])
        ->assertOk()
        ->assertExactJson(['username' => 'jane.doe', 'url' => 'https://www.facebook.com/jane.doe']);
});

it('facebook connect extracts the Page name from a legacy /pages/ link (G4-4)', function () {
    actingAsUser(genericLinkUser('gfb2'))
        ->postJson('/api/platforms/facebook/connect', ['username' => 'https://www.facebook.com/pages/Some-Cafe/123456789'])
        ->assertOk()
        ->assertExactJson(['username' => 'Some-Cafe', 'url' => 'https://www.facebook.com/pages/Some-Cafe/123456789']);
});

it('facebook connect returns the exact 422 message on a handleless link', function () {
    actingAsUser(genericLinkUser('gfb3'))
        ->postJson('/api/platforms/facebook/connect', ['username' => 'https://www.facebook.com/'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Enter your Facebook username or profile URL.');
});

it('forget clears the link connection and selection returns null (behaviour-equivalent under the generalized read path)', function () {
    $user = genericLinkUser('gldforget');

    actingAsUser($user)->postJson('/api/platforms/tiktok/connect', ['username' => '@dancer'])->assertOk();
    actingAsUser($user)->getJson('/api/platforms/tiktok/selection')->assertOk()
        ->assertJsonPath('selection.username', 'dancer');

    actingAsUser($user)->deleteJson('/api/platforms/tiktok')->assertOk()
        ->assertExactJson(['selection' => null]);

    actingAsUser($user)->getJson('/api/platforms/tiktok/selection')->assertOk()
        ->assertExactJson(['selection' => null]);
});
