<?php

namespace App\Jobs\Concerns;

trait HasCloudflareRetryPolicy
{
    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    // Short-circuit permanent failures (e.g. revoked token) so failed()/Nightwatch fires after 2 attempts, not 3.
    public int $maxExceptions = 2;
}
