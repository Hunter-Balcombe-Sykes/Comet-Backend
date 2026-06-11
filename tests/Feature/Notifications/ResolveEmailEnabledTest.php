<?php

/** @phpstan-ignore-all */

use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Locks in the resolveEmailEnabled() precedence chain so future preference-
// model refactors don't silently flip the meaning of force_on / force_off /
// mandatory. Mirrors the same sqlite-attached-schemas pattern used in
// NotificationPublisherTest — we hit the real DB layer so the underlying
// SQL (WHERE ... IS NULL for global rows, etc.) is exercised.
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

    foreach (['core', 'notifications'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (Throwable $e) {
            // already attached
        }
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notification_email_policies (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        category_key TEXT NOT NULL,
        mode TEXT NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notification_email_preferences (
        id TEXT PRIMARY KEY,
        user_id TEXT NOT NULL,
        category_key TEXT NOT NULL,
        enabled INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');

    // Default mandatory categories — individual tests override as needed.
    Config::set('partna.notifications.mandatory_categories', ['payouts']);

    // Cache: tests run on the array driver. Flush so a previous test's
    // resolved-map cache can't leak into this one's expectations.
    Cache::flush();

    // The cache loader iterates self::categories(), so the registry must be
    // non-empty for the map to contain anything. Pin a small fixture set.
    Config::set('partna.notifications.mailables', [
        'invites' => 'StubMail',
        'payouts' => 'StubMail',
    ]);
});

function insertPolicy(?string $proId, string $category, string $mode): void
{
    DB::table('notifications.notification_email_policies')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'category_key' => $category,
        'mode' => $mode,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function insertPreference(string $proId, string $category, bool $enabled): void
{
    DB::table('notifications.notification_email_preferences')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'category_key' => $category,
        'enabled' => $enabled ? 1 : 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('returns true by default when no policy or preference exists', function () {
    expect(NotificationPublisher::resolveEmailEnabled('pro-1', 'invites'))->toBeTrue();
});

it('respects a user preference set to false', function () {
    insertPreference('pro-1', 'invites', false);

    expect(NotificationPublisher::resolveEmailEnabled('pro-1', 'invites'))->toBeFalse();
});

it('lets per-professional force_off override a user preference set to true', function () {
    insertPreference('pro-1', 'invites', true);
    insertPolicy('pro-1', 'invites', 'force_off');

    expect(NotificationPublisher::resolveEmailEnabled('pro-1', 'invites'))->toBeFalse();
});

it('lets per-professional force_on override a user preference set to false', function () {
    insertPreference('pro-1', 'invites', false);
    insertPolicy('pro-1', 'invites', 'force_on');

    expect(NotificationPublisher::resolveEmailEnabled('pro-1', 'invites'))->toBeTrue();
});

it('falls back to global policy when no per-professional policy is set', function () {
    insertPolicy(null, 'invites', 'force_off');
    insertPreference('pro-1', 'invites', true);

    expect(NotificationPublisher::resolveEmailEnabled('pro-1', 'invites'))->toBeFalse();
});

it('lets per-professional force_on beat global force_off (per-pro wins)', function () {
    insertPolicy(null, 'invites', 'force_off');
    insertPolicy('pro-1', 'invites', 'force_on');

    expect(NotificationPublisher::resolveEmailEnabled('pro-1', 'invites'))->toBeTrue();
});

it('treats mandatory categories as unconditionally enabled', function () {
    // Even with every rung saying "off", a mandatory category still sends.
    insertPolicy(null, 'payouts', 'force_off');
    insertPolicy('pro-1', 'payouts', 'force_off');
    insertPreference('pro-1', 'payouts', false);

    expect(NotificationPublisher::resolveEmailEnabled('pro-1', 'payouts'))->toBeTrue();
});

it('exposes the mandatory list via the static helpers', function () {
    Config::set('partna.notifications.mandatory_categories', ['payouts', 'subscriptions']);

    expect(NotificationPublisher::isMandatory('payouts'))->toBeTrue();
    expect(NotificationPublisher::isMandatory('invites'))->toBeFalse();
    expect(NotificationPublisher::mandatoryCategories())->toEqual(['payouts', 'subscriptions']);
});

// --- Cache layer -----------------------------------------------------------

it('caches the resolved map after the first lookup', function () {
    insertPreference('pro-1', 'invites', false);

    expect(NotificationPublisher::resolveEmailEnabled('pro-1', 'invites'))->toBeFalse();

    // Mutate the underlying row directly — the cached map should mask it.
    DB::table('notifications.notification_email_preferences')
        ->where('user_id', 'pro-1')
        ->update(['enabled' => 1]);

    expect(NotificationPublisher::resolveEmailEnabled('pro-1', 'invites'))->toBeFalse();
});

it('forget() drops the per-pro cache so the next read recomputes', function () {
    insertPreference('pro-1', 'invites', false);
    NotificationPublisher::resolveEmailEnabled('pro-1', 'invites'); // prime cache

    DB::table('notifications.notification_email_preferences')
        ->where('user_id', 'pro-1')
        ->update(['enabled' => 1]);

    NotificationPublisher::forget('pro-1');

    expect(NotificationPublisher::resolveEmailEnabled('pro-1', 'invites'))->toBeTrue();
});

it('bumpGlobalVersion() invalidates every per-pro cache entry', function () {
    insertPreference('pro-1', 'invites', true);
    insertPreference('pro-2', 'invites', true);
    NotificationPublisher::resolveEmailEnabled('pro-1', 'invites');
    NotificationPublisher::resolveEmailEnabled('pro-2', 'invites');

    // Simulate a staff global-policy change post-cache-warm.
    insertPolicy(null, 'invites', 'force_off');
    NotificationPublisher::bumpGlobalVersion();

    expect(NotificationPublisher::resolveEmailEnabled('pro-1', 'invites'))->toBeFalse();
    expect(NotificationPublisher::resolveEmailEnabled('pro-2', 'invites'))->toBeFalse();
});

it('falls back to a fresh compute when a newly-registered category is missing from the cached map', function () {
    insertPreference('pro-1', 'invites', false);
    NotificationPublisher::resolveEmailEnabled('pro-1', 'invites'); // warm with the initial registry

    // Register a new category after the cache was populated. The first lookup
    // for the new category must self-heal (forget + re-load) instead of
    // returning the default with no DB check.
    Config::set('partna.notifications.mailables', [
        'invites' => 'StubMail',
        'payouts' => 'StubMail',
        'brand_links' => 'StubMail',
    ]);
    insertPreference('pro-1', 'brand_links', false);

    expect(NotificationPublisher::resolveEmailEnabled('pro-1', 'brand_links'))->toBeFalse();
});
