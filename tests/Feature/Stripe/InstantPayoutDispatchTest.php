<?php

use App\Jobs\Shopify\ProcessShopifyOrderWebhookJob;
use App\Jobs\Stripe\ProcessCommissionPayoutsJob;
use App\Models\Brand\BrandStoreSettings;
use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Exercises ProcessShopifyOrderWebhookJob::dispatchInstantPayoutIfEligible via
// reflection — the private helper that triggers the instant-payout sweep when a
// brand has opted into hold_days=0 AND all payout prerequisites are met.

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();

    // The base helper doesn't include the Stripe brand-side columns we read here.
    foreach (['stripe_customer_id', 'stripe_payment_method_id'] as $col) {
        try {
            DB::connection('pgsql')->statement("ALTER TABLE core.professionals ADD COLUMN {$col} TEXT");
        } catch (\Throwable) {
            // column may already exist from a prior test in this connection
        }
    }

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS brand.brand_store_settings (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        default_commission_rate TEXT,
        payout_hold_days INTEGER,
        created_at TEXT,
        updated_at TEXT
    )');

    Queue::fake();
});

function instantBrand(array $overrides = []): Professional
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    // Under v2 the instant-payout dispatcher checks: stripe_connect_account_id (v2 Account),
    // stripe_connect_status='active', AND stripe_payment_method_id. All three must be present.
    DB::connection('pgsql')->table('core.professionals')->insert(array_merge([
        'id' => $id,
        'handle' => "brand-{$id}",
        'handle_lc' => "brand-{$id}",
        'display_name' => 'Side St',
        'professional_type' => 'brand',
        'status' => 'active',
        'primary_email' => 'brand@example.com',
        'stripe_connect_account_id' => 'acct_brand_test',
        'stripe_connect_status' => 'active',
        'stripe_payment_method_id' => 'pm_test',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return Professional::find($id);
}

function instantAffiliate(array $overrides = []): Professional
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert(array_merge([
        'id' => $id,
        'handle' => "aff-{$id}",
        'handle_lc' => "aff-{$id}",
        'display_name' => 'Aff One',
        'professional_type' => 'professional',
        'status' => 'active',
        'primary_email' => 'aff@example.com',
        'stripe_connect_status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides));

    return Professional::find($id);
}

function settingsRow(string $brandId, ?int $holdDays): ?BrandStoreSettings
{
    if ($holdDays === null) {
        return null;
    }

    DB::connection('pgsql')->table('brand.brand_store_settings')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $brandId,
        'payout_hold_days' => $holdDays,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return BrandStoreSettings::where('professional_id', $brandId)->first();
}

// DB-15: brand is now passed directly (already loaded) instead of re-fetched.
function invokeDispatcher(string $brandId, ?BrandStoreSettings $settings, Professional $affiliate, Professional $brand): void
{
    $job = new ProcessShopifyOrderWebhookJob(
        brandProfessionalId: $brandId,
        orderPayload: [],
        shopifyEventId: 'evt_test',
        source: 'webhook',
    );

    $method = (new ReflectionClass($job))->getMethod('dispatchInstantPayoutIfEligible');
    $method->setAccessible(true);
    $method->invoke($job, $settings, $affiliate, $brand);
}

it('dispatches the payout sweep when brand has hold=0 + PM and affiliate is active', function () {
    $brand = instantBrand();
    $affiliate = instantAffiliate();
    $settings = settingsRow($brand->id, 0);

    invokeDispatcher($brand->id, $settings, $affiliate, $brand);

    Queue::assertPushed(ProcessCommissionPayoutsJob::class);
});

it('does not dispatch when brand hold_days is 7', function () {
    $brand = instantBrand();
    $affiliate = instantAffiliate();
    $settings = settingsRow($brand->id, 7);

    invokeDispatcher($brand->id, $settings, $affiliate, $brand);

    Queue::assertNotPushed(ProcessCommissionPayoutsJob::class);
});

it('does not dispatch when brand has no settings row at all', function () {
    $brand = instantBrand();
    $affiliate = instantAffiliate();

    invokeDispatcher($brand->id, null, $affiliate, $brand);

    Queue::assertNotPushed(ProcessCommissionPayoutsJob::class);
});

it('does not dispatch when brand is missing a payment method even with hold=0', function () {
    $brand = instantBrand(['stripe_payment_method_id' => null]);
    $affiliate = instantAffiliate();
    $settings = settingsRow($brand->id, 0);

    invokeDispatcher($brand->id, $settings, $affiliate, $brand);

    Queue::assertNotPushed(ProcessCommissionPayoutsJob::class);
});

it('does not dispatch when affiliate Connect is not active', function () {
    $brand = instantBrand();
    $affiliate = instantAffiliate(['stripe_connect_status' => 'onboarding']);
    $settings = settingsRow($brand->id, 0);

    invokeDispatcher($brand->id, $settings, $affiliate, $brand);

    Queue::assertNotPushed(ProcessCommissionPayoutsJob::class);
});
