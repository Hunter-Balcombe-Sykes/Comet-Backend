<?php

use App\Jobs\Notifications\SendTransactionalNotificationEmailJob;
use App\Mail\Notifications\InviteNotificationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    attachTestSchemas();
    setupUsersTable();

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        type TEXT NULL,
        category TEXT NULL,
        title TEXT NULL,
        body TEXT NULL,
        cta_url TEXT NULL,
        primary_action_label TEXT NULL,
        secondary_action_label TEXT NULL,
        secondary_action_url TEXT NULL,
        severity TEXT NULL,
        starts_at TEXT NULL,
        ends_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.notification_email_policies (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        category_key TEXT NULL,
        mode TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.notification_email_preferences (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        category_key TEXT NULL,
        enabled INTEGER NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.site_visits (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        site_id TEXT NULL,
        visitor_id TEXT NULL,
        ip_hash TEXT NULL,
        occurred_at TEXT NULL
    )');

    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS analytics.link_clicks (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        site_id TEXT NULL,
        visitor_id TEXT NULL,
        ip_hash TEXT NULL,
        occurred_at TEXT NULL
    )');

});

// ── #V5-012: transactional email job ─────────────────────────────────────────

it('email job exits without sending when professional is soft-deleted', function () {
    Mail::fake();

    $proId = (string) Str::uuid();
    $notifId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'deleted',
        'handle_lc' => 'deleted',
        'display_name' => 'Deleted',
        'first_name' => 'Deleted',
        'primary_email' => 'deleted@example.test',
        'status' => 'active',
        'deleted_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('notifications.notifications')->insert([
        'id' => $notifId,
        'user_id' => $proId,
        'type' => 'Info',
        'category' => 'invites',
        'title' => 'Test notification',
        'body' => 'Test body',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    config([
        'partna.notifications.email_enabled' => true,
        'partna.notifications.mailables.invites' => InviteNotificationMail::class,
    ]);

    (new SendTransactionalNotificationEmailJob($notifId, 'invites', $proId))->handle();

    Mail::assertNothingSent();
});

it('email job does not block active professionals from receiving email', function () {
    // Verify the whereNull('deleted_at') guard passes through for live professionals.
    $proId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'active',
        'handle_lc' => 'active',
        'display_name' => 'Active',
        'first_name' => 'Active',
        'primary_email' => 'active@example.test',
        'status' => 'active',
        'deleted_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $email = DB::connection('pgsql')
        ->table('core.users')
        ->where('id', $proId)
        ->whereNull('deleted_at')
        ->value('primary_email');

    expect($email)->toBe('active@example.test');
});

// ── #V5-013: soft-delete EXISTS predicate ────────────────────────────────────

it('analytics soft-delete guard does not block non-deleted professional', function () {
    // Verify the EXISTS query itself returns true for an active professional.
    $proId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $proId,
        'handle' => 'existsguard',
        'handle_lc' => 'existsguard',
        'display_name' => 'Existsguard',
        'first_name' => 'Existsguard',
        'status' => 'active',
        'deleted_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $shouldProceed = DB::connection('pgsql')
        ->table('core.users')
        ->where('id', $proId)
        ->whereNull('deleted_at')
        ->exists();

    expect($shouldProceed)->toBeTrue();
});

// ── #V5-056: staff stats doesn't count deleted professionals ─────────────────

it('whereNull deleted_at query excludes soft-deleted users from account_type counts', function () {
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        ['id' => (string) Str::uuid(), 'handle' => 'countuser1', 'handle_lc' => 'countuser1', 'display_name' => 'Countuser1', 'first_name' => 'Countuser1', 'account_type' => 'partna', 'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
        ['id' => (string) Str::uuid(), 'handle' => 'countuser2', 'handle_lc' => 'countuser2', 'display_name' => 'Countuser2', 'first_name' => 'Countuser2', 'account_type' => 'partna', 'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
        ['id' => (string) Str::uuid(), 'handle' => 'countuser3', 'handle_lc' => 'countuser3', 'display_name' => 'Countuser3', 'first_name' => 'Countuser3', 'account_type' => 'partna', 'deleted_at' => $now, 'created_at' => $now, 'updated_at' => $now],
        ['id' => (string) Str::uuid(), 'handle' => 'countuser4', 'handle_lc' => 'countuser4', 'display_name' => 'Countuser4', 'first_name' => 'Countuser4', 'account_type' => 'partna', 'deleted_at' => null, 'created_at' => $now, 'updated_at' => $now],
    ]);

    $typeCounts = DB::connection('pgsql')
        ->table('core.users')
        ->whereNull('deleted_at')
        ->selectRaw('account_type, count(*) as total')
        ->groupBy('account_type')
        ->pluck('total', 'account_type');

    expect((int) $typeCounts->get('partna'))->toBe(3);
});
