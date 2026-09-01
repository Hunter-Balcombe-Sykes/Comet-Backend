<?php

namespace App\Services\User;

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Extracted verbatim from BootstrapRequest::generateHandleFromDisplayName so the
// pre-account build path can allocate handles without a Form Request in scope.
// Behavior contract: slug, 'professional' fallback, BARE-integer collision suffix
// (jane-doe1 — distinct from the subdomain's hyphenated -1 style, deliberately).
//
// SIGNUP-1: a candidate must be free as a handle AND as a subdomain. The site is
// provisioned VERBATIM from what this returns (SiteProvisioningService::createSiteForHandle),
// so anything handed back that provisioning would have had to rewrite — a taken
// subdomain, an alias-held name, a reserved word — is divergence at birth:
// core.users.handle and site.sites.subdomain drift apart, and every subdomain-keyed
// consumer breaks (the build's site_url 404s; site.compute_user_url() feeds a dead
// host into core.users.partna_url and from there into every alias KV redirect;
// WarmPublicSiteCacheJob looks the subdomain up as handle_lc and warms nothing).
// Folding both pools into ONE predicate is what makes the two suffix formats
// unreachable rather than merely discouraged.
class HandleAllocator
{
    /**
     * DNS label limit. site.sites.subdomain is unconstrained text, but
     * site.site_subdomain_aliases.subdomain is varchar(63) — a longer handle
     * provisions fine and then cannot mint an alias on a later rename.
     */
    private const MAX_LENGTH = 63;

    /**
     * Item 1c (2026-09-01, owner-approved): handles read as the display name
     * with the spaces removed — `thefamishedwolf`, `ryanfitzsimons` — never
     * hyphenated. The collision ladder is deliberate about WHO gets a number:
     *
     *   1. the cleaned name's slug;
     *   2. the UNTRIMMED name's slug when one is supplied (Item 1b trims
     *      "The Famished Wolf Kensington" to "The Famished Wolf" — a second
     *      location of the same brand deserves its real distinguisher, the
     *      suburb, before anyone gets a digit);
     *   3. the bare-integer suffix, unchanged (jane-doe1 style — still
     *      distinct from the subdomain's hyphenated -1 on purpose).
     *
     * @return array{handle: string, handle_lc: string}
     */
    public function allocate(string $seed, ?string $untrimmedSeed = null): array
    {
        $base = $this->base($seed);

        if ($this->isAvailable(strtolower($base))) {
            return ['handle' => $base, 'handle_lc' => strtolower($base)];
        }

        if (is_string($untrimmedSeed) && trim($untrimmedSeed) !== '' && $untrimmedSeed !== $seed) {
            $fallback = $this->base($untrimmedSeed);
            if ($fallback !== $base && $this->isAvailable(strtolower($fallback))) {
                return ['handle' => $fallback, 'handle_lc' => strtolower($fallback)];
            }
        }

        $handle = $base;
        $attempt = 1;
        while (! $this->isAvailable(strtolower($handle))) {
            $suffix = (string) $attempt;
            $handle = $this->limit($base, strlen($suffix)).$suffix;
            $attempt++;
        }

        return ['handle' => $handle, 'handle_lc' => strtolower($handle)];
    }

    /** Slug with NO separator — display-name words concatenate. */
    private function base(string $seed): string
    {
        $base = Str::slug($seed, '');
        if ($base === '' || $base === '-') {
            $base = 'professional';
        }

        return $this->limit($base, 0);
    }

    /**
     * Truncate so base+suffix fits a DNS label, and never end on a hyphen —
     * `errol-.partna.au` is not a legal host.
     */
    private function limit(string $base, int $suffixLength): string
    {
        $max = max(1, self::MAX_LENGTH - $suffixLength);

        return rtrim(substr($base, 0, $max), '-') ?: 'professional';
    }

    /**
     * Free as a handle AND as a subdomain. Mirrors SubdomainAvailabilityService's
     * reserved / sites / active-alias predicates so the allocator can never hand
     * back a name the subdomain side would reject.
     *
     * The active-alias clause (`expires_at IS NULL OR expires_at > now()`) matches
     * SiteProvisioningService::subdomainHeldByActiveAlias() and RenameSubdomainAction:
     * an EXPIRED alias has released the name back to the pool and must not lock
     * anyone out, even before the pruner collects it.
     *
     * Deliberately NOT checked here: core.user_handle_aliases. Another user's live
     * old-handle redirect blocking a NEW handle is pre-existing behaviour on this
     * path and widening it would change handle-allocation semantics, not converge
     * handle with subdomain.
     */
    private function isAvailable(string $candidate): bool
    {
        $reserved = array_map('strtolower', (array) config('partna.reserved_subdomains', []));
        if (in_array($candidate, $reserved, true)) {
            return false;
        }

        if (User::query()->where('handle_lc', $candidate)->exists()) {
            return false;
        }

        if (DB::connection('pgsql')->table('site.sites')
            ->whereRaw('lower(subdomain) = ?', [$candidate])
            ->exists()) {
            return false;
        }

        return ! DB::connection('pgsql')->table('site.site_subdomain_aliases')
            ->whereRaw('lower(subdomain) = ?', [$candidate])
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }
}
