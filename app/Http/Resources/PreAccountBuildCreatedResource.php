<?php

namespace App\Http\Resources;

use App\Models\Core\User\PreAccountBuild;
use Illuminate\Http\Request;

// POST /api/public/signup/build response shape (#W2-SEC-1). Wraps the same
// public poll fields as PreAccountBuildStatusResource and appends
// claim_token ONLY when one was minted for THIS request — a NEW build, never
// a re-served/deduped one (spec §5.4; minting on a dedupe hit would let
// anyone who guesses a live source_ref fetch a working takeover capability).
//
// GET /api/public/signup/builds/{build} deliberately keeps using
// PreAccountBuildStatusResource, unchanged, forever: that endpoint is a
// public opaque-UUID GET, so the plaintext token must exist in exactly one
// response — the create — and never again.
/**
 * @mixin PreAccountBuild
 */
class PreAccountBuildCreatedResource extends ApiResource
{
    public function __construct(
        PreAccountBuild $resource,
        private readonly ?string $claimToken,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $base = (new PreAccountBuildStatusResource($this->resource))->toArray($request);

        return array_filter([
            ...$base,
            'claim_token' => $this->claimToken,
        ], fn ($v) => $v !== null);
    }
}
