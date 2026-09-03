<?php

use App\Models\Core\User\PreAccountBuild;
use Carbon\CarbonInterface;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
});

it('casts the three settle stamps to datetimes', function () {
    [$user, $site, $build] = makeReadyBuild();
    $build->forceFill([
        'settled_at' => now(),
        'setup_stalled_at' => now(),
        'welcomed_at' => now(),
    ])->save();

    $fresh = $build->fresh();
    expect($fresh->settled_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($fresh->setup_stalled_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($fresh->welcomed_at)->toBeInstanceOf(CarbonInterface::class);
});

// SEC-4: state columns must not be mass-assignable. A silently dropped write
// here would strand a build unwelcomed with no error.
it('refuses to mass-assign the settle stamps', function () {
    $build = new PreAccountBuild([
        'settled_at' => now(),
        'setup_stalled_at' => now(),
        'welcomed_at' => now(),
    ]);

    expect($build->settled_at)->toBeNull()
        ->and($build->setup_stalled_at)->toBeNull()
        ->and($build->welcomed_at)->toBeNull();
});
