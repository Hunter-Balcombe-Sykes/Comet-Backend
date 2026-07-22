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

it('forgives a never-run task while another task proves the scheduler is alive', function () {
    $schedule = app(Schedule::class);
    $events = $schedule->events();
    expect(count($events))->toBeGreaterThan(1);

    // Only ONE task has ever run. That fresh heartbeat proves the cron runner is
    // alive; the rest are null purely because they haven't hit their first firing
    // since the scheduler came online (e.g. a 3am daily task at 11am). Those must
    // not drag the endpoint to 503 — that was the false positive on the dev flip.
    $alive = $events[0];
    $aliveKey = RecordScheduledTaskHeartbeat::taskKey($alive);
    Cache::forever(RecordScheduledTaskHeartbeat::CACHE_PREFIX.$aliveKey, now()->toIso8601String());

    $response = $this->getJson('/api/health/scheduler')->assertOk();
    expect($response->json('healthy'))->toBeTrue();

    // A never-run task is reported not-stale and flagged as awaiting its first run.
    $neverRun = collect($response->json('tasks'))->firstWhere('last_run_at', null);
    expect($neverRun)->not->toBeNull()
        ->and($neverRun['stale'])->toBeFalse()
        ->and($neverRun['pending_first_run'])->toBeTrue();
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
    Illuminate\Support\Facades\Schedule::clearResolvedInstance(Schedule::class);

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
