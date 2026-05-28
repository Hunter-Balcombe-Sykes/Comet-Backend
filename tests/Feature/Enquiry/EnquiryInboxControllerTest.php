<?php

use App\Models\Core\Site\Enquiry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupContactInboxSchema();
});

it('returns counts across all five statuses', function () {
    $user = makeInboxUser();
    $siteId = (string) Str::uuid();
    foreach (['new', 'new', 'new', 'read', 'read', 'replied', 'archived', 'archived', 'archived', 'archived', 'archived', 'spam', 'spam', 'spam', 'spam'] as $status) {
        seedInboxEnquiry($user->id, $siteId, ['status' => $status]);
    }

    actingAsUser($user)
        ->getJson('/api/enquiries/counts')
        ->assertOk()
        ->assertJson([
            'new' => 3, 'read' => 2, 'replied' => 1, 'archived' => 5, 'spam' => 4,
        ]);
});

it('excludes other pros enquiries from counts', function () {
    $me = makeInboxUser();
    $other = makeInboxUser();
    seedInboxEnquiry($me->id, (string) Str::uuid(), ['status' => 'new']);
    seedInboxEnquiry($other->id, (string) Str::uuid(), ['status' => 'new']);

    actingAsUser($me)
        ->getJson('/api/enquiries/counts')
        ->assertOk()
        ->assertJson(['new' => 1]);
});

it('returns enquiry + customer + history; auto-transitions new -> read', function () {
    $user = makeInboxUser();
    $customerId = seedInboxCustomer($user->id);
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), [
        'status' => 'new', 'customer_id' => $customerId,
    ]);
    seedInboxEnquiry($user->id, (string) Str::uuid(), [
        'customer_id' => $customerId, 'subject' => 'Earlier question',
    ]);

    $response = actingAsUser($user)->getJson("/api/enquiries/{$enquiryId}");

    $response->assertOk()
        ->assertJsonPath('data.status', 'read')
        ->assertJsonPath('data.customer.id', (string) $customerId);

    expect(Enquiry::find($enquiryId)->status->value)->toBe('read');
});

it('returns 404 when enquiry belongs to another pro', function () {
    $me = makeInboxUser();
    $other = makeInboxUser();
    $enquiryId = seedInboxEnquiry($other->id, (string) Str::uuid());

    actingAsUser($me)->getJson("/api/enquiries/{$enquiryId}")->assertNotFound();
});

it('marks the linked notification receipt as read on view', function () {
    $user = makeInboxUser();

    // Recreate notification_receipts with the UNIQUE constraint required by
    // NotificationListingService::upsertReceipt's ON CONFLICT clause (SQLite
    // requires the constraint to exist even when there's no actual conflict).
    DB::connection('pgsql')->statement('DROP TABLE IF EXISTS notifications.notification_receipts');
    DB::connection('pgsql')->statement('CREATE TABLE notifications.notification_receipts (
        id TEXT PRIMARY KEY,
        notification_id TEXT NOT NULL,
        user_id TEXT NOT NULL,
        read_at TEXT NULL,
        dismissed_at TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(notification_id, user_id)
    )');

    // Seed a notification linked to the enquiry via notification_id.
    $notificationId = (string) Str::uuid();
    DB::connection('pgsql')->table('notifications.notifications')->insert([
        'id' => $notificationId, 'user_id' => $user->id, 'type' => 'enquiry.received',
        'category' => 'inbox', 'title' => 'New enquiry', 'body' => 'x',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), [
        'status' => 'new', 'notification_id' => $notificationId,
    ]);

    actingAsUser($user)->getJson("/api/enquiries/{$enquiryId}")->assertOk();

    // upsertReceipt creates the receipt row with read_at set.
    $receipt = DB::connection('pgsql')->table('notifications.notification_receipts')
        ->where('notification_id', $notificationId)->where('user_id', $user->id)->first();
    expect($receipt->read_at)->not->toBeNull();
});

it('PATCH /api/enquiries/{id} with {read: true} sets status=read AND read_at', function () {
    $user = makeInboxUser();
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), ['status' => 'new']);

    actingAsUser($user)->patchJson("/api/enquiries/{$enquiryId}", ['read' => true])->assertOk();

    $fresh = Enquiry::find($enquiryId);
    expect($fresh->status->value)->toBe('read');
    expect($fresh->read_at)->not->toBeNull();
});

it('PATCH /api/enquiries/{id} with {read: false} sets status=new AND clears read_at', function () {
    $user = makeInboxUser();
    $enquiryId = seedInboxEnquiry($user->id, (string) Str::uuid(), [
        'status' => 'read', 'read_at' => now()->toDateTimeString(),
    ]);

    actingAsUser($user)->patchJson("/api/enquiries/{$enquiryId}", ['read' => false])->assertOk();

    $fresh = Enquiry::find($enquiryId);
    expect($fresh->status->value)->toBe('new');
    expect($fresh->read_at)->toBeNull();
});

it('default list excludes archived and spam', function () {
    $user = makeInboxUser();
    $siteId = (string) Str::uuid();
    foreach (['new', 'read', 'replied', 'archived', 'spam'] as $s) {
        seedInboxEnquiry($user->id, $siteId, ['status' => $s]);
    }

    $response = actingAsUser($user)->getJson('/api/enquiries')->assertOk();
    expect(count($response->json('data')))->toBe(3);  // new, read, replied
});

it('?status=archived returns only archived enquiries', function () {
    $user = makeInboxUser();
    $siteId = (string) Str::uuid();
    seedInboxEnquiry($user->id, $siteId, ['status' => 'archived']);
    seedInboxEnquiry($user->id, $siteId, ['status' => 'archived']);
    seedInboxEnquiry($user->id, $siteId, ['status' => 'new']);

    $response = actingAsUser($user)->getJson('/api/enquiries?status=archived')->assertOk();
    expect(count($response->json('data')))->toBe(2);
});
