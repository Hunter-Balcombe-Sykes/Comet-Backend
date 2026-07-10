<?php

/** @phpstan-ignore-all */

// OV-H: the prune command removes expired NON-critical notifications and leaves
// critical + unexpired rows alone.

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Config::set('database.connections.pgsql', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
    DB::purge('pgsql');
    DB::reconnect('pgsql');

    $conn = DB::connection('pgsql');
    try {
        $conn->statement("ATTACH DATABASE ':memory:' AS notifications");
    } catch (Throwable $e) {
        // already attached
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        type TEXT NOT NULL,
        category TEXT NULL,
        title TEXT NOT NULL,
        body TEXT NOT NULL,
        severity TEXT NULL,
        critical INTEGER NOT NULL DEFAULT 0,
        starts_at TEXT NULL,
        ends_at TEXT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
});

function seedPruneRow(string $id, bool $critical, ?string $endsAt): void
{
    DB::table('notifications.notifications')->insert([
        'id' => $id,
        'user_id' => 'pro-1',
        'type' => $critical ? 'Warning' : 'Info',
        'category' => 'x',
        'title' => 't',
        'body' => 'b',
        'severity' => 'info',
        'critical' => $critical ? 1 : 0,
        'starts_at' => now()->subDays(2),
        'ends_at' => $endsAt,
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);
}

it('deletes expired non-critical rows but keeps critical and unexpired', function () {
    seedPruneRow('expired-noncritical', critical: false, endsAt: now()->subDay()->toDateTimeString());
    seedPruneRow('expired-critical', critical: true, endsAt: now()->subDay()->toDateTimeString());
    seedPruneRow('unexpired-noncritical', critical: false, endsAt: now()->addDay()->toDateTimeString());
    seedPruneRow('critical-no-expiry', critical: true, endsAt: null);

    // --days=0 → cutoff is now, so anything with ends_at in the past is expired.
    Artisan::call('partna:prune-notifications', ['--days' => 0]);

    $remaining = DB::table('notifications.notifications')->pluck('id')->all();

    expect($remaining)->not->toContain('expired-noncritical');   // pruned
    expect($remaining)->toContain('expired-critical');           // critical kept despite expiry
    expect($remaining)->toContain('unexpired-noncritical');      // not yet expired
    expect($remaining)->toContain('critical-no-expiry');         // critical, persists
});

it('dry-run deletes nothing', function () {
    seedPruneRow('expired-noncritical', critical: false, endsAt: now()->subDay()->toDateTimeString());

    Artisan::call('partna:prune-notifications', ['--days' => 0, '--dry-run' => true]);

    expect(DB::table('notifications.notifications')->count())->toBe(1);
});
