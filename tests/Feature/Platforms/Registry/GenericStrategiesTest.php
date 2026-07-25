<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\Strategies\Refresh\NoRefresh;
use App\Services\Platforms\Strategies\Refresh\ScheduledRefresh;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('NoRefresh is not refreshable and returns the row untouched', function () {
    $conn = new IntegrationConnection(['platform' => 'linkedin', 'payload' => ['url' => 'u']]);
    $strategy = new NoRefresh;
    expect($strategy->isRefreshable())->toBeFalse();
    expect($strategy->run($conn))->toBe($conn);
});

it('ScheduledRefresh calls fetch and persists the new payload', function () {
    $user = User::create([
        'handle' => 'sch', 'handle_lc' => 'sch', 'display_name' => 'Sch',
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'sch@example.com',
    ]);
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'twitch', 'resource_id' => 'twitch',
        'payload' => ['login' => 'a', 'name' => 'old'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $fetch = new class implements FetchStrategy
    {
        public function fetch(IntegrationConnection $connection): array
        {
            return [...$connection->payload, 'name' => 'fresh'];
        }
    };

    (new ScheduledRefresh($fetch))->run($conn->fresh());

    expect($conn->fresh()->payload['name'])->toBe('fresh');
    expect($conn->fresh()->last_refresh_status)->toBe('ok');
});
