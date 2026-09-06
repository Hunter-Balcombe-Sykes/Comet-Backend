<?php

namespace App\Console\Commands;

use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Test harness (Get Started rebuild, 2026-09-07): put one authenticated TEST
 * account back to "just signed up" so the walk can be replayed and timed.
 * Destructive by design; refuses to run on production.
 */
class SetupResetCommand extends Command
{
    protected $signature = 'setup:reset {user : id, handle or email} {--rediscover : re-run discovery for the account} {--yes : skip the confirmation}';

    protected $description = 'Reset a test account to pre-Get-Started state (destructive, dev only)';

    /** user_id-scoped tables, children before parents. Extend when a plan adds a table. */
    private const USER_TABLES = [
        'content.item_links', 'content.item_merges', 'content.collection_items', 'content.item_anchors', 'content.item_slugs',
        'content.identity_candidates', 'content.identity_keys', 'content.source_items', 'content.media_assets', 'content.storefronts',
        'content.collections', 'content.items', 'content.sources',
        'routing.item_tombstones', 'routing.import_runs', 'routing.source_intents',
        'ingest.anomalies', 'ingest.effects', 'ingest.record_versions', 'ingest.record_state', 'ingest.runs', 'ingest.streams', 'ingest.sources',
        'site.workplace_candidates', 'site.workplaces', 'site.menus',
        'site.platform_connections',
        'core.pre_account_build_events',
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run on production.');

            return self::FAILURE;
        }
        $needle = (string) $this->argument('user');
        // Postgres type-checks every predicate's literal, OR or not: `id = 'handle-text'`
        // throws invalid-input-syntax-for-uuid before handle/primary_email are ever
        // reached, for every non-UUID lookup. SQLite's test lane doesn't enforce this,
        // so it passed tests but broke every real (non-uuid) invocation.
        $query = User::query()->where('handle', $needle)->orWhere('primary_email', $needle);
        if (Str::isUuid($needle)) {
            $query->orWhere('id', $needle);
        }
        $user = $query->first();
        if ($user === null) {
            $this->error("No user matches [$needle].");

            return self::FAILURE;
        }
        if (! $this->option('yes') && ! $this->confirm("Wipe discovery/setup state for {$user->handle} ({$user->primary_email})?")) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($user) {
            foreach (self::USER_TABLES as $table) {
                if (! Schema::hasTable($table) && ! Schema::connection('pgsql')->hasTable($table)) {
                    $this->warn("Skipping [$table]: table does not exist (renamed or removed?).");

                    continue;
                }
                if (Schema::hasColumn($table, 'user_id')) {
                    DB::table($table)->where('user_id', $user->id)->delete();
                } elseif (Schema::hasColumn($table, 'build_id')) {
                    DB::table($table)->whereIn('build_id', DB::table('core.pre_account_builds')->where('user_id', $user->id)->select('id'))->delete();
                } elseif (Schema::hasColumn($table, 'source_id')) {
                    // content.*'s source_id references content.sources; everywhere
                    // else it references ingest.sources — two different parents,
                    // same column name (found in Task 1 review: content.collection_items
                    // and content.source_items were silently no-ops against ingest.sources).
                    $sourcesTable = str_starts_with($table, 'content.') ? 'content.sources' : 'ingest.sources';
                    DB::table($table)->whereIn('source_id', DB::table($sourcesTable)->where('user_id', $user->id)->select('id'))->delete();
                } elseif (Schema::hasColumn($table, 'site_id') && $user->site !== null) {
                    DB::table($table)->where('site_id', $user->site->id)->delete();
                }
            }
            if ($user->site !== null) {
                DB::table('site.section_items')->whereIn('section_id', DB::table('site.sections')->where('site_id', $user->site->id)->select('id'))->delete();
                $user->site->forceFill(['setup_step' => null, 'setup_completed_at' => null])->saveQuietly();
            }
        });
        $this->info("Reset {$user->handle}.");

        if ($this->option('rediscover')) {
            $latest = PreAccountBuild::query()->where('user_id', $user->id)->latest('created_at')->first();
            if ($latest === null) {
                $this->error('No previous build to copy the source from; sign the account up again instead.');

                return self::FAILURE;
            }
            $buildId = $latest->id;
            // core.pre_account_builds.user_id is UNIQUE (confirmed against the live
            // dev schema) — one build row per user, ever. A rediscover therefore
            // UPDATEs $latest back to a fresh/unclaimed state rather than INSERTing
            // a second row, which would throw a unique-constraint violation on every
            // real invocation for an account that has already been claimed once
            // (found live, 2026-09-07, replaying against stalicoffeeroasters).
            // GeneratePreAccountSiteJob::handle() only re-runs a build when
            // claimed_at is null AND build_state !== STATE_READY (its early-return
            // guard) — those two plus the terminal/progress markers a completed
            // build would otherwise still carry are what must be cleared.
            DB::table('core.pre_account_builds')->where('id', $buildId)->update([
                // source_type/source_ref/source_ref_lc/built_via/source_name are
                // already correct on $latest — this IS that same row, just reset.
                'build_state' => PreAccountBuild::STATE_PENDING,
                'failure_code' => null,
                'claimed_at' => null,
                'content_filled_at' => null,
                'enriched_at' => null,
                'settled_at' => null,
                'setup_stalled_at' => null,
                'welcomed_at' => null,
                'thin_scrape_at' => null,
                'published_by_claim' => false,
                'updated_at' => now(),
            ]);
            GeneratePreAccountSiteJob::dispatch($buildId, $latest->source_type, false)->afterCommit();
            $this->info("Discovery dispatched: build {$buildId}");
            $this->line("Follow: php artisan setup:timing {$user->handle} --watch");
        }

        return self::SUCCESS;
    }
}
