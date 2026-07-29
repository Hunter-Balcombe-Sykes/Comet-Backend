<?php

use App\Jobs\Platforms\ScanPreviousWebsiteContentJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Shape guard, NOT the proof. This pins the retry-semantics properties
// #JOB-1 changed; ScanPreviousWebsiteContentRetryTest.php is the real
// queue-drain proof that the retry actually stops re-dispatching billed
// sub-jobs. Precedent: tests/Unit/Jobs/PreAccountScrapeThrottleTest.php.
it('is single-attempt with a declared failOnTimeout default, matching the RunSourceJob precedent', function () {
    $job = new ScanPreviousWebsiteContentJob('user-1', 'site-1', 'https://example.com');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->tries)->toBe(1)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->timeout)->toBe(60)
        ->and($job->uniqueFor)->toBe(300)
        ->and($job->backoff)->toBe([30]);
});

it('never redeclares $afterCommit with a type (repo gotcha: never type a public bool $afterCommit)', function () {
    // Queueable::$afterCommit exists (untyped, framework-declared) on every
    // ShouldQueue job via the trait — PHP folds a used trait's property
    // declaration into the class itself, so getDeclaringClass() always
    // names this class regardless of where it's really declared. hasType()
    // is the actual guard: a re-declaration with `public bool $afterCommit`
    // would fatal a job enqueued before the property existed and restored
    // via unserialize() without a value (the repo's own gotcha).
    $property = new ReflectionProperty(ScanPreviousWebsiteContentJob::class, 'afterCommit');

    expect($property->hasType())->toBeFalse();
});
