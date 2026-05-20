<?php

use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;
use App\Services\Billing\ChangeProfessionalPlanAction;
use App\Services\Billing\Entitlements;
use App\Services\Stripe\StripeBillingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// -------------------------------------------------------------------
// Schema bootstrap
// -------------------------------------------------------------------

beforeEach(function () {
    attachTestSchemas();
    setupProfessionalsTable();

    $conn = DB::connection('pgsql');

    $conn->statement('CREATE TABLE IF NOT EXISTS billing.plans (
        id TEXT PRIMARY KEY,
        plan_key TEXT NOT NULL,
        name TEXT NULL,
        description TEXT NULL,
        stripe_price_id TEXT NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NULL,
        price_cents INTEGER NULL,
        currency_code TEXT NULL,
        billing_interval TEXT NULL,
        entitlements TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS billing.subscriptions (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        plan_id TEXT NOT NULL,
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

// -------------------------------------------------------------------
// Shared factory helpers
// -------------------------------------------------------------------

/**
 * Insert a professional row and return the model.
 */
function planTestProfessional(string $type = 'affiliate'): Professional
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => "pro-{$id}",
        'handle_lc' => "pro-{$id}",
        'display_name' => "Test Pro {$id}",
        'professional_type' => $type,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Professional::find($id);
}

/**
 * Insert a billing plan row and return the model.
 */
function planTestPlan(string $key, string $priceId = 'price_abc'): Plan
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('billing.plans')->insert([
        'id' => $id,
        'plan_key' => $key,
        'name' => ucfirst($key),
        'stripe_price_id' => $priceId,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Plan::find($id);
}

/**
 * Insert a subscription row and return the model.
 */
function planTestSubscription(Professional $professional, Plan $plan, string $stripeSubId = 'sub_test123'): Subscription
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('billing.subscriptions')->insert([
        'id' => $id,
        'professional_id' => $professional->id,
        'plan_id' => $plan->id,
        'provider' => 'stripe',
        'stripe_subscription_id' => $stripeSubId,
        'status' => 'active',
        'cancel_at_period_end' => 0,
        'ended_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Subscription::find($id);
}

// -------------------------------------------------------------------
// Tests — TRNX-5: paid→paid is Stripe-FIRST, then local plan_id write
// -------------------------------------------------------------------

it('paid→paid: calls Stripe updateSubscriptionPlan then persists plan_id locally', function () {
    $professional = planTestProfessional('affiliate');
    $currentPlan = planTestPlan('starter', 'price_starter');
    $newPlan = planTestPlan('growth', 'price_growth');
    $subscription = planTestSubscription($professional, $currentPlan);

    $billing = Mockery::mock(StripeBillingService::class);
    $billing->shouldReceive('updateSubscriptionPlan')
        ->once()
        ->with($subscription->stripe_subscription_id, Mockery::on(fn ($p) => $p->id === $newPlan->id))
        // Ordering proof: at the moment Stripe is called, the local plan_id must
        // still be the OLD plan — the new plan_id is persisted only after this
        // call returns. A write-before-Stripe regression fails here.
        ->andReturnUsing(function () use ($subscription, $currentPlan) {
            expect($subscription->fresh()->plan_id)->toBe($currentPlan->id);

            // updateSubscriptionPlan declares a Stripe\Subscription return type;
            // return a stub so the mock satisfies it.
            return new \Stripe\Subscription($subscription->stripe_subscription_id);
        });

    $action = new ChangeProfessionalPlanAction($billing, new Entitlements);
    $result = $action->execute($professional, ['plan_id' => $newPlan->id]);

    // plan_id is written locally only AFTER Stripe confirmed the price change.
    expect($subscription->fresh()->plan_id)->toBe($newPlan->id);

    $result = $result instanceof Subscription ? $result : null;
    expect($result)->toBeInstanceOf(Subscription::class);
    expect($result->id)->toBe($subscription->id);
    expect($result->plan_id)->toBe($newPlan->id);
});

it('paid→paid: does NOT persist plan_id when the Stripe call fails (no plan they are not paying for)', function () {
    // TRNX-5: ordering invariant — Stripe must succeed before plan_id is written.
    // A failed Stripe call must leave the local plan_id untouched, otherwise the
    // customer would be entitled to a plan they are not being billed for.
    $professional = planTestProfessional('affiliate');
    $currentPlan = planTestPlan('starter', 'price_starter');
    $newPlan = planTestPlan('growth', 'price_growth');
    $subscription = planTestSubscription($professional, $currentPlan);

    $billing = Mockery::mock(StripeBillingService::class);
    $billing->shouldReceive('updateSubscriptionPlan')
        ->once()
        ->andThrow(new \RuntimeException('Stripe API error'));

    $action = new ChangeProfessionalPlanAction($billing, new Entitlements);

    expect(fn () => $action->execute($professional, ['plan_id' => $newPlan->id]))
        ->toThrow(\RuntimeException::class, 'Stripe API error');

    // Local plan_id stays on the old plan — never lead Stripe.
    expect($subscription->fresh()->plan_id)->toBe($currentPlan->id);
});

it('paid→paid: does not reset cancel_at_period_end locally', function () {
    $professional = planTestProfessional('affiliate');
    $currentPlan = planTestPlan('starter', 'price_starter');
    $newPlan = planTestPlan('growth', 'price_growth');
    $subscription = planTestSubscription($professional, $currentPlan);

    // Simulate a subscription already marked for cancellation at period end
    DB::connection('pgsql')->table('billing.subscriptions')
        ->where('id', $subscription->id)
        ->update(['cancel_at_period_end' => 1]);

    $billing = Mockery::mock(StripeBillingService::class);
    $billing->shouldReceive('updateSubscriptionPlan')
        ->once()
        ->with($subscription->stripe_subscription_id, Mockery::on(fn ($p) => $p->id === $newPlan->id));

    $action = new ChangeProfessionalPlanAction($billing, new Entitlements);
    $action->execute($professional, ['plan_id' => $newPlan->id]);

    // cancel_at_period_end must remain true — webhook will reconcile it
    expect($subscription->fresh()->cancel_at_period_end)->toBeTrue();
});
