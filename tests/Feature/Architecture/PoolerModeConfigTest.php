<?php

// Pins the one-flip contract for the Supavisor pooler mode.
//
// Port 5432 is the SESSION pooler: a slot is pinned per PROCESS for the life of
// that process, so Horizon's daemons hold ~15 of dev's 30 before a request
// lands, and a dashboard burst exhausts it (EMAXCONNSESSION —
// docs/runbooks/db-pool-exhausted.md). Port 6543 is the TRANSACTION pooler,
// where a backend is borrowed per transaction; that is the standing fix.
//
// Transaction mode cannot carry server-side prepared statements, because the
// second half of the exchange may reach a backend that never saw the first. So
// config/database.php derives PDO::ATTR_EMULATE_PREPARES from the port instead
// of exposing a second flag that could be set inconsistently — the failure mode
// of a mismatch is a silent wrong-backend error, not a loud one.
//
// This test exists so that coupling cannot be quietly unpicked: someone adding
// their own `options` array, or "tidying" the derivation into a hardcoded
// false, would otherwise leave a port flip looking complete and failing under
// load only.
//
// What it does NOT check: that transaction mode actually works end-to-end. The
// suite runs SQLite, which has no pooler, no search_path and no timeouts — see
// the runbook's checklist for what has to be watched on dev after the flip.

use Illuminate\Support\Env;

/** Re-evaluates config/database.php with DB_PORT overridden, since config is read once at boot. */
function pgsqlConfigWithPort(string $port): array
{
    $repository = Env::getRepository();
    $previous = $repository->get('DB_PORT');

    $repository->set('DB_PORT', $port);

    try {
        $config = require base_path('config/database.php');
    } finally {
        $previous === null
            ? $repository->clear('DB_PORT')
            : $repository->set('DB_PORT', $previous);
    }

    return $config['connections']['pgsql'];
}

it('leaves prepared statements native on the session pooler', function () {
    $pgsql = pgsqlConfigWithPort('5432');

    expect($pgsql['port'])->toBe('5432')
        ->and($pgsql['options'][PDO::ATTR_EMULATE_PREPARES])->toBeFalse();
});

it('emulates prepared statements on the transaction pooler', function () {
    $pgsql = pgsqlConfigWithPort('6543');

    expect($pgsql['port'])->toBe('6543')
        ->and($pgsql['options'][PDO::ATTR_EMULATE_PREPARES])->toBeTrue();
});

it('keeps the emulation derived from the port, not a separate flag', function () {
    $source = file_get_contents(base_path('config/database.php'));

    expect($source)->toContain('PDO::ATTR_EMULATE_PREPARES');

    // A literal true/false, or a flag of its own, breaks the single-flip
    // contract this file exists to protect.
    expect($source)->not->toMatch('/ATTR_EMULATE_PREPARES\s*=>\s*(true|false)\b/')
        ->and($source)->not->toContain('DB_EMULATE_PREPARES');
});
