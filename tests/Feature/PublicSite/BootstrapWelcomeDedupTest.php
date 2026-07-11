<?php

/**
 * LIFE-7 / DINT-12: UserBootstrapService::createWelcomeNotification() used to dedup via a
 * racy firstOrCreate (SELECT-then-INSERT) with no DB-level backstop, so a concurrent
 * double-bootstrap could create two "Welcome to Partna" rows. The fix routes the write
 * through an atomic insertOrIgnore keyed on the notifications_dedupe_key_per_pro_uq unique
 * index (user_id, dedupe_key). These tests exercise the private method directly via
 * reflection — it only touches the Notification model and the passed User, so no HTTP
 * request or full bootstrap() call is needed.
 */

use App\Models\Core\Notifications\Notification;
use App\Models\Core\User\User;
use App\Services\User\UserBootstrapService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupNotificationsTable();

    // Create the real unique index so the dedup is actually enforced on sqlite —
    // mirrors the index NotificationPublisherTest relies on (baseline migration line 1031).
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq '.
        'ON notifications (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL'
    );
});

/**
 * Seed a minimal core.users row and return the hydrated model.
 */
function seedWelcomeDedupUser(string $id): User
{
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => 'welcome-dedup-uid',
        'handle' => 'welcomededuper',
        'handle_lc' => 'welcomededuper',
        'display_name' => 'Welcome Deduper',
        'primary_email' => 'welcomededuper@example.com',
        'account_type' => 'partna',
        'status' => 'active',
    ]);

    return User::query()->findOrFail($id);
}

/**
 * Invoke the private createWelcomeNotification() via reflection.
 */
function invokeCreateWelcomeNotification(User $professional): void
{
    $service = app(UserBootstrapService::class);
    $method = new ReflectionMethod(UserBootstrapService::class, 'createWelcomeNotification');
    $method->setAccessible(true);
    $method->invoke($service, $professional);
}

it('writes exactly one welcome row carrying the dedupe_key (proves the atomic path, not the old firstOrCreate)', function () {
    $user = seedWelcomeDedupUser((string) Str::uuid());

    invokeCreateWelcomeNotification($user);

    $rows = Notification::query()->where('user_id', $user->id)->get();

    expect($rows)->toHaveCount(1);
    expect($rows->first()->title)->toBe('Welcome to Partna');
    // The old firstOrCreate never wrote a dedupe_key at all — this is the discriminator
    // that proves the new insertOrIgnore path ran, not a leftover dedup-by-title match.
    expect($rows->first()->dedupe_key)->toBe('welcome:'.$user->id);
});

it('makes a concurrent second write a silent no-op instead of a duplicate or a thrown exception', function () {
    $user = seedWelcomeDedupUser((string) Str::uuid());

    // Two calls model the concurrent double-bootstrap's two inserts both reaching the DB.
    // Without the unique index the 2nd insert would duplicate; with the old firstOrCreate
    // the 2nd concurrent insert would throw a unique violation that aborts the surrounding
    // bootstrap transaction. The atomic insertOrIgnore makes it a clean no-op instead.
    invokeCreateWelcomeNotification($user);
    invokeCreateWelcomeNotification($user);

    expect(Notification::query()->where('user_id', $user->id)->count())->toBe(1);
});
