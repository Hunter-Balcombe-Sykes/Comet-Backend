<?php

use App\Models\Core\Professional\Professional;
use App\Services\Billing\CancelProfessionalSubscriptionAction;
use App\Services\Stripe\StripeBillingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS billing.subscriptions (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        plan_id TEXT NOT NULL DEFAULT \'plan-1\',
        provider TEXT NOT NULL DEFAULT \'stripe\',
        stripe_customer_id TEXT NULL,
        stripe_subscription_id TEXT NULL,
        status TEXT NOT NULL DEFAULT \'active\',
        current_period_start TEXT NULL,
        current_period_end TEXT NULL,
        cancel_at_period_end INTEGER NOT NULL DEFAULT 0,
        trial_ends_at TEXT NULL,
        ended_at TEXT NULL,
        provider_payload TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');
});

function cancelTestProfessional(): Professional
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'primary_email' => 'pro@example.com',
        'status' => 'active',
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    return Professional::query()->where('id', $id)->first();
}

function seedCancelSubscription(string $professionalId, array $overrides = []): void
{
    DB::connection('pgsql')->table('billing.subscriptions')->insert(array_merge([
        'id' => (string) Str::uuid(),
        'professional_id' => $professionalId,
        'provider' => 'stripe',
        'stripe_subscription_id' => 'sub_test_123',
        'status' => 'active',
        'cancel_at_period_end' => 0,
        'current_period_end' => now()->addDays(10)->toIso8601String(),
        'ended_at' => null,
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ], $overrides));
}

it('sets cancel_at_period_end locally then calls Stripe', function () {
    $pro = cancelTestProfessional();
    seedCancelSubscription($pro->id);

    $billing = Mockery::mock(StripeBillingService::class);
    $billing->shouldReceive('cancelSubscriptionAtPeriodEnd')
        ->once()
        ->andReturn(Mockery::mock(\Stripe\Subscription::class));

    $returned = (new CancelProfessionalSubscriptionAction($billing))->execute($pro);

    expect($returned->cancel_at_period_end)->toBeTrue();

    $row = DB::connection('pgsql')
        ->table('billing.subscriptions')
        ->where('professional_id', $pro->id)
        ->first();
    expect((bool) $row->cancel_at_period_end)->toBeTrue();
});

it('commits the local update before calling Stripe (Stripe call is post-commit)', function () {
    // TRNX-4: proves the Stripe call is NOT inside an open DB transaction —
    // by the time the Stripe call runs, the local row is already persisted.
    $pro = cancelTestProfessional();
    seedCancelSubscription($pro->id);

    $billing = Mockery::mock(StripeBillingService::class);
    $billing->shouldReceive('cancelSubscriptionAtPeriodEnd')
        ->once()
        ->andReturnUsing(function () use ($pro) {
            $row = DB::connection('pgsql')
                ->table('billing.subscriptions')
                ->where('professional_id', $pro->id)
                ->first();
            expect((bool) $row->cancel_at_period_end)->toBeTrue();

            return Mockery::mock(\Stripe\Subscription::class);
        });

    (new CancelProfessionalSubscriptionAction($billing))->execute($pro);
});

it('keeps the local cancellation and does not throw when Stripe fails (webhook reconciles)', function () {
    // TRNX-4: a post-commit Stripe failure must not throw — local state is the
    // source of intent and the customer.subscription.updated webhook reconciles.
    $pro = cancelTestProfessional();
    seedCancelSubscription($pro->id);

    $billing = Mockery::mock(StripeBillingService::class);
    $billing->shouldReceive('cancelSubscriptionAtPeriodEnd')
        ->once()
        ->andThrow(new \RuntimeException('Stripe API error'));

    $returned = (new CancelProfessionalSubscriptionAction($billing))->execute($pro);
    expect($returned->cancel_at_period_end)->toBeTrue();

    $row = DB::connection('pgsql')
        ->table('billing.subscriptions')
        ->where('professional_id', $pro->id)
        ->first();
    expect((bool) $row->cancel_at_period_end)->toBeTrue();
});

it('rejects cancellation of a free internal subscription', function () {
    $pro = cancelTestProfessional();
    seedCancelSubscription($pro->id, ['provider' => 'internal', 'stripe_subscription_id' => null]);

    $billing = Mockery::mock(StripeBillingService::class);
    $billing->shouldNotReceive('cancelSubscriptionAtPeriodEnd');

    expect(fn () => (new CancelProfessionalSubscriptionAction($billing))->execute($pro))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
