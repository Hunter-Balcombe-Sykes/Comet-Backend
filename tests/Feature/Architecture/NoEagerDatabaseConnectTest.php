<?php

// No service provider may open a database connection while booting.
//
// DatabaseServiceProvider used to do exactly that — DB::connection()->getPdo()
// in boot(), to issue SET statement_timeout / SET lock_timeout once per
// connection. The SETs were correct; the eager connect was not. It ran in EVERY
// process that boots the framework: every PHP-FPM child, every queue worker,
// every artisan command, every scheduler tick — regardless of whether that
// process ever queried the database.
//
// Port 5432 is Supavisor's SESSION pooler, where a connection pins a pool slot
// for the life of the process. So each of those boots consumed a slot and held
// it. Measured on dev 2026-09-04: 17 of 23 pooled connections (73.9%) had
// executed nothing but those two SET statements — the pool was three-quarters
// full of processes that never asked the database for anything. That is the bulk
// of what produced the recurring EMAXCONNSESSION exhaustion.
//
// The timeouts now live on the app_backend ROLE (migration 20260905120000),
// applied by Postgres at backend startup, so nothing needs a connection at boot
// to install them.
//
// This guard is grep-shaped on purpose: a behavioural test cannot easily catch
// "a socket was opened during boot", and the regression is a one-line
// reintroduction that reads as reasonable.
//
// See docs/runbooks/db-pool-exhausted.md.

$forbidden = [
    'DB::connection()->getPdo(',
    'DB::getPdo(',
    '->getPdo()->exec(',
];

it('opens no database connection from a service provider boot', function () use ($forbidden) {
    $offenders = [];

    foreach (glob(base_path('app/Providers/*.php')) as $file) {
        $source = file_get_contents($file);

        foreach ($forbidden as $needle) {
            if (str_contains($source, $needle)) {
                $offenders[] = basename($file).' contains '.$needle;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps the retired DatabaseServiceProvider retired', function () {
    expect(file_exists(base_path('app/Providers/DatabaseServiceProvider.php')))->toBeFalse()
        ->and(file_get_contents(base_path('bootstrap/providers.php')))
        ->not->toContain('DatabaseServiceProvider');
});

it('does not put the timeouts back into the connection config', function () {
    // They belong to the role now. A config key here would be read by nothing
    // and would quietly disagree with the database.
    $pgsql = config('database.connections.pgsql');

    expect($pgsql)->not->toHaveKey('statement_timeout')
        ->and($pgsql)->not->toHaveKey('lock_timeout');
});
