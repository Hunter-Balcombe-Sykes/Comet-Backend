<?php

use App\Providers\AppServiceProvider;
use App\Providers\BotProtectionServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\PlatformRegistryServiceProvider;
use App\Providers\RedisBreakerServiceProvider;
use App\Providers\SectionVisibilityServiceProvider;

return [
    AppServiceProvider::class,
    EventServiceProvider::class,
    BotProtectionServiceProvider::class,
    PlatformRegistryServiceProvider::class,
    SectionVisibilityServiceProvider::class,
    RedisBreakerServiceProvider::class,
];
