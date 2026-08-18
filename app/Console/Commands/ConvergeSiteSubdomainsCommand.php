<?php

namespace App\Console\Commands;

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\SiteCacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

// SIGNUP-1 backfill: converge site.sites.subdomain onto core.users.handle_lc for
// rows that diverged before the allocator/provisioning fix (three causes —
// Str::slug vs preg_replace normalisation, bare-integer vs hyphenated collision
// suffix, and handle renames that bypassed RenameSubdomainAction).
//
// Straight rename, no alias rows: this is pre-beta repair data with no traffic
// (prod core.users = 0), so there is nothing to 301 from. Deliberately DOES NOT
// touch subdomain_changed_at — a data repair must not spend the user's 30-day
// rename cooldown.
//
// Writes bypass Eloquent on purpose. SiteObserver::saved() blanket-dispatches
// SyncSubdomainToKvJob on any wasChanged('subdomain'), and the Cloudflare KV
// namespace is SHARED between dev and prod — a blanket dispatch from a dev
// backfill writes into the namespace production serves from. Only the users who
// actually need a KV write get one (see kvPlanFor()). CLAUDE.md's corollary is
// honoured explicitly: because no observer fires, this command busts the
// affected cache keys itself.
class ConvergeSiteSubdomainsCommand extends Command
{
    protected $signature = 'partna:converge-site-subdomains
                            {--apply : Actually write. Omit for a dry run (the default).}';

    protected $description = 'Converges site.sites.subdomain onto core.users.handle_lc for diverged rows (dry run unless --apply).';

