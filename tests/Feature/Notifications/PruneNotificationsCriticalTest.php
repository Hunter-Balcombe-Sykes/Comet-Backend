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

// SCALE-1: the daily-scheduled prune used to be one unbounded DELETE. Now batched
// like PurgeRawAnalyticsEvents::purgeBatched — these prove the loop still deletes
// every eligible row across multiple rounds AND leaves ineligible rows untouched.
it('deletes every eligible row across multiple batches when eligible rows exceed the batch size', function () {
    // 5 expired non-critical rows — batch size 2 forces 3 rounds (2, 2, 1).
    foreach (range(1, 5) as $i) {
        seedPruneRow("expired-{$i}", critical: false, endsAt: now()->subDay()->toDateTimeString());
    }
    // Ineligible rows must survive the batching loop too — the survival half is
    // the one most likely to catch an inverted comparison.
    seedPruneRow('kept-unexpired', critical: false, endsAt: now()->addDay()->toDateTimeString());
    seedPruneRow('kept-critical', critical: true, endsAt: now()->subDay()->toDateTimeString());

    DB::connection('pgsql')->enableQueryLog();
    Artisan::call('partna:prune-notifications', ['--days' => 0, '--batch-size' => 2]);
    $log = DB::connection('pgsql')->getQueryLog();
    DB::connection('pgsql')->disableQueryLog();

    $remaining = DB::table('notifications.notifications')->pluck('id')->all();

    foreach (range(1, 5) as $i) {
        expect($remaining)->not->toContain("expired-{$i}");
    }
    expect($remaining)->toContain('kept-unexpired')
        ->and($remaining)->toContain('kept-critical');

    // The actual "genuinely loops" proof: 5 eligible rows at batch size 2 must take
    // 3 DELETE round trips (2, 2, 1) — a single unbounded DELETE would show as 1 and
    // still pass the row-survival assertions above, so this is the half that catches
    // a batching loop that was silently skipped/no-opped.
    $deleteQueries = array_filter($log, fn ($q) => str_starts_with(trim($q['query']), 'delete'));
    expect($deleteQueries)->toHaveCount(3);
});

it('aborts without deleting when the given batch size is non-positive (infinite-loop guard)', function () {
    seedPruneRow('expired-noncritical', critical: false, endsAt: now()->subDay()->toDateTimeString());

    // Must return promptly (no infinite do/while spin) and leave the row untouched.
    Artisan::call('partna:prune-notifications', ['--days' => 0, '--batch-size' => 0]);

    expect(DB::table('notifications.notifications')->count())->toBe(1);
});

// CFG-2: --days is a grace period measured from ends_at, not a retention window, so a
// long-retention category is NOT pruned early. NotificationPublisher stamps
// ends_at = now + partna.notification_retention_days.<category> at publish time; a
// 365-day policy_update therefore survives the daily --days=30 run until day 395.
// This is the assertion the audit finding assumed was missing.
it('leaves a long-retention notification alone until its own ends_at has passed', function () {
    // Stamped as NotificationPublisher would for a 365-day category.
    seedPruneRow('policy-update', critical: false, endsAt: now()->addDays(365)->toDateTimeString());
    // Same category, but already 31 days past its own expiry — beyond the 30-day grace.
    seedPruneRow('policy-update-expired', critical: false, endsAt: now()->subDays(31)->toDateTimeString());

    Artisan::call('partna:prune-notifications', ['--days' => 30]);

    $remaining = DB::table('notifications.notifications')->pluck('id')->all();

    expect($remaining)->toContain('policy-update')
        ->and($remaining)->not->toContain('policy-update-expired');
});
