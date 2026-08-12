<?php

namespace App\Http\Controllers\Api\User\Profile;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\Profile\UpdateSectorRequest;
use App\Services\Profile\SectorTaxonomy;
use Illuminate\Http\JsonResponse;

// Writes sector_source='manual', the top rank in SectorProvenance's ladder:
// nothing automated overwrites a human's pick. Clearing the sector nulls the
// source too, which returns the row to "any source may fill it".
// Keep the sector/sector_source writes below coupled: askSector's gate reads
// sector_source alone, so a stuck 'manual' row with no sector is never asked.
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
