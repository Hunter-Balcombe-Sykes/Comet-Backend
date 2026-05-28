<?php

use App\Models\Core\Site\Enquiry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
});

it('owner can view + update + delete their enquiry', function () {
    $user = makeInboxUser();
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid());
    $enquiry = Enquiry::find($enquiryId);

    expect(Gate::forUser($user)->allows('view', $enquiry))->toBeTrue();
    expect(Gate::forUser($user)->allows('update', $enquiry))->toBeTrue();
    expect(Gate::forUser($user)->allows('delete', $enquiry))->toBeTrue();
});

it('another pro cannot view/update/delete', function () {
    $owner = makeInboxUser();
    $stranger = makeInboxUser();
    $enquiryId = seedInboxEnquiry($owner->id, (string) Str::uuid());
    $enquiry = Enquiry::find($enquiryId);

    expect(Gate::forUser($stranger)->allows('view', $enquiry))->toBeFalse();
    expect(Gate::forUser($stranger)->allows('update', $enquiry))->toBeFalse();
    expect(Gate::forUser($stranger)->allows('delete', $enquiry))->toBeFalse();
});

it('viewAny allows any authenticated user (controller scopes by user_id)', function () {
    $user = makeInboxUser();
    expect(Gate::forUser($user)->allows('viewAny', Enquiry::class))->toBeTrue();
});
