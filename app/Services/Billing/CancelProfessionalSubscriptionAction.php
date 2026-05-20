<?php

namespace App\Services\Billing;

use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\StripeBillingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

// V2: Cancels subscription at billing period end. Stripe-managed subs call Stripe API; free plans are rejected.
class CancelProfessionalSubscriptionAction
{
    public function __construct(private StripeBillingService $billing) {}

    public function execute(Professional $professional): Subscription
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

        if (! $subscription->isActive()) {
            throw ValidationException::withMessages([
                'subscription' => ['Subscription is not active.'],
            ]);
        }

        if ($subscription->isFreeInternal()) {
            throw ValidationException::withMessages([
                'subscription' => ['Free subscriptions cannot be canceled.'],
            ]);
        }

        // Local-first, then Stripe. A cancel-at-period-end is intent-driven: the
        // user wants the subscription to stop renewing, and that intent is fully
        // captured by the local cancel_at_period_end=true. This is a single-row
        // update, so no explicit DB transaction is needed.
        $subscription->update([
            'cancel_at_period_end' => true,
        ]);

        // Push the cancellation to Stripe after the local write. The Stripe call
        // must NOT sit inside a DB transaction. If it fails we do NOT throw —
        // local state is already committed as the source of intent and the
        // customer.subscription.updated webhook reconciles cancel_at_period_end.
        try {
            $this->billing->cancelSubscriptionAtPeriodEnd($subscription->stripe_subscription_id);
        } catch (\Throwable $e) {
            Log::warning('billing.cancel.stripe_call_failed', [
                'subscription_id' => $subscription->id,
                'professional_id' => $professional->id,
                'stripe_subscription_id' => $subscription->stripe_subscription_id,
                'error' => $e->getMessage(),
            ]);

            // Money-affecting silent failure: the local cancel committed but
            // Stripe still believes the subscription renews, and there is no
            // self-healing path (no Stripe state change → no reconciling
            // webhook). report() so Nightwatch alerts an operator — Log::warning
            // alone is breadcrumb-only and would never page anyone.
            report($e);
        }

        return $subscription->fresh();
    }
}
