<?php

namespace App\Listeners;

use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Support\Facades\Cache;

// Records a "last started" timestamp per scheduled task so GET /api/health/scheduler
// can detect a silently-stopped cron runner. The existing onFailure() hooks only
// fire when a task runs AND errors — they're blind to the case where the scheduler
// itself is off (Laravel Cloud cron disabled, worker crashed), which produces no
// events and is indistinguishable from success until orphan data shows up.
class RecordScheduledTaskHeartbeat
{
    public const CACHE_PREFIX = 'scheduler:last_run:';

    public function handle(ScheduledTaskStarting $event): void
    {
        $key = self::taskKey($event->task);
        // TTL, not forever: maxmemory-policy is instance-wide across every Redis
        // DB here, so an inevictable key on the cache DB is ballast the queue
        // pays for. 30d clears every current task's max(2 x interval, 1h)
        // staleness window by an order of magnitude — the longest scheduled
        // interval is daily. Should a monthly task ever exist, an expired
        // heartbeat degrades one row's last_run_at to null rather than faking a
        // 503: HealthController forgives a null heartbeat whenever any other
        // task proves the runner alive.
        Cache::put(self::CACHE_PREFIX.$key, now()->toIso8601String(), now()->addDays(30));
    }

    // Stable identifier for a scheduled task. Prefers description (set explicitly
    // or auto-set by Schedule::job() to the job class), falls back to the command
    // string with the 'artisan' prefix stripped. Normalized to cache-key-safe chars.
    public static function taskKey(ScheduledEvent $task): string
    {
        $raw = (string) ($task->description ?: $task->command ?: 'unknown');
        // Strip "'/usr/bin/php' 'artisan' foo:bar" down to "foo:bar"
        $raw = preg_replace('/^.*\\bartisan\\b\\s+/', '', $raw);
        $raw = trim($raw, " '\"");

        return preg_replace('/[^A-Za-z0-9:_.-]+/', '_', $raw);
    }
}
