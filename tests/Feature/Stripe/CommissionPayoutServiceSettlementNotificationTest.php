<?php

/** @phpstan-ignore-all */

use App\Models\Commerce\CommissionPayout;
use App\Models\Core\Professional\Professional;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Stripe\CommissionPayoutService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Tests for §28.10: payout_settlement notification dispatch from markCompleted().
// markCompleted() is private — tests reach it via markPaymentIntentSucceeded(),
// which is the public webhook entry point that calls it.

beforeEach(function () {
    Bus::fake();
    setupProfessionalsTable();
    setupCommerceOrdersTables();
    setupBrandStoreSettingsTable();

    $conn = DB::connection('pgsql');

    foreach ([
        'stripe_connect_account_id TEXT',
        'stripe_connect_status TEXT DEFAULT \'not_connected\'',
        'stripe_payment_method_id TEXT',
        'stripe_payment_method_brand TEXT',
        'stripe_payment_method_last4 TEXT',
        'payout_method TEXT',
        'display_name TEXT',
    ] as $col) {
        try {
            $conn->statement("ALTER TABLE core.professionals ADD COLUMN {$col}");
        } catch (\Throwable) {
        }
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payouts (
        id TEXT PRIMARY KEY,
        brand_professional_id TEXT,
        affiliate_professional_id TEXT,
        payment_intent_id TEXT,
        charge_id TEXT,
        status TEXT NOT NULL DEFAULT \'pending\',
        gross_commission_cents INTEGER NOT NULL DEFAULT 0,
        platform_fee_cents INTEGER NOT NULL DEFAULT 0,
        net_payout_cents INTEGER NOT NULL DEFAULT 0,
        currency_code TEXT NOT NULL DEFAULT \'AUD\',
        failure_reason TEXT,
        failure_code TEXT,
        ledger_entry_count INTEGER NOT NULL DEFAULT 0,
        eligible_after TEXT,
        processed_at TEXT,
        charge_cents INTEGER DEFAULT 0,
        retry_count INTEGER NOT NULL DEFAULT 0,
        needs_manual_refund INTEGER NOT NULL DEFAULT 0,
        void_at TEXT,
        transfer_completed_at TEXT,
        failure_category TEXT,
        grace_notifications_sent TEXT NOT NULL DEFAULT \'[]\',
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payout_items (
        id TEXT PRIMARY KEY,
        payout_id TEXT NOT NULL,
        order_id TEXT NOT NULL,
        amount_cents INTEGER NOT NULL DEFAULT 0
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        type TEXT,
        category TEXT,
        title TEXT,
        body TEXT,
        cta_url TEXT,
        primary_action_label TEXT,
        secondary_action_label TEXT,
        secondary_action_url TEXT,
        severity TEXT,
        starts_at TEXT,
        ends_at TEXT,
        dedupe_key TEXT,
        email_sent_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    try {
        $conn->statement(
            'CREATE UNIQUE INDEX notifications.notif_dedupe_settlement_uq
             ON notifications (professional_id, dedupe_key)
             WHERE dedupe_key IS NOT NULL'
        );
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notification_email_policies (
        id TEXT, professional_id TEXT, category_key TEXT, mode TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notification_email_preferences (
        id TEXT, professional_id TEXT, category_key TEXT, enabled INTEGER
    )');
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

function settl_createBrand(?string $id = null): Professional
{
    $id ??= (string) Str::uuid();

    return tap(new Professional([
        'id' => $id,
        'handle' => 'brand-s-'.substr($id, 0, 6),
        'display_name' => 'Settl Brand',
        'professional_type' => 'brand',
        'account_type' => 'brand',
        'stripe_connect_account_id' => 'acct_b_'.$id,
        'stripe_connect_status' => 'active',
        'stripe_payment_method_id' => 'pm_'.$id,
        'payout_method' => 'card',
    ]), fn (Professional $p) => $p->save());
}

function settl_createAffiliate(?string $id = null): Professional
{
    $id ??= (string) Str::uuid();

    return tap(new Professional([
        'id' => $id,
        'handle' => 'aff-s-'.substr($id, 0, 6),
        'display_name' => 'Settl Affiliate',
        'professional_type' => 'affiliate',
        'account_type' => 'partner',
        'stripe_connect_account_id' => 'acct_a_'.$id,
        'stripe_connect_status' => 'active',
    ]), fn (Professional $p) => $p->save());
}

function settl_createPayout(Professional $brand, Professional $aff, array $overrides = []): CommissionPayout
{
    return tap((new CommissionPayout)->forceFill(array_merge([
        'id' => (string) Str::uuid(),
        'brand_professional_id' => $brand->id,
        'affiliate_professional_id' => $aff->id,
        'status' => 'processing',
        'gross_commission_cents' => 5000,
        'platform_fee_cents' => 150,
        'net_payout_cents' => 4850,
        'currency_code' => 'AUD',
        'ledger_entry_count' => 2,
        'payment_intent_id' => 'pi_test_'.Str::random(8),
        'void_at' => now()->addDays(60),
        'retry_count' => 0,
    ], $overrides)), fn (CommissionPayout $p) => $p->save());
}

// ─── Tests ───────────────────────────────────────────────────────────────────

it('markCompleted dispatches payout_settlement notification with correct dedupe key', function () {
    $brand = settl_createBrand();
    $aff = settl_createAffiliate();
    $payout = settl_createPayout($brand, $aff);

    $capturedArgs = [];
    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')
        ->once()
        ->withArgs(function () use (&$capturedArgs): bool {
            $capturedArgs = func_get_args();

            // Named-args arrive positionally in withArgs: professionalId, frontendType, category, title, body, dedupeKey...
            return true;
        });

    $svc = new CommissionPayoutService(null, $publisher);
    // markPaymentIntentSucceeded is the public entry point for payment_intent.succeeded webhooks.
    $svc->markPaymentIntentSucceeded($payout, 'ch_test');

    expect($capturedArgs)->not->toBeEmpty();
    // Key assertions on named args (positional in the variadic call):
    expect($capturedArgs['professionalId'] ?? $capturedArgs[0])->toBe($aff->id);
    expect($capturedArgs['category'] ?? $capturedArgs[2])->toBe('payout_settlement');
    expect($capturedArgs['dedupeKey'] ?? $capturedArgs[5])->toBe("payout_settlement.{$payout->id}");
    expect($capturedArgs['ctaUrl'] ?? $capturedArgs[6] ?? null)->toBe('/account/payouts');
});

it('markCompleted still saves payout as completed when publish throws', function () {
    Log::spy();

    $brand = settl_createBrand();
    $aff = settl_createAffiliate();
    $payout = settl_createPayout($brand, $aff);

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->andThrow(new \RuntimeException('redis down'));

    $svc = new CommissionPayoutService(null, $publisher);
    $svc->markPaymentIntentSucceeded($payout, 'ch_test');

    // Payout is still marked completed despite the publish exception.
    $payout->refresh();
    expect($payout->status)->toBe('completed');

    Log::shouldHaveReceived('warning')
        ->atLeast()->once()
        ->withArgs(fn (string $msg) => str_contains($msg, 'payout_settlement notification'));
});
