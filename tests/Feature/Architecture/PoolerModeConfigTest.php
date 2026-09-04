<?php

// Keeps PDO::ATTR_EMULATE_PREPARES OFF for the pgsql connection.
//
// It was switched ON (derived from DB_PORT) on 2026-09-04 as groundwork for
// moving off the session pooler, and measured on dev the same day. It breaks
// the app: emulation makes PDO interpolate bound values itself, a PHP `true`
// interpolates as `1`, and Postgres refuses `boolean = integer` —
//
//   SQLSTATE[42883] operator does not exist: boolean = integer
//   ... "platform_connections"."user_id" and "is_active" = 1 ...
//
// Every `->where('some_bool', true)` in the codebase is affected. Worse, the
// content lane fails open, so the damage showed as `pools: {}` on every public
// profile — an empty sitepage served with a 200, not an error page.
//
// Laravel's own connector already defaults this key to false
// (Connector::$options), so the correct state is simply not to set it. This
// test exists because the wrong value shipped once already and reads as a
// plausible, well-commented improvement.
//
// This does NOT say transaction mode is abandoned — see the runbook. It says
// emulation is not the road to it.

it('never enables emulated prepares on the pgsql connection', function () {
    $options = config('database.connections.pgsql.options', []);

    expect($options[PDO::ATTR_EMULATE_PREPARES] ?? false)->toBeFalse();
});

it('keeps the config free of an emulation toggle', function () {
    $source = file_get_contents(base_path('config/database.php'));

    // The comment block naming the failure is welcome; a live assignment is not.
    expect($source)->not->toMatch('/^\s*PDO::ATTR_EMULATE_PREPARES\s*=>/m')
        ->and($source)->not->toContain('DB_EMULATE_PREPARES');
});
