<?php

use App\Models\Core\Site\Enquiry;
use App\Models\Core\User\Customer;
use App\Services\Notifications\EnquirySpamBlocklist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
    Redis::connection()->flushdb();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.email_subscriptions (
        id TEXT PRIMARY KEY,
        user_id TEXT NOT NULL,
        email TEXT NULL,
        email_lc TEXT NULL,
        list_key TEXT NULL,
        status TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
});

it('mark-as-spam transitions status + adds email to blocklist', function () {
    $user = makeInboxUser();
    $customerId = seedInboxCustomer($user->id, ['email' => 'spammer@example.com', 'source' => 'enquiry']);
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), [
        'customer_id' => $customerId, 'email' => 'spammer@example.com',
    ]);

    actingAsUser($user)->postJson("/api/enquiries/{$enquiryId}/spam")->assertOk();

    expect(Enquiry::find($enquiryId)->status->value)->toBe('spam');
    expect(app(EnquirySpamBlocklist::class)->contains((string) $user->id, 'spammer@example.com'))->toBeTrue();
});

it('soft-deletes the Customer when it has no other touchpoints', function () {
    $user = makeInboxUser();
    $customerId = seedInboxCustomer($user->id, ['email' => 'lone@example.com', 'source' => 'enquiry', 'external_id' => null]);
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), [
        'customer_id' => $customerId, 'email' => 'lone@example.com',
    ]);

    actingAsUser($user)->postJson("/api/enquiries/{$enquiryId}/spam")->assertOk();

    expect(Customer::find($customerId))->toBeNull();              // soft-deleted (default scope hides it)
    expect(Customer::withTrashed()->find($customerId))->not->toBeNull();
});

it('preserves the Customer when external_id exists', function () {
    $user = makeInboxUser();
    $customerId = seedInboxCustomer($user->id, ['email' => 'multi@example.com', 'source' => 'enquiry', 'external_id' => 'pos-123']);
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), [
        'customer_id' => $customerId, 'email' => 'multi@example.com',
    ]);

    actingAsUser($user)->postJson("/api/enquiries/{$enquiryId}/spam")->assertOk();

    expect(Customer::find($customerId))->not->toBeNull();  // preserved
});

it('preserves the Customer when another enquiry references it', function () {
    $user = makeInboxUser();
    $customerId = seedInboxCustomer($user->id, ['email' => 'two@example.com', 'source' => 'enquiry', 'external_id' => null]);
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), ['customer_id' => $customerId, 'email' => 'two@example.com']);
    seedInboxEnquiry($user->id, (string) Str::uuid(), ['customer_id' => $customerId, 'email' => 'two@example.com']);

    actingAsUser($user)->postJson("/api/enquiries/{$enquiryId}/spam")->assertOk();

    expect(Customer::find($customerId))->not->toBeNull();  // preserved (other enquiry)
});
