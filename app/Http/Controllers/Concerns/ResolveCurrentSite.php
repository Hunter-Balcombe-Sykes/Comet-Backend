<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Core\Professional\User;
use App\Models\Core\Site\Site;
use Illuminate\Validation\ValidationException;

// V2: Resolves the Site belonging to the current Professional, aborting with a validation error if none exists.
trait ResolveCurrentSite
{
    protected function currentSite(User $professional): Site
    {
        $site = $professional->site;

        if (! $site) {
            throw ValidationException::withMessages([
                'site' => 'Professional has no site.',
            ]);
        }

        return $site;
    }
}
