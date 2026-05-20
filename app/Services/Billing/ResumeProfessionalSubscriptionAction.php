<?php

namespace App\Services\Billing;

use App\Models\Billing\Subscription;
use App\Models\Core\Professional\Professional;
use App\Services\Stripe\StripeBillingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

// V2: Resumes a subscription scheduled for cancellation. Clears cancel_at_period_end on both Stripe and local DB.
class ResumeProfessionalSubscriptionAction
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
                'subscription' => ['No subscription to resume.'],
            ]);
        }

        if (! $subscription->isActive()) {
            throw ValidationException::withMessages([
                'subscription' => ['Subscription is no longer active and cannot be resumed.'],
            ]);
        }

        if (! $subscription->cancel_at_period_end) {
            throw ValidationException::withMessages([
                'subscription' => ['Subscription is not scheduled for cancellation.'],
            ]);
        }

        if ($subscription->current_period_end && $subscription->current_period_end->isPast()) {
            throw ValidationException::withMessages([
                'subscription' => ['Subscription period has already ended.'],
            ]);
        }

        // Local-first, then Stripe. Resuming is intent-driven: the user wants the
        // subscription to keep renewing, and that intent is fully captured by the
        // local cancel_at_period_end=false. The Stripe call must NOT sit inside a
        // DB transaction — a network call would hold the transaction open across
        // latency, and a rollback could never undo the Stripe-side change anyway.
        // It is a single-row update, so no explicit transaction is needed.
        $subscription->update(['cancel_at_period_end' => false]);

        // Push the same change to Stripe after the local write. If this fails we
        // do NOT throw — local state is the source of intent and the
        // customer.subscription.updated webhook reconciles cancel_at_period_end.
        if ($subscription->isStripeManaged() && $subscription->stripe_subscription_id) {
            try {
                $this->billing->resumeSubscription($subscription->stripe_subscription_id);
            } catch (\Throwable $e) {
                Log::warning('billing.resume.stripe_call_failed', [
                    'subscription_id' => $subscription->id,
                    'professional_id' => $professional->id,
                    'stripe_subscription_id' => $subscription->stripe_subscription_id,
                    'error' => $e->getMessage(),
                ]);

                // Silent Stripe divergence: local state says "renewing" but
                // Stripe may still be set to cancel. No self-healing path, so
                // report() to surface it on Nightwatch — Log::warning is
                // breadcrumb-only and never alerts.
                report($e);
            }
        }

        return $subscription->fresh();
    }
}
