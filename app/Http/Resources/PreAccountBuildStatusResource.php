<?php

namespace App\Http\Resources;

use App\Models\Core\User\PreAccountBuild;
use Illuminate\Http\Request;

// Public poll shape for a pre-account build (site-first signup + staff
// marketing builds). No scraped content leaks here (spec §8) — content only
// ever appears via the public site payload once the build is ready.
/**
 * @mixin PreAccountBuild
 */
class PreAccountBuildStatusResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $ready = $this->build_state === PreAccountBuild::STATE_READY;
        $subdomain = $ready ? $this->user?->site?->subdomain : null;

        // Same fallback as routes/api/publicSite.php — a missing/typo'd
        // PARTNA_PUBLIC_DOMAIN env must not silently break the poll payload.
        $publicDomain = config('partna.public_domain') ?: 'partna.au';

        return array_filter([
            'build_id' => $this->id,
            'build_state' => $this->build_state,
            'account_type' => $this->user?->account_type?->value,
            'subdomain' => $subdomain,
            'site_url' => $subdomain ? 'https://'.$subdomain.'.'.$publicDomain : null,
            'failure_code' => $this->failure_code,
        ], fn ($v) => $v !== null);
    }
}
