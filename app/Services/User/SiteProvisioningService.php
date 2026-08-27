<?php

namespace App\Services\User;

use App\Exceptions\Site\SubdomainUnavailableException;
use App\Models\Core\Site\Site;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

// V2: Provisions new professional sites with unique subdomains.
class SiteProvisioningService
{
    /** DNS label limit; also the width of site.site_subdomain_aliases.subdomain. */
    private const MAX_SUBDOMAIN_LENGTH = 63;

    /**
     * SIGNUP-1: provision a site whose subdomain IS the user's handle — ONE
     * candidate, exactly $handleLc, never suffixed.
     *
     * Applies the full availability predicate itself — reserved list, site.sites,
     * active alias — rather than trusting the caller to have applied it. Only the
     * pre-account path allocates through HandleAllocator (which enforces the same
     * predicate); the bootstrap path takes a CLIENT-SUPPLIED handle that
     * BootstrapRequest validates for regex/length/handle uniqueness and nothing
     * else, so "the caller already checked" is false there.
     *
     * On any failure this refuses to suffix — which is what createSiteWithRetry()
     * does, and what shipped the handle/subdomain divergence, hiding the problem
     * behind a site whose URL 404s. It throws SubdomainUnavailableException
     * instead — WITHOUT reporting; see that class for why the two callers handle
     * it differently, and refuseToDiverge() for why telemetry is theirs to decide.
     *
     * @throws SubdomainUnavailableException when the handle is not usable verbatim
     */
    public function createSiteForHandle(string $userId, string $handleLc, bool $published = true): Site
    {
        $subdomain = strtolower(trim($handleLc));

        if ($subdomain === '' || strlen($subdomain) > self::MAX_SUBDOMAIN_LENGTH) {
            $this->refuseToDiverge($userId, $subdomain, 'handle is empty or longer than the 63-char DNS label limit');
        }

        // Reserved names are unroutable by construction — ResolvesSubdomainFromHost
        // rejects the host and SubdomainAvailabilityService reports REASON_RESERVED —
        // so provisioning one produces a site that can never be reached. createSiteWithRetry()
        // sidestepped this by suffixing ('support' → 'support-1'); the exact path has to
        // refuse instead.
        $reserved = array_map('strtolower', (array) config('partna.reserved_subdomains', []));
        if (in_array($subdomain, $reserved, true)) {
            $this->refuseToDiverge($userId, $subdomain, 'this is a reserved subdomain');
        }

        // Explicit pre-check rather than leaning on core_sites_subdomain_lower_unique
        // to surface the collision. tryCreateSite() only detects a taken subdomain by
        // catching the 23505, and the SQLite stand-in in tests carries no unique index
        // on lower(subdomain) — so without this the guard would be a no-op in the very
        // suite that is supposed to pin it, and would insert a duplicate row instead.
        if (DB::connection('pgsql')->table('site.sites')
            ->whereRaw('lower(subdomain) = ?', [$subdomain])
            ->exists()) {
            $this->refuseToDiverge($userId, $subdomain, 'another site already holds this subdomain');
        }

        // site.sites carries a SECOND unique index this insert can trip —
        // sites_user_unique on user_id — and tryCreateSite() swallows the 23505
        // without saying which one fired, so "subdomain taken" would be reported for
        // "this user already has a site". Check it explicitly: a truthful diagnosis,
        // and (like the subdomain pre-check above) one the SQLite stand-in can
        // actually reach, since it carries neither index.
        if (DB::connection('pgsql')->table('site.sites')->where('user_id', $userId)->exists()) {
            $this->refuseToDiverge($userId, $subdomain, 'this user already has a site');
        }

        // tryCreateSite() also declines on an active alias, but only by returning
        // null — indistinguishable there from a lost race. An active alias is
        // stable pre-existing state that reproduces on every retry, so reporting
        // it as a concurrent write sends on-call after a race that cannot exist.
        if ($this->subdomainHeldByActiveAlias($subdomain)) {
            $this->refuseToDiverge($userId, $subdomain, 'an active subdomain alias holds this name');
        }

        $site = $this->tryCreateSite($userId, $subdomain, $published);
        if ($site) {
            return $site;
        }

        // Every deterministic cause is pre-checked above, so only a concurrent
        // write can land here — true ONLY while the alias pre-check above and
        // tryCreateSite()'s own subdomainHeldByActiveAlias() test the same
        // predicate. Tighten one without the other and this comment silently
        // goes false, which is the bug the pre-check exists to prevent.
        $this->refuseToDiverge(
            $userId,
            $subdomain,
            'lost a race — the subdomain, an alias on it, or a site for this user was created concurrently'
        );
    }

    /**
     * Deliberately does NOT report(). Whether a refusal is an alarm depends on the
     * caller, not on this class: on the pre-account path the handle is machine-
     * allocated, so a refusal means HandleAllocator's guarantee broke and the call
     * site reports it; on the bootstrap path the handle is user-supplied, so a
     * refusal is ordinary bad input that returns a clean 409 — reporting that would
     * file a Nightwatch exception per typo and train the alarm to be ignored.
     *
     * @throws SubdomainUnavailableException always
     */
    private function refuseToDiverge(string $userId, string $subdomain, string $why): never
    {
        throw new SubdomainUnavailableException(
            "Cannot provision a site for user {$userId} on subdomain '{$subdomain}': {$why}. "
            .'Refusing to suffix — handle and subdomain must converge (SIGNUP-1).'
        );
    }

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
        $max = self::MAX_SUBDOMAIN_LENGTH - strlen($suffixIncludingHyphen);
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
                // architecture_id defaults to 'scroll' at the DB level (TEXT
                // CHECK, DEFAULT flipped 'staple'->'scroll' 2026-08-27,
                // migration 20260827120000). New sites pick up the
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
