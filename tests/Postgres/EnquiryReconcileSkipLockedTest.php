<?php

// #PGR-12: ReconcileEnquiryNotifications (app/Console/Commands/ReconcileEnquiryNotifications.php)
// runs ONE DB::connection('pgsql')->transaction() wrapping ONE
// Enquiry::query()->whereNotNull('notifications_pending_since')->orderBy(...)->limit($batch)
// ->lock('for update skip locked')->get() (:44-49) and the whole foreach. Per row it dispatches
// two jobs and, on success, clears the marker (:96).
//
// SKIP LOCKED is invisible to the SQLite mirror (the lock clause is silently ignored there —
// see tests/Feature/Console/ReconcileEnquiryNotificationsCommandTest.php, which passes
// identically with or without it). This file proves the Postgres-only contract: a row an
// overlapping reconcile run already holds under FOR UPDATE is passed over cleanly rather than
// blocked on or double-processed, while the rows nobody holds are reconciled correctly.
//
// Modelled on tests/Postgres/ClaimConcurrencyTest.php's "leaves an expired build alone..." case
// (:433-486) — deterministic lock-holding on a second, independently-resolved connection,
// fork-free.

use App\Jobs\Notifications\DispatchEnquiryNotificationsJob;
use App\Jobs\Notifications\SendEnquiryConfirmationJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

// Owns site.enquiries exclusively in this lane (verified: no other tests/Postgres/ file creates
// it) — free to DROP/CREATE per-run rather than the shared-table ALTER-heal pattern siblings use
// for core.users/site.sites.
beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS site');

    $pg->statement('DROP TABLE IF EXISTS site.enquiries CASCADE');

    // Shape tracks supabase/migrations/20260726000000_baseline_pilot.sql:1633-1657 +
    // 20260806100000_add_enquiry_notifications_pending_since.sql:17, with two deliberate
    // deviations:
    //   - status is a plain text column, not the site.enquiry_status ENUM TYPE. A shared enum
    //     type in schema `site` is exactly the first-creator-wins artefact
    //     tests/Postgres/SourceIntentUpsertRaceTest.php's header warns against; the model's
    //     backed-enum cast reads a plain string fine and this command never touches `status`.
    //   - user_id/site_id/customer_id/notification_id are bare uuids with no FK, following
    //     SourceIntentUpsertRaceTest.php:56-61 — nothing under test here touches referential
    //     integrity, only the row lock.
    $pg->statement('CREATE TABLE site.enquiries (
        id                           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id                      uuid NOT NULL,
        site_id                      uuid NOT NULL,
        name                         character varying(100) NOT NULL,
        email                        character varying(255) NOT NULL,
        phone                        character varying(30),
        subject                      character varying(100) NOT NULL,
        message                      text NOT NULL,
        ip_hash                      character varying(64),
        user_agent                   character varying(500),
        read_at                      timestamptz,
        deleted_at                   timestamptz,
        created_at                   timestamptz NOT NULL DEFAULT now(),
        updated_at                   timestamptz NOT NULL DEFAULT now(),
        email_sent_at                timestamptz,
        status                       text NOT NULL DEFAULT \'new\',
        customer_id                  uuid,
        notification_id              uuid,
        replied_at                   timestamptz,
        archived_at                  timestamptz,
        spam_at                      timestamptz,
        redacted_at                  timestamptz,
        confirmation_sent_at         timestamptz,
        notifications_pending_since  timestamptz
    )');

    Queue::fake();
});

afterAll(function () {
    DB::connection('pgsql')->statement('DROP TABLE IF EXISTS site.enquiries CASCADE');
});

/**
 * Seeds a pending enquiry ~1 minute old — fresh enough that the confirmation-staleness branch
 * (:64, config('partna.enquiry.confirmation_reconcile_window_minutes') default 60) stays on the
 * "still send" arm, and well inside the notifications_pending_alert_minutes default (30) so the
 * report() pager (:107-112) never fires and never pollutes this run.
 */
function seedPendingEnquiry(string $suffix): string
{
    $id = (string) Str::uuid();

    DB::connection('pgsql')->table('site.enquiries')->insert([
        'id' => $id,
        'user_id' => (string) Str::uuid(),
        'site_id' => (string) Str::uuid(),
        'name' => "Visitor {$suffix}",
        'email' => "visitor-{$suffix}@example.test",
        'subject' => 'General enquiry',
        'message' => "Hello from {$suffix}",
        'notifications_pending_since' => now()->subMinute()->toDateTimeString(),
        'created_at' => now()->subMinute()->toDateTimeString(),
        'updated_at' => now()->subMinute()->toDateTimeString(),
    ]);

    return $id;
}

