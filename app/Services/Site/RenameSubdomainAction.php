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
use Illuminate\Support\Facades\Log;
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
            $this->reserveSubdomainAlias($site, $reclaimDays, $redirectDays);
        }

        // Keep the canonical handle on the professional in sync with the subdomain.
        // The public site resolver (IndividualProfileController / ResolvesSiteFromRequest)
        // looks up by handle_lc, so a desync means the public URL breaks immediately after a rename.
        // The DB trigger (trg_professional_handle_change) records the old handle into
        // user_handle_aliases automatically on this save. We also write it
        // from PHP (belt-and-suspenders) so tests without the trigger stay green.
        $oldHandle = $professional->handle;
        if (! empty($oldHandle) && strtolower($oldHandle) !== $incoming) {
            $this->reserveHandleAlias($professional, $oldHandle, $reclaimDays, $redirectDays);

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

    /**
     * Reserves the subdomain the site is renaming AWAY from as a 301-redirect alias.
     *
     * Two hazards this navigates, both of which shipped as production 500s:
     *
     * 1. The unique index is GLOBAL, not per-site — `core_site_subdomain_aliases_subdomain_lower_unique`
     *    is on `lower(subdomain)` alone. A collision here is therefore NOT necessarily
     *    our own row, so the old "refresh my own row" recovery matched zero rows.
     * 2. PostgreSQL aborts the ENTIRE transaction on any statement error. Catching the
     *    23505 in PHP restores our control flow but not the database's: every later
     *    statement in the caller's transaction then dies with 25P02 ("current transaction
     *    is aborted"). Inserting inside a NESTED transaction makes Laravel emit
     *    SAVEPOINT / ROLLBACK TO SAVEPOINT instead of BEGIN/COMMIT, so the rollback
     *    clears the aborted state and leaves the caller's transaction healthy.
     *    Mirrors SiteProvisioningService::tryCreateSite().
     *
     * SQLite does not abort on statement error, so hazard 2 is invisible to the default
     * test suite — see tests/Postgres/SubdomainAliasCollisionTest.php.
     */
    private function reserveSubdomainAlias(Site $site, int $reclaimDays, int $redirectDays): void
    {
        $subdomain = (string) $site->subdomain;

        if ($this->insertSubdomainAlias($site, $subdomain, $reclaimDays, $redirectDays)) {
            return;
        }

        $existing = SiteSubdomainAlias::query()
            ->whereRaw('lower(subdomain) = ?', [strtolower($subdomain)])
            ->first();

        // Gone between the failed insert and this read (concurrent prune) — retry once.
        if (! $existing) {
            $this->insertSubdomainAlias($site, $subdomain, $reclaimDays, $redirectDays);

            return;
        }

        // Our own row — refresh the lifecycle timestamps, which is what the pre-fix
        // recovery intended (e.g. an expired alias the pruner hasn't collected yet).
        if ((string) $existing->site_id === (string) $site->id) {
            $existing->forceFill([
                'reclaim_until' => now()->addDays($reclaimDays),
                'expires_at' => now()->addDays($redirectDays),
            ])->save();

            return;
        }

        // Another site's row. An EXPIRED alias has already released the subdomain back
        // to the pool (that is exactly what the conflict pre-check above assumes), so
        // the row is prune-debt, not a claim — drop it and take the subdomain over.
        if ($existing->expires_at !== null && ! $existing->expires_at->isFuture()) {
            $existing->delete();
            $this->insertSubdomainAlias($site, $subdomain, $reclaimDays, $redirectDays);

            return;
        }

        // Another site holds a LIVE redirect on this subdomain. We cannot mint a second
        // alias for it, but the rename itself is legitimate and must not trap the user on
        // a subdomain they are trying to leave — proceed without an alias. The only loss
        // is the 301 from the old subdomain, which the other site's active alias already
        // owns and serves. Reaching here means a site was provisioned onto an
        // alias-held subdomain, which SiteProvisioningService now rejects; log it so a
        // recurrence is visible rather than silent.
        Log::warning('Subdomain alias not created — another site holds an active alias', [
            'site_id' => (string) $site->id,
            'subdomain' => $subdomain,
            'holder_site_id' => (string) $existing->site_id,
        ]);
    }

    /**
     * Inserts one alias row behind a savepoint. Returns false on a unique collision,
     * with the caller's transaction left healthy (see reserveSubdomainAlias docblock).
     */
    private function insertSubdomainAlias(Site $site, string $subdomain, int $reclaimDays, int $redirectDays): bool
    {
        try {
            DB::connection('pgsql')->transaction(fn () => SiteSubdomainAlias::query()->create([
                'site_id' => $site->id,
                'subdomain' => $subdomain,
                'reclaim_until' => now()->addDays($reclaimDays),
                'expires_at' => now()->addDays($redirectDays),
                'created_at' => now(),
            ]));

            return true;
        } catch (UniqueConstraintViolationException $e) {
            // LIFE-1: typed catch handles only 23505 by construction — no SQLSTATE
            // string-compare (Postgres-specific; getCode() is '23000' under SQLite).
            // Laravel has already rolled back to (and released) the savepoint by the
            // time this is reached, so the caller's transaction is usable again.
            return false;
        }
    }

    /**
     * Handle-side mirror of reserveSubdomainAlias(). `user_handle_aliases_handle_lc_uq`
     * is likewise GLOBAL on `lower(handle)`, so a collision can be another user's row —
     * the pre-fix catch assumed it was always our own and swallowed it, leaving the
     * caller's transaction poisoned for every statement that followed.
     */
    private function reserveHandleAlias(User $professional, string $oldHandle, int $reclaimDays, int $redirectDays): void
    {
        if ($this->insertHandleAlias($professional, $oldHandle, $reclaimDays, $redirectDays)) {
            return;
        }

        $existing = UserHandleAlias::query()
            ->whereRaw('lower(handle) = ?', [strtolower($oldHandle)])
            ->first();

        if (! $existing) {
            $this->insertHandleAlias($professional, $oldHandle, $reclaimDays, $redirectDays);

            return;
        }

        // Already aliased for this user — the pre-fix "nothing to do" case, still correct.
        if ((string) $existing->user_id === (string) $professional->id) {
            return;
        }

        // Expired alias belonging to someone else — prune-debt, take it over.
        if ($existing->expires_at !== null && ! $existing->expires_at->isFuture()) {
            $existing->delete();
            $this->insertHandleAlias($professional, $oldHandle, $reclaimDays, $redirectDays);

            return;
        }

        Log::warning('Handle alias not created — another user holds an active alias', [
            'user_id' => (string) $professional->id,
            'handle' => $oldHandle,
            'holder_user_id' => (string) $existing->user_id,
        ]);
    }

    private function insertHandleAlias(User $professional, string $oldHandle, int $reclaimDays, int $redirectDays): bool
    {
        try {
            DB::connection('pgsql')->transaction(fn () => UserHandleAlias::query()->create([
                'user_id' => $professional->id,
                'handle' => $oldHandle,
                'reclaim_until' => now()->addDays($reclaimDays),
                'expires_at' => now()->addDays($redirectDays),
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            return true;
        } catch (UniqueConstraintViolationException $e) {
            return false;
        }
    }
}
