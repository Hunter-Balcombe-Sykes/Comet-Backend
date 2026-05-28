<?php

use App\Models\Core\Site\Enquiry;
use App\Models\Core\User\Customer;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
});

it('redact() nulls customer PII and cascades to linked enquiries', function () {
    $user = makeInboxUser();
    $customerId = seedInboxCustomer($user->id, [
        'email' => 'foo@example.com',
        'full_name' => 'Foo Bar',
    ]);
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), [
        'customer_id' => $customerId,
        'name' => 'Foo Bar',
        'email' => 'foo@example.com',
    ]);

    $customer = Customer::find($customerId);
    $customer->redact();

    $fresh = Customer::find($customerId);
    expect($fresh->email)->toBeNull();
    expect($fresh->full_name)->toBeNull();
    expect($fresh->redacted_at)->not->toBeNull();

    $freshEnquiry = Enquiry::find($enquiryId);
    expect($freshEnquiry->name)->toBeNull();
    expect($freshEnquiry->redacted_at)->not->toBeNull();
});
