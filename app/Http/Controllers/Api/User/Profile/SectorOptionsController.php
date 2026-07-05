<?php

namespace App\Http\Controllers\Api\User\Profile;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Services\Profile\SectorTaxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Serves the curated sector/industry picker list, grouped into sections. The
// list is a static code constant (SectorTaxonomy), so this is a pure read — the
// user context is resolved only to keep the endpoint behind the authenticated
// user group like the rest of /profile.
class SectorOptionsController extends ApiController
{
    use ResolveCurrentUser;

    public function show(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        // The picker also needs the user's current pick + its provenance so the
        // Brand Info page can pre-select the slug and badge a Google-sourced value.
        return $this->success([
            'groups' => SectorTaxonomy::all(),
            'current' => $user->sector,
            'currentSource' => $user->sector_source,
        ]);
    }
}
