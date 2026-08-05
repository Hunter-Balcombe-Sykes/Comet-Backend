<?php

use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
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
        'first_name' => ucfirst($h),
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

it('reorders links and lists them in the new order', function () {
    $user = customLinksCtrlUser('clinkorder');
    foreach ([['link-a', 0], ['link-b', 1], ['link-c', 2]] as [$rid, $pos]) {
        IntegrationConnection::create([
            'user_id' => $user->id, 'platform' => 'custom', 'resource_id' => $rid,
            'resource_kind' => 'link',
            'payload' => ['kind' => 'link', 'url' => "https://{$rid}.test", 'name' => $rid],
            'is_active' => true, 'sort_order' => $pos, 'last_refresh_status' => 'ok',
        ]);
    }

    actingAsUser($user)->putJson('/api/platforms/custom/links/order', ['ids' => ['link-c', 'link-a']])
        ->assertOk()
        ->assertJsonPath('links.0.id', 'link-c')
        ->assertJsonPath('links.1.id', 'link-a')
        // Omitted rows keep their relative order after the listed ones.
        ->assertJsonPath('links.2.id', 'link-b');

    actingAsUser($user)->getJson('/api/platforms/custom/links')
        ->assertOk()
        ->assertJsonPath('links.0.id', 'link-c')
        ->assertJsonPath('links.1.id', 'link-a')
        ->assertJsonPath('links.2.id', 'link-b');
});

// #TEST-1: a pure sort_order shuffle fires neither IntegrationConnectionObserver
// (gates on payload/display_settings/is_active) nor any site touch by itself
// — before the fix, site.updated_at stayed pinned on the old order for the
// full cache TTL even though the reorder itself succeeded. Freezing time
// (rather than relying on wall-clock granularity between two calls a few ms
// apart) is the house pattern for this class of assertion — see
// RefreshAsyncTest.php's $this->travel(...)->minutes().
it('touches the site on reorder so the cached public payload does not stay pinned on the old order', function () {
    $user = customLinksCtrlUser('clinktouch');
    $site = new Site(['subdomain' => 'clinktouch', 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();
    foreach ([['link-a', 0], ['link-b', 1]] as [$rid, $pos]) {
        IntegrationConnection::create([
            'user_id' => $user->id, 'platform' => 'custom', 'resource_id' => $rid,
            'resource_kind' => 'link',
            'payload' => ['kind' => 'link', 'url' => "https://{$rid}.test", 'name' => $rid],
            'is_active' => true, 'sort_order' => $pos, 'last_refresh_status' => 'ok',
        ]);
    }

    $before = $site->fresh()->updated_at;

    $this->travel(5)->minutes();

    actingAsUser($user)->putJson('/api/platforms/custom/links/order', ['ids' => ['link-b', 'link-a']])
        ->assertOk();

    $after = Site::query()->find($site->id)->updated_at;

    expect($after->gt($before))->toBeTrue();
});

it('rejects reorder ids that are not the users links', function () {
    $user = customLinksCtrlUser('clinkbad');
    actingAsUser($user)->putJson('/api/platforms/custom/links/order', ['ids' => ['link-nope']])
        ->assertNotFound();
});
