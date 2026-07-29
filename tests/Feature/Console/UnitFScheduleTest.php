<?php

use Illuminate\Console\Scheduling\Schedule;

// Unit F (LIFE-19/LIFE-20): a dropped Schedule::command() line silently stops
// these alarms from ever running with no other test failure — guard the wiring
// explicitly, mirroring ReconcileTrackedSessionsCommandTest's shape.

it('registers ingest:anomalies hourly at :53 with onOneServer + withoutOverlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) ($e->command ?? ''), 'ingest:anomalies'));

    expect($event)->not->toBeNull('ingest:anomalies is not registered in the scheduler');
    expect($event->expression)->toBe('53 * * * *');
    expect($event->onOneServer)->toBeTrue();
    expect($event->withoutOverlapping)->toBeTrue();
});

it('registers routing:stuck-intents daily at 06:20 with onOneServer + withoutOverlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) ($e->command ?? ''), 'routing:stuck-intents'));

    expect($event)->not->toBeNull('routing:stuck-intents is not registered in the scheduler');
    expect($event->expression)->toBe('20 6 * * *');
    expect($event->onOneServer)->toBeTrue();
    expect($event->withoutOverlapping)->toBeTrue();
});
