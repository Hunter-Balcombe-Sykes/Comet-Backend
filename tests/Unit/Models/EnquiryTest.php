<?php

use App\Enums\EnquiryStatus;
use App\Models\Core\Site\Enquiry;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Tests\TestCase;

// Relationship assertions require a booted Laravel app (DB resolver).
uses(TestCase::class);

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
});

it('has new fillable + casts + relationships', function () {
    $enquiry = new Enquiry;

    $expected = ['user_id', 'site_id', 'name', 'email', 'phone', 'subject',
        'message', 'ip_hash', 'user_agent', 'read_at', 'email_sent_at',
        'status', 'customer_id', 'notification_id',
        'replied_at', 'archived_at', 'spam_at', 'redacted_at'];

    foreach ($expected as $field) {
        expect($enquiry->getFillable())->toContain($field);
    }

    expect($enquiry->getCasts()['status'])->toBe(EnquiryStatus::class);
    expect($enquiry->getCasts()['replied_at'])->toBe('datetime');
    expect($enquiry->getCasts()['archived_at'])->toBe('datetime');
    expect($enquiry->getCasts()['spam_at'])->toBe('datetime');
    expect($enquiry->getCasts()['redacted_at'])->toBe('datetime');

    expect($enquiry->customer())->toBeInstanceOf(BelongsTo::class);
    expect($enquiry->notification())->toBeInstanceOf(BelongsTo::class);
});

it('markRead() sets status=read and read_at', function () {
    $user = makeInboxUser();
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), ['status' => 'new']);
    $enquiry = Enquiry::find($enquiryId);

    $enquiry->markRead();

    $fresh = Enquiry::find($enquiryId);
    expect($fresh->status)->toBe(EnquiryStatus::Read);
    expect($fresh->read_at)->not->toBeNull();
});

it('markReplied() sets status=replied and replied_at', function () {
    $user = makeInboxUser();
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), ['status' => 'read']);
    $enquiry = Enquiry::find($enquiryId);

    $enquiry->markReplied();

    $fresh = Enquiry::find($enquiryId);
    expect($fresh->status)->toBe(EnquiryStatus::Replied);
    expect($fresh->replied_at)->not->toBeNull();
});

it('archive() sets status=archived and archived_at', function () {
    $user = makeInboxUser();
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), ['status' => 'replied']);
    $enquiry = Enquiry::find($enquiryId);

    $enquiry->archive();

    $fresh = Enquiry::find($enquiryId);
    expect($fresh->status)->toBe(EnquiryStatus::Archived);
    expect($fresh->archived_at)->not->toBeNull();
});

it('markSpam() sets status=spam and spam_at', function () {
    $user = makeInboxUser();
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), ['status' => 'new']);
    $enquiry = Enquiry::find($enquiryId);

    $enquiry->markSpam();

    $fresh = Enquiry::find($enquiryId);
    expect($fresh->status)->toBe(EnquiryStatus::Spam);
    expect($fresh->spam_at)->not->toBeNull();
});

it('restoreToNew() sets status=new and preserves audit timestamps', function () {
    $user = makeInboxUser();
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), [
        'status' => 'archived',
        'archived_at' => now()->toDateTimeString(),
    ]);
    $enquiry = Enquiry::find($enquiryId);

    $enquiry->restoreToNew();

    $fresh = Enquiry::find($enquiryId);
    expect($fresh->status)->toBe(EnquiryStatus::New);
    expect($fresh->archived_at)->not->toBeNull(); // audit history preserved
});

it('transitions are idempotent', function () {
    $user = makeInboxUser();
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), ['status' => 'archived']);
    $enquiry = Enquiry::find($enquiryId);

    $enquiry->archive();
    $enquiry->archive();

    expect(Enquiry::find($enquiryId)->status)->toBe(EnquiryStatus::Archived);
});
