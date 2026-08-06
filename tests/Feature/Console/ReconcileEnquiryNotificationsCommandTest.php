<?php

use App\Jobs\Notifications\DispatchEnquiryNotificationsJob;
use App\Jobs\Notifications\SendEnquiryConfirmationJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupEnquiriesTable();
    Queue::fake();
});

it('re-dispatches both jobs and clears the marker', function () {
    $pro = createTenant('reconcile-both');
    $enquiry = createEnquiryFor($pro, ['notifications_pending_since' => now()->subMinutes(5)->toDateTimeString()]);

    $this->artisan('enquiries:reconcile-notifications')->assertSuccessful();

    Queue::assertPushed(DispatchEnquiryNotificationsJob::class);
    Queue::assertPushed(SendEnquiryConfirmationJob::class);

    expect(DB::connection('pgsql')->table('site.enquiries')->where('id', $enquiry->id)->value('notifications_pending_since'))
        ->toBeNull();
});

it('ignores enquiries with no pending marker', function () {
    $pro = createTenant('reconcile-none');
    createEnquiryFor($pro);

    $this->artisan('enquiries:reconcile-notifications')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('skips the visitor confirmation past its staleness window', function () {
    config(['partna.enquiry.confirmation_reconcile_window_minutes' => 60]);

    $pro = createTenant('reconcile-stale');
    createEnquiryFor($pro, ['notifications_pending_since' => now()->subHours(6)->toDateTimeString()]);

    $this->artisan('enquiries:reconcile-notifications')->assertSuccessful();

    // The professional still gets told — a six-hour-late lead is still a lead.
    Queue::assertPushed(DispatchEnquiryNotificationsJob::class);
    // The visitor does not — "we received your message" six hours later reads
    // worse than nothing.
    Queue::assertNotPushed(SendEnquiryConfirmationJob::class);
});

it('respects the batch size', function () {
    config(['partna.enquiry.reconcile_batch_size' => 2]);

    $pro = createTenant('reconcile-batch');
    foreach (range(1, 5) as $ignored) {
        createEnquiryFor($pro, ['notifications_pending_since' => now()->subMinutes(5)->toDateTimeString()]);
    }

    $this->artisan('enquiries:reconcile-notifications')->assertSuccessful();

    Queue::assertPushed(DispatchEnquiryNotificationsJob::class, 2);

    expect(DB::connection('pgsql')->table('site.enquiries')->whereNotNull('notifications_pending_since')->count())
        ->toBe(3);
});

it('leaves the marker in place when the queue is still down', function () {
    $pro = createTenant('reconcile-still-down');
    $enquiry = createEnquiryFor($pro, ['notifications_pending_since' => now()->subMinutes(5)->toDateTimeString()]);

    Queue::shouldReceive('connection')->andThrow(
        new RuntimeException('read error on connection to 127.0.0.1:6379')
    );

    $this->artisan('enquiries:reconcile-notifications')->assertSuccessful();

    // Retried on the next tick rather than lost.
    expect(DB::connection('pgsql')->table('site.enquiries')->where('id', $enquiry->id)->value('notifications_pending_since'))
        ->not->toBeNull();
});

it('leaves the marker set when job 1 enqueues but job 2 throws', function () {
    // Regression for a partial-failure gap: one shared try/catch covers both
    // dispatch() calls (deliberate — one marker column, spec §5.2), so if the
    // professional's job reaches the queue but the confirmation job's push
    // throws, the marker stays set and BOTH re-dispatch on the next tick —
    // including the one that already succeeded. Distinguishing the two push()
    // calls by job type (rather than call count) keeps this independent of
    // dispatch order inside the command.
    $pro = createTenant('reconcile-partial-failure');
    $enquiry = createEnquiryFor($pro, ['notifications_pending_since' => now()->subMinutes(5)->toDateTimeString()]);

    Queue::shouldReceive('connection')->andReturnSelf();
    Queue::shouldReceive('push')
        ->once()
        ->withArgs(fn ($job) => $job instanceof DispatchEnquiryNotificationsJob)
        ->andReturn(null);
    Queue::shouldReceive('push')
        ->once()
        ->withArgs(fn ($job) => $job instanceof SendEnquiryConfirmationJob)
        ->andThrow(new RuntimeException('read error on connection to 127.0.0.1:6379'));

    $this->artisan('enquiries:reconcile-notifications')->assertSuccessful();

    // Both Mockery ->once() expectations above already prove job 1 was pushed
    // and job 2 threw. What this asserts is the consequence: the marker is
    // NOT cleared despite job 1's successful push — safe to re-dispatch both
    // next tick because DispatchEnquiryNotificationsJob claims an atomic
    // Cache::add SETNX before its side-effect, and SendEnquiryConfirmationJob
    // is ShouldBeUnique and checks confirmation_sent_at.
    expect(DB::connection('pgsql')->table('site.enquiries')->where('id', $enquiry->id)->value('notifications_pending_since'))
        ->not->toBeNull();
});

it('skips soft-deleted enquiries', function () {
    $pro = createTenant('reconcile-deleted');
    createEnquiryFor($pro, [
        'notifications_pending_since' => now()->subMinutes(5)->toDateTimeString(),
        'deleted_at' => now()->toDateTimeString(),
    ]);

    $this->artisan('enquiries:reconcile-notifications')->assertSuccessful();

    Queue::assertNothingPushed();
});
