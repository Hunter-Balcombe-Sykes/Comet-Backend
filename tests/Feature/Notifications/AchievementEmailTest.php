<?php

use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Services\Notifications\Dispatchers\AchievementNotifier;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupNotificationsTable();
    setupNotificationEmailPoliciesTable();
    setupNotificationEmailPreferencesTable();
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq '.
        'ON notifications (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL'
    );
    config(['partna.notifications.email_enabled' => true]);
});

it('dispatches the transactional email job on a genuinely new achievement', function () {
    Bus::fake();
    $pro = createTenant('achiever');

    app(AchievementNotifier::class)->firstEnquiry($pro->id);

    Bus::assertDispatched(SendTransactionalNotificationEmailJob::class, fn ($job) => $job->category === 'achievement' && $job->userId === $pro->id);
});

it('never dispatches twice for the same dedupe key', function () {
    Bus::fake();
    $pro = createTenant('achiever-2');

    app(AchievementNotifier::class)->firstEnquiry($pro->id);
    app(AchievementNotifier::class)->firstEnquiry($pro->id);

    Bus::assertDispatchedTimes(SendTransactionalNotificationEmailJob::class, 1);
});

it('does not dispatch when the notifications email feature flag is off', function () {
    Bus::fake();
    config(['partna.notifications.email_enabled' => false]);
    $pro = createTenant('achiever-3');

    app(AchievementNotifier::class)->firstEnquiry($pro->id);

    Bus::assertNotDispatched(SendTransactionalNotificationEmailJob::class);
});
