<?php

namespace App\Http\Resources;

use App\Models\Core\User\PreAccountBuild;
use Illuminate\Http\Request;

// Webhook response for ManyChat. Carries the claim URL — the ONE time the
// plaintext token is ever visible — so it must never be reused for a GET. The
// poll shape is PreAccountBuildStatusResource, which has no token.
/**
 * @mixin PreAccountBuild
 */
class ManyChatBuildResource extends ApiResource
{
    public function __construct(
        PreAccountBuild $resource,
        private readonly bool $reused,
        private readonly ?string $claimUrl,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return array_filter([
            'build_id' => $this->id,
            'build_state' => $this->build_state,
            'subdomain' => $this->user?->site?->subdomain,
            'reused' => $this->reused,
            // Absent unless a token was minted — spec §5.4.
            'claim_url' => $this->claimUrl,
        ], fn ($v) => $v !== null);
    }
}
