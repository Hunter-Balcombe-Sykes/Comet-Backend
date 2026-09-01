<?php

namespace App\Console\Commands;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\Profile\NameShapeGate;
use Illuminate\Console\Command;

// Backfill for F3 (2026-08-31 audit; reproduced 2026-09-01 re-audit): a large
// share of Instagram-sourced unclaimed accounts carry a descriptor, an emoji,
// a letter-spaced string or a raw handle where a name belongs — and the review
// person-scope matcher READS those names, so first_name "The" admits a whole
// venue's reviews. Re-running the whole build to fix a string would cost an
// Apify scrape each; the source fullName is already stored on the connection
// payload, so the gate is re-applied in place.
//
// UNCLAIMED ONLY, by design: once someone claims their site the name is theirs,
// and no backfill may overwrite an owner's own words.
class NamesRegateCommand extends Command
{
    protected $signature = 'names:regate {--dry-run : Print the changes, write nothing} {--limit=0 : Stop after N changed accounts}';

    protected $description = 'Re-apply the name-shape gate to unclaimed Instagram-sourced accounts.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dry = (bool) $this->option('dry-run');
        $rows = [];
        $changed = 0;
        $seen = 0;

        $builds = PreAccountBuild::query()
            ->where('source_type', 'instagram')
            ->where('build_state', 'ready')
            ->orderBy('created_at')
            ->get();

        foreach ($builds as $build) {
            $user = User::query()->whereKey($build->user_id)->first();
            if (! $user || $user->status !== 'unclaimed') {
                continue;
            }
            $seen++;

            $payload = IntegrationConnection::withoutGlobalScopes()
                ->where('user_id', $user->id)
                ->where('platform', 'instagram')
                ->value('payload') ?? [];
            $fullName = trim((string) (data_get($payload, 'fullName') ?? data_get($payload, 'full_name') ?? ''));

            $gated = NameShapeGate::apply(
                ['displayName' => $user->display_name, 'firstName' => $user->first_name, 'lastName' => $user->last_name],
                (string) $build->source_ref,
                $fullName,
            );

            if ($gated['displayName'] === $user->display_name
                && $gated['firstName'] === $user->first_name
                && $gated['lastName'] === $user->last_name) {
                continue;
            }

            $rows[] = [
                $user->handle_lc,
                trim(($user->display_name ?? '—').' / '.($user->first_name ?? '—').' / '.($user->last_name ?? '—')),
                trim(($gated['displayName'] ?? '—').' / '.($gated['firstName'] ?? '—').' / '.($gated['lastName'] ?? '—')),
            ];
            $changed++;

            if (! $dry) {
                $user->display_name = $gated['displayName'] ?? $user->display_name;
                $user->first_name = $gated['firstName'];
                $user->last_name = $gated['lastName'];
                $user->save();
            }

            if ($limit > 0 && $changed >= $limit) {
                break;
            }
        }

        $this->table(['handle', 'before (display / first / last)', 'after'], $rows);
        $this->info(($dry ? 'Would change ' : 'Changed ').$changed.' of '.$seen.' unclaimed instagram accounts.');

        return self::SUCCESS;
    }
}
