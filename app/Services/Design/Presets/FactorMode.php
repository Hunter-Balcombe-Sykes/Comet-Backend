<?php

namespace App\Services\Design\Presets;

// How a factor's contribution updates over the integration's lifecycle.
enum FactorMode: string
{
    // Resolved once at connect and FROZEN — later changes to the integration's
    // data don't move it (rebuilds preserve the existing rows). Removed only on
    // disconnect.
    case OneShot = 'one_shot';

    // Re-resolved whenever the integration's payload changes (its refresh
    // cadence). Tracks current state.
    case Auto = 'auto';
}
