<?php

namespace App\Services\Site;

use App\Models\Core\HandleChangeLog;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;
use App\Models\Core\Site\UserHandleAlias;
use App\Models\Core\User\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Handles subdomain rename business logic — cooldown enforcement, conflict checks,
// alias lifecycle, handle sync, and audit log.
//
// CONTRACT: must be called INSIDE an open pgsql transaction (the caller owns the
// transaction). Stages $site->subdomain and $site->subdomain_changed_at on the
// model in memory but does NOT call $site->save(). The caller (UpdateSiteAction)
// saves after, so all model mutations land in a single observer fire.
class RenameSubdomainAction
{
    /**
     * Stages the subdomain rename on $site. No-ops when the new subdomain equals
     * the current one (case-insensitive). The caller must wrap this in a pgsql
     * transaction and call $site->save() after.
     */
    public function execute(Site $site, string $newSubdomain, User $professional, array $options = []): void
    {
        $incoming = strtolower($newSubdomain);

        // LIFE-5/LIFE-2: re-read BOTH subdomain and subdomain_changed_at under a row
        // lock INSIDE the tx — not just subdomain_changed_at (LIFE-5's original fix).
        // $site->subdomain is only a pre-lock in-memory snapshot; under a rapid
        // double-rename (allow_subdomain_override=true) it goes stale between the
        // caller loading $site and this lock read, which silently drops the
        // intermediate subdomain's alias/audit-log entry below. Locking both columns
        // together keeps $site's in-memory subdomain in sync with what's actually
        // being renamed FROM. (lockForUpdate is a no-op on SQLite — fine for tests.)
        $locked = DB::connection('pgsql')->table('site.sites')
            ->where('id', $site->id)
            ->lockForUpdate()
            ->first(['subdomain', 'subdomain_changed_at']);
        $site->subdomain = (string) $locked->subdomain;
        $site->subdomain_changed_at = $locked->subdomain_changed_at !== null ? Carbon::parse($locked->subdomain_changed_at) : null;
        $current = strtolower($site->subdomain);

        // No-op when the subdomain isn't actually changing — checked AFTER the lock
        // read so a stale pre-lock snapshot can't misjudge this.
        if ($incoming === $current) {
            return;
        }

        $allowSubdomainOverride = (bool) ($options['allow_subdomain_override'] ?? false);

        if (! $allowSubdomainOverride && $site->subdomain_changed_at) {
            // Days between allowed subdomain changes; mirrored in UserSelfController::show.
            $cooldownDays = (int) config('partna.handle.subdomain_cooldown_days', 30);
            $nextAllowed = $site->subdomain_changed_at->copy()->addDays($cooldownDays);

            if (Carbon::now()->lt($nextAllowed)) {
                throw ValidationException::withMessages([
                    'subdomain' => ['You can change your subdomain again on '.$nextAllowed->toDateString().'.'],
                ]);
            }
        }

        $conflictInSites = DB::connection('pgsql')->table('site.sites')
            ->whereRaw('lower(subdomain) = ?', [$incoming])
            ->where('id', '!=', $site->id)
            ->exists();

        if ($conflictInSites) {
            throw ValidationException::withMessages([
                'subdomain' => ['This subdomain is already taken.'],
            ]);
        }

        // Exclude the current site's own aliases — renaming back to a
        // previously held subdomain is allowed (the alias will be collapsed below).
        // Only ACTIVE aliases block: an expired-but-unpruned alias has released
        // the subdomain back to the pool, so it must not lock anyone out.
        $conflictInAliases = DB::connection('pgsql')->table('site.site_subdomain_aliases')
            ->whereRaw('lower(subdomain) = ?', [$incoming])
            ->where('site_id', '!=', $site->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        if ($conflictInAliases) {
            throw ValidationException::withMessages([
                'subdomain' => ['This subdomain is already taken.'],
            ]);
        }

        $reclaimDays = (int) config('partna.handle.reclaim_days', 14);
        $redirectDays = (int) config('partna.handle.redirect_days', 90);

        if (! empty($site->subdomain)) {
            try {
                SiteSubdomainAlias::query()->create([
                    'site_id' => $site->id,
                    'subdomain' => $site->subdomain,
                    'reclaim_until' => now()->addDays($reclaimDays),
                    'expires_at' => now()->addDays($redirectDays),
                    'created_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Alias row already exists — refresh lifecycle timestamps in case
                // it was stale (e.g. a previous alias that expired and wasn't pruned yet).
                // LIFE-1: typed catch handles only 23505 by construction — no SQLSTATE
                // string-compare (Postgres-specific; getCode() is '23000' under SQLite).
                SiteSubdomainAlias::query()
                    ->where('site_id', $site->id)
                    ->whereRaw('lower(subdomain) = ?', [strtolower((string) $site->subdomain)])
                    ->update([
                        'reclaim_until' => now()->addDays($reclaimDays),
                        'expires_at' => now()->addDays($redirectDays),
                    ]);
            }
        }

        // Keep the canonical handle on the professional in sync with the subdomain.
        // The public site resolver (IndividualProfileController / ResolvesSiteFromRequest)
        // looks up by handle_lc, so a desync means the public URL breaks immediately after a rename.
        // The DB trigger (trg_professional_handle_change) records the old handle into
        // user_handle_aliases automatically on this save. We also write it
        // from PHP (belt-and-suspenders) so tests without the trigger stay green.
        $oldHandle = $professional->handle;
        if (! empty($oldHandle) && strtolower($oldHandle) !== $incoming) {
            try {
                UserHandleAlias::query()->create([
                    'user_id' => $professional->id,
                    'handle' => $oldHandle,
                    'reclaim_until' => now()->addDays($reclaimDays),
                    'expires_at' => now()->addDays($redirectDays),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Old handle is already aliased for this user — nothing to do.
            }

            $professional->forceFill([
                'handle' => $incoming,
                'handle_lc' => $incoming,
            ])->save();
        }

        // Collapse: if the user is renaming back to a subdomain they hold as an
        // alias, drop that alias — they own it again, nothing to redirect from it.
        SiteSubdomainAlias::query()
            ->where('site_id', $site->id)
            ->whereRaw('lower(subdomain) = ?', [$incoming])
            ->delete();

        // Audit log — record who changed what and from where. $current holds
        // the old subdomain; $incoming is the new one. actor_id falls back to
        // the professional themselves (self-serve rename).
        HandleChangeLog::create([
            'user_id' => (string) $professional->id,
            'old_handle' => $current,
            'new_handle' => $incoming,
            'reason' => (string) ($options['reason'] ?? HandleChangeLog::REASON_RENAME),
            'actor_id' => (string) ($options['actor_id'] ?? $professional->id),
            'ip_address' => $options['ip'] ?? null,
            'user_agent' => $options['user_agent'] ?? null,
            'changed_at' => now(),
        ]);

        // Stage the new values on the model — caller saves after.
        $site->subdomain = $incoming;
        $site->subdomain_changed_at = now();
    }
}
