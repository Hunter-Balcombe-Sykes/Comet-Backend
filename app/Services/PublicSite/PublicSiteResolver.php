<?php

namespace App\Services\PublicSite;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;

// Resolves published sites by subdomain, falling back to ACTIVE subdomain aliases.
// Requires the owning user to be active. Returns a redirect-intent array so callers
// can tell a direct (canonical) hit from an alias hit — the public site-render path
// 301s to the canonical on an alias hit, while data-capture endpoints (lead/enquiry/
// subscribe) simply process against the resolved canonical site (no redirect — a 301
// on a POST would be downgraded to GET by most clients and silently lose the body).
class PublicSiteResolver
{
    /**
     * @return array{site: ?Site, alias_hit: bool, canonical_subdomain: ?string}
     */
    public function resolvePublishedSite(string $subdomain): array
    {
        $subdomain = strtolower($subdomain);

        $siteQuery = Site::query()
            ->where('is_published', true)
            ->with('user')
            ->whereHas('user', function ($q) {
                // Unclaimed (pre-account) owners render when published — the
                // publish knob, not claim state, controls visibility (spec §2).
                $q->whereIn('status', ['active', 'unclaimed']);
            });

        // Direct (canonical) match — never an alias hit.
        $site = (clone $siteQuery)
            ->whereRaw('lower(subdomain) = ?', [$subdomain])
            ->first();

        if ($site) {
            return ['site' => $site, 'alias_hit' => false, 'canonical_subdomain' => $site->subdomain];
        }

        // Fallback: only an ACTIVE alias resolves. Expired-but-unpruned aliases
        // are filtered out here so they stop redirecting the moment they lapse,
        // before the daily prune job hard-deletes the row.
        $alias = SiteSubdomainAlias::query()
            ->active()
            ->whereRaw('lower(subdomain) = ?', [$subdomain])
            ->first();

        if (! $alias) {
            return ['site' => null, 'alias_hit' => false, 'canonical_subdomain' => null];
        }

        // Re-run the published/active-user gate on the aliased site: an alias must
        // not resolve if its canonical site is unpublished or its owner inactive.
        $aliased = (clone $siteQuery)
            ->where('id', $alias->site_id)
            ->first();

        return [
            'site' => $aliased,
            'alias_hit' => (bool) $aliased,
            'canonical_subdomain' => $aliased?->subdomain,
        ];
    }
}
