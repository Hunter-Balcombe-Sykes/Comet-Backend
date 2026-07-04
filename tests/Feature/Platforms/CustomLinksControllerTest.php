<?php

use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function customLinksCtrlUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('addLink returns 202 with a minimal card and no outbound HTTP', function () {
    Queue::fake();
    Http::fake();

    $user = customLinksCtrlUser('asynclink1');

    $res = actingAsUser($user)->postJson('/api/platforms/custom/links', ['url' => 'https://www.example.com/x']);

    $res->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('link.name', 'example.com');
    Http::assertNothingSent();
    Queue::assertPushed(EnrichLinkCardJob::class,
        fn ($j) => $j->platform === 'custom' && str_starts_with($j->resourceId, 'link-'));
});

it('status endpoint reports ready once the row is ok', function () {
    $user = customLinksCtrlUser('asynclink2');

    $rid = 'link-'.substr(sha1(strtolower('https://example.com')), 0, 16);

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'custom',
        'resource_id' => $rid,
        'payload' => ['kind' => 'link', 'url' => 'https://example.com', 'name' => 'example.com',
            'description' => null, 'favicon' => null, 'logo' => null],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)
        ->getJson("/api/platforms/custom/links/{$rid}/status")
        ->assertOk()
        ->assertJsonPath('status', 'ready');
});
