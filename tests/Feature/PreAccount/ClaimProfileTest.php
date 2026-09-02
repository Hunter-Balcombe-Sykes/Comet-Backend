<?php

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\PreAccount\ClaimSiteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// A.8 (decision 8): the sign-up flow's answers — handle, names, display
// name, sector — ride the claim and land inside the claim transaction.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupEmailSubscriptionsTable();
    setupNotificationsTable();
    setupSubdomainAliasesTable();
    setupHandleAliasesTable();
    Queue::fake();
});

it('assigns the chosen handle to both the site and the user, no alias minted', function () {
    [$user, $site, $build] = makeReadyBuild('generated-ig-name');

    app(ClaimSiteService::class)->claim('auth-h1', 'jane@example.com', 'generated-ig-name', profile: [
        'handle' => 'JaneDoeHair',
    ]);

    $freshSite = $site->fresh();
    $freshUser = $user->fresh();
    expect($freshSite->subdomain)->toBe('janedoehair')
        ->and($freshUser->handle)->toBe('janedoehair')
        ->and($freshUser->handle_lc)->toBe('janedoehair')
        ->and(DB::table('site.site_subdomain_aliases')->where('site_id', $site->id)->exists())->toBeFalse();
});

it('answers HANDLE_TAKEN when the chosen handle belongs to someone else', function () {
    $other = User::factory()->create();
    Site::factory()->create(['user_id' => $other->id, 'subdomain' => 'takenname']);
    [$user, $site, $build] = makeReadyBuild('mine-generated');

    expect(fn () => app(ClaimSiteService::class)->claim('auth-h2', 'jane2@example.com', 'mine-generated', profile: [
        'handle' => 'takenname',
    ]))->toThrow(RuntimeException::class, 'HANDLE_TAKEN');

    // The whole claim rolled back — still unclaimed, still re-claimable.
    expect($user->fresh()->auth_user_id)->toBeNull()
        ->and($site->fresh()->subdomain)->toBe('mine-generated');
});

it('writes names and display name from the flow', function () {
    [$user, $site] = makeReadyBuild('names-build');

    app(ClaimSiteService::class)->claim('auth-h3', 'jane3@example.com', 'names-build', profile: [
        'first_name' => 'Jane', 'last_name' => 'Doe', 'display_name' => 'Jane Doe Hair',
    ]);

    $fresh = $user->fresh();
    expect($fresh->first_name)->toBe('Jane')
        ->and($fresh->last_name)->toBe('Doe')
        ->and($fresh->display_name)->toBe('Jane Doe Hair');
});

it('stamps sector manual only when the answer differs from the stored value', function () {
    [$user, $site] = makeReadyBuild('sector-same');
    $user->forceFill(['sector' => 'barber', 'sector_source' => 'google-business'])->save();

    app(ClaimSiteService::class)->claim('auth-h4', 'jane4@example.com', 'sector-same', profile: [
        'sector' => 'barber',
    ]);

    // Confirming the scraped guess must not upgrade its provenance.
    expect($user->fresh()->sector_source)->toBe('google-business');

    [$user2, $site2] = makeReadyBuild('sector-diff');
    $user2->forceFill(['sector' => 'barber', 'sector_source' => 'google-business'])->save();

    app(ClaimSiteService::class)->claim('auth-h5', 'jane5@example.com', 'sector-diff', profile: [
        'sector' => 'hairdressing',
    ]);

    $fresh2 = $user2->fresh();
    expect($fresh2->sector)->toBe('hairdressing')
        ->and($fresh2->sector_source)->toBe('manual');
});

it('claims exactly as before when no profile is sent (ManyChat claim page)', function () {
    [$user, $site] = makeReadyBuild('legacy-claim');

    $result = app(ClaimSiteService::class)->claim('auth-h6', 'jane6@example.com', 'legacy-claim');

    expect($result['professional']->status)->toBe('active')
        ->and($site->fresh()->subdomain)->toBe('legacy-claim');
});
