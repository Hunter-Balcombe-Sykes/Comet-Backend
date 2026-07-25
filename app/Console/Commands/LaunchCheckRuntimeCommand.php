<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/**
 * Deployed RUNTIME liveness — is the system actually processing, not just
 * configured to? Run ON the env via `cloud command:run` (runtime-health.sh).
 * Config-correctness is group F's job; this checks the moving parts are alive.
 */
class LaunchCheckRuntimeCommand extends Command
{
    protected $signature = 'launch-check:runtime {--target=launch : pilot|launch}';

    protected $description = 'Assert Horizon/Redis/storage/scheduler are alive on the deployed env';

    /** @var array<int,string> */
    private array $fail = [];

    /** @var array<int,string> */
    private array $warn = [];

    /** @var array<int,string> */
    private array $ok = [];

    public function handle(): int
    {
        $target = $this->option('target');
        if (! in_array($target, ['pilot', 'launch'], true)) {
            $this->error("unknown --target '{$target}' (must be pilot or launch)");

            return self::INVALID;
        }
        $launch = $target === 'launch';   // pilot legitimises ONLY the two dev-deviation checks below

        // --- Horizon workers actually running (the 0-masters incident) ---
        // Binding verified against the installed Horizon version (v5.48.1):
        // Laravel\Horizon\Contracts\MasterSupervisorRepository::all() exists and is
        // bound to Repositories\RedisMasterSupervisorRepository in ServiceBindings.
        // `legitDevDeviation`: dev runs 0 Horizon workers by design (QUEUE_CONNECTION=sync) —
        // pilot may warn here, launch must fail. This does NOT extend to a thrown/unverifiable
        // probe elsewhere; only these two checks get the pilot downgrade at all.
        $this->check('horizon-masters', $launch, function () {
            $repo = app(MasterSupervisorRepository::class);
            $n = count($repo->all());

            return [$n > 0, "horizon masters: {$n}"];
        }, legitDevDeviation: true);

        // --- Queue not backing up + failed_jobs sane ---
        // queue-backlog: legitimate dev deviation (sync queue) AND a soft threshold (WARN, not
        // FAIL, when merely over the count — see $thresholdWarnOnly below).
        $this->check('queue-backlog', $launch, function () {
            $size = Queue::size();

            return [$size < 1000, "default queue depth: {$size}"];
        }, thresholdWarnOnly: true, legitDevDeviation: true);
        // failed-jobs is a soft threshold (over-count WARNs) but NOT a legitimate dev deviation —
        // an unreachable failed_jobs table (DB down) must FAIL, not silently warn as "expected on
        // dev". See the critical review: a dead DB previously read green through this exact path.
        $this->check('failed-jobs', $launch, function () {
            $n = DB::table('failed_jobs')->count();

            return [$n < 50, "failed_jobs: {$n}"];
        }, thresholdWarnOnly: true);

        // --- Redis reachable --- no legitimate dev deviation: must be able to FAIL at pilot too.
        $this->check('redis', $launch, function () {
            $pong = Redis::connection()->ping();

            return [(bool) $pong, 'redis ping ok'];
        });

        // --- Media disk writable (upload path) --- no legitimate dev deviation.
        $this->check('storage', $launch, function () {
            $disk = Storage::disk(config('filesystems.default'));
            $key = '_launch-check/probe.txt';
            $disk->put($key, 'ok');
            $read = $disk->get($key);
            $disk->delete($key);

            return [$read === 'ok', 'media disk write/read/delete ok'];
        });

        // --- Scheduler has registered events (cron wiring) --- no legitimate dev deviation.
        // Registration ≠ cron actually firing — that stays partly manual (see MANUAL-CHECKLIST).
        $this->check('scheduler', $launch, function () {
            $n = count(app(Schedule::class)->events());

            return [$n > 0, "scheduled tasks registered: {$n}"];
        });

        foreach ($this->ok as $l) {
            $this->line("  ok    {$l}");
        }
        foreach ($this->warn as $l) {
            $this->warn("  warn  {$l}");
        }
        foreach ($this->fail as $l) {
            $this->error("  FAIL  {$l}");
        }

        $failed = count($this->fail) > 0;
        $this->newLine();
        $this->line('LAUNCH-CHECK-RUNTIME: '.($failed ? 'FAIL' : 'PASS'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Run one probe. $probe returns [bool healthy, string label]. Try/catch keeps
     * one dead check from aborting the rest, but a THROWN probe is "unverifiable",
     * never "within threshold" — $thresholdWarnOnly must NOT swallow it. Unverifiable
     * is a FAIL unless $legitDevDeviation lets pilot downgrade it (dev's known,
     * intentional deviations only: 0 Horizon masters, sync-queue depth). Every other
     * check FAILs on both an unhealthy verified result AND a thrown probe, at BOTH
     * targets — "could not check" must never read as a pass or a blanket warn.
     *
     * $thresholdWarnOnly: the probe genuinely returned a verified-but-over-threshold
     * result (e.g. queue depth, failed_jobs count) — WARN, never FAIL, regardless of
     * target. Does not apply to a thrown/unverifiable probe.
     * $legitDevDeviation: dev is KNOWN to legitimately differ here (Horizon masters,
     * queue backlog under sync) — pilot downgrades an unhealthy-or-unverifiable
     * result to WARN; launch still FAILs it. Every other check ignores $launch
     * entirely and FAILs on unhealthy-or-unverifiable at both targets.
     */
    private function check(
        string $name,
        bool $launch,
        callable $probe,
        bool $thresholdWarnOnly = false,
        bool $legitDevDeviation = false,
    ): void {
        $unverifiable = false;
        try {
            [$healthy, $label] = $probe();
        } catch (\Throwable $e) {
            $healthy = false;
            $unverifiable = true;
            $label = "{$name}: {$e->getMessage()}";
        }

        if ($healthy) {
            $this->ok[] = $label;

            return;
        }

        if ($unverifiable) {
            // Could-not-check is never downgraded by threshold semantics — only the
            // explicit, named dev-deviation checks may warn about it, and only at pilot.
            if ($legitDevDeviation && ! $launch) {
                $this->warn[] = $label.' — expected on dev?';
            } else {
                $this->fail[] = $label;
            }

            return;
        }

        // Verified (not thrown) unhealthy result.
        if ($thresholdWarnOnly) {
            $this->warn[] = $label;

            return;
        }

        if ($legitDevDeviation && ! $launch) {
            $this->warn[] = $label.' — expected on dev?';

            return;
        }

        $this->fail[] = $label;
    }
}
