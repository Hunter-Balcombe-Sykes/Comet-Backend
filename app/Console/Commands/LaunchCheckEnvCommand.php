<?php

namespace App\Console\Commands;

use App\Support\LaunchCheck\EnvManifest;
use Illuminate\Console\Command;

/**
 * Verifies the RUNNING env's resolved config against the launch manifest.
 * Meant to run ON the deployed env via `cloud command:run` (env-check.sh),
 * so it reads config() — NOT env(), which config:cache nulls out.
 */
class LaunchCheckEnvCommand extends Command
{
    protected $signature = 'launch-check:env {--target=launch : pilot|launch}';

    protected $description = 'Assert deployed env vars/config match the launch manifest';

    public function handle(): int
    {
        $target = $this->option('target') === 'pilot' ? 'pilot' : 'launch';

        $values = [];
        foreach (EnvManifest::keys() as $key) {
            $values[$key] = config($key);
        }

        $r = EnvManifest::evaluate($values, $target);

        foreach ($r['ok'] as $line) {
            $this->line("  ok    {$line}");
        }
        foreach ($r['warn'] as $line) {
            $this->warn("  warn  {$line}");
        }
        foreach ($r['fail'] as $line) {
            $this->error("  FAIL  {$line}");
        }

        $failed = count($r['fail']) > 0;
        $this->newLine();
        $this->line("target={$target}  ok=".count($r['ok'])
            .' warn='.count($r['warn']).' fail='.count($r['fail']));
        // Sentinel for env-check.sh — the exit code can be lost through cloud command:run.
        $this->line('LAUNCH-CHECK-ENV: '.($failed ? 'FAIL' : 'PASS'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
