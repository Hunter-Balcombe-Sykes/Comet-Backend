<?php

// Helpers file loaded by Pest's BootFiles from tests/Helpers/ at boot — this
// require_once is a defence-in-depth for worktree environments where the Pest
// rootPath resolves to the parent repo (vendor symlink), which may be on a
// different branch and miss the helpers.
require_once __DIR__.'/../../Helpers/EnquiryInboxTestHelpers.php';

use App\Models\Core\Notifications\Notification;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Services\Notifications\Adapters\InAppEnquiryNotificationAdapter;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
});

it('publishes an in-app notification and writes notification_id back', function () {
    $user = makeInboxUser();
    $siteId = (string) Str::uuid();
    $enquiryId = seedInboxEnquiry($user->id, $siteId);
    $blockId = seedContactBlock($siteId, $user->id);

    $enquiry = Enquiry::find($enquiryId);
    $block = Block::find($blockId);

    app(InAppEnquiryNotificationAdapter::class)->dispatch($enquiry, $block);

    $fresh = Enquiry::find($enquiryId);
    expect($fresh->notification_id)->not->toBeNull();

    $notif = Notification::find($fresh->notification_id);
    expect($notif->dedupe_key)->toBe("enquiry:{$enquiry->id}");
    expect($notif->category)->toBe('inbox');
});

it('is idempotent under retries (same dedupe key)', function () {
    $user = makeInboxUser();
    $siteId = (string) Str::uuid();
    $enquiryId = seedInboxEnquiry($user->id, $siteId);
    $blockId = seedContactBlock($siteId, $user->id);

    $enquiry = Enquiry::find($enquiryId);
    $block = Block::find($blockId);

    $adapter = app(InAppEnquiryNotificationAdapter::class);
    $adapter->dispatch($enquiry, $block);
    $firstId = Enquiry::find($enquiryId)->notification_id;

    $adapter->dispatch($enquiry, $block);
    $secondId = Enquiry::find($enquiryId)->notification_id;

    expect($secondId)->toBe($firstId);
});
