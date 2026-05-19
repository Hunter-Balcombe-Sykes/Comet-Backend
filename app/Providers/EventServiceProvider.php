<?php

namespace App\Providers;

use App\Events\Accounts\AccountTypeTransitionEvent;
use App\Listeners\Accounts\InvalidateProfessionalCacheOnTransition;
use App\Listeners\Accounts\LogAccountTypeTransition;
use App\Models\Brand\BrandStoreSettings;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Commerce\CommissionMovement;
use App\Models\Commerce\CommissionPayout;
use App\Models\Core\Professional\BrandAffiliateInvite;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Customer;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Models\Core\Professional\Service;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Observers\Brand\BrandStoreSettingsObserver;
use App\Observers\Commerce\AffiliateProductSelectionObserver;
use App\Observers\Core\BlockObserver;
use App\Observers\Core\BrandAffiliateInviteObserver;
use App\Observers\Core\BrandPartnerLinkObserver;
use App\Observers\Core\BrandProfileObserver;
use App\Observers\Core\CommissionMovementObserver;
use App\Observers\Core\CommissionPayoutObserver;
use App\Observers\Core\CustomerObserver;
use App\Observers\Core\ProfessionalIntegrationObserver;
use App\Observers\Core\ServiceObserver;
use App\Observers\Core\SiteMediaObserver;
use App\Observers\Core\SiteObserver;
use App\Observers\Professional\ProfessionalObserver;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

// V2: Registers Eloquent model observers for professionals, sites, blocks, services, customers, integrations, commissions, and media.
class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        AccountTypeTransitionEvent::class => [
            InvalidateProfessionalCacheOnTransition::class,
            LogAccountTypeTransition::class,
        ],
    ];

    public function boot(): void
    {
        Professional::observe(ProfessionalObserver::class);
        Site::observe(SiteObserver::class);
        Block::observe(BlockObserver::class);
        Service::observe(ServiceObserver::class);
        Customer::observe(CustomerObserver::class);
        BrandAffiliateInvite::observe(BrandAffiliateInviteObserver::class);
        BrandPartnerLink::observe(BrandPartnerLinkObserver::class);
        CommissionMovement::observe(CommissionMovementObserver::class);
        CommissionPayout::observe(CommissionPayoutObserver::class);
        ProfessionalIntegration::observe(ProfessionalIntegrationObserver::class);
        BrandProfile::observe(BrandProfileObserver::class);
        BrandStoreSettings::observe(BrandStoreSettingsObserver::class);
        SiteMedia::observe(SiteMediaObserver::class);
        AffiliateProductSelection::observe(AffiliateProductSelectionObserver::class);
    }
}