    public function handle(SiteCacheService $siteCache): int
    {
        $apply = (bool) $this->option('apply');

        $rows = DB::connection('pgsql')->table('site.sites as s')
            ->join('core.users as u', 'u.id', '=', 's.user_id')
            ->whereNull('u.deleted_at')
            ->whereRaw('lower(s.subdomain) <> lower(u.handle_lc)')
            ->orderBy('s.subdomain')
            ->get(['s.id as site_id', 's.subdomain as old_subdomain', 'u.id as user_id', 'u.handle_lc as new_subdomain']);

        $this->info($apply
            ? 'APPLY — writing '.$rows->count().' diverged row(s).'
            : 'DRY RUN — '.$rows->count().' diverged row(s). Nothing will be written; re-run with --apply.');

        if ($rows->isEmpty()) {
            $this->line('Nothing to converge.');

            return self::SUCCESS;
        }

        $converged = 0;
        $skipped = 0;
        $failed = [];

        foreach ($rows as $row) {
            $old = strtolower(trim((string) $row->old_subdomain));
            $new = strtolower(trim((string) $row->new_subdomain));

            $this->newLine();
            $this->line("site {$row->site_id} (user {$row->user_id})");
            $this->line("  old subdomain: {$old}");
            $this->line("  new subdomain: {$new}");

            if ($blocker = $this->blockerFor($row->site_id, $new)) {
                $this->warn("  SKIP: {$blocker}");
                $skipped++;

                continue;
            }

            $kvUsers = $this->kvPlanFor((string) $row->user_id, $new);
            $this->line('  KV: '.($kvUsers === []
                ? 'none — the main entry is keyed on the handle, which is not changing'
                : 'put alias redirect -> https://'.$new.'.'.(config('partna.public_domain') ?: 'partna.au')
                    .' for '.count($kvUsers).' active alias key(s): '.implode(', ', $kvUsers)));

            $keys = $this->cacheKeysFor($old, $new, (string) $row->user_id);
            $this->line('  cache bust:');
            foreach ($keys as $key) {
                $this->line("    - {$key}");
            }

            if (! $apply) {
                $converged++;

                continue;
            }

            $now = now();

            DB::connection('pgsql')->table('site.sites')
                ->where('id', $row->site_id)
                ->update(['subdomain' => $new, 'updated_at' => $now]);

            // The UPDATE above is a single autocommitted statement, so everything
            // below runs strictly post-commit — no transaction wraps it (see the
            // class docblock's rejection of that approach: dispatching
            // SyncSubdomainToKvJob pre-commit races the trigger-recomputed
            // partna_url and can alias-redirect to the OLD subdomain). A failure
            // here means the row is WRITTEN but not invalidated: caught, reported,
            // and the run continues rather than stranding the remaining rows.
            try {
                Cache::deleteMultiple($keys);

                // Mirrors SiteCacheService::invalidateSitePayload's post-write
                // floor raise: an in-flight reader that queried the DB pre-commit
                // can re-put the old resolve timestamp into handle.resolve after
                // the delete above. Only $new needs its floor raised, and the
                // reason is the key derivation, not routing: handle.resolve and
                // its floor key off core.users.handle_lc, and $new IS handle_lc
                // (the query at :46 aliases it new_subdomain). $old is a stale
                // site.sites.subdomain string that no handle resolves by — see
                // the handle-vs-subdomain divergence this repo has been bitten by
                // — so its busted resolve entry has no lease to guard.
                $siteCache->raiseResolveFloor($new, $now->timestamp);

                // Only users holding an active handle alias need KV: the alias entry
                // embeds `redirect: <partna_url>`, and partna_url is recomputed from
                // sites.subdomain by the sites_url_sync_aiu trigger, which the raw
                // UPDATE above fires. The main `<handle>` entry is unaffected.
                if ($kvUsers !== []) {
                    SyncSubdomainToKvJob::dispatch((string) $row->user_id);
                }
            } catch (Throwable $e) {
                report($e);
                $this->error('  WRITTEN BUT NOT INVALIDATED — subdomain is now '.$new.', stale routing state persists.');
                $this->error('  retry KV:    php artisan partna:backfill-subdomain-kv '.$row->user_id);
                $this->error('  stale keys:  '.implode(', ', $keys));
                $failed[] = (string) $row->user_id;

                continue;   // do not strand the remaining rows
            }

            $this->line('  written.');
            $converged++;
        }

        $this->newLine();
        $this->info(($apply ? 'Converged ' : 'Would converge ').$converged.' row(s); skipped '.$skipped.'.');

        if ($failed !== []) {
            $this->error('Failed to invalidate for '.count($failed).' user(s) — see above for per-row remediation: '.implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Why this row cannot be converged, or null when it can. Both cases are real
     * conflicts rather than repairable drift, so they are reported for a human
     * rather than resolved by guesswork.
     */
    private function blockerFor(string $siteId, string $target): ?string
    {
        if ($target === '') {
            return 'user has an empty handle_lc';
        }

        // A reserved handle (dev has one: handle 'admin') is unroutable on BOTH
        // sides already — ResolvesSubdomainFromHost rejects the host and KV is
        // keyed on that same handle. Parking a site row on the reserved name
        // would not fix it, so the repair is a handle rename by a human.
        $reserved = array_map('strtolower', (array) config('partna.reserved_subdomains', []));
        if (in_array($target, $reserved, true)) {
            return "'{$target}' is a reserved subdomain — rename the handle instead";
        }

        if (DB::connection('pgsql')->table('site.sites')
            ->whereRaw('lower(subdomain) = ?', [$target])
            ->where('id', '!=', $siteId)
            ->exists()) {
            return "another site already holds subdomain '{$target}'";
        }

        $alias = DB::connection('pgsql')->table('site.site_subdomain_aliases')
            ->whereRaw('lower(subdomain) = ?', [$target])
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first(['site_id']);

        if ($alias !== null) {
            return (string) $alias->site_id === $siteId
                ? "this site's own active alias holds '{$target}' (would self-redirect — collapse the alias first)"
                : "an active alias on another site holds '{$target}'";
        }

        return null;
    }

    /**
     * The alias KV keys whose `redirect` value changes with the subdomain.
     * Mirrors SyncSubdomainToKvJob::writeAliasEntries()'s active-alias predicate
     * and its skip of an alias equal to the current handle.
     *
     * @return list<string>
     */
    private function kvPlanFor(string $userId, string $currentHandle): array
    {
        return DB::connection('pgsql')->table('core.user_handle_aliases')
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->pluck('handle')
            ->map(fn ($h) => strtolower(trim((string) $h)))
            ->filter(fn (string $h) => $h !== '' && $h !== $currentHandle)
            ->values()
            ->all();
    }

    /**
     * Everything SiteCacheService::invalidateSitePayload() would have busted that
     * this rename actually invalidates, for BOTH the old and new subdomain.
     *
     * Not included, deliberately: siteBlocks/emailBrand (keyed on site id, whose
     * contents this rename does not change) and the alias payload keys (this
     * command mints no aliases).
     *
     * @return list<string>
     */
    private function cacheKeysFor(string $old, string $new, string $userId): array
    {
        $keys = [];

        foreach ([$old, $new] as $subdomain) {
            $payload = CacheKeyGenerator::publicSitePayload($subdomain);
            $keys[] = $payload;
            $keys[] = CacheKeyGenerator::staleKey($payload);

            $resolve = CacheKeyGenerator::handleResolve($subdomain);
            $keys[] = $resolve;
            $keys[] = CacheKeyGenerator::staleKey($resolve);
        }

        // The auth-path model cache preloads the site relation, so it carries the
        // old subdomain until busted.
        $model = CacheKeyGenerator::professionalModel($userId);
        $keys[] = $model;
        $keys[] = CacheKeyGenerator::staleKey($model);

        return array_values(array_unique($keys));
    }
}
