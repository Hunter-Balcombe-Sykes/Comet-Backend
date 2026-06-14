<?php

use App\Jobs\Cache\WarmPublicSiteCacheJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Tests\TestCase;

// Boot the app: the constructor resolves its queue via config('partna.queues.cache_warm')
// (B15/CFG-9), which needs the container bound — a bare unit instantiation can't.
uses(TestCase::class);

it('is unique per lowered subdomain so cascaded warms coalesce to one rebuild', function () {
    $job = new WarmPublicSiteCacheJob('Mixed-CASE');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('mixed-case')
        ->and($job->uniqueFor)->toBe(120);
});
