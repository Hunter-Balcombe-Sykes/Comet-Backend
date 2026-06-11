<?php

use App\Providers\AppServiceProvider;
use App\Providers\BotProtectionServiceProvider;
use App\Providers\DatabaseServiceProvider;
use App\Providers\EventServiceProvider;

return [
    AppServiceProvider::class,
    DatabaseServiceProvider::class,
    EventServiceProvider::class,
    BotProtectionServiceProvider::class,
];
