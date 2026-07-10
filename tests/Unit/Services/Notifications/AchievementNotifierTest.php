<?php

/** @phpstan-ignore-all */

use App\Services\Notifications\Dispatchers\AchievementNotifier;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupContactInboxSchema(); // notifications.notifications with critical + dedupe_key
    // The partial unique index is what makes insertOrIgnore dedupe (prod has it via migration).
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq
         ON notifications (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL'
    );
});

it('publishes a non-critical Success achievement on the first enquiry', function () {
    app(AchievementNotifier::class)->firstEnquiry('pro-1');

    $row = DB::table('notifications.notifications')->where('user_id', 'pro-1')->first();

    expect($row)->not->toBeNull();
    expect($row->category)->toBe('achievement');
    expect($row->type)->toBe('Success');
    expect((int) $row->critical)->toBe(0);        // achievements never email
    expect($row->ends_at)->not->toBeNull();       // non-critical → auto-expires
});

it('is idempotent — the same achievement fires only once (dedupe)', function () {
    $notifier = app(AchievementNotifier::class);
    $notifier->firstEnquiry('pro-1');
    $notifier->firstEnquiry('pro-1');
    $notifier->firstEnquiry('pro-1');

    expect(DB::table('notifications.notifications')->where('user_id', 'pro-1')->count())->toBe(1);
});
