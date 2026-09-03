<?php

namespace App\Console\Commands;

use App\Catalog\CompiledCatalog;
use App\Routing\IriCanonicalizer;
use App\Routing\LinkProjector;
use App\Routing\PublicSuffixList;
use App\Routing\Rulepack;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ONE replay/diff tool (C9 — this is both `catalog:shadow` and
 * `routing:reproject` from the councils' vocabulary). Re-runs recorded
 * observations through the CURRENT rules and reports what would change:
 *
 *   php artisan routing:reproject --since=30d
 *   php artisan routing:reproject --since=30d --against=/path/to/other/checkout
 *
 * `--against` loads a second compiled artefact from a git checkout — never
 * from object storage, so what you diff is always something you can read.
 * Writes nothing, ever: the output is a decision aid for a pull request.
 */
class RoutingReprojectCommand extends Command
{
    protected $signature = 'routing:reproject
        {--since=30d : window of observations to replay (e.g. 7d, 30d, 90d)}
        {--limit=5000 : maximum observations to replay}
        {--against= : path to another checkout whose compiled artefact to diff against}
        {--show=20 : how many example changes to print per bucket}';

    protected $description = 'Replay recorded link observations against the current (or another) rulepack and diff the verdicts';

    public function handle(): int
    {
        $since = $this->parseSince((string) $this->option('since'));
        if ($since === null) {
            $this->error('Could not parse --since; use a form like 30d, 12h, 8w.');

            return self::FAILURE;
        }

        $rulepack = $this->option('against')
            ? $this->rulepackFrom((string) $this->option('against'))
            : Rulepack::fromCompiledCatalog();

        if ($rulepack === null) {
            return self::FAILURE;
        }

        $canonicalizer = new IriCanonicalizer(PublicSuffixList::instance());
        $projector = new LinkProjector($rulepack);

        $observations = DB::table('routing.link_observations')
            ->where('observed_at', '>=', $since)
            ->orderByDesc('observed_at')
            ->limit((int) $this->option('limit'))
            ->get(['raw_url', 'surface_key', 'catalog_digest']);

        if ($observations->isEmpty()) {
            $this->warn('No observations in that window — nothing to replay.');

            return self::SUCCESS;
        }

        $buckets = ['reclassified' => [], 'newly_matched' => [], 'lost' => [], 'unchanged' => 0];

        foreach ($observations as $observation) {
            $projection = $projector->project($canonicalizer->canonicalize($observation->raw_url));
            $before = $observation->surface_key;
            $after = $projection->matched() ? $projection->surfaceKey : null;

            if ($before === $after) {
                $buckets['unchanged']++;

                continue;
            }

            $entry = [
                'url' => $observation->raw_url,
                'before' => $before ?? '—',
                'after' => $after ?? '—',
                // A reprojection that CHANGED SURFACE is the whole finding now
                // — there is no score to have drifted, and a surface change was
                // always the only part of this report worth acting on.
                'contested' => $projection->contested,
            ];

            if ($before === null) {
                $buckets['newly_matched'][] = $entry;
            } elseif ($after === null) {
                $buckets['lost'][] = $entry;
            } else {
                $buckets['reclassified'][] = $entry;
            }
        }

        $this->render($observations->count(), $buckets, $rulepack->catalogDigest);

        // A "lost" classification is the one bucket that silently removes
        // behaviour users already have, so it decides the exit code: a
        // non-zero status makes this usable as a CI gate on a rules PR.
        return $buckets['lost'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed> $buckets */
    private function render(int $total, array $buckets, string $digest): void
    {
        $this->info(sprintf(
            '%d replayed against %s: %d reclassified, %d newly matched, %d lost, %d unchanged.',
            $total,
            substr($digest, 0, 19),
            count($buckets['reclassified']),
            count($buckets['newly_matched']),
            count($buckets['lost']),
            $buckets['unchanged'],
        ));

        $show = max(1, (int) $this->option('show'));

        foreach (['lost', 'reclassified', 'newly_matched'] as $bucket) {
            if ($buckets[$bucket] === []) {
                continue;
            }
            $this->newLine();
            $this->line(strtoupper(str_replace('_', ' ', $bucket)).':');
            foreach (array_slice($buckets[$bucket], 0, $show) as $entry) {
                $this->line(sprintf('  %s', $entry['url']));
                $this->line(sprintf('      %s → %s%s', $entry['before'], $entry['after'], $entry['contested'] ? ' (another brand also matches)' : ''));
            }
            $remaining = count($buckets[$bucket]) - $show;
            if ($remaining > 0) {
                $this->line("  … and {$remaining} more");
            }
        }
    }

    private function rulepackFrom(string $checkout): ?Rulepack
    {
        $path = rtrim($checkout, '/').'/bootstrap/catalog/compiled.php';
        if (! is_file($path)) {
            $this->error("No compiled artefact at {$path} — run `catalog:compile` in that checkout first.");

            return null;
        }

        $artefact = require $path;
        $this->comment("Diffing against {$path} ({$artefact['digest']})");

        return Rulepack::derive($artefact['detectors'], $artefact['digest']);
    }

    private function parseSince(string $since): ?\DateTimeInterface
    {
        if (! preg_match('/^(\d+)\s*([hdw])$/i', trim($since), $m)) {
            return null;
        }

        return match (strtolower($m[2])) {
            'h' => now()->subHours((int) $m[1]),
            'd' => now()->subDays((int) $m[1]),
            'w' => now()->subWeeks((int) $m[1]),
            // Unreachable via the regex above, but a match must be total —
            // an unknown unit is "could not parse", never a silent default
            // window that quietly replays the wrong slice of traffic.
            default => null,
        };
    }

    /** Guard against being run with no compiled catalog at all. */
    public function isEnabled(): bool
    {
        return CompiledCatalog::isCompiled();
    }
}
