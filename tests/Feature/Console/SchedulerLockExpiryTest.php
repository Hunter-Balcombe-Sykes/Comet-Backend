<?php

use Illuminate\Console\Scheduling\Schedule;

// #LIFE-16 / #SCALE-20 and #LIFE-17 / #SCALE-21 — both entries used a BARE
// ->withoutOverlapping(), which Laravel defaults to a 1440-minute (24h) lock.
// A run that crashes without releasing the mutex therefore silently stops the
// command for a whole day, and with no ->onFailure() nothing says so.
//
// routes/console.php's own convention header requires an explicit N, an
// ->onFailure(), and ->runInBackground() for daily/cron-scale tasks.

/** @return object|null */
function scheduledEvent(string $needle)
{
    return collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) ($e->command ?? ''), $needle));
}

/** onFailure() registers an after-callback; the list is protected. */
function afterCallbackCount(object $event): int
{
    $prop = new ReflectionProperty($event, 'afterCallbacks');
    $prop->setAccessible(true);

    return count($prop->getValue($event));
}

it('gives platforms:enrich-pending-cards a bounded lock and a failure handler', function () {
    $event = scheduledEvent('platforms:enrich-pending-cards');

    expect($event)->not->toBeNull('platforms:enrich-pending-cards is not registered in the scheduler');
    expect($event->expression)->toBe('20 3 * * *');
    expect($event->onOneServer)->toBeTrue();
    expect($event->withoutOverlapping)->toBeTrue();
    // The whole point: NOT the 1440 default.
    expect($event->expiresAt)->toBe(30);
    expect($event->runInBackground)->toBeTrue();
    expect(afterCallbackCount($event))->toBeGreaterThan(0);
});

it('gives content:refresh-item-caches a bounded lock and a failure handler', function () {
    $event = scheduledEvent('content:refresh-item-caches');

    expect($event)->not->toBeNull('content:refresh-item-caches is not registered in the scheduler');
    expect($event->expression)->toBe('25 3 * * *');
    expect($event->onOneServer)->toBeTrue();
    expect($event->withoutOverlapping)->toBeTrue();
    expect($event->expiresAt)->toBe(30);
    expect($event->runInBackground)->toBeTrue();
    expect(afterCallbackCount($event))->toBeGreaterThan(0);
});

// The convention header says EVERY entry must honour these. A bare
// withoutOverlapping() anywhere is the same 24h-lock defect wearing a different
// command name, so pin the whole file rather than just the two findings.
it('leaves no scheduled command on the bare 1440-minute default lock', function () {
    $offenders = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => $e->withoutOverlapping && $e->expiresAt === 1440)
        ->map(fn ($e) => (string) ($e->command ?? $e->description ?? 'unknown'))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
