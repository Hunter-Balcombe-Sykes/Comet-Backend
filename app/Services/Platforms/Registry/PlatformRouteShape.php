<?php

namespace App\Services\Platforms\Registry;

// How the registry-driven route loop in routes/api/integrations.php emits a
// platform's per-user endpoints. Bespoke platforms are skipped by the loop and
// keep their hand-written standalone groups.
enum PlatformRouteShape
{
    // connect + selection + DELETE all on GenericPlatformController (link-only socials).
    case LinkOnly;

    // connect + selection + DELETE all on the platform's own controller; no /accounts
    // (the former $singleSelection group: skool, strava, google-business).
    case SingleSelection;

    // connect on the platform's own controller; selection + DELETE (and /accounts when
    // multiAccount()) on GenericPlatformController (the former $migratedReads group).
    case MultiAccount;

    // Not emitted by the loop — the platform keeps its standalone route group. Default.
    case Bespoke;
}
