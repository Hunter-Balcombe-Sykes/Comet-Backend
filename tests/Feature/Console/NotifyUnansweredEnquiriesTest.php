<?php

use App\Models\Core\Notifications\Notification;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupEnquiriesTable();
    setupNotificationsTable();

    // The shared test schema doesn't carry `status` (prod: NOT NULL DEFAULT
    // 'new' — supabase/migrations/20260726000000_baseline_pilot.sql:1649).
    // Defensive ALTER, same pattern setupNotificationsTable() uses for its
    // own late-added columns.
    try {
        DB::connection('pgsql')->statement("ALTER TABLE site.enquiries ADD COLUMN status TEXT NOT NULL DEFAULT 'new'");
    } catch (Throwable $e) {
        // already exists
    }

    // Create the real unique index so insertOrIgnore's dedupe is actually
    // enforced on sqlite — mirrors BootstrapWelcomeDedupTest /
    // NotificationPublisherTest (baseline migration line 1031).
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq '.
        'ON notifications (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL'
    );
});

it('publishes one reminder per stale unread enquiry and never twice', function () {
    $pro = createTenant('cafe-owner');

    $stale = createEnquiryFor($pro, [
        'name' => "Riley O'Brien",
        'subject' => 'Booking for Saturday',
        'read_at' => null,
        'created_at' => now()->subHours(60)->toDateTimeString(),
    ]);

    // Fresh (under 48h) and already-read enquiries are ignored.
    createEnquiryFor($pro, ['read_at' => null, 'created_at' => now()->subHours(12)->toDateTimeString()]);
    createEnquiryFor($pro, ['read_at' => now()->toDateTimeString(), 'created_at' => now()->subHours(60)->toDateTimeString()]);

    $this->artisan('partna:notify-unanswered-enquiries')->assertSuccessful();
    $this->artisan('partna:notify-unanswered-enquiries')->assertSuccessful(); // idempotent

    $rows = Notification::query()->where('category', 'enquiry_reminder')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->user_id)->toBe($pro->id)
        ->and($rows->first()->title)->toContain('waiting')
        ->and($rows->first()->dedupe_key)->toBe("enquiry_reminder:{$stale->id}");
});

it('ignores spam, archived and replied enquiries, and anything older than 7 days', function () {
    $pro = createTenant('cafe-owner-2');

    createEnquiryFor($pro, ['status' => 'spam', 'read_at' => null, 'created_at' => now()->subHours(60)->toDateTimeString()]);
    createEnquiryFor($pro, ['status' => 'archived', 'read_at' => null, 'created_at' => now()->subHours(60)->toDateTimeString()]);
    createEnquiryFor($pro, ['status' => 'replied', 'read_at' => null, 'created_at' => now()->subHours(60)->toDateTimeString()]);
    createEnquiryFor($pro, ['read_at' => null, 'created_at' => now()->subDays(10)->toDateTimeString()]);

    $this->artisan('partna:notify-unanswered-enquiries')->assertSuccessful();

    expect(Notification::query()->where('category', 'enquiry_reminder')->count())->toBe(0);
});

it('dry-run does not publish anything', function () {
    $pro = createTenant('cafe-owner-3');
    createEnquiryFor($pro, ['read_at' => null, 'created_at' => now()->subHours(60)->toDateTimeString()]);

    $this->artisan('partna:notify-unanswered-enquiries --dry-run')->assertSuccessful();

    expect(Notification::query()->where('category', 'enquiry_reminder')->count())->toBe(0);
});
