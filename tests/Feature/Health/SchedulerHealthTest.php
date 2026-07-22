<?php

use App\Listeners\RecordScheduledTaskHeartbeat;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Cache::flush();
});

it('reports 503 when no scheduled tasks have heartbeats yet', function () {
    $this->getJson('/api/health/scheduler')
        ->assertStatus(503)
        ->assertJsonPath('healthy', false)
        ->assertJsonStructure(['healthy', 'tasks' => [['name', 'expression', 'last_run_at', 'age_seconds', 'max_age_seconds', 'stale']]]);
});

it('reports 200 when every scheduled task has a fresh heartbeat', function () {
    $schedule = app(Schedule::class);

    foreach ($schedule->events() as $event) {
        $key = RecordScheduledTaskHeartbeat::taskKey($event);
        Cache::forever(RecordScheduledTaskHeartbeat::CACHE_PREFIX.$key, now()->toIso8601String());
    }

    $this->getJson('/api/health/scheduler')
        ->assertOk()
        ->assertJsonPath('healthy', true);
});

it('flags a task stale when its heartbeat is older than 2x its cron interval', function () {
    $schedule = app(Schedule::class);
    $events = $schedule->events();
    expect($events)->not->toBeEmpty();

    // Freshen every task, then backdate one far enough to exceed the 1h floor.
    foreach ($events as $event) {
        $key = RecordScheduledTaskHeartbeat::taskKey($event);
        Cache::forever(RecordScheduledTaskHeartbeat::CACHE_PREFIX.$key, now()->toIso8601String());
    }

    $target = $events[0];
    $targetKey = RecordScheduledTaskHeartbeat::taskKey($target);
    Cache::forever(RecordScheduledTaskHeartbeat::CACHE_PREFIX.$targetKey, now()->subDays(30)->toIso8601String());

    $response = $this->getJson('/api/health/scheduler')->assertStatus(503);

    $tasks = collect($response->json('tasks'));
    $targetReport = $tasks->firstWhere('name', $targetKey);

    expect($targetReport['stale'])->toBeTrue();
});

it('loads the schedule on demand when the singleton starts empty, as over real HTTP', function () {
    // Over real HTTP, routes/console.php never loads (bootstrap gates it on
    // runningInConsole), so the Schedule singleton is empty and the endpoint
    // used to return healthy:true with zero tasks — a vacuous pass that hid a
    // never-enabled cron. Simulate that state with a fresh, empty Schedule.
    app()->instance(Schedule::class, new Schedule);
    \Illuminate\Support\Facades\Schedule::clearResolvedInstance(Schedule::class);

    $response = $this->getJson('/api/health/scheduler')->assertStatus(503);

    expect($response->json('tasks'))->not->toBeEmpty()
        ->and($response->json('healthy'))->toBeFalse();
});

it('records a heartbeat when a ScheduledTaskStarting event fires', function () {
    $schedule = app(Schedule::class);
    $event = $schedule->events()[0];
    $key = RecordScheduledTaskHeartbeat::taskKey($event);

    expect(Cache::get(RecordScheduledTaskHeartbeat::CACHE_PREFIX.$key))->toBeNull();

    Event::dispatch(new ScheduledTaskStarting($event));

    expect(Cache::get(RecordScheduledTaskHeartbeat::CACHE_PREFIX.$key))->not->toBeNull();
});
