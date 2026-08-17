<?php

namespace App\Console\Commands;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Site\Documents\BuildState;
use App\Site\Pools\PoolRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bring a pool's existing `pool:<pool>` sections up to the CURRENT canonical
 * shape in PoolRegistry::SECTION_SHAPE.
 *
 * Generalised 2026-08-18 (overnight run) from the media-only reshape: shop
 * changed shape on 2026-08-17 and its 12 dev sections were never rewritten
 * (PoolSectionProvisioner::ensure returns the existing row unchanged), and
 * media changed again on 2026-08-18 (opt-in, ruling R5). Every future shape
 * change is this one command with a pool argument.
 *
 * Only sections still wearing SOME canonical shape are touched — a rule whose
 * ops are all in {kind_is, latest_per_auto_source, latest_n_per_auto_source}
 * and whose values are exactly the pool's kinds. A hand-edited rule (any other
 * op, or narrowed kinds) is left alone and reported. Fires all three cache
 * lanes per rewritten site (BuildState bump, sites.updated_at, edge purge) —
 * PoolController::poolChanged() is the reference.
 */
class ReshapePoolSectionsCommand extends Command
{
    protected $signature = 'content:reshape-pool-sections
        {pool : media|shop|watch|listen|events|services|menus|reviews|custom_links}
        {--dry-run : Report what would be reshaped without writing}';

    protected $description = 'Rewrite a pool\'s existing sections to the current canonical rule + order (canonical-shaped rows only).';

    private const CANONICAL_OPS = ['kind_is', 'latest_per_auto_source', 'latest_n_per_auto_source'];

    public function handle(): int
    {
        $pool = (string) $this->argument('pool');
        if (! PoolRegistry::isPool($pool)) {
            $this->error("Unknown pool '{$pool}'.");

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');
        $shape = PoolRegistry::sectionShape($pool);
        $kinds = PoolRegistry::kinds($pool);
        $targetRule = ['all' => $shape['rule']];

        $reshaped = 0;
        $skipped = 0;
        $current = 0;

        $sections = DB::connection('pgsql')->table('site.sections')
            ->where('key', PoolRegistry::sectionKey($pool))
            ->get(['id', 'site_id', 'rule', 'order_by']);

        foreach ($sections as $section) {
            $rule = is_string($section->rule) ? json_decode($section->rule, true) : (array) $section->rule;
            $predicates = is_array($rule['all'] ?? null) ? $rule['all'] : [];

            if ($rule === $targetRule && ($section->order_by ?? null) === $shape['order_by']) {
                $current++;

                continue;
            }
            if (! $this->isCanonicalShape($predicates, $kinds)) {
                $skipped++;
                $this->line("  - section {$section->id}: hand-edited rule, left alone");

                continue;
            }
            if ($dry) {
                $this->line("  + section {$section->id}: would reshape");
                $reshaped++;

                continue;
            }

            DB::connection('pgsql')->table('site.sections')
                ->where('id', $section->id)
                ->update([
                    'rule' => json_encode($targetRule),
                    'order_by' => $shape['order_by'],
                    'updated_at' => now(),
                ]);
            $reshaped++;

            $site = DB::connection('pgsql')->table('site.sites')
                ->where('id', $section->site_id)->first(['id', 'subdomain']);
            if ($site !== null) {
                BuildState::bump((string) $site->id);
                DB::connection('pgsql')->table('site.sites')
                    ->where('id', $site->id)->update(['updated_at' => now()]);
                if ((string) ($site->subdomain ?? '') !== '') {
                    CloudflareCachePurgeJob::dispatch($site->subdomain);
                }
            }
        }

        $verb = $dry ? 'would reshape' : 'reshaped';
        $this->info("pool:{$pool} sections: {$verb} {$reshaped}, already current {$current}, left alone {$skipped}.");

        return self::SUCCESS;
    }

    /** @param list<mixed> $predicates @param list<string> $kinds */
    private function isCanonicalShape(array $predicates, array $kinds): bool
    {
        if ($predicates === []) {
            return false;
        }
        foreach ($predicates as $p) {
            if (! is_array($p) || ! in_array($p['op'] ?? null, self::CANONICAL_OPS, true)) {
                return false;
            }
            $values = $p['values'] ?? [];
            if (! is_array($values)) {
                return false;
            }
            sort($values);
            $expected = $kinds;
            sort($expected);
            if ($values !== [] && $values !== $expected) {
                return false;
            }
        }

        return true;
    }
}
