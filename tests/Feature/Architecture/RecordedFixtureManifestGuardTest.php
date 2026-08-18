<?php

use App\Support\Fixtures\FixtureManifest;

// A recorded fixture nobody registered — or one somebody hand-edited — must not
// pass silently: the manifest is what makes a capture traceable (source URL,
// date, hash). Orphans and hash mismatches are both red here.
it('every file under tests/fixtures/recorded is registered in MANIFEST.json with a matching hash', function () {
    $root = base_path('tests/fixtures/recorded');
    $problems = (new FixtureManifest($root.'/MANIFEST.json'))->verify($root);

    expect($problems)->toBeEmpty(
        "tests/fixtures/recorded/ and MANIFEST.json disagree — run `php artisan fixtures:verify` and either\n"
        ."re-capture with `fixtures:capture` or register the file by hand:\n - ".implode("\n - ", $problems),
    );
});
