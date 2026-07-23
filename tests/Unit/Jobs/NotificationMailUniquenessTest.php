<?php

// #SEM-10 reversal (TRIAGE unit 13 / R3-JOB-1..3): once the idempotency stamp
// moved from inside the lock to after the send, the lockForUpdate transaction
// no longer provides mutual exclusion between concurrent dispatches. ShouldBeUnique
// is the real duplicate-dispatch guard now. These pin the shape by reflection,
// mirroring ConnectFetchJobTest's triple (instance-of / uniqueFor+timeout / uniqueId()).

use App\Jobs\Notifications\SendEnquiryConfirmationJob;
use App\Jobs\Notifications\SendEnquiryNotificationJob;
use App\Jobs\Notifications\SendSubscriptionConfirmationJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('SendEnquiryConfirmationJob is uniquely dispatched, keyed on enquiryId', function () {
    $job = new SendEnquiryConfirmationJob('enq-123');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueFor)->toBe(300)
        ->and($job->uniqueFor)->toBeGreaterThan($job->timeout)
        ->and($job->uniqueId())->toBe('enq-123');
});

it('SendSubscriptionConfirmationJob is uniquely dispatched, keyed on subscriptionId', function () {
    $job = new SendSubscriptionConfirmationJob('sub-456');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueFor)->toBe(300)
        ->and($job->uniqueFor)->toBeGreaterThan($job->timeout)
        ->and($job->uniqueId())->toBe('sub-456');
});

it('SendEnquiryNotificationJob is uniquely dispatched, keyed on enquiryId (not blockId)', function () {
    $job = new SendEnquiryNotificationJob('enq-789', 'block-999');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueFor)->toBe(300)
        ->and($job->uniqueFor)->toBeGreaterThan($job->timeout)
        ->and($job->uniqueId())->toBe('enq-789');
});
