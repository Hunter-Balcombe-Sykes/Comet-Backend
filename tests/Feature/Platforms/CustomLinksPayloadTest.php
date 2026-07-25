<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function customLinksUser(string $h): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h), 'first_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('lists a stored custom link with its full card shape', function () {
    $user = customLinksUser('clink');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'custom', 'resource_id' => 'link-abc',
        'resource_kind' => 'link',
        'payload' => ['kind' => 'link', 'url' => 'https://acme.test', 'name' => 'Acme',
            'description' => 'Best', 'favicon' => 'https://f.ico', 'logo' => 'https://l.png'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->getJson('/api/platforms/custom/links')
        ->assertOk()
        ->assertJsonPath('links.0.id', 'link-abc')
        ->assertJsonPath('links.0.url', 'https://acme.test')
        ->assertJsonPath('links.0.name', 'Acme')
        ->assertJsonPath('links.0.description', 'Best')
        ->assertJsonPath('links.0.favicon', 'https://f.ico')
        ->assertJsonPath('links.0.logo', 'https://l.png');
});
