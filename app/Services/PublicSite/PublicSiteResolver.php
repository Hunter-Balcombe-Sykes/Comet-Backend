<?php

namespace App\Services\PublicSite;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;

// V2: Resolves published sites by subdomain with fallback to subdomain aliases. Requires professional active status.
class PublicSiteResolver
{
    public function resolvePublishedSite(string $subdomain): ?Site
    {
        $subdomain = strtolower($subdomain);

        $siteQuery = Site::query()
            ->where('is_published', true)
            ->with('user')
            ->whereHas('user', function ($q) {
                $q->where('status', 'active');
            });

        $site = (clone $siteQuery)
            ->whereRaw('lower(subdomain) = ?', [$subdomain])
            ->first();

        if ($site) {
            return $site;
        }

        $alias = SiteSubdomainAlias::query()
            ->whereRaw('lower(subdomain) = ?', [$subdomain])
            ->first();

        if (! $alias) {
            return null;
        }

        return (clone $siteQuery)
            ->where('id', $alias->site_id)
            ->first();
    }
}
