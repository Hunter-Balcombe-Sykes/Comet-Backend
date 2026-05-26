<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Core\User\User;
use Illuminate\Http\Request;

// V2: Extracts the authenticated Professional model from request attributes set by the current.pro middleware.
trait ResolveCurrentUser
{
    protected function currentUser(Request $request): User
    {
        $pro = $request->attributes->get('professional');

        if (! $pro instanceof User) {
            abort(401, 'Professional not loaded. Ensure current.pro middleware is applied.');
        }

        return $pro;
    }
}
