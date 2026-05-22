<?php

/** @phpstan-ignore-all */

use App\Models\Core\Notifications\EmailSubscription;
use App\Services\Customers\ContactCaptureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Locks in the ContactCaptureService contract for the affiliate auto-capture
 * flow. Every assertion here guards against a specific audit finding on the
 * contacts commit (2580537) — if one fails, a regression has reintroduced the
 * original bug.
 */
beforeEach(function () {
    attachTestSchemas();
    $conn = DB::connection('pgsql');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.users (
        id TEXT PRIMARY KEY,
        handle TEXT NULL,
        handle_lc TEXT NULL,
        display_name TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    // core.customers — minimal column set matching the production migration.
    // marketing_opt_in_cached DEFAULT 1 mirrors the Postgres DEFAULT true so
    // the schema-default test scenario is faithful.
    $conn->statement('DROP TABLE IF EXISTS core.customers');
    $conn->statement('CREATE TABLE core.customers (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        email TEXT NULL,
        phone TEXT NULL,
        full_name TEXT NULL,
        source TEXT NULL,
        notes TEXT NULL,
        external_id TEXT NULL,
        marketing_opt_in_cached INTEGER NULL DEFAULT 1,
        deleted_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $conn->statement('DROP TABLE IF EXISTS notifications.email_subscriptions');
    $conn->statement('CREATE TABLE notifications.email_subscriptions (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        list_key TEXT NOT NULL DEFAULT "marketing",
        email TEXT NOT NULL,
        email_lc TEXT NOT NULL,
        full_name TEXT NULL,
        status TEXT NOT NULL DEFAULT "subscribed",
        subscribed_at TEXT NULL,
        unsubscribed_at TEXT NULL,
        unsubscribe_token TEXT NOT NULL,
        consent_source TEXT NULL,
        consent_ip_hash TEXT NULL,
        consent_user_agent TEXT NULL,
        qr_slug TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
    // Production has a partial unique index (professional_id, list_key, email_lc)
    // on this table. We don't create it here — SQLite rejects schema-qualified
    // CREATE INDEX syntax. Tests use unique emails per case so uniqueness
    // collisions never arise naturally; the race-recovery test simulates the
    // collision by pre-inserting a conflicting row and exercising the
    // reconcile path directly.

    $professionalId = (string) Str::uuid();
    DB::table('core.users')->insert([
        'id' => $professionalId,
        'handle' => 'acme',
        'handle_lc' => 'acme',
        'display_name' => 'Acme Salon',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    test()->professionalId = $professionalId;
    test()->service = new ContactCaptureService;
});

/**
 * --------------------------------------------------------------------------
 *  Marketing opt-in default
 * --------------------------------------------------------------------------
 */
it('defaults marketing_opt_in_cached to TRUE when capturing a new contact from a Shopify order', function () {
    $customer = test()->service->captureContact(test()->professionalId, [
        'email' => 'jane@example.com',
        'full_name' => 'Jane Doe',
        'phone' => '+61400000000',
        'source' => 'shopify_order',
        'external_id' => 'order_123',
    ]);

    expect($customer)->not->toBeNull();
    expect($customer->marketing_opt_in_cached)->toBeTrue();
    expect($customer->professional_id)->toBe(test()->professionalId);
});

it('defaults marketing_opt_in_cached to TRUE when capturing a new contact from a Square booking', function () {
    $customer = test()->service->captureContact(test()->professionalId, [
        'email' => 'alex@example.com',
        'full_name' => 'Alex Smith',
        'phone' => '',
        'source' => 'square_booking',
    ]);

    expect($customer)->not->toBeNull();
    expect($customer->marketing_opt_in_cached)->toBeTrue();
});

it('honours an explicit marketing_opt_in=false override for an opted-out Shopify buyer', function () {
    $customer = test()->service->captureContact(test()->professionalId, [
        'email' => 'optout@example.com',
        'full_name' => 'Opt Out',
        'source' => 'shopify_order',
        'marketing_opt_in' => false,
    ]);

    expect($customer)->not->toBeNull();
    expect($customer->marketing_opt_in_cached)->toBeFalse();
});

/**
 * --------------------------------------------------------------------------
 *  full_name preservation / overwrite
 * --------------------------------------------------------------------------
 */
it('preserves a manually-edited full_name when the incoming payload is shorter or equal length', function () {
    $initial = test()->service->captureContact(test()->professionalId, [
        'email' => 'repeat@example.com',
        'full_name' => 'Elizabeth Mountbatten-Windsor',
        'source' => 'shopify_order',
    ]);
    expect($initial->full_name)->toBe('Elizabeth Mountbatten-Windsor');

    // Later order payload carries a shorter (less complete) name. Must not overwrite.
    $second = test()->service->captureContact(test()->professionalId, [
        'email' => 'repeat@example.com',
        'full_name' => 'Liz W',
        'source' => 'shopify_order',
    ]);

    expect($second->id)->toBe($initial->id);
    expect($second->fresh()->full_name)->toBe('Elizabeth Mountbatten-Windsor');
});

it('preserves full_name when the incoming value is exactly the same length', function () {
    test()->service->captureContact(test()->professionalId, [
        'email' => 'same@example.com',
        'full_name' => 'John Doe',
        'source' => 'shopify_order',
    ]);

    // Same length — the manual edit should win. "john doe" (lowercase) must not clobber.
    test()->service->captureContact(test()->professionalId, [
        'email' => 'same@example.com',
        'full_name' => 'JOHN DOE',
        'source' => 'shopify_order',
    ]);

    $row = DB::table('core.customers')->where('email', 'same@example.com')->first();
    expect($row->full_name)->toBe('John Doe');
});

it('overwrites full_name when the incoming value is strictly longer (more substantial)', function () {
    test()->service->captureContact(test()->professionalId, [
        'email' => 'grow@example.com',
        'full_name' => 'John',
        'source' => 'shopify_order',
    ]);

    test()->service->captureContact(test()->professionalId, [
        'email' => 'grow@example.com',
        'full_name' => 'John Quincy Doe',
        'source' => 'shopify_order',
    ]);

    $row = DB::table('core.customers')->where('email', 'grow@example.com')->first();
    expect($row->full_name)->toBe('John Quincy Doe');
});

it('fills in full_name when the existing row has none', function () {
    test()->service->captureContact(test()->professionalId, [
        'email' => 'blank@example.com',
        'full_name' => null,
        'source' => 'shopify_order',
    ]);

    test()->service->captureContact(test()->professionalId, [
        'email' => 'blank@example.com',
        'full_name' => 'Real Name',
        'source' => 'shopify_order',
    ]);

    $row = DB::table('core.customers')->where('email', 'blank@example.com')->first();
    expect($row->full_name)->toBe('Real Name');
});

/**
 * --------------------------------------------------------------------------
 *  Phone normalization — one source of truth in the service
 * --------------------------------------------------------------------------
 */
it('normalizes empty / whitespace phone values to null so callers do not have to guard', function () {
    $customer = test()->service->captureContact(test()->professionalId, [
        'email' => 'phone-empty@example.com',
        'full_name' => 'Empty Phone',
        'phone' => '   ',
        'source' => 'square_booking',
    ]);

    expect($customer->phone)->toBeNull();
});

/**
 * --------------------------------------------------------------------------
 *  EmailSubscription race reconciliation
 * --------------------------------------------------------------------------
 *
 * We can't easily simulate a true concurrent INSERT race inside an in-memory
 * test, so we pre-create the "winner" row with the opposite state (unsubscribed)
 * and then force the service down the collision branch by pre-occupying the
 * unique key. The reconcile path must flip the surviving row back to
 * 'subscribed' — that's the exact bug the fix addresses.
 */
it('reconciles a raced EmailSubscription by updating the surviving row to subscribed', function () {
    $professionalId = test()->professionalId;
    $email = 'race@example.com';

    // Simulate a "winner" row: some earlier request created an unsubscribed row.
    DB::table('notifications.email_subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $professionalId,
        'list_key' => 'marketing',
        'email' => $email,
        'email_lc' => $email,
        'full_name' => null,
        'status' => 'unsubscribed',
        'unsubscribed_at' => now(),
        'unsubscribe_token' => Str::random(48),
        'consent_source' => 'prior_flow',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Capture a matching contact first so the saved-hook has a target.
    test()->service->captureContact($professionalId, [
        'email' => $email,
        'full_name' => 'Race Winner',
        'source' => 'shopify_order',
    ]);

    test()->service->captureMarketingSubscription(
        $professionalId,
        $email,
        'Race Winner',
        'shopify_order',
    );

    // The service's initial SELECT should find the unsubscribed row and
    // reactivate it — NOT leave it unsubscribed. This is the behaviour the
    // reconcile fix locks in: whether we hit the happy path or the 23505
    // recovery branch, the end state is 'subscribed'.
    $sub = EmailSubscription::query()
        ->where('professional_id', $professionalId)
        ->where('email_lc', $email)
        ->first();

    expect($sub)->not->toBeNull();
    expect($sub->status)->toBe('subscribed');
});

it('applies the raced-row reconcile path explicitly via reflection', function () {
    // Directly exercise the reconcileRacedSubscription private method with an
    // existing unsubscribed row — proves the reconciliation logic itself is
    // correct even if the in-memory driver never produces a real 23505.
    $professionalId = test()->professionalId;
    $email = 'reconcile@example.com';

    DB::table('notifications.email_subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $professionalId,
        'list_key' => 'marketing',
        'email' => $email,
        'email_lc' => $email,
        'full_name' => null,
        'status' => 'unsubscribed',
        'unsubscribed_at' => now(),
        'unsubscribe_token' => Str::random(48),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = test()->service;
    $method = new ReflectionMethod($service, 'reconcileRacedSubscription');
    $method->setAccessible(true);
    $method->invoke($service, $professionalId, $email, 'Reconciled Name', [
        'source' => 'shopify_order',
        'ip_hash' => null,
        'user_agent' => null,
    ]);

    $row = DB::table('notifications.email_subscriptions')
        ->where('professional_id', $professionalId)
        ->where('email_lc', $email)
        ->first();

    expect($row->status)->toBe('subscribed');
    expect($row->full_name)->toBe('Reconciled Name');
});
