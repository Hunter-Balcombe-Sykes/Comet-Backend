<?php

namespace App\Services\Platforms\Registry;

// How the registry-driven route loop in routes/api/platforms.php emits a
// platform's per-user endpoints. Bespoke platforms are skipped by the loop and
// keep their hand-written standalone groups.
enum PlatformRouteShape
{
    // connect + selection + DELETE all on GenericPlatformController (link-only socials).
    case LinkOnly;

    // connect + selection + DELETE all on the platform's own controller; no /accounts.
    // google-business is the only remaining user (skool and strava left for
    // LinkOnly in the P2 demotion).
    case SingleSelection;

    // connect on the platform's own controller; selection + DELETE (and /accounts when
    // multiAccount()) on GenericPlatformController (the former $migratedReads group).
    //
    // The name describes the EMISSION PATTERN, not an account cardinality. A
    // descriptor may be MultiAccount with multiAccount() === false — nowbookit,
    // opentable and resdiary all are — and that combination is correct: it emits
    // connect + selection + forget on GenericPlatformController and no /accounts
    // routes, which is exactly what a single-account registry-driven provider
    // needs. Do NOT "fix" it by reclassifying to SingleSelection: all three
    // declare connectController = null (->routes(MultiAccount, null, false) in each
    // of their binding classes, FOUND-24), and SingleSelection wires selection +
    // forget to that controller.
    // Branch on multiAccount(), never on this enum.
    case MultiAccount;

    // connect + selection + DELETE all on GenericPlatformController, for
    // descriptors DERIVED from the compiled catalog (DerivedDescriptorFactory).
    // Distinct from LinkOnly, which is for hand-registered socials: a Brand
    // connect additionally validates that the submitted URL belongs to THIS
    // brand, so /platforms/menulog/connect cannot accept a DoorDash link.
    // /accounts is emitted only when multiAccount().
    case Brand;

    // Not emitted by the loop — the platform keeps its standalone route group. Default.
    case Bespoke;
}
