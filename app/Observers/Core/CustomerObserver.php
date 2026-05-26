<?php

namespace App\Observers\Core;

use App\Models\Core\User\Customer;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;

// V2: Invalidates customer count cache on any customer change (create, update, delete, restore).
class CustomerObserver
{
    public bool $afterCommit = true;

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
            $key = CacheKeyGenerator::customerCount((string) $customer->user_id);
            Cache::forget($key);
            Cache::forget($key.':stale');
        }
    }
}
