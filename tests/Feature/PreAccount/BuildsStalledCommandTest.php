<?php

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
});

it('lists a stalled build', function () {
    [$user, $site, $build] = makeReadyBuild('stalledone');
    $build->forceFill(['setup_stalled_at' => now()])->save();

    $this->artisan('builds:stalled')
        ->expectsOutputToContain('stalledone')
        ->assertExitCode(0);
});

it('does not list a settled build', function () {
    [$user, $site, $build] = makeReadyBuild('finework');
    $build->forceFill(['settled_at' => now()])->save();

    $this->artisan('builds:stalled')
        ->doesntExpectOutputToContain('finework')
        ->assertExitCode(0);
});

it('honours the hours window', function () {
    [$user, $site, $build] = makeReadyBuild('ancient');
    $build->forceFill(['setup_stalled_at' => now()->subDays(5)])->save();

    $this->artisan('builds:stalled', ['--hours' => 24])
        ->doesntExpectOutputToContain('ancient')
        ->assertExitCode(0);
});
