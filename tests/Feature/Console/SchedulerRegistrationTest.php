<?php

use App\Jobs\Streaming\CheckStreamingLiveStatusJob;
use Illuminate\Console\Scheduling\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduler-Driven Job Registration
|--------------------------------------------------------------------------
| These jobs have no observer or controller dispatch path — they run only
| via the scheduler. A dropped Schedule::job() entry silently stops the
| feature in production with no test failure. This file prevents that.
*/

it('registers all scheduler-driven jobs', function (string $jobClass, string $expectedExpression) {
    $events = collect(app(Schedule::class)->events());

    $event = $events->first(fn ($e) => ($e->description ?? '') === $jobClass);

    expect($event)->not->toBeNull("{$jobClass} is not registered in the scheduler");
    expect($event->expression)->toBe($expectedExpression, "{$jobClass} has wrong schedule expression");
})->with([
    'CheckStreamingLiveStatusJob' => [CheckStreamingLiveStatusJob::class, '*/2 * * * *'],
]);
