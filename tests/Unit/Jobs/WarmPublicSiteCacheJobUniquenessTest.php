<?php

use App\Jobs\Cache\WarmPublicSiteCacheJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;

it('is unique per lowered subdomain so cascaded warms coalesce to one rebuild', function () {
    $job = new WarmPublicSiteCacheJob('Mixed-CASE');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('mixed-case')
        ->and($job->uniqueFor)->toBe(120);
});
