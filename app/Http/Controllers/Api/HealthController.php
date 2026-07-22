<?php

namespace App\Http\Controllers\Api;

use App\Listeners\RecordScheduledTaskHeartbeat;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

// V2: Liveness/readiness probe. Checks database and Redis connectivity with response times for deployment health checks.
class HealthController extends ApiController
{
    public function check(): JsonResponse
    {
        $db = $this->checkDatabase();
        $cache = $this->checkCache();

        $status = [
            'app' => 'healthy',
            'database' => $db,
            'cache' => $cache,
        ];

        $allHealthy = ($db['status'] === 'healthy') && ($cache['status'] === 'healthy');

        return $this->success($status, $allHealthy ? 200 : 503);
    }

    // Scheduler heartbeat check. Returns 503 if a task that HAS run goes quiet for
    // longer than 2× its cron interval (min 1h buffer), or if no task has ever run
    // at all (stopped/never-started cron). A never-run task is forgiven while any
    // other task is still firing on time — otherwise a 3am daily task falsely 503s
    // the endpoint from deploy until its first firing. Catches a stopped Laravel
    // Cloud cron runner — onFailure() can't, since it only fires when a task runs.
    public function scheduler(): JsonResponse
    {
        $schedule = app(Schedule::class);

        // routes/console.php is only loaded for console processes (bootstrap wires
        // it via withRouting(commands:), gated on runningInConsole()), so over HTTP
        // the Schedule singleton starts EMPTY and every check passes vacuously —
        // "healthy" with zero tasks even when cron has never fired. Load the
        // definitions on demand so this endpoint audits the real schedule.
        if ($schedule->events() === []) {
            require base_path('routes/console.php');
        }

        $now = now();

        // First pass: resolve each task's last heartbeat and its staleness window,
        // and decide whether the cron runner is demonstrably alive right now.
        $rows = [];
        $schedulerProvenAlive = false;

        foreach ($schedule->events() as $event) {
            $name = RecordScheduledTaskHeartbeat::taskKey($event);
            $lastRunIso = Cache::get(RecordScheduledTaskHeartbeat::CACHE_PREFIX.$name);
            $lastRun = $lastRunIso ? Carbon::parse($lastRunIso) : null;

            // Max acceptable staleness: 2× cron interval, floored at 1h. The floor
            // covers hourly tasks right after deploy when cache is cold.
            $cron = new CronExpression($event->expression);
            $prev = $cron->getPreviousRunDate($now);
            $prevPrev = $cron->getPreviousRunDate($prev);
            $intervalSeconds = $prev->getTimestamp() - $prevPrev->getTimestamp();
            $maxAgeSeconds = max($intervalSeconds * 2, 3600);

            $ageSeconds = $lastRun ? $now->getTimestamp() - $lastRun->getTimestamp() : null;

            // Any task that ran within its own window proves the runner is up — the
            // one thing this probe exists to verify.
            if ($ageSeconds !== null && $ageSeconds <= $maxAgeSeconds) {
                $schedulerProvenAlive = true;
            }

            $rows[] = [
                'name' => $name,
                'expression' => $event->expression,
                'last_run_at' => $lastRunIso,
                'age_seconds' => $ageSeconds,
                'max_age_seconds' => $maxAgeSeconds,
            ];
        }

        // Second pass: a never-run task (null heartbeat) is only stale when the
        // scheduler ISN'T demonstrably alive. A not-yet-due daily task right after
        // the cron comes online is null through no fault of the runner — forgive it
        // as long as some other task is firing on time. If NOTHING has run at all,
        // null is genuine evidence of a stopped/never-started cron and stays stale
        // (503) — the vacuous-healthy case this endpoint was built to catch.
        // Trade-off: a task broken from its very first firing while the runner is
        // otherwise healthy won't surface here — that's the per-task onFailure()
        // hooks' job, not the runner-liveness probe's.
        $tasks = [];
        $healthy = true;

        foreach ($rows as $row) {
            $neverRun = $row['age_seconds'] === null;
            $pendingFirstRun = $neverRun && $schedulerProvenAlive;

            $stale = $neverRun
                ? ! $schedulerProvenAlive
                : $row['age_seconds'] > $row['max_age_seconds'];

            if ($stale) {
                $healthy = false;
            }

            $tasks[] = $row + [
                'stale' => $stale,
                'pending_first_run' => $pendingFirstRun,
            ];
        }

        return $this->success(['healthy' => $healthy, 'tasks' => $tasks], $healthy ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        $start = microtime(true);

        try {
            DB::connection()->getPdo();
            $ms = (microtime(true) - $start) * 1000;

            return ['status' => 'healthy', 'ms' => $ms];
        } catch (Throwable $e) {
            $ms = (microtime(true) - $start) * 1000;

            return [
                'status' => 'unhealthy',
                'ms' => $ms,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkCache(): array
    {
        $start = microtime(true);

        try {
            $key = 'health:cache:'.bin2hex(random_bytes(8));
            $value = bin2hex(random_bytes(8));

            Cache::put($key, $value, now()->addSeconds(10));
            $read = Cache::get($key);
            Cache::forget($key);

            $ms = (microtime(true) - $start) * 1000;

            return [
                'status' => ($read === $value) ? 'healthy' : 'unhealthy',
                'ms' => $ms,
            ];
        } catch (Throwable $e) {
            $ms = (microtime(true) - $start) * 1000;

            return [
                'status' => 'unhealthy',
                'ms' => $ms,
                'error' => $e->getMessage(),
            ];
        }
    }
}
