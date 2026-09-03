<?php

namespace App\Console\Commands;

use App\Models\Core\User\PreAccountBuild;
use Illuminate\Console\Command;

// Triage queue for builds that reached the ceiling or failed without ever
// settling -- they get no email, by ruling, so this is the only way anyone
// finds out. Same shape as catalog:unmatched: a command works the day it
// merges, with no frontend dependency.
class BuildsStalledCommand extends Command
{
    protected $signature = 'builds:stalled {--hours=24 : Only builds stalled within this many hours}';

    protected $description = 'List pre-account builds that stalled during setup and were never emailed about.';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));

        $rows = PreAccountBuild::query()
            ->with('user.site')
            ->whereNotNull('setup_stalled_at')
            ->where('setup_stalled_at', '>=', now()->subHours($hours))
            ->orderByDesc('setup_stalled_at')
            ->get();

        if ($rows->isEmpty()) {
            $this->info("No stalled builds in the last {$hours}h.");

            return self::SUCCESS;
        }

        $this->table(
            ['handle', 'build', 'state', 'source', 'via', 'claimed', 'stalled at'],
            $rows->map(fn (PreAccountBuild $b) => [
                (string) ($b->user?->site?->subdomain ?? '—'),
                (string) $b->id,
                (string) $b->build_state,
                $b->source_type.':'.$b->source_ref,
                (string) $b->built_via,
                $b->claimed_at !== null ? 'yes' : 'no',
                $b->setup_stalled_at?->toDateTimeString() ?? '',
            ])->all(),
        );
        $this->warn($rows->count().' stalled build(s) in the last '.$hours.'h — none received an email.');

        return self::SUCCESS;
    }
}
