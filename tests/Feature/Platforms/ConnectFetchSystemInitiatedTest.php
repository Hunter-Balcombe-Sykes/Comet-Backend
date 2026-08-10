<?php

use App\Jobs\Platforms\ConnectFetchJob;

it('defaults to human-initiated so existing call sites are unchanged', function () {
    expect((new ConnectFetchJob('c1', 'fresha'))->systemInitiated)->toBeFalse();
});

it('can be constructed as system-initiated', function () {
    expect((new ConnectFetchJob('c1', 'fresha', systemInitiated: true))->systemInitiated)->toBeTrue();
});

it('keeps the unique id independent of the flag', function () {
    // uniqueId keys the ShouldBeUnique lock. If the flag leaked into it, an auto
    // dispatch and a dashboard connect for the same row would stop excluding
    // each other and both could write the payload.
    expect((new ConnectFetchJob('c1', 'fresha', systemInitiated: true))->uniqueId())
        ->toBe((new ConnectFetchJob('c1', 'fresha'))->uniqueId());
});

it('guards both notifier calls behind the flag', function () {
    $source = file_get_contents(base_path('app/Jobs/Platforms/ConnectFetchJob.php'));
    // Both success paths (the 304 short-circuit and the write path) must be gated.
    expect(substr_count($source, 'if (! $this->systemInitiated)'))->toBe(2);
});
