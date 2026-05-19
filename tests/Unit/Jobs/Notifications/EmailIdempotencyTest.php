<?php

use App\Jobs\Notifications\SendEnquiryNotificationJob;
use App\Jobs\Notifications\SendStaffBroadcastEmailToSubscriberJob;
use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Mail\Notifications\CommissionNotificationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// ---------------------------------------------------------------------------
// SendEnquiryNotificationJob
// ---------------------------------------------------------------------------

beforeEach(function () {
    setupEnquiriesTable();
});

it('SendEnquiryNotificationJob skips send when email_sent_at is already set', function () {
    Mail::fake();

    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.enquiries')->insert([
        'id' => $id,
        'professional_id' => (string) Str::uuid(),
        'name' => 'Test User',
        'email' => 'visitor@example.test',
        'message' => 'Hello',
        'email_sent_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $job = new SendEnquiryNotificationJob($id, 'owner@example.test');
    $job->handle();

    Mail::assertNothingSent();
});

it('SendEnquiryNotificationJob sends and stamps email_sent_at on first invocation', function () {
    Mail::fake();

    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.enquiries')->insert([
        'id' => $id,
        'professional_id' => (string) Str::uuid(),
        'name' => 'Test User',
        'email' => 'visitor@example.test',
        'message' => 'Hello',
        'email_sent_at' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $job = new SendEnquiryNotificationJob($id, 'owner@example.test');
    $job->handle();

    Mail::assertSentCount(1);

    $emailSentAt = DB::connection('pgsql')
        ->table('site.enquiries')
        ->where('id', $id)
        ->value('email_sent_at');

    expect($emailSentAt)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// SendTransactionalNotificationEmailJob
// ---------------------------------------------------------------------------

beforeEach(function () {
    setupNotificationsTable();
    setupProfessionalsTable();
    setupNotificationEmailPoliciesTable();
    setupNotificationEmailPreferencesTable();
});

it('SendTransactionalNotificationEmailJob skips send when email_sent_at is already set', function () {
    Mail::fake();

    config([
        'partna.notifications.email_enabled' => true,
        'partna.notifications.mailables.commissions' => CommissionNotificationMail::class,
    ]);

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'primary_email' => 'pro@example.test',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $notifId = (string) Str::uuid();
    DB::connection('pgsql')->table('notifications.notifications')->insert([
        'id' => $notifId,
        'professional_id' => $proId,
        'type' => 'Info',
        'category' => 'commissions',
        'title' => 'Commission earned',
        'body' => 'You earned $10',
        'email_sent_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $job = new SendTransactionalNotificationEmailJob($notifId, 'commissions', $proId);
    $job->handle();

    Mail::assertNothingSent();
});

it('SendTransactionalNotificationEmailJob sends and stamps email_sent_at on first invocation', function () {
    Mail::fake();

    config([
        'partna.notifications.email_enabled' => true,
        'partna.notifications.mailables.commissions' => CommissionNotificationMail::class,
    ]);

    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'primary_email' => 'pro@example.test',
        // 'commissions' is gated on receives_commission_notifications (§28.11);
        // brand + partner have the capability, individual does not.
        'account_type' => 'brand',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $notifId = (string) Str::uuid();
    DB::connection('pgsql')->table('notifications.notifications')->insert([
        'id' => $notifId,
        'professional_id' => $proId,
        'type' => 'Info',
        'category' => 'commissions',
        'title' => 'Commission earned',
        'body' => 'You earned $10',
        'email_sent_at' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $job = new SendTransactionalNotificationEmailJob($notifId, 'commissions', $proId);
    $job->handle();

    Mail::assertSentCount(1);

    $emailSentAt = DB::connection('pgsql')
        ->table('notifications.notifications')
        ->where('id', $notifId)
        ->value('email_sent_at');

    expect($emailSentAt)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// SendStaffBroadcastEmailToSubscriberJob
// ---------------------------------------------------------------------------

beforeEach(function () {
    setupNotificationsTable();
    setupEmailSubscriptionsTable();
    setupBroadcastEmailReceiptsTable();
});

it('SendStaffBroadcastEmailToSubscriberJob skips send when receipt already exists', function () {
    Mail::fake();

    $notifId = (string) Str::uuid();
    DB::connection('pgsql')->table('notifications.notifications')->insert([
        'id' => $notifId,
        'type' => 'Info',
        'category' => 'broadcast',
        'title' => 'Test Broadcast',
        'body' => 'Hello subscribers.',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $subId = (string) Str::uuid();
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => $subId,
        'professional_id' => null,
        'list_key' => 'sidest_updates',
        'email' => 'sub@example.test',
        'email_lc' => 'sub@example.test',
        'status' => 'subscribed',
        'unsubscribe_token' => (string) Str::uuid(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Pre-insert the receipt to simulate a prior successful delivery
    DB::connection('pgsql')->table('notifications.broadcast_email_receipts')->insert([
        'notification_id' => $notifId,
        'subscription_id' => $subId,
        'email_sent_at' => now()->toDateTimeString(),
    ]);

    $job = new SendStaffBroadcastEmailToSubscriberJob($notifId, $subId);
    $job->handle();

    Mail::assertNothingSent();
});

it('SendStaffBroadcastEmailToSubscriberJob sends and records receipt on first delivery', function () {
    Mail::fake();

    $notifId = (string) Str::uuid();
    DB::connection('pgsql')->table('notifications.notifications')->insert([
        'id' => $notifId,
        'type' => 'Info',
        'category' => 'broadcast',
        'title' => 'Test Broadcast',
        'body' => 'Hello subscribers.',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $subId = (string) Str::uuid();
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => $subId,
        'professional_id' => null,
        'list_key' => 'sidest_updates',
        'email' => 'sub@example.test',
        'email_lc' => 'sub@example.test',
        'status' => 'subscribed',
        'unsubscribe_token' => (string) Str::uuid(),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $job = new SendStaffBroadcastEmailToSubscriberJob($notifId, $subId);
    $job->handle();

    Mail::assertSentCount(1);

    $receipt = DB::connection('pgsql')
        ->table('notifications.broadcast_email_receipts')
        ->where('notification_id', $notifId)
        ->where('subscription_id', $subId)
        ->first();

    expect($receipt)->not->toBeNull();
});
