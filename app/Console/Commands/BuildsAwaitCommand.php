<?php

namespace App\Console\Commands;

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use Illuminate\Console\Command;

// Phase-0 run tooling (efficiency protocol, 2026-08-28): server-side wait
// for a batch of builds to reach a terminal state — replaces client-side
// sleep-timer polling (one remote command instead of N tinker round trips).
class BuildsAwaitCommand extends Command
{
    protected $signature = 'builds:await {--since= : Only builds created at/after this UTC datetime (default: 15 minutes ago)} {--timeout=300 : Give up after this many seconds} {--interval=10 : Poll interval in seconds}';

    protected $description = 'Block until every matching pre-account build is terminal (ready/failed); print the final states.';

    private const TERMINAL = [PreAccountBuild::STATE_READY, PreAccountBuild::STATE_FAILED];

    public function handle(): int
    {
        $since = (string) ($this->option('since') ?: now()->subMinutes(15)->toDateTimeString());
        $deadline = microtime(true) + max(1, (int) $this->option('timeout'));
        $interval = max(1, (int) $this->option('interval'));

        while (true) {
            $pending = PreAccountBuild::query()
                ->where('created_at', '>=', $since)
                ->whereNotIn('build_state', self::TERMINAL)
                ->count();

            if ($pending === 0) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $this->warn("Timed out with {$pending} build(s) still non-terminal.");
                break;
            }
            sleep($interval);
        }

        $rows = PreAccountBuild::query()
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')
            ->get()
            ->map(fn (PreAccountBuild $b) => [
                // Item 1a: a build that failed before the scrape verified its
                // source never had a user — label it by ref, not '(gone)'.
                ($b->user_id === null
                    ? ($b->source_ref ? "({$b->source_ref})" : '(no identity)')
                    : User::query()->whereKey($b->user_id)->value('handle_lc') ?? '(gone)'),
                (string) $b->build_state,
                $b->failure_code ?? '-',
                (string) $b->created_at,
            ])
            ->all();

        if ($rows === []) {
            $this->info("No builds since {$since}.");

            return self::SUCCESS;
        }

        $this->table(['handle', 'state', 'failure', 'created'], $rows);

        $failed = collect($rows)->filter(fn ($r) => $r[1] === PreAccountBuild::STATE_FAILED)->count();
        $nonTerminal = collect($rows)->filter(fn ($r) => ! in_array($r[1], self::TERMINAL, true))->count();

        return ($failed > 0 || $nonTerminal > 0) ? self::FAILURE : self::SUCCESS;
    }
}
