<?php

use App\Exceptions\Platforms\PlatformRefreshBacklogException;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Small, deterministic thresholds so we don't have to seed 500 rows.
    config()->set('partna.refresh.backlog.grace_multiplier', 1);
    config()->set('partna.refresh.backlog.alert_threshold', 1);
});

function backlogUser(): User
{
    return User::create([
        'handle' => 'bk', 'handle_lc' => 'bk', 'display_name' => 'BK',
        'account_type' => 'individual', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'bk@example.com',
    ]);
}

it('reports a backlog exception when overdue count exceeds the threshold', function () {
    Exceptions::fake();
    $user = backlogUser();

    foreach (['youtube', 'youtube2'] as $i => $rid) {
        IntegrationConnection::create([
            'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => $rid,
            'payload' => ['handle' => 'c'], 'last_refreshed_at' => now()->subYear(),
        ]);
    }

    $this->artisan('integrations:refresh-backlog')->assertSuccessful();

    Exceptions::assertReported(PlatformRefreshBacklogException::class);
});

it('does not report when the backlog is within threshold', function () {
    Exceptions::fake();
    backlogUser(); // no overdue connections

    $this->artisan('integrations:refresh-backlog')->assertSuccessful();

    Exceptions::assertNothingReported();
});
