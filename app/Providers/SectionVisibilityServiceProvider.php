<?php

namespace App\Providers;

use App\Services\User\Visibility\Rules\BookingVisibility;
use App\Services\User\Visibility\Rules\ContactVisibility;
use App\Services\User\Visibility\Rules\CredentialsVisibility;
use App\Services\User\Visibility\Rules\DocumentsVisibility;
use App\Services\User\Visibility\Rules\ExperienceVisibility;
use App\Services\User\Visibility\Rules\GalleryVisibility;
use App\Services\User\Visibility\Rules\PublicContactVisibility;
use App\Services\User\Visibility\Rules\ServicesVisibility;
use App\Services\User\Visibility\Rules\WorkplaceVisibility;
use App\Services\User\Visibility\SectionVisibilityRegistry;
use Illuminate\Support\ServiceProvider;

// Binds the SectionVisibilityRegistry singleton and registers every section type's
// visibility rule. Single place a section visibility rule is declared. Mirrors
// PlatformRegistryServiceProvider. Section types with no data requirement
// (contacts_collection, sitepage_analytics, barbershop_info, newsletter, bio) get
// no rule and default to visible.
class SectionVisibilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SectionVisibilityRegistry::class, function () {
            $r = new SectionVisibilityRegistry;

            $r->register(new GalleryVisibility);
            $r->register(new DocumentsVisibility);
            $r->register(new ServicesVisibility);
            $r->register(new BookingVisibility);
            $r->register(new CredentialsVisibility);
            $r->register(new ExperienceVisibility);
            $r->register(new PublicContactVisibility);
            $r->register(new WorkplaceVisibility);
            $r->register(new ContactVisibility);

            return $r;
        });
    }
}
