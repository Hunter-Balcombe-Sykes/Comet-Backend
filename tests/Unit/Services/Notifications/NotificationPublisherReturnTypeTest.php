<?php

use App\Models\Core\Notifications\Notification;
use App\Services\Notifications\NotificationPublisher;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();  // creates notifications.notifications table
});

it('publish() returns the inserted Notification on first call', function () {
    $publisher = app(NotificationPublisher::class);
    $user = makeInboxUser();

    $result = $publisher->publish(
        userId: (string) $user->id,
        frontendType: 'enquiry.received',
        category: 'inbox',
        title: 'New enquiry',
        body: 'Hello there',
        dedupeKey: 'enquiry:test-1',
        ctaUrl: '/dashboard/enquiries/abc',
    );

    expect($result)->toBeInstanceOf(Notification::class);
    expect($result->dedupe_key)->toBe('enquiry:test-1');
});

it('publish() returns the EXISTING Notification when dedupe-key matches', function () {
    $publisher = app(NotificationPublisher::class);
    $user = makeInboxUser();

    $first = $publisher->publish(
        userId: (string) $user->id,
        frontendType: 'enquiry.received',
        category: 'inbox',
        title: 'a',
        body: 'b',
        dedupeKey: 'enquiry:test-2',
    );
    $second = $publisher->publish(
        userId: (string) $user->id,
        frontendType: 'enquiry.received',
        category: 'inbox',
        title: 'c',
        body: 'd',
        dedupeKey: 'enquiry:test-2',
    );

    expect($second->id)->toBe($first->id);
});
