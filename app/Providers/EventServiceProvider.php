<?php

namespace App\Providers;

use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\User;
use App\Models\Core\Professional\Service;
use App\Models\Core\Professional\ServiceCategory;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Observers\Core\BlockObserver;
use App\Observers\Core\CustomerObserver;
use App\Observers\Core\ServiceCategoryObserver;
use App\Observers\Core\ServiceObserver;
use App\Observers\Core\SiteMediaObserver;
use App\Observers\Core\SiteObserver;
use App\Observers\Professional\ProfessionalObserver;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

// Registers Eloquent model observers for users, sites, blocks, services, customers, and media.
class EventServiceProvider extends ServiceProvider
{
    protected $listen = [];

    public function boot(): void
    {
        User::observe(ProfessionalObserver::class);
        Site::observe(SiteObserver::class);
        Block::observe(BlockObserver::class);
        Service::observe(ServiceObserver::class);
        ServiceCategory::observe(ServiceCategoryObserver::class);
        Customer::observe(CustomerObserver::class);
        SiteMedia::observe(SiteMediaObserver::class);
    }
}