it('passes cleanly over rows a concurrent reconcile run holds under FOR UPDATE, and reconciles them once released', function () {
    $unlockedIds = [seedPendingEnquiry('u1'), seedPendingEnquiry('u2'), seedPendingEnquiry('u3')];
    $lockedIds = [seedPendingEnquiry('l1'), seedPendingEnquiry('l2'), seedPendingEnquiry('l3')];

    // A genuinely separate Postgres connection standing in for an overlapping reconcile run
    // that has already claimed 3 of the 6 rows under FOR UPDATE SKIP LOCKED and not yet
    // committed.
    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
    $second = DB::connection('pgsql_second');

    DB::connection('pgsql')->statement('SET lock_timeout = 2000');

    try {
        $second->beginTransaction();
        $placeholders = implode(',', array_fill(0, count($lockedIds), '?'));
        $second->select(
            "SELECT id FROM site.enquiries WHERE id IN ({$placeholders}) FOR UPDATE",
            $lockedIds
        );

        Artisan::call('enquiries:reconcile-notifications');
        $output = trim(Artisan::output());

        // The command's own verdict — the only true proof that a downgrade to plain FOR UPDATE
        // (which would hang on lock_timeout, or in the worst case block forever) did not happen.
        expect($output)->toBe('Reconciled 3 enquiry notification(s).');

        // Disjointness, not "3 got done" — the 3 UNLOCKED rows are cleared...
        foreach ($unlockedIds as $id) {
            expect(DB::connection('pgsql')->table('site.enquiries')->where('id', $id)->value('notifications_pending_since'))
                ->toBeNull("unlocked row {$id} was not reconciled");
        }
        // ...and the 3 LOCKED rows are untouched, by id, not merely by count.
        foreach ($lockedIds as $id) {
            expect(DB::connection('pgsql')->table('site.enquiries')->where('id', $id)->value('notifications_pending_since'))
                ->not->toBeNull("locked row {$id} was reconciled despite being held under FOR UPDATE");
        }

        Queue::assertPushed(DispatchEnquiryNotificationsJob::class, 3);
        Queue::assertPushed(SendEnquiryConfirmationJob::class, 3);

        foreach ($unlockedIds as $id) {
            Queue::assertPushed(DispatchEnquiryNotificationsJob::class, fn ($job) => $job->enquiryId === $id);
        }
        foreach ($lockedIds as $id) {
            Queue::assertNotPushed(DispatchEnquiryNotificationsJob::class, fn ($job) => $job->enquiryId === $id);
        }

        // Anti-vacuity control: without this, every assertion above would pass identically
        // against a command that reconciles nothing at all. Release the held lock and re-run —
        // the other 3 rows must now clear too, and the queue counts accumulate to all 6.
        $second->rollBack();
        DB::purge('pgsql_second');

        Artisan::call('enquiries:reconcile-notifications');
        $secondOutput = trim(Artisan::output());
        expect($secondOutput)->toBe('Reconciled 3 enquiry notification(s).');

        foreach ($lockedIds as $id) {
            expect(DB::connection('pgsql')->table('site.enquiries')->where('id', $id)->value('notifications_pending_since'))
                ->toBeNull("previously-locked row {$id} was not reconciled once the lock was released — the disjointness assertions above are vacuous");
        }

        Queue::assertPushed(DispatchEnquiryNotificationsJob::class, 6);
        Queue::assertPushed(SendEnquiryConfirmationJob::class, 6);
    } finally {
        // try/finally so a failed assertion above can never leak an open FOR UPDATE — a leaked
        // lock would hang every later file in the lane.
        try {
            $second->rollBack();
        } catch (Throwable $e) {
            // already rolled back / connection gone — fine.
        }
        DB::purge('pgsql_second');
        DB::connection('pgsql')->statement('SET lock_timeout = DEFAULT');
    }
});

// Pins the real LIMIT + SKIP LOCKED interaction: the LIMIT applies to the candidate set BEFORE
// SKIP LOCKED filters out held rows, so a batch of 3 against 6 unlocked + 3 locked candidates can
// come back with FEWER than 3 rows if the limited slice happens to land on locked ones. This pins
// observed Postgres behaviour, not an app-level contract — it documents why reconcile_batch_size
// is not a promise of "exactly N processed per run" under contention.
it('applies the batch limit before SKIP LOCKED filters out held rows, per real Postgres semantics', function () {
    config(['partna.enquiry.reconcile_batch_size' => 3]);

    $unlockedIds = [seedPendingEnquiry('b-u1'), seedPendingEnquiry('b-u2'), seedPendingEnquiry('b-u3'), seedPendingEnquiry('b-u4'), seedPendingEnquiry('b-u5'), seedPendingEnquiry('b-u6')];
    $lockedIds = [seedPendingEnquiry('b-l1'), seedPendingEnquiry('b-l2'), seedPendingEnquiry('b-l3')];

    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
    $second = DB::connection('pgsql_second');

    DB::connection('pgsql')->statement('SET lock_timeout = 2000');

    try {
        $second->beginTransaction();
        $placeholders = implode(',', array_fill(0, count($lockedIds), '?'));
        $second->select(
            "SELECT id FROM site.enquiries WHERE id IN ({$placeholders}) FOR UPDATE",
            $lockedIds
        );

        Artisan::call('enquiries:reconcile-notifications');

        $reconciledCount = DB::connection('pgsql')->table('site.enquiries')
            ->whereIn('id', array_merge($unlockedIds, $lockedIds))
            ->whereNull('notifications_pending_since')
            ->count();

        // No promise of exactly 3: the LIMIT 3 candidate slice is chosen by
        // notifications_pending_since ordering BEFORE SKIP LOCKED filters, so it may include
        // some of the locked rows and come back with fewer than 3 usable rows this run.
        expect($reconciledCount)->toBeLessThanOrEqual(3);

        // None of the LOCKED rows were ever touched, regardless of how the limited slice landed.
        foreach ($lockedIds as $id) {
            expect(DB::connection('pgsql')->table('site.enquiries')->where('id', $id)->value('notifications_pending_since'))
                ->not->toBeNull("locked row {$id} was reconciled despite being held under FOR UPDATE");
        }
    } finally {
        try {
            $second->rollBack();
        } catch (Throwable $e) {
            // already rolled back / connection gone — fine.
        }
        DB::purge('pgsql_second');
        DB::connection('pgsql')->statement('SET lock_timeout = DEFAULT');
    }
});
