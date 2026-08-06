<?php

namespace App\Observers\Core;

use App\Models\Core\User\Customer;
use App\Services\Cache\UserCacheService;
use Illuminate\Support\Facades\Log;
use Throwable;

// V2: Invalidates customer count cache on any customer change (create, update, delete, restore).
// Uses UserCacheService::invalidateCustomerCount() to stay within the GS-1 service-layer rule
// (no raw Cache:: calls outside cache services).
class CustomerObserver
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly UserCacheService $userCache,
    ) {}

    public function created(Customer $customer): void
    {
        $this->invalidateCount($customer);
    }

    public function updated(Customer $customer): void
    {
        $this->invalidateCount($customer);
    }

    public function deleted(Customer $customer): void
    {
        $this->invalidateCount($customer);
    }

    public function restored(Customer $customer): void
    {
        $this->invalidateCount($customer);
    }

    private function invalidateCount(Customer $customer): void
    {
        if (empty($customer->user_id)) {
            return;
        }

        // Best-effort, mirroring MenuItemObserver::sync()/deleted(). This fires
        // synchronously on PublicEnquiryController::submit()'s customer upsert,
        // BEFORE $enquiry->save() — an unguarded throw here (dead cache store)
        // used to turn a lead that should have been saved into a raw 500 with
        // nothing persisted (Finding 1, 2026-08-06 final review; drill 03).
        // Swallowing is correct: when the store is unreachable there is no live
        // cache entry that can go stale, TTL is the backstop for everything
        // else, and a failed *invalidation* must never cost the visitor their
        // enquiry.
        try {
            $this->userCache->invalidateCustomerCount((string) $customer->user_id);
        } catch (Throwable $e) {
            Log::warning('customer.count_invalidation_failed', [
                'user_id' => (string) $customer->user_id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
