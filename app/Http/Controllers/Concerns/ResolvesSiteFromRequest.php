<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Core\User\User;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;

// V2: Resolves a Site by ID or subdomain (with subdomain alias fallback) from incoming request data.
trait ResolvesSiteFromRequest
{
    /**
     * Resolve the site by ID or subdomain (with alias fallback).
     *
     * When both site_id and subdomain are present (subdomain is populated from the
     * route parameter or X-Site-Subdomain header by PageviewRequest/ClickRequest::
     * prepareForValidation), we cross-check them to prevent an attacker on subdomain
     * "attacker" from submitting a victim's site_id and recording events against it.
     */
    protected function resolveSiteFromData(array $data): ?Site
    {
        if (! empty($data['site_id'])) {
            $query = Site::query()->whereKey($data['site_id']);

            // When a subdomain is also present, cross-check to prevent IDOR:
            // an attacker submitting a victim's site_id under a different subdomain.
            if (! empty($data['subdomain'])) {
                $query->whereRaw('lower(subdomain) = ?', [strtolower($data['subdomain'])]);
            }

            $site = $query->first();

            // site_id was given with a subdomain but nothing matched — invalid input.
            if (! $site && ! empty($data['subdomain'])) {
                return null;
            }

            return $site;
        }

        if (! empty($data['subdomain'])) {
            $subdomain = strtolower($data['subdomain']);

            // Try direct match
            $site = Site::query()->whereRaw('lower(subdomain) = ? ', [$subdomain])->first();
            if ($site) {
                return $site;
            }

            // Try alias — only ACTIVE (non-expired) aliases resolve.
            $alias = SiteSubdomainAlias::query()->active()->whereRaw('lower(subdomain) = ?', [$subdomain])->first();
            if ($alias) {
                return Site::query()->find($alias->site_id);
            }

            // Fallback: the analytics client sends `affiliate.slug` (the handle)
            // as `subdomain`. Handle and subdomain are the same value in V2/V3,
            // but the client only knows the handle. Resolve via user handle.
            $user = User::query()->where('handle_lc', $subdomain)->first();
            if ($user) {
                return Site::query()->where('user_id', $user->id)->first();
            }
        }

        return null;
    }
}
