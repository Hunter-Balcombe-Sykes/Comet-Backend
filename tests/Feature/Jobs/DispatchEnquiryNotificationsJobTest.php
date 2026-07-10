<?php

use App\Jobs\Notifications\DispatchEnquiryNotificationsJob;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Services\Notifications\Dispatchers\AchievementNotifier;
use App\Services\Notifications\EnquiryNotificationDispatcher;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
});

it('resolves enquiry + active contact block then invokes dispatcher', function () {
    $user = makeInboxUser();
    $siteId = (string) Str::uuid();
    $enquiryId = seedInboxEnquiry($user->id, $siteId);
    $blockId = seedContactBlock($siteId, $user->id);  // is_active=1, block_type=contact, block_group=sections

    $enquiry = Enquiry::find($enquiryId);
    $block = Block::find($blockId);

    $dispatcher = Mockery::mock(EnquiryNotificationDispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->withArgs(fn ($e, $b) => (string) $e->id === (string) $enquiry->id && (string) $b->id === (string) $block->id)
        ->once();
    app()->instance(EnquiryNotificationDispatcher::class, $dispatcher);

    (new DispatchEnquiryNotificationsJob((string) $enquiry->id))->handle($dispatcher, app(AchievementNotifier::class));
});

it('silently no-ops when the enquiry has been deleted before the job runs', function () {
    $user = makeInboxUser();
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid());
    Enquiry::find($enquiryId)->delete();

    $dispatcher = Mockery::mock(EnquiryNotificationDispatcher::class);
    $dispatcher->shouldNotReceive('dispatch');

    (new DispatchEnquiryNotificationsJob($enquiryId))->handle($dispatcher, app(AchievementNotifier::class));
});

it('no-ops when no active contact block exists', function () {
    $user = makeInboxUser();
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid());
    // No contact block seeded.

    $dispatcher = Mockery::mock(EnquiryNotificationDispatcher::class);
    $dispatcher->shouldNotReceive('dispatch');

    (new DispatchEnquiryNotificationsJob($enquiryId))->handle($dispatcher, app(AchievementNotifier::class));
});
