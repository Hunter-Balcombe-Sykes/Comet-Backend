<?php

// Defence-in-depth require for worktree environments where Pest's rootPath
// resolves to the parent repo (vendor symlink) and may miss these helpers.
require_once __DIR__.'/../../Helpers/EnquiryInboxTestHelpers.php';

use App\Jobs\Notifications\SendEnquiryNotificationJob;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Services\Notifications\Adapters\EmailEnquiryNotificationAdapter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
    RateLimiter::clear('enquiry_notify:*');
});

it('dispatches SendEnquiryNotificationJob within rate limit', function () {
    Bus::fake();
    $user = makeInboxUser();
    $siteId = (string) Str::uuid();
    $enquiryId = seedInboxEnquiry($user->id, $siteId);
    $blockId = seedContactBlock($siteId, $user->id, ['notification_email' => 'pro@example.com']);

    $enquiry = Enquiry::find($enquiryId);
    $block = Block::find($blockId);

    app(EmailEnquiryNotificationAdapter::class)->dispatch($enquiry, $block);

    Bus::assertDispatched(SendEnquiryNotificationJob::class);
});

it('silently drops when per-pro hourly limit exceeded', function () {
    Bus::fake();
    $user = makeInboxUser();
    $siteId = (string) Str::uuid();
    $enquiryId = seedInboxEnquiry($user->id, $siteId);
    $blockId = seedContactBlock($siteId, $user->id, ['notification_email' => 'pro@example.com']);

    $enquiry = Enquiry::find($enquiryId);
    $block = Block::find($blockId);

    $key = 'enquiry_notify:'.$user->id;
    $limit = (int) config('partna.throttle.enquiry_notification_per_hour', 10);
    for ($i = 0; $i < $limit; $i++) {
        RateLimiter::hit($key, 3600);
    }

    app(EmailEnquiryNotificationAdapter::class)->dispatch($enquiry, $block);

    Bus::assertNotDispatched(SendEnquiryNotificationJob::class);
});

it('does nothing when notification_email is empty', function () {
    Bus::fake();
    $user = makeInboxUser();
    $siteId = (string) Str::uuid();
    $enquiryId = seedInboxEnquiry($user->id, $siteId);
    $blockId = seedContactBlock($siteId, $user->id, ['notification_email' => '']);

    $enquiry = Enquiry::find($enquiryId);
    $block = Block::find($blockId);

    app(EmailEnquiryNotificationAdapter::class)->dispatch($enquiry, $block);

    Bus::assertNotDispatched(SendEnquiryNotificationJob::class);
});
