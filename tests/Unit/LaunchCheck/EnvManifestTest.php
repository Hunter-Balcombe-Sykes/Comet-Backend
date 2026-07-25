<?php

use App\Support\LaunchCheck\EnvManifest;

// Closure, not a `function` declaration: Pest loads every test file into the
// GLOBAL namespace, so a plain `function fullValues()` here would fatally
// redeclare against any same-named helper added anywhere else in the suite.
$fullValues = function (array $overrides = []): array {
    $base = [];
    foreach (EnvManifest::REQUIRED as $k) {
        $base[$k] = 'set';
    }
    foreach (EnvManifest::EXPECTED as $k => $v) {
        $base[$k] = $v;
    }

    return array_merge($base, $overrides);
};

it('passes when everything is present and correct', function () use ($fullValues) {
    $r = EnvManifest::evaluate($fullValues(), 'launch');
    expect($r['fail'])->toBe([])->and($r['warn'])->toBe([]);
});

it('fails a missing required secret on any target', function () use ($fullValues) {
    $r = EnvManifest::evaluate($fullValues(['app.key' => null]), 'pilot');
    expect($r['fail'])->toContain('missing: app.key');
});

it('fails queue=sync at launch (the inline-jobs incident)', function () use ($fullValues) {
    $r = EnvManifest::evaluate($fullValues(['queue.default' => 'sync']), 'launch');
    expect($r['fail'])->toContain('queue.default = sync (want redis)');
});

it('only warns on queue=sync at pilot (dev legitimately deviates)', function () use ($fullValues) {
    $r = EnvManifest::evaluate($fullValues(['queue.default' => 'sync']), 'pilot');
    expect($r['fail'])->toBe([]);
    expect($r['warn'])->toContain('queue.default = sync (want redis) — expected deviation on dev?');
});

it('fails APP_DEBUG on at launch', function () use ($fullValues) {
    $r = EnvManifest::evaluate($fullValues(['app.debug' => true]), 'launch');
    expect($r['fail'])->toContain('app.debug = true (want false)');
});
