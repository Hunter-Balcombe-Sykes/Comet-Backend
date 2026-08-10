<?php

namespace App\Services\Design;

// Default-variant anchors of the theme-mode palettes. LOCKSTEP mirror of
// partna-monorepo/packages/design-system/src/design-kit/palettes.ts — email
// theming needs bg/text server-side and cannot import the TS package.
//
// ONE palette since 2026-08-06 (design-kit simplification). The roster used to
// hold five here (bleach, dust, warm, dusk, midnight) and three on the sitepage
// side (bleach, dust, tonal) — the two never agreed, so 'warm', 'dusk' and
// 'midnight' were modes only the backend believed in: the renderer rejected
// them as unknown and drew bleach. Collapsing to bleach closes that gap rather
// than widening it.
//
// The shape is deliberately kept as a keyed roster rather than flattened to two
// constants: a second palette is a product decision away, and anchorsFor()'s
// null-coalescing fallback is what keeps a stale row rendering instead of
// fataling.
//
// ─── 2026-08-09: the ink moves onto the grey ramp. ───────────────────────────
//
// The go-live replaces palettes.ts's eight use-named anchors with a 12-step
// value-named ramp (GRAY_RAMP_DAY). Its surface IS gray-0 — rgb(255,255,255),
// byte-identical to the old bleach bg — but its ink is gray-900,
// rgb(20,20,20) = #141414, where the old anchor was #181818. The TS side has
// carried the divergence as declared debt since the ramp landed:
//
//     "LOCKSTEP DEBT, not yet due: the ramp's ink is gray-900 = #141414,
//      where the backend anchor above is #181818. ThemeModePalettes.php moves
//      to the ramp in phase 6 and the BACKEND_ANCHORS table above follows it
//      in the same commit."   — apps/pages/test/palettes.test.ts
//
// This is phase 6. The ink is #141414 now.
//
// ⚠ THE PAGES LANE OWES THE OTHER HALF. apps/pages/test/palettes.test.ts pins
// BACKEND_ANCHORS against THEME_MODE_PALETTES.bleach.day.*, which are still
// the LEGACY anchors (#181818) — so that test compares TS to TS and will keep
// passing while this file drifts away from it. Updating BACKEND_ANCHORS to
// #141414 must therefore come WITH re-pointing the assertion at
// GRAY_RAMP_DAY['900'] (rgba(20,20,20,1) → #141414), which is where the ink
// actually lives once phase 5 deletes the anchors. Changing only the constant
// fails the test; changing neither leaves the two systems silently apart.
//
// Visual impact: 4/255 on one channel — imperceptible, and it only reaches
// email, which is the sole consumer of these anchors.
class ThemeModePalettes
{
    public const DEFAULT_MODE = 'bleach';

    /** @var array<string, array{bg: string, text: string}> */
    public const ANCHORS = [
        // The grey ramp's ends (2026-08-09): bg = gray-0 rgb(255,255,255),
        // text = gray-900 rgb(20,20,20). Was #ffffff/#181818, the ALD-calibrated
        // anchor pair — the surface is unchanged, the ink moved one ramp step.
        'bleach' => ['bg' => '#ffffff', 'text' => '#141414'],
    ];

    /** @return array{bg: string, text: string} */
    public static function anchorsFor(?string $mode): array
    {
        // Coalesce before indexing: a null offset is a PHP 8.5 deprecation.
        return self::ANCHORS[$mode ?? self::DEFAULT_MODE] ?? self::ANCHORS[self::DEFAULT_MODE];
    }
}
