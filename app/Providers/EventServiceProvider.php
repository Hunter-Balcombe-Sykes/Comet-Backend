<?php

namespace App\Providers;

use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\Site\SmartLink;
use App\Models\Core\User\Customer;
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use App\Models\Core\User\User;
use App\Observers\Core\BlockObserver;
use App\Observers\Core\CustomerObserver;
use App\Observers\Core\ServiceCategoryObserver;
use App\Observers\Core\ServiceObserver;
use App\Observers\Core\SiteMediaObserver;
use App\Observers\Core\SiteObserver;
use App\Observers\Core\SmartLinkObserver;
use App\Observers\User\UserObserver;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

// Registers Eloquent model observers for users, sites, blocks, services, customers, and media.
class EventServiceProvider extends ServiceProvider
{
    protected $listen = [];

    public function boot(): void
    {
        parent::boot();

        User::observe(UserObserver::class);
        Site::observe(SiteObserver::class);
        Block::observe(BlockObserver::class);
        Service::observe(ServiceObserver::class);
        ServiceCategory::observe(ServiceCategoryObserver::class);
        Customer::observe(CustomerObserver::class);
        SiteMedia::observe(SiteMediaObserver::class);
        SmartLink::observe(SmartLinkObserver::class);
    }
}
