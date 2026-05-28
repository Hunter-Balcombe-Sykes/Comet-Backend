<?php

// Helpers file loaded by Pest's BootFiles from tests/Helpers/ at boot — this
// require_once is a defence-in-depth for worktree environments where the Pest
// rootPath resolves to the parent repo (vendor symlink), which may miss the helpers.
require_once __DIR__.'/../../Helpers/EnquiryInboxTestHelpers.php';

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Enquiry;
use App\Services\Notifications\Adapters\EmailEnquiryNotificationAdapter;
use App\Services\Notifications\Adapters\InAppEnquiryNotificationAdapter;
use App\Services\Notifications\EnquiryNotificationDispatcher;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
});

function dispatcherTestEnquiryAndBlock(array $blockSettings): array
{
    $user = makeInboxUser();
    $siteId = (string) Str::uuid();
    $enquiryId = seedInboxEnquiry($user->id, $siteId);
    $blockId = seedContactBlock($siteId, $user->id, $blockSettings);

    return [Enquiry::find($enquiryId), Block::find($blockId)];
}

it('dispatches in_app only when channels = [in_app]', function () {
    [$enquiry, $block] = dispatcherTestEnquiryAndBlock(['notification_channels' => ['in_app']]);

    $inApp = Mockery::mock(InAppEnquiryNotificationAdapter::class);
    $email = Mockery::mock(EmailEnquiryNotificationAdapter::class);
    $inApp->shouldReceive('channel')->andReturn('in_app');
    $email->shouldReceive('channel')->andReturn('email');
    $inApp->shouldReceive('dispatch')->once();
    $email->shouldNotReceive('dispatch');

    (new EnquiryNotificationDispatcher([$inApp, $email]))->dispatch($enquiry, $block);
});

it('dispatches both when channels = [in_app, email]', function () {
    [$enquiry, $block] = dispatcherTestEnquiryAndBlock(['notification_channels' => ['in_app', 'email']]);

    $inApp = Mockery::mock(InAppEnquiryNotificationAdapter::class);
    $email = Mockery::mock(EmailEnquiryNotificationAdapter::class);
    $inApp->shouldReceive('channel')->andReturn('in_app');
    $email->shouldReceive('channel')->andReturn('email');
    $inApp->shouldReceive('dispatch')->once();
    $email->shouldReceive('dispatch')->once();

    (new EnquiryNotificationDispatcher([$inApp, $email]))->dispatch($enquiry, $block);
});

it('defaults to [in_app] when channels key missing', function () {
    [$enquiry, $block] = dispatcherTestEnquiryAndBlock([]);  // no notification_channels

    $inApp = Mockery::mock(InAppEnquiryNotificationAdapter::class);
    $email = Mockery::mock(EmailEnquiryNotificationAdapter::class);
    $inApp->shouldReceive('channel')->andReturn('in_app');
    $email->shouldReceive('channel')->andReturn('email');
    $inApp->shouldReceive('dispatch')->once();
    $email->shouldNotReceive('dispatch');

    (new EnquiryNotificationDispatcher([$inApp, $email]))->dispatch($enquiry, $block);
});

it('continues to other adapters when one throws (reports, no rethrow)', function () {
    [$enquiry, $block] = dispatcherTestEnquiryAndBlock(['notification_channels' => ['in_app', 'email']]);

    $inApp = Mockery::mock(InAppEnquiryNotificationAdapter::class);
    $email = Mockery::mock(EmailEnquiryNotificationAdapter::class);
    $inApp->shouldReceive('channel')->andReturn('in_app');
    $email->shouldReceive('channel')->andReturn('email');
    $inApp->shouldReceive('dispatch')->andThrow(new RuntimeException('publisher down'));
    $email->shouldReceive('dispatch')->once();

    (new EnquiryNotificationDispatcher([$inApp, $email]))->dispatch($enquiry, $block);
});
