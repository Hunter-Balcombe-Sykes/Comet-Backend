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
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
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

// CA-SM review fix (E-5 follow-up): scopeDueForRefresh()'s pending exclusion
// hides a row stranded 'pending' by a dead worker from dueForRefresh()-based
// counts — before that fix, a never-refreshed row (including a stranded
// pending one) fell into the "never refreshed" bucket and WAS counted here.
// Folded back into this SAME alarm (not a new one) so a stranded row still
// trips the existing threshold instead of silently disappearing from it.
it('reports a backlog exception when stranded pending rows alone exceed the threshold', function () {
    Exceptions::fake();
    $user = backlogUser();

    foreach (['youtube', 'youtube2'] as $rid) {
        $stranded = IntegrationConnection::create([
            'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => $rid,
            'payload' => ['handle' => 'c'], 'last_refresh_status' => 'pending',
        ]);
        IntegrationConnection::query()->where('id', $stranded->id)->update(['updated_at' => now()->subMinutes(10)]);
    }

    $this->artisan('integrations:refresh-backlog')->assertSuccessful();

    Exceptions::assertReported(PlatformRefreshBacklogException::class);
});

it('does not count a fresh in-flight pending row toward the backlog', function () {
    Exceptions::fake();
    $user = backlogUser();

    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'youtube',
        'payload' => ['handle' => 'c'], 'last_refresh_status' => 'pending',
    ]);

    $this->artisan('integrations:refresh-backlog')->assertSuccessful();

    Exceptions::assertNothingReported();
});
