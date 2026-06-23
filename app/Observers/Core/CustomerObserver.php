<?php

namespace App\Observers\Core;

use App\Models\Core\User\Customer;
use App\Services\Cache\UserCacheService;

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
        if (! empty($customer->user_id)) {
            $this->userCache->invalidateCustomerCount((string) $customer->user_id);
        }
    }
}
