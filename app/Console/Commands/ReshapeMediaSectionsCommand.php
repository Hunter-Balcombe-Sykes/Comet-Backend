<?php

namespace App\Console\Commands;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Site\Documents\BuildState;
use App\Site\Pools\PoolRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-shape existing pool:media sections still carrying the watch/listen
 * default rule (slice 1a §3.6). PoolSectionProvisioner::ensure() early-returns
 * on an existing section, so changing SECTION_SHAPE does not reshape the ten
 * rows that exist on dev — this does.
 *
 * Matched on rule CONTENT (any predicate with op=latest_per_auto_source),
 * not blindly on the key, so a hand-edited section is never clobbered.
 * Idempotent: the corrected rule no longer matches. Read-only under --dry-run.
 */
class ReshapeMediaSectionsCommand extends Command
{
    protected $signature = 'content:reshape-media-sections
        {--dry-run : Report what would be reshaped without writing}';

    protected $description = 'Correct pool:media section rules from latest_per_auto_source to bare kind_is';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $shape = PoolRegistry::sectionShape('media');
        $reshaped = 0;
        $skipped = 0;

        $sections = DB::connection('pgsql')->table('site.sections')
            ->where('key', PoolRegistry::sectionKey('media'))
            ->get(['id', 'site_id', 'rule']);

        foreach ($sections as $section) {
            $rule = is_string($section->rule) ? json_decode($section->rule, true) : (array) $section->rule;
            $predicates = is_array($rule['all'] ?? null) ? $rule['all'] : [];

            $carriesLegacyDefault = collect($predicates)->contains(
                fn ($p) => is_array($p) && ($p['op'] ?? null) === 'latest_per_auto_source'
            );

            if (! $carriesLegacyDefault) {
                $skipped++;

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
                    'rule' => json_encode(['all' => $shape['rule']]),
                    'order_by' => $shape['order_by'],
                    'updated_at' => now(),
                ]);
            $reshaped++;

            // Raw-write seam: all three lanes by hand (spec §4). bump() alone
            // is not enough — the payload cache key composes from
            // sites.updated_at, and the CDN outlives the origin write.
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
        $this->info("pool:media sections: {$verb} {$reshaped}, left alone {$skipped}.");

        return self::SUCCESS;
    }
}
