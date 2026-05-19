<?php

use App\Jobs\Shopify\Gdpr\ExportCustomerDataJob;
use App\Mail\Gdpr\CustomerDataExportMail;
use App\Models\Core\Gdpr\GdprRequest;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    Mail::fake();

    $conn = DB::connection('pgsql');
    try {
        $conn->statement('ATTACH DATABASE \':memory:\' AS core');
        $conn->statement('ATTACH DATABASE \':memory:\' AS notifications');
        $conn->statement('ATTACH DATABASE \':memory:\' AS site');
        $conn->statement('ATTACH DATABASE \':memory:\' AS analytics');
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY,
        primary_email TEXT,
        public_contact_email TEXT,
        display_name TEXT,
        handle TEXT,
        status TEXT,
        professional_type TEXT,
        account_type TEXT NULL,
        has_historical_partner_links INTEGER NULL,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        provider TEXT NOT NULL,
        shopify_shop_domain TEXT,
        provider_metadata TEXT,
        access_token TEXT,
        refresh_token TEXT,
        external_account_id TEXT,
        expires_at TEXT,
        catalog_latest_time TEXT,
        last_catalog_sync_at TEXT,
        last_catalog_sync_error TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.customers (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        email TEXT,
        phone TEXT,
        full_name TEXT,
        source TEXT,
        notes TEXT,
        external_id TEXT,
        redacted_at TEXT,
        marketing_opt_in_cached INTEGER,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.email_subscriptions (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        list_key TEXT,
        email TEXT,
        email_lc TEXT,
        full_name TEXT,
        status TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS site.enquiries (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        site_id TEXT,
        name TEXT,
        email TEXT,
        phone TEXT,
        subject TEXT,
        message TEXT,
        ip_hash TEXT,
        user_agent TEXT,
        read_at TEXT,
        deleted_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS analytics.lead_submissions (
        id TEXT PRIMARY KEY,
        customer_id TEXT,
        professional_id TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS analytics.booking_events (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        site_id TEXT,
        occurred_at TEXT,
        status TEXT,
        source TEXT,
        customer_name TEXT,
        customer_email TEXT,
        customer_phone TEXT,
        currency_code TEXT,
        amount_paid_cents INTEGER,
        raw_payload TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.gdpr_requests (
        id TEXT PRIMARY KEY,
        topic TEXT NOT NULL,
        shop_domain TEXT NOT NULL,
        shopify_shop_id INTEGER,
        payload_hash TEXT NOT NULL,
        payload TEXT NOT NULL,
        professional_id TEXT,
        status TEXT NOT NULL DEFAULT \'received\',
        error TEXT,
        received_at TEXT,
        completed_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
});

function seedExportFixture(): array
{
    $professionalId = (string) Str::uuid();
    $shopDomain = 'test-brand.myshopify.com';

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $professionalId,
        'primary_email' => 'merchant@example.com',
        'public_contact_email' => 'merchant@example.com',
        'display_name' => 'Merchant Name',
        'handle' => 'test-brand',
        'status' => 'active',
        'professional_type' => 'brand',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // shopify_shop_domain is a generated column — raw insert bypasses $fillable
    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $professionalId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'provider_metadata' => json_encode(['shop_domain' => $shopDomain]),
        'shopify_shop_domain' => $shopDomain,
        'access_token' => 'shpat_live',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $customer = Customer::create([
        'professional_id' => $professionalId,
        'email' => 'target@example.com',
        'phone' => '+1555',
        'full_name' => 'Target Customer',
        'source' => 'shopify',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('notifications.email_subscriptions')->insert([
        'id' => 'sub-exp',
        'professional_id' => $professionalId,
        'list_key' => 'marketing',
        'email' => 'target@example.com',
        'email_lc' => 'target@example.com',
        'full_name' => 'Target Customer',
        'status' => 'subscribed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Booking for the target customer — must appear in the export.
    DB::table('analytics.booking_events')->insert([
        'id' => 'bk-export-target',
        'professional_id' => $professionalId,
        'site_id' => 'site-1',
        'occurred_at' => now(),
        'status' => 'completed',
        'source' => 'site_booking_checkout',
        'customer_name' => 'Target Customer',
        'customer_email' => 'target@example.com',
        'customer_phone' => '+1555',
        'currency_code' => 'USD',
        'amount_paid_cents' => 4200,
        'raw_payload' => json_encode(['customer' => ['email' => 'target@example.com'], 'staff_id' => 'third-party-staff-id']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Booking for a DIFFERENT customer — must not leak into the export.
    DB::table('analytics.booking_events')->insert([
        'id' => 'bk-export-other',
        'professional_id' => $professionalId,
        'site_id' => 'site-1',
        'occurred_at' => now(),
        'status' => 'completed',
        'source' => 'site_booking_checkout',
        'customer_name' => 'Other Customer',
        'customer_email' => 'other@example.com',
        'customer_phone' => '+9999',
        'currency_code' => 'USD',
        'amount_paid_cents' => 9999,
        'raw_payload' => json_encode(['customer' => ['email' => 'other@example.com']]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $gdpr = GdprRequest::create([
        'topic' => GdprRequest::TOPIC_CUSTOMERS_DATA_REQUEST,
        'shop_domain' => $shopDomain,
        'payload_hash' => str_repeat('e', 64),
        'payload' => [
            'shop_domain' => $shopDomain,
            'customer' => ['id' => 99, 'email' => 'target@example.com'],
            'orders_requested' => [],
        ],
        'received_at' => now(),
    ]);

    return compact('professionalId', 'customer', 'gdpr');
}

it('sends the export email to the merchant contact address', function () {
    $ctx = seedExportFixture();

    (new ExportCustomerDataJob($ctx['gdpr']->id))->handle();

    Mail::assertSent(CustomerDataExportMail::class, function ($mail) {
        return $mail->hasTo('merchant@example.com')
            && $mail->customerEmail === 'target@example.com'
            && $mail->shopDomain === 'test-brand.myshopify.com';
    });
});

it('includes the customer row and email subscription in the export payload', function () {
    $ctx = seedExportFixture();

    (new ExportCustomerDataJob($ctx['gdpr']->id))->handle();

    Mail::assertSent(CustomerDataExportMail::class, function ($mail) {
        $data = $mail->exportData;

        return isset($data['customers'][0]['email'])
            && $data['customers'][0]['email'] === 'target@example.com'
            && count($data['email_subscriptions']) === 1;
    });
});

it('includes booking_events for the matching email but omits raw_payload and other customers', function () {
    seedExportFixture();

    (new ExportCustomerDataJob(GdprRequest::first()->id))->handle();

    Mail::assertSent(CustomerDataExportMail::class, function ($mail) {
        $bookings = $mail->exportData['bookings'] ?? [];

        // Only the target customer's booking — other@example.com's row is filtered out.
        return count($bookings) === 1
            && $bookings[0]['customer_email'] === 'target@example.com'
            && $bookings[0]['amount_paid_cents'] === 4200
            // raw_payload contains third-party data (staff_id) and is deliberately excluded.
            && ! array_key_exists('raw_payload', $bookings[0]);
    });
});

it('marks the request completed on success', function () {
    $ctx = seedExportFixture();

    (new ExportCustomerDataJob($ctx['gdpr']->id))->handle();

    expect(GdprRequest::find($ctx['gdpr']->id)->status)->toBe(GdprRequest::STATUS_COMPLETED);
});

it('marks the request skipped when shop_domain is unknown', function () {
    $gdpr = GdprRequest::create([
        'topic' => GdprRequest::TOPIC_CUSTOMERS_DATA_REQUEST,
        'shop_domain' => 'ghost.myshopify.com',
        'payload_hash' => str_repeat('f', 64),
        'payload' => ['customer' => ['email' => 'x@example.com']],
        'received_at' => now(),
    ]);

    (new ExportCustomerDataJob($gdpr->id))->handle();

    expect(GdprRequest::find($gdpr->id)->status)->toBe(GdprRequest::STATUS_SKIPPED);
    Mail::assertNothingSent();
});

it('sends an empty export when the customer has no records (legitimate case)', function () {
    $ctx = seedExportFixture();
    // Force-delete (not soft-delete) — withTrashed() would include a soft-deleted row.
    $ctx['customer']->forceDelete();
    DB::table('notifications.email_subscriptions')->where('id', 'sub-exp')->delete();

    (new ExportCustomerDataJob($ctx['gdpr']->id))->handle();

    // Shopify expects confirmation even when we hold no data.
    Mail::assertSent(CustomerDataExportMail::class, function ($mail) {
        return count($mail->exportData['customers'] ?? []) === 0;
    });
    expect(GdprRequest::find($ctx['gdpr']->id)->status)->toBe(GdprRequest::STATUS_COMPLETED);
});
