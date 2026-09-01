<?php

namespace App\Console\Commands;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use App\Services\PreAccount\PreAccountBuildService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

// Phase-0 run tooling (efficiency protocol, 2026-08-28): the whole
// expire → prune → fresh-staff-build dance as ONE command. The live-source
// dedupe blocks a plain re-request, so a rebuild MUST tear the old build
// down first — this encodes the sanctioned sequence the 2026-08-27
// acceptance round used, plus the lesson from the fleet redo: source_name
// comes from the FULL workplace/current name (the truncated-display-name
// misfire allocated wrong handles and cost a second prune cycle).
//
// All-or-nothing validation: one bad handle aborts the whole run before any
// build is expired — a half-expired fleet is worse than a failed command.
class FleetRebuildCommand extends Command
{
    protected $signature = 'fleet:rebuild {handles* : handle_lc values to tear down and rebuild} {--dry-run : Validate + print specs, change nothing}';

    protected $description = 'Rebuild unclaimed accounts from scratch: expire, prune, fresh staff build (full source names preserved).';

    public function handle(PreAccountBuildService $builds): int
    {
        $specs = [];
        foreach ($this->argument('handles') as $handle) {
            $handle = strtolower(trim((string) $handle));
            $user = User::query()->where('handle_lc', $handle)->first();
            if (! $user) {
                $this->error("{$handle}: no such user.");

                return self::FAILURE;
            }
            if ($user->status !== 'unclaimed') {
                $this->error("{$handle}: status is '{$user->status}' — only unclaimed accounts can be torn down.");

                return self::FAILURE;
            }
            $build = PreAccountBuild::query()->where('user_id', $user->id)->orderByDesc('created_at')->first();
            if (! $build) {
                $this->error("{$handle}: no build row.");

                return self::FAILURE;
            }

            // Full name first: the workplace's own (untruncated) name is what
            // HandleAllocator must see so the rebuild re-allocates the SAME
            // handle; display_name is the partna-account fallback.
            $siteId = Site::query()->where('user_id', $user->id)->value('id');
            $fullName = $siteId ? Workplace::query()->where('site_id', $siteId)->value('name') : null;
            $sourceName = trim((string) ($fullName ?: $user->display_name));

            $specs[] = [
                'handle' => $handle,
                'account_type' => $user->account_type->value,
                'source_type' => (string) $build->source_type,
                'source_ref' => (string) $build->source_ref,
                'source_name' => $sourceName !== '' ? $sourceName : $handle,
                'build' => $build,
            ];
        }

        foreach ($specs as $spec) {
            $this->line("{$spec['handle']}: {$spec['account_type']} {$spec['source_type']} '{$spec['source_name']}' ref={$spec['source_ref']}");
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing changed.');

            return self::SUCCESS;
        }

        $staff = PartnaStaff::query()->first();
        if (! $staff) {
            $this->error('No PartnaStaff row — the staff build path needs one.');

            return self::FAILURE;
        }

        foreach ($specs as $spec) {
            $spec['build']->forceFill(['expires_at' => now()->subMinute()])->save();
        }

        // builds:prune-expired prunes EVERY expired live build, not only ours —
        // by design: anything legitimately expired should go on the same sweep.
        Artisan::call('builds:prune-expired', [], $this->output);

        $failures = 0;
        foreach ($specs as $spec) {
            try {
                $result = $builds->requestBuild(
                    $spec['account_type'],
                    $spec['source_type'],
                    $spec['source_ref'],
                    $spec['source_name'],
                    null,
                    $staff,
                );
                $fresh = $result['build'];
                // Item 1a/1d: the fresh handle allocates inside the job from the
                // SCRAPED display name — the exact ladder public signups use —
                // so a request-time handle print (and the old CHANGED warning)
                // is impossible now. builds:await / fleet:verify report the
                // handle each rebuild actually landed on.
                $this->info("{$spec['handle']} -> build {$fresh->id} queued (handle allocates after scrape)");
            } catch (\Throwable $e) {
                $failures++;
                $this->error("{$spec['handle']}: rebuild failed — ".$e->getMessage());
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
