<?php

/** @phpstan-ignore-all */

// OV-H wiring: a terminal menu-scrape failure publishes the non-critical content_scrape
// warning via MenuFetchJob::failed().

use App\Jobs\Platforms\MenuFetchJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();          // site.menus (looked up in failed())
    setupContactInboxSchema();  // notifications.notifications
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq
         ON notifications (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL'
    );
});

it('publishes a non-critical content_scrape warning when the menu job fails terminally', function () {
    Exceptions::fake();

    (new MenuFetchJob('pro-1'))->failed(new RuntimeException('scrape blew up'));

    $row = DB::table('notifications.notifications')
        ->where('user_id', 'pro-1')
        ->where('category', 'content_scrape')
        ->first();

    expect($row)->not->toBeNull();
    expect((int) $row->critical)->toBe(0);          // in-app only — the menu self-heals
    expect($row->ends_at)->not->toBeNull();
});
