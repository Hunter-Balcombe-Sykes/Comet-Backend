<?php

namespace App\Http\Controllers\Api\User\Profile;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Profile\UpdateSectorRequest;
use App\Services\Profile\SectorTaxonomy;
use Illuminate\Http\JsonResponse;

// Sets the user's sector/industry from the curated picker. A manual pick always
// stamps sector_source='manual' so the Google precedence rule in IdentitySync
// knows this value was user-chosen (business overwrites it, partna does not).
class SectorController extends ApiController
{
    use ResolveCurrentUser;

    public function update(UpdateSectorRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $sector = $request->validated()['sector']; // null or a valid slug

        // sector_source is not fillable (service-written) — assign directly.
        $user->sector = $sector;
        $user->sector_source = 'manual';
        $user->save();

        return $this->success([
            'sector' => $sector,
            'sector_label' => $sector !== null ? SectorTaxonomy::labelFor($sector) : null,
        ]);
    }
}
