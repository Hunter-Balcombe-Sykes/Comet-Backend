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

    // Scheduler heartbeat check. Returns 503 if any task hasn't reported a
    // "task starting" event within 2× its cron interval (min 1h buffer).
    // Catches a stopped Laravel Cloud cron runner — onFailure() can't, since
    // it only fires when a task actually runs.
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
        $tasks = [];
        $healthy = true;

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
            $stale = $ageSeconds === null || $ageSeconds > $maxAgeSeconds;
            if ($stale) {
                $healthy = false;
            }

            $tasks[] = [
                'name' => $name,
                'expression' => $event->expression,
                'last_run_at' => $lastRunIso,
                'age_seconds' => $ageSeconds,
                'max_age_seconds' => $maxAgeSeconds,
                'stale' => $stale,
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
