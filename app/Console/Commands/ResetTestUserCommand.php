<?php

namespace App\Console\Commands;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Dev-only clean slate for a TEST account: removes every platform connection
 * (through Eloquent so the observer detaches ingest + mirrored media), then
 * hard-deletes the user's content.*, ingest.*, routing.* rows and site
 * workplaces so the next connect proves what a connect fills from nothing.
 * Keeps the user, site, pages, sections, design kit and uploaded site media.
 *
 * Refuses to run in production. Written for the 2026-08-18 overnight run;
 * every count it prints goes into the run LOG so re-probes are comparable.
 */
class ResetTestUserCommand extends Command
{
    protected $signature = 'partna:reset-test-user
        {handle : Handle of the TEST account to wipe}
        {--yes : Skip the confirmation prompt}
        {--keep-connections : Only wipe content/ingest/routing, keep connections}';

    protected $description = 'DEV ONLY: wipe a test user\'s connections, content, ingest, routing rows for a clean-slate re-probe.';

    private const USER_TABLES = [
        'routing.import_runs', 'routing.item_tombstones', 'routing.link_observations', 'routing.source_intents',
        'content.item_merges', 'content.identity_decisions', 'content.item_anchors', 'content.item_slugs',
        'content.storefronts', 'content.collections', 'content.items', 'content.media_assets',
        'site.item_slugs', 'site.menus',
    ];

    public function handle(): int
    {
        if (app()->isProduction() || (string) config('app.env') === 'production') {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $handle = (string) $this->argument('handle');
        $user = User::query()->where('handle', $handle)->first();
        if (! $user) {
            $this->error("No user with handle '{$handle}'.");

            return self::FAILURE;
        }
        $userId = $user->getKey();

        $before = $this->counts($userId);
        $this->table(['table', 'before'], collect($before)->map(fn ($v, $k) => [$k, $v])->values()->all());

        if (! $this->option('yes') && ! $this->confirm("Wipe ALL of the above for '{$handle}' ({$userId})?")) {
            return self::SUCCESS;
        }

        if (! $this->option('keep-connections')) {
            // Eloquent soft-delete first so IntegrationConnectionObserver::deleted
            // runs (ingest source off, mirrored media cleanup, edge purge).
            IntegrationConnection::withoutTrashed()->where('user_id', $userId)->get()->each->delete();
            // Then hard-remove every row (live + historical) so cascades take
            // content.sources / ingest.sources / brand_asset_refs with them.
            DB::table('site.platform_connections')->where('user_id', $userId)->delete();
        }

        foreach (self::USER_TABLES as $table) {
            DB::table($table)->where('user_id', $userId)->delete();
        }
        DB::table('ingest.sources')->where('user_id', $userId)->delete();

        $siteIds = DB::table('site.sites')->where('user_id', $userId)->pluck('id');
        if ($siteIds->isNotEmpty()) {
            DB::table('site.workplaces')->whereIn('site_id', $siteIds)->delete();
        }

        $after = $this->counts($userId);
        $this->table(['table', 'after'], collect($after)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->info("Reset complete for '{$handle}'.");

        return self::SUCCESS;
    }

    /** @return array<string,int> */
    private function counts(string $userId): array
    {
        $out = [
            'site.platform_connections' => (int) DB::table('site.platform_connections')->where('user_id', $userId)->count(),
            'ingest.sources' => (int) DB::table('ingest.sources')->where('user_id', $userId)->count(),
        ];
        foreach (self::USER_TABLES as $table) {
            $out[$table] = (int) DB::table($table)->where('user_id', $userId)->count();
        }
        $siteIds = DB::table('site.sites')->where('user_id', $userId)->pluck('id');
        $out['site.workplaces'] = $siteIds->isEmpty() ? 0 : (int) DB::table('site.workplaces')->whereIn('site_id', $siteIds)->count();
        $out['site.section_items(pins)'] = $siteIds->isEmpty() ? 0 : (int) DB::table('site.section_items')
            ->whereIn('section_id', DB::table('site.sections')->whereIn('site_id', $siteIds)->select('id'))->count();

        return $out;
    }
}
