<?php

use App\Providers\AppServiceProvider;
use App\Providers\BotProtectionServiceProvider;
use App\Providers\DatabaseServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\PlatformRegistryServiceProvider;

return [
    AppServiceProvider::class,
    DatabaseServiceProvider::class,
    EventServiceProvider::class,
    BotProtectionServiceProvider::class,
    PlatformRegistryServiceProvider::class,
];
