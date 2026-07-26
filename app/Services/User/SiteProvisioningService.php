<?php

namespace App\Services\User;

use App\Models\Core\Site\Site;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

// V2: Provisions new professional sites with unique subdomains.
class SiteProvisioningService
{
    public function createSiteWithRetry(string $userId, string $base, bool $published = true): Site
    {
        $reserved = array_map('strtolower', config('partna.reserved_subdomains', []));
        $base = strtolower($base);
        $baseIsReserved = in_array($base, $reserved, true);

        if ($baseIsReserved) {
            for ($i = 1; $i <= 20; $i++) {
                $candidate = $this->buildCandidate($base, (string) $i);
                $site = $this->tryCreateSite($userId, $candidate, $published);
                if ($site) {
                    return $site;
                }
            }
        } else {
            for ($i = 0; $i < 20; $i++) {
                $suffix = $i === 0 ? null : (string) $i;
                $candidate = $this->buildCandidate($base, $suffix);
                $site = $this->tryCreateSite($userId, $candidate, $published);
                if ($site) {
                    return $site;
                }
            }
        }

        for ($i = 0; $i < 10; $i++) {
            $rand = Str::lower(Str::random(6));
            $candidate = $this->buildCandidate($base, $rand);
            $site = $this->tryCreateSite($userId, $candidate, $published);
            if ($site) {
                return $site;
            }
        }

        throw new RuntimeException('Could not allocate a unique subdomain.');
    }

    public function subdomainBaseFromHandle(string $handle): string
    {
        $v = mb_strtolower(trim($handle));
        $v = preg_replace('/[^a-z0-9]+/', '-', $v);
        $v = trim($v, '-');

        if ($v === '') {
            $v = 'user-'.substr(Str::uuid()->toString(), 0, 8);
        }

        return $v;
    }

    private function buildCandidate(string $base, ?string $suffix): string
    {
        if ($suffix === null) {
            return $base;
        }

        $base = $this->limitSubdomainBase($base, '-'.$suffix);

        return $base.'-'.$suffix;
    }

    private function limitSubdomainBase(string $base, string $suffixIncludingHyphen): string
    {
        $max = 63 - strlen($suffixIncludingHyphen);
        if ($max < 1) {
            return substr($base, 0, 1);
        }

        return substr($base, 0, $max);
    }

    /**
     * A subdomain still held as an ACTIVE redirect alias by another site is NOT free,
     * even though `site.sites` has no row for it.
     *
     * `core_site_subdomain_aliases_subdomain_lower_unique` is global on lower(subdomain),
     * so provisioning here would mint a site that can never create its own alias when it
     * later renames — the 23505 that shipped as a 25P02 500 on PATCH /api/site — and would
     * point the public 301 for that subdomain at a different site in the meantime. The
     * insert in tryCreateSite() cannot catch this: it only guards site.sites uniqueness.
     *
     * Mirrors the active-alias predicate in RenameSubdomainAction (and the ->active()
     * model scope): an EXPIRED alias has released the name back to the pool and must not
     * lock anyone out, even before the pruner collects it.
     */
    private function subdomainHeldByActiveAlias(string $candidate): bool
    {
        return DB::connection('pgsql')->table('site.site_subdomain_aliases')
            ->whereRaw('lower(subdomain) = ?', [strtolower($candidate)])
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    private function tryCreateSite(string $userId, string $candidate, bool $published): ?Site
    {
        // Advisory pre-check — a concurrent alias insert could still slip past it, but
        // that race leaves at worst one site without a future rename alias (handled
        // gracefully in RenameSubdomainAction), not a poisoned signup transaction.
        if ($this->subdomainHeldByActiveAlias($candidate)) {
            return null; // caller's retry loop advances to the next candidate
        }

        try {
            // Wrap the insert in a nested transaction so Laravel emits a
            // SAVEPOINT/RELEASE pair (instead of a fresh BEGIN/COMMIT) when this
            // runs inside the outer signup transaction in UserBootstrapService.
            // PostgreSQL aborts the ENTIRE transaction on any statement error: a
            // subdomain unique-violation (23505) would otherwise poison the outer
            // transaction, so every subsequent statement fails with 25P02
            // ("current transaction is aborted") and the whole signup rolls back.
            // On a 23505 the savepoint is rolled back to and the QueryException is
            // re-thrown — caught below — leaving the outer transaction healthy so
            // the retry loop can try the next candidate. SQLite doesn't abort on
            // statement error, which is why this bug is invisible in the SQLite
            // test suite (see SiteProvisioningSavepointTest, gated to real pgsql).
            return DB::connection('pgsql')->transaction(function () use ($userId, $candidate, $published) {
                // architecture_id defaults to 'staple' at the DB level (TEXT CHECK
                // DEFAULT 'staple' — the only layout). New sites pick up the
                // default automatically; no need to set it explicitly.
                $site = new Site([
                    'subdomain' => $candidate,
                    'is_published' => $published,
                    'settings' => [],
                ]);

                $site->user_id = $userId;
                $site->save();

                return $site;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Catch sits OUTSIDE the nested DB::connection('pgsql')->transaction() call:
            // by the time the exception surfaces here, Laravel has already rolled back to
            // (and released) the savepoint, so the outer transaction is no longer in an
            // aborted state and the loop can safely retry.
            // Subdomain candidate taken — caller's retry loop tries the next one.
            return null;
        }
    }
}
