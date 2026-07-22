<?php

namespace App\Http\Resources;

use App\Models\Core\User\PreAccountBuild;
use Illuminate\Http\Request;

// Staff-facing shape for a pre-account build: the public poll fields plus the
// outreach-state fields (auto_invite, invited_at) that MUST NOT leak onto the
// public PreAccountBuildStatusResource (spec §8). Staff endpoints return this
// variant so a manual invite call can confirm the send actually happened.
/**
 * @mixin PreAccountBuild
 */
class StaffPreAccountBuildResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $base = (new PreAccountBuildStatusResource($this->resource))->toArray($request);

        return array_merge($base, [
            'auto_invite' => (bool) $this->auto_invite,
            'invited_at' => $this->invited_at?->toIso8601String(),
        ]);
    }
}
