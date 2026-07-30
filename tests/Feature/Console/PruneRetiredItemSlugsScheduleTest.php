<?php

use Illuminate\Console\Scheduling\Schedule;

// 271-PRIV-1: a retention column with no prune job scheduled is worse than
// nothing. This asserts the SCHEDULE ENTRY exists -- not merely that the
// command works when invoked directly -- mirroring UnitFScheduleTest's shape.
// Do NOT mirror SchedulerRegistrationTest: it matches on $e->description ===
// $jobClass, the Schedule::job() shape, which never matches a Schedule::command()
// entry like this one.

it('registers slugs:prune-retired daily at 03:35 with onOneServer + withoutOverlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) ($e->command ?? ''), 'slugs:prune-retired'));

    expect($event)->not->toBeNull('slugs:prune-retired is not registered in the scheduler');
    expect($event->expression)->toBe('35 3 * * *');
    expect($event->onOneServer)->toBeTrue();
    expect($event->withoutOverlapping)->toBeTrue();
});
