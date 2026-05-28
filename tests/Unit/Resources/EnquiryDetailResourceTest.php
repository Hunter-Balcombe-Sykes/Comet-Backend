<?php

use App\Http\Resources\EnquiryDetailResource;
use App\Models\Core\Site\Enquiry;
use App\Models\Core\User\Customer;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
});

it('includes mailto_url + eager-loaded customer + history collection', function () {
    $user = makeInboxUser();
    $customerId = seedInboxCustomer($user->id, ['email' => 'a@example.com']);
    $customer = Customer::find($customerId);

    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), [
        'customer_id' => $customerId,
        'email' => 'a@example.com',
        'subject' => 'Pricing & Availability',
    ]);
    $siblingId = seedInboxEnquiry($user->id, (string) Str::uuid(), [
        'customer_id' => $customerId,
        'subject' => 'Prior question',
    ]);

    $enquiry = Enquiry::find($enquiryId);
    $sibling = Enquiry::find($siblingId);

    // Controller pre-loads relation + attaches history collection (mirrors Task 20).
    $enquiry->setRelation('customer', $customer);
    $enquiry->historyForDetailView = collect([$sibling]);

    $payload = (new EnquiryDetailResource($enquiry))->resolve();

    expect($payload['mailto_url'])->toBe('mailto:a%40example.com?subject=Re%3A%20Pricing%20%26%20Availability');
    expect($payload['customer']['email'])->toBe('a@example.com');
    expect($payload['history'])->toHaveCount(1);
    expect($payload['history'][0]['subject'])->toBe('Prior question');
});
