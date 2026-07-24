<?php

// Regression guard for #CACHE-2: bootstrap/app.php disables Laravel's event
// auto-discovery so AppServiceProvider's explicit Event::listen() calls are the
// ONLY registration path. Before the fix, every listener typed to handle a
// discoverable event (CacheHit|CacheMissed|KeyWritten, ScheduledTaskStarting,
// MessageSending) was bound twice — once by discovery scanning app/Listeners,
// once by AppServiceProvider — silently doubling every cache counter, scheduler
// heartbeat write, and suppression lookup. Asserts against the real dispatcher
// (Event::getRawListeners()), not Event::fake(), because a fake would hide the
// exact duplication this test exists to catch.

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;

it('registers exactly one listener for each discoverable event RecordCacheMetrics handles', function () {
    foreach ([CacheHit::class, CacheMissed::class, KeyWritten::class] as $event) {
        expect(Event::getRawListeners()[$event] ?? [])->toHaveCount(1, "expected exactly one listener for {$event}");
    }
});

it('registers exactly one listener for ScheduledTaskStarting (RecordScheduledTaskHeartbeat)', function () {
    expect(Event::getRawListeners()[ScheduledTaskStarting::class] ?? [])->toHaveCount(1);
});

it('registers exactly one listener for MessageSending (BlockSuppressedRecipients)', function () {
    expect(Event::getRawListeners()[MessageSending::class] ?? [])->toHaveCount(1);
});
