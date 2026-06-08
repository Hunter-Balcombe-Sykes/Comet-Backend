<?php

use App\Http\Resources\EnquiryResource;
use App\Models\Core\Site\Enquiry;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
});

it('emits status + new audit timestamps', function () {
    $user = makeInboxUser();
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), [
        'status' => 'replied',
        'replied_at' => now()->toDateTimeString(),
    ]);
    $enquiry = Enquiry::find($enquiryId);

    $payload = (new EnquiryResource($enquiry))->resolve();

    expect($payload['status'])->toBe('replied');
    expect($payload)->toHaveKey('replied_at');
    expect($payload)->toHaveKey('archived_at');
    expect($payload)->toHaveKey('spam_at');
    expect($payload)->toHaveKey('updated_at');
    // Backwards-compat fields retained:
    expect($payload)->toHaveKey('is_read');
    expect($payload)->toHaveKey('read_at');
    expect($payload['is_read'])->toBe(true);  // status !== 'new'
});

it('is_read is false when status is new', function () {
    $user = makeInboxUser();
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), ['status' => 'new']);
    $enquiry = Enquiry::find($enquiryId);

    $payload = (new EnquiryResource($enquiry))->resolve();
    expect($payload['is_read'])->toBe(false);
});

// SEM-9: a null status must NOT silently read as is_read=true. The old
// `$this->status?->value !== 'new'` evaluated `null !== 'new'` → true.
it('is_read falls back to read_at (false) when status is null and unread', function () {
    $user = makeInboxUser();
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), ['status' => 'new']);
    $enquiry = Enquiry::find($enquiryId);
    // Simulate a row created without the status default (e.g. bulk import).
    $enquiry->status = null;
    $enquiry->read_at = null;

    $payload = (new EnquiryResource($enquiry))->resolve();
    expect($payload['is_read'])->toBe(false);
});

it('is_read is true when status is null but read_at is set', function () {
    $user = makeInboxUser();
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), ['status' => 'new']);
    $enquiry = Enquiry::find($enquiryId);
    $enquiry->status = null;
    $enquiry->read_at = now();

    $payload = (new EnquiryResource($enquiry))->resolve();
    expect($payload['is_read'])->toBe(true);
});
