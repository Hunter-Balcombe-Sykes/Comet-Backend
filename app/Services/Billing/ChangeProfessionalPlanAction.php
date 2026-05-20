<?php

namespace App\Services\Billing;

use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\StripeBillingService;
use Illuminate\Validation\ValidationException;

// V2: Changes professional's subscription plan. Handles free->paid (checkout), paid->paid (Stripe update), and paid->free (cancel + fallback).
class ChangeProfessionalPlanAction
{
    public function __construct(
        private StripeBillingService $billing,
        private Entitlements $entitlements,
    ) {}

    /**
     * Change the professional's current plan.
     * On paid→paid switches the Stripe price update happens FIRST; only once
     * Stripe confirms the change do we persist plan_id locally — so we never
     * show a plan the customer is not actually being billed for. The
     * customer.subscription.updated webhook remains the backup reconciliation
     * path (it also re-syncs plan_id, but gated on status=active).
     *
     * @return Subscription|array{checkout_url: string, session_id: string}
     */
    public function execute(Professional $professional, array $data): Subscription|array
    {
        $subscription = Subscription::query()
            ->where('professional_id', $professional->id)
            ->whereNull('ended_at')
            ->first();

        if (! $subscription) {
            throw ValidationException::withMessages([
                'subscription' => ['Professional has no active subscription.'],
            ]);
        }

        if (! $subscription->isActive() && ! $subscription->isInGracePeriod()) {
            throw ValidationException::withMessages([
                'subscription' => ['Subscription is not active.'],
            ]);
        }

        $newPlan = Plan::findOrFail($data['plan_id']);

        // Enforce plan authorization by professional type
        $type = $professional->professional_type;
        if ($type === 'brand' && $newPlan->plan_key !== 'brands' && $newPlan->plan_key !== 'free') {
            throw ValidationException::withMessages([
                'plan_id' => ['This plan is not available for brand accounts.'],
            ]);
        }
        if ($type !== 'brand' && $newPlan->plan_key === 'brands') {
            throw ValidationException::withMessages([
                'plan_id' => ['This plan is only available for brand accounts.'],
            ]);
        }

        if ($subscription->plan_id === $newPlan->id) {
            throw ValidationException::withMessages([
                'plan_id' => ['New plan is the same as current plan.'],
            ]);
        }

        // Free -> Paid: need Stripe Checkout (no payment method on file)
        if ($subscription->isFreeInternal()) {
            return $this->billing->createCheckoutSession(
                $professional,
                $newPlan,
                $data['success_url'],
                $data['cancel_url'],
            );
        }

        // Paid -> Free: cancel Stripe subscription, webhook handles free fallback
        if ($newPlan->plan_key === 'free') {
            $this->billing->cancelSubscriptionImmediately($subscription->stripe_subscription_id);

            // The webhook for customer.subscription.deleted will:
            // 1. Set ended_at on this subscription
            // 2. Create a free internal subscription for affiliates
            return $subscription->fresh();
        }

        // Paid -> Paid: Stripe FIRST, then persist locally.
        //
        // Ordering is deliberate and the opposite of the cancel/resume actions:
        // for a plan CHANGE the local plan_id must never lead Stripe. If we wrote
        // plan_id before Stripe confirmed, a failed Stripe call would leave the
        // customer seeing (and being entitled to) a plan they are not paying for.
        // So the Stripe price update must succeed before we touch the DB. If it
        // throws, the exception propagates and the local row is untouched.
        //
        // No DB transaction is needed — there is exactly one local write below,
        // and it only runs after the (non-transactional) Stripe call has returned.
        $this->billing->updateSubscriptionPlan(
            $subscription->stripe_subscription_id,
            $newPlan,
        );

        // Stripe confirmed the price change — persist plan_id immediately so the
        // UI and entitlement checks reflect reality without waiting on the webhook.
        // The customer.subscription.updated webhook stays as the backup path.
        $subscription->update(['plan_id' => $newPlan->id]);

        // Drop the per-request entitlement cache so any subsequent entitlement
        // check in this request resolves against the new plan.
        $this->entitlements->clearCache($professional->id);

        return $subscription->fresh();
    }
}
