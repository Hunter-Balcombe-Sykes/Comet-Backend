<?php

namespace App\Http\Controllers\Api\User\Profile;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Profile\UpdateSectorRequest;
use App\Services\Profile\SectorTaxonomy;
use Illuminate\Http\JsonResponse;

// Sets the user's sector/industry from the curated picker. A manual PICK stamps
// sector_source='manual' so IdentitySync's precedence rule knows the value was
// user-chosen (manual is permanent vs Google, 2026-07-15). CLEARING the pick
// clears provenance too — a null sector stamped 'manual' would permanently
// block Google from ever filling the field, freezing a business's
// sector-derived capabilities in the not-food state.
class SectorController extends ApiController
{
    use ResolveCurrentUser;

    public function update(UpdateSectorRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $this->authorizeForUser($user, 'update', $user);

        $sector = $request->validated()['sector']; // null or a valid slug

        // sector_source is not fillable (service-written) — assign directly.
        $changed = $user->sector !== $sector;
        $user->sector = $sector;
        $user->sector_source = $sector === null ? null : 'manual';
        $user->save();

        // The sector drives the read-time profile design presets — touch the
        // site so the public payload + email caches roll and the sitepage
        // restyles immediately (SiteObserver::saved runs the purge chain).
        if ($changed) {
            $user->site()->first()?->touch();
        }

        return $this->success([
            'sector' => $sector,
            'sector_label' => $sector !== null ? SectorTaxonomy::labelFor($sector) : null,
        ]);
    }
}
