<?php

/** @phpstan-ignore-all */

// OV-H: the first-enquiry achievement fires exactly on the user's first enquiry
// (and not on later ones). Wired in DispatchEnquiryNotificationsJob::handle().

use App\Jobs\Notifications\DispatchEnquiryNotificationsJob;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Services\Notifications\Dispatchers\AchievementNotifier;
use App\Services\Notifications\EnquiryNotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq
         ON notifications (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL'
    );
});

it('fires the first-enquiry achievement on the user\'s first enquiry', function () {
    $user = makeInboxUser();
    $siteId = (string) Str::uuid();
    $enquiryId = seedInboxEnquiry($user->id, $siteId);
    $blockId = seedContactBlock($siteId, $user->id);

    $enquiry = Enquiry::find($enquiryId);
    $block = Block::find($blockId);

    // Isolate the achievement wiring from the enquiry dispatcher's adapter chain.
    $dispatcher = Mockery::mock(EnquiryNotificationDispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once();

    (new DispatchEnquiryNotificationsJob((string) $enquiry->id))
        ->handle($dispatcher, app(AchievementNotifier::class));

    $achievement = DB::table('notifications.notifications')
        ->where('user_id', $user->id)
        ->where('category', 'achievement')
        ->first();

    expect($achievement)->not->toBeNull();
    expect($achievement->type)->toBe('Success');
    expect((int) $achievement->critical)->toBe(0);
});

it('does NOT fire the achievement when it is not the user\'s first enquiry', function () {
    $user = makeInboxUser();
    $siteId = (string) Str::uuid();
    // Two enquiries already exist for this user before the job runs.
    seedInboxEnquiry($user->id, $siteId);
    $secondId = seedInboxEnquiry($user->id, $siteId);
    $blockId = seedContactBlock($siteId, $user->id);

    $block = Block::find($blockId);

    $dispatcher = Mockery::mock(EnquiryNotificationDispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once();

    (new DispatchEnquiryNotificationsJob($secondId))
        ->handle($dispatcher, app(AchievementNotifier::class));

    expect(
        DB::table('notifications.notifications')->where('category', 'achievement')->count()
    )->toBe(0);
});
