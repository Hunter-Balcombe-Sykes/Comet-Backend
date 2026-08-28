<?php

namespace App\Console\Commands;

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\Platforms\GoogleBusinessService;
use Illuminate\Console\Command;

// Run tooling (2026-08-28 overnight round). A google_business build needs a
// place_id, and a place_id is opaque — you cannot type one from a business
// name. The 2026-08-27 round resolved them by hand (share.google link ->
// follow redirect -> read the name -> Places search), which is several
// round trips per account.
//
// This turns a list of plain-English queries into the exact spec rows
// fleet:new consumes, and flags any place_id already in the fleet so a round
// doesn't re-build an account it already has. Read-only against our own data;
// the only write is one Places searchText call per query (PlacesBudget still
// governs it, same as production traffic).
class FleetPlacesCommand extends Command
{
    protected $signature = 'fleet:places
        {--b64= : base64 of a JSON list of query strings}
        {--json= : the same JSON inline (quoting-fragile; prefer --b64)}
        {--region=au : two-letter region code biasing the search}';

    protected $description = 'Resolve business-name queries to place ids and print fleet:new specs.';

    public function handle(GoogleBusinessService $places): int
    {
        $raw = $this->option('b64') !== null && $this->option('b64') !== ''
            ? base64_decode((string) $this->option('b64'), true)
            : (string) $this->option('json');

        $queries = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($queries) || $queries === []) {
            $this->error('Pass --b64= (preferred) or --json= with a JSON list of query strings.');

            return self::FAILURE;
        }

        // Every place_id the fleet already built from, so a repeat query is
        // reported rather than silently rebuilt.
        $taken = PreAccountBuild::query()
            ->where('source_type', 'google_business')
            ->pluck('source_ref')
            ->flip();

        // searchText bills against a user id for budget accounting; a staff
        // lookup is not any one user's traffic, so it rides the first staff
        // user — the same account the staff build path already uses.
        $userId = (string) (User::query()->orderBy('created_at')->value('id') ?? '');

        $specs = [];
        $rows = [];
        foreach ($queries as $query) {
            $query = trim((string) $query);
            if ($query === '') {
                continue;
            }

            $results = $places->searchText($query, $userId, null, (string) $this->option('region'), 1);
            if ($results === null) {
                $rows[] = [$query, 'NO KEY / BUDGET', '-', '-'];

                continue;
            }
            $hit = $results[0] ?? null;
            if (! is_array($hit)) {
                $rows[] = [$query, 'NO MATCH', '-', '-'];

                continue;
            }

            $placeId = (string) ($hit['id'] ?? '');
            $name = (string) (data_get($hit, 'displayName.text') ?? '');
            $already = $taken->has($placeId);
            $rows[] = [$query, $name, $placeId, $already ? 'ALREADY BUILT' : 'new'];

            if (! $already && $placeId !== '' && $name !== '') {
                $specs[] = [
                    'account_type' => 'business',
                    'source_type' => 'google_business',
                    'source_ref' => $placeId,
                    'source_name' => $name,
                ];
            }
        }

        $this->table(['query', 'resolved name', 'place_id', 'state'], $rows);

        if ($specs !== []) {
            $this->newLine();
            $this->line('SPECS_B64: '.base64_encode(json_encode($specs)));
        }

        return self::SUCCESS;
    }
}
